<?php

namespace App\Services\Bot;

use App\Models\Area;
use App\Models\BotCompany;
use App\Models\Profesion;
use App\Support\SensitiveDataSanitizer;
use App\Support\TlsVerification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GeminiVacancyAnalyzer
{
    public function analyze(string $title, BotCompany|string $company, string $description): array
    {
        return $this->analyzeWithMeta($title, $company, $description)['data'];
    }

    public function analyzeWithMeta(string $title, BotCompany|string $company, string $description, array $options = []): array
    {
        // Keep legacy class and metadata names for saved-preview compatibility.
        // New Evaluar and E-Talent analyses are sent exclusively to Claude.
        $key = config('services.anthropic.api_key');
        $model = trim((string) config('services.anthropic.model', 'claude-haiku-4-5-20251001'));
        $companyName = $company instanceof BotCompany ? $company->name : $company;
        $preparedDescription = $this->prepareDescription($description);

        if (! preg_match('/^[A-Za-z0-9._-]{1,100}$/', $model)) {
            return $this->metaError(
                errorType: 'invalid_configuration',
                error: 'ANTHROPIC_MODEL contiene un valor no permitido.',
                used: false,
                model: 'invalid',
                extra: $preparedDescription['meta'],
            );
        }

        if (! empty($options['skip_due_to_quota'])) {
            return $this->metaError(
                errorType: 'quota_exceeded_skipped',
                error: 'Se alcanzó el límite de Claude en este lote; se usaron valores de respaldo.',
                used: false,
                model: $model,
                extra: [
                    'gemini_skipped_due_to_quota' => true,
                    ...$preparedDescription['meta'],
                ],
            );
        }

        if (! $key) {
            return $this->metaError(
                errorType: 'missing_api_key',
                error: 'ANTHROPIC_API_KEY no configurada',
                used: false,
                model: $model,
                extra: $preparedDescription['meta'],
            );
        }

        try {
            $response = null;
            $lastThrowable = null;
            $attempts = 0;

            while ($attempts < 3) {
                $attempts++;

                try {
                    $response = $this->sendRequest($model, $key, $title, $companyName, $preparedDescription['text']);
                    $status = $response->status();

                    if (in_array($status, [500, 502, 503, 504], true) && $attempts < 3) {
                        usleep(1_000_000);

                        continue;
                    }

                    if ($status === 429 && $attempts < 2) {
                        usleep(2_000_000);

                        continue;
                    }

                    break;
                } catch (\Throwable $exception) {
                    $lastThrowable = $exception;
                    $type = $this->classifyThrowable($exception);

                    if ($type === 'timeout' && $attempts < 3) {
                        usleep(1_000_000);

                        continue;
                    }

                    throw $exception;
                }
            }

            if (! $response && $lastThrowable) {
                throw $lastThrowable;
            }

            $responseJson = $response->json();
            $usageMetadata = $this->usageMetadata($responseJson);

            if (! $response->successful()) {
                $status = $response->status();

                return $this->metaError(
                    errorType: $this->classifyHttpError($status, $response->body()),
                    error: $this->httpError($status, $response->body()),
                    used: true,
                    model: $model,
                    httpStatus: $status,
                    extra: [
                        'gemini_attempts' => $attempts,
                        'gemini_response_metadata' => SensitiveDataSanitizer::payloadMetadata($response->body()),
                        ...$usageMetadata,
                        ...$preparedDescription['meta'],
                    ],
                );
            }

            $text = data_get($responseJson, 'content.0.text');
            if (! $text) {
                return $this->metaError(
                    errorType: 'invalid_json',
                    error: 'Claude devolvió una respuesta vacía.',
                    used: true,
                    model: $model,
                    httpStatus: $response->status(),
                    extra: [
                        'gemini_attempts' => $attempts,
                        'gemini_finish_reason' => data_get($responseJson, 'stop_reason'),
                        ...$usageMetadata,
                        ...$preparedDescription['meta'],
                    ],
                );
            }

            $decoded = $this->extractJson($text);
            if (! is_array($decoded)) {
                return $this->metaError(
                    errorType: 'invalid_json',
                    error: 'Claude respondió, pero el JSON no se pudo interpretar.',
                    used: true,
                    model: $model,
                    httpStatus: $response->status(),
                    extra: [
                        'gemini_attempts' => $attempts,
                        'gemini_finish_reason' => data_get($responseJson, 'stop_reason'),
                        'gemini_json_error' => SensitiveDataSanitizer::text(json_last_error_msg(), 120),
                        'gemini_response_metadata' => SensitiveDataSanitizer::payloadMetadata($text),
                        ...$usageMetadata,
                        ...$preparedDescription['meta'],
                    ],
                );
            }

            $validationErrors = $this->validateAnalysisContract($decoded);

            if ($validationErrors !== []) {
                return $this->metaError(
                    errorType: 'invalid_schema',
                    error: 'Claude devolvió un JSON que no cumple el contrato de clasificación.',
                    used: true,
                    model: $model,
                    httpStatus: $response->status(),
                    extra: [
                        'gemini_attempts' => $attempts,
                        'gemini_finish_reason' => data_get($responseJson, 'stop_reason'),
                        'gemini_validation_errors' => $validationErrors,
                        'gemini_response_metadata' => SensitiveDataSanitizer::payloadMetadata($text),
                        ...$usageMetadata,
                        ...$preparedDescription['meta'],
                    ],
                );
            }

            return [
                'data' => $this->normalizeAnalysis($decoded, $title, $description),
                'used' => true,
                'success' => true,
                'model' => $model,
                'error' => null,
                'error_type' => null,
                'http_status' => $response->status(),
                'gemini_response_metadata' => SensitiveDataSanitizer::payloadMetadata($text),
                'gemini_attempts' => $attempts,
                'gemini_finish_reason' => data_get($responseJson, 'stop_reason'),
                'analyzed_at' => now()->toIso8601String(),
                'prompt_version' => (string) config('profession_matching.prompt_version'),
                ...$usageMetadata,
                ...$preparedDescription['meta'],
            ];
        } catch (\Throwable $exception) {
            $type = $this->classifyThrowable($exception);

            return $this->metaError(
                errorType: $type,
                error: $this->humanThrowableMessage($exception, $type),
                used: true,
                model: $model,
                extra: [
                    'gemini_attempts' => $attempts ?? null,
                    ...$preparedDescription['meta'],
                    ...$this->sslDiagnostics($exception, $type),
                ],
            );
        }
    }

    private function sendRequest(string $model, string $key, string $title, string $companyName, string $description)
    {
        $verify = TlsVerification::option(
            'Claude',
            (bool) config('services.anthropic.verify_ssl', true),
            config('services.anthropic.ca_bundle'),
        );

        return Http::connectTimeout((int) config('services.anthropic.connect_timeout', 10))
            ->timeout((int) config('services.anthropic.timeout', 60))
            ->withHeaders([
                'Content-Type' => 'application/json',
                'x-api-key' => $key,
                'anthropic-version' => config('services.anthropic.version', '2023-06-01'),
            ])
            ->withOptions([
                'verify' => $verify,
                'allow_redirects' => false,
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => (int) config('services.anthropic.max_tokens', 2048),
                'temperature' => 0,
                'system' => 'Analiza convocatorias laborales y responde exclusivamente con el JSON solicitado, sin texto adicional.',
                'messages' => [[
                    'role' => 'user',
                    'content' => $this->prompt($title, $companyName, $description),
                ]],
            ]);
    }

    private function responseSchema(): array
    {
        $catalogNames = Profesion::query()
            ->whereHas('areas')
            ->orderBy('profesion_name')
            ->pluck('profesion_name')
            ->values()
            ->all();
        $areaNames = Area::query()
            ->whereHas('profesions')
            ->orderBy('area_name')
            ->pluck('area_name')
            ->values()
            ->all();

        return [
            'type' => 'object',
            'properties' => [
                'profesiones_encontradas' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'nombre_original' => ['type' => 'string'],
                            'nombre_catalogo' => [
                                'type' => 'string',
                                'enum' => $catalogNames,
                            ],
                            'evidencia' => ['type' => 'string'],
                            'tipo_requisito' => [
                                'type' => 'string',
                                'enum' => ['obligatoria', 'alternativa', 'deseable'],
                            ],
                            'confianza' => ['type' => 'number'],
                        ],
                        'required' => [
                            'nombre_original',
                            'nombre_catalogo',
                            'evidencia',
                            'tipo_requisito',
                            'confianza',
                        ],
                        'propertyOrdering' => [
                            'nombre_original',
                            'nombre_catalogo',
                            'evidencia',
                            'tipo_requisito',
                            'confianza',
                        ],
                    ],
                ],
                'acepta_carreras_afines' => ['type' => 'boolean'],
                'evidencia_carreras_afines' => ['type' => 'string'],
                'area_principal_catalogo' => [
                    'type' => 'string',
                    'enum' => ['SIN_AREA', ...$areaNames],
                ],
                'evidencia_area_principal' => ['type' => 'string'],
                'confianza_area_principal' => ['type' => 'number'],
                'ubicacion_departamento' => ['type' => 'string'],
                'ubicacion' => ['type' => 'string'],
                'sueldo' => ['type' => 'string'],
                'fecha_expiracion' => ['type' => 'string'],
            ],
            'required' => [
                'profesiones_encontradas',
                'acepta_carreras_afines',
                'evidencia_carreras_afines',
                'area_principal_catalogo',
                'evidencia_area_principal',
                'confianza_area_principal',
                'ubicacion_departamento',
                'ubicacion',
                'sueldo',
                'fecha_expiracion',
            ],
            'propertyOrdering' => [
                'profesiones_encontradas',
                'acepta_carreras_afines',
                'evidencia_carreras_afines',
                'area_principal_catalogo',
                'evidencia_area_principal',
                'confianza_area_principal',
                'ubicacion_departamento',
                'ubicacion',
                'sueldo',
                'fecha_expiracion',
            ],
        ];
    }

    private function sslDiagnostics(\Throwable $exception, string $type): array
    {
        if (
            $type !== 'ssl_error'
            || ! (bool) config('services.anthropic.debug_ssl', false)
            || ! app()->environment('local', 'testing')
        ) {
            return [];
        }

        preg_match('/cURL error\s+(\d+)/i', $exception->getMessage(), $matches);
        $exceptionType = preg_replace('/[^A-Za-z0-9_]/', '', class_basename($exception)) ?: 'Throwable';

        return [
            'gemini_ssl_diagnostics' => [
                'exception_type' => Str::limit($exceptionType, 100, ''),
                'curl_error_code' => isset($matches[1]) ? (int) $matches[1] : null,
                'verify_ssl' => (bool) config('services.anthropic.verify_ssl', true),
                'ca_bundle_configured' => trim((string) config('services.anthropic.ca_bundle')) !== '',
            ],
        ];
    }

    public function fallback(): array
    {
        return [
            'area_ids' => [],
            'areas' => [],
            'area_principal' => 'No especificado',
            'profesiones_encontradas' => [],
            'acepta_carreras_afines' => false,
            'evidencia_carreras_afines' => '',
            'area_principal_catalogo' => '',
            'evidencia_area_principal' => '',
            'confianza_area_principal' => 0.0,
            'profesiones_sugeridas' => [],
            'area' => 'No especificado',
            'professions' => 'No especificado',
            'department' => 'No especificado',
            'location' => 'No especificado',
            'salary' => 0,
            'expiration_date' => 'No especificado',
            'municipality' => null,
        ];
    }

    private function metaError(
        string $errorType,
        string $error,
        bool $used,
        string $model,
        ?int $httpStatus = null,
        array $extra = [],
    ): array {
        unset($extra['raw_response'], $extra['gemini_raw_response']);
        $extra = SensitiveDataSanitizer::context($extra, 300, 4, 50);

        return [
            'data' => $this->fallback(),
            'used' => $used,
            'success' => false,
            'model' => $model,
            'error' => SensitiveDataSanitizer::text($error, 300),
            'error_type' => $errorType,
            'http_status' => $httpStatus,
            'prompt_version' => (string) config('profession_matching.prompt_version'),
            ...$extra,
        ];
    }

    private function prepareDescription(string $description): array
    {
        $max = (int) config('services.anthropic.max_description_chars', 6000);
        $max = $max > 0 ? $max : 6000;
        $length = mb_strlen($description);
        $truncated = $length > $max;
        $text = $truncated ? mb_substr($description, 0, $max) : $description;

        return [
            'text' => $text,
            'meta' => [
                'description_truncated_for_gemini' => $truncated,
                'description_original_length' => $length,
                'description_sent_length' => mb_strlen($text),
            ],
        ];
    }

    private function usageMetadata(?array $response): array
    {
        $usage = data_get($response, 'usage');

        if (! is_array($usage)) {
            return [
                'usage_metadata' => null,
                'prompt_tokens' => null,
                'candidates_tokens' => null,
                'total_tokens' => null,
                'thoughts_tokens' => null,
            ];
        }

        return [
            'usage_metadata' => $usage,
            'prompt_tokens' => data_get($usage, 'input_tokens'),
            'candidates_tokens' => data_get($usage, 'output_tokens'),
            'total_tokens' => (int) data_get($usage, 'input_tokens', 0) + (int) data_get($usage, 'output_tokens', 0),
            'thoughts_tokens' => null,
        ];
    }

    private function classifyHttpError(int $status, string $body): string
    {
        $message = $this->normalize($this->httpError($status, $body));

        if ($status === 429 || Str::contains($message, ['quota', 'exceeded', 'too many requests', 'rate limit'])) {
            return 'quota_exceeded';
        }

        return 'http_error';
    }

    private function classifyThrowable(\Throwable $exception): string
    {
        $message = $this->normalize($exception->getMessage());

        if (Str::contains($message, ['curl error 60', 'ssl certificate problem', 'ca bundle', 'tls'])) {
            return 'ssl_error';
        }

        if (Str::contains($message, ['timeout', 'timed out', 'operation timed out'])) {
            return 'timeout';
        }

        if (Str::contains($message, ['quota', 'exceeded', 'too many requests', 'rate limit'])) {
            return 'quota_exceeded';
        }

        return 'http_error';
    }

    private function humanThrowableMessage(\Throwable $exception, string $type): string
    {
        if ($type === 'ssl_error') {
            return 'Error SSL. Verifica curl.cainfo y openssl.cafile en php.ini, y ejecuta php artisan optimize:clear.';
        }

        return SensitiveDataSanitizer::text($exception->getMessage(), 300)
            ?: 'Error al conectar con Claude.';
    }

    private function prompt(string $title, string $company, string $description): string
    {
        $professionCatalog = Profesion::query()
            ->with('areas:id,area_name')
            ->orderBy('profesion_name')
            ->get(['id', 'profesion_name'])
            ->map(fn (Profesion $profession): string => sprintf(
                '- %s%s',
                $profession->profesion_name,
                $profession->areas->isEmpty()
                    ? ''
                    : ' (áreas de referencia: '.$profession->areas->pluck('area_name')->implode(', ').')',
            ))
            ->implode("\n");

        return <<<PROMPT
Analiza la convocatoria laboral proporcionada.

Devuelve exclusivamente JSON valido con esta estructura:

{
  "profesiones_encontradas": [
    {
      "nombre_original": "Trabajo Social",
      "nombre_catalogo": "Trabajo Social",
      "evidencia": "Se requiere Licenciatura en Trabajo Social",
      "tipo_requisito": "obligatoria",
      "confianza": 0.98
    }
  ],
  "acepta_carreras_afines": false,
  "evidencia_carreras_afines": "",
  "area_principal_catalogo": "Área ECONÓMICA, ADMINISTRATIVA Y FINANCIERA",
  "evidencia_area_principal": "Funciones de análisis financiero y económico",
  "confianza_area_principal": 0.95,
  "ubicacion_departamento": "",
  "ubicacion": "",
  "sueldo": "",
  "fecha_expiracion": ""
}

Reglas:
- No uses markdown.
- No expliques.
- No devuelvas texto adicional.
- Extrae únicamente profesiones o carreras exigidas como formación académica base.
- nombre_catalogo debe ser exactamente uno de los nombres del catálogo interno incluido abajo. Nunca escribas un nombre fuera del catálogo.
- nombre_original conserva la expresión encontrada en la convocatoria, aunque sea plural, abreviada o genérica.
- Puedes asociar una expresión a un nombre oficial sólo cuando el significado sea inequívoco dentro del catálogo.
- Si una expresión genérica no permite elegir una profesión concreta, por ejemplo "ingeniería" sin especialidad, no la incluyas.
- No incluyas cursos, conocimientos, experiencia ni temas de postgrado como profesiones. Ejemplos que deben omitirse cuando aparecen sólo como especialización o conocimiento: "postgrado en riesgos", "data science", "econometría" y "análisis económico-financiero".
- No inventes profesiones ni agregues profesiones relacionadas que no estén sustentadas por el texto.
- Conserva en nombre_original el nombre tal como aparece en la convocatoria.
- Incluye en evidencia el fragmento textual mínimo que demuestra la mención.
- tipo_requisito debe ser obligatoria, alternativa o deseable.
- confianza debe ser un número entre 0 y 1.
- Si aparece "carreras afines" o una expresión equivalente, marca acepta_carreras_afines como true.
- Si acepta_carreras_afines es true, copia en evidencia_carreras_afines el fragmento que contiene "afines", "ramas afines" o su equivalente. En caso contrario devuelve una cadena vacía.
- No incluyas expresiones como "ramas afines" dentro de profesiones_encontradas. Laravel expandirá posteriormente las áreas asociadas a cada profesión explícita, aplicando las excepciones configuradas en el catálogo.
- Si el texto enumera grupos explícitos de formación, por ejemplo "carreras administrativas, económicas, financieras o contables", asocia cada grupo inequívoco con su nombre oficial del catálogo.
- area_principal_catalogo debe ser exactamente un área del catálogo. Elígela considerando primero el cargo y sus funciones, y después las carreras admitidas.
- Cuando haya carreras o ramas afines, area_principal_catalogo sigue representando el área que mejor describe el cargo, pero no limita qué áreas asociadas a las profesiones explícitas serán expandidas por Laravel.
- Si la convocatoria menciona carreras de varias áreas, selecciona el área que mejor representa el trabajo; no elijas simplemente la que tenga más nombres.
- evidencia_area_principal debe ser un fragmento breve del cargo o de sus funciones que justifique el área. confianza_area_principal debe estar entre 0 y 1.
- Sólo si realmente no existe información suficiente para elegir un área, devuelve area_principal_catalogo como "SIN_AREA", evidencia_area_principal como cadena vacía y confianza_area_principal como 0.
- No asignes IDs de profesión.
- No asignes IDs de área.
- Si no se menciona ninguna formación, devuelve profesiones_encontradas como [].
- No repitas una misma profesión. Si aparece varias veces, conserva una sola entrada y usa la evidencia más explícita.
- Extrae ubicacion_departamento y ubicacion solo si aparecen explicitamente en el titulo o descripcion. No las infieras por la empresa, el dominio o conocimiento externo.
- ubicacion_departamento debe ser el departamento si se puede detectar explicitamente.
- Ubicacion debe ser ciudad, municipio, sucursal o lugar de trabajo si aparece explicitamente.
- Si no hay sueldo, devolver exactamente: 0.
- Si hay sueldo, devuelve el monto detectado.
- Si no hay fecha de expiracion, devolver exactamente: No especificado.
- Para fecha de expiracion busca especialmente: postular hasta, fecha limite, fecha límite, recepción de postulaciones, recepcion de postulaciones, fecha de cierre, cierre, hasta el, fecha de vencimiento, vencimiento.

Catálogo interno permitido. nombre_catalogo debe copiar literalmente uno de estos nombres. No devuelvas IDs:
{$professionCatalog}

Los nombres de área permitidos son los textos que aparecen entre paréntesis como "áreas de referencia". area_principal_catalogo debe copiar literalmente uno de ellos.

Datos:
Titulo: {$title}
Empresa: {$company}
Descripcion: {$description}
PROMPT;
    }

    public function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace(["\u{2013}", "\u{2014}", "\u{00A0}"], ['-', '-', ' '], $text));
    }

    public function extractJson(string $text): ?array
    {
        $text = $this->cleanText($text);
        $text = Str::of($text)->replace(['```json', '```JSON', '```'], '')->trim()->toString();
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end >= $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeAnalysis(array $decoded, string $title, string $description): array
    {
        $professions = collect($decoded['profesiones_encontradas'])
            ->map(fn (array $profession): array => [
                'nombre_original' => $this->cleanText($profession['nombre_original']),
                'nombre_catalogo' => $this->cleanText($profession['nombre_catalogo']),
                'evidencia' => $this->cleanText($profession['evidencia']),
                'tipo_requisito' => $profession['tipo_requisito'],
                'confianza' => round((float) $profession['confianza'], 4),
            ])
            ->unique(fn (array $profession): string => $this->normalize($profession['nombre_original']))
            ->values()
            ->all();
        $location = $this->cleanText((string) ($decoded['ubicacion'] ?? $decoded['location'] ?? ''));
        $municipality = $this->detectMunicipality($location, $title, $description);
        $department = $this->cleanText((string) ($decoded['ubicacion_departamento'] ?? $decoded['department'] ?? ''));
        $department = $department ?: ($municipality['department'] ?? $this->detectDepartment($location, $title, $description));

        if (! $location && $municipality) {
            $location = $municipality['municipality'];
        }

        return [
            'area_ids' => [],
            'areas' => [],
            'area_principal' => 'No especificado',
            'profesiones_encontradas' => $professions,
            'acepta_carreras_afines' => (bool) $decoded['acepta_carreras_afines'],
            'evidencia_carreras_afines' => $this->cleanText($decoded['evidencia_carreras_afines']),
            'area_principal_catalogo' => $decoded['area_principal_catalogo'] === 'SIN_AREA'
                ? ''
                : $this->cleanText($decoded['area_principal_catalogo']),
            'evidencia_area_principal' => $this->cleanText($decoded['evidencia_area_principal']),
            'confianza_area_principal' => round((float) $decoded['confianza_area_principal'], 4),
            'profesiones_sugeridas' => collect($professions)->pluck('nombre_original')->all(),
            'area' => 'No especificado',
            'professions' => collect($professions)->pluck('nombre_original')->implode(', ') ?: 'No especificado',
            'department' => $department,
            'location' => $location ?: 'No especificado',
            'salary' => $this->normalizeSalaryValue($decoded['sueldo'] ?? $decoded['salary'] ?? null),
            'expiration_date' => $this->cleanText((string) ($decoded['fecha_expiracion'] ?? $decoded['expiration_date'] ?? 'No especificado')) ?: 'No especificado',
            'municipality' => $municipality,
        ];
    }

    private function validateAnalysisContract(array $decoded): array
    {
        $errors = [];

        if (! array_key_exists('profesiones_encontradas', $decoded) || ! is_array($decoded['profesiones_encontradas'])) {
            $errors[] = 'profesiones_encontradas debe ser un arreglo.';
        } else {
            $catalogNames = Profesion::query()
                ->whereHas('areas')
                ->pluck('profesion_name')
                ->all();

            foreach ($decoded['profesiones_encontradas'] as $index => $profession) {
                $prefix = "profesiones_encontradas.{$index}";

                if (! is_array($profession)) {
                    $errors[] = "{$prefix} debe ser un objeto.";

                    continue;
                }

                foreach (['nombre_original', 'nombre_catalogo', 'evidencia'] as $field) {
                    if (! is_string($profession[$field] ?? null) || trim($profession[$field]) === '') {
                        $errors[] = "{$prefix}.{$field} es obligatorio.";
                    }
                }

                if (
                    is_string($profession['nombre_catalogo'] ?? null)
                    && ! in_array($profession['nombre_catalogo'], $catalogNames, true)
                ) {
                    $errors[] = "{$prefix}.nombre_catalogo no pertenece al catálogo autorizado.";
                }

                if (! in_array($profession['tipo_requisito'] ?? null, ['obligatoria', 'alternativa', 'deseable'], true)) {
                    $errors[] = "{$prefix}.tipo_requisito no es válido.";
                }

                if (
                    ! is_numeric($profession['confianza'] ?? null)
                    || (float) $profession['confianza'] < 0
                    || (float) $profession['confianza'] > 1
                ) {
                    $errors[] = "{$prefix}.confianza debe estar entre 0 y 1.";
                }

            }
        }

        if (! array_key_exists('acepta_carreras_afines', $decoded) || ! is_bool($decoded['acepta_carreras_afines'])) {
            $errors[] = 'acepta_carreras_afines debe ser booleano.';
        }

        if (! array_key_exists('evidencia_carreras_afines', $decoded) || ! is_string($decoded['evidencia_carreras_afines'])) {
            $errors[] = 'evidencia_carreras_afines debe ser texto.';
        } elseif (
            ($decoded['acepta_carreras_afines'] ?? false) === true
            && trim($decoded['evidencia_carreras_afines']) === ''
        ) {
            $errors[] = 'evidencia_carreras_afines es obligatoria cuando acepta_carreras_afines es true.';
        }

        $areaNames = Area::query()
            ->whereHas('profesions')
            ->pluck('area_name')
            ->all();
        if (! array_key_exists('area_principal_catalogo', $decoded) || ! is_string($decoded['area_principal_catalogo'])) {
            $errors[] = 'area_principal_catalogo debe ser texto.';
        } elseif (
            ! in_array($decoded['area_principal_catalogo'], ['', 'SIN_AREA'], true)
            && ! in_array($decoded['area_principal_catalogo'], $areaNames, true)
        ) {
            $errors[] = 'area_principal_catalogo no pertenece al catálogo autorizado.';
        }

        if (! array_key_exists('evidencia_area_principal', $decoded) || ! is_string($decoded['evidencia_area_principal'])) {
            $errors[] = 'evidencia_area_principal debe ser texto.';
        } elseif (
            ! in_array(($decoded['area_principal_catalogo'] ?? ''), ['', 'SIN_AREA'], true)
            && trim($decoded['evidencia_area_principal']) === ''
        ) {
            $errors[] = 'evidencia_area_principal es obligatoria cuando se selecciona un área.';
        }

        if (
            ! is_numeric($decoded['confianza_area_principal'] ?? null)
            || (float) $decoded['confianza_area_principal'] < 0
            || (float) $decoded['confianza_area_principal'] > 1
        ) {
            $errors[] = 'confianza_area_principal debe estar entre 0 y 1.';
        }

        foreach (['ubicacion_departamento', 'ubicacion', 'sueldo', 'fecha_expiracion'] as $field) {
            if (! array_key_exists($field, $decoded) || ! is_string($decoded[$field])) {
                $errors[] = "{$field} debe ser texto.";
            }
        }

        return array_values(array_unique($errors));
    }

    private function detectMunicipality(string ...$texts): ?array
    {
        $haystack = $this->normalize(implode(' ', $texts));

        if ($haystack === '') {
            return null;
        }

        foreach (config('bolivia_municipalities', []) as $municipality => $department) {
            $needle = $this->normalize((string) $municipality);

            if ($needle !== '' && preg_match('/\b'.preg_quote($needle, '/').'\b/', $haystack)) {
                return [
                    'municipality' => Str::headline($needle),
                    'department' => (string) $department,
                    'is_main_city' => $this->isMainCity($needle),
                ];
            }
        }

        return null;
    }

    private function detectDepartment(string ...$texts): string
    {
        $combined = $this->normalize(implode(' ', $texts));

        $departments = [
            'la paz' => 'La Paz',
            'santa cruz' => 'Santa Cruz',
            'cochabamba' => 'Cochabamba',
            'oruro' => 'Oruro',
            'potosi' => 'Potosí',
            'tarija' => 'Tarija',
            'chuquisaca' => 'Chuquisaca',
            'beni' => 'Beni',
            'pando' => 'Pando',
        ];

        foreach ($departments as $needle => $label) {
            if (preg_match('/\b'.preg_quote($needle, '/').'\b/', $combined)) {
                return $label;
            }
        }

        return 'No especificado';
    }

    private function isMainCity(string $location): bool
    {
        return in_array($this->normalize($location), [
            'la paz',
            'santa cruz',
            'santa cruz de la sierra',
            'cochabamba',
            'oruro',
            'potosi',
            'tarija',
            'sucre',
            'trinidad',
            'cobija',
        ], true);
    }

    private function normalizeSalaryValue(string|int|float|null $salary): string|int|float
    {
        if (is_int($salary) || is_float($salary)) {
            return $salary;
        }

        $salary = $this->cleanText((string) $salary);

        if (! $salary || in_array($this->normalize($salary), ['0', 'no especificado', 'sueldo no declarado por la institucion'], true)) {
            return 0;
        }

        if (preg_match('/\b(?:bs\.?|bob)\s*([0-9][0-9\.,\s]*)\b/i', $salary, $matches)
            || preg_match('/\b([0-9][0-9\.,\s]*)\s*(?:bs\.?|bob)\b/i', $salary, $matches)) {
            $number = preg_replace('/[^\d]/', '', $matches[1]);

            return $number !== '' ? (int) $number : $salary;
        }

        if (preg_match('/^\s*[0-9][0-9\.,\s]*\s*$/', $salary)) {
            $number = preg_replace('/[^\d]/', '', $salary);

            return $number !== '' ? (int) $number : 0;
        }

        return $salary;
    }

    private function normalize(string $value): string
    {
        return Str::of(strip_tags($this->cleanText($value)))
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    private function httpError(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $message = data_get($decoded, 'error.message') ?: data_get($decoded, 'message');
        $message = $message ? SensitiveDataSanitizer::text(strip_tags((string) $message), 220) : null;

        return trim("HTTP {$status}".($message ? ": {$message}" : ''));
    }
}

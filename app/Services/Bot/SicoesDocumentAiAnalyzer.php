<?php

namespace App\Services\Bot;

use App\Models\Area;
use App\Models\Profesion;
use App\Support\SensitiveDataSanitizer;
use App\Support\TlsVerification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SicoesDocumentAiAnalyzer
{
    public function analyze(array $document, string $text): array
    {
        $provider = config('sicoes.ai.provider', 'anthropic');
        $key = config('services.anthropic.api_key');
        $model = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
        $version = config('services.anthropic.version', '2023-06-01');
        $maxTokens = (int) config('services.anthropic.max_tokens', 4000);
        $verify = TlsVerification::option(
            'Anthropic',
            (bool) config('services.anthropic.verify_ssl', true),
            config('services.anthropic.ca_bundle'),
        );
        $prepared = $this->prepareText($text);

        if ($provider !== 'anthropic') {
            return $this->error('unsupported_provider', 'SICOES_AI_PROVIDER debe ser anthropic para SICOES.', false, $model, [
                'provider' => $provider,
                ...$prepared['meta'],
            ]);
        }

        if (! $key) {
            return $this->error('missing_api_key', 'ANTHROPIC_API_KEY no configurada.', false, $model, [
                'provider' => $provider,
                ...$prepared['meta'],
            ]);
        }

        try {
            $messageContent = $this->messageContent($document, $prepared['text']);
            $prepared['meta'] = [...$prepared['meta'], ...$messageContent['meta']];
        } catch (\Throwable $exception) {
            return $this->error(
                'visual_pdf_error',
                SensitiveDataSanitizer::text($exception->getMessage(), 500) ?: 'No se pudo preparar el PDF escaneado.',
                true,
                $model,
                ['provider' => $provider, ...$prepared['meta']],
            );
        }

        $attempts = (int) config('sicoes.ai.retries', 2);
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::withOptions(['verify' => $verify])
                    ->timeout((int) config('sicoes.ai.timeout', 120))
                    ->withHeaders([
                        'content-type' => 'application/json',
                        'x-api-key' => $key,
                        'anthropic-version' => $version,
                    ])
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => $model,
                        'max_tokens' => $maxTokens > 0 ? $maxTokens : 4000,
                        'temperature' => 0,
                        'system' => $this->systemPrompt(),
                        'messages' => [[
                            'role' => 'user',
                            'content' => $messageContent['content'],
                        ]],
                    ]);

                $body = $response->json();
                $usage = $this->usageMetadata(is_array($body) ? $body : []);

                if (! $response->successful()) {
                    $last = $this->error(
                        $this->classifyHttpError($response->status(), $response->body()),
                        $this->httpError($response->status(), $response->body()),
                        true,
                        $model,
                        [
                            'provider' => $provider,
                            'http_status' => $response->status(),
                            'anthropic_response_metadata' => SensitiveDataSanitizer::payloadMetadata($response->body()),
                            'ai_attempts' => $attempt,
                            'anthropic_attempts' => $attempt,
                            ...$usage,
                            ...$prepared['meta'],
                        ],
                    );

                    if ($attempt < $attempts && $this->shouldRetryHttp($response->status())) {
                        $this->backoff($attempt);

                        continue;
                    }

                    return $last;
                }

                $stopReason = (string) data_get($body, 'stop_reason', '');
                $rawText = $this->responseText(is_array($body) ? $body : []);

                if ($stopReason === 'refusal') {
                    return $this->error(
                        'ai_refusal',
                        'Claude rechazo la solicitud de analisis del documento SICOES.',
                        true,
                        $model,
                        [
                            'provider' => $provider,
                            'http_status' => $response->status(),
                            'anthropic_response_metadata' => SensitiveDataSanitizer::payloadMetadata($rawText),
                            'ai_attempts' => $attempt,
                            'anthropic_attempts' => $attempt,
                            'anthropic_stop_reason' => $stopReason,
                            ...$usage,
                            ...$prepared['meta'],
                        ],
                    );
                }

                $decoded = $this->extractJson($rawText);
                $validationErrors = is_array($decoded)
                    ? $this->validationErrors($decoded)
                    : ['La respuesta no contiene un objeto JSON válido.'];

                if (! is_array($decoded) || $validationErrors !== []) {
                    $last = $this->error(
                        'invalid_json',
                        'Claude respondio, pero el JSON SICOES no tiene la estructura requerida.',
                        true,
                        $model,
                        [
                            'provider' => $provider,
                            'http_status' => $response->status(),
                            'anthropic_response_metadata' => SensitiveDataSanitizer::payloadMetadata($rawText),
                            'ai_attempts' => $attempt,
                            'anthropic_attempts' => $attempt,
                            'anthropic_stop_reason' => $stopReason,
                            'ai_validation_errors' => $validationErrors,
                            ...$usage,
                            ...$prepared['meta'],
                        ],
                    );

                    if ($attempt < $attempts) {
                        $this->backoff($attempt);

                        continue;
                    }

                    return $last;
                }

                return [
                    'success' => true,
                    'used' => true,
                    'provider' => $provider,
                    'model' => $model,
                    'http_status' => $response->status(),
                    'error' => null,
                    'error_type' => null,
                    'data' => $this->normalizeResponse($decoded),
                    'anthropic_response_metadata' => SensitiveDataSanitizer::payloadMetadata($rawText),
                    'ai_attempts' => $attempt,
                    'anthropic_attempts' => $attempt,
                    'anthropic_stop_reason' => $stopReason,
                    'ai_validation_errors' => [],
                    'analyzed_at' => now()->toIso8601String(),
                    ...$usage,
                    ...$prepared['meta'],
                ];
            } catch (\Throwable $exception) {
                $last = $this->error(
                    $this->classifyThrowable($exception),
                    $this->humanThrowableMessage($exception),
                    true,
                    $model,
                    [
                        'provider' => $provider,
                        'ai_attempts' => $attempt,
                        'anthropic_attempts' => $attempt,
                        ...$prepared['meta'],
                    ],
                );

                if ($attempt < $attempts && ! in_array($last['error_type'] ?? null, ['ssl_error', 'api_key_error', 'encoding_error'], true)) {
                    $this->backoff($attempt);

                    continue;
                }

                return $last;
            }
        }

        return $last ?: $this->error('unknown', 'No se pudo analizar el documento SICOES con Claude.', true, $model, [
            'provider' => $provider,
            ...$prepared['meta'],
        ]);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Eres un extractor estricto para documentos SICOES de Bolivia.

Objetivo principal:
- Identificar si el documento es una oportunidad laboral para una persona natural.
- Extraer una ficha estructurada si corresponde a consultoria individual o a una publicacion oficial de Requerimientos de Personal.

Reglas criticas:
- Analiza UN SOLO documento. No mezcles informacion de otras convocatorias.
- Prioriza Terminos de Referencia, TDR, modalidad de contratacion, objeto, metodo de seleccion, perfil requerido, formacion academica, experiencia, funciones, requisitos legales, lugar, plazo y forma de pago.
- No inventes datos. Si falta informacion usa cadenas vacias, arreglos vacios o el tipo no_especificada.
- Devuelve solo JSON valido. No markdown, no explicaciones fuera del JSON.
- Prioriza precision sobre cantidad.
- Extrae profesiones, carreras y especialidades que sirvan para clasificar la convocatoria. No registres grados academicos generales como Magister, Maestria, Doctorado, Licenciatura, Tecnico Superior o Diplomado como profesiones; son requisitos de nivel y deben quedar en la evidencia del cargo.
- No devuelvas IDs de areas ni profesiones. Los IDs se resolveran exclusivamente en MySQL.
- nombre_catalogo solo puede copiar literalmente una profesion del catalogo proporcionado. Si no hay coincidencia segura, dejalo vacio y conserva nombre_original.
- No agregues profesiones relacionadas que no esten escritas. Si aparece "carreras afines", activa acepta_carreras_afines, conserva su evidencia y no inventes carreras adicionales.
- Cada dato extraido debe conservar una evidencia textual breve y especifica.
- Si la fila proviene de Requerimientos de Personal, evalua el cargo para persona natural y usa tipo_oportunidad "requerimiento_personal"; no exijas que diga consultoria individual.
- Si no es Requerimientos de Personal y no hay evidencia suficiente para confirmar consultoria individual/persona natural, usa tipo_oportunidad "no_determinado" y debe_descartarse true.
- Si el documento parece ser para empresa, persona juridica, firma consultora, bienes, obras, productos, equipamiento, materiales, medicamentos, alimentos, maquinaria, vehiculos, seguros, auditorias empresariales u otros procesos no laborales, debe_descartarse true.
- Si hay senales fuertes de compra de bienes/servicios/obra, descarta aunque aparezca la palabra "consultoria" en texto general.
- Si se exige NIT empresarial, matricula de comercio, experiencia institucional, propuesta empresarial, equipo empresarial, persona juridica o sociedad comercial, descarta.
- Si pide profesionales individuales, persona natural, consultor individual de linea, consultor individual por producto o consultoria individual, acepta.
- Si el objeto menciona una carretera, construccion u obra, no descartes por esa palabra aislada: decide segun la modalidad legal del proponente. Un equipo de consultores individuales que trabajara en un proyecto de obra sigue siendo elegible.
- Si existen varios items o cargos de consultores individuales, acepta y usa contract_type "multiple_individual".
- Si marcas aceptado pero tipo_oportunidad es empresa_consultora, bienes_servicios, obra, otro o no_determinado, corrige: debe_descartarse true.
- Si marcas consultoria individual y hay evidencia de empresa/persona juridica, agrega advertencia.
- Para lugar_trabajo usa solo el lugar donde el consultor prestara efectivamente servicios. No uses direcciones de consultas, entrega de propuestas, contratacion, reuniones de aclaracion o domicilio legal, salvo que el texto diga expresamente que tambien es el lugar de trabajo.
- Para duracion_contrato extrae solo el plazo contractual. No confundas validez de propuesta, garantia, cronograma de contratacion, evaluacion o presentacion de documentos.
- Para modalidad_postulacion extrae la forma real de presentar la propuesta. No confundas direcciones de consultas o reuniones.
- No redactes una descripcion publicable. Laravel la construira deterministicamente.
PROMPT;
    }

    private function userPrompt(array $document, string $text): string
    {
        $relations = ['areas:id,area_name'];
        if (Schema::hasTable('profesion_aliases')) {
            $relations[] = 'aliases:id,profesion_id,alias';
        }

        $professions = Profesion::query()
            ->with($relations)
            ->orderBy('id')
            ->get(['id', 'profesion_name'])
            ->each(function (Profesion $profession): void {
                if (! $profession->relationLoaded('aliases')) {
                    $profession->setRelation('aliases', collect());
                }
            })
            ->map(fn (Profesion $profession): string => json_encode([
                'nombre' => $profession->profesion_name,
                'areas_referencia' => $profession->areas->pluck('area_name')->values()->all(),
                'aliases_autorizados' => $profession->aliases->pluck('alias')->values()->all(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->implode("\n");

        $cuce = $document['cuce'] ?? 'No especificado';
        $sourceType = $document['source_type'] ?? 'consulting_services';
        $identifierLabel = $sourceType === 'personnel_requirements' ? 'Referencia' : 'CUCE';
        $title = $document['title'] ?? 'No especificado';
        $entity = $document['entity'] ?? 'No especificado';
        $filename = $document['filename'] ?? 'No especificado';
        $publishedAt = $document['published_at'] ?? 'No especificado';
        $preclassification = $this->preclassificationContext($document);

        return <<<PROMPT
Devuelve exclusivamente JSON valido con esta estructura exacta:

{
  "eligible": false,
  "contract_type": "individual | multiple_individual | individual_product | rejected_company | rejected_goods | rejected_works | rejected_service | other_rejected",
  "es_oportunidad_consultor_persona": false,
  "tipo_oportunidad": "consultor_linea | consultor_producto | consultoria_individual | requerimiento_personal | empresa_consultora | bienes_servicios | obra | otro | no_determinado",
  "debe_descartarse": true,
  "motivo_descarte": null,
  "evidencia_clasificacion": "",
  "titulo_objeto": "",
  "cargos": [
    {
      "nombre": "",
      "evidencia": "",
      "profesiones": [""]
    }
  ],
  "profesiones_encontradas": [
    {
      "nombre_original": "",
      "nombre_catalogo": "",
      "evidencia": "",
      "tipo_requisito": "obligatoria|alternativa|deseable",
      "confianza": 0.0
    }
  ],
  "acepta_carreras_afines": false,
  "evidencia_carreras_afines": "",
  "area_principal_catalogo": "",
  "evidencia_area_principal": "",
  "confianza_area_principal": 0.0,
  "lugar_trabajo": {
    "direccion_exacta": "",
    "municipio": "",
    "departamento": "",
    "evidencia": "",
    "documento_fuente": "",
    "confianza": 0.0,
    "requiere_revision": false,
    "direcciones_candidatas_descartadas": [
      {
        "direccion_exacta": "",
        "municipio": "",
        "departamento": "",
        "tipo": "entrega_propuestas|consultas|domicilio_legal|reunion_aclaracion|otra",
        "evidencia": "",
        "motivo_descarte": ""
      }
    ]
  },
  "duracion_contrato": {
    "texto_exacto": "",
    "evidencia": "",
    "confianza": 0.0
  },
  "modalidad_postulacion": {
    "texto_exacto": "",
    "tipo": "digital_rupe|digital_otro|fisica|correo|mixta|no_especificada",
    "evidencia": "",
    "confianza": 0.0
  },
  "cuce": {
    "valor": "",
    "evidencia": ""
  },
  "salarios": {
    "tipo": "unico|multiple|no_declarado",
    "cantidad": 0,
    "detalle": [
      {
        "cargo": "",
        "monto_bob": 0,
        "evidencia": ""
      }
    ]
  },
  "advertencias": []
}

Clasificacion obligatoria:
- Si el contexto indica source_type personnel_requirements, acepta cargos destinados a personas naturales aunque sean laborales, eventuales o de planta, y usa requerimiento_personal.
- Acepta solo oportunidades para personas consultoras: consultor individual de linea, consultor individual por producto, consultoria individual, consultores en linea, consultor/a por producto o servicios de consultoria individual con perfil profesional/persona natural.
- Descarta empresa consultora, firma consultora, empresa especializada, servicios generales de una empresa, proveedor empresarial, persona juridica, sociedad comercial, consultoria por empresa, bienes, obras, compras, productos, materiales, medicamentos, alimentos, maquinaria, vehiculos, seguros y auditorias empresariales.
- Si debe_descartarse es true, llena motivo_descarte y evidencia_clasificacion.
- eligible debe ser exactamente el inverso de debe_descartarse.
- Usa contract_type "multiple_individual" cuando el documento agrupe varios cargos o items de personas consultoras.
- Las referencias a la empresa constructora, al proyecto, a la carretera o a la obra donde trabajara el consultor son contexto y no convierten por si solas al proponente en empresa.

Reglas de datos:
- CUCE: extrae del documento solo si aparece explicitamente. La fila SICOES es la fuente primaria y Laravel comparara ambos valores.
- Profesiones: conserva nombre_original exactamente como aparece y una evidencia que contenga ese requisito. nombre_catalogo debe ser un nombre literal del catalogo o una cadena vacia. Si se exige un grado como Magister/Doctor en un area amplia, registra el area como profesion detectable y conserva el grado solo en cargos/evidencia.
- Cargos: si hay varios cargos realmente diferentes, incluye en cada cargo las profesiones o areas de conocimiento que lo habilitan usando exactamente sus valores nombre_original. Copia tambien las carreras/areas de cada cargo en profesiones_encontradas; no dejes esa lista vacia cuando el documento enumere areas amplias. Una lista de profesiones alternativas para un mismo cargo no convierte esas profesiones en cargos separados.
- tipo_requisito debe ser obligatoria, alternativa o deseable. confianza debe estar entre 0 y 1.
- No dupliques una profesion equivalente; conserva la evidencia mas clara.
- Si aparece "carreras afines" o equivalente, activa acepta_carreras_afines y copia la frase en evidencia_carreras_afines. No agregues por tu cuenta todas las profesiones del area.
- area_principal_catalogo puede contener literalmente un area_referencia del catalogo, pero nunca es fuente de IDs y debe tener evidencia y confianza.
- Lugar: prioriza lugar de trabajo/prestacion, luego lugar/sede de funciones, destino del cargo, TDR del cargo y municipio explicito del cargo. Separa direccion_exacta, municipio y departamento.
- Registra como direcciones_candidatas_descartadas las direcciones de entrega, consultas, domicilio legal o reunion que no sean lugar de trabajo.
- Si hay dos lugares de trabajo contradictorios, conserva el mejor candidato, activa requiere_revision y agrega advertencia.
- Duracion: texto_exacto y evidencia deben referirse al plazo contractual, no a validez de propuesta, fecha limite, garantia, evaluacion o presentacion de documentos.
- Modalidad: tipo digital_rupe solo si el texto indica presentacion electronica mediante RUPE/SICOES; usa fisica, correo, mixta o no_especificada segun la evidencia real.
- Salarios: monto_bob es entero sin separadores. No sumes, promedies ni intercambies montos. Conserva el orden de cargos del documento.
- salarios.tipo es multiple si hay varios cargos con sueldo, unico si existe un solo sueldo aplicable y no_declarado si no hay remuneracion identificable.
- Si falta un monto, conserva el cargo con monto_bob 0 y no inventes el valor.

Catalogo autorizado de profesiones (un objeto JSON por linea, sin IDs):
{$professions}

Contexto de la fila SICOES:
Tipo de publicacion: {$sourceType}
{$identifierLabel} fila: {$cuce}
Titulo u objeto: {$title}
Entidad: {$entity}
Archivo: {$filename}
Fecha publicacion: {$publishedAt}
Preclasificacion local basada en senales explicitas: {$preclassification}

Texto completo del documento:
{$text}
PROMPT;
    }

    /** @return array{content: array<int, array<string, mixed>>, meta: array<string, mixed>} */
    private function messageContent(array $document, string $text): array
    {
        $content = [];
        $path = (string) ($document['visual_pdf_path'] ?? '');
        $visual = $path !== '' && is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';

        if ($visual) {
            $maxBytes = max(1, (int) config('sicoes.ai.max_visual_pdf_bytes', 20 * 1024 * 1024));
            $size = filesize($path) ?: 0;

            if ($size > $maxBytes) {
                throw new \RuntimeException('El PDF escaneado supera el límite permitido para análisis visual.');
            }

            $binary = file_get_contents($path);
            if ($binary === false || ! str_starts_with($binary, '%PDF')) {
                throw new \RuntimeException('El archivo descargado no es un PDF válido para análisis visual.');
            }

            $content[] = [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => base64_encode($binary),
                ],
            ];
        }

        $content[] = [
            'type' => 'text',
            'text' => $this->repairUtf8($this->userPrompt($document, $text)),
        ];

        return [
            'content' => $content,
            'meta' => [
                'document_visual_pdf_sent' => $visual,
                'document_visual_pdf_bytes' => $visual ? (filesize($path) ?: 0) : 0,
            ],
        ];
    }

    private function prepareText(string $text): array
    {
        $max = (int) config('sicoes.ai.max_text_chars', 250000);
        $max = $max > 0 ? $max : 250000;
        $utf8Repaired = ! mb_check_encoding($text, 'UTF-8');
        $text = $this->repairUtf8($text);
        $text = trim($text);
        $length = mb_strlen($text);
        $truncated = $length > $max;

        return [
            'text' => $truncated ? mb_substr($text, 0, $max) : $text,
            'meta' => [
                'document_text_original_length' => $length,
                'document_text_sent_length' => $truncated ? $max : $length,
                'document_text_truncated_for_ai' => $truncated,
                'document_text_utf8_repaired' => $utf8Repaired,
            ],
        ];
    }

    private function repairUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $repaired = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return is_string($repaired)
            ? $repaired
            : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    private function normalizeResponse(array $data): array
    {
        $location = is_array($data['lugar_trabajo'] ?? null) ? $data['lugar_trabajo'] : [];
        $duration = is_array($data['duracion_contrato'] ?? null) ? $data['duracion_contrato'] : [];
        $modality = is_array($data['modalidad_postulacion'] ?? null) ? $data['modalidad_postulacion'] : [];
        $cuce = is_array($data['cuce'] ?? null) ? $data['cuce'] : [];
        $salary = is_array($data['salarios'] ?? null) ? $data['salarios'] : [];
        $type = $this->tipoOportunidad($data['tipo_oportunidad'] ?? 'no_determinado');
        $mustDiscard = (bool) ($data['debe_descartarse'] ?? true);

        if (in_array($type, ['empresa_consultora', 'bienes_servicios', 'obra', 'otro', 'no_determinado'], true)) {
            $mustDiscard = true;
        }

        $normalizedSalary = $this->normalizeSalaries($salary);
        $eligible = ! $mustDiscard && (bool) ($data['es_oportunidad_consultor_persona'] ?? $data['eligible'] ?? false);

        return [
            'eligible' => $eligible,
            'contract_type' => $this->contractType($data['contract_type'] ?? null, $type, $mustDiscard),
            'es_consultoria_individual' => $eligible,
            'es_oportunidad_consultor_persona' => $eligible,
            'tipo_oportunidad' => $type,
            'debe_descartarse' => $mustDiscard,
            'motivo_descarte' => $this->nullableText($data['motivo_descarte'] ?? null),
            'evidencia_clasificacion' => $this->text($data['evidencia_clasificacion'] ?? ''),
            'titulo_objeto' => $this->text($data['titulo_objeto'] ?? ''),
            'cargos' => $this->normalizeRoles($data['cargos'] ?? []),
            'profesiones_encontradas' => $this->normalizeProfessions($data['profesiones_encontradas'] ?? []),
            'acepta_carreras_afines' => (bool) ($data['acepta_carreras_afines'] ?? false),
            'evidencia_carreras_afines' => $this->text($data['evidencia_carreras_afines'] ?? ''),
            'area_principal_catalogo' => $this->text($data['area_principal_catalogo'] ?? ''),
            'evidencia_area_principal' => $this->text($data['evidencia_area_principal'] ?? ''),
            'confianza_area_principal' => $this->confidence($data['confianza_area_principal'] ?? 0),
            'lugar_trabajo' => [
                'direccion_exacta' => $this->text($location['direccion_exacta'] ?? ''),
                'municipio' => $this->text($location['municipio'] ?? ''),
                'departamento' => $this->text($location['departamento'] ?? ''),
                'evidencia' => $this->text($location['evidencia'] ?? ''),
                'documento_fuente' => $this->text($location['documento_fuente'] ?? ''),
                'confianza' => $this->confidence($location['confianza'] ?? 0),
                'requiere_revision' => (bool) ($location['requiere_revision'] ?? false),
                'direcciones_candidatas_descartadas' => $this->normalizeDiscardedAddresses(
                    $location['direcciones_candidatas_descartadas'] ?? [],
                ),
            ],
            'duracion_contrato' => [
                'texto_exacto' => $this->text($duration['texto_exacto'] ?? ''),
                'evidencia' => $this->text($duration['evidencia'] ?? ''),
                'confianza' => $this->confidence($duration['confianza'] ?? 0),
            ],
            'modalidad_postulacion' => [
                'texto_exacto' => $this->text($modality['texto_exacto'] ?? ''),
                'tipo' => $this->modalityType($modality['tipo'] ?? 'no_especificada'),
                'evidencia' => $this->text($modality['evidencia'] ?? ''),
                'confianza' => $this->confidence($modality['confianza'] ?? 0),
            ],
            'cuce' => [
                'valor' => $this->text($cuce['valor'] ?? ''),
                'evidencia' => $this->text($cuce['evidencia'] ?? ''),
            ],
            'salarios' => $normalizedSalary,
            'advertencias' => $this->stringList($data['advertencias'] ?? []),
        ];
    }

    private function hasRequiredShape(array $data): bool
    {
        return $this->validationErrors($data) === [];
    }

    private function validationErrors(array $data): array
    {
        $errors = [];
        $requiredTypes = [
            'eligible' => 'boolean',
            'contract_type' => 'string',
            'es_oportunidad_consultor_persona' => 'boolean',
            'tipo_oportunidad' => 'string',
            'debe_descartarse' => 'boolean',
            'evidencia_clasificacion' => 'string',
            'titulo_objeto' => 'string',
            'cargos' => 'array',
            'profesiones_encontradas' => 'array',
            'acepta_carreras_afines' => 'boolean',
            'evidencia_carreras_afines' => 'string',
            'area_principal_catalogo' => 'string',
            'evidencia_area_principal' => 'string',
            'lugar_trabajo' => 'array',
            'duracion_contrato' => 'array',
            'modalidad_postulacion' => 'array',
            'cuce' => 'array',
            'salarios' => 'array',
            'advertencias' => 'array',
        ];

        foreach ($requiredTypes as $key => $type) {
            if (! array_key_exists($key, $data)) {
                $errors[] = "Falta {$key}.";

                continue;
            }

            $valid = match ($type) {
                'boolean' => is_bool($data[$key]),
                'string' => is_string($data[$key]),
                'array' => is_array($data[$key]),
                default => false,
            };

            if (! $valid) {
                $errors[] = "{$key} debe ser {$type}.";
            }
        }

        if (
            ! array_key_exists('motivo_descarte', $data)
            || (! is_null($data['motivo_descarte']) && ! is_string($data['motivo_descarte']))
        ) {
            $errors[] = 'motivo_descarte debe ser texto o null.';
        }

        $professionKeys = [];
        $officialProfessionNames = Schema::hasTable('profesions')
            ? Profesion::query()->pluck('profesion_name')->all()
            : [];

        foreach ((array) ($data['profesiones_encontradas'] ?? []) as $index => $profession) {
            if (! is_array($profession)) {
                $errors[] = "profesiones_encontradas.{$index} debe ser objeto.";

                continue;
            }

            foreach (['nombre_original', 'nombre_catalogo', 'evidencia', 'tipo_requisito', 'confianza'] as $key) {
                if (! array_key_exists($key, $profession)) {
                    $errors[] = "Falta profesiones_encontradas.{$index}.{$key}.";
                }
            }

            if (trim((string) ($profession['nombre_original'] ?? '')) === '') {
                $errors[] = "profesiones_encontradas.{$index}.nombre_original es obligatorio.";
            }
            if (trim((string) ($profession['evidencia'] ?? '')) === '') {
                $errors[] = "profesiones_encontradas.{$index}.evidencia es obligatoria.";
            }
            if (! in_array($profession['tipo_requisito'] ?? null, ['obligatoria', 'alternativa', 'deseable'], true)) {
                $errors[] = "profesiones_encontradas.{$index}.tipo_requisito no es válido.";
            }
            if (! $this->validConfidence($profession['confianza'] ?? null)) {
                $errors[] = "profesiones_encontradas.{$index}.confianza debe estar entre 0 y 1.";
            }

            $catalogName = trim((string) ($profession['nombre_catalogo'] ?? ''));
            if ($catalogName !== '' && ! in_array($catalogName, $officialProfessionNames, true)) {
                $errors[] = "profesiones_encontradas.{$index}.nombre_catalogo no pertenece al catálogo autorizado.";
            }

            $deduplicationKey = $this->normalize(
                $catalogName !== '' ? $catalogName : (string) ($profession['nombre_original'] ?? ''),
            );
            if ($deduplicationKey !== '' && in_array($deduplicationKey, $professionKeys, true)) {
                $errors[] = "profesiones_encontradas.{$index} duplica una profesión anterior.";
            }
            $professionKeys[] = $deduplicationKey;
        }

        foreach ([
            'lugar_trabajo' => ['direccion_exacta', 'municipio', 'departamento', 'evidencia', 'documento_fuente', 'confianza', 'requiere_revision'],
            'duracion_contrato' => ['texto_exacto', 'evidencia', 'confianza'],
            'modalidad_postulacion' => ['texto_exacto', 'tipo', 'evidencia', 'confianza'],
            'cuce' => ['valor', 'evidencia'],
            'salarios' => ['tipo', 'cantidad', 'detalle'],
        ] as $parent => $keys) {
            if (! is_array($data[$parent] ?? null)) {
                continue;
            }

            foreach ($keys as $key) {
                if (! array_key_exists($key, $data[$parent])) {
                    $errors[] = "Falta {$parent}.{$key}.";
                }
            }
        }

        foreach (['lugar_trabajo', 'duracion_contrato', 'modalidad_postulacion'] as $parent) {
            if (
                is_array($data[$parent] ?? null)
                && ! $this->validConfidence(data_get($data, "{$parent}.confianza"))
            ) {
                $errors[] = "{$parent}.confianza debe estar entre 0 y 1.";
            }
        }

        if (! $this->validConfidence($data['confianza_area_principal'] ?? null)) {
            $errors[] = 'confianza_area_principal debe estar entre 0 y 1.';
        }

        $areaName = trim((string) ($data['area_principal_catalogo'] ?? ''));
        if (
            $areaName !== ''
            && Schema::hasTable('areas')
            && ! Area::query()->where('area_name', $areaName)->exists()
        ) {
            $errors[] = 'area_principal_catalogo no pertenece al catálogo autorizado.';
        }

        $locationHasValue = collect([
            data_get($data, 'lugar_trabajo.direccion_exacta'),
            data_get($data, 'lugar_trabajo.municipio'),
            data_get($data, 'lugar_trabajo.departamento'),
        ])->contains(fn (mixed $value): bool => trim((string) $value) !== '');
        if ($locationHasValue && trim((string) data_get($data, 'lugar_trabajo.evidencia', '')) === '') {
            $errors[] = 'lugar_trabajo.evidencia es obligatoria cuando existe ubicación.';
        }

        if (
            trim((string) data_get($data, 'duracion_contrato.texto_exacto', '')) !== ''
            && trim((string) data_get($data, 'duracion_contrato.evidencia', '')) === ''
        ) {
            $errors[] = 'duracion_contrato.evidencia es obligatoria cuando existe duración.';
        }

        if (
            data_get($data, 'modalidad_postulacion.tipo') !== 'no_especificada'
            && trim((string) data_get($data, 'modalidad_postulacion.evidencia', '')) === ''
        ) {
            $errors[] = 'modalidad_postulacion.evidencia es obligatoria cuando existe modalidad.';
        }

        if (
            trim((string) data_get($data, 'cuce.valor', '')) !== ''
            && trim((string) data_get($data, 'cuce.evidencia', '')) === ''
        ) {
            $errors[] = 'cuce.evidencia es obligatoria cuando Claude extrae un CUCE.';
        }

        if (
            is_array($data['modalidad_postulacion'] ?? null)
            && ! in_array(data_get($data, 'modalidad_postulacion.tipo'), [
                'digital_rupe',
                'digital_otro',
                'fisica',
                'correo',
                'mixta',
                'no_especificada',
            ], true)
        ) {
            $errors[] = 'modalidad_postulacion.tipo no es válido.';
        }

        if (
            is_array($data['salarios'] ?? null)
            && ! in_array(data_get($data, 'salarios.tipo'), ['unico', 'multiple', 'no_declarado'], true)
        ) {
            $errors[] = 'salarios.tipo no es válido.';
        }

        foreach ((array) data_get($data, 'salarios.detalle', []) as $index => $salary) {
            if (! is_array($salary)) {
                $errors[] = "salarios.detalle.{$index} debe ser objeto.";

                continue;
            }

            foreach (['cargo', 'monto_bob', 'evidencia'] as $key) {
                if (! array_key_exists($key, $salary)) {
                    $errors[] = "Falta salarios.detalle.{$index}.{$key}.";
                }
            }

            if (
                (trim((string) ($salary['cargo'] ?? '')) !== '' || (int) ($salary['monto_bob'] ?? 0) > 0)
                && trim((string) ($salary['evidencia'] ?? '')) === ''
            ) {
                $errors[] = "salarios.detalle.{$index}.evidencia es obligatoria.";
            }
        }

        return array_values(array_unique($errors));
    }

    private function extractJson(string $text): ?array
    {
        $text = $this->cleanText($text);
        $text = Str::of($text)
            ->replace(['```json', '```JSON', '```Json', '```'], '')
            ->trim()
            ->toString();
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end >= $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function responseText(array $response): string
    {
        return collect($response['content'] ?? [])
            ->filter(fn ($part): bool => is_array($part) && ($part['type'] ?? null) === 'text')
            ->map(fn (array $part): string => (string) ($part['text'] ?? ''))
            ->implode("\n");
    }

    private function usageMetadata(array $response): array
    {
        $usage = data_get($response, 'usage');
        $inputTokens = data_get($usage, 'input_tokens');
        $outputTokens = data_get($usage, 'output_tokens');

        return [
            'ai_usage_metadata' => is_array($usage) ? $usage : null,
            'ai_prompt_tokens' => $inputTokens,
            'ai_output_tokens' => $outputTokens,
            'ai_total_tokens' => is_numeric($inputTokens) && is_numeric($outputTokens) ? $inputTokens + $outputTokens : null,
            'anthropic_usage_metadata' => is_array($usage) ? $usage : null,
            'anthropic_input_tokens' => $inputTokens,
            'anthropic_output_tokens' => $outputTokens,
            'anthropic_total_tokens' => is_numeric($inputTokens) && is_numeric($outputTokens) ? $inputTokens + $outputTokens : null,
        ];
    }

    private function error(string $type, string $message, bool $used, string $model, array $extra = []): array
    {
        unset($extra['raw_response'], $extra['anthropic_raw_response']);
        $extra = SensitiveDataSanitizer::context($extra, 500, 4, 50);

        return [
            'success' => false,
            'used' => $used,
            'provider' => $extra['provider'] ?? 'anthropic',
            'model' => $model,
            'http_status' => $extra['http_status'] ?? null,
            'error' => SensitiveDataSanitizer::text($message, 500),
            'error_type' => $type,
            'data' => null,
            ...$extra,
        ];
    }

    private function classifyHttpError(int $status, string $body): string
    {
        if (in_array($status, [401, 403], true)) {
            return 'api_key_error';
        }

        if ($status === 429) {
            return 'rate_limited';
        }

        if ($status >= 500) {
            return 'server_error';
        }

        $message = $this->normalize($this->httpError($status, $body));

        if (Str::contains($message, ['quota', 'exceeded', 'too many requests', 'rate limit'])) {
            return 'rate_limited';
        }

        return 'http_error';
    }

    private function classifyThrowable(\Throwable $exception): string
    {
        $message = $this->normalize($exception->getMessage());

        if (Str::contains($message, ['malformed utf-8', 'invalid utf-8', 'json_encode error'])) {
            return 'encoding_error';
        }

        if (Str::contains($message, ['curl error 60', 'ssl certificate problem'])) {
            return 'ssl_error';
        }

        if (Str::contains($message, ['timeout', 'timed out', 'operation timed out'])) {
            return 'timeout';
        }

        if (Str::contains($message, ['unauthorized', 'forbidden', 'api key'])) {
            return 'api_key_error';
        }

        if (Str::contains($message, ['quota', 'exceeded', 'too many requests', 'rate limit'])) {
            return 'rate_limited';
        }

        return 'http_error';
    }

    private function humanThrowableMessage(\Throwable $exception): string
    {
        return match ($this->classifyThrowable($exception)) {
            'ssl_error' => 'Error SSL al conectar con Anthropic. Verifica curl.cainfo, openssl.cafile o el certificado del entorno.',
            'api_key_error' => 'Error de configuracion Anthropic. Verifica ANTHROPIC_API_KEY.',
            'encoding_error' => 'El texto extraido contiene caracteres invalidos y no pudo codificarse para Anthropic.',
            default => SensitiveDataSanitizer::text($exception->getMessage(), 500)
                ?: 'Error al conectar con Anthropic.',
        };
    }

    private function httpError(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $message = data_get($decoded, 'error.message') ?: data_get($decoded, 'message');

        return 'HTTP '.$status.($message ? ': '.SensitiveDataSanitizer::text($message, 300) : '');
    }

    private function shouldRetryHttp(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function backoff(int $attempt): void
    {
        usleep(1_000_000 * $attempt);
    }

    private function tipoOportunidad(mixed $value): string
    {
        $value = $this->normalize($this->text($value));
        $allowed = [
            'consultor_linea',
            'consultor_producto',
            'consultoria_individual',
            'requerimiento_personal',
            'empresa_consultora',
            'bienes_servicios',
            'obra',
            'otro',
            'no_determinado',
        ];

        return in_array($value, $allowed, true) ? $value : 'no_determinado';
    }

    private function preclassificationContext(array $document): string
    {
        $classification = $document['preclassification'] ?? null;

        if (! is_array($classification)) {
            return 'No disponible; clasifica usando el documento completo.';
        }

        return json_encode([
            'decision' => $classification['decision'] ?? null,
            'contract_type' => $classification['contract_type'] ?? null,
            'evidence' => $classification['evidence'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'No disponible.';
    }

    private function normalizeSalaries(array $salary): array
    {
        $rows = collect(is_array($salary['detalle'] ?? null) ? $salary['detalle'] : [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'cargo' => $this->text($row['cargo'] ?? ''),
                'monto_bob' => $this->integerAmount($row['monto_bob'] ?? null),
                'evidencia' => $this->text($row['evidencia'] ?? ''),
            ])
            ->values();
        $declaredRows = $rows
            ->filter(fn (array $row): bool => $row['monto_bob'] > 0)
            ->values();
        $amounts = $declaredRows->pluck('monto_bob')->unique()->values();
        $roles = $declaredRows
            ->pluck('cargo')
            ->filter()
            ->map(fn (string $role): string => $this->normalize($role))
            ->unique()
            ->values();
        $multipleDistinctItems = $roles->count() > 1
            || ($declaredRows->count() > 1 && $roles->isEmpty());
        $type = $this->normalize($this->text($salary['tipo'] ?? 'no_declarado'));

        if ($type === 'multiple' || $multipleDistinctItems || $amounts->count() > 1) {
            $value = 1;
            $type = 'multiple';
        } elseif ($type === 'unico' && $amounts->count() === 1) {
            $value = (int) $amounts->first();
        } else {
            $value = 0;
            $type = 'no_declarado';
        }

        return [
            'tipo' => in_array($type, ['unico', 'multiple', 'no_declarado'], true) ? $type : 'no_declarado',
            'cantidad' => max(0, (int) ($salary['cantidad'] ?? $rows->count())),
            'detalle' => $rows->all(),
            'valor' => max(0, $value),
        ];
    }

    private function normalizeProfessions(mixed $professions): array
    {
        if (! is_array($professions)) {
            return [];
        }

        return collect($professions)
            ->filter(fn (mixed $profession): bool => is_array($profession))
            ->map(fn (array $profession): array => [
                'nombre_original' => $this->text($profession['nombre_original'] ?? ''),
                'nombre_catalogo' => $this->text($profession['nombre_catalogo'] ?? ''),
                'evidencia' => $this->text($profession['evidencia'] ?? ''),
                'tipo_requisito' => in_array(
                    $profession['tipo_requisito'] ?? null,
                    ['obligatoria', 'alternativa', 'deseable'],
                    true,
                ) ? $profession['tipo_requisito'] : 'obligatoria',
                'confianza' => $this->confidence($profession['confianza'] ?? 0),
            ])
            ->unique(fn (array $profession): string => $this->normalize(
                $profession['nombre_catalogo'] ?: $profession['nombre_original'],
            ))
            ->values()
            ->all();
    }

    private function normalizeRoles(mixed $roles): array
    {
        if (! is_array($roles)) {
            return [];
        }

        return collect($roles)
            ->filter(fn (mixed $role): bool => is_array($role))
            ->map(fn (array $role): array => [
                'nombre' => $this->text($role['nombre'] ?? ''),
                'evidencia' => $this->text($role['evidencia'] ?? ''),
                'profesiones' => collect(is_array($role['profesiones'] ?? null) ? $role['profesiones'] : [])
                    ->map(fn (mixed $profession): string => $this->text($profession))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $role): bool => $role['nombre'] !== '')
            ->values()
            ->all();
    }

    private function normalizeDiscardedAddresses(mixed $candidates): array
    {
        if (! is_array($candidates)) {
            return [];
        }

        return collect($candidates)
            ->filter(fn (mixed $candidate): bool => is_array($candidate))
            ->map(fn (array $candidate): array => [
                'direccion_exacta' => $this->text($candidate['direccion_exacta'] ?? ''),
                'municipio' => $this->text($candidate['municipio'] ?? ''),
                'departamento' => $this->text($candidate['departamento'] ?? ''),
                'tipo' => $this->text($candidate['tipo'] ?? ''),
                'evidencia' => $this->text($candidate['evidencia'] ?? ''),
                'motivo_descarte' => $this->text($candidate['motivo_descarte'] ?? ''),
            ])
            ->values()
            ->all();
    }

    private function modalityType(mixed $value): string
    {
        $value = $this->normalize($this->text($value));

        return in_array($value, [
            'digital_rupe',
            'digital_otro',
            'fisica',
            'correo',
            'mixta',
            'no_especificada',
        ], true) ? $value : 'no_especificada';
    }

    private function validConfidence(mixed $value): bool
    {
        return is_numeric($value) && (float) $value >= 0 && (float) $value <= 1;
    }

    private function confidence(mixed $value): float
    {
        return $this->validConfidence($value) ? round((float) $value, 4) : 0.0;
    }

    private function integerAmount(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            return max(0, (int) $value);
        }

        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }

        if (preg_match('/\d[\d.,\s]*/', $value, $matches) !== 1) {
            return 0;
        }

        $number = preg_replace('/\s+/', '', $matches[0]) ?: '';
        $number = preg_replace('/[.,]\d{2}$/', '', $number) ?: $number;
        $digits = preg_replace('/\D/', '', $number) ?: '';

        return $digits !== '' ? (int) $digits : 0;
    }

    private function contractType(mixed $value, string $opportunityType, bool $mustDiscard): string
    {
        $normalized = $this->normalize($this->text($value));
        $allowed = [
            'individual',
            'multiple_individual',
            'individual_product',
            'rejected_company',
            'rejected_goods',
            'rejected_works',
            'rejected_service',
            'other_rejected',
        ];

        if (in_array($normalized, $allowed, true)) {
            return $normalized;
        }

        if ($mustDiscard) {
            return match ($opportunityType) {
                'empresa_consultora' => 'rejected_company',
                'bienes_servicios' => 'rejected_goods',
                'obra' => 'rejected_works',
                default => 'other_rejected',
            };
        }

        return $opportunityType === 'consultor_producto' ? 'individual_product' : 'individual';
    }

    private function nullableText(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text === '' || $this->normalize($text) === 'null' ? null : $text;
    }

    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : explode(',', (string) $value))
            ->map(fn ($item): string => $this->text($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique(fn (string $item): string => $this->normalize($item))
            ->values()
            ->all();
    }

    private function integerList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit(trim($id))))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function text(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return trim($this->cleanText((string) $value));
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace(["\u{2013}", "\u{2014}", "\u{00A0}"], ['-', '-', ' '], $text));
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->trim('_')
            ->toString();
    }
}

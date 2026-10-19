<?php

namespace App\Support;

final class BotUiLabels
{
    public static function previewStatus(mixed $status): string
    {
        return match (self::normalize($status)) {
            'preview' => 'Pendiente de revisión',
            'edited' => 'Editada',
            'published' => 'Publicada',
            'error' => 'Con error',
            default => 'Estado no disponible',
        };
    }

    public static function processStatus(mixed $status): string
    {
        return match (self::normalize($status)) {
            'ok', 'success', 'saved', 'finished', 'completed' => 'Completado',
            'partial' => 'Parcial',
            'queued', 'pending' => 'En espera',
            'running', 'processing' => 'En proceso',
            'error', 'failed' => 'Con error',
            'empty', 'no_results' => 'Sin resultados',
            default => 'No disponible',
        };
    }

    public static function discardType(mixed $type): string
    {
        return match (self::normalize($type)) {
            'rejected_company', 'empresa_consultora' => 'Empresa consultora',
            'rejected_goods', 'bienes_servicios' => 'Compra de bienes o servicios',
            'rejected_works', 'obra' => 'Obra',
            'rejected_service' => 'Servicio no individual',
            'other_rejected', 'otro' => 'Otro tipo de descarte',
            'no_determinado' => 'No determinado',
            default => 'Otro tipo de descarte',
        };
    }

    public static function errorType(mixed $type): string
    {
        return match (self::normalize($type)) {
            'http_error' => 'Error de conexión',
            'invalid_json' => 'Respuesta inválida',
            'invalid_schema' => 'Respuesta incompleta de la IA',
            'invalid_configuration' => 'Configuración inválida',
            'api_key_error' => 'Error de clave de acceso',
            'missing_api_key' => 'Falta la clave de acceso',
            'ssl_error' => 'Error de certificado',
            'timeout' => 'Tiempo de espera agotado',
            'rate_limited', 'quota_exceeded', 'quota_exceeded_skipped' => 'Límite de solicitudes alcanzado',
            'server_error' => 'Error del servidor',
            'encoding_error' => 'Error de codificación',
            'unsupported_provider' => 'Proveedor no compatible',
            'ai_refusal' => 'Solicitud rechazada por la IA',
            'unknown' => 'Error no identificado',
            default => 'Otro error',
        };
    }

    /**
     * Devuelve motivos aptos para mostrar al administrador sin exponer códigos
     * internos ni mensajes técnicos en inglés.
     */
    public static function previewIssues(mixed $rawData): array
    {
        if (! is_array($rawData)) {
            return [];
        }

        $technicalErrors = collect([
            data_get($rawData, 'document_error'),
            data_get($rawData, 'ai_error'),
            data_get($rawData, 'anthropic_error'),
            data_get($rawData, 'gemini_error'),
            data_get($rawData, 'profession_assignment_error'),
        ])->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        $hasEncodingError = $technicalErrors->contains(
            fn (string $error): bool => str_contains(self::normalize($error), 'malformed utf-8')
                || str_contains(self::normalize($error), 'incorrectly encoded'),
        );

        $issues = collect((array) data_get($rawData, 'motivos_revision', []))
            ->map(fn (mixed $reason): ?string => self::humanIssue($reason))
            ->filter();

        if ($hasEncodingError) {
            $issues->push('La IA no pudo analizar el documento porque el texto extraído contenía caracteres inválidos. Vuelve a ejecutar la extracción para analizarlo nuevamente.');
        } else {
            $issues->push(...collect((array) data_get($rawData, 'manual_review_reasons', []))
                ->map(fn (mixed $reason): ?string => self::humanIssue($reason))
                ->filter()
                ->all());

            if ($issues->isEmpty()) {
                $type = collect([
                    data_get($rawData, 'document_error_type'),
                    data_get($rawData, 'ai_error_type'),
                    data_get($rawData, 'anthropic_error_type'),
                    data_get($rawData, 'gemini_error_type'),
                ])->first(fn (mixed $value): bool => is_string($value) && trim($value) !== '');

                if ($type) {
                    $issues->push(self::errorType($type).'.');
                }
            }
        }

        $publishError = data_get($rawData, 'publish_error');
        if (is_string($publishError) && trim($publishError) !== '') {
            $issues->push('No se pudo publicar: '.trim($publishError));
        }

        if ($issues->isEmpty() && data_get($rawData, 'manual_review_required')) {
            $issues->push('La clasificación automática requiere revisión manual.');
        }

        $usesAnthropic = self::normalize(data_get($rawData, 'source')) === 'sicoes'
            || self::normalize(data_get($rawData, 'ai_provider')) === 'anthropic';

        return $issues
            ->map(fn (string $issue): string => trim($issue))
            ->map(fn (string $issue): string => $usesAnthropic
                ? str_ireplace('Gemini', 'Claude', $issue)
                : $issue)
            ->filter()
            ->unique(fn (string $issue): string => self::normalize($issue))
            ->values()
            ->all();
    }

    public static function professionMatchType(mixed $type): string
    {
        return match (self::normalize($type)) {
            'exacta' => 'Coincidencia exacta',
            'alias' => 'Alias reconocido',
            'normalizada' => 'Coincidencia normalizada',
            'aproximada' => 'Coincidencia aproximada',
            'expansion_area_afin' => 'Carrera afín agregada',
            default => 'Coincidencia automática',
        };
    }

    private static function normalize(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private static function humanIssue(mixed $reason): ?string
    {
        if (! is_string($reason) || trim($reason) === '') {
            return null;
        }

        $reason = trim($reason);
        $normalized = self::normalize($reason);
        $knownTypes = [
            'http_error',
            'invalid_json',
            'invalid_schema',
            'invalid_configuration',
            'api_key_error',
            'missing_api_key',
            'ssl_error',
            'timeout',
            'rate_limited',
            'quota_exceeded',
            'quota_exceeded_skipped',
            'server_error',
            'encoding_error',
            'unsupported_provider',
            'ai_refusal',
            'unknown',
        ];

        if (in_array($normalized, $knownTypes, true)) {
            return self::errorType($normalized).'.';
        }

        if (str_contains($normalized, 'malformed utf-8') || str_contains($normalized, 'incorrectly encoded')) {
            return 'El texto extraído contiene caracteres inválidos y debe analizarse nuevamente.';
        }

        if (preg_match('/^[a-z0-9_\-]+$/', $normalized) === 1) {
            return 'La clasificación automática requiere revisión manual.';
        }

        return $reason;
    }
}

<?php

namespace Tests\Unit;

use App\Support\BotUiLabels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BotUiLabelsTest extends TestCase
{
    #[DataProvider('previewStatuses')]
    public function test_preview_statuses_are_translated_to_spanish(string $status, string $label): void
    {
        $this->assertSame($label, BotUiLabels::previewStatus($status));
    }

    public static function previewStatuses(): array
    {
        return [
            'pending review' => ['preview', 'Pendiente de revisión'],
            'edited' => ['edited', 'Editada'],
            'published' => ['published', 'Publicada'],
            'error' => ['error', 'Con error'],
            'unknown' => ['unexpected_status', 'Estado no disponible'],
        ];
    }

    public function test_process_and_diagnostic_statuses_do_not_expose_internal_english_values(): void
    {
        $this->assertSame('Parcial', BotUiLabels::processStatus('partial'));
        $this->assertSame('En proceso', BotUiLabels::processStatus('running'));
        $this->assertSame('Empresa consultora', BotUiLabels::discardType('rejected_company'));
        $this->assertSame('Respuesta inválida', BotUiLabels::errorType('invalid_json'));
        $this->assertSame('Carrera afín agregada', BotUiLabels::professionMatchType('expansion_area_afin'));
    }

    public function test_preview_issues_explain_encoding_failures_in_spanish(): void
    {
        $issues = BotUiLabels::previewIssues([
            'ai_error_type' => 'http_error',
            'manual_review_reasons' => ['http_error'],
            'ai_error' => 'json_encode error: Malformed UTF-8 characters, possibly incorrectly encoded',
        ]);

        $this->assertSame([
            'La IA no pudo analizar el documento porque el texto extraído contenía caracteres inválidos. Vuelve a ejecutar la extracción para analizarlo nuevamente.',
        ], $issues);
    }

    public function test_preview_issues_preserve_spanish_review_reasons(): void
    {
        $issues = BotUiLabels::previewIssues([
            'motivos_revision' => [
                'La convocatoria incluye profesiones pertenecientes a varias áreas sin un área dominante.',
            ],
        ]);

        $this->assertSame([
            'La convocatoria incluye profesiones pertenecientes a varias áreas sin un área dominante.',
        ], $issues);
    }

    public function test_sicoes_legacy_gemini_reasons_are_displayed_as_claude(): void
    {
        $issues = BotUiLabels::previewIssues([
            'source' => 'sicoes',
            'motivos_revision' => [
                'Gemini no detectó ninguna profesión explícita.',
                'No se pudo resolver ninguna profesión contra el catálogo.',
            ],
        ]);

        $this->assertSame([
            'Claude no detectó ninguna profesión explícita.',
            'No se pudo resolver ninguna profesión contra el catálogo.',
        ], $issues);
    }
}

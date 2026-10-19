<?php

namespace Tests\Unit;

use App\Jobs\ProcessSicoesJob;
use App\Livewire\Admin\Bot\BotPreview;
use App\Services\Bot\SicoesDocumentImporterService;
use App\Services\Bot\SicoesRunnerService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class SicoesProcessingRulesTest extends TestCase
{
    public function test_analysis_cache_key_includes_prompt_version(): void
    {
        config()->set('sicoes.ai.provider', 'anthropic');
        config()->set('services.anthropic.model', 'claude-haiku-4-5-20251001');

        $importer = (new ReflectionClass(SicoesDocumentImporterService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SicoesDocumentImporterService::class, 'analysisKey');
        $method->setAccessible(true);
        $document = ['document_hash' => 'stable-document-hash'];

        $first = $method->invoke($importer, $document, 'prompt-v1');
        $same = $method->invoke($importer, $document, 'prompt-v1');
        $changed = $method->invoke($importer, $document, 'prompt-v2');

        $this->assertSame($first, $same);
        $this->assertNotSame($first, $changed);
    }

    public function test_analysis_cache_key_includes_schema_and_catalog_versions(): void
    {
        config()->set('sicoes.ai.provider', 'anthropic');
        config()->set('services.anthropic.model', 'claude-haiku-4-5-20251001');

        $importer = (new ReflectionClass(SicoesDocumentImporterService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SicoesDocumentImporterService::class, 'analysisKey');
        $method->setAccessible(true);
        $document = ['document_hash' => 'stable-document-hash'];

        $base = $method->invoke($importer, $document, 'prompt-v1', 'schema-v1', 'catalog-v1');
        $schemaChanged = $method->invoke($importer, $document, 'prompt-v1', 'schema-v2', 'catalog-v1');
        $catalogChanged = $method->invoke($importer, $document, 'prompt-v1', 'schema-v1', 'catalog-v2');

        $this->assertNotSame($base, $schemaChanged);
        $this->assertNotSame($base, $catalogChanged);
    }

    public function test_analysis_cache_key_includes_document_hash_and_model(): void
    {
        config()->set('sicoes.ai.provider', 'anthropic');
        config()->set('services.anthropic.model', 'claude-model-v1');

        $importer = (new ReflectionClass(SicoesDocumentImporterService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SicoesDocumentImporterService::class, 'analysisKey');
        $method->setAccessible(true);

        $base = $method->invoke($importer, ['document_hash' => 'hash-v1'], 'prompt-v1', 'schema-v1', 'catalog-v1');
        $documentChanged = $method->invoke($importer, ['document_hash' => 'hash-v2'], 'prompt-v1', 'schema-v1', 'catalog-v1');
        config()->set('services.anthropic.model', 'claude-model-v2');
        $modelChanged = $method->invoke($importer, ['document_hash' => 'hash-v1'], 'prompt-v1', 'schema-v1', 'catalog-v1');

        $this->assertNotSame($base, $documentChanged);
        $this->assertNotSame($base, $modelChanged);
    }

    public function test_documents_found_prefers_real_imported_documents(): void
    {
        $job = new ProcessSicoesJob('2026-07-07', 1, 'test-user', 'test-batch');
        $method = new ReflectionMethod(ProcessSicoesJob::class, 'documentsFound');
        $method->setAccessible(true);

        $this->assertSame(5, $method->invoke(
            $job,
            ['sicoes_items' => 1],
            ['total_items_feed' => 5],
        ));
    }

    public function test_documents_found_falls_back_to_runner_when_import_unexpectedly_returns_zero(): void
    {
        $job = new ProcessSicoesJob('2026-08-24', 1, 'test-user', 'test-batch');
        $method = new ReflectionMethod(ProcessSicoesJob::class, 'documentsFound');
        $method->setAccessible(true);

        $this->assertSame(1, $method->invoke(
            $job,
            ['sicoes_items' => 1],
            ['total_items_feed' => 0, 'no_results' => false],
        ));
    }

    public function test_personnel_slug_is_preserved_for_document_discovery(): void
    {
        $importer = (new ReflectionClass(SicoesDocumentImporterService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SicoesDocumentImporterService::class, 'dateSlug');
        $method->setAccessible(true);

        $this->assertSame('24-08-2026-personal', $method->invoke($importer, '24-08-2026-personal'));
    }

    public function test_assisted_download_idle_timeout_covers_manual_intervention_window(): void
    {
        config()->set('sicoes.process.idle_timeout', 240);
        config()->set('sicoes.manual_download.timeout_ms', 600000);

        $method = new ReflectionMethod(SicoesRunnerService::class, 'processIdleTimeout');
        $method->setAccessible(true);
        $runner = new SicoesRunnerService;

        $this->assertSame(240, $method->invoke($runner, false));
        $this->assertSame(660, $method->invoke($runner, true));
    }

    public function test_runner_accepts_an_explicitly_allowed_empty_final_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sicoes-empty-');
        file_put_contents($path, json_encode([
            'fecha' => '27/08/2026',
            'total_fichas' => 0,
            'fichas_finales' => [],
        ], JSON_THROW_ON_ERROR));

        try {
            $method = new ReflectionMethod(SicoesRunnerService::class, 'validatedFinalItems');
            $method->setAccessible(true);

            $this->assertSame([], $method->invoke(new SicoesRunnerService, $path, true));
        } finally {
            @unlink($path);
        }
    }

    public function test_runner_rejects_an_unexpected_empty_final_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sicoes-empty-');
        file_put_contents($path, json_encode([
            'fecha' => '27/08/2026',
            'total_fichas' => 0,
            'fichas_finales' => [],
        ], JSON_THROW_ON_ERROR));

        try {
            $method = new ReflectionMethod(SicoesRunnerService::class, 'validatedFinalItems');
            $method->setAccessible(true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('JSON final vacio');
            $method->invoke(new SicoesRunnerService, $path);
        } finally {
            @unlink($path);
        }
    }

    #[DataProvider('expirationCases')]
    public function test_sicoes_expiration_preserves_real_time_and_controls_invalid_values(
        string $source,
        string $expected,
    ): void {
        $importer = (new ReflectionClass(SicoesDocumentImporterService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SicoesDocumentImporterService::class, 'expirationDate');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($importer, ['expires_at' => $source]));
    }

    public static function expirationCases(): array
    {
        return [
            'date with time' => ['20/07/2026 15:00', '2026-07-20 15:00:00'],
            'date only' => ['20/07/2026', '2026-07-20 23:59:00'],
            'invalid text' => ['fecha irrelevante', ''],
        ];
    }

    public function test_sicoes_publication_never_uses_fifteen_day_fallback(): void
    {
        $component = (new ReflectionClass(BotPreview::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BotPreview::class, 'expirationForAnnouncement');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($component, 'fecha irrelevante', true);
    }
}

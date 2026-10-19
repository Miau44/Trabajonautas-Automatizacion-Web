<?php

namespace Tests\Feature;

use App\Jobs\ProcessSicoesJob;
use App\Livewire\Admin\Bot\BotPreview;
use App\Models\Area;
use App\Models\BotCompany;
use App\Models\BotSource;
use App\Models\BotVacancyPreview;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Location;
use App\Models\Profesion;
use App\Models\SicoesScrapeBatch;
use App\Models\SicoesScrapeBatchItem;
use App\Models\User;
use App\Services\Bot\SicoesDocumentImporterService;
use App\Services\Bot\SicoesRunnerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SicoesBatchIsolationTest extends TestCase
{
    private ?string $basePath = null;

    private BotSource $source;

    private BotCompany $botCompany;

    private Area $area;

    private Profesion $profession;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->basePath = storage_path('framework/testing/sicoes-batch-isolation-'.Str::uuid());
        $this->deleteTemporaryRunDirectory();
        $this->createSchema();
        $this->authenticateAdministrator();

        $this->source = BotSource::create([
            'name' => 'SICOES',
            'slug' => 'sicoes',
            'scraper_type' => 'sicoes',
            'active' => true,
        ]);
        $this->botCompany = BotCompany::create([
            'bot_source_id' => $this->source->id,
            'name' => 'SICOES',
            'slug' => 'sicoes',
            'active' => true,
        ]);
        $this->area = Area::create([
            'area_name' => 'Area SOCIAL',
            'description' => 'Area SOCIAL',
        ]);
        $this->profession = Profesion::create(['profesion_name' => 'Trabajo Social']);
        $this->area->profesions()->sync([$this->profession->id]);
        Location::create(['location_name' => 'Santa Cruz']);
        Location::create(['location_name' => 'No especificado']);
        CompanyType::create(['company_type_name' => 'Publica']);

        config()->set('sicoes.ai.provider', 'anthropic');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.model', 'claude-haiku-4-5-20251001');
    }

    protected function tearDown(): void
    {
        $this->deleteTemporaryRunDirectory();
        Storage::disk('public')->deleteDirectory('convocatorias/sicoes');

        parent::tearDown();
    }

    private function deleteTemporaryRunDirectory(): void
    {
        if ($this->basePath === null) {
            return;
        }

        $testingRoot = str_replace('\\', '/', storage_path('framework/testing')).'/';
        $candidate = str_replace('\\', '/', $this->basePath);

        if (! str_starts_with($candidate, $testingRoot.'sicoes-batch-isolation-')) {
            throw new \RuntimeException('Se rechazó limpiar una ruta fuera del directorio temporal de pruebas.');
        }

        File::deleteDirectory($this->basePath);
    }

    private function authenticateAdministrator(): void
    {
        $role = (new Role)->forceFill([
            'name' => 'ADMIN',
            'guard_name' => 'web',
        ]);
        $user = (new User)->forceFill([
            'id' => (string) Str::uuid(),
            'name' => 'BOT Test Administrator',
            'email' => 'bot-admin@example.test',
            'email_verified_at' => now(),
        ]);
        $user->setRelation('roles', collect([$role]));

        $this->actingAs($user);
    }

    public function test_same_document_is_cached_and_batches_do_not_mix_previews(): void
    {
        $run = $this->writeRun(
            cuce: '26-0291-07-1669139-1-2',
            title: 'Equipo PRP Construccion Carretera Okinawa',
            text: 'Consultoria Individual de Linea. ITEM 1 Coordinador Bs. 14.402 mensual. ITEM 2 Especialista Social Bs. 12.788 mensual. Persona natural adjudicada como consultor.',
        );
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response($this->acceptedClaudeResponse()),
        ]);

        $firstBatch = $this->batch('00000000-0000-4000-8000-000000000001');
        $first = app(SicoesDocumentImporterService::class)->importRun(
            run: $run,
            botCompanyId: $this->botCompany->id,
            userId: 'test-user',
            batchId: $firstBatch->id,
        );

        $this->assertSame(1, $first['saved']);
        $this->assertSame(1, $first['ai_calls']);
        $this->assertSame(0, $first['ai_cache_hits']);
        $preview = BotVacancyPreview::sole();
        $this->assertSame('1', $preview->salary);
        $this->assertSame('2026-07-20 23:59:00', $preview->expiration_date);
        $this->assertSame([$this->profession->id], $preview->selected_profession_ids);
        $this->assertStringContainsString('#doc-', $preview->source_url);

        $secondBatch = $this->batch('00000000-0000-4000-8000-000000000002');
        $second = app(SicoesDocumentImporterService::class)->importRun(
            run: $run,
            botCompanyId: $this->botCompany->id,
            userId: 'test-user',
            batchId: $secondBatch->id,
        );

        $this->assertSame(0, $second['ai_calls']);
        $this->assertSame(1, $second['ai_cache_hits']);
        $this->assertSame(1, BotVacancyPreview::count());
        $this->assertSame($preview->id, SicoesScrapeBatchItem::where('batch_id', $secondBatch->id)->value('preview_id'));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-api-key', 'test-key'));

        $component = $this->mountedPreview($secondBatch->id);
        $this->assertSame([$preview->id], $this->visiblePreviewIds($component));

        $emptyBatch = $this->batch(
            '00000000-0000-4000-8000-000000000003',
            ['created_at' => now()->addMinute(), 'updated_at' => now()->addMinute()],
        );
        $component = $this->mountedPreview();
        $this->assertSame($emptyBatch->id, $component->currentBatchId);
        $this->assertSame([], $this->visiblePreviewIds($component));
    }

    public function test_multiple_roles_from_different_areas_create_separate_publishable_previews(): void
    {
        $civilArea = Area::create([
            'area_name' => 'Area CIVIL Y CONSTRUCCION',
            'description' => 'Area CIVIL Y CONSTRUCCION',
        ]);
        $civilProfession = Profesion::create(['profesion_name' => 'Ingenieria Civil']);
        $civilArea->profesions()->sync([$civilProfession->id]);
        $run = $this->writeRun(
            cuce: '26-0291-07-1669139-1-2',
            title: 'Equipo multidisciplinario',
            text: 'Consultoria Individual de Linea. ITEM 1 Especialista Social. ITEM 2 Ingeniero Civil.',
        );
        $response = $this->acceptedClaudeResponse();
        $payload = json_decode($response['content'][0]['text'], true);
        $payload['titulo_objeto'] = 'Equipo multidisciplinario';
        $payload['cargos'] = [
            [
                'nombre' => 'Especialista Social',
                'evidencia' => 'ITEM 1 Especialista Social',
                'profesiones' => ['Especialista Social'],
            ],
            [
                'nombre' => 'Ingeniero Civil',
                'evidencia' => 'ITEM 2 Ingeniero Civil',
                'profesiones' => ['Ingeniero Civil'],
            ],
        ];
        $payload['profesiones_encontradas'][] = [
            'nombre_original' => 'Ingeniero Civil',
            'nombre_catalogo' => 'Ingenieria Civil',
            'evidencia' => 'ITEM 2 Ingeniero Civil',
            'tipo_requisito' => 'obligatoria',
            'confianza' => 0.98,
        ];
        $payload['area_principal_catalogo'] = '';
        $payload['evidencia_area_principal'] = '';
        $payload['confianza_area_principal'] = 0;
        $response['content'][0]['text'] = json_encode($payload);
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response($response),
        ]);
        $batch = $this->batch('00000000-0000-4000-8000-000000000071');

        $summary = app(SicoesDocumentImporterService::class)->importRun(
            run: $run,
            botCompanyId: $this->botCompany->id,
            userId: 'test-user',
            batchId: $batch->id,
        );

        $this->assertSame(1, $summary['total_items_feed']);
        $this->assertSame(1, $summary['document_processed']);
        $this->assertSame(2, $summary['saved']);
        $this->assertSame(2, $summary['shown_in_batch']);
        $this->assertSame(1, $summary['ai_calls']);
        $this->assertCount(2, $summary['preview_ids']);
        $this->assertSame(2, SicoesScrapeBatchItem::where('batch_id', $batch->id)->count());
        $this->assertEqualsCanonicalizing(
            [$this->area->id, $civilArea->id],
            BotVacancyPreview::pluck('selected_area_id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [[$this->profession->id], [$civilProfession->id]],
            BotVacancyPreview::get()->pluck('selected_profession_ids')->all(),
        );
        $this->assertTrue(BotVacancyPreview::get()->every(fn (BotVacancyPreview $preview): bool => $preview->status === 'preview'));
    }

    public function test_gestor_social_keeps_four_cross_area_professions_in_one_publishable_preview(): void
    {
        $communicationArea = Area::create(['area_name' => 'Area COMUNICACION']);
        $environmentalArea = Area::create(['area_name' => 'Area AMBIENTAL']);
        $communication = Profesion::create(['profesion_name' => 'Comunicación Social']);
        $environmental = Profesion::create(['profesion_name' => 'Ingeniería Ambiental']);
        $sociology = Profesion::create(['profesion_name' => 'Sociología']);
        $forestry = Profesion::create(['profesion_name' => 'Ingeniería Forestal']);
        $communicationArea->profesions()->sync([$communication->id]);
        $environmentalArea->profesions()->sync([$environmental->id, $forestry->id]);
        $this->area->profesions()->attach($sociology->id);
        $run = $this->writeRun(
            cuce: '26-0291-07-1669140-1-2',
            title: 'Gestor Social 5',
            text: 'Formación en Comunicación Social, Ingeniería Ambiental, Sociología o Ingeniería Forestal.',
        );
        $response = $this->acceptedClaudeResponse();
        $payload = json_decode($response['content'][0]['text'], true);
        $payload['contract_type'] = 'individual';
        $payload['titulo_objeto'] = 'Gestor Social 5';
        $payload['cargos'] = [[
            'nombre' => 'Gestor Social 5',
            'evidencia' => 'Cargo: Gestor Social 5',
            'profesiones' => [
                'Comunicación Social',
                'Ingeniería Ambiental',
                'Sociología',
                'Ingeniería Forestal',
            ],
        ]];
        $payload['profesiones_encontradas'] = collect($payload['cargos'][0]['profesiones'])
            ->map(fn (string $name): array => [
                'nombre_original' => $name,
                'nombre_catalogo' => $name,
                'evidencia' => "Formación en {$name}.",
                'tipo_requisito' => 'alternativa',
                'confianza' => 0.98,
            ])->all();
        $payload['area_principal_catalogo'] = 'Area SOCIAL';
        $payload['evidencia_area_principal'] = 'El cargo y las funciones son de gestión social.';
        $payload['confianza_area_principal'] = 0.55;
        $payload['cuce']['valor'] = '26-0291-07-1669140-1-2';
        $payload['cuce']['evidencia'] = 'CUCE: 26-0291-07-1669140-1-2';
        $response['content'][0]['text'] = json_encode($payload);
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response($response),
        ]);
        $batch = $this->batch('00000000-0000-4000-8000-000000000076');

        $summary = app(SicoesDocumentImporterService::class)->importRun(
            run: $run,
            botCompanyId: $this->botCompany->id,
            userId: 'test-user',
            batchId: $batch->id,
        );

        $preview = BotVacancyPreview::query()->sole();
        $this->assertSame(1, $summary['saved']);
        $this->assertSame(1, $summary['shown_in_batch']);
        $this->assertSame('preview', $preview->status);
        $this->assertSame($this->area->id, $preview->selected_area_id);
        $this->assertEqualsCanonicalizing(
            [$communication->id, $environmental->id, $sociology->id, $forestry->id],
            $preview->selected_profession_ids,
        );
        $this->assertFalse((bool) data_get($preview->raw_data, 'manual_review_required'));
    }

    public function test_strong_company_document_is_recorded_as_discarded_without_preview_or_ai_call(): void
    {
        $run = $this->writeRun(
            cuce: '26-1510-00-1672333-1-1',
            title: 'Plan Autonomico de Desarrollo',
            text: 'La empresa consultora debe presentar matricula de comercio vigente y poder del representante legal.',
        );
        Http::fake();
        $batch = $this->batch('00000000-0000-4000-8000-000000000010');

        $result = app(SicoesDocumentImporterService::class)->importRun(
            run: $run,
            botCompanyId: $this->botCompany->id,
            userId: 'test-user',
            batchId: $batch->id,
        );

        $this->assertSame(1, $result['document_discarded']);
        $this->assertSame(0, $result['ai_calls']);
        $this->assertSame(0, BotVacancyPreview::count());
        $item = SicoesScrapeBatchItem::sole();
        $this->assertSame('discarded', $item->status);
        $this->assertSame('rejected_company', $item->contract_type);
        $this->assertFalse($item->eligible);
        Http::assertNothingSent();
    }

    public function test_changed_prompt_version_does_not_reuse_cached_analysis(): void
    {
        $run = $this->writeRun(
            cuce: '26-0291-07-1669139-1-2',
            title: 'Consultoria Individual de Linea',
            text: 'Consultoria Individual de Linea para persona natural.',
        );
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response($this->acceptedClaudeResponse()),
        ]);
        $importer = app(SicoesDocumentImporterService::class);
        $firstBatch = $this->batch('00000000-0000-4000-8000-000000000011');

        $first = $importer->importRun($run, $this->botCompany->id, 'test-user', $firstBatch->id);
        $cachedItem = SicoesScrapeBatchItem::where('batch_id', $firstBatch->id)->sole();
        $keyMethod = new ReflectionMethod(SicoesDocumentImporterService::class, 'analysisKey');
        $keyMethod->setAccessible(true);
        $cachedItem->update([
            'analysis_key' => $keyMethod->invoke(
                $importer,
                ['document_hash' => $cachedItem->document_hash],
                'previous-prompt-version',
            ),
        ]);

        $secondBatch = $this->batch('00000000-0000-4000-8000-000000000012');
        $second = $importer->importRun($run, $this->botCompany->id, 'test-user', $secondBatch->id);

        $this->assertSame(1, $first['ai_calls']);
        $this->assertSame(1, $second['ai_calls']);
        $this->assertSame(0, $second['ai_cache_hits']);
        Http::assertSentCount(2);
    }

    public function test_changed_profession_catalog_does_not_reuse_cached_analysis(): void
    {
        $run = $this->writeRun(
            cuce: '26-0291-07-1669139-1-2',
            title: 'Consultoria Individual de Linea',
            text: 'Consultoria Individual de Linea. Formación requerida: Trabajo Social.',
        );
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response($this->acceptedClaudeResponse()),
        ]);
        $importer = app(SicoesDocumentImporterService::class);

        $first = $importer->importRun(
            $run,
            $this->botCompany->id,
            'test-user',
            $this->batch('00000000-0000-4000-8000-000000000013')->id,
        );

        $newProfession = Profesion::create(['profesion_name' => 'Sociología']);
        $this->area->profesions()->attach($newProfession->id);

        $second = $importer->importRun(
            $run,
            $this->botCompany->id,
            'test-user',
            $this->batch('00000000-0000-4000-8000-000000000014')->id,
        );

        $this->assertSame(1, $first['ai_calls']);
        $this->assertSame(1, $second['ai_calls']);
        $this->assertSame(0, $second['ai_cache_hits']);
        Http::assertSentCount(2);
    }

    public function test_portal_cuce_has_priority_and_conflict_requires_review(): void
    {
        $portalCuce = '26-0291-07-1669139-1-2';
        $documentCuce = '26-9999-99-9999999-9-9';
        $run = $this->writeRun(
            cuce: $portalCuce,
            title: 'Consultoria Individual de Linea',
            text: "Consultoria Individual de Linea. CUCE {$documentCuce}.",
        );
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response($this->claudeResponseWith([
                'cuce' => [
                    'valor' => $documentCuce,
                    'evidencia' => "CUCE {$documentCuce}",
                ],
            ])),
        ]);

        app(SicoesDocumentImporterService::class)->importRun(
            $run,
            $this->botCompany->id,
            'test-user',
            $this->batch('00000000-0000-4000-8000-000000000015')->id,
        );

        $preview = BotVacancyPreview::sole();
        $this->assertSame($portalCuce, data_get($preview->raw_data, 'cuce'));
        $this->assertSame($portalCuce, data_get($preview->raw_data, 'cuce_portal'));
        $this->assertSame($documentCuce, data_get($preview->raw_data, 'cuce_documento'));
        $this->assertTrue(data_get($preview->raw_data, 'cuce_contradictorio'));
        $this->assertSame('error', $preview->status);
        $this->assertStringContainsString($portalCuce, $preview->description);
    }

    public function test_informational_ai_warnings_do_not_turn_valid_preview_into_error(): void
    {
        $run = $this->writeRun(
            cuce: '26-0291-07-1669139-1-2',
            title: 'Consultoria Individual de Linea',
            text: 'Consultoria Individual de Linea. Formación requerida: Trabajo Social.',
        );
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response($this->claudeResponseWith([
                'advertencias' => [
                    'El documento contiene el DBC completo.',
                    'La duración llega hasta el cierre de la gestión.',
                ],
            ])),
        ]);

        app(SicoesDocumentImporterService::class)->importRun(
            $run,
            $this->botCompany->id,
            'test-user',
            $this->batch('00000000-0000-4000-8000-000000000023')->id,
        );

        $preview = BotVacancyPreview::sole();
        $this->assertSame('preview', $preview->status);
        $this->assertFalse(data_get($preview->raw_data, 'manual_review_required'));
        $this->assertCount(2, data_get($preview->raw_data, 'warnings', []));
    }

    public function test_edited_preview_preserves_manual_fields_and_professions(): void
    {
        $run = $this->writeRun(
            cuce: '26-0291-07-1669139-1-2',
            title: 'Consultoria Individual de Linea',
            text: 'Consultoria Individual de Linea. Formación requerida: Trabajo Social.',
        );
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response($this->acceptedClaudeResponse()),
        ]);
        $importer = app(SicoesDocumentImporterService::class);
        $firstBatch = $this->batch('00000000-0000-4000-8000-000000000016');
        $importer->importRun($run, $this->botCompany->id, 'test-user', $firstBatch->id);
        $preview = BotVacancyPreview::sole();
        $preview->update([
            'title' => 'Título corregido manualmente',
            'description' => '<p>Descripción manual protegida.</p>',
            'selected_profession_ids' => [$this->profession->id],
            'status' => 'edited',
            'raw_data' => array_merge($preview->raw_data ?? [], ['manual_professions_locked' => true]),
        ]);

        $second = $importer->importRun(
            $run,
            $this->botCompany->id,
            'test-user',
            $this->batch('00000000-0000-4000-8000-000000000017')->id,
        );

        $preview->refresh();
        $this->assertSame(0, $second['ai_calls']);
        $this->assertSame(1, $second['ai_cache_hits']);
        $this->assertSame('Título corregido manualmente', $preview->title);
        $this->assertSame('<p>Descripción manual protegida.</p>', $preview->description);
        $this->assertSame([$this->profession->id], $preview->selected_profession_ids);
        $this->assertSame('edited', $preview->status);
    }

    public function test_published_preview_is_immutable_and_is_not_reanalyzed(): void
    {
        $run = $this->writeRun(
            cuce: '26-0291-07-1669139-1-2',
            title: 'Consultoria Individual de Linea',
            text: 'Consultoria Individual de Linea. Formación requerida: Trabajo Social.',
        );
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response($this->acceptedClaudeResponse()),
        ]);
        $importer = app(SicoesDocumentImporterService::class);
        $importer->importRun(
            $run,
            $this->botCompany->id,
            'test-user',
            $this->batch('00000000-0000-4000-8000-000000000018')->id,
        );
        $preview = BotVacancyPreview::sole();
        $preview->update([
            'title' => 'Publicación protegida',
            'status' => 'published',
        ]);

        $second = $importer->importRun(
            $run,
            $this->botCompany->id,
            'test-user',
            $this->batch('00000000-0000-4000-8000-000000000019')->id,
        );

        $preview->refresh();
        $this->assertSame(1, $second['already_published']);
        $this->assertSame(0, $second['ai_calls']);
        $this->assertSame('Publicación protegida', $preview->title);
        $this->assertSame('published', $preview->status);
        Http::assertSentCount(1);
    }

    public function test_document_without_text_creates_reviewable_error_without_ai_call(): void
    {
        $runWithoutText = $this->writeRun(
            cuce: '26-0291-07-1669139-1-2',
            title: 'Consultoria Individual de Linea',
            text: 'Texto que será eliminado.',
        );
        File::delete($this->basePath.'/salida/resultados/07-07-2099/textos-extraidos/01_26-0291-07-1669139-1-2.txt');
        Http::fake();
        $withoutText = app(SicoesDocumentImporterService::class)->importRun(
            $runWithoutText,
            $this->botCompany->id,
            'test-user',
            $this->batch('00000000-0000-4000-8000-000000000021')->id,
        );

        $this->assertSame(1, $withoutText['document_errors']);
        $this->assertSame(0, $withoutText['ai_errors']);
        $this->assertSame('error', BotVacancyPreview::sole()->status);
        Http::assertNothingSent();
    }

    public function test_unavailable_claude_creates_reviewable_error_without_stray_request(): void
    {
        $run = $this->writeRun(
            cuce: '26-0291-07-1669139-1-2',
            title: 'Consultoria Individual de Linea',
            text: 'Consultoria Individual de Linea. Formación requerida: Trabajo Social.',
        );
        config()->set('services.anthropic.api_key', null);
        Http::fake();

        $result = app(SicoesDocumentImporterService::class)->importRun(
            $run,
            $this->botCompany->id,
            'test-user',
            $this->batch('00000000-0000-4000-8000-000000000022')->id,
        );

        $preview = BotVacancyPreview::sole();
        $this->assertSame(1, $result['document_errors']);
        $this->assertSame(1, $result['ai_errors']);
        $this->assertSame('missing_api_key', data_get($preview->raw_data, 'ai_error_type'));
        $this->assertSame('error', $preview->status);
        Http::assertNothingSent();
    }

    public function test_missing_batch_does_not_fall_back_to_latest_batch(): void
    {
        $latestBatch = $this->batch('00000000-0000-4000-8000-000000000020');
        $preview = $this->preview('Preview from latest batch', 'https://example.test/latest');
        $this->batchItem($latestBatch, $preview, 'latest-document');

        $component = $this->mountedPreview('00000000-0000-4000-8000-999999999999');

        $this->assertTrue($component->batchNotFound);
        $this->assertNull($component->currentBatchId);
        $this->assertSame([], $this->visiblePreviewIds($component));
    }

    public function test_batch_status_messages_are_explicit(): void
    {
        $queued = $this->batch('00000000-0000-4000-8000-000000000031', ['status' => 'queued']);
        $running = $this->batch('00000000-0000-4000-8000-000000000032', ['status' => 'running']);
        $finished = $this->batch('00000000-0000-4000-8000-000000000033', [
            'status' => 'completed',
            'documents_processed' => 5,
            'discarded_count' => 5,
            'previews_count' => 0,
        ]);
        $failed = $this->batch('00000000-0000-4000-8000-000000000034', ['status' => 'failed']);

        $this->livewirePreview($queued->id)->assertSee('La ejecucion esta en espera.');
        $this->livewirePreview($running->id)->assertSee('La ejecucion esta procesando documentos.');
        $this->livewirePreview($finished->id)
            ->assertSee('Se procesaron 5 documentos. 5 fueron descartados. No existen convocatorias publicables en este lote.');
        $this->livewirePreview($failed->id)->assertSee('La ejecucion finalizo con error.');
    }

    public function test_preview_status_badges_are_displayed_in_spanish(): void
    {
        $batch = $this->batch('00000000-0000-4000-8000-000000000072');
        $pending = $this->preview('Pendiente', 'https://example.test/pending');
        $edited = $this->preview('Editada', 'https://example.test/edited');
        $edited->update(['status' => 'edited']);
        $failed = $this->preview('Con error', 'https://example.test/error');
        $failed->update(['status' => 'error']);
        $this->batchItem($batch, $pending, 'pending-document');
        $editedItem = $this->batchItem($batch, $edited, 'edited-document');
        $editedItem->update(['status' => 'edited']);
        $failedItem = $this->batchItem($batch, $failed, 'error-document');
        $failedItem->update(['status' => 'error']);

        $this->livewirePreview($batch->id)
            ->assertSee('Pendiente de revisión')
            ->assertSee('Editada')
            ->assertSee('Con error');
    }

    public function test_confirmations_use_embedded_dialogs_instead_of_browser_alerts(): void
    {
        $batch = $this->batch('00000000-0000-4000-8000-000000000078');
        $preview = $this->preview('Vista con diálogo', 'https://example.test/dialog-preview');
        $this->batchItem($batch, $preview, 'dialog-preview');
        $html = $this->livewirePreview($batch->id)->html();

        $this->assertStringContainsString('<dialog', $html);
        $this->assertStringContainsString('openConfirmation', $html);
        $this->assertStringNotContainsString('wire:confirm', $html);
        $this->assertStringNotContainsString('Swal.fire', $html);
    }

    public function test_error_preview_with_missing_location_can_be_opened_and_explains_the_failure(): void
    {
        $batch = $this->batch('00000000-0000-4000-8000-000000000073');
        $unspecifiedLocation = Location::query()
            ->where('location_name', 'No especificado')
            ->firstOrFail();
        $failed = $this->preview(
            'Consultoría individual por producto',
            'https://example.test/consultoria-producto',
        );
        $failed->update([
            'status' => 'error',
            'department' => 'No especificado',
            'location' => 'No especificado',
            'selected_location_ids' => [$unspecifiedLocation->id],
            'raw_data' => [
                'ai_error_type' => 'http_error',
                'manual_review_required' => true,
                'manual_review_reasons' => ['http_error'],
                'ai_error' => 'json_encode error: Malformed UTF-8 characters, possibly incorrectly encoded',
            ],
        ]);
        $this->batchItem($batch, $failed, 'encoding-error-document');

        $component = $this->livewirePreview($batch->id)
            ->assertSee('Motivo del error')
            ->assertSee('La IA no pudo analizar el documento porque el texto extraído contenía caracteres inválidos.');

        $component
            ->call('edit', $failed->id)
            ->assertSet('showModal', true)
            ->assertSet('editingId', $failed->id)
            ->assertSee('Por qué requiere revisión')
            ->assertSee('Ubicaciones')
            ->assertDontSee('Ciudad o localidad')
            ->assertHasNoErrors();

        $companyId = $component->get('form.selected_company_id');
        $component
            ->set('form.title', 'Consultoría individual por producto corregida')
            ->set('form.description', '<p>Descripción revisada.</p>')
            ->set('form.salary', 0)
            ->set('form.selected_company_id', $companyId)
            ->set('form.selected_area_id', $this->area->id)
            ->set('form.selected_profession_ids', [$this->profession->id])
            ->set('form.selected_location_ids', [Location::where('location_name', 'Santa Cruz')->value('id')])
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $failed->refresh();
        $this->assertSame('Santa Cruz', $failed->department);
        $this->assertSame('Santa Cruz', $failed->location);
        $this->assertSame('edited', $failed->status);
    }

    public function test_multiarea_classification_warning_can_be_published_anyway_with_all_professions(): void
    {
        $batch = $this->batch('00000000-0000-4000-8000-000000000074');
        $environmentalArea = Area::create(['area_name' => 'Area AMBIENTAL']);
        $environmentalProfession = Profesion::create(['profesion_name' => 'Ingeniería Ambiental']);
        $environmentalArea->profesions()->sync([$environmentalProfession->id]);
        $company = Company::create([
            'company_name' => 'SICOES',
            'company_type_id' => CompanyType::query()->value('id'),
        ]);
        $preview = $this->preview('Gestor Social 5', 'https://example.test/gestor-social-5');
        $preview->update([
            'status' => 'error',
            'description' => '<p>Consultoría individual con perfil profesional.</p>',
            'department' => 'Santa Cruz',
            'location' => 'Santa Cruz',
            'expiration_date' => '2099-09-09 23:59:00',
            'salary' => '11076',
            'selected_company_id' => $company->id,
            'selected_area_id' => null,
            'selected_profession_ids' => [$this->profession->id, $environmentalProfession->id],
            'selected_location_ids' => [Location::where('location_name', 'Santa Cruz')->value('id')],
            'raw_data' => [
                'ai_success' => true,
                'anthropic_success' => true,
                'manual_review_reasons' => [
                    'La confianza del área principal seleccionada por Claude es insuficiente.',
                    'La convocatoria incluye profesiones pertenecientes a varias áreas sin un área dominante.',
                ],
                'profession_resolution' => [
                    'profession_ids' => [$this->profession->id, $environmentalProfession->id],
                    'area_ids' => [$this->area->id, $environmentalArea->id],
                    'profesiones_no_identificadas' => [],
                    'profesiones_ambiguas' => [],
                    'motivos_revision' => [
                        'La confianza del área principal seleccionada por Claude es insuficiente.',
                        'La convocatoria incluye profesiones pertenecientes a varias áreas sin un área dominante.',
                    ],
                ],
            ],
        ]);
        $this->batchItem($batch, $preview, 'gestor-social-5');

        $this->livewirePreview($batch->id)
            ->assertSee('Publicar de todas formas')
            ->call('publishAnyway', $preview->id)
            ->assertHasNoErrors()
            ->assertSet('errorMessage', null);

        $preview->refresh();
        $announcement = Announcement::query()->sole();
        $this->assertSame('published', $preview->status);
        $this->assertTrue((bool) data_get($preview->raw_data, 'published_anyway'));
        $this->assertEqualsCanonicalizing(
            [$this->profession->id, $environmentalProfession->id],
            $announcement->profesions()->pluck('profesions.id')->all(),
        );
    }

    public function test_old_multiarea_preview_can_update_expiration_and_pro_without_reselecting_everything(): void
    {
        $batch = $this->batch('00000000-0000-4000-8000-000000000077');
        $environmentalArea = Area::create(['area_name' => 'Area AMBIENTAL']);
        $environmentalProfession = Profesion::create(['profesion_name' => 'Ingeniería Ambiental']);
        $environmentalArea->profesions()->sync([$environmentalProfession->id]);
        $company = Company::create([
            'company_name' => 'SICOES',
            'company_type_id' => CompanyType::query()->value('id'),
        ]);
        $preview = $this->preview('Gestor Social 5', 'https://example.test/gestor-social-edit');
        $preview->update([
            'status' => 'error',
            'description' => '<p>Consultoría individual con perfil profesional.</p>',
            'department' => 'Santa Cruz',
            'location' => 'Santa Cruz',
            'expiration_date' => '2099-09-09 23:59:00',
            'salary' => '11076',
            'selected_company_id' => $company->id,
            'selected_area_id' => null,
            'selected_profession_ids' => [$this->profession->id, $environmentalProfession->id],
            'selected_location_ids' => [Location::where('location_name', 'Santa Cruz')->value('id')],
            'is_pro' => false,
            'raw_data' => [
                'ai_success' => true,
                'anthropic_success' => true,
                'ai_analysis' => [
                    'area_principal_catalogo' => 'Area SOCIAL',
                ],
                'profession_resolution' => [
                    'profession_ids' => [$this->profession->id, $environmentalProfession->id],
                    'area_ids' => [$this->area->id, $environmentalArea->id],
                    'profesiones_no_identificadas' => [],
                    'profesiones_ambiguas' => [],
                    'motivos_revision' => [
                        'La confianza del área principal seleccionada por Claude es insuficiente.',
                        'La convocatoria incluye profesiones pertenecientes a varias áreas sin un área dominante.',
                    ],
                ],
            ],
        ]);
        $this->batchItem($batch, $preview, 'gestor-social-edit');

        $this->livewirePreview($batch->id)
            ->call('edit', $preview->id)
            ->assertSet('form.selected_area_id', $this->area->id)
            ->set('form.selected_area_id', null)
            ->set('form.expiration_date', '2099-10-15 18:30')
            ->set('form.is_pro', true)
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $preview->refresh();
        $this->assertSame('2099-10-15 18:30:00', $preview->expiration_date);
        $this->assertTrue($preview->is_pro);
        $this->assertSame($this->area->id, $preview->selected_area_id);
        $this->assertSame('edited', $preview->status);
        $this->assertEqualsCanonicalizing(
            [$this->profession->id, $environmentalProfession->id],
            $preview->selected_profession_ids,
        );
    }

    public function test_technical_ai_error_does_not_offer_or_allow_publish_anyway(): void
    {
        $batch = $this->batch('00000000-0000-4000-8000-000000000075');
        $failed = $this->preview('Documento sin analizar', 'https://example.test/documento-sin-analizar');
        $failed->update([
            'status' => 'error',
            'raw_data' => [
                'ai_success' => false,
                'anthropic_success' => false,
                'ai_error_type' => 'http_error',
                'ai_error' => 'Error de conexión.',
                'manual_review_reasons' => ['Error de conexión.'],
            ],
        ]);
        $this->batchItem($batch, $failed, 'technical-ai-error');

        $this->livewirePreview($batch->id)
            ->assertDontSee('Publicar de todas formas')
            ->call('publishAnyway', $failed->id)
            ->assertSet(
                'errorMessage',
                'Esta previsualización no puede publicarse de todas formas: corrige primero los datos indispensables o el error técnico.',
            );

        $this->assertSame('error', $failed->fresh()->status);
        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_visible_batch_query_excludes_previews_from_other_batches(): void
    {
        $oldBatch = $this->batch('00000000-0000-4000-8000-000000000041');
        $visibleBatch = $this->batch('00000000-0000-4000-8000-000000000042');
        $oldPreview = $this->preview('Old preview', 'https://example.test/old');
        $visiblePreview = $this->preview('Visible preview', 'https://example.test/visible');
        $this->batchItem($oldBatch, $oldPreview, 'old-document');
        $this->batchItem($visibleBatch, $visiblePreview, 'visible-document');

        $component = $this->mountedPreview($visibleBatch->id);

        $this->assertSame([$visiblePreview->id], $this->visiblePreviewIds($component));
        $this->assertNotContains($oldPreview->id, $this->visiblePreviewIds($component));
    }

    public function test_job_persists_real_document_count_instead_of_heuristic_count(): void
    {
        $batch = $this->batch('00000000-0000-4000-8000-000000000050', ['status' => 'queued']);
        $runner = \Mockery::mock(SicoesRunnerService::class);
        $runner->shouldReceive('run')->once()->andReturn([
            'status' => 'OK',
            'date' => '07/07/2099',
            'slug' => '07-07-2099',
            'json_path' => $this->basePath.'/fichas-finales/07-07-2099.json',
            'sicoes_items' => 1,
        ]);
        $importer = \Mockery::mock(SicoesDocumentImporterService::class);
        $importer->shouldReceive('importRun')->once()->andReturn([
            'total_items_feed' => 5,
            'document_processed' => 5,
            'shown_in_batch' => 1,
            'document_discarded' => 4,
            'document_errors' => 0,
            'saved' => 0,
            'updated' => 1,
            'ai_calls' => 0,
            'ai_cache_hits' => 1,
            'ai_errors' => 0,
            'errors' => [],
        ]);

        (new ProcessSicoesJob('2099-07-07', $this->botCompany->id, 'test-user', $batch->id))
            ->handle($runner, $importer);

        $batch->refresh();
        $this->assertSame(5, $batch->documents_found);
        $this->assertSame(5, $batch->documents_downloaded);
        $this->assertSame(5, $batch->documents_processed);
    }

    public function test_date_without_convocations_completes_as_empty_instead_of_failed(): void
    {
        $batch = $this->batch('00000000-0000-4000-8000-000000000053', ['status' => 'queued']);
        $runner = \Mockery::mock(SicoesRunnerService::class);
        $runner->shouldReceive('run')->once()->andReturn([
            'status' => 'OK',
            'date' => '08/07/2099',
            'slug' => '08-07-2099',
            'json_path' => null,
            'sicoes_items' => 0,
            'no_results' => true,
        ]);
        $importer = \Mockery::mock(SicoesDocumentImporterService::class);
        $importer->shouldReceive('importRun')->once()->andReturn([
            'total_items_feed' => 0,
            'document_processed' => 0,
            'document_errors' => 0,
            'document_discarded' => 0,
            'shown_in_batch' => 0,
            'saved' => 0,
            'updated' => 0,
            'ai_calls' => 0,
            'ai_cache_hits' => 0,
            'ai_errors' => 0,
            'errors' => [],
        ]);

        (new ProcessSicoesJob('2099-07-08', $this->botCompany->id, 'test-user', $batch->id))
            ->handle($runner, $importer);

        $batch->refresh();
        $this->assertSame('completed', $batch->status);
        $this->assertSame(0, $batch->documents_found);
        $this->assertTrue((bool) data_get($batch->summary, 'no_results'));
    }

    public function test_unexpected_zero_import_is_partial_instead_of_completed(): void
    {
        $batch = $this->batch('00000000-0000-4000-8000-000000000054', ['status' => 'queued']);
        $runner = \Mockery::mock(SicoesRunnerService::class);
        $runner->shouldReceive('run')->once()->andReturn([
            'status' => 'OK',
            'date' => '24/08/2099',
            'slug' => '24-08-2099-personal',
            'json_path' => $this->basePath.'/fichas-finales/24-08-2099-personal.json',
            'sicoes_items' => 1,
            'no_results' => false,
        ]);
        $importer = \Mockery::mock(SicoesDocumentImporterService::class);
        $importer->shouldReceive('importRun')->once()->andReturn([
            'total_items_feed' => 0,
            'document_processed' => 0,
            'document_errors' => 0,
            'document_discarded' => 0,
            'shown_in_batch' => 0,
            'saved' => 0,
            'updated' => 0,
            'ai_calls' => 0,
            'ai_cache_hits' => 0,
            'ai_errors' => 0,
            'errors' => [],
        ]);

        (new ProcessSicoesJob('2099-08-24', $this->botCompany->id, 'test-user', $batch->id, 'personnel_requirements'))
            ->handle($runner, $importer);

        $batch->refresh();
        $this->assertSame('partial', $batch->status);
        $this->assertSame(1, $batch->documents_found);
    }

    public function test_partially_processed_batch_is_persisted_as_partial(): void
    {
        $batch = $this->batch('00000000-0000-4000-8000-000000000051', ['status' => 'queued']);
        $runner = \Mockery::mock(SicoesRunnerService::class);
        $runner->shouldReceive('run')->once()->andReturn([
            'status' => 'OK',
            'date' => '07/07/2099',
            'slug' => '07-07-2099',
            'json_path' => $this->basePath.'/fichas-finales/07-07-2099.json',
            'sicoes_items' => 2,
        ]);
        $importer = \Mockery::mock(SicoesDocumentImporterService::class);
        $importer->shouldReceive('importRun')->once()->andReturn([
            'total_items_feed' => 2,
            'document_processed' => 2,
            'shown_in_batch' => 1,
            'document_discarded' => 0,
            'document_errors' => 1,
            'saved' => 1,
            'updated' => 0,
            'ai_calls' => 1,
            'ai_cache_hits' => 0,
            'ai_errors' => 1,
            'errors' => ['Un documento no pudo procesarse.'],
        ]);

        (new ProcessSicoesJob('2099-07-07', $this->botCompany->id, 'test-user', $batch->id))
            ->handle($runner, $importer);

        $batch->refresh();
        $this->assertSame('partial', $batch->status);
        $this->assertSame(2, $batch->documents_processed);
        $this->assertSame(1, $batch->errors_count);
    }

    public function test_failed_document_download_marks_batch_failed_without_importing(): void
    {
        $batch = $this->batch('00000000-0000-4000-8000-000000000052', ['status' => 'queued']);
        $runner = \Mockery::mock(SicoesRunnerService::class);
        $runner->shouldReceive('run')
            ->once()
            ->andThrow(new \RuntimeException('descarga Word completada con 0 archivos validos'));
        $importer = \Mockery::mock(SicoesDocumentImporterService::class);
        $importer->shouldNotReceive('importRun');
        $job = new ProcessSicoesJob('2099-07-07', $this->botCompany->id, 'test-user', $batch->id);

        try {
            $job->handle($runner, $importer);
            $this->fail('El job debía fallar cuando no se descargó ningún documento.');
        } catch (\RuntimeException $exception) {
            $job->failed($exception);
        }

        $batch->refresh();
        $this->assertSame('failed', $batch->status);
        $this->assertSame(0, BotVacancyPreview::count());
    }

    private function acceptedClaudeResponse(): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'eligible' => true,
                    'contract_type' => 'multiple_individual',
                    'es_oportunidad_consultor_persona' => true,
                    'tipo_oportunidad' => 'consultor_linea',
                    'debe_descartarse' => false,
                    'motivo_descarte' => null,
                    'evidencia_clasificacion' => 'Consultoria Individual de Linea por items.',
                    'titulo_objeto' => 'Equipo PRP Construccion Carretera Okinawa',
                    'cargos' => [
                        ['nombre' => 'Coordinador', 'evidencia' => 'ITEM 1 Coordinador'],
                        ['nombre' => 'Especialista Social', 'evidencia' => 'ITEM 2 Especialista Social'],
                    ],
                    'profesiones_encontradas' => [[
                        'nombre_original' => 'Especialista Social',
                        'nombre_catalogo' => 'Trabajo Social',
                        'evidencia' => 'ITEM 2 Especialista Social',
                        'tipo_requisito' => 'obligatoria',
                        'confianza' => 0.95,
                    ]],
                    'acepta_carreras_afines' => false,
                    'evidencia_carreras_afines' => '',
                    'area_principal_catalogo' => 'Area SOCIAL',
                    'evidencia_area_principal' => 'Especialista Social',
                    'confianza_area_principal' => 0.98,
                    'lugar_trabajo' => [
                        'direccion_exacta' => 'Área del proyecto en Warnes, Santa Cruz',
                        'municipio' => 'Warnes',
                        'departamento' => 'Santa Cruz',
                        'evidencia' => 'El consultor prestará servicios en Warnes, Santa Cruz.',
                        'documento_fuente' => 'Términos de Referencia',
                        'confianza' => 0.98,
                        'requiere_revision' => false,
                        'direcciones_candidatas_descartadas' => [],
                    ],
                    'duracion_contrato' => [
                        'texto_exacto' => 'Hasta el 31 de diciembre de 2099.',
                        'evidencia' => 'Duración: hasta el 31 de diciembre de 2099.',
                        'confianza' => 0.98,
                    ],
                    'modalidad_postulacion' => [
                        'texto_exacto' => 'Presentación electrónica mediante RUPE.',
                        'tipo' => 'digital_rupe',
                        'evidencia' => 'La propuesta será presentada mediante RUPE.',
                        'confianza' => 0.99,
                    ],
                    'cuce' => [
                        'valor' => '26-0291-07-1669139-1-2',
                        'evidencia' => 'CUCE: 26-0291-07-1669139-1-2',
                    ],
                    'salarios' => [
                        'tipo' => 'multiple',
                        'cantidad' => 2,
                        'detalle' => [
                            ['cargo' => 'Coordinador', 'monto_bob' => 14402, 'evidencia' => 'Bs. 14.402 mensual'],
                            ['cargo' => 'Especialista Social', 'monto_bob' => 12788, 'evidencia' => 'Bs. 12.788 mensual'],
                        ],
                    ],
                    'advertencias' => [],
                ]),
            ]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 200],
        ];
    }

    private function claudeResponseWith(array $overrides): array
    {
        $response = $this->acceptedClaudeResponse();
        $payload = json_decode($response['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $response['content'][0]['text'] = json_encode(
            array_replace_recursive($payload, $overrides),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return $response;
    }

    private function writeRun(string $cuce, string $title, string $text): array
    {
        $slug = '07-07-2099';
        $words = $this->basePath.'/entrada/words/'.$slug;
        $rows = $this->basePath.'/salida/convocatorias';
        $texts = $this->basePath.'/salida/resultados/'.$slug.'/textos-extraidos';
        File::ensureDirectoryExists($words);
        File::ensureDirectoryExists($rows);
        File::ensureDirectoryExists($texts);
        File::ensureDirectoryExists($this->basePath.'/fichas-finales');

        $filename = "01_{$cuce}_01_Documento_Base_de_Contratacion.docx";
        File::put($words.'/'.$filename, 'stable-document-binary-'.$cuce);
        File::put($texts.'/01_'.$cuce.'.txt', $text);
        File::put($rows.'/'.$slug.'.json', json_encode([[
            'cuce' => $cuce,
            'entidad' => 'Administradora Boliviana de Carreteras',
            'objetoContratacion' => $title,
            'fechaPublicacion' => '07/07/2099',
            'fechaPresentacion' => '20/07/2026',
            'ficha' => "https://www.sicoes.gob.bo/portal/contrataciones/ficha/fichaProceso.php?cp={$cuce}",
        ]]));

        return [
            'status' => 'OK',
            'date' => '07/07/2099',
            'slug' => $slug,
            'json_path' => $this->basePath.'/fichas-finales/'.$slug.'.json',
            'sicoes_items' => 1,
        ];
    }

    private function batch(string $id, array $extra = []): SicoesScrapeBatch
    {
        return SicoesScrapeBatch::create(array_merge([
            'id' => $id,
            'bot_company_id' => $this->botCompany->id,
            'requested_date' => '2099-07-07',
            'status' => 'finished',
        ], $extra));
    }

    private function mountedPreview(?string $batchId = null): BotPreview
    {
        $query = $batchId ? ['batch' => $batchId] : [];
        app()->instance('request', LaravelRequest::create('/admin/bot/sicoes/empresa/sicoes', 'GET', $query));
        $component = new BotPreview;
        $component->mount($this->source, $this->botCompany);

        return $component;
    }

    private function livewirePreview(string $batchId)
    {
        return Livewire::withQueryParams(['batch' => $batchId])->test(BotPreview::class, [
            'source' => $this->source,
            'company' => $this->botCompany,
        ]);
    }

    private function preview(string $title, string $sourceUrl): BotVacancyPreview
    {
        return BotVacancyPreview::create([
            'bot_company_id' => $this->botCompany->id,
            'title' => $title,
            'source_url' => $sourceUrl,
            'status' => 'preview',
        ]);
    }

    private function batchItem(SicoesScrapeBatch $batch, BotVacancyPreview $preview, string $identity): SicoesScrapeBatchItem
    {
        $documentId = hash('sha256', $identity);

        return SicoesScrapeBatchItem::create([
            'batch_id' => $batch->id,
            'preview_id' => $preview->id,
            'document_id' => $documentId,
            'document_hash' => hash('sha256', $identity.'-content'),
            'source_hash' => hash('sha256', $preview->source_url),
            'source_url' => $preview->source_url,
            'cuce' => '26-0000-00-0000000-1-1',
            'filename' => $identity.'.docx',
            'status' => 'preview',
            'eligible' => true,
            'contract_type' => 'individual',
        ]);
    }

    private function visiblePreviewIds(BotPreview $component): array
    {
        $method = new ReflectionMethod($component, 'batchPreviewsQuery');
        $method->setAccessible(true);

        return $method->invoke($component)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function createSchema(): void
    {
        Schema::create('areas', function (Blueprint $table): void {
            $table->id();
            $table->string('area_name');
            $table->string('description')->nullable();
            $table->string('user_id')->nullable();
            $table->timestamps();
        });
        Schema::create('profesions', function (Blueprint $table): void {
            $table->id();
            $table->string('profesion_name');
            $table->string('user_id')->nullable();
            $table->timestamps();
        });
        Schema::create('area_profesion', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('area_id');
            $table->unsignedBigInteger('profesion_id');
            $table->timestamps();
        });
        Schema::create('bot_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('scraper_type');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('bot_companies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bot_source_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('evaluar_url')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('company_types', function (Blueprint $table): void {
            $table->id();
            $table->string('company_type_name');
            $table->timestamps();
        });
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('company_name');
            $table->text('description')->nullable();
            $table->string('company_image')->nullable();
            $table->string('user_id')->nullable();
            $table->unsignedBigInteger('company_type_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->string('location_name');
            $table->timestamps();
        });
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->text('announce_title')->nullable();
            $table->longText('description')->nullable();
            $table->dateTime('expiration_time')->nullable();
            $table->unsignedBigInteger('salary')->default(0);
            $table->boolean('pro')->default(false);
            $table->dateTime('scheduled_at')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('user_id')->nullable();
            $table->text('source_url')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamps();
        });
        Schema::create('announcement_profesion', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('announcement_id');
            $table->unsignedBigInteger('profesion_id');
            $table->timestamps();
        });
        Schema::create('announcement_location', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('announcement_id');
            $table->unsignedBigInteger('location_id');
            $table->timestamps();
        });
        Schema::create('bot_vacancy_previews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bot_company_id');
            $table->string('title');
            $table->text('source_url');
            $table->longText('original_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('area')->nullable();
            $table->text('professions')->nullable();
            $table->string('department')->nullable();
            $table->string('location')->nullable();
            $table->string('expiration_date')->nullable();
            $table->string('salary')->nullable();
            $table->json('raw_data')->nullable();
            $table->string('status')->default('preview');
            $table->string('scrape_batch_id')->nullable();
            $table->timestamp('removed_from_batch_at')->nullable();
            $table->unsignedBigInteger('convocatoria_id')->nullable();
            $table->unsignedBigInteger('selected_company_id')->nullable();
            $table->unsignedBigInteger('selected_area_id')->nullable();
            $table->json('selected_profession_ids')->nullable();
            $table->json('selected_location_ids')->nullable();
            $table->boolean('is_pro')->default(false);
            $table->json('attachments')->nullable();
            $table->timestamps();
        });
        Schema::create('sicoes_scrape_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('bot_company_id');
            $table->date('requested_date');
            $table->string('status');
            $table->unsignedInteger('documents_found')->default(0);
            $table->unsignedInteger('documents_downloaded')->default(0);
            $table->unsignedInteger('documents_processed')->default(0);
            $table->unsignedInteger('previews_count')->default(0);
            $table->unsignedInteger('discarded_count')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->unsignedInteger('ai_calls')->default(0);
            $table->unsignedInteger('ai_cache_hits')->default(0);
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sicoes_scrape_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_id');
            $table->unsignedBigInteger('preview_id')->nullable();
            $table->string('document_id', 64);
            $table->string('document_hash', 64);
            $table->string('analysis_key', 64)->nullable();
            $table->string('source_hash', 64);
            $table->text('source_url');
            $table->string('cuce');
            $table->string('filename');
            $table->string('status');
            $table->boolean('eligible')->nullable();
            $table->string('contract_type')->nullable();
            $table->text('discard_reason')->nullable();
            $table->text('classification_evidence')->nullable();
            $table->json('analysis_result')->nullable();
            $table->json('ai_metadata')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'document_id']);
        });
    }
}

<?php

namespace App\Livewire\Admin\Bot;

use App\Models\Area;
use App\Models\BotCompany;
use App\Models\BotSource;
use App\Models\BotVacancyPreview;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Location;
use App\Models\Profesion;
use App\Models\SicoesScrapeBatch;
use App\Models\SicoesScrapeBatchItem;
use App\Services\Bot\BotVacancyNormalizer;
use App\Services\Bot\ETalentScraperService;
use App\Services\Bot\EvaluarScraperService;
use App\Services\ProfessionAssignmentService;
use App\Services\SiteAnnouncementPublisher;
use App\Support\BotUiLabels;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class BotPreview extends Component
{
    use WithFileUploads, WithPagination;

    public BotSource $source;

    public BotCompany $company;

    public ?int $editingId = null;

    public bool $showModal = false;

    public ?string $message = null;

    public ?string $errorMessage = null;

    public ?string $professionSuggestionNotice = null;

    public string $startDate;

    public string $endDate;

    #[Url(as: 'batch')]
    public ?string $currentBatchId = null;

    public bool $batchNotFound = false;

    public array $lastScrapeSummary = [];

    public array $botFiles = [];

    public array $existingBotFiles = [];

    public array $form = [
        'title' => '',
        'area' => '',
        'professions' => '',
        'department' => '',
        'location' => '',
        'expiration_date' => '',
        'salary' => '',
        'description' => '',
        'source_url' => '',
        'selected_company_id' => null,
        'selected_area_id' => null,
        'selected_profession_ids' => [],
        'selected_location_ids' => [],
        'is_pro' => false,
    ];

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasRole('ADMIN'), 403);
    }

    public function mount(BotSource $source, BotCompany $company): void
    {
        abort_unless($source->active, 404);
        abort_unless($company->active, 404);
        abort_unless((int) $company->bot_source_id === (int) $source->id, 404);

        $this->source = $source;
        $this->company = $company;
        $this->startDate = now()->subDays(15)->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
        if ($source->scraper_type === 'sicoes') {
            $explicitBatchRequested = request()->query->has('batch');
            $requestedBatchId = (string) (request()->query('batch') ?? $this->currentBatchId ?? '');
            $batch = null;

            if ($explicitBatchRequested) {
                $batch = $requestedBatchId !== ''
                    ? SicoesScrapeBatch::query()
                        ->where('bot_company_id', $company->id)
                        ->whereKey($requestedBatchId)
                        ->first()
                    : null;

                if (! $batch) {
                    $this->batchNotFound = true;
                    $this->currentBatchId = null;

                    return;
                }
            } else {
                $batch = SicoesScrapeBatch::query()
                    ->where('bot_company_id', $company->id)
                    ->latest('created_at')
                    ->first();
            }

            $this->currentBatchId = $batch?->id;

            if ($batch) {
                $this->lastScrapeSummary = [
                    ...($batch->summary ?? []),
                    'status' => $batch->status,
                    'total_items_feed' => $batch->documents_found,
                    'document_processed' => $batch->documents_processed,
                    'shown_in_batch' => $batch->previews_count,
                    'document_discarded' => $batch->discarded_count,
                    'document_errors' => $batch->errors_count,
                    'ai_calls' => $batch->ai_calls,
                    'ai_cache_hits' => $batch->ai_cache_hits,
                ];
            }

            if (! $this->currentBatchId && ! $explicitBatchRequested) {
                $this->currentBatchId = $company->vacancyPreviews()
                    ->whereNull('removed_from_batch_at')
                    ->whereIn('status', ['preview', 'edited', 'error'])
                    ->whereNotNull('scrape_batch_id')
                    ->latest('created_at')
                    ->value('scrape_batch_id');
            }
        }
    }

    public function scrape(): void
    {
        $this->reset(['message', 'errorMessage']);

        if (! $this->isGeminiJobBoardSource()) {
            $this->errorMessage = 'Extractor no implementado para esta fuente.';

            return;
        }

        $this->validate([
            'startDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:startDate',
        ]);

        $this->currentBatchId = (string) Str::uuid();
        $this->resetPage('previewsPage');

        $scraper = $this->jobBoardScraper();
        $result = $scraper->scrapeCompany(
            company: $this->company,
            startDate: $this->startDate,
            endDate: $this->endDate,
            batchId: $this->currentBatchId,
        );

        $this->lastScrapeSummary = $result;
        $shown = (int) ($result['shown_in_batch'] ?? 0);

        $this->message = $this->scrapeMessage($result, $shown);

        if (($result['status'] ?? null) === 'ERROR') {
            $this->errorMessage = 'El extractor encontro errores. Revisa el resumen tecnico de la busqueda.';
        }
    }

    public function edit(int $id): void
    {
        $preview = $this->batchPreviewsQuery()->findOrFail($id);
        $this->normalizePreviewData($preview);
        $this->ensureSicoesAttachments($preview);
        $preview->refresh();

        $company = $preview->selected_company_id
            ? Company::find($preview->selected_company_id)
            : $this->resolveCompany();

        $storedProfessionIds = collect($preview->selected_profession_ids ?: [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $professionIds = $storedProfessionIds;
        $reviewReasons = BotUiLabels::previewIssues($preview->raw_data);
        $this->professionSuggestionNotice = $reviewReasons !== []
            ? collect($reviewReasons)->filter()->unique()->implode(' ')
            : null;

        $this->editingId = $preview->id;
        $this->form = [
            'title' => $preview->title,
            'area' => $preview->area ?? '',
            'professions' => $preview->professions ?? '',
            'department' => $preview->department ?? '',
            'location' => $preview->location ?? '',
            'expiration_date' => $preview->expiration_date ?? '',
            'salary' => $preview->salary ?? '',
            'description' => $preview->description ?? '',
            'source_url' => $preview->source_url,
            'selected_company_id' => $company?->id,
            'selected_area_id' => $this->selectedAreaIdForEditing($preview),
            'selected_profession_ids' => $professionIds,
            'selected_location_ids' => collect($preview->selected_location_ids ?: $this->resolveLocations($preview))
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            'is_pro' => (bool) $preview->is_pro,
        ];
        $this->existingBotFiles = $preview->attachments ?? [];
        $this->botFiles = [];
        $this->showModal = true;
    }

    public function recalculateSuggestedProfessions(): void
    {
        if (! $this->editingId) {
            return;
        }

        $preview = $this->batchPreviewsQuery()->findOrFail($this->editingId);
        $detected = data_get($preview->raw_data, 'ai_analysis.profesiones_encontradas', []);
        $assignment = $this->professionAssignments()->resolveDetectedProfessions(
            is_array($detected) ? $detected : [],
            (bool) data_get($preview->raw_data, 'ai_analysis.acepta_carreras_afines', false),
            data_get($preview->raw_data, 'ai_analysis.evidencia_carreras_afines'),
            data_get($preview->raw_data, 'ai_analysis.area_principal_catalogo'),
            (float) data_get($preview->raw_data, 'ai_analysis.confianza_area_principal', 0),
            data_get($preview->raw_data, 'ai_analysis.evidencia_area_principal'),
            aiProviderLabel: 'Claude',
        );
        $result = $this->professionAssignments()->applyDetectedResolutionToPreview($preview, $assignment, [
            'source' => 'bot_preview_recalculate',
            'raw_ai_professions' => $this->rawAiProfessions($preview),
        ]);
        $ids = $result['professions_after'];

        $this->form['selected_profession_ids'] = $ids;
        $this->form['selected_area_id'] = $assignment['selected_area_id'] ?? null;
        $this->professionSuggestionNotice = ! $assignment['requiere_revision']
            ? null
            : implode(' ', $assignment['motivos_revision']);
        $this->message = ! $assignment['requiere_revision']
            ? 'Profesiones recalculadas desde las menciones explícitas detectadas.'
            : 'La clasificación conserva sus sugerencias, pero requiere revisión manual.';

        $this->dispatch(
            'bot-professions-recalculated',
            ids: $ids,
            areaId: $this->form['selected_area_id'],
        );
    }

    public function professionsForArea(int $areaId, ProfessionAssignmentService $service): array
    {
        abort_unless(Area::query()->whereKey($areaId)->exists(), 404);

        return $service->resolve([$areaId])['profession_ids'];
    }

    public function saveEdit(): void
    {
        $preview = $this->batchPreviewsQuery()->findOrFail($this->editingId);

        if ($this->source->scraper_type === 'sicoes' && empty($this->form['selected_area_id'])) {
            $this->form['selected_area_id'] = $this->selectedAreaIdForEditing($preview);
        }

        $this->validate([
            'form.title' => 'required|min:5|max:255',
            'form.area' => 'nullable|max:255',
            'form.professions' => 'nullable',
            'form.expiration_date' => 'nullable|max:255',
            'form.salary' => $this->source->scraper_type === 'sicoes'
                ? 'required|integer|min:0'
                : 'nullable|max:255',
            'form.description' => 'required',
            'form.selected_company_id' => 'required|exists:companies,id',
            'form.selected_area_id' => 'nullable|exists:areas,id',
            'form.selected_profession_ids' => 'required|array|min:1',
            'form.selected_profession_ids.*' => 'integer|exists:profesions,id',
            'form.selected_location_ids' => 'required|array|min:1',
            'form.selected_location_ids.*' => 'integer|exists:locations,id',
            'form.is_pro' => 'boolean',
            'botFiles.*' => 'file|mimes:jpg,jpeg,png,pdf,docx,xlsx,xlsm,xls,csv|max:30000',
        ]);
        $this->validateSelectedProfessionsBelongToArea();

        $normalizedFields = $this->normalizedFormFields($preview);
        $locationSelection = $this->locationSelectionData($this->form['selected_location_ids']);
        $selectedLocationIds = $locationSelection['ids'];
        $normalizedFields['department'] = $locationSelection['department'];
        $normalizedFields['location'] = $locationSelection['location'];
        $normalizedFields['location_source'] = 'manual_selection';
        $normalizedFields['location_detected_text'] = $locationSelection['location'];
        $attachments = $this->storePreviewAttachments($preview);
        $selectedAreaName = Area::query()
            ->whereKey($this->form['selected_area_id'])
            ->value('area_name');
        $selectedProfessionNames = Profesion::query()
            ->whereIn('id', $this->form['selected_profession_ids'])
            ->orderBy('profesion_name')
            ->pluck('profesion_name')
            ->all();

        $preview->update([
            'title' => $this->form['title'],
            'area' => $selectedAreaName,
            'professions' => implode(', ', $selectedProfessionNames),
            'department' => $normalizedFields['department'],
            'location' => $normalizedFields['location'],
            'expiration_date' => $normalizedFields['expiration_date'],
            'salary' => $normalizedFields['salary'],
            'description' => $this->form['description'],
            'selected_company_id' => $this->form['selected_company_id'],
            'selected_area_id' => $this->form['selected_area_id'] ?: null,
            'selected_profession_ids' => array_values($this->form['selected_profession_ids']),
            'selected_location_ids' => $selectedLocationIds,
            'is_pro' => (bool) $this->form['is_pro'],
            'attachments' => array_values(array_merge($preview->attachments ?? [], $attachments)),
            'raw_data' => array_merge($preview->raw_data ?? [], [
                'manual_professions_locked' => true,
                'manual_professions_locked_at' => now()->toDateTimeString(),
                'expiration_source' => $normalizedFields['expiration_source'],
                'expiration_detected_text' => $normalizedFields['expiration_detected_text'],
                'location_source' => $normalizedFields['location_source'],
                'location_detected_text' => $normalizedFields['location_detected_text'],
                'salary_source' => $normalizedFields['salary_source'],
                'salary_detected_text' => $normalizedFields['salary_detected_text'],
            ]),
            'status' => 'edited',
        ]);

        $this->message = 'Cambios guardados en la previsualizacion. Aun no se publico la convocatoria.';
        $this->closeModal();
    }

    public function publishAll(): void
    {
        $this->reset(['message', 'errorMessage']);

        if (! $this->currentBatchId) {
            $this->errorMessage = $this->source->scraper_type === 'sicoes'
                ? 'Primero debes ejecutar SICOES para generar un lote de resultados.'
                : 'Primero debes agregar un rango para generar el lote actual.';

            return;
        }

        $previews = $this->batchPreviewsQuery()
            ->whereIn('status', ['preview', 'edited'])
            ->get();

        $published = 0;
        $failed = 0;

        foreach ($previews as $preview) {
            try {
                DB::transaction(function () use ($preview, &$published) {
                    $publication = $this->publishPreview($preview);

                    $preview->update([
                        'status' => 'published',
                        'convocatoria_id' => null,
                        'raw_data' => array_merge($preview->raw_data ?? [], [
                            'remote_publication' => [
                                'announcement_id' => $publication['id'],
                                'created' => $publication['created'],
                                'attachment_count' => $publication['attachment_count'],
                                'published_at' => now()->toIso8601String(),
                            ],
                        ]),
                    ]);

                    $this->currentBatchItemForPreview($preview->id)?->update(['status' => 'published']);

                    $published++;
                });
            } catch (\Throwable $exception) {
                $preview->update([
                    'status' => 'error',
                    'raw_data' => array_merge($preview->raw_data ?? [], [
                        'publish_error' => $exception->getMessage(),
                    ]),
                ]);
                $failed++;
            }
        }

        $this->message = "Publicacion terminada. Convocatorias publicadas: {$published}.";

        if ($failed > 0) {
            $this->errorMessage = "{$failed} convocatoria(s) no se pudieron publicar y quedaron marcadas como error.";
        }

        $this->resetPage('previewsPage');
    }

    public function publishAnyway(int $previewId): void
    {
        $this->reset(['message', 'errorMessage']);
        $preview = $this->batchPreviewsQuery()->findOrFail($previewId);

        try {
            DB::transaction(function () use ($preview): void {
                $this->assertCanPublishAnyway($preview);
                $publication = $this->publishPreview($preview, allowClassificationOverride: true);

                $preview->refresh();
                $preview->update([
                    'status' => 'published',
                    'convocatoria_id' => null,
                    'raw_data' => array_merge($preview->raw_data ?? [], [
                        'remote_publication' => [
                            'announcement_id' => $publication['id'],
                            'created' => $publication['created'],
                            'attachment_count' => $publication['attachment_count'],
                            'published_at' => now()->toIso8601String(),
                        ],
                        'published_anyway' => true,
                        'published_anyway_at' => now()->toIso8601String(),
                        'published_anyway_by' => auth()->id(),
                    ]),
                ]);
                $this->currentBatchItemForPreview($preview->id)?->update(['status' => 'published']);
            });

            $this->message = 'La convocatoria se publicó conservando todas sus profesiones explícitas.';
        } catch (\Throwable $exception) {
            $this->errorMessage = $exception->getMessage();
        }

        $this->resetPage('previewsPage');
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->editingId = null;
        $this->botFiles = [];
        $this->existingBotFiles = [];
        $this->professionSuggestionNotice = null;
    }

    public function removeFromBatch(int $previewId): void
    {
        $this->reset(['message', 'errorMessage']);

        if (! $this->currentBatchId) {
            $this->errorMessage = $this->source->scraper_type === 'sicoes'
                ? 'No hay un lote SICOES actual para quitar convocatorias.'
                : 'No hay un lote actual para quitar convocatorias.';

            return;
        }

        $preview = $this->batchPreviewsQuery()->findOrFail($previewId);
        $batchItem = $this->currentBatchItemForPreview($preview->id);

        if ($batchItem) {
            $batchItem->update(['removed_at' => now()]);
        } else {
            $preview->update(['removed_from_batch_at' => now()]);
        }

        $this->message = 'Convocatoria quitada del lote actual.';
        $this->resetPage('previewsPage');
    }

    public function reanalyzeErrors(): void
    {
        $this->reset(['message', 'errorMessage']);

        if (! $this->isGeminiJobBoardSource()) {
            $this->errorMessage = 'Esta acción de reanálisis corresponde únicamente a Evaluar y E-Talent.';

            return;
        }

        if (! $this->currentBatchId) {
            $this->errorMessage = 'No hay un lote actual para reanalizar.';

            return;
        }

        if (! config('services.anthropic.api_key')) {
            $this->errorMessage = 'Claude esta desactivado porque falta ANTHROPIC_API_KEY.';

            return;
        }

        $retried = 0;
        $recovered = 0;
        $failed = 0;

        $scraper = $this->jobBoardScraper();
        $previews = $this->geminiErrorPreviewsQuery()->get();

        foreach ($previews as $preview) {
            $result = $scraper->reanalyzePreview($preview, 'explicit_admin_reanalyze_errors');
            $retried++;
            $preview->refresh();
            $recovered += $preview->status === 'preview' ? 1 : 0;
            $failed += empty($result['gemini_success']) ? 1 : 0;
        }

        $this->message = "Reanálisis terminado. Procesadas: {$retried}. Sin revisión pendiente: {$recovered}.";
        if ($failed > 0) {
            $this->errorMessage = "Claude no pudo completar {$failed} análisis. Los detalles sanitizados quedaron en cada previsualización.";
        }
    }

    public function retryGeminiErrors(): void
    {
        $this->reanalyzeErrors();
    }

    public function reanalyzeSelected(int $previewId): void
    {
        $this->reset(['message', 'errorMessage']);

        if (! $this->isGeminiJobBoardSource()) {
            $this->errorMessage = 'Esta acción de reanálisis corresponde únicamente a Evaluar y E-Talent.';

            return;
        }

        if (! config('services.anthropic.api_key')) {
            $this->errorMessage = 'Claude esta desactivado porque falta ANTHROPIC_API_KEY.';

            return;
        }

        $preview = $this->batchPreviewsQuery()->findOrFail($previewId);
        $scraper = $this->jobBoardScraper();
        $result = $scraper->reanalyzePreview($preview, 'explicit_admin_selected_preview');

        if (($result['reason'] ?? null) === 'published_preview') {
            $this->errorMessage = 'Una previsualización publicada no se reanaliza automáticamente.';

            return;
        }

        $this->message = ! empty($result['gemini_success'])
            ? (! empty($result['manual_changes_preserved'])
                ? 'Reanálisis completado; se conservaron todos los cambios manuales.'
                : 'Previsualización reanalizada. Revisa la clasificación antes de publicar.')
            : 'El reanálisis no se completó; el error sanitizado quedó registrado.';
    }

    public function render()
    {
        $previews = $this->currentBatchId
            ? $this->batchPreviewsQuery()
                ->latest()
                ->paginate(12, ['*'], 'previewsPage')
            : BotVacancyPreview::query()
                ->whereRaw('1 = 0')
                ->paginate(12, ['*'], 'previewsPage');

        $forcePublishablePreviewIds = $previews->getCollection()
            ->filter(fn (BotVacancyPreview $preview): bool => $this->canPublishAnyway($preview))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return view('livewire.admin.bot.bot-preview', [
            'previews' => $previews,
            'currentSicoesBatch' => $this->currentSicoesBatch(),
            'profesions' => $this->profesions,
            'locations' => $this->locations,
            'areas' => $this->areas,
            'companies' => $this->companies,
            'editingPreview' => $this->editingId
                ? $this->batchPreviewsQuery()->find($this->editingId)
                : null,
            'currentGeminiErrors' => $this->currentBatchId ? $this->geminiErrorPreviewsQuery()->count() : 0,
            'publishablePreviewCount' => $this->currentBatchId
                ? $this->batchPreviewsQuery()->whereIn('status', ['preview', 'edited'])->count()
                : 0,
            'forcePublishablePreviewIds' => $forcePublishablePreviewIds,
        ]);
    }

    private function publishPreview(
        BotVacancyPreview $preview,
        bool $allowClassificationOverride = false,
    ): array
    {
        $company = $preview->selected_company_id
            ? Company::find($preview->selected_company_id)
            : $this->resolveCompany();
        $professionIds = $preview->selected_profession_ids ?: $this->resolveProfessions($preview);

        if ($professionIds === []) {
            throw new \RuntimeException('La previsualización no tiene profesiones explícitas validadas.');
        }
        if (! $allowClassificationOverride) {
            $this->assertPreviewProfessionAreaIntegrity($preview, $professionIds);
        }

        $normalizedFields = $this->normalizedPreviewFields($preview);
        $locationIds = $preview->selected_location_ids ?: $this->locationIdsForPublication($preview, $normalizedFields);
        $locationIds = $this->locationSelectionData($locationIds)['ids'];
        $preview->update([
            'department' => $normalizedFields['department'],
            'location' => $normalizedFields['location'],
            'expiration_date' => $normalizedFields['expiration_date'],
            'salary' => $normalizedFields['salary'],
            'selected_location_ids' => $locationIds,
            'raw_data' => array_merge($preview->raw_data ?? [], [
                'expiration_source' => $normalizedFields['expiration_source'],
                'expiration_detected_text' => $normalizedFields['expiration_detected_text'],
                'location_source' => $normalizedFields['location_source'],
                'location_detected_text' => $normalizedFields['location_detected_text'],
                'salary_source' => $normalizedFields['salary_source'],
                'salary_detected_text' => $normalizedFields['salary_detected_text'],
                'normalized_fields_at' => now()->toDateTimeString(),
            ]),
        ]);

        $data = [
            'announce_title' => $preview->title,
            'description' => $this->descriptionForAnnouncement($preview->description),
            'expiration_time' => $this->expirationForAnnouncement(
                $normalizedFields['expiration_date'],
                strict: $this->source->scraper_type === 'sicoes',
            ),
            'salary' => $this->salaryForAnnouncement($normalizedFields['salary']),
            'pro' => (bool) $preview->is_pro,
            'scheduled_at' => null,
        ];

        $professionNames = Profesion::query()->whereIn('id', $professionIds)->pluck('profesion_name')->all();
        $locationNames = Location::query()->whereIn('id', $locationIds)->pluck('location_name')->all();

        return app(SiteAnnouncementPublisher::class)->publish(
            preview: $preview,
            company: $company,
            professionNames: $professionNames,
            locationNames: $locationNames,
            announcement: $data,
        );
    }

    private function resolveCompany(): Company
    {
        $company = $this->company->realCompany();
        if ($company) {
            return $company;
        }

        $normalizedName = $this->normalize($this->company->name);

        $company = Company::withTrashed()->whereNull('site_company_id')->get()->first(function (Company $company) use ($normalizedName) {
            $current = $this->normalize($company->company_name);

            return $current === $normalizedName
                || Str::contains($current, $normalizedName)
                || Str::contains($normalizedName, $current);
        });

        if ($company) {
            if (method_exists($company, 'trashed') && $company->trashed()) {
                $company->restore();
            }

            return $company;
        }

        $companyType = CompanyType::where('company_type_name', 'LIKE', '%Priv%')->first()
            ?: CompanyType::firstOrCreate(['company_type_name' => 'Privada']);

        return Company::create([
            'company_name' => $this->company->name,
            'description' => 'Empresa creada automáticamente desde el módulo BOT para convocatorias desde '
                .$this->source->name.'.',
            'company_image' => $this->company->logo ?: 'empresas/tbn-new-default.webp',
            'user_id' => auth()->id(),
            'company_type_id' => $companyType->id,
        ]);
    }

    private function normalizePreviewData(BotVacancyPreview $preview): void
    {
        if ($preview->status === 'edited') {
            return;
        }

        $normalizedFields = $this->normalizedPreviewFields($preview);

        $preview->update([
            'department' => $normalizedFields['department'],
            'location' => $normalizedFields['location'],
            'expiration_date' => $normalizedFields['expiration_date'],
            'salary' => $normalizedFields['salary'],
            'selected_location_ids' => $this->locationIdsForPublication($preview, $normalizedFields),
            'raw_data' => array_merge($preview->raw_data ?? [], [
                'expiration_source' => $normalizedFields['expiration_source'],
                'expiration_detected_text' => $normalizedFields['expiration_detected_text'],
                'location_source' => $normalizedFields['location_source'],
                'location_detected_text' => $normalizedFields['location_detected_text'],
                'salary_source' => $normalizedFields['salary_source'],
                'salary_detected_text' => $normalizedFields['salary_detected_text'],
                'normalized_fields_at' => now()->toDateTimeString(),
            ]),
        ]);
    }

    private function validateSelectedProfessionsBelongToArea(): void
    {
        $areaId = (int) ($this->form['selected_area_id'] ?? 0);
        $professionIds = collect($this->form['selected_profession_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($areaId <= 0) {
            throw ValidationException::withMessages([
                'form.selected_area_id' => 'Selecciona el área que se utilizará para validar las profesiones.',
            ]);
        }

        $compatibleIds = Profesion::query()
            ->whereIn('id', $professionIds)
            ->whereHas('areas', fn ($query) => $query->where('areas.id', $areaId))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
        $incompatibleIds = $professionIds->diff($compatibleIds)->values();

        if ($incompatibleIds->isNotEmpty()) {
            $preview = $this->editingId
                ? $this->batchPreviewsQuery()->find($this->editingId)
                : null;
            if ($preview && $this->isValidatedAiMultiAreaSelection($preview, $professionIds->all(), $areaId)) {
                return;
            }

            $names = Profesion::query()
                ->whereIn('id', $incompatibleIds)
                ->pluck('profesion_name')
                ->implode(', ');

            throw ValidationException::withMessages([
                'form.selected_profession_ids' => "Estas profesiones no pertenecen al área seleccionada: {$names}.",
            ]);
        }
    }

    private function selectedAreaIdForEditing(BotVacancyPreview $preview): ?int
    {
        $rawData = $preview->raw_data ?? [];
        $resolvedAreaIds = collect(data_get($rawData, 'profession_resolution.area_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $storedCandidates = collect([
            $preview->selected_area_id,
            data_get($rawData, 'profession_resolution.selected_area_id'),
            data_get($rawData, 'profession_resolution.area_dominante.area_id'),
            data_get($rawData, 'profession_resolution.area_principal_ia.area_id'),
        ])->map(fn ($id): int => (int) $id)->filter();

        foreach ($storedCandidates as $areaId) {
            if (Area::query()->whereKey($areaId)->exists()) {
                return $areaId;
            }
        }

        $aiAreaName = trim((string) data_get($rawData, 'ai_analysis.area_principal_catalogo'));
        if ($aiAreaName !== '') {
            $aiArea = Area::query()->get(['id', 'area_name'])->first(
                fn (Area $area): bool => $this->normalize($area->area_name) === $this->normalize($aiAreaName),
            );

            if ($aiArea && ($resolvedAreaIds->isEmpty() || $resolvedAreaIds->contains((int) $aiArea->id))) {
                return (int) $aiArea->id;
            }
        }

        $fallbackAreaId = $resolvedAreaIds->first(
            fn (int $areaId): bool => Area::query()->whereKey($areaId)->exists(),
        );

        return $fallbackAreaId ? (int) $fallbackAreaId : null;
    }

    private function assertPreviewProfessionAreaIntegrity(
        BotVacancyPreview $preview,
        array $professionIds,
    ): void {
        $areaId = (int) $preview->selected_area_id;
        if ($areaId <= 0) {
            throw new \RuntimeException(
                'La previsualización requiere seleccionar un área antes de publicarse.',
            );
        }

        $professionIds = collect($professionIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $compatibleCount = Profesion::query()
            ->whereIn('id', $professionIds)
            ->whereHas('areas', fn ($query) => $query->where('areas.id', $areaId))
            ->count();

        if ($compatibleCount !== $professionIds->count()) {
            if ($this->isValidatedAiMultiAreaSelection($preview, $professionIds->all(), $areaId)) {
                return;
            }

            throw new \RuntimeException(
                'La previsualización contiene profesiones incompatibles con el área seleccionada.',
            );
        }
    }

    private function isValidatedAiMultiAreaSelection(
        BotVacancyPreview $preview,
        array $professionIds,
        int $areaId,
    ): bool {
        $resolution = data_get($preview->raw_data, 'profession_resolution', []);
        $resolvedAreaIds = collect(data_get($resolution, 'area_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique();
        $resolvedIds = collect(data_get($resolution, 'profession_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique();
        $requestedIds = collect($professionIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique();
        $blockingReasons = collect(data_get($resolution, 'motivos_revision', []))
            ->reject(fn (mixed $reason): bool => $this->isAreaClassificationWarning((string) $reason));

        return $areaId > 0
            && $resolvedAreaIds->contains($areaId)
            && $blockingReasons->isEmpty()
            && (array) data_get($resolution, 'profesiones_no_identificadas', []) === []
            && (array) data_get($resolution, 'profesiones_ambiguas', []) === []
            && $requestedIds->isNotEmpty()
            && $requestedIds->diff($resolvedIds)->isEmpty();
    }

    private function canPublishAnyway(BotVacancyPreview $preview): bool
    {
        if ($this->source->scraper_type !== 'sicoes' || $preview->status !== 'error') {
            return false;
        }

        $rawData = $preview->raw_data ?? [];
        $analysisSucceeded = data_get($rawData, 'anthropic_success') === true
            || data_get($rawData, 'ai_success') === true;
        $technicalError = collect([
            data_get($rawData, 'document_error_type'),
            data_get($rawData, 'document_error'),
            data_get($rawData, 'ai_error_type'),
            data_get($rawData, 'ai_error'),
            data_get($rawData, 'anthropic_error_type'),
            data_get($rawData, 'anthropic_error'),
        ])->contains(fn (mixed $value): bool => $value !== null && $value !== '' && $value !== false);
        $reasons = collect([
            ...((array) data_get($rawData, 'manual_review_reasons', [])),
            ...((array) data_get($rawData, 'profession_resolution.motivos_revision', [])),
        ])->filter(fn (mixed $reason): bool => trim((string) $reason) !== '')->unique();
        $professionIds = collect($preview->selected_profession_ids ?: [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique();
        $locationIds = collect($preview->selected_location_ids ?: [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique();

        if (
            ! $analysisSucceeded
            || $technicalError
            || $reasons->isEmpty()
            || $reasons->contains(fn (mixed $reason): bool => ! $this->isAreaClassificationWarning((string) $reason))
            || mb_strlen(trim((string) $preview->title)) < 5
            || trim(strip_tags((string) $preview->description)) === ''
            || ! $preview->selected_company_id
            || ! Company::query()->whereKey($preview->selected_company_id)->exists()
            || $professionIds->isEmpty()
            || Profesion::query()->whereIn('id', $professionIds)->count() !== $professionIds->count()
            || $locationIds->isEmpty()
            || Location::query()->whereIn('id', $locationIds)->get()
                ->reject(fn (Location $location): bool => $this->normalize($location->location_name) === 'no especificado')
                ->isEmpty()
        ) {
            return false;
        }

        try {
            $expiration = Carbon::parse((string) $preview->expiration_date);
        } catch (\Throwable) {
            return false;
        }

        return $expiration->year > 2000 && ! $expiration->isPast();
    }

    private function assertCanPublishAnyway(BotVacancyPreview $preview): void
    {
        if (! $this->canPublishAnyway($preview)) {
            throw new \RuntimeException(
                'Esta previsualización no puede publicarse de todas formas: corrige primero los datos indispensables o el error técnico.',
            );
        }
    }

    private function isAreaClassificationWarning(string $reason): bool
    {
        $normalized = $this->normalize($reason);

        return str_contains($normalized, 'area principal')
            || str_contains($normalized, 'varias areas')
            || str_contains($normalized, 'area dominante')
            || str_contains($normalized, 'confianza del area');
    }

    private function resolveProfessions(BotVacancyPreview $preview, bool $force = false): array
    {
        $selected = collect($preview->selected_profession_ids ?: [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($selected !== [] || $this->isGeminiJobBoardSource()) {
            if ($selected !== []) {
                return $selected;
            }

            return collect(data_get($preview->raw_data, 'profession_resolution.profession_ids', []))
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return $this->areaAssignmentFromPreview($preview)['profession_ids'];
    }

    private function areaAssignmentFromPreview(BotVacancyPreview $preview): array
    {
        $rawData = $preview->raw_data ?? [];
        $areaIds = data_get($rawData, 'resolved_area_ids')
            ?: data_get($rawData, 'gemini_area_ids')
            ?: data_get($rawData, 'area_ids')
            ?: data_get($rawData, 'ai_analysis.area_ids');

        if ((! is_array($areaIds) || $areaIds === []) && $preview->selected_area_id) {
            $areaIds = [(int) $preview->selected_area_id];
        }

        if (is_array($areaIds) && $areaIds !== []) {
            return $this->professionAssignments()->resolve($areaIds);
        }

        $areaNames = collect([
            ...((array) data_get($rawData, 'gemini_areas', [])),
            ...((array) data_get($rawData, 'areas', [])),
            data_get($rawData, 'gemini_area_principal'),
            data_get($rawData, 'ai_analysis.area_profesional_principal'),
            $preview->area,
        ])->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')->values()->all();

        return $this->professionAssignments()->resolveExactAreaNames($areaNames);
    }

    private function rawAiAreas(BotVacancyPreview $preview): array
    {
        $rawData = $preview->raw_data ?? [];
        $value = data_get($rawData, 'ai_analysis.area_ids')
            ?: data_get($rawData, 'gemini_area_ids')
            ?: data_get($rawData, 'gemini_areas')
            ?: data_get($rawData, 'areas', []);

        return is_array($value) ? array_values($value) : [$value];
    }

    private function rawAiProfessions(BotVacancyPreview $preview): array
    {
        $rawData = $preview->raw_data ?? [];
        $value = data_get($rawData, 'ai_analysis.profesiones_encontradas')
            ?: data_get($rawData, 'profesiones_originales')
            ?: data_get($rawData, 'ai_analysis.profesiones_sugeridas')
            ?: data_get($rawData, 'ai_profession_suggestions')
            ?: data_get($rawData, 'gemini_profesiones_sugeridas', []);

        return is_array($value) ? array_values($value) : [$value];
    }

    private function professionAssignments(): ProfessionAssignmentService
    {
        return app(ProfessionAssignmentService::class);
    }

    private function resolveLocations(BotVacancyPreview $preview): array
    {
        $departments = data_get($preview->raw_data, 'location_departments', []);

        return $this->locationIdsForDepartments(
            is_array($departments) && $departments !== []
                ? $departments
                : [$preview->department],
        );
    }

    private function locationIdsForDepartments(array $departments): array
    {
        return collect($departments)
            ->map(fn (mixed $department): string => trim((string) $department))
            ->filter()
            ->unique(fn (string $department): string => $this->normalize($department))
            ->flatMap(fn (string $department): array => $this->locationIdsForDepartment($department))
            ->unique()
            ->values()
            ->all();
    }

    private function locationIdsForDepartment(?string $department): array
    {
        $department = trim((string) $department);

        if ($department && $this->normalize($department) !== 'no especificado') {
            $location = $this->findLocation($department)
                ?: Location::create(['location_name' => Str::limit($department, 255, '')]);
            Cache::forget('locations');

            return [$location->id];
        }

        $location = Location::firstOrCreate(['location_name' => 'No especificado']);

        return [$location->id];
    }

    private function locationIdsForPublication(BotVacancyPreview $preview, array $normalizedFields): array
    {
        $storedIds = collect($preview->selected_location_ids ?: [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        $normalizedDepartment = (string) ($normalizedFields['department'] ?? '');

        if ($this->normalize($normalizedDepartment) === 'no especificado') {
            return $storedIds ?: $this->resolveLocations($preview);
        }

        if (! $storedIds || $this->locationIdsAreOnlyUnspecified($storedIds)) {
            return $this->locationIdsForDepartments(
                $normalizedFields['departments'] ?? [$normalizedDepartment],
            );
        }

        return $storedIds;
    }

    private function locationSelectionData(array $locationIds): array
    {
        $ids = collect($locationIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $locationsById = Location::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
        $locations = $ids
            ->map(fn (int $id): ?Location => $locationsById->get($id))
            ->filter()
            ->reject(fn (Location $location): bool => $this->normalize($location->location_name) === 'no especificado')
            ->values();

        if ($locations->isEmpty()) {
            throw ValidationException::withMessages([
                'form.selected_location_ids' => 'Selecciona al menos una ubicación válida.',
            ]);
        }

        $names = $locations->pluck('location_name')->all();
        $selectableLocationCount = Location::all()
            ->reject(fn (Location $location): bool => $this->normalize($location->location_name) === 'no especificado')
            ->count();
        $locationSummary = $locations->count() > 1
            && $locations->count() === $selectableLocationCount
            ? 'Toda Bolivia'
            : implode(', ', $names);

        return [
            'ids' => $locations->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'department' => count($names) === 1 ? $names[0] : $locationSummary,
            'location' => $locationSummary,
        ];
    }

    private function locationIdsAreOnlyUnspecified(array $locationIds): bool
    {
        if (! $locationIds) {
            return true;
        }

        return Location::whereIn('id', $locationIds)
            ->get()
            ->every(fn (Location $location): bool => $this->normalize($location->location_name) === 'no especificado');
    }

    private function findLocation(string $name): ?Location
    {
        $needle = $this->normalize($name);

        return Location::all()->first(fn (Location $location) => $this->normalize($location->location_name) === $needle);
    }

    private function normalizedPreviewFields(BotVacancyPreview $preview): array
    {
        if ($this->source->scraper_type === 'sicoes') {
            return [
                'expiration_date' => $preview->expiration_date,
                'expiration_source' => data_get($preview->raw_data, 'expiration_source', 'sicoes_row'),
                'expiration_detected_text' => data_get($preview->raw_data, 'sicoes_expiration_date', $preview->expiration_date),
                'department' => $preview->department ?: 'No especificado',
                'location' => $preview->location ?: 'No especificado',
                'municipality' => data_get($preview->raw_data, 'municipality'),
                'location_source' => data_get($preview->raw_data, 'location_source', 'sicoes_document_ai'),
                'location_detected_text' => data_get($preview->raw_data, 'location_detected_text'),
                'salary' => $this->salaryForAnnouncement($preview->salary),
                'salary_source' => data_get($preview->raw_data, 'salary_source', 'sicoes_document_ai'),
                'salary_detected_text' => data_get($preview->raw_data, 'salary_detected_text'),
            ];
        }

        return $this->normalizer()->normalize(
            title: $preview->title,
            description: $preview->original_description ?: $preview->description,
            analysis: [
                'department' => $preview->department,
                'location' => $preview->location,
                'salary' => $preview->salary,
                'expiration_date' => $preview->expiration_date,
            ],
            rawData: $preview->raw_data ?? [],
        );
    }

    private function normalizedFormFields(BotVacancyPreview $preview): array
    {
        if ($this->source->scraper_type === 'sicoes') {
            return [
                'expiration_date' => $this->sicoesExpirationForPreview($this->form['expiration_date']),
                'expiration_source' => 'manual',
                'expiration_detected_text' => $this->form['expiration_date'],
                'department' => $this->form['department'] ?: 'No especificado',
                'location' => $this->form['location'] ?: 'No especificado',
                'municipality' => data_get($preview->raw_data, 'municipality'),
                'location_source' => 'manual',
                'location_detected_text' => $this->form['location'],
                'salary' => $this->salaryForAnnouncement($this->form['salary']),
                'salary_source' => 'manual',
                'salary_detected_text' => $this->form['salary'],
            ];
        }

        return $this->normalizer()->normalize(
            title: $this->form['title'],
            description: $this->form['description'],
            analysis: [
                'department' => $this->form['department'],
                'location' => $this->form['location'],
                'salary' => $this->form['salary'],
                'expiration_date' => $this->form['expiration_date'],
            ],
            rawData: $preview->raw_data ?? [],
        );
    }

    private function sicoesExpirationForPreview(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        try {
            $expiration = Carbon::parse($value);
            if (! preg_match('/\d{1,2}:\d{2}/', $value)) {
                $expiration->setTime(23, 59, 0);
            }

            return $expiration->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function expirationForAnnouncement(?string $date, bool $strict = false): string
    {
        try {
            if (! $date) {
                throw new \InvalidArgumentException('La fecha limite SICOES no esta especificada.');
            }

            $expiration = Carbon::parse($date);
            if (! preg_match('/\d{1,2}:\d{2}/', $date)) {
                $expiration->setTime(23, 59, 0);
            }

            if ($expiration->isPast()) {
                if ($strict) {
                    throw new \InvalidArgumentException('La fecha limite SICOES ya vencio y debe revisarse antes de publicar.');
                }

                return now()->addDays(15)->setTime(23, 59, 0)->format('Y-m-d H:i:s');
            }

            return $expiration->format('Y-m-d H:i:s');
        } catch (\Throwable $exception) {
            if ($strict) {
                throw new \InvalidArgumentException('La fecha limite SICOES no es valida.', 0, $exception);
            }

            return now()->addDays(15)->setTime(23, 59, 0)->format('Y-m-d H:i:s');
        }
    }

    private function salaryForAnnouncement(mixed $salary): int
    {
        if ($this->source->scraper_type === 'sicoes') {
            $salary = trim((string) $salary);

            return ctype_digit($salary) ? (int) $salary : 0;
        }

        $normalized = $this->normalize((string) $salary);

        if (Str::contains($normalized, ['detallado', 'descripcion'])) {
            return 1;
        }

        return $this->normalizer()->salaryForStorage($salary);
    }

    private function descriptionForAnnouncement(?string $description): string
    {
        $description = trim((string) $description);

        if ($description !== strip_tags($description)) {
            return $this->normalizeHtmlSpacing($description);
        }

        return nl2br(e($description));
    }

    private function normalizeHtmlSpacing(string $description): string
    {
        return preg_replace('/<p>(?:\s|&nbsp;)*<\/p>/i', '<p><br></p>', $description) ?: $description;
    }

    private function batchPreviewsQuery()
    {
        $query = $this->company->vacancyPreviews();

        if ($this->source->scraper_type === 'sicoes') {
            if ($this->currentSicoesBatch()) {
                $previewIds = SicoesScrapeBatchItem::query()
                    ->where('batch_id', $this->currentBatchId)
                    ->whereNotNull('preview_id')
                    ->whereNull('removed_at')
                    ->pluck('preview_id');
                $query->whereIn('id', $previewIds);
            } elseif (! $this->batchNotFound && $this->currentBatchId) {
                $query->where('scrape_batch_id', $this->currentBatchId)
                    ->whereNull('removed_from_batch_at');
            } else {
                $query->whereRaw('1 = 0');
            }
        } else {
            $query->where('scrape_batch_id', $this->currentBatchId)
                ->whereNull('removed_from_batch_at');
        }

        return $query
            ->whereIn('status', ['preview', 'edited', 'error'])
            ->where(function ($query) {
                $query
                    ->whereNull('raw_data->internship_or_practice_detected')
                    ->orWhere('raw_data->internship_or_practice_detected', false);
            });
    }

    private function currentSicoesBatch(): ?SicoesScrapeBatch
    {
        if ($this->source->scraper_type !== 'sicoes' || ! $this->currentBatchId) {
            return null;
        }

        return SicoesScrapeBatch::query()
            ->whereKey($this->currentBatchId)
            ->where('bot_company_id', $this->company->id)
            ->first();
    }

    private function currentBatchItemForPreview(int $previewId): ?SicoesScrapeBatchItem
    {
        if (! $this->currentSicoesBatch()) {
            return null;
        }

        return SicoesScrapeBatchItem::query()
            ->where('batch_id', $this->currentBatchId)
            ->where('preview_id', $previewId)
            ->first();
    }

    private function geminiErrorPreviewsQuery()
    {
        return $this->batchPreviewsQuery()
            ->where('status', 'error')
            ->where(function ($query) {
                $query
                    ->whereNull('raw_data->manual_professions_locked')
                    ->orWhere('raw_data->manual_professions_locked', false);
            });
    }

    private function scrapeMessage(array $result, int $shown): string
    {
        $totalItems = (int) ($result['total_items_feed'] ?? 0);
        $outOfRange = (int) ($result['skipped_out_of_range'] ?? 0);
        $alreadyPublished = (int) ($result['already_published'] ?? 0);

        if ($totalItems === 0) {
            return 'No se encontraron publicaciones en la fuente.';
        }

        if ($shown > 0) {
            return 'Scrapeo terminado. Revisa las convocatorias antes de publicarlas.';
        }

        if ($outOfRange > 0 && $outOfRange === $totalItems) {
            return 'Se encontraron publicaciones, pero ninguna esta dentro del rango seleccionado.';
        }

        if ($alreadyPublished > 0) {
            return 'Las convocatorias del rango seleccionado ya fueron publicadas anteriormente.';
        }

        return 'No se encontraron convocatorias nuevas para mostrar en el rango seleccionado.';
    }

    private function storePreviewAttachments(BotVacancyPreview $preview): array
    {
        $attachments = [];

        foreach ($this->botFiles as $index => $file) {
            $url = $file->storeAs(
                path: 'convocatorias',
                name: 'bot-'.$preview->id.'-'.$index.'-'.$file->hashName(),
                options: 'public',
            );

            $attachments[] = [
                'url' => $url,
                'original_name' => $file->getClientOriginalName(),
            ];
        }

        return $attachments;
    }

    private function ensureSicoesAttachments(BotVacancyPreview $preview): void
    {
        if ($this->source->scraper_type !== 'sicoes' || ! empty($preview->attachments)) {
            return;
        }

        $cuce = (string) data_get($preview->raw_data, 'cuce');
        if ($cuce === '') {
            return;
        }

        $wordPath = $this->findSicoesWordPath($cuce);
        if (! $wordPath) {
            return;
        }

        $filename = basename($wordPath);
        $dateSlug = basename(dirname($wordPath));
        $publicPath = 'convocatorias/sicoes/'.$dateSlug.'/'.$filename;

        if (! Storage::disk('public')->exists($publicPath)) {
            $contents = @file_get_contents($wordPath);

            if ($contents === false) {
                return;
            }

            if (! Storage::disk('public')->put($publicPath, $contents)) {
                return;
            }
        }

        if (! Storage::disk('public')->exists($publicPath)) {
            return;
        }

        $preview->update([
            'attachments' => [[
                'url' => $publicPath,
                'original_name' => $filename,
                'source' => 'sicoes',
                'cuce' => $cuce,
                'local_path' => $wordPath,
                'attached_at' => now()->toIso8601String(),
            ]],
        ]);
    }

    private function findSicoesWordPath(string $cuce): ?string
    {
        $basePath = storage_path('app/bot/sicoes-scraper/Sicoes/entrada/words');

        if (! is_dir($basePath)) {
            return null;
        }

        $matches = glob($basePath.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*'.$cuce.'*') ?: [];

        foreach ($matches as $file) {
            if (! is_file($file)) {
                continue;
            }

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($extension, ['doc', 'docx', 'pdf'], true)) {
                return $file;
            }
        }

        return null;
    }

    #[Computed]
    public function profesions(): array
    {
        $catalog = Cache::remember('profesions_with_areas', 86400, function () {
            return Profesion::query()->whereHas('areas')->with('areas')->get()->map(function ($p) {
                return [
                    'id' => (int) $p->id,
                    'profesion_name' => $p->profesion_name,
                    'area_ids' => $p->areas->pluck('id')->map(fn ($id) => (int) $id)->toArray(),
                ];
            })->toArray();
        });
        $selectedIds = collect($this->form['selected_profession_ids'] ?? [])->map(fn ($id) => (int) $id)->filter();
        $missingIds = $selectedIds->diff(collect($catalog)->pluck('id'));

        if ($missingIds->isEmpty()) {
            return $catalog;
        }

        $existingSelection = Profesion::with('areas')->whereIn('id', $missingIds)->get()->map(function ($p) {
            return [
                'id' => (int) $p->id,
                'profesion_name' => $p->profesion_name,
                'area_ids' => $p->areas->pluck('id')->map(fn ($id) => (int) $id)->toArray(),
            ];
        })->all();

        return collect($catalog)->merge($existingSelection)->unique('id')->values()->all();
    }

    #[Computed]
    public function locations()
    {
        return Cache::remember('locations', 86400, fn () => Location::all(['id', 'location_name']));
    }

    #[Computed]
    public function areas()
    {
        return Cache::remember('areas', 86400, fn () => Area::all(['id', 'area_name']));
    }

    #[Computed]
    public function companies()
    {
        $connection = config('database.connections.site');
        $cacheKey = 'site_company_catalog_synced:'.hash(
            'sha256',
            implode('|', [
                (string) ($connection['host'] ?? ''),
                (string) ($connection['database'] ?? ''),
            ]),
        );

        try {
            Cache::remember($cacheKey, now()->addMinutes(15), fn () => app(\App\Services\SiteCompanyCatalogSynchronizer::class)
                ->sync((string) auth()->id()));
        } catch (\Throwable) {
            $this->errorMessage = 'No se pudo actualizar el catálogo desde la web; se muestran las últimas empresas sincronizadas que hay en esta PC. Revisa SITE_DB_* para mantenerlo al día.';
        }

        return Company::query()
            ->whereNotNull('site_company_id')
            ->orderBy('company_name')
            ->get(['id', 'company_name']);
    }

    private function isGeminiJobBoardSource(): bool
    {
        return in_array($this->source->scraper_type, ['evaluar', 'etalent'], true);
    }

    private function jobBoardScraper(): EvaluarScraperService
    {
        return match ($this->source->scraper_type) {
            'evaluar' => app(EvaluarScraperService::class),
            'etalent' => app(ETalentScraperService::class),
            default => throw new \LogicException('La fuente no utiliza el flujo de scraping con Gemini.'),
        };
    }

    private function normalizer(): BotVacancyNormalizer
    {
        return app(BotVacancyNormalizer::class);
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
}

<?php

namespace App\Livewire\Admin\Bot;

use App\Jobs\ProcessSicoesJob;
use App\Models\BotCompany;
use App\Models\BotSource;
use App\Models\BotVacancyPreview;
use App\Models\SicoesScrapeBatch;
use App\Support\SensitiveDataSanitizer;
use App\Support\SicoesProgressCache;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

class BotCompanies extends Component
{
    public BotSource $source;

    public bool $showForm = false;

    public ?int $editingCompanyId = null;

    public ?string $message = null;

    public ?string $errorMessage = null;

    public string $search = '';

    public string $sort = 'name_asc';

    public string $statusFilter = 'active';

    public string $categoryFilter = 'all';

    #[Url(as: 'fecha')]
    public string $sicoesDate = '';

    #[Url(as: 'modalidad')]
    public string $sicoesSourceType = '';

    public int $perPage = 12;

    public int $companiesPage = 1;

    public array $companyStats = [
        'active' => 0,
        'inactive' => 0,
        'without_scraper' => 0,
        'total' => 0,
    ];

    public array $previewStats = [
        'week_count' => 0,
        'today_count' => 0,
        'last_run' => null,
    ];

    public array $sicoesStats = [
        'published' => 0,
        'today_count' => 0,
        'last_run' => null,
    ];

    public array $sicoesProgress = [];

    public array $categories = [];

    public array $form = [
        'name' => '',
        'evaluar_url' => '',
        'active' => true,
    ];

    public function boot(): void
    {
        abort_unless(auth()->user()?->hasRole('ADMIN'), 403);
    }

    public function mount(BotSource $source): void
    {
        abort_unless($source->active, 404);

        $this->source = $source;
        $this->categories = $this->categoryOptions();
        if ($this->sicoesDate === '') {
            $this->sicoesDate = now()->format('Y-m-d');
        }

        if ($this->source->scraper_type === 'sicoes') {
            if (! in_array($this->sicoesSourceType, SicoesScrapeBatch::SOURCE_TYPES, true)) {
                $this->sicoesSourceType = '';
            }
            $this->ensureSicoesCompany();
        }

        $this->refreshDashboardStats();
        $this->refreshSicoesProgress();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'sort', 'statusFilter', 'categoryFilter', 'perPage'], true)) {
            $this->companiesPage = 1;
        }
    }

    public function goToCompaniesPage(int $page): void
    {
        $this->companiesPage = max(1, $page);
    }

    public function previousCompaniesPage(): void
    {
        $this->companiesPage = max(1, $this->companiesPage - 1);
    }

    public function nextCompaniesPage(): void
    {
        $this->companiesPage++;
    }

    public function createCompany(): void
    {
        $this->guardCompanySource();
        $this->resetValidation();
        $this->reset(['message', 'errorMessage']);
        $this->editingCompanyId = null;
        $this->form = [
            'name' => '',
            'evaluar_url' => '',
            'active' => true,
        ];
        $this->showForm = true;
    }

    public function editCompany(int $companyId): void
    {
        $this->guardCompanySource();
        $this->resetValidation();
        $this->reset(['message', 'errorMessage']);

        $company = $this->source->companies()->findOrFail($companyId);
        $this->editingCompanyId = $company->id;
        $this->form = [
            'name' => $company->name,
            'evaluar_url' => $company->evaluar_url,
            'active' => (bool) $company->active,
        ];
        $this->showForm = true;
    }

    public function saveCompany(): void
    {
        $this->guardCompanySource();
        $this->reset(['message', 'errorMessage']);
        $this->form['evaluar_url'] = $this->normalizeJobBoardUrl(
            (string) $this->form['evaluar_url'],
        );

        $this->validate([
            'form.name' => 'required|string|max:255',
            'form.evaluar_url' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $this->isAllowedJobBoardUrl((string) $value)) {
                        $fail($this->jobBoardUrlValidationMessage());

                        return;
                    }

                    $existingCompany = $this->findCompanyByNormalizedJobBoardUrl((string) $value);

                    if (! $existingCompany || $existingCompany->id === $this->editingCompanyId) {
                        return;
                    }

                    if (! $this->editingCompanyId && ! $existingCompany->active) {
                        return;
                    }

                    if (! $this->editingCompanyId) {
                        $fail('Ya existe una empresa activa con esa URL.');

                        return;
                    }

                    if ($existingCompany->active) {
                        $fail('Ya existe otra empresa activa con esa URL.');

                        return;
                    }

                    $fail('Esta URL pertenece a otra empresa desactivada. Reactívala o usa otra URL.');
                },
            ],
            'form.active' => 'boolean',
        ]);

        if (! $this->editingCompanyId) {
            $inactiveCompany = $this->findCompanyByNormalizedJobBoardUrl(
                (string) $this->form['evaluar_url'],
            );

            if ($inactiveCompany && ! $inactiveCompany->active) {
                $inactiveCompany->update([
                    'bot_source_id' => $this->source->id,
                    'name' => trim((string) $this->form['name']),
                    'evaluar_url' => $this->form['evaluar_url'],
                    'active' => true,
                ]);

                $this->message = 'La empresa existía desactivada y fue reactivada correctamente.';
                $this->closeForm();
                $this->refreshDashboardStats();

                return;
            }
        }

        $company = $this->editingCompanyId
            ? $this->source->companies()->findOrFail($this->editingCompanyId)
            : new BotCompany(['bot_source_id' => $this->source->id]);

        $company->fill([
            'bot_source_id' => $this->source->id,
            'name' => trim((string) $this->form['name']),
            'evaluar_url' => $this->form['evaluar_url'],
            'active' => $this->editingCompanyId ? (bool) $this->form['active'] : true,
        ]);

        if (! $company->exists || ! $company->slug) {
            $company->slug = $this->uniqueSlug($company->name, $company->id);
        }

        $company->save();

        $this->message = $this->editingCompanyId
            ? 'Empresa actualizada correctamente.'
            : 'Empresa agregada correctamente.';
        $this->closeForm();
        $this->refreshDashboardStats();
    }

    public function removeCompany(int $companyId): void
    {
        $this->guardCompanySource();
        $this->reset(['message', 'errorMessage']);

        $company = $this->source->companies()->findOrFail($companyId);
        $company->update(['active' => false]);

        $this->message = 'Empresa quitada del BOT. No se borraron convocatorias ni previsualizaciones.';
        $this->refreshDashboardStats();
    }

    public function runSicoes(): void
    {
        $this->guardSicoesSource();
        $this->reset(['message', 'errorMessage']);

        $this->validate([
            'sicoesDate' => 'required|date_format:Y-m-d',
            'sicoesSourceType' => 'required|in:'.implode(',', SicoesScrapeBatch::SOURCE_TYPES),
        ]);

        $batch = null;
        $dispatched = false;

        try {
            if (config('queue.default') === 'sync') {
                $this->errorMessage = 'SICOES requiere un worker activo. Inicia manualmente con php artisan queue:work database --queue=sicoes,default.';

                return;
            }

            $company = $this->ensureSicoesCompany();

            if (! $company->active) {
                $this->errorMessage = 'La fuente SICOES esta deshabilitada. Reactivala antes de iniciar una ejecucion.';

                return;
            }

            $requestedDate = $this->sicoesDate;
            $sourceType = $this->sicoesSourceType;
            $batch = DB::transaction(function () use ($company, $requestedDate, $sourceType): ?SicoesScrapeBatch {
                $lockedCompany = BotCompany::query()
                    ->whereKey($company->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $lockedCompany->active) {
                    throw new \RuntimeException(
                        'La empresa SICOES fue deshabilitada mientras se preparaba la ejecucion.'
                    );
                }

                if ((int) $lockedCompany->bot_source_id !== (int) $this->source->id) {
                    throw new \RuntimeException(
                        'La empresa SICOES cambio de fuente mientras se preparaba la ejecucion.'
                    );
                }

                $activeBatch = SicoesScrapeBatch::query()
                    ->where('bot_company_id', $lockedCompany->getKey())
                    ->where('requested_date', $requestedDate)
                    ->where('source_type', $sourceType)
                    ->whereIn('status', SicoesScrapeBatch::ACTIVE_STATUSES)
                    ->lockForUpdate()
                    ->first();

                if ($activeBatch) {
                    return null;
                }

                return SicoesScrapeBatch::query()->create([
                    'id' => (string) Str::uuid(),
                    'bot_company_id' => $lockedCompany->getKey(),
                    'requested_date' => $requestedDate,
                    'source_type' => $sourceType,
                    'status' => SicoesScrapeBatch::STATUS_QUEUED,
                ]);
            }, 3);

            if (! $batch) {
                $this->errorMessage = 'Ya existe una ejecucion SICOES activa para esa fecha. Espera a que termine antes de volver a enviarla.';

                return;
            }

            $runId = (string) $batch->getKey();

            $this->sicoesProgress = [
                'run_id' => $runId,
                'status' => SicoesScrapeBatch::STATUS_QUEUED,
                'date' => $requestedDate,
                'source_type' => $sourceType,
                'total' => 0,
                'processed' => 0,
                'saved' => 0,
                'updated' => 0,
                'failed' => 0,
                'last_step' => 'Job en cola',
                'queued_at' => now()->toDateTimeString(),
            ];
            if (! SicoesProgressCache::replace($requestedDate, $this->sicoesProgress, $sourceType)) {
                throw new \RuntimeException('No se pudo inicializar el progreso SICOES.');
            }

            ProcessSicoesJob::dispatch(
                $requestedDate,
                (int) $company->getKey(),
                (string) auth()->id(),
                $runId,
                $sourceType,
            )->afterCommit();
            $dispatched = true;

            $this->ensureSicoesQueueWorker();

            $this->message = 'SICOES fue enviado a la cola. El scraper correra en background y mostrara resultados para revisar antes de publicar.';
        } catch (\Throwable $exception) {
            $failure = SensitiveDataSanitizer::exception($exception, 240);
            $storedFailure = [
                'type' => $failure['type'],
                'code' => $failure['code'],
                'failed_at' => now()->toIso8601String(),
            ];
            $markedAsFailed = false;

            if ($batch && ! $dispatched) {
                try {
                    $markedAsFailed = SicoesScrapeBatch::query()
                        ->whereKey($batch->getKey())
                        ->where('status', SicoesScrapeBatch::STATUS_QUEUED)
                        ->update([
                            'status' => SicoesScrapeBatch::STATUS_FAILED,
                            'summary' => json_encode(
                                ['failure' => $storedFailure],
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                            ),
                            'finished_at' => now(),
                        ]) === 1;
                } catch (\Throwable $recoveryException) {
                    Log::warning('SICOES no pudo marcar como fallido un lote cuyo despacho fallo.', [
                        'run_id' => (string) $batch->getKey(),
                        'exception_type' => get_debug_type($recoveryException),
                    ]);
                }
            }

            if ($batch && $markedAsFailed) {
                $this->sicoesProgress = [
                    'run_id' => (string) $batch->getKey(),
                    'status' => SicoesScrapeBatch::STATUS_FAILED,
                    'date' => $this->sicoesDate,
                    'failed' => 1,
                    'last_step' => 'No se pudo enviar el trabajo SICOES a la cola.',
                    'failed_at' => now()->toDateTimeString(),
                ];

                try {
                    SicoesProgressCache::update(
                        $this->sicoesDate,
                        function (array $current) use ($batch): ?array {
                            $currentRunId = $current['run_id'] ?? null;
                            $failedRunId = (string) $batch->getKey();

                            if (
                                $currentRunId
                                && ! hash_equals((string) $currentRunId, $failedRunId)
                            ) {
                                return null;
                            }

                            return $this->sicoesProgress;
                        },
                        $this->sicoesSourceType,
                    );
                } catch (\Throwable $cacheException) {
                    Log::warning('SICOES no pudo actualizar la cache de un despacho fallido.', [
                        'run_id' => (string) $batch->getKey(),
                        'exception_type' => get_debug_type($cacheException),
                    ]);
                }
            }

            Log::error('SICOES no pudo crear o despachar el trabajo.', [
                'run_id' => $batch ? (string) $batch->getKey() : null,
                'bot_company_id' => $batch?->bot_company_id,
                'requested_date' => $this->sicoesDate,
                'failure' => $failure,
            ]);
            $this->errorMessage = $dispatched
                ? 'El trabajo SICOES quedó en cola, pero no se pudo iniciar automáticamente el worker. El lote permanece en espera.'
                : 'SICOES no pudo iniciar la ejecucion. Revisa la configuracion de la cola e intentalo nuevamente.';
        }

        try {
            $this->refreshDashboardStats();
        } catch (\Throwable $refreshException) {
            Log::warning('SICOES no pudo refrescar las estadisticas despues del despacho.', [
                'exception_type' => get_debug_type($refreshException),
            ]);
        }
    }

    public function refreshSicoesProgress(): void
    {
        if ($this->source->scraper_type !== 'sicoes') {
            return;
        }

        if (! in_array($this->sicoesSourceType, SicoesScrapeBatch::SOURCE_TYPES, true)) {
            $this->sicoesProgress = [];

            return;
        }

        $this->sicoesProgress = Cache::get($this->sicoesProgressKey($this->sicoesDate), []);
    }

    public function selectSicoesSource(string $sourceType): void
    {
        $this->guardSicoesSource();
        abort_unless(in_array($sourceType, SicoesScrapeBatch::SOURCE_TYPES, true), 404);

        $this->sicoesSourceType = $sourceType;
        $this->reset(['message', 'errorMessage']);
        $this->refreshSicoesProgress();
    }

    public function chooseAnotherSicoesSource(): void
    {
        $this->guardSicoesSource();
        $this->sicoesSourceType = '';
        $this->sicoesProgress = [];
        $this->reset(['message', 'errorMessage']);
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingCompanyId = null;
    }

    public function render()
    {
        $companies = $this->isCompanyScraperSource()
            ? $this->paginateCompanyQuery()
            : new LengthAwarePaginator(collect(), 0, $this->perPage, $this->companiesPage);

        return view('livewire.admin.bot.bot-companies', [
            'companies' => $companies,
            'companyStats' => $this->companyStats,
            'previewStats' => $this->previewStats,
            'sicoesStats' => $this->sicoesStats,
            'categories' => $this->categories,
            'companyCategoryLabels' => collect($companies->items())
                ->mapWithKeys(fn (BotCompany $company) => [$company->id => $this->categories[$this->companyCategory($company)] ?? 'Otros'])
                ->all(),
        ]);
    }

    private function paginateCompanyQuery(): LengthAwarePaginator
    {
        $perPage = in_array((int) $this->perPage, [12, 24, 48], true) ? (int) $this->perPage : 12;
        $query = $this->source->companies()->getQuery();

        if ($this->statusFilter === 'active') {
            $query->where('active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('active', false);
        } elseif ($this->statusFilter === 'without_scraper') {
            $query->where(fn ($builder) => $builder->whereNull('evaluar_url')->orWhere('evaluar_url', ''));
        }

        if ($this->categoryFilter !== 'all') {
            $query->whereIn('id', $this->categoryFilteredCompanyIds());
        }

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('evaluar_url', 'like', "%{$search}%");
            });
        }

        match ($this->sort) {
            'name_desc' => $query->orderByDesc('name'),
            'recent' => $query->orderByDesc('created_at'),
            'oldest' => $query->orderBy('created_at'),
            'active_first' => $query->orderByDesc('active')->orderBy('name'),
            default => $query->orderBy('name'),
        };

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $this->companiesPage = min(max(1, $this->companiesPage), $lastPage);

        return $query->paginate($perPage, ['*'], 'companiesPage', $this->companiesPage);
    }

    private function refreshDashboardStats(): void
    {
        if ($this->source->scraper_type === 'sicoes') {
            $sicoesCompany = $this->ensureSicoesCompany();

            $this->sicoesStats = [
                'published' => BotVacancyPreview::where('bot_company_id', $sicoesCompany->id)
                    ->whereIn('status', ['preview', 'edited', 'error'])
                    ->whereNull('removed_from_batch_at')
                    ->count(),
                'today_count' => BotVacancyPreview::where('bot_company_id', $sicoesCompany->id)
                    ->whereDate('updated_at', today())
                    ->count(),
                'last_run' => BotVacancyPreview::where('bot_company_id', $sicoesCompany->id)
                    ->latest('updated_at')
                    ->value('updated_at'),
            ];

            $this->companyStats = [
                'active' => $this->source->companies()->where('active', true)->count(),
                'inactive' => $this->source->companies()->where('active', false)->count(),
                'without_scraper' => 0,
                'total' => $this->source->companies()->count(),
            ];

            return;
        }

        if (! $this->isCompanyScraperSource()) {
            return;
        }

        $baseQuery = BotCompany::query()->where('bot_source_id', $this->source->id);
        $companyIds = (clone $baseQuery)->pluck('id')->all();

        $this->companyStats = [
            'active' => (clone $baseQuery)->where('active', true)->count(),
            'inactive' => (clone $baseQuery)->where('active', false)->count(),
            'without_scraper' => (clone $baseQuery)->where(fn ($query) => $query->whereNull('evaluar_url')->orWhere('evaluar_url', ''))->count(),
            'total' => (clone $baseQuery)->count(),
        ];

        $this->previewStats = $companyIds === []
            ? ['week_count' => 0, 'today_count' => 0, 'last_run' => null]
            : [
                'week_count' => BotVacancyPreview::whereIn('bot_company_id', $companyIds)
                    ->where('created_at', '>=', now()->startOfWeek())
                    ->count(),
                'today_count' => BotVacancyPreview::whereIn('bot_company_id', $companyIds)
                    ->whereDate('created_at', today())
                    ->count(),
                'last_run' => BotVacancyPreview::whereIn('bot_company_id', $companyIds)
                    ->latest('updated_at')
                    ->value('updated_at'),
            ];
    }

    private function categoryFilteredCompanyIds(): array
    {
        return $this->source->companies()
            ->get(['id', 'name', 'evaluar_url'])
            ->filter(fn (BotCompany $company) => $this->companyCategory($company) === $this->categoryFilter)
            ->pluck('id')
            ->all();
    }

    private function categoryOptions(): array
    {
        return [
            'all' => 'Todas las categorias',
            'banks' => 'Bancos',
            'insurance' => 'Seguros',
            'pharma' => 'Farmaceuticas',
            'education' => 'Educacion',
            'other' => 'Otros',
        ];
    }

    private function companyCategory(BotCompany $company): string
    {
        $name = Str::of($company->name)->ascii()->lower()->toString();
        $url = Str::of($company->evaluar_url)->ascii()->lower()->toString();
        $value = "{$name} {$url}";

        if (Str::contains($value, ['banco', 'bmsc', 'bisa', 'fie', 'union', 'sol', 'economico', 'bcp'])) {
            return 'banks';
        }

        if (Str::contains($value, ['seguro', 'alianza'])) {
            return 'insurance';
        }

        if (Str::contains($value, ['farma', 'bago', 'laboratorio'])) {
            return 'pharma';
        }

        if (Str::contains($value, ['universidad', 'educacion', 'colegio', 'escuela'])) {
            return 'education';
        }

        return 'other';
    }

    private function guardCompanySource(): void
    {
        abort_unless($this->isCompanyScraperSource(), 404);
    }

    private function isCompanyScraperSource(): bool
    {
        return in_array($this->source->scraper_type, ['evaluar', 'etalent'], true);
    }

    private function guardSicoesSource(): void
    {
        abort_unless($this->source->scraper_type === 'sicoes', 404);
    }

    private function sicoesProgressKey(string $date): string
    {
        return SicoesProgressCache::key($date, $this->sicoesSourceType ?: SicoesScrapeBatch::SOURCE_CONSULTING);
    }

    private function ensureSicoesCompany(): BotCompany
    {
        $company = BotCompany::query()->where('slug', 'sicoes')->first();

        if (! $company) {
            $company = DB::transaction(function (): BotCompany {
                BotSource::query()
                    ->whereKey($this->source->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                return BotCompany::firstOrCreate(
                    ['slug' => 'sicoes'],
                    [
                        'bot_source_id' => $this->source->id,
                        'name' => 'SICOES',
                        'evaluar_url' => 'https://www.sicoes.gob.bo',
                        'logo' => null,
                        'active' => true,
                    ],
                );
            }, 3);
        }

        if ((int) $company->bot_source_id !== (int) $this->source->id) {
            throw new \RuntimeException(
                'La empresa SICOES esta vinculada a una fuente distinta y requiere revision administrativa.'
            );
        }

        return $company;
    }

    private function normalizeJobBoardUrl(string $url): string
    {
        $url = trim($url);

        if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);

        if (! $parts || empty($parts['host'])) {
            return $url;
        }

        $scheme = 'https';
        $host = strtolower($parts['host']);
        $path = preg_replace('#/+#', '/', $parts['path'] ?? '') ?: '';
        $path = $path !== '/' ? rtrim($path, '/') : '';
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $queryString = $query === []
            ? ''
            : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return "{$scheme}://{$host}{$path}{$queryString}";
    }

    private function isAllowedJobBoardUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        if ($this->source->scraper_type === 'evaluar') {
            return Str::endsWith($host, '.evaluar.com')
                || Str::endsWith($host, '.evaluarjobs.com');
        }

        if ($this->source->scraper_type !== 'etalent' || $host !== 'e-talent.jobs') {
            return false;
        }

        if (rtrim((string) ($parts['path'] ?? ''), '/') !== '/bolsa-de-trabajo') {
            return false;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        return trim((string) ($query['search_keywords'] ?? '')) !== '';
    }

    private function findCompanyByNormalizedJobBoardUrl(string $url): ?BotCompany
    {
        $normalizedUrl = $this->normalizeJobBoardUrl($url);

        return BotCompany::query()
            ->get(['id', 'evaluar_url', 'active', 'bot_source_id'])
            ->first(
                fn (BotCompany $company) => $this->normalizeJobBoardUrl($company->evaluar_url)
                    === $normalizedUrl,
            );
    }

    private function jobBoardUrlValidationMessage(): string
    {
        return $this->source->scraper_type === 'etalent'
            ? 'La URL debe usar https://e-talent.jobs/bolsa-de-trabajo/ e incluir search_keywords.'
            : 'La URL debe ser un subdominio válido de evaluar.com o evaluarjobs.com.';
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'empresa';
        $slug = $base;
        $counter = 2;

        while (BotCompany::where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}

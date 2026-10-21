<section>
    <div class="min-h-[calc(100vh-8rem)] bg-gradient-to-br from-slate-50 via-white to-orange-50/40 py-6 text-slate-950 dark:from-slate-950 dark:via-slate-950 dark:to-slate-900 dark:text-white sm:py-8">
        <div class="mx-auto flex w-full max-w-[1600px] flex-col gap-6 px-4 sm:px-6 lg:px-10 xl:px-12">
            <header class="overflow-hidden rounded-[2rem] border border-slate-200/80 bg-white/90 p-5 shadow-xl shadow-slate-200/70 backdrop-blur dark:border-white/10 dark:bg-white/[0.04] dark:shadow-2xl dark:shadow-black/20 md:p-6 lg:p-7">
                <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                    <div class="min-w-0">
                        <a href="{{ route('admin.bot.index') }}" wire:navigate
                            class="inline-flex items-center gap-2 text-sm font-semibold text-slate-500 transition hover:text-orange-600 dark:text-slate-300 dark:hover:text-orange-300">
                            BOT &#129302;
                        </a>
                        <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950 dark:text-white sm:text-4xl">
                            BOT / {{ $source->name }}
                        </h1>
                        <p class="mt-2 max-w-3xl text-sm font-medium leading-6 text-slate-600 dark:text-slate-300">
                            {{ $source->description }}
                        </p>
                    </div>

                    @if (in_array($source->scraper_type, ['evaluar', 'etalent'], true))
                        <div class="grid gap-3 sm:grid-cols-3 xl:min-w-[680px]">
                            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-400/20 dark:bg-emerald-400/10">
                                <p class="text-xs font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-200">Empresas</p>
                                <p class="mt-2 text-2xl font-black text-slate-950 dark:text-white">{{ $companyStats['active'] }}</p>
                                <p class="text-xs font-semibold text-emerald-700/80 dark:text-emerald-100/80">empresas activas</p>
                            </div>

                            <div class="rounded-2xl border border-orange-200 bg-orange-50 p-4 dark:border-orange-400/20 dark:bg-orange-400/10">
                                <p class="text-xs font-bold uppercase tracking-wide text-orange-700 dark:text-orange-200">Esta semana</p>
                                <p class="mt-2 text-2xl font-black text-slate-950 dark:text-white">{{ $previewStats['week_count'] }}</p>
                                <p class="text-xs font-semibold text-orange-700/80 dark:text-orange-100/80">
                                    Ultima ejecucion:
                                    {{ $previewStats['last_run'] ? \Illuminate\Support\Carbon::parse($previewStats['last_run'])->format('d/m H:i') : 'sin registro' }}
                                </p>
                            </div>

                            <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-400/20 dark:bg-sky-400/10">
                                <p class="text-xs font-bold uppercase tracking-wide text-sky-700 dark:text-sky-200">Hoy</p>
                                <p class="mt-2 text-2xl font-black text-slate-950 dark:text-white">+{{ $previewStats['today_count'] }}</p>
                                <p class="text-xs font-semibold text-sky-700/80 dark:text-sky-100/80">convocatorias de hoy</p>
                            </div>
                        </div>
                    @endif
                </div>
            </header>

            @if ($message)
                <div class="rounded-2xl border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800 shadow-lg shadow-green-100/60 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-100 dark:shadow-black/10">
                    {{ $message }}
                </div>
            @endif

            @if ($errorMessage)
                <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800 shadow-lg shadow-red-100/60 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100 dark:shadow-black/10">
                    {{ $errorMessage }}
                </div>
            @endif

            @if ($source->scraper_type === 'sicoes')
                @if ($sicoesSourceType === '')
                    <div class="rounded-[2rem] border border-slate-200 bg-white/90 p-6 shadow-xl shadow-slate-200/70 backdrop-blur dark:border-white/10 dark:bg-white/[0.04] dark:shadow-2xl dark:shadow-black/20">
                        <p class="text-xs font-black uppercase tracking-[0.2em] text-orange-600 dark:text-orange-300">SICOES</p>
                        <h2 class="mt-2 text-2xl font-black text-slate-950 dark:text-white">¿Qué tipo de publicación deseas procesar?</h2>
                        <p class="mt-2 text-sm font-medium leading-6 text-slate-600 dark:text-slate-300">Cada opción conserva su propio lote, progreso y documentos.</p>

                        <div class="mt-6 grid gap-5 md:grid-cols-2">
                            <button type="button" wire:click="selectSicoesSource('{{ \App\Models\SicoesScrapeBatch::SOURCE_CONSULTING }}')"
                                class="group rounded-3xl border border-orange-200 bg-gradient-to-br from-orange-50 to-white p-6 text-left transition hover:-translate-y-1 hover:border-orange-400 hover:shadow-xl hover:shadow-orange-100 dark:border-orange-400/20 dark:from-orange-500/10 dark:to-white/[0.03] dark:hover:border-orange-400/50 dark:hover:shadow-black/20">
                                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-orange-500 text-xl text-white"><i class="fas fa-file-contract"></i></span>
                                <span class="mt-5 block text-xl font-black text-slate-950 dark:text-white">Servicios de consultoría</span>
                                <span class="mt-2 block text-sm font-medium leading-6 text-slate-600 dark:text-slate-300">Convocatorias nacionales identificadas mediante CUCE.</span>
                                <span class="mt-4 inline-flex items-center text-sm font-black text-orange-700 dark:text-orange-300">Ingresar <i class="ml-2 fas fa-arrow-right transition group-hover:translate-x-1"></i></span>
                            </button>

                            <button type="button" wire:click="selectSicoesSource('{{ \App\Models\SicoesScrapeBatch::SOURCE_PERSONNEL }}')"
                                class="group rounded-3xl border border-sky-200 bg-gradient-to-br from-sky-50 to-white p-6 text-left transition hover:-translate-y-1 hover:border-sky-400 hover:shadow-xl hover:shadow-sky-100 dark:border-sky-400/20 dark:from-sky-500/10 dark:to-white/[0.03] dark:hover:border-sky-400/50 dark:hover:shadow-black/20">
                                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-600 text-xl text-white"><i class="fas fa-users"></i></span>
                                <span class="mt-5 block text-xl font-black text-slate-950 dark:text-white">Requerimientos de personal</span>
                                <span class="mt-2 block text-sm font-medium leading-6 text-slate-600 dark:text-slate-300">Publicaciones identificadas por referencia, incluidos PDF escaneados.</span>
                                <span class="mt-4 inline-flex items-center text-sm font-black text-sky-700 dark:text-sky-300">Ingresar <i class="ml-2 fas fa-arrow-right transition group-hover:translate-x-1"></i></span>
                            </button>
                        </div>
                    </div>
                @else
                <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                    <div class="rounded-[2rem] border border-slate-200 bg-white/90 p-6 shadow-xl shadow-slate-200/70 backdrop-blur dark:border-white/10 dark:bg-white/[0.04] dark:shadow-2xl dark:shadow-black/20">
                        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <p class="text-xs font-black uppercase tracking-[0.2em] text-orange-600 dark:text-orange-300">SICOES</p>
                                <h2 class="mt-2 text-2xl font-black text-slate-950 dark:text-white">
                                    {{ $sicoesSourceType === \App\Models\SicoesScrapeBatch::SOURCE_PERSONNEL ? 'Requerimientos de personal' : 'Servicios de consultoría' }}
                                </h2>
                                <p class="mt-2 max-w-3xl text-sm font-medium leading-6 text-slate-600 dark:text-slate-300">
                                    Procesa la fecha seleccionada y muestra los resultados encontrados. No se publican hasta revisarlos en el modal.
                                </p>
                            </div>

                            <span class="inline-flex w-fit items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-4 py-2 text-xs font-black uppercase tracking-wide text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                Activo
                            </span>
                        </div>

                        <button type="button" wire:click="chooseAnotherSicoesSource" class="mt-4 inline-flex items-center text-xs font-black text-slate-500 transition hover:text-orange-600 dark:text-slate-300">
                            <i class="mr-2 fas fa-arrow-left"></i> Elegir otra sección de SICOES
                        </button>

                        <div class="mt-6 grid items-end gap-4 md:grid-cols-[1fr_auto]">
                            <div>
                                <x-label for="sicoes-date">Fecha de publicación</x-label>
                                <x-input id="sicoes-date" type="date" wire:model.live="sicoesDate"
                                    class="mt-1 block w-full rounded-2xl border-slate-200 bg-white shadow-sm focus:border-orange-400 focus:ring-orange-400 dark:border-white/10 dark:bg-slate-950/70 dark:text-white" />
                                <x-input-error for="sicoesDate" class="mt-2" />
                            </div>

                            <button type="button" wire:click="runSicoes" wire:loading.attr="disabled" wire:target="runSicoes"
                                class="inline-flex w-full items-center justify-center rounded-2xl bg-gradient-to-r from-orange-500 to-red-500 px-5 py-3 text-sm font-black text-white shadow-lg shadow-orange-200 transition hover:-translate-y-0.5 hover:from-orange-400 hover:to-red-400 hover:shadow-orange-300 disabled:cursor-wait disabled:opacity-70 dark:shadow-orange-950/30 md:w-auto">
                                <span wire:loading.remove wire:target="runSicoes">
                                    <i class="mr-1 text-xs fas fa-robot"></i> Ejecutar SICOES
                                </span>
                                <span wire:loading wire:target="runSicoes">Ejecutando...</span>
                            </button>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <a href="{{ route('admin.bot.company.preview', array_filter(['source' => $source, 'company' => 'sicoes', 'batch' => $sicoesProgress['run_id'] ?? null])) }}"
                                class="inline-flex items-center justify-center rounded-2xl border border-sky-200 bg-sky-50 px-4 py-2.5 text-xs font-black text-sky-800 transition hover:border-sky-300 hover:bg-sky-100 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-100 dark:hover:bg-sky-500/20">
                                <i class="mr-2 fas fa-list-check"></i>
                                Revisar resultados encontrados
                            </a>
                        </div>

                        <div wire:loading wire:target="runSicoes"
                            class="mt-5 flex items-start gap-3 rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm font-semibold text-blue-800 shadow-lg shadow-blue-100/60 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-100 dark:shadow-black/10">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-700 dark:bg-blue-400/20 dark:text-blue-100">
                                <i class="fas fa-spinner fa-spin text-xs"></i>
                            </span>
                            <span>Procesando convocatorias SICOES...</span>
                        </div>

                        <div wire:poll.5s="refreshSicoesProgress" class="mt-5">
                            @if (!empty($sicoesProgress))
                                @php
                                    $total = (int) ($sicoesProgress['total'] ?? 0);
                                    $processed = (int) ($sicoesProgress['processed'] ?? 0);
                                    $percent = $total > 0 ? min(100, (int) floor(($processed / $total) * 100)) : 0;
                                @endphp

                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm font-semibold text-slate-700 dark:border-white/10 dark:bg-white/[0.03] dark:text-slate-200">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <span>{{ $sicoesProgress['last_step'] ?? 'SICOES en progreso' }}</span>
                                        <span>{{ $processed }}/{{ $total }} procesadas</span>
                                    </div>
                                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                                        <div class="h-full rounded-full bg-orange-500 transition-all" style="width: {{ $percent }}%"></div>
                                    </div>
                                    <div class="mt-3 grid gap-2 text-xs text-slate-500 dark:text-slate-400 sm:grid-cols-4">
                                        <span>Fecha ejecutada: {{ !empty($sicoesProgress['date']) ? \Illuminate\Support\Carbon::parse($sicoesProgress['date'])->format('d/m/Y') : '-' }}</span>
                                        <span>Estado: {{ \App\Support\BotUiLabels::processStatus($sicoesProgress['status'] ?? null) }}</span>
                                        <span>Nuevas previsualizaciones: {{ $sicoesProgress['saved'] ?? 0 }}</span>
                                        <span>Previsualizaciones actualizadas: {{ $sicoesProgress['updated'] ?? 0 }}</span>
                                        <span>Fallidas: {{ $sicoesProgress['failed'] ?? 0 }}</span>
                                        <span>Descartadas: {{ $sicoesProgress['discarded'] ?? 0 }}</span>
                                        <span>Descartes sin IA: {{ $sicoesProgress['preclassified_discards'] ?? 0 }}</span>
                                        <span>Empresa/bienes/obra: {{ $sicoesProgress['discarded_company_or_goods'] ?? 0 }}</span>
                                        <span>No consultor persona: {{ $sicoesProgress['discarded_not_individual_consultant'] ?? 0 }}</span>
                                        <span>Claude llamadas: {{ $sicoesProgress['ai_calls'] ?? 0 }}</span>
                                        <span>Analisis reutilizados: {{ $sicoesProgress['ai_cache_hits'] ?? 0 }}</span>
                                        <span>Errores Claude: {{ $sicoesProgress['ai_errors'] ?? 0 }}</span>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    <aside class="rounded-[2rem] border border-slate-200 bg-white/90 p-5 shadow-xl shadow-slate-200/70 ring-1 ring-slate-100 backdrop-blur dark:border-white/10 dark:bg-slate-900/70 dark:shadow-2xl dark:shadow-black/20 dark:ring-white/5">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-black uppercase tracking-[0.2em] text-sky-700 dark:text-sky-200/80">Resumen</p>
                                <h2 class="mt-1 text-xl font-black text-slate-950 dark:text-white">Previsualizaciones</h2>
                            </div>
                            <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-sky-50 text-sky-700 dark:bg-sky-400/10 dark:text-sky-200">
                                <i class="fas fa-database"></i>
                            </div>
                        </div>

                        <div class="mt-6 grid gap-3">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                                <p class="text-xs font-bold uppercase tracking-wide text-slate-500">En revision SICOES</p>
                                <p class="mt-2 text-3xl font-black text-slate-950 dark:text-white">{{ $sicoesStats['published'] }}</p>
                            </div>
                            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-400/20 dark:bg-emerald-400/10">
                                <p class="text-xs font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-200">Previsualizaciones hoy</p>
                                <p class="mt-2 text-3xl font-black text-slate-950 dark:text-white">{{ $sicoesStats['today_count'] }}</p>
                            </div>
                            <div class="rounded-2xl border border-orange-200 bg-orange-50 p-4 dark:border-orange-400/20 dark:bg-orange-400/10">
                                <p class="text-xs font-bold uppercase tracking-wide text-orange-700 dark:text-orange-200">Ultima ejecucion</p>
                                <p class="mt-2 text-sm font-black text-slate-950 dark:text-white">
                                    {{ $sicoesStats['last_run'] ? \Illuminate\Support\Carbon::parse($sicoesStats['last_run'])->format('d/m/Y H:i') : 'sin registro' }}
                                </p>
                            </div>
                        </div>
                    </aside>
                </div>
                @endif
            @elseif (!in_array($source->scraper_type, ['evaluar', 'etalent'], true))
                <div class="rounded-[2rem] border border-slate-200 bg-white p-10 text-center shadow-xl shadow-slate-200/70 dark:border-white/10 dark:bg-white/[0.04] dark:shadow-2xl dark:shadow-black/20">
                    <div class="mb-3 text-5xl">{{ $source->icon }}</div>
                    <h2 class="text-2xl font-black text-slate-950 dark:text-white">{{ $source->name }}</h2>
                    <p class="mt-2 text-sm font-medium text-slate-600 dark:text-slate-300">Este grupo todavía no tiene extractor implementado.</p>
                </div>
            @else
                <div class="rounded-[2rem] border border-slate-200 bg-white/90 p-4 shadow-xl shadow-slate-200/70 backdrop-blur dark:border-white/10 dark:bg-white/[0.04] dark:shadow-2xl dark:shadow-black/20 lg:p-5">
                    <div class="flex flex-col gap-3 xl:flex-row xl:items-center">
                        <label class="relative flex-1">
                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                <i class="fas fa-search"></i>
                            </span>
                            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Buscar empresa..."
                                class="w-full rounded-2xl border border-slate-200 bg-white py-3 pl-11 pr-4 text-sm font-semibold text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-orange-400 focus:ring-4 focus:ring-orange-400/10 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:placeholder:text-slate-500">
                        </label>

                        <div class="grid gap-3 sm:grid-cols-2 xl:w-[520px]">
                            <label class="relative">
                                <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                    <i class="fas fa-sort"></i>
                                </span>
                                <select wire:model.change="sort"
                                    class="w-full appearance-none rounded-2xl border border-slate-200 bg-white py-3 pl-11 pr-10 text-sm font-semibold text-slate-900 outline-none transition focus:border-orange-400 focus:ring-4 focus:ring-orange-400/10 dark:border-white/10 dark:bg-slate-950/70 dark:text-white">
                                    <option value="name_asc">Nombre A-Z</option>
                                    <option value="name_desc">Nombre Z-A</option>
                                    <option value="recent">Mas recientes primero</option>
                                    <option value="oldest">Mas antiguas primero</option>
                                    <option value="active_first">Activas primero</option>
                                </select>
                            </label>

                            <button type="button" wire:click="createCompany" wire:loading.attr="disabled" wire:target="createCompany"
                                class="inline-flex items-center justify-center rounded-2xl bg-gradient-to-r from-orange-500 to-red-500 px-5 py-3 text-sm font-black text-white shadow-lg shadow-orange-200 transition hover:-translate-y-0.5 hover:from-orange-400 hover:to-red-400 hover:shadow-orange-300 disabled:cursor-wait disabled:opacity-70 dark:shadow-orange-950/30 dark:hover:shadow-orange-500/20">
                                <i class="mr-2 text-xs fas fa-plus"></i>
                                Agregar empresa
                            </button>
                        </div>
                    </div>
                </div>

                <div class="grid gap-6 xl:grid-cols-[320px_minmax(0,1fr)]">
                    <aside class="rounded-[2rem] border border-slate-200 bg-white/90 p-5 shadow-xl shadow-slate-200/70 ring-1 ring-slate-100 backdrop-blur dark:border-sky-300/10 dark:bg-slate-900/70 dark:shadow-2xl dark:shadow-black/20 dark:ring-white/5 xl:sticky xl:top-6 xl:self-start">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-black uppercase tracking-[0.2em] text-sky-700 dark:text-sky-200/80">Filtros</p>
                                <h2 class="mt-1 text-xl font-black text-slate-950 dark:text-white">Panel {{ $source->name }}</h2>
                            </div>
                            <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-sky-50 text-sky-700 dark:bg-sky-400/10 dark:text-sky-200">
                                <i class="fas fa-sliders-h"></i>
                            </div>
                        </div>

                        <div class="mt-6 space-y-6">
                            <div>
                                <p class="mb-3 text-sm font-bold text-slate-700 dark:text-slate-200">Estado del extractor</p>
                                <div class="space-y-2">
                                    <button type="button" wire:click="$set('statusFilter', 'active')" wire:loading.attr="disabled" wire:target="statusFilter"
                                        class="flex w-full items-center justify-between rounded-2xl border px-4 py-3 text-sm font-bold transition disabled:cursor-wait disabled:opacity-70 {{ $statusFilter === 'active' ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-400/40 dark:bg-emerald-400/10 dark:text-emerald-100' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50 dark:border-white/10 dark:bg-white/[0.03] dark:text-slate-300 dark:hover:border-white/20 dark:hover:bg-white/[0.06]' }}">
                                        <span class="inline-flex items-center gap-2">
                                            <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                                            Activo
                                        </span>
                                        <span>{{ $companyStats['active'] }}</span>
                                    </button>

                                    <button type="button" wire:click="$set('statusFilter', 'inactive')" wire:loading.attr="disabled" wire:target="statusFilter"
                                        class="flex w-full items-center justify-between rounded-2xl border px-4 py-3 text-sm font-bold transition disabled:cursor-wait disabled:opacity-70 {{ $statusFilter === 'inactive' ? 'border-red-300 bg-red-50 text-red-800 dark:border-red-400/40 dark:bg-red-400/10 dark:text-red-100' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50 dark:border-white/10 dark:bg-white/[0.03] dark:text-slate-300 dark:hover:border-white/20 dark:hover:bg-white/[0.06]' }}">
                                        <span class="inline-flex items-center gap-2">
                                            <span class="h-2 w-2 rounded-full bg-red-400"></span>
                                            Inactivo
                                        </span>
                                        <span>{{ $companyStats['inactive'] }}</span>
                                    </button>

                                    <button type="button" wire:click="$set('statusFilter', 'without_scraper')" wire:loading.attr="disabled" wire:target="statusFilter"
                                        class="flex w-full items-center justify-between rounded-2xl border px-4 py-3 text-sm font-bold transition disabled:cursor-wait disabled:opacity-70 {{ $statusFilter === 'without_scraper' ? 'border-slate-300 bg-slate-100 text-slate-900 dark:border-slate-300/40 dark:bg-slate-300/10 dark:text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50 dark:border-white/10 dark:bg-white/[0.03] dark:text-slate-300 dark:hover:border-white/20 dark:hover:bg-white/[0.06]' }}">
                                        <span class="inline-flex items-center gap-2">
                                            <span class="h-2 w-2 rounded-full bg-slate-400"></span>
                                            Sin extractor
                                        </span>
                                        <span>{{ $companyStats['without_scraper'] }}</span>
                                    </button>
                                </div>
                            </div>

                            <div>
                                <label class="mb-3 block text-sm font-bold text-slate-700 dark:text-slate-200" for="bot-category-filter">Categoria</label>
                                <select id="bot-category-filter" wire:model.change="categoryFilter"
                                    class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-900 outline-none transition focus:border-sky-300 focus:ring-4 focus:ring-sky-300/10 dark:border-white/10 dark:bg-slate-950/70 dark:text-white">
                                    @foreach ($categories as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-2 text-xs font-medium text-slate-500 dark:text-slate-400">Clasificacion visual inferida desde nombre y URL.</p>
                            </div>

                            <div>
                                <p class="mb-3 text-sm font-bold text-slate-700 dark:text-slate-200">Fecha de agregado</p>
                                <div class="grid gap-2">
                                    <button type="button" wire:click="$set('sort', 'recent')" wire:loading.attr="disabled" wire:target="sort"
                                        class="rounded-2xl border px-4 py-3 text-left text-sm font-bold transition disabled:cursor-wait disabled:opacity-70 {{ $sort === 'recent' ? 'border-orange-300 bg-orange-50 text-orange-800 dark:border-orange-400/40 dark:bg-orange-400/10 dark:text-orange-100' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50 dark:border-white/10 dark:bg-white/[0.03] dark:text-slate-300 dark:hover:border-white/20 dark:hover:bg-white/[0.06]' }}">
                                        Mas recientes primero
                                    </button>
                                    <button type="button" wire:click="$set('sort', 'oldest')" wire:loading.attr="disabled" wire:target="sort"
                                        class="rounded-2xl border px-4 py-3 text-left text-sm font-bold transition disabled:cursor-wait disabled:opacity-70 {{ $sort === 'oldest' ? 'border-orange-300 bg-orange-50 text-orange-800 dark:border-orange-400/40 dark:bg-orange-400/10 dark:text-orange-100' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50 dark:border-white/10 dark:bg-white/[0.03] dark:text-slate-300 dark:hover:border-white/20 dark:hover:bg-white/[0.06]' }}">
                                        Mas antiguas primero
                                    </button>
                                </div>
                            </div>
                        </div>
                    </aside>

                    <div class="min-w-0" wire:loading.class="opacity-60" wire:target="search,sort,statusFilter,categoryFilter,perPage,goToCompaniesPage,previousCompaniesPage,nextCompaniesPage">
                        <div class="grid gap-5 md:grid-cols-2 2xl:grid-cols-3">
                            @forelse ($companies as $company)
                                @php($companyLogoUrl = $company->websiteLogoUrl())
                                @php($companyDisplayName = $company->websiteCompanyName())
                                <article wire:key="bot-company-{{ $company->id }}"
                                    class="group flex min-h-[310px] flex-col rounded-[2rem] border border-slate-200 bg-gradient-to-br from-white via-white to-slate-50 p-5 shadow-xl shadow-slate-200/70 transition duration-200 hover:-translate-y-1 hover:border-orange-300 hover:shadow-orange-100 dark:border-white/10 dark:bg-gradient-to-br dark:from-slate-900 dark:via-slate-900 dark:to-slate-950 dark:shadow-black/20 dark:hover:border-orange-400/40 dark:hover:shadow-orange-950/30">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="flex items-start gap-4">
                                            @if ($companyLogoUrl)
                                                <img src="{{ $companyLogoUrl }}" alt="{{ $companyDisplayName }}"
                                                    class="h-16 w-16 rounded-2xl border border-slate-200 bg-white object-contain p-1 shadow-inner dark:border-white/10">
                                            @else
                                                <div
                                                    class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-red-500 text-2xl font-black text-white shadow-lg shadow-orange-200 dark:shadow-orange-950/30">
                                                    {{ \Illuminate\Support\Str::substr($companyDisplayName, 0, 1) }}
                                                </div>
                                            @endif
                                            <div class="min-w-0">
                                                <h2 class="line-clamp-2 font-black leading-5 text-slate-950 dark:text-white">{{ $companyDisplayName }}</h2>
                                                <p class="mt-2 break-all text-xs font-semibold leading-5 text-slate-500 dark:text-slate-400">{{ $company->evaluar_url }}</p>
                                            </div>
                                        </div>

                                        @if ($company->active)
                                            <span class="shrink-0 rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-400/10 dark:text-emerald-200 dark:ring-emerald-400/30">
                                                Activo
                                            </span>
                                        @elseif (blank($company->evaluar_url))
                                            <span class="shrink-0 rounded-full bg-slate-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-slate-600 ring-1 ring-slate-200 dark:bg-slate-400/10 dark:text-slate-200 dark:ring-slate-400/30">
                                                Sin extractor
                                            </span>
                                        @else
                                            <span class="shrink-0 rounded-full bg-red-50 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-red-700 ring-1 ring-red-200 dark:bg-red-400/10 dark:text-red-200 dark:ring-red-400/30">
                                                Inactivo
                                            </span>
                                        @endif
                                    </div>

                                    <div class="mt-5 grid grid-cols-2 gap-3">
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/[0.03]">
                                            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Categoria</p>
                                            <p class="mt-1 text-sm font-black text-slate-900 dark:text-slate-100">{{ $companyCategoryLabels[$company->id] ?? 'Otros' }}</p>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/[0.03]">
                                            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Agregada</p>
                                            <p class="mt-1 text-sm font-black text-slate-900 dark:text-slate-100">{{ optional($company->created_at)->format('d/m/Y') ?: 'Sin fecha' }}</p>
                                        </div>
                                    </div>

                                    <div class="mt-auto pt-5">
                                        @if ($company->active)
                                            <a href="{{ route('admin.bot.company.preview', ['source' => $source, 'company' => $company]) }}" wire:navigate
                                                class="flex items-center justify-between rounded-2xl border border-orange-200 bg-orange-50 px-4 py-3 text-sm font-black text-orange-700 transition hover:border-orange-300 hover:bg-orange-100 dark:border-orange-400/30 dark:bg-orange-500/10 dark:text-orange-100 dark:hover:border-orange-300 dark:hover:bg-orange-500/20">
                                                <span>Ver convocatorias</span>
                                                <i class="text-sm fas fa-arrow-right transition group-hover:translate-x-1"></i>
                                            </a>
                                        @else
                                            <div class="flex items-center justify-between rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-black text-slate-500 dark:border-white/10 dark:bg-white/[0.03] dark:text-slate-400">
                                                <span>Ver convocatorias</span>
                                                <span class="text-xs">No disponible</span>
                                            </div>
                                        @endif

                                        <div class="mt-3 grid grid-cols-2 gap-2">
                                            <button type="button" wire:click.stop="editCompany({{ $company->id }})" wire:loading.attr="disabled" wire:target="editCompany"
                                                class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-black text-slate-700 transition hover:border-sky-300 hover:bg-sky-50 hover:text-sky-700 disabled:cursor-wait disabled:opacity-70 dark:border-white/10 dark:bg-white/[0.04] dark:text-slate-200 dark:hover:border-sky-300/50 dark:hover:bg-sky-400/10 dark:hover:text-sky-100">
                                                <i class="mr-2 fas fa-pen"></i> Editar
                                            </button>
                                            <button type="button" wire:click.stop="removeCompany({{ $company->id }})"
                                                wire:confirm="&iquest;Seguro que deseas quitar esta empresa del BOT? No se borraran las convocatorias ya publicadas."
                                                wire:loading.attr="disabled" wire:target="removeCompany"
                                                class="inline-flex items-center justify-center rounded-2xl border border-red-200 bg-red-50 px-3 py-2.5 text-xs font-black text-red-700 transition hover:border-red-300 hover:bg-red-100 disabled:cursor-wait disabled:opacity-70 dark:border-red-400/30 dark:bg-red-500/10 dark:text-red-100 dark:hover:border-red-300 dark:hover:bg-red-500/20">
                                                <i class="mr-2 fas fa-trash"></i> Quitar
                                            </button>
                                        </div>
                                    </div>
                                </article>
                            @empty
                                <div class="rounded-[2rem] border border-dashed border-slate-300 bg-white p-10 text-center text-slate-600 shadow-xl shadow-slate-200/70 dark:border-white/15 dark:bg-white/[0.04] dark:text-slate-300 dark:shadow-black/20 md:col-span-2 2xl:col-span-3">
                                    No hay empresas que coincidan con los filtros actuales.
                                </div>
                            @endforelse
                        </div>

                        <div class="mt-6 flex flex-col gap-4 rounded-[2rem] border border-slate-200 bg-white/90 p-4 shadow-xl shadow-slate-200/70 dark:border-white/10 dark:bg-white/[0.04] dark:shadow-black/20 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">
                                @if ($companies->total() > 0)
                                    Mostrando {{ $companies->firstItem() }} a {{ $companies->lastItem() }} de {{ $companies->total() }} empresas
                                @else
                                    Mostrando 0 empresas
                                @endif
                            </p>

                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                <label class="flex items-center gap-2 text-sm font-semibold text-slate-600 dark:text-slate-300">
                                    Mostrar
                                    <select wire:model.change="perPage"
                                        class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-900 outline-none focus:border-orange-400 focus:ring-4 focus:ring-orange-400/10 dark:border-white/10 dark:bg-slate-950/70 dark:text-white">
                                        <option value="12">12</option>
                                        <option value="24">24</option>
                                        <option value="48">48</option>
                                    </select>
                                    por pagina
                                </label>

                                <div class="flex items-center gap-2">
                                    <button type="button" wire:click="previousCompaniesPage" wire:loading.attr="disabled" wire:target="previousCompaniesPage" @disabled($companies->onFirstPage())
                                        class="inline-flex h-10 min-w-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 text-sm font-black text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:bg-white/[0.04] dark:text-slate-200 dark:hover:bg-white/[0.08]">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>

                                    @for ($page = 1; $page <= $companies->lastPage(); $page++)
                                        <button type="button" wire:click="goToCompaniesPage({{ $page }})" wire:loading.attr="disabled" wire:target="goToCompaniesPage"
                                            class="hidden h-10 min-w-10 items-center justify-center rounded-xl border px-3 text-sm font-black transition disabled:cursor-wait disabled:opacity-60 sm:inline-flex {{ $companies->currentPage() === $page ? 'border-orange-400 bg-orange-500 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-white/10 dark:bg-white/[0.04] dark:text-slate-200 dark:hover:bg-white/[0.08]' }}">
                                            {{ $page }}
                                        </button>
                                    @endfor

                                    <button type="button" wire:click="nextCompaniesPage" wire:loading.attr="disabled" wire:target="nextCompaniesPage" @disabled(!$companies->hasMorePages())
                                        class="inline-flex h-10 min-w-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 text-sm font-black text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:bg-white/[0.04] dark:text-slate-200 dark:hover:bg-white/[0.08]">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($showForm)
        <div class="fixed inset-0 z-30 flex items-center justify-center overflow-y-auto bg-black/75 px-4 py-8 backdrop-blur-sm">
            <form wire:submit="saveCompany"
                class="tbn-form relative z-40 mx-auto w-full max-w-2xl rounded-[2rem] border border-slate-200 bg-white p-6 shadow-2xl dark:border-white/10 dark:bg-tbn-dark">
                <div class="mb-5 flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-xl font-bold text-tbn-primary">
                            {{ $editingCompanyId ? 'Editar empresa '.$source->name : 'Agregar empresa '.$source->name }}
                        </h3>
                        <p class="text-sm text-tbn-secondary dark:text-tbn-light">
                            {{ $source->scraper_type === 'etalent'
                                ? 'La URL debe pertenecer a e-talent.jobs/bolsa-de-trabajo/ e incluir search_keywords.'
                                : 'La URL debe pertenecer a un subdominio de evaluar.com o evaluarjobs.com.' }}
                        </p>
                    </div>
                    <button type="button" wire:click="closeForm" class="text-2xl leading-none text-tbn-secondary dark:text-tbn-light">
                        &times;
                    </button>
                </div>

                <div class="mb-4">
                    <x-label for="bot-company-name">Nombre de empresa</x-label>
                    <x-input id="bot-company-name" type="text" wire:model="form.name" class="mt-1 block w-full" />
                    <x-input-error for="form.name" class="mt-2" />
                </div>

                <div class="mb-4">
                    <x-label for="bot-company-url">URL {{ $source->name }}</x-label>
                    <x-input id="bot-company-url" type="text" wire:model="form.evaluar_url" class="mt-1 block w-full"
                        placeholder="{{ $source->scraper_type === 'etalent'
                            ? 'https://e-talent.jobs/bolsa-de-trabajo/?search_keywords=EMPRESA'
                            : 'https://empresa.evaluar.com' }}" />
                    <x-input-error for="form.evaluar_url" class="mt-2" />
                </div>

                <label class="mb-5 flex cursor-pointer items-start gap-3 rounded-md border border-tbn-light p-3 dark:border-tbn-secondary">
                    <x-checkbox wire:model="form.active" />
                    <span>
                        <span class="block text-sm font-semibold text-tbn-dark dark:text-white">Activa</span>
                        <span class="block text-xs text-tbn-secondary dark:text-tbn-light">Mostrar esta empresa en el BOT {{ $source->name }}.</span>
                    </span>
                </label>

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <x-secondary-button type="button" wire:click="closeForm">
                        Cancelar
                    </x-secondary-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="saveCompany">
                        Guardar empresa
                    </x-button>
                </div>
            </form>
        </div>
    @endif
</section>

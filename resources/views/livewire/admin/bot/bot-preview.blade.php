<section class="min-h-[calc(100vh-8rem)] bg-gradient-to-br from-slate-50 via-white to-orange-50/50 py-6 text-slate-950 dark:from-slate-950 dark:via-slate-950 dark:to-slate-900 dark:text-white sm:py-8">
    <div x-data="botPreview" class="mx-auto w-full max-w-[1600px] px-4 sm:px-6 lg:px-10 xl:px-12">
        @php
            $previewStatusLabels = [
                'preview' => \App\Support\BotUiLabels::previewStatus('preview'),
                'edited' => \App\Support\BotUiLabels::previewStatus('edited'),
                'published' => \App\Support\BotUiLabels::previewStatus('published'),
                'error' => \App\Support\BotUiLabels::previewStatus('error'),
            ];
        @endphp
        <header class="mb-6 overflow-hidden rounded-[2rem] border border-slate-200/80 bg-white/90 p-5 shadow-xl shadow-slate-200/70 backdrop-blur dark:border-white/10 dark:bg-white/[0.04] dark:shadow-2xl dark:shadow-black/20 md:p-6 lg:p-7">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2 text-sm font-black uppercase tracking-[0.16em]">
                        <span class="text-slate-500 dark:text-slate-300">BOT</span>
                        <span class="text-slate-300 dark:text-slate-600">/</span>
                        <span class="text-slate-500 dark:text-slate-300">{{ $source->name }}</span>
                    </div>
                    <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950 dark:text-white sm:text-4xl">
                        BOT / {{ $source->name }} /
                        <span class="text-tbn-primary">{{ $company->name }}</span>
                    </h1>
                    <p class="mt-2 max-w-3xl text-sm font-medium leading-6 text-slate-600 dark:text-slate-300">
                        Previsualizacion de convocatorias extraidas. Todavia no estan publicadas.
                    </p>
                </div>

                <div class="flex items-center gap-3 rounded-2xl border border-orange-200 bg-orange-50 px-4 py-3 text-sm font-bold text-orange-700 dark:border-orange-400/20 dark:bg-orange-400/10 dark:text-orange-100">
                    @if ($company->hasLogoFile())
                        <img src="{{ $company->logoUrl() }}" alt="{{ $company->name }}"
                            class="h-11 w-11 rounded-xl border border-white bg-white object-cover shadow-sm dark:border-white/10">
                    @else
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-red-500 text-lg font-black text-white shadow-lg shadow-orange-200 dark:shadow-orange-950/30">
                            {{ \Illuminate\Support\Str::substr($company->name, 0, 1) }}
                        </div>
                    @endif
                    <span class="min-w-0 truncate">{{ $company->evaluar_url }}</span>
                </div>
            </div>
        </header>

        @if (!in_array($source->scraper_type, ['evaluar', 'etalent', 'sicoes'], true))
            <div class="mb-5 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm font-semibold text-amber-800 shadow-lg shadow-amber-100/60 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100 dark:shadow-black/10">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-400/20 dark:text-amber-100">
                    <i class="fas fa-exclamation-triangle text-xs"></i>
                </span>
                <span>Extractor no implementado para esta fuente.</span>
            </div>
        @else
        @if (in_array($source->scraper_type, ['evaluar', 'etalent'], true))
            <div x-data="{ startDate: $wire.entangle('startDate').live, endDate: $wire.entangle('endDate').live }">
                <div class="mb-5 rounded-[2rem] border border-slate-200 bg-white p-5 shadow-xl shadow-slate-200/70 dark:border-white/10 dark:bg-white/[0.04] dark:shadow-2xl dark:shadow-black/20">
                    <div class="grid items-end gap-4 md:grid-cols-[1fr_1fr_auto]">
                        <div>
                            <x-label for="bot-start-date">Fecha inicio</x-label>
                            <x-input id="bot-start-date" type="date" x-model="startDate"
                                class="mt-1 block w-full rounded-2xl border-slate-200 bg-white shadow-sm focus:border-orange-400 focus:ring-orange-400 dark:border-white/10 dark:bg-slate-950/70 dark:text-white" />
                            <x-input-error for="startDate" class="mt-2" />
                        </div>
                        <div>
                            <x-label for="bot-end-date">Fecha fin</x-label>
                            <x-input id="bot-end-date" type="date" x-model="endDate"
                                class="mt-1 block w-full rounded-2xl border-slate-200 bg-white shadow-sm focus:border-orange-400 focus:ring-orange-400 dark:border-white/10 dark:bg-slate-950/70 dark:text-white" />
                            <x-input-error for="endDate" class="mt-2" />
                        </div>
                        <button type="button" wire:click="scrape" wire:loading.attr="disabled" wire:target="scrape"
                            class="inline-flex w-full items-center justify-center rounded-2xl bg-gradient-to-r from-orange-500 to-red-500 px-5 py-3 text-sm font-black text-white shadow-lg shadow-orange-200 transition hover:-translate-y-0.5 hover:from-orange-400 hover:to-red-400 hover:shadow-orange-300 disabled:cursor-wait disabled:opacity-70 dark:shadow-orange-950/30 md:w-auto">
                            <span wire:loading.remove wire:target="scrape">
                                <i class="mr-1 text-xs fas fa-robot"></i> Empezar rango
                            </span>
                            <span wire:loading wire:target="scrape">Empezando...</span>
                        </button>
                    </div>
                </div>

                <div wire:loading wire:target="scrape"
                    class="mb-5 flex items-start gap-3 rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm font-semibold text-blue-800 shadow-lg shadow-blue-100/60 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-100 dark:shadow-black/10">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-700 dark:bg-blue-400/20 dark:text-blue-100">
                        <i class="fas fa-spinner fa-spin text-xs"></i>
                    </span>
                    <span>Buscando convocatorias entre <span x-text="startDate"></span> y <span x-text="endDate"></span>...</span>
                </div>
            </div>
        @else
            <div class="mb-5 flex items-start justify-between gap-4 rounded-[2rem] border border-sky-200 bg-sky-50 p-5 text-sm font-semibold text-sky-800 shadow-lg shadow-sky-100/60 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100 dark:shadow-black/10">
                <div class="flex items-start gap-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-700 dark:bg-sky-400/20 dark:text-sky-100">
                        <i class="fas fa-list-check text-xs"></i>
                    </span>
                    <span>
                        @if ($batchNotFound)
                            Lote SICOES no encontrado.
                        @elseif ($currentSicoesBatch)
                            @switch($currentSicoesBatch->status)
                                @case('queued')
                                    La ejecucion esta en espera.
                                    @break
                                @case('running')
                                    La ejecucion esta procesando documentos.
                                    @break
                                @case('failed')
                                    La ejecucion finalizo con error.
                                    @break
                                @case('partial')
                                    La ejecucion finalizo parcialmente: {{ $currentSicoesBatch->documents_processed }} procesados y {{ $currentSicoesBatch->errors_count }} con error.
                                    @break
                                @case('finished')
                                @case('completed')
                                    {{ $currentSicoesBatch->source_type === \App\Models\SicoesScrapeBatch::SOURCE_PERSONNEL ? 'Requerimientos de personal' : 'Servicios de consultoría' }}
                                    del {{ $currentSicoesBatch->requested_date->format('d/m/Y') }}: {{ $currentSicoesBatch->documents_processed }} procesados, {{ $currentSicoesBatch->previews_count }} previsualizaciones y {{ $currentSicoesBatch->discarded_count }} descartados.
                                    @break
                                @default
                                    Estado del lote: {{ \App\Support\BotUiLabels::processStatus($currentSicoesBatch->status) }}.
                            @endswitch
                        @else
                            Resultados SICOES encontrados. Revisa cada convocatoria en el modal antes de publicarla.
                        @endif
                    </span>
                </div>
                <a href="{{ route('admin.bot.source', $source) }}"
                    class="shrink-0 rounded-xl border border-sky-200 bg-white px-3 py-2 text-xs font-black text-sky-800 transition hover:bg-sky-100 dark:border-sky-400/30 dark:bg-slate-950/40 dark:text-sky-100">
                    Volver a SICOES
                </a>
            </div>
        @endif

        @if ($message)
            <div class="mb-5 flex items-start gap-3 rounded-2xl border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800 shadow-lg shadow-green-100/60 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-100 dark:shadow-black/10">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-700 dark:bg-green-400/20 dark:text-green-100">
                    <i class="fas fa-check text-xs"></i>
                </span>
                <span>{{ $message }}</span>
            </div>
        @endif

        @if ($errorMessage)
            <div class="mb-5 flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800 shadow-lg shadow-red-100/60 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100 dark:shadow-black/10">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700 dark:bg-red-400/20 dark:text-red-100">
                    <i class="fas fa-times text-xs"></i>
                </span>
                <span>{{ $errorMessage }}</span>
            </div>
        @endif

        @if (!empty($lastScrapeSummary))
            <div class="mb-6 rounded-[2rem] border border-slate-200 bg-white p-5 text-sm text-slate-700 shadow-xl shadow-slate-200/70 dark:border-white/10 dark:bg-white/[0.04] dark:text-slate-300 dark:shadow-2xl dark:shadow-black/20 lg:p-6">
                <div class="mb-5 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.2em] text-orange-600 dark:text-orange-300">Extracción</p>
                        <h2 class="mt-1 text-2xl font-black text-slate-950 dark:text-white">Resumen de la extracción</h2>
                    </div>
                    <span class="inline-flex w-fit items-center gap-2 rounded-full bg-sky-50 px-3 py-1.5 text-xs font-black text-sky-800 ring-1 ring-sky-200 dark:bg-sky-400/10 dark:text-sky-100 dark:ring-sky-400/30">
                        <span class="h-2 w-2 rounded-full bg-sky-500"></span>
                        Estado: {{ \App\Support\BotUiLabels::processStatus($lastScrapeSummary['status'] ?? null) }}
                    </span>
                </div>

                <div class="mb-5 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    <div class="rounded-2xl border border-orange-200 bg-orange-50 p-4 dark:border-orange-400/20 dark:bg-orange-400/10">
                        <p class="text-xs font-bold uppercase tracking-wide text-orange-700 dark:text-orange-200">Fuente utilizada</p>
                        @php
                            $summarySource = $source->scraper_type === 'sicoes'
                                ? (($lastScrapeSummary['source_type'] ?? null) === \App\Models\SicoesScrapeBatch::SOURCE_PERSONNEL
                                    ? 'Requerimientos de personal'
                                    : 'Servicios de consultoría')
                                : ($lastScrapeSummary['feed_url'] ?? 'Ninguno');
                        @endphp
                        <p class="mt-2 truncate text-sm font-black text-slate-950 dark:text-white" title="{{ $summarySource }}">{{ $summarySource }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">
                            {{ $source->scraper_type === 'sicoes' ? 'Documentos encontrados' : 'Total encontradas' }}
                        </p>
                        <p class="mt-2 text-3xl font-black text-slate-950 dark:text-white">{{ $lastScrapeSummary['total_items_feed'] ?? 0 }}</p>
                    </div>
                    <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-400/20 dark:bg-blue-400/10">
                        <p class="text-xs font-bold uppercase tracking-wide text-blue-700 dark:text-blue-200">Actualizadas</p>
                        <p class="mt-2 text-3xl font-black text-slate-950 dark:text-white">{{ $lastScrapeSummary['updated'] ?? 0 }}</p>
                    </div>
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-400/20 dark:bg-amber-400/10">
                        <p class="text-xs font-bold uppercase tracking-wide text-amber-700 dark:text-amber-200">Publicadas / duplicadas</p>
                        <p class="mt-2 text-3xl font-black text-slate-950 dark:text-white">{{ $lastScrapeSummary['already_published'] ?? 0 }}</p>
                    </div>
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-400/20 dark:bg-emerald-400/10">
                        <p class="text-xs font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-200">Mostradas en rango</p>
                        <p class="mt-2 text-3xl font-black text-slate-950 dark:text-white">{{ $lastScrapeSummary['shown_in_batch'] ?? 0 }}</p>
                    </div>
                </div>

                @php
                    $summaryMetrics = [
                        'Guardadas nuevas' => $lastScrapeSummary['saved'] ?? 0,
                        'Ya previsualizadas' => $lastScrapeSummary['already_previewed'] ?? 0,
                    ];

                    if ($source->scraper_type === 'sicoes') {
                        $summaryMetrics = array_merge($summaryMetrics, [
                            'Documentos procesados' => $lastScrapeSummary['document_processed'] ?? 0,
                            'Documentos con error' => $lastScrapeSummary['document_errors'] ?? 0,
                            'Descartados' => $lastScrapeSummary['document_discarded'] ?? 0,
                            'Descartados empresa/bienes/obra' => $lastScrapeSummary['discarded_company_or_goods'] ?? 0,
                            'Descartados no consultor persona' => $lastScrapeSummary['discarded_not_individual_consultant'] ?? 0,
                        ]);
                    } else {
                        $summaryMetrics = array_merge($summaryMetrics, [
                            'Pasantia/practica' => $lastScrapeSummary['skipped_internship'] ?? 0,
                            'Claude procesadas' => $lastScrapeSummary['gemini_processed'] ?? 0,
                            'Claude omitidas' => $lastScrapeSummary['gemini_skipped_existing_preview'] ?? 0,
                            'Saltadas por rango' => $lastScrapeSummary['skipped_out_of_range'] ?? 0,
                        ]);
                    }

                    if ($source->scraper_type === 'sicoes') {
                        $summaryMetrics = array_merge($summaryMetrics, [
                            'Claude llamadas' => $lastScrapeSummary['ai_calls'] ?? 0,
                            'Tokens Claude' => $lastScrapeSummary['ai_total_tokens'] ?? 0,
                            'Tokens entrada' => $lastScrapeSummary['ai_prompt_tokens'] ?? 0,
                            'Tokens salida' => $lastScrapeSummary['ai_output_tokens'] ?? 0,
                            'Errores IA' => $lastScrapeSummary['ai_errors'] ?? 0,
                            'Errores' => count($lastScrapeSummary['errors'] ?? []),
                            'Reactivadas' => $lastScrapeSummary['reactivated_deleted'] ?? 0,
                        ]);
                    } else {
                        $summaryMetrics = array_merge($summaryMetrics, [
                            'Llamadas a Claude' => $lastScrapeSummary['gemini_calls'] ?? 0,
                            'Tokens Claude' => $lastScrapeSummary['gemini_total_tokens'] ?? 0,
                            'Tokens entrada' => $lastScrapeSummary['gemini_prompt_tokens'] ?? 0,
                            'Tokens salida' => $lastScrapeSummary['gemini_candidates_tokens'] ?? 0,
                            'Reintentos Claude' => $lastScrapeSummary['gemini_retries'] ?? 0,
                            'Errores' => count($lastScrapeSummary['errors'] ?? []),
                            'Reactivadas' => $lastScrapeSummary['reactivated_deleted'] ?? 0,
                        ]);
                    }
                @endphp

                <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                    @foreach ($summaryMetrics as $label => $value)
                        <div class="rounded-2xl border border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-white/[0.03]">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">{{ $label }}</p>
                            <p class="mt-1 text-xl font-black text-slate-950 dark:text-white">{{ $value }}</p>
                        </div>
                    @endforeach
                    <div class="rounded-2xl border border-purple-200 bg-purple-50 p-3 dark:border-purple-400/20 dark:bg-purple-400/10">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-purple-700 dark:text-purple-200">
                            Claude
                        </p>
                        <p class="mt-1 text-sm font-black text-slate-950 dark:text-white">
                            @if ($source->scraper_type === 'sicoes')
                                {{ (!empty($lastScrapeSummary['ai_enabled']) || (!array_key_exists('ai_enabled', $lastScrapeSummary) && !empty($lastScrapeSummary['ai_calls']))) ? 'Activado' : 'Desactivado' }}
                            @else
                                {{ !empty($lastScrapeSummary['gemini_enabled']) ? 'Activado' : 'Desactivado' }}
                            @endif
                        </p>
                        <p class="text-[11px] text-purple-700/80 dark:text-purple-100/80">
                            {{ $source->scraper_type === 'sicoes' ? ($lastScrapeSummary['ai_model'] ?? 'claude-haiku-4-5-20251001') : ($lastScrapeSummary['gemini_model'] ?? 'claude-haiku-4-5-20251001') }}
                        </p>
                    </div>
                    <div class="rounded-2xl border border-red-200 bg-red-50 p-3 dark:border-red-400/20 dark:bg-red-400/10">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-red-700 dark:text-red-200">
                            Errores Claude
                        </p>
                        <p class="mt-1 text-xl font-black text-slate-950 dark:text-white">
                            {{ $source->scraper_type === 'sicoes' ? ($lastScrapeSummary['ai_errors'] ?? 0) : ($lastScrapeSummary['gemini_errors'] ?? 0) }}
                        </p>
                    </div>
                </div>

                @if ($source->scraper_type === 'sicoes' && !empty($lastScrapeSummary['discarded_by_type']))
                    <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                        <p class="mb-3 font-black text-amber-950 dark:text-white">Documentos descartados por tipo:</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($lastScrapeSummary['discarded_by_type'] as $type => $total)
                                <span class="rounded-full border border-amber-200 bg-white px-3 py-1 font-bold dark:border-amber-500/30 dark:bg-slate-950/40">
                                    {{ \App\Support\BotUiLabels::discardType($type) }}: {{ $total }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($source->scraper_type !== 'sicoes' && !empty($lastScrapeSummary['gemini_errors_by_type']))
                    <div class="mb-4 rounded-2xl border border-sky-200 bg-sky-50 p-4 text-xs text-sky-800 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
                        <p class="mb-3 font-black text-sky-950 dark:text-white">Errores de Claude por tipo en este lote:</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($lastScrapeSummary['gemini_errors_by_type'] as $type => $total)
                                <span class="rounded-full border border-sky-200 bg-white px-3 py-1 font-bold dark:border-sky-500/30 dark:bg-slate-950/40">
                                    {{ \App\Support\BotUiLabels::errorType($type) }}: {{ $total }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($source->scraper_type !== 'sicoes' && $currentBatchId && $currentGeminiErrors > 0)
                    <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-xs dark:border-amber-500/30 dark:bg-amber-500/10">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-semibold text-amber-800 dark:text-amber-100">
                                Hay {{ $currentGeminiErrors }} previsualizacion(es) con clasificación pendiente en este lote.
                            </span>
                            <button type="button" x-on:click="openConfirmation('reanalyzeErrors')"
                                class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-orange-500 to-red-500 px-4 py-2 text-xs font-black text-white shadow-lg shadow-orange-100 transition hover:from-orange-400 hover:to-red-400 dark:shadow-orange-950/30">
                                Reanalizar errores
                            </button>
                        </div>
                    </div>
                @endif

                @if ($source->scraper_type === 'sicoes')
                    <p class="mb-4 text-xs font-medium text-slate-500 dark:text-slate-400">
                        En SICOES se generan una o más previsualizaciones por documento aceptado por Claude. Las profesiones explícitas se conservan aunque pertenezcan a áreas distintas. Solo se separan previsualizaciones cuando el documento contiene cargos realmente diferentes y Claude relaciona sus profesiones. Los documentos descartados por empresa, bienes, obra o falta de evidencia no quedan listos para publicar.
                    </p>
                @else
                    <p class="mb-4 text-xs font-medium text-slate-500 dark:text-slate-400">
                        Las convocatorias saltadas por rango son publicaciones encontradas en el RSS, pero su fecha de publicacion no esta dentro del rango seleccionado.
                    </p>
                @endif

                @if (!empty($lastScrapeSummary['already_published']))
                    <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-xs font-semibold text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                        Estas convocatorias ya fueron publicadas anteriormente y no se vuelven a mostrar para evitar duplicados.
                    </div>
                @endif

                @if (!empty($lastScrapeSummary['reactivated_deleted']))
                    <div class="mb-4 rounded-2xl border border-blue-200 bg-blue-50 p-4 text-xs font-semibold text-blue-800 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-100">
                        Algunas convocatorias habian sido publicadas antes, pero ya no existen en el listado de convocatorias. Se reactivaron para este lote.
                    </div>
                @endif

                @if ($source->scraper_type === 'sicoes' && !empty($lastScrapeSummary['document_discarded']))
                    <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-xs font-semibold text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                        Claude descarto {{ $lastScrapeSummary['document_discarded'] }} documento(s). No se crearon previsualizaciones publicables para esos documentos.
                    </div>
                @endif

                @if (($source->scraper_type === 'sicoes' && !empty($lastScrapeSummary['ai_errors'])) || ($source->scraper_type !== 'sicoes' && !empty($lastScrapeSummary['gemini_errors'])))
                    <div class="mb-4 rounded-2xl border border-red-200 bg-red-50 p-4 text-xs font-semibold text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100">
                        @if ($source->scraper_type === 'sicoes')
                            Claude fallo en {{ $lastScrapeSummary['ai_errors'] }} documento(s). Esas previsualizaciones quedaron con error para revision manual y el lote continuo.
                        @else
                            Claude falló en {{ $lastScrapeSummary['gemini_errors'] }} convocatoria(s), se usaron valores por defecto.
                        @endif
                    </div>
                @endif

                @if (!empty($lastScrapeSummary['skipped_out_of_range_details']))
                    <details class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 text-xs shadow-sm transition hover:border-orange-200 dark:border-white/10 dark:bg-slate-950/50 dark:hover:border-orange-400/30">
                        <summary class="cursor-pointer font-black text-slate-950 dark:text-white">
                            Ver detalles de saltadas por rango
                        </summary>
                        <div class="mt-3 overflow-x-auto">
                            <table class="min-w-full overflow-hidden rounded-xl text-xs">
                                <thead class="bg-slate-100 text-slate-600 dark:bg-slate-900 dark:text-slate-300">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Titulo</th>
                                        <th class="px-3 py-2 text-left">Fecha original</th>
                                        <th class="px-3 py-2 text-left">Motivo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (array_slice($lastScrapeSummary['skipped_out_of_range_details'], 0, 20) as $detail)
                                        <tr class="border-t border-slate-200 dark:border-white/10">
                                            <td class="px-3 py-2">{{ $detail['title'] ?? '-' }}</td>
                                            <td class="px-3 py-2">{{ $detail['pubDate_original'] ?? '-' }}</td>
                                            <td class="px-3 py-2">{{ $detail['reason'] ?? 'fuera del rango' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endif

                @if (!empty($lastScrapeSummary['feeds_tested']))
                    <div class="mb-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-950/50">
                        <table class="min-w-full text-xs">
                            <thead class="bg-slate-100 text-slate-600 dark:bg-slate-900 dark:text-slate-300">
                                <tr>
                                    <th class="px-3 py-3 text-left">Fuente probada</th>
                                    <th class="px-3 py-3 text-left">HTTP</th>
                                    <th class="px-3 py-3 text-left">XML</th>
                                    <th class="px-3 py-3 text-left">Elementos</th>
                                    <th class="px-3 py-3 text-left">Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($lastScrapeSummary['feeds_tested'] as $feed)
                                    <tr class="border-t border-slate-200 dark:border-white/10">
                                        <td class="px-3 py-3 break-all">{{ $feed['url'] ?? '' }}</td>
                                        <td class="px-3 py-3">{{ $feed['status_code'] ?? '-' }}</td>
                                        <td class="px-3 py-3">{{ !empty($feed['parsed_xml']) ? 'Si' : 'No' }}</td>
                                        <td class="px-3 py-3">{{ $feed['items_count'] ?? 0 }}</td>
                                        <td class="px-3 py-3">{{ $feed['error'] ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if (!empty($lastScrapeSummary['errors']))
                    <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-xs text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100">
                        <p class="mb-1 font-semibold">Errores:</p>
                        <ul class="space-y-1 list-disc list-inside">
                            @foreach (array_slice($lastScrapeSummary['errors'], 0, 8) as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($previews as $preview)
                @php
                    $previewIssues = \App\Support\BotUiLabels::previewIssues($preview->raw_data);
                @endphp
                <article
                    class="group flex min-h-[390px] flex-col justify-between rounded-[2rem] border border-slate-200 bg-white p-5 shadow-xl shadow-slate-200/70 transition duration-200 hover:-translate-y-1 hover:border-orange-300 hover:shadow-orange-100 dark:border-white/10 dark:bg-slate-900/80 dark:shadow-black/20 dark:hover:border-orange-400/40 dark:hover:shadow-orange-950/30">
                    <div>
                        <div class="mb-5 flex items-start justify-between gap-4">
                            <div class="flex items-start gap-3">
                            @if ($company->hasLogoFile())
                                <img src="{{ $company->logoUrl() }}" alt="{{ $company->name }}"
                                    class="h-14 w-14 rounded-2xl border border-slate-200 bg-white object-cover shadow-inner dark:border-white/10">
                            @else
                                <div
                                    class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-red-500 text-xl font-black text-white shadow-lg shadow-orange-200 dark:shadow-orange-950/30">
                                    {{ \Illuminate\Support\Str::substr($company->name, 0, 1) }}
                                </div>
                            @endif
                            <div class="min-w-0">
                                <p class="text-xs font-black uppercase tracking-wide text-tbn-primary">{{ $company->name }}</p>
                                <p class="mt-1 text-xs font-semibold text-slate-500 dark:text-slate-400">
                                    <i class="mr-1 text-orange-500 fas fa-map-marker-alt"></i>
                                    {{ $preview->location ?: $preview->department ?: 'No especificado' }}
                                </p>
                            </div>
                            </div>
                            <span class="shrink-0 rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-400/10 dark:text-emerald-200 dark:ring-emerald-400/30">
                                Activa
                            </span>
                        </div>

                        <h2 class="mb-3 text-xl font-black leading-6 text-slate-950 dark:text-white">
                            {{ $preview->title }}
                        </h2>

                        <p class="mb-4 line-clamp-4 text-sm font-medium leading-6 text-slate-600 dark:text-slate-300">
                            {{ \Illuminate\Support\Str::limit(strip_tags($preview->original_description ?: 'Sin descripcion original.'), 240) }}
                        </p>

                        @if ($preview->status === 'error')
                            <div class="mb-4 rounded-2xl border border-red-200 bg-red-50 p-3 text-xs text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100">
                                <p class="font-black uppercase tracking-wide">Motivo del error</p>
                                <ul class="mt-2 list-inside list-disc space-y-1">
                                    @forelse ($previewIssues as $issue)
                                        <li>{{ $issue }}</li>
                                    @empty
                                        <li>No se registró un motivo detallado. Abre "Ver datos" para revisar los campos faltantes.</li>
                                    @endforelse
                                </ul>
                            </div>
                        @endif

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="rounded-2xl border border-orange-200 bg-orange-50 p-3 dark:border-orange-400/20 dark:bg-orange-400/10">
                                <p class="text-[11px] font-bold uppercase tracking-wide text-orange-700 dark:text-orange-200">
                                    <i class="mr-1 fas fa-money-bill-wave"></i>
                                    Sueldo
                                </p>
                                <p class="mt-1 text-sm font-black text-slate-950 dark:text-white">
                                    {{ $preview->salary ?: 'Sueldo no declarado' }}
                                </p>
                            </div>
                            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-400/20 dark:bg-amber-400/10">
                                <p class="text-[11px] font-bold uppercase tracking-wide text-amber-700 dark:text-amber-200">
                                    <i class="mr-1 fas fa-clock"></i>
                                    Expira
                                </p>
                                <p class="mt-1 text-sm font-black text-slate-950 dark:text-white">
                                    {{ $preview->expiration_date ?: 'No especificado' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 border-t border-slate-200 pt-4 dark:border-white/10">
                        @php
                            $statusClasses = [
                                'preview' => 'bg-yellow-100 text-yellow-800 ring-yellow-200 dark:bg-yellow-400/10 dark:text-yellow-100 dark:ring-yellow-400/30',
                                'edited' => 'bg-blue-100 text-blue-800 ring-blue-200 dark:bg-blue-400/10 dark:text-blue-100 dark:ring-blue-400/30',
                                'published' => 'bg-green-100 text-green-800 ring-green-200 dark:bg-green-400/10 dark:text-green-100 dark:ring-green-400/30',
                                'error' => 'bg-red-100 text-red-800 ring-red-200 dark:bg-red-400/10 dark:text-red-100 dark:ring-red-400/30',
                            ][$preview->status] ?? 'bg-gray-100 text-gray-800 ring-gray-200 dark:bg-gray-400/10 dark:text-gray-100 dark:ring-gray-400/30';
                        @endphp
                        <div class="mb-3 flex items-center justify-between gap-3">
                        <span
                            class="inline-flex items-center rounded-full px-3 py-1 text-xs font-black uppercase tracking-wide ring-1 {{ $statusClasses }}">
                            {{ $previewStatusLabels[$preview->status] ?? \App\Support\BotUiLabels::previewStatus($preview->status) }}
                        </span>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <a href="{{ $preview->source_url }}" target="_blank" rel="noopener"
                                class="inline-flex items-center justify-center rounded-2xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs font-black text-amber-700 transition hover:border-amber-300 hover:bg-amber-100 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-100 dark:hover:bg-amber-400/20">
                                <i class="mr-2 fas fa-external-link-alt"></i>
                                {{ $source->scraper_type === 'sicoes' ? 'Fuente SICOES' : 'Ver fuente original' }}
                            </a>
                            <button type="button" wire:click="edit({{ $preview->id }})"
                                class="inline-flex items-center justify-center rounded-2xl bg-gradient-to-r from-orange-500 to-red-500 px-3 py-2.5 text-xs font-black text-white shadow-lg shadow-orange-200 transition hover:from-orange-400 hover:to-red-400 hover:shadow-orange-300 dark:shadow-orange-950/30">
                                Ver datos
                            </button>
                            @if (in_array($source->scraper_type, ['evaluar', 'etalent'], true) && $preview->status !== 'published')
                                <button type="button"
                                    x-on:click="openConfirmation('reanalyzeSelected', {{ $preview->id }})"
                                    class="inline-flex items-center justify-center rounded-2xl border border-sky-200 bg-sky-50 px-3 py-2.5 text-xs font-black text-sky-700 transition hover:bg-sky-100 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-100">
                                    Reanalizar
                                </button>
                            @endif
                            @if (in_array((int) $preview->id, $forcePublishablePreviewIds, true))
                                <button type="button"
                                    x-on:click="openConfirmation('publishAnyway', {{ $preview->id }})"
                                    class="inline-flex items-center justify-center rounded-2xl border border-violet-200 bg-violet-50 px-3 py-2.5 text-xs font-black text-violet-700 transition hover:bg-violet-100 dark:border-violet-400/30 dark:bg-violet-500/10 dark:text-violet-100">
                                    Publicar de todas formas
                                </button>
                            @endif
                            <button type="button"
                                x-on:click="openConfirmation('removeFromBatch', {{ $preview->id }})"
                                class="inline-flex items-center justify-center rounded-2xl border border-red-200 bg-red-50 px-3 py-2.5 text-xs font-black text-red-700 transition hover:border-red-300 hover:bg-red-100 dark:border-red-400/30 dark:bg-red-500/10 dark:text-red-100 dark:hover:bg-red-500/20"
                                title="Quitar">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-[2rem] border border-dashed border-slate-300 bg-white py-12 text-center shadow-xl shadow-slate-200/70 md:col-span-2 xl:col-span-3 dark:border-white/15 dark:bg-white/[0.04] dark:shadow-black/20">
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-300">
                        @if ($source->scraper_type === 'sicoes')
                            @if ($batchNotFound)
                                Lote SICOES no encontrado.
                            @elseif ($currentSicoesBatch)
                                @switch($currentSicoesBatch->status)
                                    @case('queued')
                                        La ejecucion esta en espera.
                                        @break
                                    @case('running')
                                        La ejecucion esta procesando documentos.
                                        @break
                                    @case('failed')
                                        La ejecucion finalizo con error.
                                        @break
                                    @case('partial')
                                        El procesamiento fue parcial: {{ $currentSicoesBatch->documents_processed }} documentos procesados y {{ $currentSicoesBatch->errors_count }} con error. Revisa el lote antes de publicar.
                                        @break
                                    @case('finished')
                                    @case('completed')
                                        Se procesaron {{ $currentSicoesBatch->documents_processed }} documentos. {{ $currentSicoesBatch->discarded_count }} fueron descartados. No existen convocatorias publicables en este lote.
                                        @break
                                    @default
                                        Estado del lote: {{ \App\Support\BotUiLabels::processStatus($currentSicoesBatch->status) }}.
                                @endswitch
                            @else
                                No hay un lote SICOES persistido para mostrar.
                            @endif
                        @else
                            Esta pantalla solo muestra el lote actual. Presiona "Empezar rango" para cargar convocatorias nuevas.
                        @endif
                    </p>
                </div>
            @endforelse
        </div>

        @if ($previews->hasPages())
            <div class="mt-6">
                {{ $previews->links() }}
            </div>
        @endif

        @if ($publishablePreviewCount > 0)
            <button type="button" x-on:click="openConfirmation('publishAll')"
                class="fixed bottom-8 right-8 z-20 flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-r from-orange-500 to-red-500 text-2xl font-black text-white shadow-2xl shadow-orange-300 transition hover:-translate-y-1 hover:from-orange-400 hover:to-red-400 dark:shadow-orange-950/40"
                title="Subir todas">
                &uarr;
            </button>
        @endif

        <dialog x-ref="confirmationDialog" x-on:cancel.prevent="closeConfirmation"
            x-on:close="resetConfirmation"
            class="w-[min(92vw,32rem)] rounded-3xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/70 dark:border-white/10 dark:bg-slate-900 dark:text-white">
            <div class="p-6 sm:p-7">
                <div class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-200">
                        <i class="fas fa-question"></i>
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-xl font-black" x-text="confirmation.title"></h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300"
                            x-text="confirmation.message"></p>
                    </div>
                </div>
                <div class="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button type="button" x-on:click="closeConfirmation"
                        class="rounded-2xl border border-slate-300 px-5 py-2.5 text-sm font-black text-slate-700 transition hover:bg-slate-100 dark:border-white/15 dark:text-slate-100 dark:hover:bg-white/10">
                        Cancelar
                    </button>
                    <button type="button" x-on:click="confirmAction"
                        class="rounded-2xl bg-gradient-to-r from-orange-500 to-red-500 px-5 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-200 transition hover:from-orange-400 hover:to-red-400 dark:shadow-orange-950/30"
                        x-text="confirmation.confirmLabel"></button>
                </div>
            </div>
        </dialog>

        @if ($showModal)
            <div class="fixed inset-0 z-30 overflow-y-auto" x-cloak>
                <div class="min-h-screen px-4 py-8">
                    <div class="fixed inset-0 bg-black opacity-50" wire:click="closeModal"></div>
                    <form wire:submit="saveEdit"
                        class="relative z-40 w-full max-w-6xl p-6 mx-auto bg-white rounded-lg shadow-xl tbn-form dark:bg-tbn-dark"
                        x-data="botEditForm({
                            companies: @js($companies),
                            areas: @js($areas),
                            profesions: @js($profesions),
                            locations: @js($locations),
                            allowMultiArea: @js($source->scraper_type === 'sicoes'),
                        })"
                        x-on:bot-professions-recalculated.window="syncRecalculatedProfessions($event.detail.ids, $event.detail.areaId)">
                        <div class="flex items-start justify-between gap-4 mb-5">
                            <div>
                                <h3 class="text-xl font-bold text-tbn-primary">Editar previsualizacion BOT</h3>
                                <p class="text-sm text-tbn-secondary dark:text-tbn-light">
                                    Este formulario usa el flujo visual de nueva convocatoria, pero solo guarda la previsualización.
                                </p>
                            </div>
                            <button type="button" wire:click="closeModal"
                                class="text-xl text-tbn-secondary hover:text-tbn-primary">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>

                        @php
                            $editingIssues = $editingPreview
                                ? \App\Support\BotUiLabels::previewIssues($editingPreview->raw_data)
                                : [];
                        @endphp
                        @if ($editingPreview?->status === 'error')
                            <div class="mb-4 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100">
                                <p class="font-black">Por qué requiere revisión</p>
                                <ul class="mt-2 list-inside list-disc space-y-1">
                                    @forelse ($editingIssues as $issue)
                                        <li>{{ $issue }}</li>
                                    @empty
                                        <li>Faltan datos obligatorios para dejar esta previsualización lista para publicar.</li>
                                    @endforelse
                                </ul>
                            </div>
                        @endif

                        <div class="mb-4">
                            <x-label for="bot_announce_title">Titulo de la convocatoria</x-label>
                            <x-textarea class="w-full" wire:model="form.title" rows="3" id="bot_announce_title" />
                            <x-input-error for="form.title" class="mt-2" />
                        </div>

                        <div class="mb-4">
                            <x-label for="bot_company_select">Empresa</x-label>
                            <div class="mt-1 tbn-tom-select" wire:ignore>
                                <x-select class="dark:bg-tbn-dark dark:text-white" id="bot_company_select">
                                    <option></option>
                                    @foreach ($companies as $companyOption)
                                        <option value="{{ $companyOption->id }}">{{ $companyOption->company_name }}</option>
                                    @endforeach
                                </x-select>
                            </div>
                            <x-input-error for="form.selected_company_id" class="mt-2" />
                        </div>

                        <div class="mb-4">
                            <x-label for="bot_area_select"><span class="font-bold">Área profesional</span> (filtra el catálogo)</x-label>
                            <div class="mt-1 tbn-tom-select" wire:ignore>
                                <x-select id="bot_area_select">
                                    <option></option>
                                    @foreach ($areas as $area)
                                        <option value="{{ $area->id }}">{{ $area->area_name }}</option>
                                    @endforeach
                                </x-select>
                            </div>
                            <x-input-error for="form.selected_area_id" class="mt-2" />
                        </div>

                        @if ($editingPreview && in_array($source->scraper_type, ['evaluar', 'etalent', 'sicoes'], true))
                            @php
                                $resolution = data_get($editingPreview->raw_data, 'profession_resolution', []);
                                $resolvedProfessions = data_get($resolution, 'profesiones_resueltas', []);
                                $unidentifiedProfessions = data_get($resolution, 'profesiones_no_identificadas', []);
                                $ambiguousProfessions = data_get($resolution, 'profesiones_ambiguas', []);
                                $expandedArea = data_get($resolution, 'area_expandida_por_afinidad');
                                $expandedAreas = data_get($resolution, 'areas_expandidas_por_afinidad', []);
                                if ($expandedAreas === [] && $expandedArea) {
                                    $expandedAreas = [$expandedArea];
                                }
                                $excludedAffinityAreas = data_get($resolution, 'areas_omitidas_por_afinidad', []);
                                $aiPrimaryArea = data_get($resolution, 'area_principal_ia');
                                $reviewReasons = \App\Support\BotUiLabels::previewIssues($editingPreview->raw_data);
                            @endphp
                            <div class="mb-4 space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-xs dark:border-white/10 dark:bg-white/[0.03]">
                                <p class="font-black uppercase tracking-wide text-slate-700 dark:text-slate-200">
                                    Clasificación automática trazable
                                </p>
                                @if ($aiPrimaryArea)
                                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-emerald-800">
                                        <strong>Área principal elegida por IA:</strong>
                                        {{ $aiPrimaryArea['area_name'] ?? '-' }}
                                        ({{ number_format((float) ($aiPrimaryArea['confianza'] ?? 0) * 100, 0) }}%)
                                        <p class="mt-1"><strong>Evidencia:</strong> {{ $aiPrimaryArea['evidencia'] ?? '-' }}</p>
                                    </div>
                                @endif
                                @forelse ($resolvedProfessions as $resolved)
                                    @php
                                        $matchType = $resolved['tipo_coincidencia'] ?? 'automática';
                                        $matchClass = str_contains($matchType, 'expansion_area_afin')
                                            ? 'bg-sky-100 text-sky-800'
                                            : (str_contains($matchType, 'alias')
                                            ? 'bg-violet-100 text-violet-800'
                                            : (str_contains($matchType, 'aproximada')
                                                ? 'bg-amber-100 text-amber-800'
                                                : 'bg-emerald-100 text-emerald-800'));
                                    @endphp
                                    <div class="rounded-xl border border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-slate-900">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <strong>{{ $resolved['nombre_original'] ?? '-' }}</strong>
                                            <span>→ {{ $resolved['profesion_name'] ?? '-' }}</span>
                                            <span class="rounded-full px-2 py-0.5 font-bold {{ $matchClass }}">{{ \App\Support\BotUiLabels::professionMatchType($matchType) }}</span>
                                            <span>IA {{ number_format((float) ($resolved['confianza_ia'] ?? 0) * 100, 0) }}%</span>
                                            <span>catálogo {{ number_format((float) ($resolved['confianza_coincidencia'] ?? 0) * 100, 0) }}%</span>
                                        </div>
                                        <p class="mt-2 text-slate-600 dark:text-slate-300">
                                            <strong>Evidencia:</strong> {{ $resolved['evidencia'] ?? '-' }}
                                        </p>
                                        <p class="mt-1 text-slate-500">
                                            <strong>Área(s):</strong>
                                            {{ collect($resolved['areas'] ?? [])->pluck('area_name')->implode(', ') ?: 'Sin relación' }}
                                        </p>
                                    </div>
                                @empty
                                    <p class="text-slate-500">No hay profesiones resueltas automáticamente.</p>
                                @endforelse

                                @if ($expandedAreas)
                                    <div class="rounded-xl border border-sky-200 bg-sky-50 p-3 text-sky-800">
                                        <strong>Ramas afines:</strong>
                                        se agregó el catálogo completo de
                                        {{ collect($expandedAreas)->pluck('area_name')->filter()->implode(', ') }}.
                                        <p class="mt-1">
                                            <strong>Evidencia:</strong>
                                            {{ collect($expandedAreas)->pluck('evidencia')->filter()->unique()->implode(' ') ?: '-' }}
                                        </p>
                                    </div>
                                @endif

                                @if ($excludedAffinityAreas)
                                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-800">
                                        <strong>Ramas afines sin expansión automática:</strong>
                                        {{ collect($excludedAffinityAreas)->pluck('area_name')->filter()->implode(', ') }}.
                                        Se conservaron las profesiones mencionadas explícitamente, sin agregar todo el catálogo de estas áreas.
                                    </div>
                                @endif

                                @foreach ($unidentifiedProfessions as $item)
                                    <div class="rounded-xl border border-red-200 bg-red-50 p-3 text-red-800">
                                        <strong>No identificada:</strong> {{ $item['nombre_original'] ?? '-' }}
                                        <p class="mt-1"><strong>Evidencia:</strong> {{ $item['evidencia'] ?? '-' }}</p>
                                    </div>
                                @endforeach
                                @foreach ($ambiguousProfessions as $item)
                                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-800">
                                        <strong>Ambigua:</strong> {{ $item['nombre_original'] ?? '-' }}
                                        <p class="mt-1">{{ $item['motivo'] ?? 'Requiere revisión manual.' }}</p>
                                    </div>
                                @endforeach
                                @if ($reviewReasons)
                                    <ul class="list-inside list-disc text-amber-800 dark:text-amber-200">
                                        @foreach ($reviewReasons as $reason)
                                            <li>{{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endif

                        <div class="mb-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <x-label for="bot_profesions_select">Profesiones
                                    <button x-on:click="clearProfesionsSelected" type="button"
                                        class="inline-block ml-2 text-sm underline text-tbn-primary">
                                        Limpiar
                                    </button>
                                </x-label>
                                <button type="button" wire:click="recalculateSuggestedProfessions"
                                    wire:loading.attr="disabled" wire:target="recalculateSuggestedProfessions"
                                    class="text-sm underline text-tbn-primary">
                                    <span wire:loading.remove wire:target="recalculateSuggestedProfessions">
                                        Recalcular desde detecciones
                                    </span>
                                    <span wire:loading wire:target="recalculateSuggestedProfessions">
                                        Recalculando...
                                    </span>
                                </button>
                            </div>
                            @if ($professionSuggestionNotice)
                                <div class="p-3 mt-2 text-xs border rounded-md text-amber-800 border-amber-200 bg-amber-50 dark:bg-amber-950/40 dark:text-amber-200 dark:border-amber-800">
                                    {{ $professionSuggestionNotice }}
                                </div>
                            @endif
                            <div x-show="areaWarning" x-text="areaWarning"
                                class="mt-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800"
                                x-cloak></div>
                            <div class="mt-1 tbn-tom-select" wire:ignore>
                                <x-select id="bot_profesions_select" multiple>
                                    @foreach ($profesions as $profesion)
                                        <option value="{{ $profesion['id'] }}">{{ $profesion['profesion_name'] }}</option>
                                    @endforeach
                                </x-select>
                            </div>
                            <x-input-error for="form.selected_profession_ids" class="mt-2" />
                        </div>

                        <div class="mb-4">
                            <x-label for="bot_locations_select">Ubicaciones
                                <button x-on:click="setAllLocations" type="button"
                                    class="inline-block ml-2 text-sm underline text-tbn-primary">
                                    Anadir toda Bolivia
                                </button>
                            </x-label>
                            <div class="mt-1 tbn-tom-select" wire:ignore>
                                <x-select id="bot_locations_select" multiple>
                                    @foreach ($locations as $location)
                                        <option value="{{ $location->id }}">{{ $location->location_name }}</option>
                                    @endforeach
                                </x-select>
                            </div>
                            <x-input-error for="form.selected_location_ids" class="mt-2" />
                        </div>

                        <div class="flex-grow mb-4">
                            <x-label for="bot_files">Archivos de la convocatoria</x-label>
                            @if (!empty($existingBotFiles))
                                <div class="mt-2 space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm dark:border-white/10 dark:bg-white/[0.03]">
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">
                                        Archivos ya asociados
                                    </p>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($existingBotFiles as $attachment)
                                            @php
                                                $url = $attachment['url'] ?? null;
                                                $name = $attachment['original_name'] ?? ($url ? basename($url) : 'Archivo SICOES');
                                                $extension = strtolower(pathinfo((string) $url, PATHINFO_EXTENSION));
                                                $icons = [
                                                    'png' => 'fas fa-file-image',
                                                    'jpg' => 'fas fa-file-image',
                                                    'jpeg' => 'fas fa-file-image',
                                                    'pdf' => 'fas fa-file-pdf',
                                                    'docx' => 'fas fa-file-word',
                                                    'doc' => 'fas fa-file-word',
                                                    'xls' => 'fas fa-file-excel',
                                                    'xlsx' => 'fas fa-file-excel',
                                                    'xlsm' => 'fas fa-file-excel',
                                                    'csv' => 'fas fa-file-excel',
                                                ];
                                                $icon = $icons[$extension] ?? 'fas fa-file';
                                            @endphp
                                            @if ($url)
                                                <a href="{{ asset('storage/' . $url) }}" target="_blank"
                                                    class="inline-flex max-w-full items-center gap-2 rounded-lg border border-tbn-primary/30 bg-white px-3 py-2 text-xs font-bold text-tbn-primary transition hover:border-tbn-primary hover:bg-orange-50 dark:bg-slate-950/50 dark:text-tbn-light"
                                                    download="{{ basename($name) }}">
                                                    <i class="{{ $icon }}"></i>
                                                    <span class="truncate">{{ $name }}</span>
                                                </a>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            <x-filepond wire:model="botFiles" multiple />
                            <x-input-error for="botFiles.*" class="mt-2" />
                        </div>

                        <div class="flex-grow block gap-2 mb-4 sm:flex">
                            <div class="w-full sm:w-1/2">
                                <x-label for="bot_expiration_time">Expiracion</x-label>
                                <x-input type="text" wire:model="form.expiration_date" id="bot_expiration_time"
                                    class="block w-full mt-1" placeholder="Definir fecha" />
                                <x-input-error for="form.expiration_date" class="mt-2" />
                            </div>
                            <div class="w-full sm:w-1/2">
                                <x-label for="bot_salary">Sueldo</x-label>
                                <x-input wire:model="form.salary" id="bot_salary" type="text"
                                    x-mask:dynamic="$money($input.replace(/[^\d]/g), ',', '.')" class="block w-full mt-1" />
                                @if ($source->scraper_type === 'sicoes')
                                    <span class="block mt-2 text-xs text-tbn-dark dark:text-tbn-light">
                                        "0" = Sueldo no declarado por la institución.</span>
                                    <span class="block text-xs text-tbn-dark dark:text-tbn-light">
                                        "1" = Existen varios sueldos detallados en la descripción.</span>
                                    <span class="block text-xs text-tbn-dark dark:text-tbn-light">
                                        Cualquier otro entero = Existe un único sueldo.</span>
                                @else
                                    <span class="block mt-2 text-xs text-tbn-dark dark:text-tbn-light">
                                        "0" = Sueldo no declarado por la institucion.</span>
                                    <span class="block text-xs text-tbn-dark dark:text-tbn-light">
                                        "1" = Los sueldos estan detallados en la descripcion.</span>
                                @endif
                                <x-input-error for="form.salary" class="mt-2" />
                            </div>
                        </div>

                        <div class="mb-4">
                            <x-label for="bot_description" class="mb-2">Descripcion / Detalles de la convocatoria</x-label>
                            <div class="tbn-quill-editor" wire:ignore>
                                <div class="block w-full" id="bot_description"></div>
                            </div>
                            <x-input-error for="form.description" class="mt-2" />
                        </div>

                        <div class="mb-4">
                            <x-label for="bot_source">Fuente</x-label>
                            <x-input id="bot_source" wire:model="form.source_url" disabled />
                        </div>

                        <div class="mb-4">
                            <x-input-checkbox-block wire:model.live="form.is_pro">
                                <div class="divide-y ms-6 divide-tbn-secondary">
                                    <div class="w-full mb-2">
                                        <p class="font-medium text-tbn-dark text-md dark:text-tbn-primary">Convocatoria PRO</p>
                                        <p class="text-xs text-tbn-secondary dark:text-tbn-light">
                                            Esta marca se guardará en la previsualización y se aplicará recién al publicar el lote.
                                        </p>
                                    </div>
                                </div>
                            </x-input-checkbox-block>
                            <x-input-error for="form.is_pro" class="mt-2" />
                        </div>

                        <div class="flex gap-2">
                            <x-button wire:loading.attr="disabled" wire:target="saveEdit" type="submit">
                                <span wire:loading.remove wire:target="saveEdit">Actualizar</span>
                                <span wire:loading wire:target="saveEdit">Actualizando...</span>
                            </x-button>
                            <x-secondary-button type="button" wire:click="closeModal">Cancelar</x-secondary-button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
        @endif
    </div>

    @script
        <script>
            Alpine.data('botPreview', () => ({
                confirmation: {
                    action: null,
                    id: null,
                    title: '',
                    message: '',
                    confirmLabel: 'Confirmar',
                },
                openConfirmation(action, id = null) {
                    const configurations = {
                        publishAll: {
                            title: 'Publicar todas las previsualizaciones',
                            message: `Se publicaran @js($publishablePreviewCount) previsualizaciones listas del lote actual (estados revisado o editado). Las que tengan error quedan excluidas.`,
                            confirmLabel: 'Publicar todas',
                        },
                        publishAnyway: {
                            title: 'Publicar de todas formas',
                            message: '¿Seguro que desea publicar esta convocatoria de todas formas?',
                            confirmLabel: 'Publicar',
                        },
                        removeFromBatch: {
                            title: 'Quitar del lote',
                            message: '¿Seguro que desea quitar esta convocatoria del lote actual?',
                            confirmLabel: 'Quitar',
                        },
                        reanalyzeSelected: {
                            title: 'Reanalizar previsualización',
                            message: 'La previsualización se enviará nuevamente a Claude. Los cambios manuales se conservarán.',
                            confirmLabel: 'Reanalizar',
                        },
                        reanalyzeErrors: {
                            title: 'Reanalizar errores',
                            message: `Se enviarán a Claude ${@js($currentGeminiErrors)} previsualizaciones con error y sin cambios manuales.`,
                            confirmLabel: 'Reanalizar',
                        },
                    };
                    const configuration = configurations[action];

                    if (!configuration) {
                        return;
                    }

                    this.confirmation = { action, id, ...configuration };
                    this.$refs.confirmationDialog.showModal();
                },
                closeConfirmation() {
                    this.$refs.confirmationDialog?.close();
                },
                resetConfirmation() {
                    this.confirmation = {
                        action: null,
                        id: null,
                        title: '',
                        message: '',
                        confirmLabel: 'Confirmar',
                    };
                },
                confirmAction() {
                    const action = this.confirmation.action;
                    const id = this.confirmation.id;
                    this.closeConfirmation();

                    if (action === 'publishAll') {
                        $wire.publishAll();
                    } else if (action === 'publishAnyway') {
                        $wire.publishAnyway(id);
                    } else if (action === 'removeFromBatch') {
                        $wire.removeFromBatch(id);
                    } else if (action === 'reanalyzeSelected') {
                        $wire.reanalyzeSelected(id);
                    } else if (action === 'reanalyzeErrors') {
                        $wire.reanalyzeErrors();
                    }
                }
            }))

            Alpine.data('botEditForm', ({ profesions, locations, allowMultiArea = false }) => ({
                tsArea: null,
                tsCompany: null,
                tsProfesions: null,
                tsLocations: null,
                quill: null,
                initializing: true,
                currentAreaId: null,
                areaWarning: '',
                allowMultiArea,
                profesions,
                locations,
                init() {
                    this.$nextTick(() => {
                        this.tsCompany = new TomSelect('#bot_company_select', {
                            plugins: ['remove_button'],
                            onChange: value => $wire.form.selected_company_id = value ? Number(value) : null,
                        });
                        this.tsArea = new TomSelect('#bot_area_select', {
                            onChange: value => {
                                if (this.initializing) {
                                    return;
                                }

                                this.onAreaChange(value);
                            },
                        });
                        this.tsProfesions = new TomSelect('#bot_profesions_select', {
                            plugins: ['remove_button'],
                            onChange: values => $wire.form.selected_profession_ids = values.map(Number),
                        });
                        this.tsLocations = new TomSelect('#bot_locations_select', {
                            plugins: ['remove_button'],
                            onChange: values => $wire.form.selected_location_ids = values.map(Number),
                        });

                        this.quill = new Quill('#bot_description', {
                            theme: 'snow',
                            modules: {
                                toolbar: [
                                    [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
                                    ['bold', 'italic', 'underline', 'strike', 'link']
                                ]
                            }
                        });

                        this.quill.root.innerHTML = $wire.form.description || '';
                        this.quill.on('text-change', (range, oldRange, source) => {
                            if (source === 'user') {
                                $wire.form.description = this.quill.root.innerHTML;
                            }
                        });

                        const selectedProfessionIds = Array.from(
                            $wire.form.selected_profession_ids || []
                        ).map(Number);

                        this.tsCompany.setValue($wire.form.selected_company_id, true);
                        this.tsProfesions.setValue(selectedProfessionIds.map(String), true);
                        this.tsLocations.setValue($wire.form.selected_location_ids || [], true);

                        if ($wire.form.selected_area_id) {
                            this.tsArea.setValue($wire.form.selected_area_id, true);
                            this.currentAreaId = Number($wire.form.selected_area_id);
                            this.allowMultiArea
                                ? this.replaceProfessionOptions(this.profesions, selectedProfessionIds)
                                : this.filterProfessionOptions(
                                    this.currentAreaId,
                                    true,
                                    selectedProfessionIds
                                );
                        }

                        this.initializing = false;

                        const expirationDate = $wire.form.expiration_date
                            && $wire.form.expiration_date !== 'No especificado'
                            ? $wire.form.expiration_date
                            : 'today';

                        flatpickr("#bot_expiration_time", {
                            defaultDate: expirationDate,
                            enableTime: true,
                            minDate: "today",
                            time_24hr: false,
                            dateFormat: "Y-m-d H:i",
                            locale: "es",
                            onChange: (selectedDates, dateStr) => {
                                $wire.set('form.expiration_date', dateStr);
                            }
                        });
                    });
                },
                async onAreaChange(areaId) {
                    const areaSelected = Number(areaId);

                    if (!areaSelected) {
                        this.currentAreaId = null;
                        this.areaWarning = '';
                        $wire.form.selected_area_id = null;
                        this.replaceProfessionOptions(this.profesions, this.tsProfesions.getValue().map(Number));
                        return;
                    }

                    const compatibleIds = (await $wire.professionsForArea(areaSelected)).map(Number);
                    const currentIds = this.tsProfesions ? this.tsProfesions.getValue().map(Number) : [];
                    const incompatibleIds = currentIds.filter(id => !compatibleIds.includes(id));

                    if (this.allowMultiArea) {
                        this.currentAreaId = areaSelected;
                        $wire.form.selected_area_id = areaSelected;
                        this.replaceProfessionOptions(this.profesions, currentIds);
                        this.areaWarning = incompatibleIds.length > 0
                            ? 'SICOES conservará las profesiones explícitas de otras áreas; el área elegida se usará solo como clasificación principal.'
                            : '';
                        $wire.form.selected_profession_ids = currentIds;
                        return;
                    }

                    if (incompatibleIds.length > 0) {
                        const incompatibleNames = this.profesions
                            .filter(item => incompatibleIds.includes(Number(item.id)))
                            .map(item => item.profesion_name)
                            .join(', ');
                        this.areaWarning =
                            `Estas profesiones se conservaron, pero no pertenecen al área elegida: ${incompatibleNames}. Quítalas manualmente antes de guardar.`;
                    } else {
                        this.areaWarning = '';
                    }

                    this.currentAreaId = areaSelected;
                    $wire.form.selected_area_id = areaSelected;
                    this.filterProfessionOptions(areaSelected, true);
                    $wire.form.selected_profession_ids = currentIds;
                },
                filterProfessionOptions(
                    areaId,
                    preserveIncompatibleSelections = false,
                    selectedIds = null
                ) {
                    const currentIds = selectedIds === null
                        ? (this.tsProfesions ? this.tsProfesions.getValue().map(Number) : [])
                        : Array.from(selectedIds).map(Number);
                    const compatible = this.profesions.filter(item =>
                        (item.area_ids || []).map(Number).includes(Number(areaId))
                    );
                    const visible = [...compatible];

                    if (preserveIncompatibleSelections) {
                        this.profesions
                            .filter(item =>
                                currentIds.includes(Number(item.id))
                                && !compatible.some(compatibleItem => Number(compatibleItem.id) === Number(item.id))
                            )
                            .forEach(item => visible.push({
                                ...item,
                                profesion_name: `${item.profesion_name} (no pertenece al área seleccionada)`
                            }));
                    }

                    this.replaceProfessionOptions(visible, currentIds);
                    if (preserveIncompatibleSelections && visible.length !== compatible.length) {
                        this.areaWarning = 'Hay profesiones seleccionadas que no pertenecen al área. Corrígelas antes de guardar.';
                    }
                },
                replaceProfessionOptions(items, selectedIds) {
                    if (!this.tsProfesions) {
                        return;
                    }

                    this.tsProfesions.clear(true);
                    this.tsProfesions.clearOptions();
                    items.forEach(item => this.tsProfesions.addOption({
                        value: String(item.id),
                        text: item.profesion_name,
                    }));
                    this.tsProfesions.refreshOptions(false);
                    this.tsProfesions.setValue((selectedIds || []).map(String), true);
                },
                syncRecalculatedProfessions(ids, areaId) {
                    const selectedIds = (ids || []).map(Number);
                    const selectedAreaId = areaId ? Number(areaId) : null;

                    if (this.tsArea) {
                        selectedAreaId
                            ? this.tsArea.setValue(selectedAreaId, true)
                            : this.tsArea.clear(true);
                    }

                    if (this.tsProfesions) {
                        this.allowMultiArea
                            ? this.replaceProfessionOptions(this.profesions, selectedIds)
                            : selectedAreaId
                            ? this.filterProfessionOptions(selectedAreaId, true, selectedIds)
                            : this.replaceProfessionOptions(this.profesions, selectedIds);
                    }

                    this.currentAreaId = selectedAreaId;
                    $wire.form.selected_area_id = selectedAreaId;
                    $wire.form.selected_profession_ids = selectedIds;
                },
                setAllLocations() {
                    const allLocations = this.locations.map(location => location.id);
                    if (this.tsLocations) {
                        this.tsLocations.clear();
                        this.tsLocations.setValue(allLocations);
                    }
                    $wire.form.selected_location_ids = allLocations;
                },
                clearProfesionsSelected() {
                    if (this.tsProfesions) {
                        this.tsProfesions.clear();
                    }
                    $wire.form.selected_profession_ids = [];
                }
            }))
        </script>
    @endscript
</section>

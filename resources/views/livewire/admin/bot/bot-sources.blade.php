@php
    $totalCompanies = $sources->sum('companies_count');
    $activeSources = $sources->count();
@endphp

<section class="min-h-[calc(100vh-8rem)] bg-gradient-to-br from-slate-50 via-white to-orange-50/60 py-8 text-slate-950 dark:from-neutral-950 dark:via-neutral-950 dark:to-neutral-900 dark:text-white sm:py-10">
    <div class="mx-auto flex w-full max-w-[1500px] flex-col gap-7 px-4 sm:px-6 lg:px-10 xl:px-12">
        <header class="flex flex-col gap-5 rounded-[2rem] border border-white/80 bg-white/85 p-5 shadow-sm shadow-slate-200/70 backdrop-blur dark:border-white/10 dark:bg-neutral-900/80 dark:shadow-black/20 md:flex-row md:items-center md:justify-between lg:p-7">
            <div class="flex items-start gap-4">
                <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-400 via-amber-400 to-yellow-300 text-3xl shadow-lg shadow-orange-200/70 dark:shadow-orange-950/30">
                    &#129302;
                </div>

                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-4xl font-black tracking-tight text-slate-950 dark:text-white sm:text-5xl">
                            BOT
                        </h1>
                        <span class="inline-flex items-center rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-xs font-bold uppercase tracking-wide text-orange-700 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-200">
                            Automatización
                        </span>
                    </div>
                    <p class="mt-2 max-w-2xl text-sm font-medium leading-6 text-slate-500 dark:text-neutral-300 sm:text-base">
                        Fuentes configuradas para robar y preparar convocatorias.
                    </p>
                </div>
            </div>

            <span class="inline-flex w-fit items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-700 shadow-sm dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                <span class="h-2.5 w-2.5 rounded-full bg-emerald-500 shadow-[0_0_0_4px_rgba(16,185,129,0.16)]"></span>
                {{ $activeSources }} fuentes activas
            </span>
        </header>

        <div class="overflow-hidden rounded-[2rem] border border-slate-200/80 bg-white shadow-sm shadow-slate-200/70 dark:border-white/10 dark:bg-neutral-900 dark:shadow-black/20">
            <div class="grid divide-y divide-slate-100 dark:divide-white/10 md:grid-cols-3 md:divide-x md:divide-y-0">
                <div class="flex items-center gap-4 p-5 lg:p-6">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-200">
                        <i class="fas fa-building"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-black text-slate-950 dark:text-white">{{ $totalCompanies }}</p>
                        <p class="text-sm font-semibold text-slate-500 dark:text-neutral-300">Empresas configuradas</p>
                    </div>
                </div>

                <div class="flex items-center gap-4 p-5 lg:p-6">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-200">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-black text-slate-950 dark:text-white">{{ $activeSources }}</p>
                        <p class="text-sm font-semibold text-slate-500 dark:text-neutral-300">Fuentes activas</p>
                    </div>
                </div>

                <div class="flex items-center gap-4 p-5 lg:p-6">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-200">
                        <i class="fas fa-sync-alt"></i>
                    </div>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-lg font-black text-slate-950 dark:text-white">Modo de ejecución</p>
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-bold uppercase text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                Manual
                            </span>
                        </div>
                        <p class="text-sm font-semibold text-slate-500 dark:text-neutral-300">Inicia el scraper desde una fuente para crear un lote.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3 xl:gap-6">
            @forelse ($sources as $source)
                <a href="{{ route('admin.bot.source', $source) }}" wire:navigate
                    class="group flex min-h-[360px] flex-col rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm shadow-slate-200/70 transition duration-200 hover:-translate-y-1 hover:border-orange-200 hover:shadow-xl hover:shadow-orange-100/70 focus:outline-none focus:ring-4 focus:ring-orange-200/70 dark:border-white/10 dark:bg-neutral-900 dark:shadow-black/20 dark:hover:border-orange-400/40 dark:hover:shadow-orange-950/20 dark:focus:ring-orange-500/20">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex h-14 w-14 items-center justify-center rounded-2xl border border-slate-100 bg-slate-50 text-3xl shadow-inner dark:border-white/10 dark:bg-neutral-800">
                            {{ $source->icon ?: 'BOT' }}
                        </div>

                        @if (in_array($source->scraper_type, ['evaluar', 'etalent', 'sicoes'], true))
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/30">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                Activa
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-slate-500 ring-1 ring-slate-200 dark:bg-white/10 dark:text-neutral-300 dark:ring-white/10">
                                Próximamente
                            </span>
                        @endif
                    </div>

                    <div class="mt-6 flex-1">
                        <h2 class="text-2xl font-black tracking-tight text-slate-950 dark:text-white">{{ $source->name }}</h2>
                        <p class="mt-3 min-h-[48px] text-sm font-medium leading-6 text-slate-500 dark:text-neutral-300">
                            {{ $source->description }}
                        </p>
                    </div>

                    <div class="my-6 h-px bg-gradient-to-r from-transparent via-slate-200 to-transparent dark:via-white/10"></div>

                    @if (in_array($source->scraper_type, ['evaluar', 'etalent', 'sicoes'], true))
                        <div class="grid gap-3">
                            <div class="rounded-2xl bg-slate-50 p-4 dark:bg-neutral-800/80">
                                <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-neutral-400">
                                    {{ $source->scraper_type === 'sicoes' ? 'Canal configurado' : 'Empresas configuradas' }}
                                </p>
                                <p class="mt-1 text-xl font-black text-slate-950 dark:text-white">{{ $source->companies_count }}</p>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div class="rounded-2xl bg-orange-50 p-4 dark:bg-orange-500/10">
                                    <p class="text-xs font-bold uppercase tracking-wide text-orange-500 dark:text-orange-200">Estado</p>
                                    <p class="mt-1 text-sm font-black text-orange-700 dark:text-orange-100">{{ $source->scraper_type === 'etalent' ? 'Scraper implementado' : '100% operativa' }}</p>
                                </div>
                                <div class="rounded-2xl bg-emerald-50 p-4 dark:bg-emerald-500/10">
                                    <p class="text-xs font-bold uppercase tracking-wide text-emerald-600 dark:text-emerald-200">Fuente</p>
                                    <p class="mt-1 text-sm font-black text-emerald-700 dark:text-emerald-100">{{ $source->scraper_type === 'etalent' ? 'Portal E-Talent' : 'Lista' }}</p>
                                </div>
                            </div>
                        </div>

                        <span class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-orange-500 px-5 py-3 text-sm font-black text-white shadow-lg shadow-orange-200 transition duration-200 group-hover:bg-orange-600 group-hover:shadow-orange-300 dark:shadow-orange-950/30">
                            Entrar
                            <i class="fas fa-arrow-right text-xs transition duration-200 group-hover:translate-x-1"></i>
                        </span>
                    @else
                        <div class="grid gap-3">
                            <div class="rounded-2xl bg-slate-50 p-4 dark:bg-neutral-800/80">
                                <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-neutral-400">Estado</p>
                                <p class="mt-1 text-sm font-black text-slate-700 dark:text-neutral-100">En desarrollo</p>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div class="rounded-2xl bg-slate-50 p-4 dark:bg-neutral-800/80">
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-neutral-400">Extractor</p>
                                    <p class="mt-1 text-sm font-black text-slate-700 dark:text-neutral-100">No implementado</p>
                                </div>
                                <div class="rounded-2xl bg-slate-50 p-4 dark:bg-neutral-800/80">
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-neutral-400">Disponibilidad</p>
                                    <p class="mt-1 text-sm font-black text-slate-700 dark:text-neutral-100">Próximamente</p>
                                </div>
                            </div>
                        </div>

                        <span class="mt-6 inline-flex w-full items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 px-5 py-3 text-sm font-black text-slate-500 dark:border-white/10 dark:bg-neutral-800 dark:text-neutral-300">
                            Próximamente
                        </span>
                    @endif
                </a>
            @empty
                <div class="rounded-[2rem] border border-dashed border-slate-300 bg-white p-10 text-center text-slate-500 shadow-sm dark:border-white/10 dark:bg-neutral-900 dark:text-neutral-300 md:col-span-2 xl:col-span-3">
                    No hay fuentes activas del BOT.
                </div>
            @endforelse
        </div>

        <div class="overflow-hidden rounded-[2rem] border border-orange-200 bg-gradient-to-r from-orange-50 via-amber-50 to-white p-5 shadow-sm shadow-orange-100/80 dark:border-orange-500/20 dark:from-orange-500/10 dark:via-amber-500/10 dark:to-neutral-900 dark:shadow-black/20 sm:p-6">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-start gap-4">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-orange-500 text-white shadow-lg shadow-orange-200 dark:shadow-orange-950/30">
                        <i class="fas fa-lightbulb"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-black text-slate-950 dark:text-white">Automatización inteligente</h3>
                        <p class="mt-1 max-w-2xl text-sm font-semibold leading-6 text-slate-600 dark:text-neutral-300">
                            El BOT trabaja 24/7 para mantener tus convocatorias actualizadas.
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-3 text-orange-500/70 dark:text-orange-200/70">
                    <i class="fas fa-bolt text-xl"></i>
                    <i class="fas fa-arrow-right text-lg"></i>
                    <i class="fas fa-check-circle text-xl"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<?php

namespace App\Models;

use App\Support\StoragePath;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BotCompany extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function getLogoAttribute(?string $value): ?string
    {
        return StoragePath::normalizePublicPath($value);
    }

    public function hasLogoFile(): bool
    {
        return StoragePath::exists($this->resolveLogoPath());
    }

    public function logoUrl(): ?string
    {
        return StoragePath::url($this->resolveLogoPath());
    }

    public function websiteLogoUrl(): ?string
    {
        $company = $this->websiteCompany();

        $path = StoragePath::normalizePublicPath($company?->company_image);

        if (! $path || $this->isPlaceholderLogo($path)) {
            return null;
        }

        return 'https://trabajonautas.com/storage/'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    public function websiteCompanyName(): string
    {
        return (string) ($this->websiteCompany()?->company_name ?: $this->name);
    }

    private function websiteCompany(): ?object
    {
        try {
            $companies = Cache::remember('site_company_logos_v1', now()->addMinutes(30), fn () =>
                DB::connection('site')->table('companies')
                    ->whereNull('deleted_at')
                    ->get(['company_name', 'company_image'])
            );
        } catch (\Throwable) {
            return null;
        }

        $aliases = $this->websiteLogoAliases();
        $company = $companies->first(fn ($candidate) =>
            in_array($this->normalizeName((string) $candidate->company_name), $aliases, true)
        );

        return $company ?: $companies->first(function ($candidate) use ($aliases): bool {
            $candidateName = $this->normalizeName((string) $candidate->company_name);

            foreach ($aliases as $alias) {
                if (strlen($alias) >= 3 && preg_match('/(?:^|\s)'.preg_quote($alias, '/').'(?:$|\s)/', $candidateName) === 1) {
                    return true;
                }
            }

            return false;
        });
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logoUrl();
    }

    public function realCompany(): ?Company
    {
        $aliases = $this->logoAliases();

        return Company::withTrashed()
            ->get()
            ->first(function (Company $company) use ($aliases) {
                $companyName = $this->normalizeName($company->company_name);

                foreach ($aliases as $alias) {
                    if ($companyName === $alias || Str::contains($companyName, $alias) || Str::contains($alias, $companyName)) {
                        return true;
                    }
                }

                return false;
            });
    }

    private function resolveLogoPath(): ?string
    {
        if (! $this->isPlaceholderLogo($this->logo) && StoragePath::exists($this->logo)) {
            return $this->logo;
        }

        $company = $this->realCompany();

        if ($company && StoragePath::exists($company->company_image)) {
            return $company->company_image;
        }

        return null;
    }

    private function isPlaceholderLogo(?string $path): bool
    {
        return Str::contains((string) $path, 'tbn-new-default');
    }

    private function logoAliases(): array
    {
        $aliases = [
            'BMSC' => ['bmsc', 'banco mercantil santa cruz', 'mercantil santa cruz'],
            'BANCO BISA' => ['banco bisa', 'bisa'],
            'FARMACORP' => ['farmacorp', 'nexocorp'],
            'BANCO FIE' => ['banco fie', 'bancofie', 'fie'],
            'BANCO SOL' => ['banco sol', 'bancosol'],
            'BANCO UNION' => ['banco union', 'union'],
            'BANCO ECONOMICO' => ['banco economico', 'economico'],
            'ALIANZA SEGUROS' => ['alianza seguros', 'alianza'],
            'WORLD VISION BOLIVIA' => ['world vision bolivia', 'world vision', 'wvi'],
            'CERVECERIA BOLIVIANA NACIONAL' => ['cerveceria boliviana nacional', 'cbn', 'cerveceria'],
            'RED ENLACE' => ['red enlace', 'redenlace'],
        ][$this->name] ?? [$this->name];

        return collect([...$aliases, $this->name])
            ->map(fn (string $alias) => $this->normalizeName($alias))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function websiteLogoAliases(): array
    {
        $aliases = [
            'BCP' => ['bcp', 'banco de credito de bolivia', 'banco de credito'],
            'BNB' => ['bnb', 'banco nacional de bolivia'],
            'BANCO BDP' => ['banco bdp', 'bdp', 'banco de desarrollo productivo'],
            'BANCO FORTALEZA' => ['banco fortaleza', 'fortaleza'],
            'BAGO' => ['bago', 'laboratorios bago'],
            'EMBOL' => ['embol', 'embotelladoras bolivianas unidas'],
            'UNIFRANZ' => ['unifranz', 'universidad privada franz tamayo'],
            'GRUPO KANTUTANI' => ['grupo kantutani', 'kantutani'],
            'COFAR' => ['cofar', 'laboratorios cofar'],
            'COMPANIA DE ALIMENTOS' => ['compania de alimentos', 'cal'],
            'DATEC' => ['datec', 'datec ltd'],
            'LA BOLIVIANA CIACRUZ' => ['la boliviana ciacruz', 'boliviana ciacruz', 'lbc'],
            'LAFAR' => ['lafar', 'laboratorios lafar'],
            'SOFIA LTDA' => ['sofia ltda', 'sofia', 'procesadora de alimentos sofia'],
            'YPFB REFINACION' => ['ypfb refinacion', 'ypfb refinacion sa'],
            'CREDINFORM' => ['credinform', 'credinform international'],
            'BBO BEBIDAS BOLIVIANAS' => ['bbo bebidas bolivianas', 'bebidas bolivianas bbo', 'bbo'],
            'TOTTO BOLIVIA' => ['totto bolivia', 'totto'],
        ][Str::of($this->name)->ascii()->upper()->toString()] ?? [];

        return collect([...$this->logoAliases(), ...$aliases, $this->name])
            ->map(fn (string $alias) => $this->normalizeName($alias))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeName(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function vacancyPreviews(): HasMany
    {
        return $this->hasMany(BotVacancyPreview::class);
    }

    public function sicoesScrapeBatches(): HasMany
    {
        return $this->hasMany(SicoesScrapeBatch::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(BotSource::class, 'bot_source_id');
    }
}

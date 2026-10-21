<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyType;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SiteCompanyCatalogSynchronizer
{
    /**
     * Read the active website company catalog and mirror it into this PC's local database.
     * No rows are written to the website database.
     *
     * @return array{created: int, updated: int, hidden: int, total: int, unmapped_local: array<int, string>}
     */
    public function sync(?string $localAdminId = null): array
    {
        $this->assertSiteConnectionConfigured();
        $site = DB::connection('site');
        $siteSchema = $site->getSchemaBuilder();

        foreach (['companies', 'company_types'] as $table) {
            if (! $siteSchema->hasTable($table)) {
                throw new RuntimeException("La base del sitio no contiene la tabla {$table}.");
            }
        }
        foreach (['id', 'company_name', 'company_type_id', 'deleted_at', 'description', 'company_image'] as $column) {
            if (! $siteSchema->hasColumn('companies', $column)) {
                throw new RuntimeException("La tabla companies del sitio no contiene la columna {$column}.");
            }
        }

        $remoteTypes = $site->table('company_types')->get(['id', 'company_type_name']);
        $remoteCompanies = $site->table('companies')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'company_name', 'description', 'company_image', 'company_type_id']);

        $localAdminId = $this->resolveLocalAdminId($localAdminId);

        return DB::transaction(function () use ($remoteTypes, $remoteCompanies, $localAdminId): array {
            $localTypes = CompanyType::query()->get();
            $typeIds = [];
            foreach ($remoteTypes as $remoteType) {
                $wanted = $this->normalize((string) $remoteType->company_type_name);
                $localType = $localTypes->first(fn (CompanyType $type): bool =>
                    $this->normalize((string) $type->company_type_name) === $wanted
                );
                if (! $localType) {
                    $localType = CompanyType::query()->create([
                        'company_type_name' => (string) $remoteType->company_type_name,
                    ]);
                    $localTypes->push($localType);
                }
                $typeIds[(int) $remoteType->id] = (int) $localType->id;
            }

            $mapped = Company::withTrashed()->whereNotNull('site_company_id')->get()->keyBy(
                fn (Company $company): int => (int) $company->site_company_id,
            );
            $unmapped = Company::query()->whereNull('site_company_id')->get()->values();
            $remoteIds = [];
            $created = 0;
            $updated = 0;

            foreach ($remoteCompanies as $remoteCompany) {
                $remoteId = (int) $remoteCompany->id;
                $remoteIds[] = $remoteId;
                $company = $mapped->get($remoteId);
                $matches = $unmapped->filter(fn (Company $candidate): bool =>
                    $this->companyNamesEquivalent(
                        (string) $candidate->company_name,
                        (string) $remoteCompany->company_name,
                    )
                )->values();

                if ($matches->count() > 1) {
                    throw new RuntimeException('Hay varios registros locales que podrían corresponder a «'.$remoteCompany->company_name.'». No se aplicó la sincronización para evitar reasignaciones ambiguas.');
                }

                if (! $company) {
                    $company = $matches->first();
                    if ($company) {
                        $unmapped = $unmapped->reject(fn (Company $candidate): bool => (int) $candidate->id === (int) $company->id)->values();
                    }
                } elseif ($matches->isNotEmpty()) {
                    $localRecord = $matches->first();
                    $this->consolidateLocalCopy($company, $localRecord);
                    $company = $localRecord;
                    $unmapped = $unmapped->reject(fn (Company $candidate): bool => (int) $candidate->id === (int) $company->id)->values();
                }

                $typeId = $typeIds[(int) $remoteCompany->company_type_id] ?? null;
                if (! $typeId) {
                    throw new RuntimeException('No se encontró el tipo local equivalente para «'.$remoteCompany->company_name.'».');
                }

                $attributes = [
                    'site_company_id' => $remoteId,
                    'company_name' => (string) $remoteCompany->company_name,
                    'company_type_id' => $typeId,
                    'description' => trim((string) ($remoteCompany->description ?? '')) ?: 'Empresa sincronizada desde el catálogo del sitio.',
                ];

                if (! $company) {
                    $attributes['user_id'] = $localAdminId;
                    // Company logos live on the VPS. Keep a local placeholder until assets are synced separately.
                    $attributes['company_image'] = 'empresas/tbn-new-default.webp';
                    $company = Company::query()->create($attributes);
                    $created++;
                    continue;
                }

                $changed = collect($attributes)->contains(
                    fn ($value, $key): bool => (string) $company->getRawOriginal($key) !== (string) $value,
                );
                if ($company->trashed()) {
                    $company->restore();
                    $changed = true;
                }
                if ($changed) {
                    $company->fill($attributes)->save();
                    $updated++;
                }
                $mapped->put($remoteId, $company);
            }

            $hidden = 0;
            if ($remoteIds !== []) {
                $stale = Company::query()
                    ->whereNotNull('site_company_id')
                    ->whereNotIn('site_company_id', $remoteIds)
                    ->get();
                foreach ($stale as $company) {
                    $company->delete();
                    $hidden++;
                }
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'hidden' => $hidden,
                'total' => count($remoteIds),
                'unmapped_local' => $unmapped->pluck('company_name')->values()->all(),
            ];
        });
    }

    private function resolveLocalAdminId(?string $localAdminId): string
    {
        $role = (string) config('trabajonautas.roles.admin', 'ADMIN');
        $admins = User::query()
            ->where('actived', true)
            ->whereHas('roles', fn ($query) => $query->where('name', $role))
            ->get(['id']);

        if ($localAdminId !== null && $admins->contains(fn (User $user): bool => (string) $user->id === $localAdminId)) {
            return $localAdminId;
        }

        $existingOwner = Company::query()->whereNotNull('user_id')->value('user_id');
        if ($existingOwner && $admins->contains(fn (User $user): bool => (string) $user->id === (string) $existingOwner)) {
            return (string) $existingOwner;
        }
        if ($admins->count() === 1) {
            return (string) $admins->first()->id;
        }

        throw new RuntimeException('No se pudo resolver un administrador local activo para crear las copias de empresas.');
    }

    private function consolidateLocalCopy(Company $catalogCopy, Company $existingLocal): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('announcements')) {
            DB::table('announcements')->where('company_id', $catalogCopy->id)->update(['company_id' => $existingLocal->id]);
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('bot_vacancy_previews')) {
            DB::table('bot_vacancy_previews')->where('selected_company_id', $catalogCopy->id)->update([
                'selected_company_id' => $existingLocal->id,
            ]);
        }

        $catalogCopy->forceFill([
            'site_company_id' => null,
            'company_name' => 'Archivo de sincronizacion '.$catalogCopy->id,
        ])->save();
        $catalogCopy->delete();
    }

    private function assertSiteConnectionConfigured(): void
    {
        if (trim((string) config('database.connections.site.url')) !== '') {
            return;
        }

        foreach (['host', 'database', 'username', 'password'] as $key) {
            if (trim((string) config("database.connections.site.{$key}")) === '') {
                throw new RuntimeException('Configura SITE_DB_* en esta PC para cargar el catálogo de empresas de la web.');
            }
        }
    }

    private function companyNamesEquivalent(string $left, string $right): bool
    {
        $a = $this->normalizeCompanyName($left);
        $b = $this->normalizeCompanyName($right);
        if ($a === $b) {
            return true;
        }

        $groups = [
            ['bmsc', 'banco mercantil santa cruz'],
            ['soboce', 'soboce sociedad boliviana de cemento'],
            ['sinchi wayra', 'grupo minero sinchi wayra'],
            ['asfi', 'autoridad de supervision del sistema financiero'],
            ['ende', 'empresa nacional de electricidad', 'empresa nacional de electricidad ende', 'ende empresa nacional de electricidad'],
            ['ucp paap', 'unidad coordinadora del programa de agua y alcantarillado periurbano', 'unidad coordinadora programa de agua y alcantarillado periurbano', 'ucp paap unidad coordinadora del programa de agua y alcantarillado periurbano', 'unidad coordinadora programa de agua y alcantarillado periurbano ucp paap'],
            ['hospital tercer nivel potosi', 'hospital de tercer nivel potosi fray giovanni eugenio natalini magnani'],
            ['servicio departamental de gestion social potosi', 'sedeges potosi servicio departamental de gestion social'],
        ];
        foreach ($groups as $group) {
            $normalized = array_map(fn (string $name): string => $this->normalizeCompanyName($name), $group);
            if (in_array($a, $normalized, true) && in_array($b, $normalized, true)) {
                return true;
            }
        }

        return $this->containsLongName($a, $b) || $this->containsLongName($b, $a);
    }

    private function containsLongName(string $short, string $long): bool
    {
        if (count(explode(' ', $short)) < 4) {
            return false;
        }

        return preg_match('/(?:^|\s)'.preg_quote($short, '/').'(?:$|\s)/', $long) === 1;
    }

    private function normalizeCompanyName(string $value): string
    {
        $normalized = $this->normalize($value);

        return trim((string) preg_replace(
            '/(?:^|\s)(?:s a|sa|s r l|srl|s a s|sas|s a c|sac|s c|sc|ltda|limitada|inc|llc|corp|corporation)(?:$|\s)/',
            ' ',
            ' '.$normalized.' ',
        ));
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }
}

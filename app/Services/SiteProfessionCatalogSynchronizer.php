<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Profesion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SiteProfessionCatalogSynchronizer
{
    /**
     * Mirror the website's areas, professions and pivot locally, preserving IDs.
     * The website connection is read-only in this service.
     *
     * @return array{areas: int, professions: int, relations: int}
     */
    public function sync(): array
    {
        if (! Schema::hasColumn('areas', 'site_catalog_active')
            || ! Schema::hasColumn('profesions', 'site_catalog_active')) {
            throw new RuntimeException('Falta ejecutar la migración del catálogo web de áreas y profesiones.');
        }

        $site = DB::connection('site');
        $siteSchema = $site->getSchemaBuilder();
        foreach (['areas', 'profesions', 'area_profesion'] as $table) {
            if (! $siteSchema->hasTable($table)) {
                throw new RuntimeException("La base web no contiene la tabla {$table} del catálogo.");
            }
        }

        $remoteAreas = $site->table('areas')->orderBy('id')->get([
            'id', 'area_name', 'description', 'created_at', 'updated_at',
        ]);
        $remoteProfessions = $site->table('profesions')->orderBy('id')->get([
            'id', 'profesion_name', 'created_at', 'updated_at',
        ]);
        $remoteRelations = $site->table('area_profesion')->orderBy('id')->get([
            'area_id', 'profesion_id', 'created_at', 'updated_at',
        ]);

        if ($remoteAreas->isEmpty() || $remoteProfessions->isEmpty() || $remoteRelations->isEmpty()) {
            throw new RuntimeException('El catálogo web está vacío o incompleto; no se modificó el catálogo local.');
        }

        $localUserId = Area::query()->value('user_id') ?? Profesion::query()->value('user_id');
        if (! $localUserId) {
            throw new RuntimeException('No se encontró un usuario local para asociar nuevas filas del catálogo.');
        }

        $areaIds = $remoteAreas->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $professionIds = $remoteProfessions->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $validRelations = $remoteRelations->filter(fn ($relation): bool =>
            in_array((int) $relation->area_id, $areaIds, true)
            && in_array((int) $relation->profesion_id, $professionIds, true)
        );
        $orphanRelations = $remoteRelations->count() - $validRelations->count();
        if ($orphanRelations > 0) {
            Log::warning('Se omitieron relaciones huérfanas del catálogo web durante la sincronización.', [
                'omitted_relations' => $orphanRelations,
            ]);
        }

        DB::transaction(function () use ($remoteAreas, $remoteProfessions, $validRelations, $localUserId): void {
            DB::table('areas')->update(['site_catalog_active' => false]);
            DB::table('profesions')->update(['site_catalog_active' => false]);

            foreach ($remoteAreas as $area) {
                DB::table('areas')->updateOrInsert(
                    ['id' => (int) $area->id],
                    [
                        'area_name' => trim((string) $area->area_name),
                        'description' => trim((string) ($area->description ?? '')) ?: trim((string) $area->area_name),
                        'user_id' => $localUserId,
                        'site_catalog_active' => true,
                        'updated_at' => now(),
                    ],
                );
            }

            foreach ($remoteProfessions as $profession) {
                DB::table('profesions')->updateOrInsert(
                    ['id' => (int) $profession->id],
                    [
                        'profesion_name' => trim((string) $profession->profesion_name),
                        'user_id' => $localUserId,
                        'site_catalog_active' => true,
                        'updated_at' => now(),
                    ],
                );
            }

            DB::table('area_profesion')->delete();
            $now = now();
            foreach ($validRelations->chunk(500) as $chunk) {
                DB::table('area_profesion')->insert($chunk->map(fn ($relation): array => [
                    'area_id' => (int) $relation->area_id,
                    'profesion_id' => (int) $relation->profesion_id,
                    'created_at' => $relation->created_at ?: $now,
                    'updated_at' => $relation->updated_at ?: $now,
                ])->all());
            }
        }, 3);

        Cache::forget('areas');
        Cache::forget('profesions_with_areas');

        return [
            'areas' => $remoteAreas->count(),
            'professions' => $remoteProfessions->count(),
            'relations' => $validRelations->count(),
            'omitted_relations' => $orphanRelations,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Area;
use App\Models\BotVacancyPreview;
use App\Models\Profesion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProfessionAssignmentService
{
    public function __construct(
        private ProfessionNameNormalizer $nameNormalizer,
    ) {}

    public function catalog(): array
    {
        return Area::query()
            ->orderBy('id')
            ->get(['id', 'area_name'])
            ->map(fn (Area $area): array => [
                'id' => (int) $area->id,
                'name' => $area->area_name,
            ])
            ->all();
    }

    public function resolveDetectedProfessions(
        array $detectedProfessions,
        bool $expandRelatedArea = false,
        ?string $affinityEvidence = null,
        ?string $aiPrimaryAreaName = null,
        float $aiPrimaryAreaConfidence = 0.0,
        ?string $aiPrimaryAreaEvidence = null,
        string $aiProviderLabel = 'Claude',
    ): array {
        $aiProviderLabel = trim($aiProviderLabel) ?: 'IA';
        $catalog = $this->professionCatalog();
        $resolved = [];
        $unidentified = [];
        $ambiguous = [];
        $ignoredAffinityExpressions = [];
        $reviewReasons = [];

        foreach ($detectedProfessions as $detected) {
            if (! is_array($detected)) {
                continue;
            }

            $originalName = trim((string) ($detected['nombre_original'] ?? ''));
            $catalogName = trim((string) ($detected['nombre_catalogo'] ?? ''));
            $evidence = trim((string) ($detected['evidencia'] ?? ''));
            $requirementType = (string) ($detected['tipo_requisito'] ?? 'obligatoria');
            $aiConfidence = $this->confidence($detected['confianza'] ?? 0);

            if ($originalName === '' || $evidence === '') {
                $unidentified[] = [
                    'nombre_original' => $originalName,
                    'evidencia' => $evidence,
                    'tipo_requisito' => $requirementType,
                    'confianza_ia' => $aiConfidence,
                    'motivo' => 'La detección no contiene nombre y evidencia completos.',
                ];

                continue;
            }

            if ($this->isGenericAffinityExpression($originalName)) {
                $ignoredAffinityExpressions[] = [
                    'nombre_original' => $originalName,
                    'evidencia' => $evidence,
                    'tipo_requisito' => $requirementType,
                    'confianza_ia' => $aiConfidence,
                    'motivo' => 'Es una expresión genérica de afinidad, no una profesión.',
                ];

                continue;
            }

            $match = $catalogName !== ''
                ? $this->matchCatalogProfession($catalogName, $catalog)
                : $this->matchProfession($originalName, $catalog);
            $base = [
                'nombre_original' => $originalName,
                'nombre_catalogo_ia' => $catalogName ?: null,
                'evidencia' => $evidence,
                'tipo_requisito' => $requirementType,
                'confianza_ia' => $aiConfidence,
            ];

            if ($match['status'] === 'unidentified') {
                $unidentified[] = [
                    ...$base,
                    'confianza_coincidencia' => $match['confidence'],
                    'motivo' => 'No coincide con una profesión autorizada del catálogo.',
                ];

                continue;
            }

            if ($match['status'] === 'ambiguous') {
                $ambiguous[] = [
                    ...$base,
                    'confianza_coincidencia' => $match['confidence'],
                    'candidatos' => $match['candidates'],
                    'motivo' => $match['reason'],
                ];

                continue;
            }

            /** @var Profesion $profession */
            $profession = $match['profession'];
            $professionId = (int) $profession->id;
            $entry = [
                'profesion_id' => $professionId,
                'profesion_name' => $profession->profesion_name,
                ...$base,
                'nombres_originales' => [$originalName],
                'evidencias' => [$evidence],
                'confianza_coincidencia' => $match['confidence'],
                'tipo_coincidencia' => $match['match_type'],
                'alias_utilizado' => $match['alias'],
                'agregada_por_afinidad' => false,
                'area_ids' => $profession->areas->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                'areas' => $profession->areas->map(fn (Area $area): array => [
                    'area_id' => (int) $area->id,
                    'area_name' => $area->area_name,
                ])->values()->all(),
            ];

            if (isset($resolved[$professionId])) {
                $resolved[$professionId]['nombres_originales'] = collect([
                    ...$resolved[$professionId]['nombres_originales'],
                    $originalName,
                ])->unique()->values()->all();
                $resolved[$professionId]['evidencias'] = collect([
                    ...$resolved[$professionId]['evidencias'],
                    $evidence,
                ])->unique()->values()->all();
                $resolved[$professionId]['confianza_ia'] = max(
                    $resolved[$professionId]['confianza_ia'],
                    $aiConfidence,
                );

                continue;
            }

            $resolved[$professionId] = $entry;
        }

        $resolved = array_values($resolved);
        $areaGroups = $this->groupResolvedProfessionsByArea($resolved);
        $dominantArea = $this->determineDominantArea($areaGroups);
        $validatedAiArea = null;
        $aiPrimaryAreaName = trim((string) $aiPrimaryAreaName);
        $aiPrimaryAreaConfidence = $this->confidence($aiPrimaryAreaConfidence);

        if ($aiPrimaryAreaName !== '') {
            $area = Area::query()->where('area_name', $aiPrimaryAreaName)->first(['id', 'area_name']);
            $hasResolvedProfessionInArea = $area && collect($resolved)->contains(
                fn (array $profession): bool => in_array(
                    (int) $area->id,
                    array_map('intval', $profession['area_ids'] ?? []),
                    true,
                ),
            );

            if (! $area) {
                $reviewReasons[] = "{$aiProviderLabel} seleccionó un área que no pertenece al catálogo autorizado.";
            } elseif (! $hasResolvedProfessionInArea) {
                $reviewReasons[] = "El área principal seleccionada por {$aiProviderLabel} no contiene ninguna profesión detectada.";
            } else {
                $validatedAiArea = [
                    'area_id' => (int) $area->id,
                    'area_name' => $area->area_name,
                    'confianza' => $aiPrimaryAreaConfidence,
                    'evidencia' => trim((string) $aiPrimaryAreaEvidence),
                ];
            }
        }
        $selectedArea = $dominantArea
            ?? $validatedAiArea
            ?? collect($areaGroups)->first();
        $expandedProfessionIds = [];
        $expandedAreas = [];
        $excludedAffinityAreas = [];

        if ($expandRelatedArea) {
            $evidence = trim((string) $affinityEvidence)
                ?: 'La convocatoria acepta carreras, áreas o ramas afines.';
            $explicitAreaIds = collect($resolved)
                ->flatMap(fn (array $profession): array => array_map(
                    'intval',
                    $profession['area_ids'] ?? [],
                ))
                ->filter(fn (int $areaId): bool => $areaId > 0)
                ->unique()
                ->values();

            if ($explicitAreaIds->isEmpty()) {
                $reviewReasons[] = 'Se mencionan carreras afines, pero las profesiones detectadas no tienen áreas relacionadas para expandir.';
            } elseif (! $selectedArea || (int) ($selectedArea['area_id'] ?? 0) <= 0) {
                $reviewReasons[] = 'Se mencionan carreras afines, pero no se pudo seleccionar un área principal para expandir.';
            } else {
                $areasForExpansion = Area::query()
                    ->whereKey((int) $selectedArea['area_id'])
                    ->get(['id', 'area_name']);
                $excludedAreaIds = $this->affinityExpansionExcludedAreaIds($catalog);
                $resolvedIds = collect($resolved)
                    ->pluck('profesion_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                foreach ($areasForExpansion as $areaForExpansion) {
                    $areaId = (int) $areaForExpansion->id;

                    if (in_array($areaId, $excludedAreaIds, true)) {
                        $excludedAffinityAreas[] = [
                            'area_id' => $areaId,
                            'area_name' => $areaForExpansion->area_name,
                            'evidencia' => $evidence,
                            'motivo' => 'El área está excluida de la expansión automática de profesiones afines.',
                        ];

                        continue;
                    }

                    $areaProfessions = $catalog->filter(
                        fn (Profesion $profession): bool => $profession->areas->contains(
                            fn (Area $area): bool => (int) $area->id === $areaId,
                        ),
                    );

                    foreach ($areaProfessions as $profession) {
                        $professionId = (int) $profession->id;
                        if (in_array($professionId, $resolvedIds, true)) {
                            continue;
                        }

                        $resolved[] = [
                            'profesion_id' => $professionId,
                            'profesion_name' => $profession->profesion_name,
                            'nombre_original' => $profession->profesion_name,
                            'nombre_catalogo_ia' => $profession->profesion_name,
                            'evidencia' => $evidence,
                            'tipo_requisito' => 'alternativa',
                            'confianza_ia' => 1.0,
                            'nombres_originales' => [$profession->profesion_name],
                            'evidencias' => [$evidence],
                            'confianza_coincidencia' => 1.0,
                            'tipo_coincidencia' => 'expansion_area_afin',
                            'alias_utilizado' => null,
                            'agregada_por_afinidad' => true,
                            'area_ids' => $profession->areas
                                ->pluck('id')
                                ->map(fn ($id): int => (int) $id)
                                ->values()
                                ->all(),
                            'areas' => $profession->areas->map(fn (Area $area): array => [
                                'area_id' => (int) $area->id,
                                'area_name' => $area->area_name,
                            ])->values()->all(),
                        ];
                        $expandedProfessionIds[] = $professionId;
                        $resolvedIds[] = $professionId;
                    }

                    $expandedAreas[] = [
                        'area_id' => $areaId,
                        'area_name' => $areaForExpansion->area_name,
                        'evidencia' => $evidence,
                        'seleccionada_por_ia' => $validatedAiArea !== null
                            && (int) $validatedAiArea['area_id'] === $areaId,
                    ];
                }

                $resolved = array_values($resolved);
                $expandedProfessionIds = array_values(array_unique($expandedProfessionIds));
                $areaGroups = $this->groupResolvedProfessionsByArea($resolved);
                $dominantArea = $this->determineDominantArea($areaGroups);
            }
        }

        if ($detectedProfessions === []) {
            $reviewReasons[] = "{$aiProviderLabel} no detectó ninguna profesión explícita.";
        }
        if ($resolved === []) {
            $reviewReasons[] = 'No se pudo resolver ninguna profesión contra el catálogo.';
        }
        if ($unidentified !== []) {
            $reviewReasons[] = 'Existen profesiones no identificadas en el catálogo.';
        }
        if ($ambiguous !== []) {
            $reviewReasons[] = 'Existen coincidencias ambiguas que requieren decisión humana.';
        }
        if (collect($resolved)->contains(fn (array $item): bool => $item['area_ids'] === [])) {
            $reviewReasons[] = 'Una profesión resuelta no tiene áreas relacionadas en el catálogo.';
        }

        $reviewReasons = array_values(array_unique($reviewReasons));
        $professionIds = collect($resolved)->pluck('profesion_id')->map(fn ($id): int => (int) $id)->values()->all();
        $professionNames = collect($resolved)->pluck('profesion_name')->values()->all();
        $areaIds = collect($areaGroups)->pluck('area_id')->map(fn ($id): int => (int) $id)->values()->all();
        $areaNames = collect($areaGroups)->pluck('area_name')->values()->all();

        return [
            'profesiones_resueltas' => $resolved,
            'profesiones_no_identificadas' => $unidentified,
            'profesiones_ambiguas' => $ambiguous,
            'expresiones_afinidad_ignoradas' => $ignoredAffinityExpressions,
            'acepta_carreras_afines' => $expandRelatedArea,
            'expansion_afinidad_aplicada' => $expandedAreas !== [],
            'area_expandida_por_afinidad' => count($expandedAreas) === 1
                ? $expandedAreas[0]
                : null,
            'areas_expandidas_por_afinidad' => $expandedAreas,
            'areas_omitidas_por_afinidad' => $excludedAffinityAreas,
            'profession_ids_agregados_por_afinidad' => $expandedProfessionIds,
            'area_principal_ia' => $validatedAiArea,
            'areas_detectadas' => $areaGroups,
            'area_dominante' => $dominantArea,
            'requiere_revision' => $reviewReasons !== [],
            'motivos_revision' => $reviewReasons,
            'profession_ids' => $professionIds,
            'profession_names' => $professionNames,
            'area_ids' => $areaIds,
            'area_names' => $areaNames,
            'selected_area_id' => $selectedArea['area_id'] ?? null,
            'valid' => $professionIds !== [] && $reviewReasons === [],
            'error' => $reviewReasons !== [] ? implode(' ', $reviewReasons) : null,
            'catalog_fingerprint' => $this->catalogFingerprint(),
            'classifier_version' => (string) config(
                'profession_matching.classifier_version',
                'profession-catalog-v1',
            ),
        ];
    }

    public function resolveSingleProfession(string $name): array
    {
        return $this->matchProfession($name, $this->professionCatalog());
    }

    public function groupResolvedProfessionsByArea(array $professions): array
    {
        $total = max(1, count($professions));
        $groups = [];

        foreach ($professions as $profession) {
            foreach ($profession['areas'] ?? [] as $area) {
                $areaId = (int) $area['area_id'];
                $groups[$areaId] ??= [
                    'area_id' => $areaId,
                    'area_name' => $area['area_name'],
                    'profesion_ids' => [],
                ];
                $groups[$areaId]['profesion_ids'][] = (int) $profession['profesion_id'];
            }
        }

        return collect($groups)
            ->map(function (array $group) use ($total): array {
                $ids = collect($group['profesion_ids'])->unique()->values()->all();

                return [
                    ...$group,
                    'profesion_ids' => $ids,
                    'cantidad' => count($ids),
                    'porcentaje' => round(count($ids) / $total, 4),
                ];
            })
            ->sortByDesc('porcentaje')
            ->values()
            ->all();
    }

    public function determineDominantArea(array $groups): ?array
    {
        if ($groups === []) {
            return null;
        }

        $groups = collect($groups)->sortByDesc('porcentaje')->values();
        $first = $groups->first();
        $second = $groups->get(1);
        $threshold = (float) config('profession_matching.dominant_area_threshold', 0.70);
        $margin = (float) config('profession_matching.ambiguity_margin', 0.05);

        if ((float) $first['porcentaje'] < $threshold) {
            return null;
        }

        if ($second && ((float) $first['porcentaje'] - (float) $second['porcentaje']) <= $margin) {
            return null;
        }

        return [
            'area_id' => (int) $first['area_id'],
            'area_name' => $first['area_name'],
            'porcentaje' => (float) $first['porcentaje'],
        ];
    }

    public function applyDetectedResolutionToPreview(
        BotVacancyPreview $preview,
        array $resolution,
        array $context = [],
    ): array {
        $before = collect($preview->selected_profession_ids ?: [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $manual = $this->professionsEditedManually($preview);
        $after = $manual ? $before : array_values($resolution['profession_ids'] ?? []);

        if (! $manual) {
            $requiresReview = (bool) ($resolution['requiere_revision'] ?? true);
            $rawData = array_merge($preview->raw_data ?? [], [
                'profession_resolution' => $resolution,
                'profesiones_originales' => collect($resolution['profesiones_resueltas'] ?? [])
                    ->pluck('nombres_originales')->flatten()->unique()->values()->all(),
                'profesiones_no_identificadas' => $resolution['profesiones_no_identificadas'] ?? [],
                'profesiones_ambiguas' => $resolution['profesiones_ambiguas'] ?? [],
                'areas_detectadas' => $resolution['areas_detectadas'] ?? [],
                'motivos_revision' => $resolution['motivos_revision'] ?? [],
                'resolved_area_ids' => $resolution['area_ids'] ?? [],
                'profession_assignment_source' => 'detected_professions_catalog',
                'profession_assignment_error' => $resolution['error'] ?? null,
                'profession_assignment_updated_at' => now()->toIso8601String(),
            ]);

            $preview->update([
                'area' => ($resolution['area_names'] ?? []) !== []
                    ? implode(', ', $resolution['area_names'])
                    : 'No especificado',
                'professions' => ($resolution['profession_names'] ?? []) !== []
                    ? implode(', ', $resolution['profession_names'])
                    : 'No especificado',
                'selected_area_id' => $resolution['selected_area_id'] ?? null,
                'selected_profession_ids' => $after,
                'status' => $requiresReview ? 'error' : 'preview',
                'raw_data' => $rawData,
            ]);
        }

        $this->logDecision(array_merge($context, [
            'preview_id' => $preview->id,
            'scrape_batch_id' => $preview->scrape_batch_id,
            'raw_ai_professions' => $context['raw_ai_professions'] ?? [],
            'resolved_area_ids' => $resolution['area_ids'] ?? [],
            'professions_before' => $before,
            'professions_from_ai' => $resolution['profession_ids'] ?? [],
            'professions_after' => $after,
            'professions_edited_manually' => $manual,
        ]));

        return [
            ...$resolution,
            'professions_before' => $before,
            'professions_after' => $after,
            'preserved_manual_selection' => $manual,
        ];
    }

    public function catalogFingerprint(): string
    {
        $parts = [
            'areas' => [
                'count' => Area::query()->count(),
                'updated' => Area::query()->max('updated_at'),
            ],
            'professions' => [
                'count' => Profesion::query()->count(),
                'updated' => Profesion::query()->max('updated_at'),
            ],
            'relations' => [
                'count' => DB::table('area_profesion')->count(),
                'updated' => DB::table('area_profesion')->max('updated_at'),
            ],
        ];

        if (Schema::hasTable('profesion_aliases')) {
            $parts['aliases'] = [
                'count' => DB::table('profesion_aliases')->count(),
                'updated' => DB::table('profesion_aliases')->max('updated_at'),
            ];
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    public function resolve(array $requestedAreaIds): array
    {
        $invalidValues = [];
        $areaIds = collect($requestedAreaIds)
            ->map(function (mixed $value) use (&$invalidValues): ?int {
                if (is_int($value) && $value > 0) {
                    return $value;
                }

                if (is_string($value) && ctype_digit(trim($value)) && (int) $value > 0) {
                    return (int) $value;
                }

                $invalidValues[] = is_scalar($value) ? (string) $value : gettype($value);

                return null;
            })
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $areas = Area::query()
            ->whereIn('id', $areaIds)
            ->orderBy('id')
            ->get(['id', 'area_name']);
        $resolvedAreaIds = $areas->pluck('id')->map(fn ($id): int => (int) $id)->values();
        $missingAreaIds = $areaIds->diff($resolvedAreaIds)->values()->all();
        $valid = $areaIds->isNotEmpty() && $invalidValues === [] && $missingAreaIds === [];

        $professionIds = $valid ? $this->professionIdsForAreaIds($resolvedAreaIds->all()) : [];
        $professions = $professionIds
            ? Profesion::query()->whereIn('id', $professionIds)->orderBy('id')->get(['id', 'profesion_name'])
            : collect();

        return [
            'valid' => $valid && $professionIds !== [],
            'requested_area_ids' => $areaIds->all(),
            'area_ids' => $resolvedAreaIds->all(),
            'area_names' => $areas->pluck('area_name')->values()->all(),
            'invalid_area_ids' => $missingAreaIds,
            'invalid_area_values' => array_values(array_unique($invalidValues)),
            'profession_ids' => $professions->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'profession_names' => $professions->pluck('profesion_name')->values()->all(),
            'error' => $this->resolutionError($areaIds->all(), $missingAreaIds, $invalidValues, $professionIds),
        ];
    }

    public function resolveExactAreaNames(array $areaNames): array
    {
        $names = collect($areaNames)
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (string $name): string => trim($name))
            ->unique(fn (string $name): string => $this->normalize($name))
            ->values();
        $catalog = Area::query()
            ->get(['id', 'area_name'])
            ->keyBy(fn (Area $area): string => $this->normalize($area->area_name));
        $resolvedIds = [];
        $unmatched = [];

        foreach ($names as $name) {
            $area = $catalog->get($this->normalize($name));

            if ($area) {
                $resolvedIds[] = (int) $area->id;
            } else {
                $unmatched[] = $name;
            }
        }

        $assignment = $this->resolve($resolvedIds);

        if ($unmatched !== []) {
            $assignment['valid'] = false;
            $assignment['profession_ids'] = [];
            $assignment['profession_names'] = [];
            $assignment['invalid_area_values'] = $unmatched;
            $assignment['error'] = 'Se recibieron areas que no coinciden exactamente con el catalogo.';
        }

        return $assignment;
    }

    public function professionIdsForAreaIds(array $areaIds): array
    {
        $areaIds = collect($areaIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($areaIds === []) {
            return [];
        }

        return Profesion::query()
            ->whereHas('areas', function ($query) use ($areaIds): void {
                $query->whereIn('areas.id', $areaIds);
            })
            ->distinct()
            ->orderBy('profesions.id')
            ->pluck('profesions.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function applyToPreview(BotVacancyPreview $preview, array $assignment, array $context = []): array
    {
        $before = collect($preview->selected_profession_ids ?: [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
        $manual = $this->professionsEditedManually($preview);
        $after = $manual ? $before : array_values($assignment['profession_ids'] ?? []);

        if (! $manual) {
            $valid = (bool) ($assignment['valid'] ?? false);
            $rawData = array_merge($preview->raw_data ?? [], [
                'resolved_area_ids' => array_values($assignment['area_ids'] ?? []),
                'profession_assignment_source' => 'area_profession_pivot',
                'profession_assignment_error' => $assignment['error'] ?? null,
                'profession_assignment_updated_at' => now()->toIso8601String(),
            ]);

            $preview->update([
                'area' => $valid ? implode(', ', $assignment['area_names'] ?? []) : 'No especificado',
                'professions' => $valid ? implode(', ', $assignment['profession_names'] ?? []) : 'No especificado',
                'selected_area_id' => $valid ? ($assignment['area_ids'][0] ?? null) : null,
                'selected_profession_ids' => $after,
                'status' => ! $valid && in_array($preview->status, ['preview', 'error'], true)
                    ? 'error'
                    : $preview->status,
                'raw_data' => $rawData,
            ]);
        }

        $this->logDecision(array_merge($context, [
            'preview_id' => $preview->id,
            'scrape_batch_id' => $preview->scrape_batch_id,
            'resolved_area_ids' => $assignment['area_ids'] ?? [],
            'professions_before' => $before,
            'professions_from_areas' => $assignment['profession_ids'] ?? [],
            'professions_from_ai' => [],
            'professions_after' => $after,
            'professions_edited_manually' => $manual,
        ]));

        return [
            ...$assignment,
            'professions_before' => $before,
            'professions_after' => $after,
            'preserved_manual_selection' => $manual,
        ];
    }

    public function professionsEditedManually(BotVacancyPreview $preview): bool
    {
        return (bool) data_get($preview->raw_data, 'manual_professions_locked')
            || ($preview->status === 'edited' && $preview->selected_profession_ids !== null);
    }

    public function logDecision(array $context): void
    {
        Log::debug('BOT profession catalog assignment.', [
            'raw_ai_areas' => $context['raw_ai_areas'] ?? [],
            'raw_ai_professions' => $context['raw_ai_professions'] ?? [],
            'resolved_area_ids' => $context['resolved_area_ids'] ?? [],
            'professions_before' => $context['professions_before'] ?? [],
            'professions_from_areas' => $context['professions_from_areas'] ?? [],
            'professions_from_ai' => $context['professions_from_ai'] ?? [],
            'professions_after' => $context['professions_after'] ?? [],
            'preview_id' => $context['preview_id'] ?? null,
            'scrape_batch_id' => $context['scrape_batch_id'] ?? null,
            'professions_edited_manually' => (bool) ($context['professions_edited_manually'] ?? false),
            'source' => $context['source'] ?? null,
        ]);
    }

    private function professionCatalog(): Collection
    {
        $relations = ['areas:id,area_name'];
        if (Schema::hasTable('profesion_aliases')) {
            $relations[] = 'aliases:id,profesion_id,alias,alias_normalizado';
        }

        return Profesion::query()
            ->with($relations)
            ->orderBy('id')
            ->get(['id', 'profesion_name'])
            ->each(function (Profesion $profession): void {
                if (! $profession->relationLoaded('aliases')) {
                    $profession->setRelation('aliases', collect());
                }
            });
    }

    private function affinityExpansionExcludedAreaIds(Collection $catalog): array
    {
        $excludedAreaNames = collect(config(
            'profession_matching.affinity_expansion_excluded_area_names',
            [],
        ))
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (string $name): string => $this->nameNormalizer->normalize($name));
        $anchorProfessionNames = collect(config(
            'profession_matching.affinity_expansion_exclusion_anchor_professions',
            [],
        ))
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(fn (string $name): string => $this->nameNormalizer->normalize($name));

        return $catalog
            ->flatMap(function (Profesion $profession) use (
                $excludedAreaNames,
                $anchorProfessionNames,
            ): array {
                $professionIsAnchor = $anchorProfessionNames->contains(
                    $this->nameNormalizer->normalize($profession->profesion_name),
                );

                return $profession->areas
                    ->filter(
                        fn (Area $area): bool => $professionIsAnchor
                            || $excludedAreaNames->contains(
                                $this->nameNormalizer->normalize($area->area_name),
                            ),
                    )
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();
            })
            ->unique()
            ->values()
            ->all();
    }

    private function isGenericAffinityExpression(string $name): bool
    {
        $normalized = $this->nameNormalizer->normalize($name);

        return preg_match('/^ramas?\b/u', $normalized) === 1
            || preg_match('/^(?:carreras?|areas?|especialidades?)\s+afines?\b/u', $normalized) === 1
            || preg_match('/^afines?\b/u', $normalized) === 1;
    }

    private function matchCatalogProfession(string $catalogName, Collection $catalog): array
    {
        $profession = $catalog->first(
            fn (Profesion $candidate): bool => $candidate->profesion_name === $catalogName,
        );

        if (! $profession) {
            return [
                'status' => 'unidentified',
                'confidence' => 0.0,
                'candidates' => [],
                'reason' => 'Claude seleccionó un nombre que no pertenece al catálogo autorizado.',
            ];
        }

        return $this->resolvedMatch($profession, 1.0, 'catalogo_ia');
    }

    private function matchProfession(string $name, Collection $catalog): array
    {
        $name = trim($name);
        $normalized = $this->nameNormalizer->normalize($name);
        $variants = $this->nameNormalizer->variants($name);

        $exact = $catalog->first(
            fn (Profesion $profession): bool => trim($profession->profesion_name) === $name,
        );
        if ($exact) {
            return $this->resolvedMatch($exact, 1.0, 'exacta');
        }

        foreach ($catalog as $profession) {
            $alias = $profession->aliases->first(
                fn ($alias): bool => trim((string) $alias->alias) === $name,
            );
            if ($alias) {
                return $this->resolvedMatch($profession, 1.0, 'alias', $alias->alias);
            }
        }

        $normalizedOfficial = $catalog->first(
            fn (Profesion $profession): bool => in_array(
                $this->nameNormalizer->normalize($profession->profesion_name),
                $variants,
                true,
            ),
        );
        if ($normalizedOfficial) {
            $score = $this->nameNormalizer->normalize($normalizedOfficial->profesion_name) === $normalized
                ? 0.99
                : 0.97;

            return $this->resolvedMatch($normalizedOfficial, $score, 'normalizada');
        }

        foreach ($catalog as $profession) {
            $alias = $profession->aliases->first(
                fn ($alias): bool => in_array((string) $alias->alias_normalizado, $variants, true),
            );
            if ($alias) {
                return $this->resolvedMatch($profession, 0.98, 'alias', $alias->alias);
            }
        }

        $candidates = $catalog
            ->map(function (Profesion $profession) use ($variants): array {
                $officialScore = collect($variants)
                    ->map(fn (string $variant): float => $this->nameNormalizer->similarity(
                        $variant,
                        $profession->profesion_name,
                    ))
                    ->max() ?? 0.0;
                $bestAlias = null;
                $bestAliasScore = 0.0;

                foreach ($profession->aliases as $alias) {
                    $score = collect($variants)
                        ->map(fn (string $variant): float => $this->nameNormalizer->similarity(
                            $variant,
                            $alias->alias_normalizado,
                        ))
                        ->max() ?? 0.0;
                    if ($score > $bestAliasScore) {
                        $bestAliasScore = $score;
                        $bestAlias = $alias->alias;
                    }
                }

                return [
                    'profession' => $profession,
                    'profession_id' => (int) $profession->id,
                    'profesion_name' => $profession->profesion_name,
                    'confidence' => max($officialScore, $bestAliasScore),
                    'match_type' => $bestAliasScore > $officialScore ? 'alias_aproximada' : 'aproximada',
                    'alias' => $bestAliasScore > $officialScore ? $bestAlias : null,
                ];
            })
            ->sortByDesc('confidence')
            ->values();

        $best = $candidates->first();
        if (! $best) {
            return [
                'status' => 'unidentified',
                'confidence' => 0.0,
                'candidates' => [],
                'reason' => 'El catálogo de profesiones está vacío.',
            ];
        }

        $second = $candidates->get(1);
        $automaticThreshold = (float) config('profession_matching.automatic_match_threshold', 0.90);
        $reviewThreshold = (float) config('profession_matching.manual_review_threshold', 0.75);
        $margin = (float) config('profession_matching.ambiguity_margin', 0.05);
        $closeCandidates = $second
            && ((float) $best['confidence'] - (float) $second['confidence']) <= $margin;
        $candidateSummary = $candidates
            ->take(3)
            ->map(fn (array $candidate): array => [
                'profesion_id' => $candidate['profession_id'],
                'profesion_name' => $candidate['profesion_name'],
                'confianza_coincidencia' => $candidate['confidence'],
                'tipo_coincidencia' => $candidate['match_type'],
            ])
            ->all();
        $controlledPartialCandidates = $candidates
            ->filter(function (array $candidate) use ($normalized): bool {
                $official = $this->nameNormalizer->normalize($candidate['profesion_name']);

                return mb_strlen($normalized) >= 6
                    && (
                        str_starts_with($official, $normalized.' ')
                        || str_contains($official, ' '.$normalized.' ')
                        || str_ends_with($official, ' '.$normalized)
                    );
            })
            ->take(3)
            ->values();

        if ($controlledPartialCandidates->isNotEmpty()) {
            return [
                'status' => 'ambiguous',
                'confidence' => max(
                    $reviewThreshold,
                    (float) $controlledPartialCandidates->first()['confidence'],
                ),
                'candidates' => $controlledPartialCandidates
                    ->map(fn (array $candidate): array => [
                        'profesion_id' => $candidate['profession_id'],
                        'profesion_name' => $candidate['profesion_name'],
                        'confianza_coincidencia' => $candidate['confidence'],
                        'tipo_coincidencia' => 'parcial_controlada',
                    ])
                    ->all(),
                'reason' => 'El nombre es parcial y requiere confirmar la profesión oficial.',
            ];
        }

        if ((float) $best['confidence'] >= $automaticThreshold && ! $closeCandidates) {
            return $this->resolvedMatch(
                $best['profession'],
                (float) $best['confidence'],
                $best['match_type'],
                $best['alias'],
            );
        }

        if ((float) $best['confidence'] >= $reviewThreshold) {
            return [
                'status' => 'ambiguous',
                'confidence' => (float) $best['confidence'],
                'candidates' => $candidateSummary,
                'reason' => $closeCandidates
                    ? 'Dos o más profesiones tienen puntuaciones demasiado próximas.'
                    : 'La coincidencia no alcanza el umbral de asignación automática.',
            ];
        }

        return [
            'status' => 'unidentified',
            'confidence' => (float) $best['confidence'],
            'candidates' => $candidateSummary,
            'reason' => 'La coincidencia está por debajo del umbral de revisión.',
        ];
    }

    private function resolvedMatch(
        Profesion $profession,
        float $confidence,
        string $matchType,
        ?string $alias = null,
    ): array {
        return [
            'status' => 'resolved',
            'profession' => $profession,
            'confidence' => round($confidence, 4),
            'match_type' => $matchType,
            'alias' => $alias,
            'candidates' => [],
            'reason' => null,
        ];
    }

    private function confidence(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        return round(max(0.0, min(1.0, (float) $value)), 4);
    }

    private function resolutionError(array $requestedIds, array $missingIds, array $invalidValues, array $professionIds): ?string
    {
        if ($requestedIds === []) {
            return 'La IA no devolvio ningun area_id.';
        }

        if ($invalidValues !== []) {
            return 'La IA devolvio valores de area que no son IDs validos.';
        }

        if ($missingIds !== []) {
            return 'La IA devolvio area_ids inexistentes en el catalogo.';
        }

        if ($professionIds === []) {
            return 'Las areas seleccionadas no tienen profesiones relacionadas en el catalogo.';
        }

        return null;
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

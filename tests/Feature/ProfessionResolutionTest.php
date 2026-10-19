<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Profesion;
use App\Models\ProfesionAlias;
use App\Services\ProfessionAssignmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfessionResolutionTest extends TestCase
{
    private ProfessionAssignmentService $service;

    private Area $social;

    private Area $administrative;

    private Area $technology;

    private Area $civil;

    private Area $industrial;

    private Area $uncommon;

    private Area $agronomic;

    private array $professions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->service = app(ProfessionAssignmentService::class);
        $owner = (string) Str::uuid();
        $this->social = Area::create([
            'area_name' => 'Ciencias Sociales',
            'description' => 'Área social',
            'user_id' => $owner,
        ]);
        $this->administrative = Area::create([
            'area_name' => 'Economía y Administración',
            'description' => 'Área administrativa',
            'user_id' => $owner,
        ]);
        $this->technology = Area::create([
            'area_name' => 'Tecnología',
            'description' => 'Área tecnológica',
            'user_id' => $owner,
        ]);
        $this->civil = Area::create([
            'area_name' => 'Área CIVIL Y CONSTRUCCIÓN',
            'description' => 'Profesiones relacionadas a CIVIL / CONSTRUCCIÓN',
            'user_id' => $owner,
        ]);
        $this->industrial = Area::create([
            'area_name' => 'Área INDUSTRIAL',
            'description' => 'Profesiones relacionadas al área industrial',
            'user_id' => $owner,
        ]);
        $this->uncommon = Area::create([
            'area_name' => 'Áreas POCO FRECUENTES',
            'description' => 'Profesiones poco frecuentes',
            'user_id' => $owner,
        ]);
        $this->agronomic = Area::create([
            'area_name' => 'Área AGRONÓMICA',
            'description' => 'Profesiones agronómicas y alimentarias',
            'user_id' => $owner,
        ]);

        $this->profession('Trabajo Social', $this->social, $owner);
        $this->profession('Psicología', $this->social, $owner);
        $this->profession('Administración de Empresas', $this->administrative, $owner);
        $this->profession('Contaduría', $this->administrative, $owner);
        $this->profession('Economía', $this->administrative, $owner);
        $this->profession('Ingeniería Financiera', $this->administrative, $owner);
        $this->profession('Ingeniería de Sistemas', $this->technology, $owner);
        $this->profession('Ciencia de Datos', $this->technology, $owner);
        $this->profession('Civil y Construcciones Civiles', $this->civil, $owner);
        $this->profession('Ingeniería Industrial', $this->industrial, $owner);
        $this->profession('Bibliotecología', $this->uncommon, $owner);
        $this->profession('Archivística', $this->uncommon, $owner);
        $this->profession('Ingeniería de Alimentos', $this->agronomic, $owner);
        $this->profession('Agronomía', $this->agronomic, $owner);

        $this->alias('Contaduría', 'Contador Público');
        $this->alias('Trabajo Social', 'Trabajador Social');
        $this->alias('Civil y Construcciones Civiles', 'Ingeniería Civil');
        $this->alias('Administración de Empresas', 'Licenciado de carreras Administrativas');
        $this->alias('Economía', 'Licenciado de carreras Económicas');
        $this->alias('Ingeniería Financiera', 'Licenciado de carreras Financieras');
        $this->alias('Contaduría', 'Licenciado de carreras Contables');
    }

    public function test_only_explicit_work_social_is_selected(): void
    {
        $result = $this->resolve(['Trabajo Social']);

        $this->assertSame([$this->professions['Trabajo Social']->id], $result['profession_ids']);
        $this->assertSame([$this->social->id], $result['area_ids']);
        $this->assertNotContains($this->professions['Psicología']->id, $result['profession_ids']);
        $this->assertFalse($result['requiere_revision']);
    }

    public function test_three_explicit_administrative_professions_do_not_select_the_rest_of_the_area(): void
    {
        $result = $this->resolve([
            'Administración de Empresas',
            'Contaduría',
            'Ingeniería Financiera',
        ]);

        $this->assertEqualsCanonicalizing([
            $this->professions['Administración de Empresas']->id,
            $this->professions['Contaduría']->id,
            $this->professions['Ingeniería Financiera']->id,
        ], $result['profession_ids']);
        $this->assertCount(3, $result['profession_ids']);
        $this->assertSame($this->administrative->id, $result['selected_area_id']);
    }

    public function test_professions_from_three_areas_keep_all_matches_without_requiring_review(): void
    {
        $result = $this->resolve([
            'Psicología',
            'Administración de Empresas',
            'Ingeniería de Sistemas',
        ]);

        $this->assertCount(3, $result['profession_ids']);
        $this->assertCount(3, $result['areas_detectadas']);
        $this->assertSame($this->social->id, $result['selected_area_id']);
        $this->assertFalse($result['requiere_revision']);
    }

    public function test_configured_alias_resolves_public_accountant(): void
    {
        $result = $this->resolve(['Contador Público']);

        $this->assertSame([$this->professions['Contaduría']->id], $result['profession_ids']);
        $this->assertSame('alias', $result['profesiones_resueltas'][0]['tipo_coincidencia']);
    }

    public function test_safe_normalization_resolves_financial_engineer(): void
    {
        $result = $this->resolve(['Ing. Financiero']);

        $this->assertSame([$this->professions['Ingeniería Financiera']->id], $result['profession_ids']);
        $this->assertSame('normalizada', $result['profesiones_resueltas'][0]['tipo_coincidencia']);
    }

    public function test_engineering_civil_alias_resolves_the_existing_civil_catalog_profession(): void
    {
        $result = $this->resolve(['Licenciatura en Ingeniería Civil']);

        $this->assertSame(
            [$this->professions['Civil y Construcciones Civiles']->id],
            $result['profession_ids'],
        );
        $this->assertSame([$this->civil->id], $result['area_ids']);
        $this->assertSame($this->civil->id, $result['selected_area_id']);
        $this->assertSame('alias', $result['profesiones_resueltas'][0]['tipo_coincidencia']);
        $this->assertFalse($result['requiere_revision']);
    }

    public function test_administrative_career_groups_resolve_and_generic_branches_are_not_professions(): void
    {
        $evidence = 'Licenciado de carreras Administrativas, Económicas, Financieras, Contables, '
            .'Ingeniería Industrial o ramas económicas financieras.';
        $result = $this->service->resolveDetectedProfessions([
            $this->detected('Licenciado de carreras Administrativas', $evidence),
            $this->detected('Licenciado de carreras Económicas', $evidence),
            $this->detected('Licenciado de carreras Financieras', $evidence),
            $this->detected('Licenciado de carreras Contables', $evidence),
            $this->detected('Ingeniería Industrial', $evidence),
            $this->detected('ramas económicas financieras', $evidence),
        ]);

        $this->assertEqualsCanonicalizing([
            $this->professions['Administración de Empresas']->id,
            $this->professions['Economía']->id,
            $this->professions['Ingeniería Financiera']->id,
            $this->professions['Contaduría']->id,
            $this->professions['Ingeniería Industrial']->id,
        ], $result['profession_ids']);
        $this->assertSame([], $result['profesiones_no_identificadas']);
        $this->assertSame(
            ['ramas económicas financieras'],
            collect($result['expresiones_afinidad_ignoradas'])->pluck('nombre_original')->all(),
        );
        $this->assertSame($this->administrative->id, $result['selected_area_id']);
        $this->assertFalse($result['requiere_revision']);
    }

    public function test_affine_careers_expression_expands_the_complete_validated_area(): void
    {
        $result = $this->service->resolveDetectedProfessions(
            [$this->detected('Trabajo Social', 'Trabajo Social o carreras afines.', 'Trabajo Social')],
            true,
            'Trabajo Social o carreras afines.',
        );

        $this->assertEqualsCanonicalizing([
            $this->professions['Trabajo Social']->id,
            $this->professions['Psicología']->id,
        ], $result['profession_ids']);
        $this->assertTrue($result['expansion_afinidad_aplicada']);
        $this->assertSame(
            [$this->professions['Psicología']->id],
            $result['profession_ids_agregados_por_afinidad'],
        );
        $this->assertSame($this->social->id, $result['selected_area_id']);
        $this->assertFalse($result['requiere_revision']);
    }

    public function test_affinity_expands_only_the_principal_area_and_keeps_all_explicit_professions(): void
    {
        $result = $this->service->resolveDetectedProfessions(
            [
                $this->detected('Trabajo Social'),
                $this->detected('Administración de Empresas'),
                $this->detected('Ingeniería de Sistemas'),
            ],
            true,
            'Trabajo Social, Administración de Empresas, Ingeniería de Sistemas o áreas afines.',
        );

        $this->assertEqualsCanonicalizing([
            $this->professions['Trabajo Social']->id,
            $this->professions['Psicología']->id,
            $this->professions['Administración de Empresas']->id,
            $this->professions['Ingeniería de Sistemas']->id,
        ], $result['profession_ids']);
        $this->assertSame(
            [$this->social->id],
            collect($result['areas_expandidas_por_afinidad'])->pluck('area_id')->all(),
        );
        $this->assertTrue($result['expansion_afinidad_aplicada']);
    }

    public function test_affinity_does_not_expand_uncommon_or_food_engineering_area(): void
    {
        $result = $this->service->resolveDetectedProfessions(
            [
                $this->detected('Trabajo Social'),
                $this->detected('Bibliotecología'),
                $this->detected('Ingeniería de Alimentos'),
            ],
            true,
            'Trabajo Social, Bibliotecología, Ingeniería de Alimentos o áreas afines.',
        );

        $this->assertContains($this->professions['Psicología']->id, $result['profession_ids']);
        $this->assertContains($this->professions['Bibliotecología']->id, $result['profession_ids']);
        $this->assertContains($this->professions['Ingeniería de Alimentos']->id, $result['profession_ids']);
        $this->assertNotContains($this->professions['Archivística']->id, $result['profession_ids']);
        $this->assertNotContains($this->professions['Agronomía']->id, $result['profession_ids']);
        $this->assertSame(
            [$this->social->id],
            collect($result['areas_expandidas_por_afinidad'])->pluck('area_id')->all(),
        );
        $this->assertSame([], $result['areas_omitidas_por_afinidad']);
    }

    public function test_ai_catalog_names_resolve_generic_financial_and_economic_mentions_without_aliases(): void
    {
        $result = $this->service->resolveDetectedProfessions([
            $this->detected('financieras', 'carreras financieras', 'Ingeniería Financiera'),
            $this->detected('económicas', 'carreras económicas', 'Economía'),
            $this->detected('contables', 'carreras contables', 'Contaduría'),
        ]);

        $this->assertEqualsCanonicalizing([
            $this->professions['Ingeniería Financiera']->id,
            $this->professions['Economía']->id,
            $this->professions['Contaduría']->id,
        ], $result['profession_ids']);
        $this->assertSame([], $result['profesiones_no_identificadas']);
        $this->assertSame(
            ['catalogo_ia'],
            collect($result['profesiones_resueltas'])->pluck('tipo_coincidencia')->unique()->values()->all(),
        );
        $this->assertFalse($result['requiere_revision']);
    }

    public function test_unknown_degree_is_preserved_as_unidentified(): void
    {
        $result = $this->resolve(['Licenciatura en Gestión Pública Municipal']);

        $this->assertSame([], $result['profession_ids']);
        $this->assertSame(
            'Licenciatura en Gestión Pública Municipal',
            $result['profesiones_no_identificadas'][0]['nombre_original'],
        );
        $this->assertTrue($result['requiere_revision']);
    }

    public function test_no_detected_profession_requires_review(): void
    {
        $result = $this->service->resolveDetectedProfessions([]);

        $this->assertSame([], $result['profession_ids']);
        $this->assertTrue($result['requiere_revision']);
    }

    public function test_equivalent_mentions_are_deduplicated_and_keep_all_evidence(): void
    {
        $result = $this->service->resolveDetectedProfessions([
            $this->detected('Trabajo Social', 'Trabajo Social.'),
            $this->detected('Licenciatura en Trabajo Social', 'Licenciatura en Trabajo Social.'),
            $this->detected('Trabajador Social', 'Trabajador Social.'),
        ]);

        $this->assertSame([$this->professions['Trabajo Social']->id], $result['profession_ids']);
        $this->assertCount(3, $result['profesiones_resueltas'][0]['evidencias']);
    }

    public function test_partial_administration_without_alias_is_ambiguous_and_not_assigned(): void
    {
        $result = $this->resolve(['Administración']);

        $this->assertSame([], $result['profession_ids']);
        $this->assertNotEmpty($result['profesiones_ambiguas']);
        $this->assertTrue($result['requiere_revision']);
    }

    private function resolve(array $names): array
    {
        return $this->service->resolveDetectedProfessions(
            collect($names)->map(fn (string $name): array => $this->detected($name))->all(),
        );
    }

    private function detected(
        string $name,
        ?string $evidence = null,
        ?string $catalogName = null,
    ): array
    {
        return [
            'nombre_original' => $name,
            ...($catalogName ? ['nombre_catalogo' => $catalogName] : []),
            'evidencia' => $evidence ?? "Se requiere {$name}.",
            'tipo_requisito' => 'obligatoria',
            'confianza' => 0.98,
        ];
    }

    private function profession(string $name, Area $area, string $owner): void
    {
        $profession = Profesion::create([
            'profesion_name' => $name,
            'user_id' => $owner,
        ]);
        $profession->areas()->attach($area->id);
        $this->professions[$name] = $profession;
    }

    private function alias(string $professionName, string $alias): void
    {
        ProfesionAlias::create([
            'profesion_id' => $this->professions[$professionName]->id,
            'alias' => $alias,
            'alias_normalizado' => app(\App\Services\ProfessionNameNormalizer::class)->normalize($alias),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('areas', function (Blueprint $table): void {
            $table->id();
            $table->string('area_name')->unique();
            $table->string('description');
            $table->uuid('user_id');
            $table->timestamps();
        });
        Schema::create('profesions', function (Blueprint $table): void {
            $table->id();
            $table->string('profesion_name')->unique();
            $table->uuid('user_id');
            $table->timestamps();
        });
        Schema::create('area_profesion', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('area_id');
            $table->unsignedBigInteger('profesion_id');
            $table->timestamps();
        });
        Schema::create('profesion_aliases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('profesion_id');
            $table->string('alias');
            $table->string('alias_normalizado')->unique();
            $table->timestamps();
        });
    }
}

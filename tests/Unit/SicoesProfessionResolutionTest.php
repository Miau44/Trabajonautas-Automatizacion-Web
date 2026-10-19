<?php

namespace Tests\Unit;

use App\Models\Area;
use App\Models\Profesion;
use App\Models\ProfesionAlias;
use App\Services\ProfessionAssignmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SicoesProfessionResolutionTest extends TestCase
{
    private Area $social;

    private Area $administrative;

    private Area $electricalArea;

    private Profesion $socialWork;

    private Profesion $accounting;

    private Profesion $electrical;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('areas', function (Blueprint $table): void {
            $table->id();
            $table->string('area_name');
            $table->timestamps();
        });
        Schema::create('profesions', function (Blueprint $table): void {
            $table->id();
            $table->string('profesion_name');
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
            $table->string('alias_normalizado');
            $table->timestamps();
        });

        $this->social = Area::create(['area_name' => 'Área SOCIAL']);
        $this->administrative = Area::create(['area_name' => 'Área ADMINISTRATIVA']);
        $this->electricalArea = Area::create(['area_name' => 'Área ELÉCTRICA']);
        $this->socialWork = $this->profession('Trabajo Social', $this->social);
        $this->accounting = $this->profession('Contaduría Pública', $this->administrative);
        $this->profession('Administración de Empresas', $this->administrative);
        $this->profession('Administración Pública', $this->administrative);
        ProfesionAlias::create([
            'profesion_id' => $this->accounting->id,
            'alias' => 'Contador Público',
            'alias_normalizado' => 'contador publico',
        ]);
        $this->electrical = $this->profession('Eléctrico/a', $this->electricalArea);
        ProfesionAlias::create([
            'profesion_id' => $this->electrical->id,
            'alias' => 'Ingeniería Eléctrica',
            'alias_normalizado' => 'ingenieria electrica',
        ]);
    }

    public function test_official_and_alias_professions_resolve_without_adding_the_whole_area(): void
    {
        $resolution = app(ProfessionAssignmentService::class)->resolveDetectedProfessions([
            $this->detected('Trabajo Social'),
            $this->detected('Contador Público', catalog: ''),
        ]);

        $this->assertEqualsCanonicalizing(
            [$this->socialWork->id, $this->accounting->id],
            $resolution['profession_ids'],
        );
        $this->assertCount(2, $resolution['profession_ids']);
        $this->assertSame('alias', collect($resolution['profesiones_resueltas'])->firstWhere('profesion_id', $this->accounting->id)['tipo_coincidencia']);
    }

    public function test_unknown_and_ambiguous_professions_require_review_without_inventing_ids(): void
    {
        $resolution = app(ProfessionAssignmentService::class)->resolveDetectedProfessions([
            $this->detected('Gestión Pública Municipal', catalog: ''),
            $this->detected('Administración', catalog: ''),
        ]);

        $this->assertSame([], $resolution['profession_ids']);
        $this->assertCount(1, $resolution['profesiones_no_identificadas']);
        $this->assertCount(1, $resolution['profesiones_ambiguas']);
        $this->assertTrue($resolution['requiere_revision']);
    }

    public function test_related_careers_expand_only_after_explicit_evidence_and_validated_area(): void
    {
        $withoutAffinity = app(ProfessionAssignmentService::class)->resolveDetectedProfessions([
            $this->detected('Contaduría Pública'),
        ]);
        $withAffinity = app(ProfessionAssignmentService::class)->resolveDetectedProfessions(
            [$this->detected('Contaduría Pública')],
            true,
            'Contaduría Pública o carreras afines.',
            'Área ADMINISTRATIVA',
            0.99,
            'El cargo desarrolla funciones administrativas y contables.',
        );

        $this->assertSame([$this->accounting->id], $withoutAffinity['profession_ids']);
        $this->assertCount(3, $withAffinity['profession_ids']);
        $this->assertTrue($withAffinity['expansion_afinidad_aplicada']);
        $this->assertNotEmpty($withAffinity['profession_ids_agregados_por_afinidad']);
    }

    public function test_original_profession_uses_catalog_alias_when_ai_leaves_catalog_name_empty(): void
    {
        $resolution = app(ProfessionAssignmentService::class)->resolveDetectedProfessions([
            $this->detected('Ingeniería Eléctrica', catalog: ''),
        ]);

        $this->assertSame([$this->electrical->id], $resolution['profession_ids']);
        $this->assertSame($this->electricalArea->id, $resolution['selected_area_id']);
        $this->assertFalse($resolution['requiere_revision']);
        $this->assertSame('alias', $resolution['profesiones_resueltas'][0]['tipo_coincidencia']);
    }

    public function test_sicoes_review_reasons_identify_claude_instead_of_gemini(): void
    {
        $resolution = app(ProfessionAssignmentService::class)->resolveDetectedProfessions(
            [],
            aiProviderLabel: 'Claude',
        );

        $this->assertContains('Claude no detectó ninguna profesión explícita.', $resolution['motivos_revision']);
        $this->assertStringNotContainsString('Gemini', implode(' ', $resolution['motivos_revision']));
    }

    public function test_multiarea_professions_use_claude_primary_area_without_losing_professions_or_blocking(): void
    {
        $resolution = app(ProfessionAssignmentService::class)->resolveDetectedProfessions(
            [
                $this->detected('Trabajo Social'),
                $this->detected('Contaduría Pública'),
            ],
            false,
            null,
            'Área SOCIAL',
            0.55,
            'El cargo y sus funciones son principalmente sociales.',
            'Claude',
        );

        $this->assertEqualsCanonicalizing(
            [$this->socialWork->id, $this->accounting->id],
            $resolution['profession_ids'],
        );
        $this->assertSame($this->social->id, $resolution['selected_area_id']);
        $this->assertFalse($resolution['requiere_revision']);
    }

    public function test_affinity_expands_only_the_primary_area_selected_by_claude(): void
    {
        $resolution = app(ProfessionAssignmentService::class)->resolveDetectedProfessions(
            [
                $this->detected('Trabajo Social'),
                $this->detected('Contaduría Pública'),
            ],
            true,
            'Trabajo Social, Contaduría Pública o ramas afines.',
            'Área ADMINISTRATIVA',
            0.60,
            'Las funciones administrativas son las más representativas.',
            'Claude',
        );

        $this->assertContains($this->socialWork->id, $resolution['profession_ids']);
        $this->assertCount(4, $resolution['profession_ids']);
        $this->assertSame(
            [$this->administrative->id],
            collect($resolution['areas_expandidas_por_afinidad'])->pluck('area_id')->all(),
        );
        $this->assertSame($this->administrative->id, $resolution['selected_area_id']);
    }

    private function profession(string $name, Area $area): Profesion
    {
        $profession = Profesion::create(['profesion_name' => $name]);
        $area->profesions()->attach($profession->id);

        return $profession;
    }

    private function detected(string $original, ?string $catalog = null): array
    {
        return [
            'nombre_original' => $original,
            'nombre_catalogo' => $catalog ?? $original,
            'evidencia' => "Formación requerida: {$original}.",
            'tipo_requisito' => 'obligatoria',
            'confianza' => 0.98,
        ];
    }
}

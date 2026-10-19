<?php

namespace Tests\Unit;

use App\Services\Bot\SicoesDocumentImporterService;
use ReflectionMethod;
use Tests\TestCase;

class SicoesDocumentImporterVariantTest extends TestCase
{
    public function test_multiple_individual_document_is_split_by_roles_and_each_role_keeps_its_professions(): void
    {
        $variants = $this->variants([
            'profesiones_resueltas' => [
                $this->profession(84, 'Ingenieria Ambiental', 41, 'Area AMBIENTAL'),
                $this->profession(228, 'Ingenieria Forestal', 41, 'Area AMBIENTAL'),
                $this->profession(161, 'Civil y Construcciones Civiles', 12, 'Area CIVIL Y CONSTRUCCION'),
            ],
            'profesiones_no_identificadas' => [],
            'profesiones_ambiguas' => [],
            'areas_detectadas' => [
                ['area_id' => 41, 'area_name' => 'Area AMBIENTAL', 'profesion_ids' => [84, 228]],
                ['area_id' => 12, 'area_name' => 'Area CIVIL Y CONSTRUCCION', 'profesion_ids' => [161]],
            ],
            'motivos_revision' => [
                'La confianza del area principal seleccionada por Gemini es insuficiente.',
                'La convocatoria incluye profesiones pertenecientes a varias areas sin un area dominante.',
            ],
            'profession_ids' => [84, 228, 161],
        ], [
            'contract_type' => 'multiple_individual',
            'cargos' => [
                [
                    'nombre' => 'Ambiental',
                    'profesiones' => ['Ingenieria Ambiental', 'Ingenieria Forestal'],
                ],
                [
                    'nombre' => 'Civil',
                    'profesiones' => ['Civil y Construcciones Civiles'],
                ],
            ],
        ]);

        $this->assertCount(2, $variants);
        $this->assertSame(41, $variants[0]['resolution']['selected_area_id']);
        $this->assertSame([84, 228], $variants[0]['resolution']['profession_ids']);
        $this->assertSame(12, $variants[1]['resolution']['selected_area_id']);
        $this->assertSame([161], $variants[1]['resolution']['profession_ids']);
        $this->assertTrue($variants[0]['resolution']['valid']);
        $this->assertSame([], $variants[0]['resolution']['motivos_revision']);
    }

    public function test_profession_related_to_multiple_areas_is_not_split_automatically(): void
    {
        $profession = $this->profession(84, 'Ingenieria Ambiental', 41, 'Area AMBIENTAL');
        $profession['areas'][] = ['area_id' => 12, 'area_name' => 'Area CIVIL Y CONSTRUCCION'];
        $profession['area_ids'][] = 12;

        $variants = $this->variants([
            'profesiones_resueltas' => [$profession],
            'profesiones_no_identificadas' => [],
            'profesiones_ambiguas' => [],
            'areas_detectadas' => [
                ['area_id' => 41, 'area_name' => 'Area AMBIENTAL', 'profesion_ids' => [84]],
                ['area_id' => 12, 'area_name' => 'Area CIVIL Y CONSTRUCCION', 'profesion_ids' => [84]],
            ],
            'motivos_revision' => [],
            'profession_ids' => [84],
        ], ['contract_type' => 'multiple_individual']);

        $this->assertSame([], $variants);
    }

    private function variants(array $resolution, array $analysis): array
    {
        $service = app(SicoesDocumentImporterService::class);
        $method = new ReflectionMethod(SicoesDocumentImporterService::class, 'professionPreviewVariants');

        return $method->invoke($service, $resolution, $analysis);
    }

    private function profession(int $id, string $name, int $areaId, string $areaName): array
    {
        return [
            'profesion_id' => $id,
            'profesion_name' => $name,
            'nombre_original' => $name,
            'nombres_originales' => [$name],
            'area_ids' => [$areaId],
            'areas' => [['area_id' => $areaId, 'area_name' => $areaName]],
        ];
    }
}

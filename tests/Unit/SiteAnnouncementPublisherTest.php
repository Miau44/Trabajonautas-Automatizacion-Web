<?php

namespace Tests\Unit;

use App\Models\BotVacancyPreview;
use App\Models\Company;
use App\Services\SiteAnnouncementPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SiteAnnouncementPublisherTest extends TestCase
{
    public function test_publishing_fails_closed_when_site_database_is_not_configured(): void
    {
        config()->set('database.connections.site.host', '');

        try {
            app(SiteAnnouncementPublisher::class)->publish(
                new BotVacancyPreview(),
                new Company(),
                ['Ingeniería'],
                ['La Paz'],
                [],
            );
            $this->fail('Expected missing remote database configuration to stop publication.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SITE_DB_*', $exception->getMessage());
        }
    }

    public function test_local_attachments_block_publication_before_any_remote_database_call(): void
    {
        config()->set('database.connections.site.host', 'example.invalid');
        config()->set('database.connections.site.database', 'site');
        config()->set('database.connections.site.username', 'publisher');
        config()->set('database.connections.site.password', 'configured');

        $preview = new BotVacancyPreview();
        $preview->attachments = [[
            'url' => 'convocatorias/local.pdf',
            'original_name' => 'convocatoria.pdf',
        ]];

        try {
            app(SiteAnnouncementPublisher::class)->publish(
                $preview,
                new Company(),
                ['Ingeniería'],
                ['La Paz'],
                [],
            );
            $this->fail('Expected local-only attachments to stop publication.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('acceso de archivos del sitio', $exception->getMessage());
        }
    }

    public function test_publication_maps_local_catalog_names_and_is_idempotent(): void
    {
        $this->useInMemorySiteDatabase();

        DB::connection('site')->table('users')->insert(['id' => 'admin-user', 'actived' => true]);
        DB::connection('site')->table('roles')->insert(['id' => 1, 'name' => 'ADMIN', 'guard_name' => 'web']);
        DB::connection('site')->table('model_has_roles')->insert([
            'role_id' => 1,
            'model_type' => 'App\\Models\\User',
            'model_id' => 'admin-user',
        ]);
        DB::connection('site')->table('companies')->insert([
            'id' => 42,
            'company_name' => 'Banco Union S.A.',
            'description' => 'Site company',
            'company_image' => 'empresas/test.webp',
            'user_id' => 'admin-user',
            'company_type_id' => 1,
        ]);
        DB::connection('site')->table('company_types')->insert(['id' => 1, 'company_type_name' => 'Pública']);
        DB::connection('site')->table('profesions')->insert(['id' => 7, 'profesion_name' => 'Ingeniería']);
        DB::connection('site')->table('locations')->insert(['id' => 3, 'location_name' => 'La Paz']);

        $company = new Company([
            'company_name' => 'BANCO UNION',
            'description' => 'Entidad de prueba local',
            'company_type_id' => 1,
        ]);
        $preview = new BotVacancyPreview([
            'source_url' => 'https://source.example/notice/123',
            'attachments' => [],
        ]);
        $announcement = [
            'announce_title' => 'Convocatoria de prueba técnica',
            'description' => 'Descripción de prueba',
            'expiration_time' => '2030-01-01 12:00:00',
            'salary' => 0,
            'pro' => false,
            'scheduled_at' => null,
        ];
        $publisher = app(SiteAnnouncementPublisher::class);

        $first = $publisher->publish($preview, $company, ['Ingenieria'], ['La Paz'], $announcement);
        $second = $publisher->publish($preview, $company, ['Ingeniería'], ['La Paz'], $announcement);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, DB::connection('site')->table('announcements')->count());
        $this->assertSame(1, DB::connection('site')->table('companies')->count());
        $this->assertDatabaseHas('announcements', [
            'id' => $first['id'],
            'company_id' => 42,
            'user_id' => 'admin-user',
        ], 'site');
        $this->assertDatabaseHas('announcement_profesion', [
            'announcement_id' => $first['id'],
            'profesion_id' => 7,
        ], 'site');
        $this->assertDatabaseHas('announcement_location', [
            'announcement_id' => $first['id'],
            'location_id' => 3,
        ], 'site');

        $differentSource = new BotVacancyPreview([
            'source_url' => 'https://source.example/notice/duplicate-copy',
            'attachments' => [],
        ]);
        $differentPublication = $publisher->publish($differentSource, $company, ['Ingenieria'], ['La Paz'], $announcement);
        $this->assertTrue($differentPublication['created']);
        $this->assertSame(2, DB::connection('site')->table('announcements')->count());

        $missingCompany = new Company(['company_name' => 'Empresa que no esta en la web']);
        $missingCompanyPreview = new BotVacancyPreview([
            'source_url' => 'https://source.example/notice/missing-company',
            'attachments' => [],
        ]);
        try {
            $publisher->publish($missingCompanyPreview, $missingCompany, ['Ingenieria'], ['La Paz'], $announcement);
            $this->fail('Expected publication to stop when the company is missing from the website.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('empresa no existe activa en la web', $exception->getMessage());
        }
        $this->assertSame(1, DB::connection('site')->table('companies')->count());
        $this->assertSame(2, DB::connection('site')->table('announcements')->count());
    }

    private function useInMemorySiteDatabase(): void
    {
        config()->set('database.default', 'site');
        config()->set('database.connections.site', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'host' => 'test-host',
            'username' => 'test-user',
            'password' => 'test-password',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('site_publishing.user_id', null);
        config()->set('site_publishing.announcement_type_slug', null);
        DB::purge('site');

        Schema::connection('site')->create('users', function ($table): void {
            $table->string('id')->primary();
            $table->boolean('actived')->default(true);
            $table->softDeletes();
        });
        Schema::connection('site')->create('roles', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
        });
        Schema::connection('site')->create('model_has_roles', function ($table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->string('model_id');
        });
        Schema::connection('site')->create('company_types', function ($table): void {
            $table->id();
            $table->string('company_type_name');
        });
        Schema::connection('site')->create('companies', function ($table): void {
            $table->id();
            $table->string('company_name');
            $table->text('description');
            $table->string('company_image');
            $table->string('user_id')->nullable();
            $table->unsignedBigInteger('company_type_id');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection('site')->create('profesions', function ($table): void {
            $table->id();
            $table->string('profesion_name');
        });
        Schema::connection('site')->create('locations', function ($table): void {
            $table->id();
            $table->string('location_name');
        });
        Schema::connection('site')->create('announcement_types', function ($table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
        });
        Schema::connection('site')->create('announcements', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('announcement_type_id')->nullable();
            $table->text('announce_title');
            $table->text('description');
            $table->dateTime('expiration_time');
            $table->decimal('salary', 10, 0);
            $table->boolean('pro')->default(false);
            $table->dateTime('scheduled_at')->nullable();
            $table->boolean('notification_sent')->default(false);
            $table->string('user_id')->nullable();
            $table->unsignedBigInteger('company_id');
            $table->text('source_url')->nullable();
            $table->char('source_hash', 64)->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection('site')->create('announcement_files', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('announcement_id');
            $table->string('url')->nullable();
            $table->string('original_name')->nullable();
            $table->timestamps();
        });
        Schema::connection('site')->create('announcement_profesion', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('announcement_id');
            $table->unsignedBigInteger('profesion_id');
            $table->timestamps();
        });
        Schema::connection('site')->create('announcement_location', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('announcement_id');
            $table->unsignedBigInteger('location_id');
            $table->string('region')->nullable();
            $table->timestamps();
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies') || Schema::hasColumn('companies', 'site_company_id')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            // This is a remote identifier only. It deliberately has no local FK.
            $table->unsignedBigInteger('site_company_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasColumn('companies', 'site_company_id')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropUnique(['site_company_id']);
            $table->dropColumn('site_company_id');
        });
    }
};

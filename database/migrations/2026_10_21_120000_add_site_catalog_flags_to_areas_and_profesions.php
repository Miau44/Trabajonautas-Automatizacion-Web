<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table): void {
            $table->boolean('site_catalog_active')->default(true)->index();
        });

        Schema::table('profesions', function (Blueprint $table): void {
            $table->boolean('site_catalog_active')->default(true)->index();
        });
    }

    public function down(): void
    {
        Schema::table('profesions', function (Blueprint $table): void {
            $table->dropIndex(['site_catalog_active']);
            $table->dropColumn('site_catalog_active');
        });

        Schema::table('areas', function (Blueprint $table): void {
            $table->dropIndex(['site_catalog_active']);
            $table->dropColumn('site_catalog_active');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_pipeline_settings', function (Blueprint $table): void {
            $table->unsignedInteger('max_generation_attempts_per_day')->default(6);
        });
    }

    public function down(): void
    {
        Schema::table('ai_pipeline_settings', function (Blueprint $table): void {
            $table->dropColumn('max_generation_attempts_per_day');
        });
    }
};

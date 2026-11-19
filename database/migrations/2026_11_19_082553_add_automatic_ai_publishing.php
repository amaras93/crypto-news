<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_pipeline_settings', function (Blueprint $table): void {
            $table->boolean('automatic_publishing')->default(false);
            $table->json('enabled_category_ids')->nullable();
        });
        Schema::table('news_sources', function (Blueprint $table): void {
            $table->boolean('primary_material_permitted')->default(false);
        });
        Schema::table('candidate_evidence', function (Blueprint $table): void {
            $table->unsignedBigInteger('verified_by')->nullable()->change();
            $table->timestamp('verified_at')->nullable()->change();
            $table->string('verification_method', 32)->default('human');
            $table->timestamp('automated_verified_at')->nullable();
            $table->text('retrieval_metadata')->nullable();
        });
        Schema::table('news_candidates', function (Blueprint $table): void {
            $table->string('automation_status', 32)->nullable()->index();
            $table->string('automation_error', 100)->nullable();
            $table->unsignedTinyInteger('automation_attempts')->default(0);
            $table->timestamp('automation_retry_at')->nullable();
        });
        Schema::table('articles', function (Blueprint $table): void {
            $table->string('automation_status', 32)->nullable()->index();
            $table->string('automation_error', 100)->nullable();
        });
        Schema::table('ai_generation_runs', function (Blueprint $table): void {
            $table->boolean('is_automated')->default(false)->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_generation_runs', function (Blueprint $table): void {
            $table->dropIndex(['is_automated']);
            $table->dropColumn('is_automated');
        });
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropIndex(['automation_status']);
            $table->dropColumn(['automation_status', 'automation_error']);
        });
        Schema::table('news_candidates', function (Blueprint $table): void {
            $table->dropIndex(['automation_status']);
            $table->dropColumn(['automation_status', 'automation_error', 'automation_attempts', 'automation_retry_at']);
        });
        Schema::table('candidate_evidence', fn (Blueprint $table) => $table->dropColumn(['verification_method', 'automated_verified_at', 'retrieval_metadata']));
        Schema::table('news_sources', fn (Blueprint $table) => $table->dropColumn('primary_material_permitted'));
        Schema::table('ai_pipeline_settings', fn (Blueprint $table) => $table->dropColumn(['automatic_publishing', 'enabled_category_ids']));
    }
};

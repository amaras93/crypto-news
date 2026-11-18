<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_sources', function (Blueprint $table): void {
            $table->boolean('ai_usage_allowed')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->string('publisher_group', 100)->nullable();
            $table->boolean('is_simulated')->default(false);
        });
        Schema::table('news_candidates', function (Blueprint $table): void {
            $table->boolean('is_simulated')->default(false);
        });
        Schema::table('candidate_source_references', function (Blueprint $table): void {
            $table->timestamp('source_published_at')->nullable();
        });
        Schema::create('ai_pipeline_settings', function (Blueprint $table): void {
            $table->id();
            foreach (['candidate_processing_enabled', 'generation_enabled', 'translation_enabled', 'scheduled_generation_enabled'] as $field) {
                $table->boolean($field)->default(false);
            }
            $table->boolean('emergency_stop')->default(true);
            $table->string('provider', 32)->default('openai');
            $table->string('model', 128)->default('');
            $table->unsignedBigInteger('daily_limit_microusd')->default(0);
            $table->unsignedBigInteger('monthly_limit_microusd')->default(0);
            $table->unsignedSmallInteger('max_articles_per_day')->default(0);
            $table->unsignedTinyInteger('max_concurrent_jobs')->default(1);
            $table->unsignedTinyInteger('max_translation_languages')->default(0);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('candidate_evidence', function (Blueprint $table): void {
            $table->id();
            $table->uuid('claim_key')->unique();
            $table->foreignId('news_candidate_id')->constrained()->restrictOnDelete();
            $table->text('claim');
            $table->json('reference_ids');
            $table->json('entities');
            $table->json('figures');
            $table->boolean('material')->default(true);
            $table->text('review_notes');
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at');
            $table->timestamps();
        });
        Schema::create('ai_generation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->string('work_hash', 64)->unique();
            $table->string('active_key', 64)->nullable()->unique();
            $table->foreignId('parent_run_id')->nullable()->constrained('ai_generation_runs')->nullOnDelete();
            $table->foreignId('news_candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('billing_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('ai_spend_reservations')->restrictOnDelete();
            $table->string('task', 16)->default('english');
            $table->string('locale', 5)->default('en');
            $table->string('section_key', 32)->nullable();
            $table->string('provider', 32);
            $table->string('model', 128);
            $table->boolean('is_simulated')->default(false);
            $table->boolean('editor_regeneration_allowed')->default(false);
            $table->boolean('confirm_human_overwrite')->default(false);
            $table->string('status', 32)->default('queued')->index();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->unsignedTinyInteger('request_count')->default(0);
            $table->string('schema_version', 16)->default('draft-v1');
            $table->string('evidence_digest', 64);
            $table->string('english_digest', 64)->nullable();
            $table->string('target_digest', 64)->nullable();
            $table->longText('evidence_snapshot');
            $table->longText('input_payload')->nullable();
            $table->longText('output_document')->nullable();
            $table->json('quality_report')->nullable();
            $table->json('pricing_snapshot')->nullable();
            $table->string('provider_response_id', 255)->nullable();
            $table->unsignedBigInteger('estimated_microusd')->nullable();
            $table->unsignedBigInteger('actual_microusd')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('cached_input_tokens')->nullable();
            $table->unsignedInteger('cache_write_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->json('simulated_usage')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamp('request_sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('section_applied_at')->nullable();
            $table->timestamps();
            $table->index(['news_candidate_id', 'locale', 'created_at']);
        });
        Schema::table('articles', function (Blueprint $table): void {
            $table->foreignId('ai_candidate_id')->nullable()->unique()->constrained('news_candidates')->restrictOnDelete();
            $table->foreignId('ai_generation_run_id')->nullable()->constrained('ai_generation_runs')->restrictOnDelete();
            $table->boolean('ai_simulated')->default(false);
        });
        Schema::table('article_translations', function (Blueprint $table): void {
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_generation_runs')->restrictOnDelete();
            $table->string('ai_generated_hash', 64)->nullable();
            $table->string('ai_review_hash', 64)->nullable();
            $table->string('ai_review_status', 16)->nullable()->index();
            $table->foreignId('ai_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ai_reviewed_at')->nullable();
            $table->timestamp('ai_human_edited_at')->nullable();
        });
        Schema::create('ai_editorial_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_translation_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_generation_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('content_digest', 64);
            $table->string('evidence_digest', 64);
            $table->text('notes');
            $table->json('quality_report');
            $table->timestamps();
        });
        Schema::table('ai_spend_reservations', function (Blueprint $table): void {
            $table->boolean('counts_article')->default(true);
            $table->unsignedBigInteger('cached_input_tokens')->nullable();
            $table->unsignedBigInteger('cache_write_tokens')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_editorial_reviews');
        Schema::table('article_translations', function (Blueprint $table): void {
            $table->dropIndex('article_translations_ai_review_status_index');
            $table->dropConstrainedForeignId('ai_run_id');
            $table->dropConstrainedForeignId('ai_reviewed_by');
            $table->dropColumn(['ai_generated_hash', 'ai_review_hash', 'ai_review_status', 'ai_reviewed_at', 'ai_human_edited_at']);
        });
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropForeign(['ai_candidate_id']);
            $table->dropUnique('articles_ai_candidate_id_unique');
            $table->dropColumn('ai_candidate_id');
            $table->dropConstrainedForeignId('ai_generation_run_id');
            $table->dropColumn('ai_simulated');
        });
        Schema::dropIfExists('ai_generation_runs');
        Schema::dropIfExists('candidate_evidence');
        Schema::dropIfExists('ai_pipeline_settings');
        Schema::table('candidate_source_references', fn (Blueprint $table) => $table->dropColumn('source_published_at'));
        Schema::table('ai_spend_reservations', fn (Blueprint $table) => $table->dropColumn(['counts_article', 'cached_input_tokens', 'cache_write_tokens']));
        Schema::table('news_candidates', fn (Blueprint $table) => $table->dropColumn('is_simulated'));
        Schema::table('news_sources', fn (Blueprint $table) => $table->dropColumn(['ai_usage_allowed', 'is_primary', 'publisher_group', 'is_simulated']));
    }
};

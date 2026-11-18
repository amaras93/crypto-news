<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('max_candidates_per_run')->default(25);
            $table->unsignedSmallInteger('max_sources_per_run')->default(3);
            $table->unsignedSmallInteger('default_polling_minutes')->default(60);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('news_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('website');
            $table->text('feed_url');
            $table->string('feed_hash', 64)->unique();
            $table->string('source_type', 8);
            $table->string('language', 35);
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('topic', 40)->nullable();
            $table->unsignedTinyInteger('priority')->default(3);
            $table->boolean('enabled')->default(false);
            $table->boolean('summary_permitted')->default(false);
            $table->text('licensing_notes');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedSmallInteger('polling_minutes')->nullable();
            $table->unsignedSmallInteger('item_limit')->default(25);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('next_poll_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->string('last_error', 64)->nullable();
            $table->string('etag', 512)->nullable();
            $table->string('last_modified', 128)->nullable();
            $table->uuid('active_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'next_poll_at']);
        });
        Schema::create('news_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('headline', 500);
            $table->text('original_url');
            $table->text('canonical_url')->nullable();
            $table->string('url_hash', 64)->unique();
            $table->string('headline_hash', 64)->index();
            $table->string('content_fingerprint', 64)->index();
            $table->string('language', 35);
            $table->text('summary')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('discovered_at');
            $table->string('topic', 40)->nullable()->index();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->json('classification');
            $table->string('status', 32)->default('new')->index();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('news_candidates')->nullOnDelete();
            $table->json('duplicate_reasons')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['discovered_at', 'id']);
        });
        Schema::create('ingestion_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_source_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('token')->unique();
            $table->unsignedInteger('source_version');
            $table->string('status', 16)->default('queued')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('item_limit');
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedSmallInteger('items_seen')->default(0);
            $table->unsignedSmallInteger('created_count')->default(0);
            $table->unsignedSmallInteger('linked_count')->default(0);
            $table->unsignedSmallInteger('duplicate_count')->default(0);
            $table->unsignedSmallInteger('invalid_count')->default(0);
            $table->json('item_errors')->nullable();
            $table->timestamps();
            $table->index(['news_source_id', 'created_at']);
        });
        Schema::create('candidate_source_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('news_source_id')->constrained()->restrictOnDelete();
            $table->foreignId('ingestion_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id', 512)->nullable();
            $table->string('external_hash', 64);
            $table->string('language', 35);
            $table->text('original_url');
            $table->string('headline', 500);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['news_source_id', 'external_hash'], 'candidate_source_external_unique');
            $table->index(['news_candidate_id', 'news_source_id']);
        });
        Schema::create('candidate_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('previous_status', 32);
            $table->string('new_status', 32);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_budget_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('period_key', 24)->unique();
            $table->unsignedBigInteger('reserved_microusd')->default(0);
            $table->unsignedBigInteger('actual_microusd')->default(0);
            $table->unsignedInteger('reserved_articles')->default(0);
            $table->unsignedInteger('actual_articles')->default(0);
            $table->timestamps();
        });
        Schema::create('ai_spend_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('news_candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('day_key', 24);
            $table->string('month_key', 24);
            $table->string('provider', 64);
            $table->string('model', 128);
            $table->unsignedBigInteger('estimated_microusd');
            $table->unsignedBigInteger('actual_microusd')->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->string('status', 16)->default('reserved');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_spend_reservations');
        Schema::dropIfExists('ai_budget_periods');
        Schema::dropIfExists('candidate_reviews');
        Schema::dropIfExists('candidate_source_references');
        Schema::dropIfExists('ingestion_runs');
        Schema::dropIfExists('news_candidates');
        Schema::dropIfExists('news_sources');
        Schema::dropIfExists('discovery_settings');
    }
};

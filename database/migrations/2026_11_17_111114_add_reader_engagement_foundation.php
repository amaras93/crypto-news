<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('price_alert_in_app')->default(true);
            $table->boolean('price_alert_email')->default(false);
        });
        Schema::create('watchlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('asset', 40);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'asset']);
            $table->index(['user_id', 'position']);
        });
        Schema::create('saved_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->timestamps();
            $table->unique(['user_id', 'article_id']);
        });
        Schema::create('price_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('asset', 40);
            $table->string('currency', 3)->default('USD');
            $table->string('unit', 20);
            $table->decimal('target_price', 30, 10);
            $table->string('direction', 8);
            $table->boolean('enabled')->default(true);
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->decimal('last_price', 30, 10)->nullable();
            $table->string('last_state', 8)->nullable();
            $table->string('last_provider', 32)->nullable();
            $table->unsignedBigInteger('last_observed_timestamp')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['user_id', 'asset', 'currency', 'unit', 'target_price', 'direction'], 'price_alert_threshold_unique');
            $table->index(['enabled', 'id']);
        });
        Schema::create('price_alert_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('price_alert_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('alert_version');
            $table->unsignedBigInteger('observed_timestamp');
            $table->string('asset', 40);
            $table->string('currency', 3);
            $table->string('unit', 20);
            $table->string('direction', 8);
            $table->decimal('target_price', 30, 10);
            $table->decimal('previous_price', 30, 10);
            $table->decimal('observed_price', 30, 10);
            $table->string('provider', 32);
            $table->timestamps();
            $table->unique(['price_alert_id', 'alert_version', 'observed_timestamp'], 'price_event_observation_unique');
            $table->index(['user_id', 'id']);
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('newsletter_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->text('email');
            $table->string('email_hash', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('locale', 5);
            $table->string('frequency', 8);
            $table->timestamp('consent_at');
            $table->string('consent_version', 32);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('confirmation_token_hash', 64)->nullable();
            $table->timestamp('confirmation_expires_at')->nullable();
            $table->unsignedInteger('confirmation_generation')->default(0);
            $table->string('pending_locale', 5)->nullable();
            $table->string('pending_frequency', 8)->nullable();
            $table->foreignId('pending_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_requested_at')->nullable();
            $table->text('unsubscribe_secret');
            $table->string('unsubscribe_token_hash', 64);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['frequency', 'locale', 'confirmed_at', 'unsubscribed_at'], 'newsletter_audience_index');
        });
        Schema::create('newsletter_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('newsletter_subscriber_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email_hash', 64);
            $table->string('action', 32);
            $table->string('locale', 5);
            $table->string('frequency', 8);
            $table->string('consent_version', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('newsletter_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 5);
            $table->string('frequency', 8);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->json('article_ids');
            $table->json('translation_hashes');
            $table->string('status', 16)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->unsignedBigInteger('audience_cursor')->default(0);
            $table->timestamps();
            $table->unique(['locale', 'frequency', 'period_start'], 'newsletter_period_unique');
        });
        Schema::create('engagement_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('unique_key', 64)->unique();
            $table->string('kind', 24);
            $table->string('channel', 12);
            $table->string('status', 16)->default('queued')->index();
            $table->string('locale', 5);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('newsletter_subscriber_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('newsletter_campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('price_alert_event_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('subscriber_version')->nullable();
            $table->unsignedInteger('confirmation_generation')->nullable();
            $table->string('recipient_hash', 64)->nullable();
            $table->longText('payload')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('dispatch_failures')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('transport', 16)->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });
        Schema::create('engagement_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('price_alerts_enabled')->default(false);
            $table->boolean('newsletters_enabled')->default(false);
            $table->boolean('real_email_enabled')->default(false);
            $table->string('daily_at', 5)->default('08:00');
            $table->unsignedTinyInteger('weekly_day')->default(1);
            $table->string('weekly_at', 5)->default('09:00');
            $table->unsignedSmallInteger('emails_per_minute')->default(60);
            $table->unsignedSmallInteger('batch_size')->default(100);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_settings');
        Schema::dropIfExists('engagement_deliveries');
        Schema::dropIfExists('newsletter_campaigns');
        Schema::dropIfExists('newsletter_consents');
        Schema::dropIfExists('newsletter_subscribers');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('price_alert_events');
        Schema::dropIfExists('price_alerts');
        Schema::dropIfExists('saved_articles');
        Schema::dropIfExists('watchlist_items');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['price_alert_in_app', 'price_alert_email']));
    }
};

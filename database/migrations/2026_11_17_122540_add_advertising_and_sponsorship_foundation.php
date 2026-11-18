<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertisers', function (Blueprint $table): void {
            $table->id();
            $table->string('business_name', 180);
            $table->string('public_brand_name', 120);
            $table->text('contact_information')->nullable();
            $table->string('website', 2048)->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->timestamps();
        });
        Schema::create('ad_placements', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 48)->unique();
            $table->string('name', 120);
            $table->boolean('enabled')->default(false);
            $table->string('provider', 12)->default('direct');
            $table->string('adsense_slot_id', 16)->nullable();
            $table->timestamps();
        });
        Schema::create('ad_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advertiser_id')->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $table->string('banner_image', 255);
            $table->string('creative_hash', 64);
            $table->unsignedSmallInteger('creative_width');
            $table->unsignedSmallInteger('creative_height');
            $table->string('destination_url', 2048);
            $table->json('languages');
            $table->json('alt_text');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 16)->default('draft');
            $table->string('risk_category', 40)->default('general');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->text('contract_amount')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_hash', 64)->nullable();
            $table->json('review_checks')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'starts_at', 'ends_at']);
        });
        Schema::create('ad_campaign_ad_placement', function (Blueprint $table): void {
            $table->foreignId('ad_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_placement_id')->constrained()->restrictOnDelete();
            $table->primary(['ad_campaign_id', 'ad_placement_id']);
        });
        Schema::create('ad_delivery_totals', function (Blueprint $table): void {
            $table->id();
            $table->string('aggregate_key', 64)->unique();
            $table->date('day');
            $table->foreignId('ad_placement_id')->constrained()->restrictOnDelete();
            $table->foreignId('ad_campaign_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('locale', 5);
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('eligible_deliveries')->default(0);
            $table->timestamps();
            $table->index(['day', 'ad_campaign_id']);
        });
        Schema::create('advertising_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->boolean('direct_enabled')->default(false);
            $table->boolean('adsense_enabled')->default(false);
            $table->boolean('adsense_reviewed')->default(false);
            $table->text('provider_review_notes')->nullable();
            $table->text('ads_txt')->nullable();
            $table->boolean('ads_txt_verified')->default(false);
            $table->text('seller_review_notes')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('advertising_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 48);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->longText('details')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'id']);
        });
        Schema::table('articles', function (Blueprint $table): void {
            $table->foreignId('sponsor_id')->nullable()->constrained('advertisers')->restrictOnDelete();
            $table->string('sponsor_classification', 16)->nullable()->index();
            $table->json('sponsorship_disclosures')->nullable();
            $table->foreignId('sponsorship_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sponsorship_reviewed_at')->nullable();
            $table->string('sponsorship_review_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sponsor_id');
            $table->dropConstrainedForeignId('sponsorship_reviewed_by');
            $table->dropColumn(['sponsor_classification', 'sponsorship_disclosures', 'sponsorship_reviewed_at', 'sponsorship_review_hash']);
        });
        Schema::dropIfExists('advertising_audits');
        Schema::dropIfExists('advertising_settings');
        Schema::dropIfExists('ad_delivery_totals');
        Schema::dropIfExists('ad_campaign_ad_placement');
        Schema::dropIfExists('ad_campaigns');
        Schema::dropIfExists('ad_placements');
        Schema::dropIfExists('advertisers');
    }
};

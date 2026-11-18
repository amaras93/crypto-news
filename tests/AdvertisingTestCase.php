<?php

namespace Tests;

use App\Models\AdCampaign;
use App\Models\AdPlacement;
use App\Models\Advertiser;
use App\Models\AdvertisingSetting;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\User;
use App\Support\AdCreative;
use App\Support\AdvertisingManager;
use Database\Seeders\AdPlacementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

abstract class AdvertisingTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
        $this->seed(AdPlacementSeeder::class);
    }

    protected function enableAds(): void
    {
        config(['advertising.enabled' => true, 'advertising.direct_enabled' => true, 'advertising.mode' => 'preview']);
        $settings = AdvertisingSetting::find(1) ?? new AdvertisingSetting;
        $settings->forceFill(['id' => 1, 'enabled' => true, 'direct_enabled' => true])->save();
        AdPlacement::query()->update(['enabled' => true]);
    }

    protected function creative(): string
    {
        return app(AdCreative::class)->store($this->admin, UploadedFile::fake()->image('campaign.png', 728, 90));
    }

    protected function campaignData(array $changes = []): array
    {
        $advertiser = Advertiser::factory()->create(['public_brand_name' => 'Fixture sponsor', 'business_name' => 'Private fixture business', 'status' => 'active']);

        return array_replace(['advertiser_id' => $advertiser->id, 'name' => 'Isolated direct campaign', 'banner_image' => $this->creative(),
            'destination_url' => 'https://example.com/campaign', 'languages' => ['en'], 'alt_text' => ['en' => 'A truthful product announcement'],
            'placement_ids' => AdPlacement::pluck('id')->all(), 'starts_at' => now()->subDay()->utc()->toDateTimeString(), 'ends_at' => now()->addDays(2)->utc()->toDateTimeString(),
            'priority' => 0, 'risk_category' => 'general', 'contract_amount' => '1250.50', 'currency' => 'USD'], $changes);
    }

    protected function reviewData(): array
    {
        return array_fill_keys(AdvertisingManager::reviewFields(), true) + ['notes' => 'Human review fixture: creative, claims, rights, destination, platform policies and all served territories were inspected.'];
    }

    protected function campaign(array $changes = [], bool $approve = true): AdCampaign
    {
        $campaign = app(AdvertisingManager::class)->campaign($this->admin, $this->campaignData($changes));
        if ($approve) {
            app(AdvertisingManager::class)->approve($this->admin, $campaign, $this->reviewData());
        }

        return $campaign->fresh(['placements', 'advertiser', 'approver']);
    }

    protected function story(string $locale = 'en', int $count = 1): Article
    {
        $article = Article::factory()->published()->create();
        ArticleTranslation::factory()->create(['article_id' => $article->id, 'locale' => $locale]);
        for ($index = 1; $index < $count; $index++) {
            Article::factory()->published()->has(ArticleTranslation::factory()->state(['locale' => $locale]), 'translations')->create();
        }

        return $article->fresh(['translations', 'tags']);
    }
}

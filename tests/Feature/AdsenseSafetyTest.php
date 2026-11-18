<?php

namespace Tests\Feature;

use App\Models\AdPlacement;
use App\Models\AdvertisingSetting;
use App\Support\AdvertisingConsent;
use App\Support\AdvertisingSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AdvertisingTestCase;

class AdsenseSafetyTest extends AdvertisingTestCase
{
    public static function blockedConfigurations(): array
    {
        return [['testing', 'live', 'live'], ['local', 'preview', 'live'], ['production', 'preview', 'live'], ['production', 'live', 'demo']];
    }

    #[DataProvider('blockedConfigurations')]
    public function test_external_advertising_is_blocked_outside_production_live_context(string $environment, string $mode, string $marketMode): void
    {
        $this->enableAds();
        $story = $this->story();
        $this->app->instance('env', $environment);
        config(['advertising.mode' => $mode, 'markets.mode' => $marketMode, 'advertising.adsense_enabled' => true, 'advertising.publisher_id' => 'ca-pub-0000000000000000']);
        AdvertisingSetting::findOrFail(1)->forceFill(['adsense_enabled' => true, 'adsense_reviewed' => true, 'provider_review_notes' => str_repeat('Verified account fixture. ', 4), 'updated_by' => $this->admin->id])->save();
        AdPlacement::query()->update(['provider' => 'adsense', 'adsense_slot_id' => '0000000000']);
        $this->get('/en/news/'.$story->translation('en')->slug)->assertOk()->assertDontSee('googlesyndication')->assertDontSee('class="adsbygoogle"', false);
        $this->assertFalse(app(AdvertisingSettings::class)->adsenseReady());
        Http::assertNothingSent();
    }

    public function test_no_certified_cmp_is_fabricated_and_cookie_or_request_flags_do_not_grant_consent(): void
    {
        $consent = app(AdvertisingConsent::class);
        $this->assertNull($consent->providerName());
        $request = Request::create('/en', 'GET', ['consent' => true], ['advertising_consent' => 'true', 'euconsent-v2' => 'invented']);
        $request->attributes->set('ads_consent', true);
        $this->assertFalse($consent->allowsExternalAds($request));
    }

    public function test_complete_operator_configuration_still_emits_no_network_code_without_a_real_cmp_adapter(): void
    {
        $this->enableAds();
        $story = $this->story();
        $this->app->instance('env', 'production');
        config(['advertising.mode' => 'live', 'markets.mode' => 'live', 'advertising.adsense_enabled' => true, 'advertising.publisher_id' => 'ca-pub-0000000000000000']);
        AdvertisingSetting::findOrFail(1)->forceFill(['adsense_enabled' => true, 'adsense_reviewed' => true, 'provider_review_notes' => str_repeat('Account review fixture. ', 4), 'updated_by' => $this->admin->id])->save();
        AdPlacement::query()->update(['provider' => 'adsense', 'adsense_slot_id' => '0000000000']);
        $this->assertTrue(app(AdvertisingSettings::class)->adsenseReady());
        $this->get('/en/news/'.$story->translation('en')->slug)->assertOk()->assertDontSee('googlesyndication')->assertDontSee('adsbygoogle');
        Http::assertNothingSent();
    }

    public function test_invalid_or_blank_publisher_ids_cannot_pass_readiness(): void
    {
        foreach (['', 'pub-123', 'ca-pub-123', 'ca-pub-0000000000000000<script>', 'https://example.com'] as $id) {
            config(['advertising.publisher_id' => $id]);
            $this->assertFalse(app(AdvertisingSettings::class)->validPublisher());
        }
    }

    public function test_adsense_has_no_custom_click_or_impression_tracking_endpoint(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'advertising.'))->map(fn ($route): string => $route->uri())->all();
        $this->assertCount(2, $routes);
        foreach ($routes as $route) {
            $this->assertStringNotContainsString('click', $route);
            $this->assertStringNotContainsString('impression', $route);
        }
    }
}

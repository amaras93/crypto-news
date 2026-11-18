<?php

namespace Tests\Feature;

use App\Models\AdPlacement;
use App\Support\AdvertisingManager;
use App\Support\AdvertisingService;
use App\Support\AdvertisingSettings;
use App\Support\ArticleContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\AdvertisingTestCase;

class DirectAdvertisingTest extends AdvertisingTestCase
{
    public function test_default_off_adds_no_advertisements_or_metrics_even_with_an_approved_campaign(): void
    {
        $this->campaign();
        $this->story(count: 8);
        $this->get('/en')->assertOk()->assertDontSee('data-ad-placement=', false)->assertDontSee('googlesyndication');
        $this->get('/en/news')->assertOk()->assertDontSee('data-ad-placement=', false);
        $this->assertDatabaseCount('ad_delivery_totals', 0);
        Http::assertNothingSent();
    }

    public function test_approved_banner_uses_fixed_local_image_safe_links_and_no_private_terms(): void
    {
        $this->enableAds();
        $campaign = $this->campaign();
        $story = $this->story();
        $response = $this->get('/en/news/'.$story->translation('en')->slug)->assertOk()
            ->assertSee('data-ad-placement="article_before_body"', false)->assertSee(__('advertising.advertisement'))
            ->assertSee('rel="sponsored nofollow noopener noreferrer"', false)->assertSee('referrerpolicy="no-referrer"', false)
            ->assertSee('/storage/'.$campaign->banner_image, false)->assertDontSee('1250.50')->assertDontSee('Private fixture business')->assertDontSee('googlesyndication');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertNothingSent();
    }

    public function test_drafts_missing_creatives_and_inactive_advertisers_are_never_served(): void
    {
        $this->enableAds();
        $campaign = $this->campaign(approve: false);
        $this->story(count: 6);
        $this->get('/en')->assertDontSee('data-ad-placement=', false);
        app(AdvertisingManager::class)->approve($this->admin, $campaign, $this->reviewData());
        $campaign->advertiser->forceFill(['status' => 'paused'])->save();
        $this->get('/en')->assertDontSee('data-ad-placement=', false);
        $campaign->advertiser->forceFill(['status' => 'active'])->save();
        Storage::disk('public')->delete($campaign->banner_image);
        $this->get('/en')->assertDontSee('data-ad-placement=', false);
    }

    public function test_start_and_exclusive_end_dates_are_rechecked_despite_cached_candidate_ids(): void
    {
        $this->enableAds();
        $this->freezeSecond();
        $start = now()->addHour();
        $this->campaign(['starts_at' => $start->toDateTimeString(), 'ends_at' => $start->copy()->addHour()->toDateTimeString()]);
        $this->story(count: 8);
        $this->get('/en')->assertDontSee('data-ad-placement=', false);
        $this->travelTo($start);
        $this->get('/en')->assertSee('data-ad-placement=', false);
        $this->travel(1)->hours();
        $this->get('/en')->assertDontSee('data-ad-placement=', false);
    }

    public function test_language_targeting_and_placement_targeting_are_exact(): void
    {
        $this->enableAds();
        $this->campaign(['languages' => ['fr'], 'alt_text' => ['fr' => 'Annonce du produit vérifiée'], 'placement_ids' => [AdPlacement::where('key', 'article_after_body')->sole()->id]]);
        $english = $this->story();
        $french = $this->story('fr');
        $this->get('/en/news/'.$english->translation('en')->slug)->assertDontSee('data-ad-placement=', false);
        $this->get('/fr/news/'.$french->translation('fr')->slug)->assertSee('data-ad-placement="article_after_body"', false)
            ->assertDontSee('data-ad-placement="article_before_body"', false);
    }

    public function test_global_stop_and_campaign_pause_apply_on_the_next_request(): void
    {
        $this->enableAds();
        $campaign = $this->campaign();
        $this->story(count: 8);
        $this->get('/en')->assertSee('data-ad-placement=', false);
        app(AdvertisingManager::class)->pause($this->admin, $campaign);
        $this->get('/en')->assertDontSee('data-ad-placement=', false);
        app(AdvertisingManager::class)->approve($this->admin, $campaign, $this->reviewData());
        $this->get('/en')->assertSee('data-ad-placement=', false);
        app(AdvertisingSettings::class)->stop($this->admin);
        $this->get('/en')->assertDontSee('data-ad-placement=', false);
    }

    public function test_rendering_has_a_two_slot_density_limit_and_disabled_placements_stay_empty(): void
    {
        $this->enableAds();
        $this->campaign();
        $this->story(count: 12);
        $response = $this->get('/en')->assertOk();
        $this->assertSame(2, substr_count($response->getContent(), 'data-ad-placement='));
        AdPlacement::query()->update(['enabled' => false]);
        $this->get('/en')->assertDontSee('data-ad-placement=', false);
    }

    public function test_priority_is_deterministic_and_all_campaign_edits_require_new_approval(): void
    {
        $this->enableAds();
        $first = $this->campaign();
        $second = $this->campaign(['priority' => 10, 'alt_text' => ['en' => 'Priority campaign fixture']]);
        $story = $this->story();
        $url = '/en/news/'.$story->translation('en')->slug;
        $this->get($url)->assertSee('Priority campaign fixture');
        $data = app(AdvertisingManager::class)->campaignData($second);
        $data['name'] = 'Changed reviewed campaign';
        app(AdvertisingManager::class)->campaign($this->admin, $data, $second);
        $this->assertSame('draft', $second->fresh()->status);
        $this->assertNull($second->fresh()->approved_at);
        $this->get($url)->assertDontSee('Priority campaign fixture')->assertSee('A truthful product announcement');
    }

    public function test_enabled_advertising_never_renders_on_account_auth_admin_or_private_article_preview_pages(): void
    {
        $this->enableAds();
        $this->campaign();
        $article = $this->story();
        foreach (['/en/account', '/en/account/watchlist', '/en/account/newsletter', '/admin', route('editorial.preview', ['article' => $article, 'locale' => 'en'])] as $path) {
            $this->get($path)->assertOk()->assertDontSee('data-ad-placement=', false)->assertDontSee('googlesyndication');
        }
        $this->app['auth']->forgetGuards();
        foreach (['/en/login', '/en/register', '/en/newsletter'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('data-ad-placement=', false)->assertDontSee('googlesyndication');
        }
    }

    public function test_article_insertion_uses_complete_sanitized_sections_and_skips_short_bodies(): void
    {
        $this->enableAds();
        $renderer = app(ArticleContent::class);
        $short = $renderer->render('<p>A short body.</p>');
        $this->assertFalse(app(AdvertisingService::class)->articleSegments($short['html'])['can_split']);
        $body = '<h2>First section</h2>'.str_repeat('<p>'.str_repeat('Factual context. ', 25).'</p>', 3)
            .'<h2>Second section</h2>'.str_repeat('<p>'.str_repeat('More context. ', 25).'</p>', 4);
        $content = $renderer->render($body);
        $segments = app(AdvertisingService::class)->articleSegments($content['html']);
        $this->assertTrue($segments['can_split']);
        $this->assertStringContainsString('article-section-1', (string) $segments['before']);
        $this->assertStringStartsWith('<h2 id="article-section-2">', (string) $segments['after']);
        $this->assertSame((string) $content['html'], (string) $segments['before'].(string) $segments['after']);
    }

    public function test_campaign_mutation_rolls_back_if_its_required_audit_record_cannot_be_written(): void
    {
        $campaign = $this->campaign();
        $settings = \Mockery::mock(AdvertisingSettings::class)->makePartial();
        $settings->shouldReceive('audit')->once()->andThrow(new \RuntimeException('Isolated audit failure'));
        $this->app->instance(AdvertisingSettings::class, $settings);
        try {
            app(AdvertisingManager::class)->pause($this->admin, $campaign);
            $this->fail('An unaudited state change was committed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Isolated audit failure', $exception->getMessage());
        }
        $this->assertSame('active', $campaign->fresh()->status);
    }

    public function test_candidate_selection_does_not_add_queries_for_each_additional_campaign(): void
    {
        $this->enableAds();
        $this->campaign();
        $story = $this->story();
        $url = '/en/news/'.$story->translation('en')->slug;
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $first = count(DB::getQueryLog());
        for ($index = 0; $index < 6; $index++) {
            $this->campaign(['priority' => $index + 1]);
        }
        DB::flushQueryLog();
        $this->get($url)->assertOk();
        $second = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($first + 3, $second);
    }
}

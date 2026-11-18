<?php

namespace Tests\Feature;

use App\ArticleStatus;
use App\Models\Article;
use App\Models\EngagementDelivery;
use App\Models\EngagementSetting;
use App\Models\NewsletterCampaign;
use App\Models\Tag;
use App\Models\User;
use App\Support\EngagementDeliveries;
use App\Support\EngagementSettings;
use App\Support\NewsDigests;
use App\Support\NewsletterSubscriptions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\EngagementTestCase;

class NewsDigestTest extends EngagementTestCase
{
    private function story(string $locale = 'en'): Article
    {
        $article = $this->article($locale);
        $article->update(['published_at' => now()->utc()->startOfDay()->subHours(3)]);

        return $article->fresh(['translations']);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function enable(): void
    {
        config(['engagement.newsletters_enabled' => true]);
        EngagementSetting::updateOrCreate(['id' => 1], ['newsletters_enabled' => true]);
    }

    public function test_digest_contains_only_real_published_localized_stories_and_uses_documented_ranking(): void
    {
        $normal = $this->story();
        $picked = $this->story();
        $pick = Tag::factory()->create(['slug' => 'editors-pick']);
        $picked->tags()->attach($pick);
        $demo = $this->story();
        $demo->tags()->attach(Tag::factory()->create(['slug' => 'cryptonews-demo']));
        $draft = $this->story();
        $draft->update(['status' => ArticleStatus::Draft]);
        $this->story('fr');
        $recent = $this->article();
        $this->assertSame([$picked->id, $normal->id], app(NewsDigests::class)->select('en', 'daily')->modelKeys());
    }

    public function test_build_is_idempotent_and_does_not_send_or_auto_approve(): void
    {
        $this->story();
        $admin = $this->admin();
        $first = app(NewsDigests::class)->build($admin, 'en', 'daily');
        $second = app(NewsDigests::class)->build($admin, 'en', 'daily');
        $this->assertSame($first->id, $second->id);
        $this->assertSame('draft', $first->status);
        $this->assertDatabaseCount('newsletter_campaigns', 1);
        Mail::assertNothingSent();
    }

    public function test_newsletter_sending_is_disabled_by_default_even_for_an_approved_digest(): void
    {
        $this->story();
        $this->subscriber();
        $campaign = app(NewsDigests::class)->build($admin = $this->admin(), 'en', 'daily');
        app(NewsDigests::class)->approve($admin, $campaign);
        $this->assertSame(0, app(NewsDigests::class)->dispatchAudience($campaign));
        $this->assertSame(0, EngagementDelivery::where('kind', 'digest')->count());
    }

    public function test_confirmed_matching_audience_is_queued_once_and_transport_outcomes_are_separate(): void
    {
        $this->enable();
        $this->story();
        $english = $this->subscriber();
        $this->subscriber('ja');
        $this->subscriber('en', 'weekly');
        $campaign = app(NewsDigests::class)->build($admin = $this->admin(), 'en', 'daily');
        app(NewsDigests::class)->approve($admin, $campaign);
        $this->assertSame(1, app(NewsDigests::class)->dispatchAudience($campaign));
        $this->assertSame(0, app(NewsDigests::class)->dispatchAudience($campaign));
        $this->assertSame('audience_queued', $campaign->fresh()->status);
        $delivery = EngagementDelivery::where('kind', 'digest')->sole();
        $this->assertSame($english->id, $delivery->newsletter_subscriber_id);
        $this->assertSame('queued', $delivery->status);
        Mail::assertNothingSent();
        config(['mail.default' => 'log']);
        app(EngagementDeliveries::class)->process($delivery->id);
        $this->assertSame('local', $delivery->fresh()->status);
        Mail::assertSentCount(1);
    }

    public function test_unsubscribe_or_preference_change_before_delivery_cancels_the_queued_digest(): void
    {
        $this->enable();
        $this->story();
        $subscriber = $this->subscriber();
        $campaign = app(NewsDigests::class)->build($admin = $this->admin(), 'en', 'daily');
        app(NewsDigests::class)->approve($admin, $campaign);
        app(NewsDigests::class)->dispatchAudience($campaign);
        app(NewsletterSubscriptions::class)->unsubscribe($subscriber);
        $delivery = EngagementDelivery::where('kind', 'digest')->sole();
        app(EngagementDeliveries::class)->process($delivery->id);
        $this->assertSame('skipped', $delivery->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_unpublished_and_edited_stories_are_rechecked_at_send_time(): void
    {
        $this->enable();
        $story = $this->story();
        $this->subscriber();
        $campaign = app(NewsDigests::class)->build($admin = $this->admin(), 'en', 'daily');
        app(NewsDigests::class)->approve($admin, $campaign);
        app(NewsDigests::class)->dispatchAudience($campaign);
        $story->translation('en')->update(['title' => 'Edited after campaign approval']);
        $delivery = EngagementDelivery::where('kind', 'digest')->sole();
        app(EngagementDeliveries::class)->process($delivery->id);
        $this->assertSame('skipped', $delivery->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_empty_japanese_digest_does_not_fall_back_to_english(): void
    {
        $this->enable();
        $this->story();
        $this->subscriber('ja');
        $campaign = app(NewsDigests::class)->build($this->admin(), 'ja', 'daily');
        $this->assertSame([], $campaign->article_ids);
        $this->assertSame(0, app(NewsDigests::class)->dispatchAudience($campaign));
        $this->assertSame(0, EngagementDelivery::where('kind', 'digest')->count());
    }

    public function test_digest_preview_is_offline_and_does_not_create_delivery_records(): void
    {
        $this->story();
        $this->artisan('engagement:preview', ['--locale' => 'en'])->assertSuccessful();
        $this->assertDatabaseCount('newsletter_campaigns', 0);
        $this->assertDatabaseCount('engagement_deliveries', 0);
        Mail::assertNothingSent();
    }

    public function test_weekly_period_is_the_previous_complete_utc_week(): void
    {
        $period = app(NewsDigests::class)->period('weekly', CarbonImmutable::parse('2026-11-17 14:00:00 UTC'));
        $this->assertSame('2026-11-09 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-16 00:00:00', $period['end']->format('Y-m-d H:i:s'));
    }

    public function test_reader_cannot_build_or_approve_a_campaign(): void
    {
        $this->expectException(HttpException::class);
        app(NewsDigests::class)->build($this->reader, 'en', 'daily');
    }

    public function test_scheduled_campaigns_require_server_and_administrator_activation_and_queue_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00 UTC'));
        $this->story();
        $this->subscriber();
        $this->assertSame(0, app(NewsDigests::class)->queueScheduled());
        $this->assertDatabaseCount('newsletter_campaigns', 0);
        config(['engagement.newsletters_enabled' => true]);
        $this->assertSame(0, app(NewsDigests::class)->queueScheduled());
        $settings = app(EngagementSettings::class);
        $settings->save($admin = $this->admin(), array_replace($settings->current(), ['newsletters_enabled' => true]));
        $this->assertSame(1, app(NewsDigests::class)->queueScheduled());
        $this->assertSame(0, app(NewsDigests::class)->queueScheduled());
        $this->assertSame(1, EngagementDelivery::where('kind', 'digest')->count());
        $this->assertSame('audience_queued', NewsletterCampaign::where('locale', 'en')->sole()->status);
        $settings->stop($admin);
        $this->assertSame(0, app(NewsDigests::class)->queueScheduled());
        Mail::assertNothingSent();
    }

    public function test_audience_batches_resume_from_a_cursor_without_duplicate_deliveries(): void
    {
        $this->enable();
        $this->story();
        for ($index = 0; $index < 5; $index++) {
            $this->subscriber();
        }
        config(['engagement.batch_size' => 2]);
        $campaign = app(NewsDigests::class)->build($admin = $this->admin(), 'en', 'daily');
        app(NewsDigests::class)->approve($admin, $campaign);
        foreach ([2, 2, 1, 0] as $count) {
            $this->assertSame($count, app(NewsDigests::class)->dispatchAudience($campaign));
        }
        $this->assertSame(5, EngagementDelivery::where('kind', 'digest')->count());
        $this->assertSame('audience_queued', $campaign->fresh()->status);
    }

    public function test_real_content_selection_is_bounded_and_excludes_unreviewed_ai_and_future_publications(): void
    {
        for ($index = 0; $index < 10; $index++) {
            $this->story();
        }
        $ai = $this->story();
        $ai->forceFill(['ai_simulated' => true])->save();
        $this->assertSame(6, app(NewsDigests::class)->select('en', 'daily')->count());
        $this->assertNotContains($ai->id, app(NewsDigests::class)->select('en', 'daily')->modelKeys());
        $future = $this->article();
        $future->update(['published_at' => now()->addDay()]);
        $this->assertNotContains($future->id, app(NewsDigests::class)->select('en', 'daily')->modelKeys());
    }

    public function test_preference_version_changes_invalidate_queued_digest_and_campaign_cancellation_stops_send(): void
    {
        $this->enable();
        $this->story();
        $subscriber = $this->subscriber(actor: $this->reader);
        $campaign = app(NewsDigests::class)->build($admin = $this->admin(), 'en', 'daily');
        app(NewsDigests::class)->approve($admin, $campaign);
        app(NewsDigests::class)->dispatchAudience($campaign);
        $delivery = EngagementDelivery::where('kind', 'digest')->sole();
        app(NewsletterSubscriptions::class)->preferences($this->reader, ['locale' => 'ja', 'frequency' => 'weekly']);
        app(EngagementDeliveries::class)->process($delivery->id);
        $this->assertSame('skipped', $delivery->fresh()->status);
        app(NewsDigests::class)->cancel($admin, $campaign);
        $this->assertSame(0, app(NewsDigests::class)->dispatchAudience($campaign));
        Mail::assertNothingSent();
    }
}

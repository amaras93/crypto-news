<?php

namespace Tests\Feature;

use App\Filament\Pages\EngagementControlCenter;
use App\Filament\Resources\EngagementDeliveries\Pages\ManageEngagementDeliveries;
use App\Filament\Resources\NewsletterCampaigns\Pages\ManageNewsletterCampaigns;
use App\Filament\Resources\NewsletterSubscribers\NewsletterSubscriberResource;
use App\Filament\Resources\NewsletterSubscribers\Pages\ManageNewsletterSubscribers;
use App\Filament\Resources\PriceAlerts\Pages\ManagePriceAlerts;
use App\Models\EngagementDelivery;
use App\Models\EngagementSetting;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterSubscriber;
use App\Models\PriceAlert;
use App\Models\User;
use App\Support\EngagementSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\EngagementTestCase;

class EngagementAdministrationTest extends EngagementTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function paths(): array
    {
        return ['/admin/engagement-control-center', '/admin/newsletter-subscribers', '/admin/newsletter-campaigns', '/admin/price-alerts', '/admin/engagement-deliveries'];
    }

    public function test_administrators_can_open_engagement_pages_with_real_records(): void
    {
        $this->subscriber('ja');
        $this->alert();
        $this->actingAs(User::factory()->admin()->create());
        foreach ($this->paths() as $path) {
            $this->get($path)->assertOk();
        }
        Livewire::test(ManageNewsletterSubscribers::class)->assertCanSeeTableRecords(NewsletterSubscriber::all());
        Livewire::test(ManageEngagementDeliveries::class)->assertCanSeeTableRecords(EngagementDelivery::all());
        Livewire::test(ManagePriceAlerts::class)->assertCanSeeTableRecords(PriceAlert::all());
    }

    public function test_readers_and_editors_cannot_access_engagement_administration(): void
    {
        foreach ([$this->reader, User::factory()->editor()->create()] as $user) {
            $this->actingAs($user);
            foreach ($this->paths() as $path) {
                $this->get($path)->assertForbidden();
            }
            Livewire::test(EngagementControlCenter::class)->assertForbidden();
        }
    }

    public function test_native_controls_obey_server_flags_and_emergency_stop(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Livewire::test(EngagementControlCenter::class)->fillForm([
            'newsletters_enabled' => true, 'price_alerts_enabled' => true, 'real_email_enabled' => true,
            'daily_at' => '07:30', 'weekly_at' => '10:15', 'weekly_day' => 2, 'emails_per_minute' => 30, 'batch_size' => 25,
        ])->call('save')->assertHasNoFormErrors();
        $this->assertFalse(app(EngagementSettings::class)->enabled('newsletters_enabled'));
        $this->assertSame($admin->id, EngagementSetting::findOrFail(1)->updated_by);
        config(['engagement.newsletters_enabled' => true]);
        $this->assertTrue(app(EngagementSettings::class)->enabled('newsletters_enabled'));
        Livewire::test(EngagementControlCenter::class)->call('stop')->assertHasNoErrors();
        $this->assertFalse(app(EngagementSettings::class)->enabled('newsletters_enabled'));
        $this->assertFalse(EngagementSetting::findOrFail(1)->real_email_enabled);
        Livewire::test(EngagementControlCenter::class)->fillForm(['daily_at' => '25:90'])->call('save')->assertHasFormErrors(['daily_at']);
    }

    public function test_native_subscriber_actions_allow_withdrawal_but_never_manufacture_consent(): void
    {
        $subscriber = $this->subscriber();
        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(ManageNewsletterSubscribers::class)->callTableAction('unsubscribe', $subscriber)->assertHasNoErrors();
        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);
        $this->assertFalse(NewsletterSubscriberResource::canCreate());
        Mail::assertNothingSent();
    }

    public function test_native_campaign_build_approval_preview_and_cancellation_are_private(): void
    {
        $story = $this->article();
        $story->update(['published_at' => now()->utc()->startOfDay()->subHour()]);
        $this->actingAs($admin = User::factory()->admin()->create());
        Livewire::test(ManageNewsletterCampaigns::class)->callAction('build_digest', data: ['locale' => 'en', 'frequency' => 'daily'])->assertHasNoErrors();
        $campaign = NewsletterCampaign::sole();
        Livewire::test(ManageNewsletterCampaigns::class)->callTableAction('approve', $campaign)->assertHasNoErrors();
        $this->assertSame($admin->id, $campaign->fresh()->approved_by);
        $url = route('engagement.preview', ['campaign' => $campaign]);
        $this->get($url)->assertOk()->assertSee($story->translation('en')->title)->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->actingAs($this->reader)->get($url)->assertForbidden();
        $this->actingAs($admin);
        Livewire::test(ManageNewsletterCampaigns::class)->callTableAction('cancel', $campaign)->assertHasNoErrors();
        $this->assertSame('cancelled', $campaign->fresh()->status);
        $this->assertDatabaseCount('engagement_deliveries', 0);
    }

    public function test_only_verified_administrators_can_queue_a_test_to_their_own_address(): void
    {
        $campaign = NewsletterCampaign::factory()->create();
        $this->actingAs($admin = User::factory()->admin()->create());
        Livewire::test(ManageNewsletterCampaigns::class)->callTableAction('test_to_self', $campaign)->assertHasNoErrors();
        $delivery = EngagementDelivery::sole();
        $this->assertSame('admin_test', $delivery->kind);
        $this->assertSame($admin->id, $delivery->user_id);
        $this->assertNull($delivery->newsletter_subscriber_id);
        Mail::assertNothingSent();
    }

    public function test_control_center_metrics_are_stored_counts_without_invented_activity(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $stats = app(EngagementControlCenter::class)->statistics();
        foreach (['Confirmed subscribers', 'Daily subscriptions', 'Weekly subscriptions', 'Active personal watchlists', 'Active price alerts', 'In-app notifications stored'] as $key) {
            $this->assertSame(0, $stats[$key]);
        }
        $this->assertSame('Disabled', $stats['Scheduled newsletters']);
    }
}

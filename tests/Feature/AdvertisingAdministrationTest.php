<?php

namespace Tests\Feature;

use App\Filament\Pages\AdvertisingControlCenter;
use App\Filament\Resources\AdCampaigns\Pages\ManageAdCampaigns;
use App\Filament\Resources\AdPlacements\Pages\ManageAdPlacements;
use App\Filament\Resources\Advertisers\Pages\ManageAdvertisers;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Models\AdCampaign;
use App\Models\AdPlacement;
use App\Models\Advertiser;
use App\Models\AdvertisingSetting;
use App\Models\User;
use App\Support\AdvertisingSettings;
use App\Support\ArticleEditor;
use App\Support\ArticlePublication;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\AdvertisingTestCase;

class AdvertisingAdministrationTest extends AdvertisingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function paths(): array
    {
        return ['/admin/advertising-control-center', '/admin/advertisers', '/admin/ad-placements', '/admin/ad-campaigns', '/admin/sponsored-contents', '/admin/ad-delivery-totals'];
    }

    public function test_administrators_can_open_all_advertising_destinations_with_real_records(): void
    {
        $this->campaign();
        foreach ($this->paths() as $path) {
            $this->get($path)->assertOk();
        }
        Livewire::test(ManageAdCampaigns::class)->assertCanSeeTableRecords(AdCampaign::all());
        Livewire::test(ManageAdPlacements::class)->assertCanSeeTableRecords(AdPlacement::all());
    }

    public function test_readers_and_editors_cannot_access_advertising_administration(): void
    {
        foreach ([User::factory()->create(), User::factory()->editor()->create()] as $user) {
            $this->actingAs($user);
            foreach ($this->paths() as $path) {
                $this->get($path)->assertForbidden();
            }
            Livewire::test(AdvertisingControlCenter::class)->assertForbidden();
        }
    }

    public function test_native_advertiser_form_uses_admin_validation_and_encrypted_private_fields(): void
    {
        Livewire::test(ManageAdvertisers::class)->callAction('create', data: ['business_name' => 'Actual fixture company', 'public_brand_name' => 'Fixture public brand',
            'contact_information' => 'Private fixture contact', 'website' => 'https://example.com/', 'internal_notes' => 'Private contract note', 'status' => 'pending'])->assertHasNoErrors();
        $advertiser = Advertiser::sole();
        $this->assertSame('pending', $advertiser->status);
        $this->assertNotSame('Private fixture contact', $advertiser->getRawOriginal('contact_information'));
        Livewire::test(ManageAdvertisers::class)->callTableAction('edit', $advertiser, data: ['business_name' => 'Actual fixture company', 'public_brand_name' => 'Fixture public brand',
            'website' => 'javascript:alert(1)', 'status' => 'active'])->assertHasFormErrors();
    }

    public function test_native_campaign_form_approval_and_pause_follow_the_review_service(): void
    {
        $data = $this->campaignData();
        $data['banner_image'] = [$data['banner_image']];
        Livewire::test(ManageAdCampaigns::class)->callAction('create', data: $data)->assertHasNoErrors();
        $campaign = AdCampaign::sole();
        $this->assertSame('draft', $campaign->status);
        Livewire::test(ManageAdCampaigns::class)->callTableAction('approve', $campaign, data: $this->reviewData())->assertHasNoErrors();
        $this->assertSame('active', $campaign->fresh()->status);
        Livewire::test(ManageAdCampaigns::class)->callTableAction('pause', $campaign)->assertHasNoErrors();
        $this->assertSame('paused', $campaign->fresh()->status);
    }

    public function test_native_placement_settings_cannot_change_server_geometry_or_registry_key(): void
    {
        $placement = AdPlacement::where('key', 'article_before_body')->sole();
        Livewire::test(ManageAdPlacements::class)->callTableAction('edit', $placement, data: ['name' => 'Reviewed article placement', 'enabled' => true, 'provider' => 'direct', 'adsense_slot_id' => null])->assertHasNoErrors();
        $this->assertSame('article_before_body', $placement->fresh()->key);
        $this->assertTrue($placement->fresh()->enabled);
    }

    public function test_native_control_center_obeys_server_flags_and_records_shutdown(): void
    {
        Livewire::test(AdvertisingControlCenter::class)->fillForm(['enabled' => true, 'direct_enabled' => true])->call('save')->assertHasNoFormErrors();
        $this->assertFalse(app(AdvertisingSettings::class)->enabled());
        Livewire::test(AdvertisingControlCenter::class)->call('stop')->assertHasNoErrors();
        $this->assertFalse(AdvertisingSetting::findOrFail(1)->enabled);
        $this->assertDatabaseCount('advertising_audits', 2);
        Livewire::test(AdvertisingControlCenter::class)->assertSee('Unavailable — not measured')->assertSee('Not connected');
    }

    public function test_native_sponsored_article_workflow_keeps_current_review_when_approval_saves_unchanged_form(): void
    {
        $sponsor = Advertiser::factory()->create(['status' => 'active']);
        $article = app(ArticleEditor::class)->save($this->admin, ['author_id' => $this->admin->id, 'translations' => ['en' => [
            'enabled' => true, 'title' => 'Native paid story', 'slug' => 'native-paid-story', 'body' => '<p>A saved paid story with factual information.</p>']],
            'sponsorship' => ['classification' => 'sponsored', 'sponsor_id' => $sponsor->id, 'disclosures' => ['en' => 'The named sponsor paid for this article; the financial relationship is disclosed.']]]);
        app(ArticlePublication::class)->transition($this->admin, $article, 'submit_review');
        Livewire::test(EditArticle::class, ['record' => $article->id])->callAction('review_sponsorship', data: $this->reviewData())->assertHasNoFormErrors();
        Livewire::test(EditArticle::class, ['record' => $article->id])->callAction('approve')->assertHasNoFormErrors();
        Livewire::test(EditArticle::class, ['record' => $article->id])->callAction('publish')->assertHasNoFormErrors();
        $this->get('/en/news/native-paid-story')->assertOk()->assertSee(__('advertising.sponsored'));
    }

    public function test_private_campaign_preview_is_authorized_noindex_and_does_not_record_metrics(): void
    {
        $campaign = $this->campaign(approve: false);
        $url = route('advertising.preview', ['campaign' => $campaign, 'locale' => 'en']);
        $this->get($url)->assertOk()->assertSee(__('advertising.preview'))->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertDontSee('1250.50')->assertDontSee('googlesyndication');
        $this->assertDatabaseCount('ad_delivery_totals', 0);
        $this->actingAs(User::factory()->editor()->create())->get($url)->assertForbidden();
    }
}

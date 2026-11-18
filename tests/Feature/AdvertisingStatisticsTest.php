<?php

namespace Tests\Feature;

use App\Models\AdDeliveryTotal;
use App\Support\AdvertisingStatisticsService;
use Illuminate\Support\Facades\Schema;
use Tests\AdvertisingTestCase;

class AdvertisingStatisticsTest extends AdvertisingTestCase
{
    public function test_reports_count_origin_requests_and_eligible_html_without_claiming_visible_impressions(): void
    {
        $this->enableAds();
        $this->campaign();
        $story = $this->story();
        $this->get('/en/news/'.$story->translation('en')->slug)->assertOk();
        $summary = app(AdvertisingStatisticsService::class)->summary();
        $this->assertSame(2, $summary['slot_requests']);
        $this->assertSame(2, $summary['eligible_server_deliveries']);
        foreach (['visible_impressions', 'clicks', 'ctr', 'revenue', 'adsense_revenue'] as $metric) {
            $this->assertNull($summary[$metric]);
        }
        $this->assertSame(4, AdDeliveryTotal::count());
    }

    public function test_no_fill_requests_do_not_become_impressions_or_fake_deliveries(): void
    {
        $this->enableAds();
        $story = $this->story();
        $this->get('/en/news/'.$story->translation('en')->slug)->assertOk();
        $summary = app(AdvertisingStatisticsService::class)->summary();
        $this->assertSame(3, $summary['slot_requests']);
        $this->assertSame(0, $summary['eligible_server_deliveries']);
        $this->assertNull($summary['revenue']);
    }

    public function test_aggregates_have_no_visitor_identifiers_and_increment_one_row_per_day_dimension(): void
    {
        $this->enableAds();
        $this->campaign();
        $story = $this->story();
        $url = '/en/news/'.$story->translation('en')->slug;
        $this->get($url);
        $this->get($url);
        $this->assertSame(4, (int) AdDeliveryTotal::sum('eligible_deliveries'));
        $this->assertSame(4, AdDeliveryTotal::count());
        foreach (['ip_address', 'user_id', 'session_id', 'user_agent', 'referrer', 'cookie_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('ad_delivery_totals', $column));
        }
    }
}

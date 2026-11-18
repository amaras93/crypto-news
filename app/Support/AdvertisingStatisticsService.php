<?php

namespace App\Support;

use App\Models\AdDeliveryTotal;
use App\Models\AdPlacement;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdvertisingStatisticsService
{
    /** Counts origin slot requests and eligible HTML deliveries, never visible impressions or clicks. */
    public function record(AdPlacement $placement, string $locale, ?int $campaignId): void
    {
        if (! array_key_exists($locale, config('news.locales'))) {
            return;
        }
        $seen = request()->attributes->get('advertising_metric_slots', []);
        if (in_array($placement->id, $seen, true)) {
            return;
        }
        request()->attributes->set('advertising_metric_slots', [...$seen, $placement->id]);
        try {
            $this->increment($placement->id, null, $locale, 'requests');
            if ($campaignId !== null) {
                $this->increment($placement->id, $campaignId, $locale, 'eligible_deliveries');
            }
        } catch (Throwable) {
            report(new \RuntimeException('Direct advertising aggregate unavailable; no visitor data recorded.'));
        }
    }

    private function increment(int $placementId, ?int $campaignId, string $locale, string $column): void
    {
        $day = now()->utc()->toDateString();
        $key = hash('sha256', $day.'|'.$placementId.'|'.($campaignId ?? 0).'|'.$locale);
        DB::transaction(function () use ($key, $day, $placementId, $campaignId, $locale, $column): void {
            DB::table('ad_delivery_totals')->insertOrIgnore(['aggregate_key' => $key, 'day' => $day, 'ad_placement_id' => $placementId,
                'ad_campaign_id' => $campaignId, 'locale' => $locale, 'requests' => 0, 'eligible_deliveries' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ad_delivery_totals')->where('aggregate_key', $key)->increment($column, 1, ['updated_at' => now()]);
        });
    }

    /** @return array{slot_requests: int, eligible_server_deliveries: int, visible_impressions: null, clicks: null, ctr: null, revenue: null, adsense_revenue: null} */
    public function summary(): array
    {
        return ['slot_requests' => (int) AdDeliveryTotal::sum('requests'), 'eligible_server_deliveries' => (int) AdDeliveryTotal::sum('eligible_deliveries'),
            'visible_impressions' => null, 'clicks' => null, 'ctr' => null, 'revenue' => null, 'adsense_revenue' => null];
    }
}

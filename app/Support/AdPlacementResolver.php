<?php

namespace App\Support;

use App\Models\AdCampaign;
use App\Models\AdPlacement;
use Illuminate\Database\Eloquent\Collection;

class AdPlacementResolver
{
    public function __construct(private AdvertisingSettings $settings, private CampaignEligibilityService $eligibility) {}

    /** @return Collection<int, AdPlacement> */
    public function placements(): Collection
    {
        $request = request();
        if (! $request->attributes->has('advertising_placements')) {
            $request->attributes->set('advertising_placements', AdPlacement::where('enabled', true)->get()->keyBy('key'));
        }

        return $request->attributes->get('advertising_placements');
    }

    public function resolve(AdPlacement $placement, string $locale): ?AdCampaign
    {
        $request = request();
        if (! $request->attributes->has('advertising_campaign_pool')) {
            $revision = $this->settings->current()['revision'];
            $ids = app(NewsDiscovery::class)->cache()->remember('advertising:campaigns:'.$revision.':'.$locale, config('advertising.cache_seconds'),
                fn (): array => AdCampaign::where('status', 'active')->whereNotNull('approved_at')->where('ends_at', '>', now())
                    ->whereJsonContains('languages', $locale)->orderByDesc('priority')->orderBy('id')->pluck('id')->all());
            $pool = AdCampaign::whereIn('id', $ids)->with(['advertiser:id,public_brand_name,status', 'approver:id,is_admin', 'placements'])
                ->orderByDesc('priority')->orderBy('id')->get();
            $request->attributes->set('advertising_campaign_pool', $pool);
        }

        return $request->attributes->get('advertising_campaign_pool')->first(fn (AdCampaign $campaign): bool => $this->eligibility->eligible($campaign, $placement, $locale));
    }
}

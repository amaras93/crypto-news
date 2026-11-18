<?php

namespace App\Support;

use App\Models\AdCampaign;
use App\Models\AdPlacement;
use Illuminate\Support\Facades\Storage;

class CampaignEligibilityService
{
    public function digest(AdCampaign $campaign): string
    {
        $languages = $campaign->languages;
        sort($languages);
        $placements = $campaign->placements->pluck('id')->sort()->values()->all();
        $alt = $campaign->alt_text;
        ksort($alt);

        return hash('sha256', json_encode([$campaign->advertiser_id, $campaign->name, $campaign->banner_image,
            $campaign->creative_hash, $campaign->creative_width, $campaign->creative_height, $campaign->destination_url, $languages, $alt,
            $campaign->starts_at->toIso8601String(), $campaign->ends_at->toIso8601String(), $campaign->priority, $campaign->risk_category, $placements], JSON_THROW_ON_ERROR));
    }

    public function eligible(AdCampaign $campaign, AdPlacement $placement, string $locale): bool
    {
        return $campaign->status === 'active' && $campaign->approved_at !== null && $campaign->approver?->is_admin
            && $campaign->advertiser?->status === 'active' && $campaign->risk_category !== 'unverified_offer'
            && $campaign->starts_at->lessThanOrEqualTo(now()) && $campaign->ends_at->greaterThan(now())
            && in_array($locale, $campaign->languages, true) && filled($campaign->alt_text[$locale] ?? null)
            && $campaign->placements->contains('id', $placement->id) && $placement->enabled && $placement->provider === 'direct'
            && $campaign->approval_hash !== null && hash_equals($campaign->approval_hash, $this->digest($campaign))
            && preg_match('#\Aadvertising/creatives/[a-f0-9-]{36}\.png\z#D', $campaign->banner_image)
            && Storage::disk('public')->exists($campaign->banner_image);
    }
}

<?php

namespace App\Support;

use App\Models\AdCampaign;
use App\Models\AdPlacement;
use App\Models\Advertiser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdvertisingManager
{
    public function __construct(private AdvertisingSettings $settings, private AdCreative $creatives, private FeedUrlSafety $urls) {}

    public function safeUrl(string $url, string $field = 'destination_url'): string
    {
        try {
            return $this->urls->inspect($url)['url'];
        } catch (FeedFailure) {
            throw ValidationException::withMessages([$field => __('advertising.invalid_url')]);
        }
    }

    public function advertiser(User $actor, array $data, ?Advertiser $advertiser = null): Advertiser
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        $values = Validator::make($data, ['business_name' => ['required', 'string', 'max:180', 'not_regex:/[<>\p{C}]/u'],
            'public_brand_name' => ['required', 'string', 'max:120', 'not_regex:/[<>\p{C}]/u'], 'contact_information' => ['nullable', 'string', 'max:3000'],
            'website' => ['nullable', 'string', 'max:2048'], 'internal_notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(['pending', 'active', 'paused'])]])->validate();
        if (filled($values['website'] ?? null)) {
            $values['website'] = $this->safeUrl($values['website'], 'website');
        }

        return DB::transaction(function () use ($actor, $values, $advertiser): Advertiser {
            $record = $advertiser ? Advertiser::query()->lockForUpdate()->findOrFail($advertiser->id) : new Advertiser;
            $before = $record->getAttributes();
            $record->forceFill($values)->save();
            $this->settings->changed();
            $this->settings->audit($actor, $advertiser ? 'advertiser.updated' : 'advertiser.created', 'advertiser', $record->id, ['before' => $before, 'after' => $values]);

            return $record;
        });
    }

    public function placement(User $actor, AdPlacement $placement, array $data): AdPlacement
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        $values = Validator::make($data, ['name' => ['required', 'string', 'max:120'], 'enabled' => ['required', 'boolean'],
            'provider' => ['required', Rule::in(['direct', 'adsense'])], 'adsense_slot_id' => ['nullable', 'regex:/\A[0-9]{6,16}\z/D'], 'key' => ['prohibited']])->validate();
        abort_unless(array_key_exists($placement->key, config('advertising.placements')), 422);

        return DB::transaction(function () use ($actor, $placement, $values): AdPlacement {
            $record = AdPlacement::query()->lockForUpdate()->findOrFail($placement->id);
            $before = $record->getAttributes();
            $record->forceFill($values)->save();
            $this->settings->changed();
            $this->settings->audit($actor, 'placement.updated', 'placement', $record->id, ['before' => $before, 'after' => $values]);

            return $record;
        });
    }

    public function campaign(User $actor, array $data, ?AdCampaign $campaign = null): AdCampaign
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        $values = Validator::make($data, [
            'advertiser_id' => ['required', 'integer', 'exists:advertisers,id'], 'name' => ['required', 'string', 'max:180'],
            'banner_image' => ['required', 'string', 'max:255'], 'destination_url' => ['required', 'string', 'max:2048'],
            'languages' => ['required', 'array', 'min:1', 'max:8'], 'languages.*' => ['required', 'distinct', Rule::in(array_keys(config('news.locales')))],
            'alt_text' => ['required', 'array:'.implode(',', array_keys(config('news.locales')))], 'alt_text.*' => ['nullable', 'string', 'max:240', 'not_regex:/[<>\p{C}]/u'],
            'placement_ids' => ['required', 'array', 'min:1', 'max:9'], 'placement_ids.*' => ['required', 'integer', 'distinct', 'exists:ad_placements,id'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'priority' => ['required', 'integer', 'between:0,1000'], 'risk_category' => ['required', Rule::in(array_keys(config('advertising.risk_categories')))],
            'contract_amount' => ['nullable', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/D'], 'currency' => ['required', 'regex:/\A[A-Z]{3}\z/D'],
            'status' => ['prohibited'], 'approved_by' => ['prohibited'], 'approved_at' => ['prohibited'], 'approval_hash' => ['prohibited'],
        ])->validate();
        foreach ($values['languages'] as $locale) {
            if (trim($values['alt_text'][$locale] ?? '') === '') {
                throw ValidationException::withMessages(['alt_text.'.$locale => 'Provide truthful creative text for every targeted language.']);
            }
        }
        $values['destination_url'] = $this->safeUrl($values['destination_url']);
        $image = $this->creatives->inspect($values['banner_image']);
        $ids = $values['placement_ids'];
        unset($values['placement_ids']);
        $values['alt_text'] = array_intersect_key($values['alt_text'], array_flip($values['languages']));

        return DB::transaction(function () use ($actor, $values, $image, $ids, $campaign): AdCampaign {
            $record = $campaign ? AdCampaign::query()->lockForUpdate()->findOrFail($campaign->id) : new AdCampaign;
            $before = $record->getAttributes();
            $record->forceFill($values + $image + ['status' => 'draft', 'approved_by' => null, 'approved_at' => null,
                'approval_hash' => null, 'review_checks' => null, 'review_notes' => null])->save();
            $record->placements()->sync($ids);
            $this->settings->changed();
            $this->settings->audit($actor, $campaign ? 'campaign.updated' : 'campaign.created', 'campaign', $record->id, ['before' => $before, 'after' => $values, 'placements' => $ids]);

            return $record->fresh(['placements', 'advertiser', 'approver']);
        });
    }

    public static function reviewFields(): array
    {
        return ['rights_confirmed', 'claims_truthful', 'no_guaranteed_returns', 'all_served_territories_reviewed', 'platform_policies_reviewed'];
    }

    public function review(array $data): array
    {
        $rules = array_fill_keys(self::reviewFields(), ['accepted']);

        return Validator::make($data, $rules + ['notes' => ['required', 'string', 'min:50', 'max:4000']])->validate();
    }

    public function assertNoGuarantees(string $text): void
    {
        if (preg_match('/(?:guaranteed\s+(?:profit|return)|risk.free\s+(?:invest|profit)|гарантирован\p{L}*\s+(?:прибыл|доход)|rendement\s+garanti|garantiert\p{L}*\s+(?:Gewinn|Rendite)|(?:beneficio|rendimiento)\s+garantizado|(?:profitto|rendimento)\s+garantito|(?:利益|利回り).{0,4}保証|(?:保证|保證).{0,4}(?:收益|回报|回報))/iu', $text)) {
            throw ValidationException::withMessages(['notes' => 'Guaranteed profit/return or misleading risk-free investment claims are prohibited. Review the image itself as well.']);
        }
    }

    public function approve(User $actor, AdCampaign $campaign, array $data): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        $checks = $this->review($data);
        DB::transaction(function () use ($actor, $campaign, $checks): void {
            $record = AdCampaign::query()->lockForUpdate()->with(['advertiser', 'placements'])->findOrFail($campaign->id);
            if ($record->advertiser->status !== 'active' || $record->risk_category === 'unverified_offer' || $record->ends_at->lessThanOrEqualTo(now()) || $record->placements->isEmpty()) {
                throw ValidationException::withMessages(['notes' => 'Only verified advertisers, configured placements and unexpired reviewed offers may be approved. Unverified investment offers are blocked.']);
            }
            $this->assertNoGuarantees(implode(' ', $record->alt_text));
            $this->safeUrl($record->destination_url);
            if ($this->creatives->inspect($record->banner_image)['creative_hash'] !== $record->creative_hash) {
                throw ValidationException::withMessages(['notes' => 'The creative changed. Save it and review again.']);
            }
            $record->forceFill(['status' => 'active', 'approved_by' => $actor->id, 'approved_at' => now(), 'review_checks' => $checks,
                'review_notes' => $checks['notes'], 'approval_hash' => app(CampaignEligibilityService::class)->digest($record)])->save();
            $this->settings->changed();
            $this->settings->audit($actor, 'campaign.approved', 'campaign', $record->id, ['risk_category' => $record->risk_category, 'checks' => $checks]);
        });
    }

    public function pause(User $actor, AdCampaign $campaign): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        DB::transaction(function () use ($actor, $campaign): void {
            $record = AdCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $record->forceFill(['status' => 'paused'])->save();
            $this->settings->changed();
            $this->settings->audit($actor, 'campaign.paused', 'campaign', $record->id);
        });
    }

    public function campaignData(AdCampaign $campaign): array
    {
        return $campaign->only(['advertiser_id', 'name', 'banner_image', 'destination_url', 'languages', 'alt_text', 'starts_at', 'ends_at', 'priority', 'risk_category', 'contract_amount', 'currency'])
            + ['placement_ids' => $campaign->placements()->pluck('ad_placements.id')->all()];
    }
}

<?php

namespace App\Support;

use App\Models\AdvertisingAudit;
use App\Models\AdvertisingSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AdvertisingSettings
{
    /** @return array{enabled: bool, direct_enabled: bool, adsense_enabled: bool, adsense_reviewed: bool, provider_review_notes: ?string, ads_txt: ?string, ads_txt_verified: bool, seller_review_notes: ?string, revision: int, updated_by: ?int} */
    public function current(): array
    {
        $defaults = ['enabled' => false, 'direct_enabled' => false, 'adsense_enabled' => false, 'adsense_reviewed' => false,
            'provider_review_notes' => null, 'ads_txt' => null, 'ads_txt_verified' => false, 'seller_review_notes' => null, 'revision' => 1, 'updated_by' => null];

        return array_replace($defaults, AdvertisingSetting::find(1)?->only(array_keys($defaults)) ?? []);
    }

    public function enabled(): bool
    {
        $mode = config('advertising.mode');

        return (bool) config('advertising.enabled') && (($mode === 'preview' && app()->environment(['local', 'testing']))
            || ($mode === 'live' && app()->isProduction())) && $this->current()['enabled'];
    }

    public function directEnabled(): bool
    {
        return $this->enabled() && (bool) config('advertising.direct_enabled') && $this->current()['direct_enabled'];
    }

    public function validPublisher(): bool
    {
        return preg_match('/\Aca-pub-[0-9]{16}\z/D', (string) config('advertising.publisher_id')) === 1;
    }

    public function adsenseReady(): bool
    {
        $state = $this->current();

        return $this->enabled() && app()->isProduction()
            && config('advertising.mode') === 'live' && config('markets.mode') === 'live'
            && (bool) config('advertising.adsense_enabled') && $state['adsense_enabled'] && $state['adsense_reviewed']
            && filled($state['provider_review_notes']) && User::find($state['updated_by'])?->is_admin && $this->validPublisher();
    }

    /** @return list<string> */
    public function sellerEntries(?string $input): array
    {
        $lines = preg_split('/\R/', trim($input ?? '')) ?: [];
        $result = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = array_map('trim', explode(',', trim($line)));
            if (count($parts) < 3 || count($parts) > 4 || strlen($line) > 500
                || ! preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}\z/D', $parts[0])
                || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $parts[1])
                || ! in_array($parts[2], ['DIRECT', 'RESELLER'], true)
                || (isset($parts[3]) && ! preg_match('/\A[A-Za-z0-9-]{1,64}\z/D', $parts[3]))) {
                throw ValidationException::withMessages(['ads_txt' => 'Enter authorized seller records only: domain, account, DIRECT or RESELLER, optional certification ID.']);
            }
            if ($parts[0] === 'google.com' && (! $this->validPublisher() || $parts[1] !== substr(config('advertising.publisher_id'), 3)
                || $parts[2] !== 'DIRECT' || ($parts[3] ?? '') !== 'f08c47fec0942fa0')) {
                throw ValidationException::withMessages(['ads_txt' => 'The Google seller must match the actual configured publisher, DIRECT relationship and Google certification ID.']);
            }
            try {
                app(FeedUrlSafety::class)->inspect('https://'.$parts[0].'/');
            } catch (FeedFailure) {
                throw ValidationException::withMessages(['ads_txt' => 'Use an actual public seller domain.']);
            }
            $result[] = implode(', ', $parts);
        }
        if (count($result) > 50 || count(array_unique($result)) !== count($result)) {
            throw ValidationException::withMessages(['ads_txt' => 'Use at most 50 distinct verified seller records.']);
        }

        return $result;
    }

    public function save(User $actor, array $data): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        $values = Validator::make($data, [
            'enabled' => ['required', 'boolean'], 'direct_enabled' => ['required', 'boolean'], 'adsense_enabled' => ['required', 'boolean'],
            'adsense_reviewed' => ['required', 'boolean'], 'provider_review_notes' => ['nullable', 'string', 'max:4000'],
            'ads_txt' => ['nullable', 'string', 'max:10000'], 'ads_txt_verified' => ['required', 'boolean'],
            'seller_review_notes' => ['nullable', 'string', 'max:4000'],
        ])->validate();
        $entries = $this->sellerEntries($values['ads_txt'] ?? null);
        if ($values['adsense_reviewed'] && (! $this->validPublisher() || mb_strlen(trim($values['provider_review_notes'] ?? '')) < 50)) {
            throw ValidationException::withMessages(['provider_review_notes' => 'Configure the actual publisher ID and document account/policy review (at least 50 characters). This does not certify a CMP or establish provider approval.']);
        }
        if ($entries && (! $values['ads_txt_verified'] || mb_strlen(trim($values['seller_review_notes'] ?? '')) < 30)) {
            throw ValidationException::withMessages(['seller_review_notes' => 'Verify authorization and document the seller evidence before publishing entries.']);
        }
        $values['ads_txt'] = $entries ? implode("\n", $entries) : null;
        DB::transaction(function () use ($actor, $values): void {
            $record = AdvertisingSetting::query()->lockForUpdate()->find(1) ?? new AdvertisingSetting;
            $before = $record->exists ? $record->getAttributes() : [];
            $record->forceFill($values + ['id' => 1, 'updated_by' => $actor->id, 'revision' => ($record->revision ?? 1) + 1])->save();
            $this->audit($actor, 'settings.updated', 'settings', 1, ['before' => $before, 'after' => $values]);
        });
    }

    public function stop(User $actor): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        DB::transaction(function () use ($actor): void {
            $record = AdvertisingSetting::query()->lockForUpdate()->find(1) ?? new AdvertisingSetting;
            $record->forceFill(['id' => 1, 'enabled' => false, 'direct_enabled' => false, 'adsense_enabled' => false,
                'updated_by' => $actor->id, 'revision' => ($record->revision ?? 1) + 1])->save();
            $this->audit($actor, 'settings.stopped', 'settings', 1);
        });
    }

    public function changed(): void
    {
        DB::table('advertising_settings')->insertOrIgnore(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        AdvertisingSetting::whereKey(1)->increment('revision');
    }

    public function audit(User $actor, string $action, string $subjectType, ?int $subjectId, array $details = []): void
    {
        $event = new AdvertisingAudit;
        $event->forceFill(['actor_id' => $actor->id, 'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'details' => $details ?: null])->save();
    }
}

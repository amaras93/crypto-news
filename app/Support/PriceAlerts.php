<?php

namespace App\Support;

use App\Models\PriceAlert;
use App\Models\PriceAlertEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PriceAlerts
{
    public function __construct(private MarketDataService $markets, private PriceThreshold $prices, private EngagementSettings $settings) {}

    public function save(User $actor, array $data, ?PriceAlert $alert = null): PriceAlert
    {
        $rules = ['asset' => ['required', Rule::in(array_keys(config('markets.assets')))],
            'direction' => ['required', Rule::in(['above', 'below'])], 'target_price' => ['required'],
            'enabled' => ['required', 'boolean'], 'in_app' => ['required', 'boolean'], 'email' => ['required', 'boolean'],
            'user_id' => ['prohibited'], 'last_price' => ['prohibited'], 'last_state' => ['prohibited'], 'version' => ['prohibited']];
        $values = Validator::make($data, $rules, array_fill_keys(['required', 'in', 'boolean', 'prohibited'], __('engagement.invalid')))->validate();
        $values['target_price'] = $this->prices->normalize($values['target_price']);
        $values['currency'] = 'USD';
        $values['unit'] = config('markets.assets.'.$values['asset'].'.unit');
        if ((isset($data['currency']) && $data['currency'] !== 'USD') || (isset($data['unit']) && $data['unit'] !== $values['unit'])) {
            throw ValidationException::withMessages(['asset' => __('engagement.invalid_unit')]);
        }
        if (! $values['in_app'] && ! $values['email']) {
            throw ValidationException::withMessages(['in_app' => __('engagement.choose_channel')]);
        }

        return DB::transaction(function () use ($actor, $values, $alert): PriceAlert {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            $record = $alert ? PriceAlert::query()->lockForUpdate()->findOrFail($alert->id) : new PriceAlert;
            Gate::forUser($actor->fresh())->authorize($alert ? 'update' : 'create', $alert ? $record : PriceAlert::class);
            $duplicate = PriceAlert::withTrashed()->where('user_id', $actor->id)->where('asset', $values['asset'])->where('target_price', $values['target_price'])
                ->where('direction', $values['direction'])->where('currency', 'USD')->where('unit', $values['unit'])->when($alert, fn ($query) => $query->whereKeyNot($alert->id))->first();
            if ($duplicate && ($alert || ! $duplicate->trashed())) {
                throw ValidationException::withMessages(['target_price' => __('engagement.duplicate_alert')]);
            }
            if ($values['enabled'] && PriceAlert::where('user_id', $actor->id)->where('enabled', true)->when($alert, fn ($query) => $query->whereKeyNot($alert->id))->count() >= min(20, config('engagement.active_alert_limit'))) {
                throw ValidationException::withMessages(['enabled' => __('engagement.alert_limit')]);
            }
            if (! $alert && $duplicate?->trashed()) {
                $record = $duplicate;
                $record->restore();
            }
            $record->fill($values);
            $record->user_id = $actor->id;
            $record->version = ($record->version ?? 0) + 1;
            $record->forceFill($this->emptyObservation())->save();

            return $record;
        }, 3);
    }

    public function pause(User $actor, PriceAlert $alert): void
    {
        DB::transaction(function () use ($actor, $alert): void {
            $record = PriceAlert::query()->lockForUpdate()->findOrFail($alert->id);
            Gate::forUser($actor->fresh())->authorize('update', $record);
            $record->forceFill(['enabled' => false, 'version' => $record->version + 1] + $this->emptyObservation())->save();
        });
    }

    public function remove(User $actor, PriceAlert $alert): void
    {
        Gate::forUser($actor->fresh())->authorize('delete', $alert);
        $this->pause($actor, $alert);
        $alert->fresh()->delete();
    }

    /** Uses only the existing cached read path; no provider request is made. */
    public function evaluate(): int
    {
        $lock = $this->settings->cache()->lock('engagement:price-alert-evaluation', 75);
        if (! $lock->get()) {
            return 0;
        }
        try {
            if (config('markets.mode') !== 'live' || ! $this->settings->enabled('price_alerts_enabled')) {
                PriceAlert::whereNotNull('last_price')->update($this->emptyObservation());

                return 0;
            }
            $quotes = collect($this->markets->snapshot()['quotes'])->keyBy('asset');
            $triggered = 0;
            PriceAlert::where('enabled', true)->select('id', 'asset')->chunkById($this->settings->batchSize(), function ($alerts) use ($quotes, &$triggered): void {
                foreach ($alerts as $alert) {
                    $triggered += (int) $this->observe($alert->id, $quotes->get($alert->asset));
                }
            });

            return $triggered;
        } finally {
            $lock->release();
        }
    }

    private function observe(int $id, ?MarketQuote $quote): bool
    {
        return DB::transaction(function () use ($id, $quote): bool {
            $alert = PriceAlert::query()->lockForUpdate()->find($id);
            if (! $alert || ! $alert->enabled) {
                return false;
            }
            if (config('markets.mode') !== 'live' || ! $this->settings->enabled('price_alerts_enabled') || ! $this->isCurrentQuote($alert, $quote)) {
                $alert->forceFill($this->emptyObservation())->save();

                return false;
            }
            $price = $this->prices->quote($quote->price);
            if ($price === null) {
                $alert->forceFill($this->emptyObservation())->save();

                return false;
            }
            if ($alert->last_observed_timestamp !== null && $quote->providerTimestamp <= $alert->last_observed_timestamp) {
                if ($quote->providerTimestamp < $alert->last_observed_timestamp || $this->prices->compare($price, $alert->last_price) !== 0) {
                    $alert->forceFill($this->emptyObservation())->save();
                }

                return false;
            }
            $comparison = $this->prices->compare($price, $alert->target_price);
            $state = $comparison > 0 ? 'above' : ($comparison < 0 ? 'below' : 'equal');
            $maximumGap = 2 * $this->markets->interval(config('markets.assets.'.$alert->asset.'.group')) + 120;
            $reliablePrevious = $alert->last_price !== null && $alert->last_observed_timestamp !== null
                && $alert->last_provider === $quote->provider && $quote->providerTimestamp - $alert->last_observed_timestamp <= $maximumGap
                && $alert->last_seen_at && $alert->last_seen_at->greaterThanOrEqualTo(now()->subSeconds($maximumGap));
            $crossed = $reliablePrevious && ($alert->direction === 'above'
                ? $this->prices->compare($alert->last_price, $alert->target_price) <= 0 && $comparison > 0
                : $this->prices->compare($alert->last_price, $alert->target_price) >= 0 && $comparison < 0);
            $previous = $alert->last_price;
            $alert->forceFill(['last_price' => $price, 'last_state' => $state, 'last_provider' => $quote->provider,
                'last_observed_timestamp' => $quote->providerTimestamp, 'last_seen_at' => now()]);
            if ($crossed) {
                $event = new PriceAlertEvent;
                $event->forceFill(['public_id' => Str::uuid()->toString(), 'price_alert_id' => $alert->id, 'user_id' => $alert->user_id,
                    'alert_version' => $alert->version, 'observed_timestamp' => $quote->providerTimestamp,
                    'asset' => $alert->asset, 'currency' => $alert->currency, 'unit' => $alert->unit, 'direction' => $alert->direction,
                    'target_price' => $alert->target_price, 'previous_price' => $previous, 'observed_price' => $price, 'provider' => $quote->provider])->save();
                $alert->last_triggered_at = now();
                app(EngagementDeliveries::class)->forPriceEvent($event, $alert);
            }
            $alert->save();

            return $crossed;
        }, 3);
    }

    public function isCurrentQuote(PriceAlert $alert, ?MarketQuote $quote): bool
    {
        $definition = config('markets.assets.'.$alert->asset);
        if (! is_array($definition) || ! $quote || $quote->status !== 'current' || $quote->provider === 'demo'
            || $quote->asset !== $alert->asset || $quote->symbol !== $definition['symbol'] || $quote->currency !== 'USD'
            || $quote->unit !== $definition['unit'] || $alert->currency !== 'USD' || $alert->unit !== $definition['unit']
            || ! $quote->providerTimestamp || ! $quote->fetchedAt || $quote->providerTimestamp > now()->timestamp || $quote->fetchedAt > now()->timestamp
            || $this->markets->blockReason($definition['group']) !== null) {
            return false;
        }

        return MarketQuote::fromCache($quote->asset, $quote->toArray()) !== null
            && now()->timestamp - min($quote->providerTimestamp, $quote->fetchedAt) <= $this->markets->interval($definition['group']) + 60;
    }

    /** @return array{last_price: null, last_state: null, last_provider: null, last_observed_timestamp: null, last_seen_at: null} */
    private function emptyObservation(): array
    {
        return ['last_price' => null, 'last_state' => null, 'last_provider' => null, 'last_observed_timestamp' => null, 'last_seen_at' => null];
    }
}

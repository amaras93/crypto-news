<?php

namespace App\Support;

use App\Models\DiscoverySetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DiscoverySettingsManager
{
    /** @return array{enabled: bool, max_candidates_per_run: int, max_sources_per_run: int, default_polling_minutes: int} */
    public function current(): array
    {
        $settings = DiscoverySetting::query()->find(1);

        return [
            'enabled' => (bool) ($settings?->enabled ?? false),
            'max_candidates_per_run' => (int) ($settings?->max_candidates_per_run ?? 25),
            'max_sources_per_run' => (int) ($settings?->max_sources_per_run ?? 3),
            'default_polling_minutes' => (int) ($settings?->default_polling_minutes ?? 60),
        ];
    }

    public function enabled(): bool
    {
        return ! config('discovery.emergency_stop') && $this->current()['enabled'];
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, array $data): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        $values = Validator::make($data, [
            'enabled' => ['required', 'boolean'], 'max_candidates_per_run' => ['required', 'integer', 'between:1,100'],
            'max_sources_per_run' => ['required', 'integer', 'between:1,5'], 'default_polling_minutes' => ['required', 'integer', 'between:15,1440'],
            'generation_enabled' => ['prohibited'], 'automatic_publishing' => ['prohibited'],
        ])->validate();
        DB::transaction(function () use ($actor, $values): void {
            abort_unless($actor->fresh()?->is_admin, 403);
            DiscoverySetting::updateOrCreate(['id' => 1], Arr::only($values, ['enabled', 'max_candidates_per_run', 'max_sources_per_run', 'default_polling_minutes']) + ['updated_by' => $actor->id]);
        });
    }
}

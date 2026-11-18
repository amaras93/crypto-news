<?php

namespace App\Support;

use App\Models\EngagementSetting;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Validator;

class EngagementSettings
{
    public function current(): array
    {
        $defaults = ['price_alerts_enabled' => false, 'newsletters_enabled' => false, 'real_email_enabled' => false,
            'daily_at' => '08:00', 'weekly_day' => 1, 'weekly_at' => '09:00',
            'emails_per_minute' => config('engagement.emails_per_minute'), 'batch_size' => config('engagement.batch_size'), 'updated_by' => null];

        return array_replace($defaults, EngagementSetting::find(1)?->only(array_keys($defaults)) ?? []);
    }

    public function enabled(string $flag): bool
    {
        return in_array($flag, ['price_alerts_enabled', 'newsletters_enabled', 'real_email_enabled'], true)
            && (bool) config('engagement.'.$flag) && (bool) $this->current()[$flag];
    }

    public function batchSize(): int
    {
        return max(1, min((int) config('engagement.batch_size'), (int) $this->current()['batch_size']));
    }

    public function emailRate(): int
    {
        return max(1, min((int) config('engagement.emails_per_minute'), (int) $this->current()['emails_per_minute']));
    }

    public function cache(): Repository
    {
        return app(NewsDiscovery::class)->cache();
    }

    public function save(User $actor, array $data): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        $values = Validator::make($data, [
            'price_alerts_enabled' => ['required', 'boolean'], 'newsletters_enabled' => ['required', 'boolean'],
            'real_email_enabled' => ['required', 'boolean'], 'daily_at' => ['required', 'date_format:H:i'],
            'weekly_day' => ['required', 'integer', 'between:0,6'], 'weekly_at' => ['required', 'date_format:H:i'],
            'emails_per_minute' => ['required', 'integer', 'between:1,600'], 'batch_size' => ['required', 'integer', 'between:1,500'],
        ])->validate();
        EngagementSetting::updateOrCreate(['id' => 1], $values + ['updated_by' => $actor->id]);
    }

    public function stop(User $actor): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        EngagementSetting::updateOrCreate(['id' => 1], ['price_alerts_enabled' => false, 'newsletters_enabled' => false,
            'real_email_enabled' => false, 'updated_by' => $actor->id]);
    }
}

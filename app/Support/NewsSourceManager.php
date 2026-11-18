<?php

namespace App\Support;

use App\IngestionStatus;
use App\Models\IngestionRun;
use App\Models\NewsSource;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NewsSourceManager
{
    public function __construct(private FeedUrlSafety $urls) {}

    public function disable(User $actor, NewsSource $source): void
    {
        DB::transaction(function () use ($actor, $source): void {
            $record = NewsSource::query()->lockForUpdate()->findOrFail($source->id);
            Gate::forUser($actor->fresh())->authorize('update', $record);
            if (! $record->enabled) {
                return;
            }
            if ($record->active_token) {
                IngestionRun::query()->where('token', $record->active_token)->whereIn('status', [IngestionStatus::Queued, IngestionStatus::Running])
                    ->update(['status' => IngestionStatus::Skipped, 'error_code' => 'source_disabled', 'finished_at' => now()]);
            }
            $record->forceFill(['enabled' => false, 'active_token' => null, 'lease_until' => null, 'version' => $record->version + 1])->save();
        });
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, array $data, ?NewsSource $source = null): NewsSource
    {
        $actor = $actor->fresh() ?? throw new AuthorizationException;
        Gate::forUser($actor)->authorize($source ? 'update' : 'create', $source ?? NewsSource::class);
        $values = Validator::make($data, [
            'name' => ['required', 'string', 'max:255', 'not_regex:/[<>\p{C}]/u'],
            'website' => ['required', 'string', 'max:2048'], 'feed_url' => ['required', 'string', 'max:2048'],
            'source_type' => ['required', Rule::in(['rss', 'atom'])],
            'language' => ['required', 'string', 'max:35', 'regex:/\A[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,3}\z/D'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'], 'topic' => ['nullable', Rule::in(array_keys(config('discovery.topics')))],
            'priority' => ['required', 'integer', 'between:1,5'], 'enabled' => ['required', 'boolean'],
            'summary_permitted' => ['required', 'boolean'],
            'licensing_notes' => ['required', 'string', 'min:10', 'max:2000'],
            'polling_minutes' => ['nullable', 'integer', 'between:15,1440'], 'item_limit' => ['required', 'integer', 'between:1,100'],
            'approval_confirmed' => ['required', 'boolean'],
            'ai_usage_allowed' => ['sometimes', 'boolean'], 'is_primary' => ['sometimes', 'boolean'],
            'publisher_group' => ['nullable', 'string', 'max:100', 'regex:/\A[a-z0-9-]+\z/D'],
        ])->validate();
        if (($values['ai_usage_allowed'] ?? false) && (! $values['approval_confirmed'] || empty($values['publisher_group']))) {
            throw ValidationException::withMessages(['ai_usage_allowed' => 'AI usage requires verified licensing and the publisher ownership group.']);
        }
        try {
            $values['website'] = $this->urls->resolve($values['website'])['url'];
            $values['feed_url'] = $this->urls->resolve($values['feed_url'])['url'];
        } catch (FeedFailure $error) {
            throw ValidationException::withMessages(['feed_url' => 'Source URL rejected: '.$error->reason]);
        }
        $values['feed_hash'] = hash('sha256', $values['feed_url']);
        if (NewsSource::query()->where('feed_hash', $values['feed_hash'])->when($source, fn (Builder $query): Builder => $query->whereKeyNot($source->id))->exists()) {
            throw ValidationException::withMessages(['feed_url' => 'This feed endpoint is already configured.']);
        }
        if ($values['enabled'] && ! $values['approval_confirmed']) {
            throw ValidationException::withMessages(['approval_confirmed' => 'Approve the publisher endpoint and usage rights before enabling this source.']);
        }

        return DB::transaction(function () use ($actor, $values, $source): NewsSource {
            $record = $source ? NewsSource::query()->lockForUpdate()->findOrFail($source->id) : new NewsSource;
            Gate::forUser($actor->fresh())->authorize($source ? 'update' : 'create', $source ?? NewsSource::class);
            $feedChanged = $record->feed_url !== $values['feed_url'];
            $approved = (bool) $values['approval_confirmed'];
            unset($values['approval_confirmed']);
            foreach (['ai_usage_allowed', 'is_primary', 'publisher_group'] as $field) {
                if (array_key_exists($field, $values)) {
                    $record->{$field} = $values[$field];
                    unset($values[$field]);
                }
            }
            $record->fill($values);
            $record->approved_by = $approved ? $actor->id : null;
            $record->approved_at = $approved ? now() : null;
            $record->version = ($record->version ?? 0) + 1;
            if ($record->active_token) {
                IngestionRun::query()->where('token', $record->active_token)->whereIn('status', [IngestionStatus::Queued, IngestionStatus::Running])
                    ->update(['status' => IngestionStatus::Skipped, 'error_code' => 'source_configuration_changed', 'finished_at' => now()]);
            }
            $record->active_token = null;
            $record->lease_until = null;
            $record->failure_count = 0;
            if ($feedChanged) {
                $record->etag = null;
                $record->last_modified = null;
            }
            $record->save();

            return $record;
        });
    }
}

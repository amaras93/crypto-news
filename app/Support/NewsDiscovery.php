<?php

namespace App\Support;

use App\IngestionStatus;
use App\Jobs\IngestNewsSource;
use App\Models\IngestionRun;
use App\Models\NewsSource;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

class NewsDiscovery
{
    public function __construct(private DiscoverySettingsManager $settings, private FeedHttpClient $http,
        private FeedParser $parser, private NewsNormalizer $normalizer, private NewsCandidateStore $candidates) {}

    public function cache(): Repository
    {
        $name = config('discovery.cache_store') ?: config('cache.default');
        if (app()->isProduction() && ! in_array(config('cache.stores.'.$name.'.driver'), ['database', 'redis'], true)) {
            throw new FeedFailure('shared_cache_required');
        }

        return Cache::store($name);
    }

    public function queueDue(?User $actor = null, ?int $sourceId = null): int
    {
        if ($actor) {
            abort_unless($actor->fresh()?->is_admin, 403);
        }
        if (! $this->settings->enabled()) {
            return 0;
        }
        $lock = $this->cache()->lock('discovery:dispatcher', 30);
        if (! $lock->get()) {
            return 0;
        }
        try {
            if ((int) $this->cache()->get('discovery:dispatch-next', 0) > now()->timestamp) {
                return 0;
            }
            $sources = NewsSource::query()->where('enabled', true)->whereNotNull('approved_at')
                ->where(fn (Builder $query): Builder => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()))
                ->where(fn (Builder $query): Builder => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->when($sourceId, fn (Builder $query): Builder => $query->whereKey($sourceId),
                    fn (Builder $query): Builder => $query->where('failure_count', '<', 3))
                ->orderBy('priority')->orderBy('id')->limit($this->settings->current()['max_sources_per_run'])->pluck('id');
            $count = 0;
            foreach ($sources as $id) {
                $count += $this->reserve((int) $id, $actor) ? 1 : 0;
            }
            if ($count > 0) {
                $this->cache()->put('discovery:dispatch-next', now()->timestamp + config('discovery.dispatch_interval_seconds'), 120);
            }

            return $count;
        } finally {
            $lock->release();
        }
    }

    public function retry(User $actor, IngestionRun $run): bool
    {
        Gate::forUser($actor->fresh())->authorize('retry', $run);
        if (! $this->settings->enabled()) {
            return false;
        }

        $lock = $this->cache()->lock('discovery:dispatcher', 30);
        if (! $lock->get()) {
            return false;
        }
        try {
            if ((int) $this->cache()->get('discovery:dispatch-next', 0) > now()->timestamp) {
                return false;
            }
            $queued = $this->reserve($run->news_source_id, $actor, $run);
            if ($queued) {
                $this->cache()->put('discovery:dispatch-next', now()->timestamp + config('discovery.dispatch_interval_seconds'), 120);
            }

            return $queued;
        } finally {
            $lock->release();
        }
    }

    private function reserve(int $sourceId, ?User $actor, ?IngestionRun $retry = null): bool
    {
        $run = DB::transaction(function () use ($sourceId, $actor, $retry): ?IngestionRun {
            $source = NewsSource::query()->lockForUpdate()->find($sourceId);
            if (! $source || ! $this->eligible($source) || ($source->next_poll_at && $source->next_poll_at->isFuture())
                || ($source->lease_until && $source->lease_until->isFuture()) || ! $this->settings->enabled()) {
                return null;
            }
            if ($source->active_token) {
                IngestionRun::query()->where('token', $source->active_token)->whereIn('status', [IngestionStatus::Queued, IngestionStatus::Running])
                    ->update(['status' => IngestionStatus::Failed, 'error_code' => 'lease_expired', 'finished_at' => now(), 'retry_at' => now()]);
            }
            if ($retry) {
                $record = IngestionRun::query()->lockForUpdate()->findOrFail($retry->id);
                if ($record->status !== IngestionStatus::Failed || $record->attempts >= config('discovery.max_attempts')
                    || $record->source_version !== $source->version || ($record->retry_at && $record->retry_at->isFuture())
                    || ! in_array($record->error_code, ['network_error', 'transport_error', 'provider_rate_limited', 'http_error', 'queue_unavailable', 'worker_failed', 'lease_expired', 'processing_error', 'candidate_store_busy', 'host_cooldown'], true)) {
                    return null;
                }
            } else {
                $record = new IngestionRun(['news_source_id' => $source->id, 'source_version' => $source->version,
                    'item_limit' => min($source->item_limit, $this->settings->current()['max_candidates_per_run'])]);
            }
            $record->requested_by = $actor?->id;
            $record->token = Str::uuid()->toString();
            $record->status = IngestionStatus::Queued;
            $record->attempts = ($record->attempts ?? 0) + 1;
            $record->queued_at = now();
            $record->finished_at = null;
            $record->retry_at = null;
            $record->error_code = null;
            $record->save();
            $source->active_token = $record->token;
            $source->lease_until = now()->addSeconds(config('discovery.lease_seconds'));
            $source->next_poll_at = now()->addMinutes($source->polling_minutes ?? $this->settings->current()['default_polling_minutes']);
            $source->save();

            return $record;
        });
        if (! $run) {
            return false;
        }
        try {
            IngestNewsSource::dispatch($run->id, $run->token)->afterCommit();
        } catch (Throwable) {
            $this->failed($run->id, $run->token, 'queue_unavailable');

            return false;
        }

        return true;
    }

    public function process(int $runId, string $token): void
    {
        $run = IngestionRun::query()->find($runId);
        if (! $run || $run->token !== $token || $run->status !== IngestionStatus::Queued) {
            return;
        }
        $lock = $this->cache()->lock('discovery:source:'.$run->news_source_id, 90);
        if (! $lock->get()) {
            return;
        }
        try {
            $source = DB::transaction(function () use ($run, $token): ?NewsSource {
                $source = NewsSource::query()->lockForUpdate()->find($run->news_source_id);
                $run->refresh();
                if (! $source || $source->active_token !== $token || $run->token !== $token || $run->status !== IngestionStatus::Queued) {
                    return null;
                }
                if (! $this->settings->enabled() || ! $this->eligible($source) || $run->source_version !== $source->version
                    || ! $source->lease_until || $source->lease_until->isPast() || ($run->requested_by && ! $run->requester?->is_admin)) {
                    $this->skip($source, $run, 'discovery_disabled_or_changed');

                    return null;
                }
                $run->status = IngestionStatus::Running;
                $run->started_at = now();
                $run->save();

                return $source;
            });
            if (! $source) {
                return;
            }
            $host = parse_url($source->feed_url, PHP_URL_HOST);
            $hostLock = $this->cache()->lock('discovery:host-lock:'.hash('sha256', $host), 20);
            if (! $hostLock->get()) {
                throw new FeedFailure('host_cooldown', 60);
            }
            try {
                $hostKey = 'discovery:host-next:'.hash('sha256', $host);
                if ((int) $this->cache()->get($hostKey, 0) > now()->timestamp) {
                    throw new FeedFailure('host_cooldown', 60);
                }
                $this->cache()->put($hostKey, now()->timestamp + config('discovery.host_interval_seconds'), 120);
                $source->last_fetched_at = now();
                $source->save();
                $payload = $this->http->fetch($source);
            } finally {
                $hostLock->release();
            }
            $limit = min($run->item_limit, $source->item_limit, $this->settings->current()['max_candidates_per_run']);
            $entries = $payload['not_modified'] ? [] : $this->parser->parse($payload['body'], $source->source_type, $limit);
            $items = [];
            $errors = [];
            foreach ($entries as $entry) {
                try {
                    $items[] = $this->normalizer->normalize($entry, $source);
                } catch (FeedFailure $error) {
                    $errors[$error->reason] = ($errors[$error->reason] ?? 0) + 1;
                }
            }
            $storeLock = $this->cache()->lock('discovery:candidates', 60);
            if (! $storeLock->get()) {
                throw new FeedFailure('candidate_store_busy', 60);
            }
            try {
                DB::transaction(function () use ($source, $run, $token, $payload, $entries, $items, $errors): void {
                    $current = NewsSource::query()->lockForUpdate()->findOrFail($source->id);
                    $run->refresh();
                    if ($current->active_token !== $token || $run->token !== $token) {
                        return;
                    }
                    if (! $this->settings->enabled() || ! $this->eligible($current) || $current->version !== $run->source_version) {
                        $this->skip($current, $run, 'discovery_disabled_or_changed');

                        return;
                    }
                    $created = $linked = $duplicates = 0;
                    foreach ($items as $item) {
                        $result = $this->candidates->store($current, $run, $item);
                        $created += (int) $result['created'];
                        $linked += (int) ! $result['created'];
                        $duplicates += (int) $result['duplicate'];
                    }
                    $run->forceFill(['status' => IngestionStatus::Succeeded, 'finished_at' => now(), 'items_seen' => count($entries),
                        'created_count' => $created, 'linked_count' => $linked, 'duplicate_count' => $duplicates,
                        'invalid_count' => array_sum($errors), 'item_errors' => $errors ?: null])->save();
                    $current->forceFill(['last_success_at' => now(), 'failure_count' => 0, 'last_error' => null,
                        'etag' => $payload['etag'], 'last_modified' => $payload['last_modified'], 'active_token' => null, 'lease_until' => null])->save();
                });
            } finally {
                $storeLock->release();
            }
        } catch (FeedFailure $error) {
            $this->failed($runId, $token, $error->reason, $error->retrySeconds);
        } catch (Throwable) {
            $this->failed($runId, $token, 'processing_error');
        } finally {
            $lock->release();
        }
    }

    public function failed(int $runId, string $token, string $code = 'worker_failed', int $retrySeconds = 3600): void
    {
        DB::transaction(function () use ($runId, $token, $code, $retrySeconds): void {
            $run = IngestionRun::query()->find($runId);
            if (! $run || $run->token !== $token || ! in_array($run->status, [IngestionStatus::Queued, IngestionStatus::Running], true)) {
                return;
            }
            $source = NewsSource::query()->lockForUpdate()->findOrFail($run->news_source_id);
            $run->refresh();
            if ($run->token !== $token || ! in_array($run->status, [IngestionStatus::Queued, IngestionStatus::Running], true)) {
                return;
            }
            $next = now()->addSeconds(max(60, $retrySeconds));
            if ($source->next_poll_at && $source->next_poll_at->greaterThan($next)) {
                $next = $source->next_poll_at;
            }
            $run->forceFill(['status' => IngestionStatus::Failed, 'error_code' => $code, 'finished_at' => now(), 'retry_at' => $next])->save();
            if ($source->active_token === $token) {
                if ($code === 'provider_polling_blocked') {
                    $source->enabled = false;
                }
                $source->forceFill(['active_token' => null, 'lease_until' => null, 'next_poll_at' => $next,
                    'last_error' => $code, 'failure_count' => min(65535, $source->failure_count + 1)])->save();
            }
        });
    }

    private function eligible(NewsSource $source): bool
    {
        return $source->enabled && $source->approved_at && $source->approver?->is_admin;
    }

    private function skip(NewsSource $source, IngestionRun $run, string $reason): void
    {
        $run->forceFill(['status' => IngestionStatus::Skipped, 'error_code' => $reason, 'finished_at' => now()])->save();
        $source->forceFill(['active_token' => null, 'lease_until' => null])->save();
    }
}

<?php

namespace App\Support;

use App\Models\Article;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NewsDigests
{
    public function __construct(private EngagementSettings $settings) {}

    /** @return array{start: CarbonImmutable, end: CarbonImmutable} */
    public function period(string $frequency, ?CarbonImmutable $at = null): array
    {
        abort_unless(in_array($frequency, ['daily', 'weekly'], true), 422);
        $end = ($at ?? CarbonImmutable::now('UTC'))->utc()->startOfDay();
        if ($frequency === 'weekly') {
            $end = $end->startOfWeek(CarbonImmutable::MONDAY);
        }

        return ['start' => $frequency === 'daily' ? $end->subDay() : $end->subWeek(), 'end' => $end];
    }

    /** @return Collection<int, Article> */
    public function select(string $locale, string $frequency, ?CarbonImmutable $at = null): Collection
    {
        abort_unless(array_key_exists($locale, config('news.locales')), 404);
        $period = $this->period($frequency, $at);

        return app(NewsFeed::class)->articles($locale)
            ->whereNull('sponsor_classification')
            ->where('published_at', '>=', $period['start'])->where('published_at', '<', $period['end'])
            ->whereDoesntHave('tags', fn (Builder $query): Builder => $query->where('slug', 'cryptonews-demo'))
            ->withCount(['tags as editorial_priority' => fn (Builder $query): Builder => $query->where('slug', 'editors-pick')])
            ->reorder()->orderByDesc('editorial_priority')->orderByDesc('published_at')->orderByDesc('id')
            ->limit(config('engagement.max_digest_stories'))->get();
    }

    public function build(User $actor, string $locale, string $frequency): NewsletterCampaign
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        $period = $this->period($frequency);
        $lock = $this->settings->cache()->lock('engagement:digest:'.$locale.':'.$frequency.':'.$period['start']->timestamp, 30);
        abort_unless($lock->get(), 409);
        try {
            return DB::transaction(function () use ($locale, $frequency, $period): NewsletterCampaign {
                $existing = NewsletterCampaign::where('locale', $locale)->where('frequency', $frequency)->where('period_start', $period['start'])->lockForUpdate()->first();
                if ($existing && $existing->status !== 'draft') {
                    return $existing;
                }
                $articles = $this->select($locale, $frequency);
                $campaign = $existing ?? new NewsletterCampaign;
                $campaign->forceFill(['status' => 'draft', 'audience_cursor' => 0, 'locale' => $locale, 'frequency' => $frequency, 'period_start' => $period['start'], 'period_end' => $period['end'],
                    'article_ids' => $articles->modelKeys(), 'translation_hashes' => $articles->mapWithKeys(fn (Article $article): array => [(string) $article->id => $article->translation($locale)->contentDigest()])->all()])->save();

                return $campaign;
            });
        } finally {
            $lock->release();
        }
    }

    /** Rechecks publication and unchanged localized content at preview and send time.
     * @return Collection<int, Article>
     */
    public function articles(NewsletterCampaign $campaign): Collection
    {
        return app(NewsFeed::class)->articles($campaign->locale)->whereIn('articles.id', $campaign->article_ids)
            ->whereNull('sponsor_classification')
            ->where('published_at', '>=', $campaign->period_start)->where('published_at', '<', $campaign->period_end)
            ->whereDoesntHave('tags', fn (Builder $query): Builder => $query->where('slug', 'cryptonews-demo'))->get()
            ->filter(fn (Article $article): bool => ($campaign->translation_hashes[$article->id] ?? null) === $article->translation($campaign->locale)->contentDigest())
            ->sortBy(fn (Article $article): int => array_search($article->id, $campaign->article_ids, true))->values();
    }

    public function approve(User $actor, NewsletterCampaign $campaign): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        DB::transaction(function () use ($actor, $campaign): void {
            $record = NewsletterCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if ($record->status === 'cancelled' || $this->articles($record)->isEmpty()) {
                throw ValidationException::withMessages(['campaign' => 'No unchanged, real, published localized stories remain in this digest.']);
            }
            $record->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()])->save();
        });
    }

    public function dispatchAudience(NewsletterCampaign $campaign): int
    {
        if (! $this->settings->enabled('newsletters_enabled')) {
            return 0;
        }

        return DB::transaction(function () use ($campaign): int {
            $record = NewsletterCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if (! in_array($record->status, ['approved', 'queuing'], true) || ! $record->approver?->is_admin || ! $record->approved_at) {
                return 0;
            }
            if ($this->articles($record)->isEmpty()) {
                $record->forceFill(['status' => 'cancelled'])->save();

                return 0;
            }
            $subscribers = NewsletterSubscriber::whereNotNull('confirmed_at')->whereNull('unsubscribed_at')
                ->where('locale', $record->locale)->where('frequency', $record->frequency)
                ->where('id', '>', $record->audience_cursor)->orderBy('id')->limit($this->settings->batchSize())->lockForUpdate()->get();
            foreach ($subscribers as $subscriber) {
                app(EngagementDeliveries::class)->digest($subscriber, $record);
                $record->audience_cursor = $subscriber->id;
            }
            $record->status = $subscribers->count() < $this->settings->batchSize() ? 'audience_queued' : 'queuing';
            $record->queued_at ??= now();
            $record->save();

            return $subscribers->count();
        }, 3);
    }

    public function cancel(User $actor, NewsletterCampaign $campaign): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        $campaign->forceFill(['status' => 'cancelled'])->save();
    }

    public function queueScheduled(): int
    {
        if (! $this->settings->enabled('newsletters_enabled')) {
            return 0;
        }
        $settings = $this->settings->current();
        $actor = User::find($settings['updated_by']);
        if (! $actor?->is_admin) {
            return 0;
        }
        $now = now()->utc();
        $frequencies = [];
        if ($now->format('H:i') >= $settings['daily_at']) {
            $frequencies[] = 'daily';
        }
        if ((int) $now->dayOfWeek === (int) $settings['weekly_day'] && $now->format('H:i') >= $settings['weekly_at']) {
            $frequencies[] = 'weekly';
        }
        $count = 0;
        foreach ($frequencies as $frequency) {
            foreach (array_keys(config('news.locales')) as $locale) {
                $campaign = $this->build($actor, $locale, $frequency);
                if ($campaign->status === 'draft' && $this->articles($campaign)->isNotEmpty()) {
                    $this->approve($actor, $campaign);
                    $campaign->refresh();
                }
                $count += $this->dispatchAudience($campaign);
            }
        }

        return $count;
    }
}

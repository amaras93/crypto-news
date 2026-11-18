<?php

namespace App\Support;

use App\Models\Article;
use App\Models\SavedArticle;
use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReaderEngagement
{
    public function addAsset(User $actor, array $data): WatchlistItem
    {
        $values = Validator::make($data, ['asset' => ['required', Rule::in(array_keys(config('markets.assets')))],
            'user_id' => ['prohibited'], 'position' => ['prohibited']], ['*' => __('engagement.invalid')])->validate();

        return DB::transaction(function () use ($actor, $values): WatchlistItem {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            $existing = WatchlistItem::where('user_id', $actor->id)->where('asset', $values['asset'])->first();
            if ($existing) {
                return $existing;
            }
            $items = WatchlistItem::where('user_id', $actor->id);
            if ((clone $items)->count() >= min(30, config('engagement.watchlist_limit'))) {
                throw ValidationException::withMessages(['asset' => __('engagement.watchlist_limit')]);
            }
            $item = new WatchlistItem($values + ['position' => ((clone $items)->max('position') ?? -1) + 1]);
            $item->user_id = $actor->id;
            $item->save();

            return $item;
        }, 3);
    }

    public function removeAsset(User $actor, WatchlistItem $item): void
    {
        DB::transaction(function () use ($actor, $item): void {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            $record = WatchlistItem::query()->lockForUpdate()->findOrFail($item->id);
            Gate::forUser($actor->fresh())->authorize('delete', $record);
            $record->delete();
        });
    }

    public function move(User $actor, WatchlistItem $item, string $direction): void
    {
        Gate::forUser($actor->fresh())->authorize('update', $item);
        abort_unless(in_array($direction, ['up', 'down'], true), 422);
        DB::transaction(function () use ($actor, $item, $direction): void {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            $ids = WatchlistItem::where('user_id', $actor->id)->orderBy('position')->orderBy('id')->pluck('id')->all();
            $position = array_search($item->id, $ids, true);
            $next = $position + ($direction === 'up' ? -1 : 1);
            if ($position !== false && isset($ids[$next])) {
                [$ids[$position], $ids[$next]] = [$ids[$next], $ids[$position]];
                $this->reorder($actor, $ids);
            }
        }, 3);
    }

    /** @param list<int> $ids */
    public function reorder(User $actor, array $ids): void
    {
        Validator::make(['order' => $ids], ['order' => ['array', 'max:30'], 'order.*' => ['integer', 'distinct']], ['*' => __('engagement.invalid_order')])->validate();
        DB::transaction(function () use ($actor, $ids): void {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            $owned = WatchlistItem::where('user_id', $actor->id)->orderBy('id')->pluck('id')->all();
            $submitted = array_map('intval', $ids);
            sort($submitted);
            if (! array_is_list($ids) || count($ids) !== count(array_unique($ids)) || $owned !== $submitted) {
                throw ValidationException::withMessages(['order' => __('engagement.invalid_order')]);
            }
            foreach ($ids as $position => $id) {
                WatchlistItem::where('user_id', $actor->id)->whereKey($id)->update(['position' => $position]);
            }
        }, 3);
    }

    public function saveArticle(User $actor, int $articleId, string $locale): SavedArticle
    {
        abort_unless(array_key_exists($locale, config('news.locales')), 404);
        Article::query()->published()->inLocale($locale)->findOrFail($articleId);

        return DB::transaction(function () use ($actor, $articleId, $locale): SavedArticle {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            $saved = SavedArticle::where('user_id', $actor->id)->where('article_id', $articleId)->first() ?? new SavedArticle;
            $saved->forceFill(['user_id' => $actor->id, 'article_id' => $articleId, 'locale' => $locale])->save();

            return $saved;
        }, 3);
    }

    public function removeSaved(User $actor, SavedArticle $saved): void
    {
        Gate::forUser($actor->fresh())->authorize('delete', $saved);
        $saved->delete();
    }

    public function preferences(User $actor, array $data): void
    {
        $values = Validator::make($data, ['price_alert_in_app' => ['required', 'boolean'], 'price_alert_email' => ['required', 'boolean'],
            'user_id' => ['prohibited'], 'is_admin' => ['prohibited']], ['*' => __('engagement.invalid')])->validate();
        User::findOrFail($actor->id)->forceFill($values)->save();
    }

    /** @param list<string> $assets
     * @return Collection<int, Article>
     */
    public function relatedNews(array $assets, string $locale): Collection
    {
        $topics = [];
        foreach ($assets as $asset) {
            if (! isset(config('markets.assets')[$asset])) {
                continue;
            }
            $topics[] = str_replace('_', '-', $asset);
            $topics[] = config('markets.assets.'.$asset.'.symbol');
            if (in_array($asset, ['gold', 'silver', 'platinum', 'palladium'], true)) {
                $topics[] = 'gold-silver';
            }
        }

        return app(NewsFeed::class)->articles($locale)->where(function (Builder $query) use ($topics): void {
            $query->whereHas('tags', fn (Builder $tags): Builder => $tags->whereIn('slug', $topics))
                ->orWhereHas('category.translations', fn (Builder $category): Builder => $category->where('locale', 'en')->whereIn('slug', $topics));
        })->limit(6)->get();
    }
}

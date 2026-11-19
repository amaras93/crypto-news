<?php

namespace App\Support;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

class NewsFeed
{
    /** @return Builder<Article> */
    public function articles(string $locale): Builder
    {
        return Article::query()->published()->inLocale($locale)
            ->with([
                'author:id,name',
                'sponsor',
                'tags:id,name,slug',
                'category.translations' => fn (Builder|Relation $query) => $query->whereIn('locale', array_unique([$locale, 'en'])),
            ])
            ->orderByDesc('published_at')->orderByDesc('id');
    }

    /** @return Collection<int, Category> */
    public function categories(string $locale): Collection
    {
        return Category::query()
            ->whereHas('translations', fn (Builder $query) => $query->whereIn('locale', array_unique([$locale, 'en'])))
            ->with(['translations' => fn (Builder|Relation $query) => $query->whereIn('locale', array_unique([$locale, 'en']))])
            ->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (Category $category): bool => $category->displayName($locale) !== null)->values();
    }

    /**
     * @param  list<int>  $excludeIds
     * @return Collection<int, Article>
     */
    public function selected(string $locale, string $tag, int $limit, ?int $excludeId = null, array $excludeIds = []): Collection
    {
        return $this->articles($locale)
            ->whereNull('sponsor_classification')
            ->when($excludeId, fn (Builder $query) => $query->whereKeyNot($excludeId))
            ->when($excludeIds, fn (Builder $query) => $query->whereNotIn('articles.id', $excludeIds))
            ->whereHas('tags', fn (Builder $query) => $query->where('slug', $tag))
            ->limit($limit)->get();
    }

    /** @return Collection<int, Article> */
    public function related(Article $article, string $locale): Collection
    {
        $tagIds = $article->tags->whereNotIn('slug', ['cryptonews-demo', 'editors-pick'])->pluck('id');

        return $this->articles($locale)->whereKeyNot($article->id)
            ->when($article->ai_generation_run_id, function (Builder $query) use ($article, $tagIds): void {
                if (! $article->category_id && $tagIds->isEmpty()) {
                    $query->whereRaw('1 = 0');

                    return;
                }
                $query->where(function (Builder $related) use ($article, $tagIds): void {
                    if ($article->category_id) {
                        $related->where('category_id', $article->category_id);
                    }
                    if ($tagIds->isNotEmpty()) {
                        $related->orWhereHas('tags', fn (Builder $tags): Builder => $tags->whereIn('tags.id', $tagIds));
                    }
                });
            })
            ->withCount(['tags as shared_tags_count' => fn (Builder $query) => $query->whereIn('tags.id', $tagIds)])
            ->reorder()
            ->when($article->category_id, fn (Builder $query) => $query->orderByRaw('CASE WHEN category_id = ? THEN 1 ELSE 0 END DESC', [$article->category_id]))
            ->orderByDesc('shared_tags_count')
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit(4)->get();
    }
}

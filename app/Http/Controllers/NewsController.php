<?php

namespace App\Http\Controllers;

use App\Http\Requests\NewsIndexRequest;
use App\Support\NewsFeed;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class NewsController extends Controller
{
    public function __invoke(NewsIndexRequest $request, NewsFeed $feed): View
    {
        $locale = app()->getLocale();
        $filters = $request->validated();
        $search = trim($filters['q'] ?? '');
        $categoryId = isset($filters['category']) ? (int) $filters['category'] : null;
        $topic = $filters['topic'] ?? null;
        $query = $feed->articles($locale);

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        if (in_array($topic, ['crypto', 'metals'], true)) {
            $topics = config($topic === 'crypto' ? 'news.crypto_topics' : 'news.metal_topics');
            $query->whereHas('category.translations', fn (Builder $query) => $query->where('locale', 'en')->whereIn('slug', $topics));
        }

        if (in_array($topic, ['analysis', 'opinion'], true)) {
            $query->whereHas('tags', fn (Builder $query) => $query->where('slug', $topic));
        }

        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

            $query->whereHas('translations', function (Builder $query) use ($locale, $pattern): void {
                $query->where('locale', $locale)->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("excerpt LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("body LIKE ? ESCAPE '!'", [$pattern]);
                });
            });
        }

        $articles = $query->paginate(12)->appends(array_filter([
            'q' => $search,
            'category' => $categoryId,
            'topic' => $topic,
        ], fn ($value) => $value !== null && $value !== ''));

        return view('news.index', [
            'articles' => $articles,
            'categories' => $feed->categories($locale),
            'search' => $search,
            'categoryId' => $categoryId,
            'topic' => $topic,
            'pageTitle' => __('messages.news'),
        ]);
    }
}

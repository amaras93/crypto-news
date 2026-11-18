<?php

namespace App\Http\Controllers;

use App\Support\NewsFeed;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __invoke(NewsFeed $feed): View
    {
        $locale = app()->getLocale();
        $featured = $feed->articles($locale)->first();
        $usedIds = $featured ? [$featured->id] : [];
        $breaking = $feed->selected($locale, 'breaking', 1)->first();
        if ($breaking) {
            $usedIds[] = $breaking->id;
        }
        $opinion = $feed->selected($locale, 'opinion', 1, excludeIds: $usedIds)->first();
        if ($opinion) {
            $usedIds[] = $opinion->id;
        }
        $picks = $feed->selected($locale, 'editors-pick', 3, excludeIds: $usedIds);
        $usedIds = array_merge($usedIds, $picks->modelKeys());
        $analysis = $feed->selected($locale, 'analysis', 1, excludeIds: $usedIds)->first();
        if ($analysis) {
            $usedIds[] = $analysis->id;
        }

        return view('home', [
            'featured' => $featured,
            'breaking' => $breaking,
            'opinion' => $opinion,
            'latest' => $feed->articles($locale)->whereNotIn('articles.id', $usedIds)->limit(4)->get(),
            'categories' => $feed->categories($locale),
            'picks' => $picks,
            'analysis' => $analysis,
        ]);
    }
}

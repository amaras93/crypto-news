<?php

namespace App\Http\Controllers;

use App\Models\SavedArticle;
use App\Support\CommunityLimits;
use App\Support\NewsFeed;
use App\Support\ReaderEngagement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SavedArticleController extends Controller
{
    public function index(Request $request, string $locale): View
    {
        $saved = SavedArticle::where('user_id', $request->user()->id)->with(['article' => fn (Builder|Relation $query): Builder|Relation => $query->published()
            ->with(['sponsor', 'translations' => fn (Builder|Relation $query): Builder|Relation => $query->publicReady()->whereIn('locale', array_keys(config('news.locales')))])])
            ->latest('id')->paginate(12);

        return view('community.saved', ['privatePage' => true, 'pageTitle' => __('engagement.saved'),
            'categories' => app(NewsFeed::class)->categories($locale), 'saved' => $saved]);
    }

    public function store(Request $request, string $locale, int $article, ReaderEngagement $engagement): RedirectResponse
    {
        CommunityLimits::hit('bookmarks', (string) $request->user()->id, 30, 60);
        $saved = $engagement->saveArticle($request->user(), $article, $locale);

        return redirect()->route('news.show', ['locale' => $locale, 'slug' => $saved->article->translation($locale)->slug])
            ->with('community_status', __('engagement.article_saved'));
    }

    public function destroy(Request $request, string $locale, SavedArticle $saved, ReaderEngagement $engagement): RedirectResponse
    {
        $engagement->removeSaved($request->user(), $saved);

        return redirect()->route('account.saved', ['locale' => $locale])->with('community_status', __('engagement.updated'));
    }
}

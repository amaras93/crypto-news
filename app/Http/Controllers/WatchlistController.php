<?php

namespace App\Http\Controllers;

use App\Models\WatchlistItem;
use App\Support\CommunityLimits;
use App\Support\MarketDataService;
use App\Support\NewsFeed;
use App\Support\ReaderEngagement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WatchlistController extends Controller
{
    public function index(Request $request, string $locale, ReaderEngagement $engagement, MarketDataService $markets): View
    {
        $items = WatchlistItem::where('user_id', $request->user()->id)->orderBy('position')->orderBy('id')->get();
        $snapshot = $markets->snapshot();
        $request->attributes->set('market_snapshot', $snapshot);

        return view('community.watchlist', ['privatePage' => true, 'pageTitle' => __('engagement.watchlist'),
            'categories' => app(NewsFeed::class)->categories($locale), 'items' => $items, 'snapshot' => $snapshot,
            'quotes' => collect($snapshot['quotes'])->keyBy('asset'), 'related' => $engagement->relatedNews($items->pluck('asset')->all(), $locale)]);
    }

    public function store(Request $request, string $locale, ReaderEngagement $engagement): RedirectResponse
    {
        CommunityLimits::hit('watchlist', (string) $request->user()->id, 30, 60);
        $engagement->addAsset($request->user(), $request->all());

        return redirect()->route('account.watchlist', ['locale' => $locale])->with('community_status', __('engagement.updated'));
    }

    public function destroy(Request $request, string $locale, WatchlistItem $item, ReaderEngagement $engagement): RedirectResponse
    {
        $engagement->removeAsset($request->user(), $item);

        return redirect()->route('account.watchlist', ['locale' => $locale]);
    }

    public function reorder(Request $request, string $locale, ReaderEngagement $engagement): RedirectResponse
    {
        $request->validate(['order' => ['required', 'array', 'max:30'], 'order.*' => ['required', 'integer', 'distinct']], ['*' => __('engagement.invalid_order')]);
        $engagement->reorder($request->user(), $request->input('order'));

        return redirect()->route('account.watchlist', ['locale' => $locale]);
    }

    public function move(Request $request, string $locale, WatchlistItem $item, ReaderEngagement $engagement): RedirectResponse
    {
        $request->validate(['direction' => ['required', 'in:up,down']], ['*' => __('engagement.invalid')]);
        $engagement->move($request->user(), $item, $request->input('direction'));

        return redirect()->route('account.watchlist', ['locale' => $locale]);
    }
}

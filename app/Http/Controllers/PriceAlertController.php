<?php

namespace App\Http\Controllers;

use App\Models\PriceAlert;
use App\Models\PriceAlertEvent;
use App\Support\CommunityLimits;
use App\Support\EngagementSettings;
use App\Support\NewsFeed;
use App\Support\PriceAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PriceAlertController extends Controller
{
    public function index(Request $request, string $locale): View
    {
        return view('community.alerts', ['privatePage' => true, 'pageTitle' => __('engagement.alerts'),
            'categories' => app(NewsFeed::class)->categories($locale),
            'alerts' => PriceAlert::where('user_id', $request->user()->id)->latest('id')->paginate(10, ['*'], 'alerts_page'),
            'events' => PriceAlertEvent::where('user_id', $request->user()->id)->latest('id')->paginate(10, ['*'], 'events_page'),
            'alertsActive' => config('markets.mode') === 'live' && app(EngagementSettings::class)->enabled('price_alerts_enabled')]);
    }

    public function store(Request $request, string $locale, PriceAlerts $alerts): RedirectResponse
    {
        CommunityLimits::hit('price-alerts', (string) $request->user()->id, 20, 60);
        $alerts->save($request->user(), $request->all());

        return redirect()->route('account.alerts', ['locale' => $locale])->with('community_status', __('engagement.updated'));
    }

    public function update(Request $request, string $locale, PriceAlert $alert, PriceAlerts $alerts): RedirectResponse
    {
        CommunityLimits::hit('price-alerts', (string) $request->user()->id, 20, 60);
        $alerts->save($request->user(), $request->all(), $alert);

        return redirect()->route('account.alerts', ['locale' => $locale])->with('community_status', __('engagement.updated'));
    }

    public function pause(Request $request, string $locale, PriceAlert $alert, PriceAlerts $alerts): RedirectResponse
    {
        $alerts->pause($request->user(), $alert);

        return redirect()->route('account.alerts', ['locale' => $locale]);
    }

    public function destroy(Request $request, string $locale, PriceAlert $alert, PriceAlerts $alerts): RedirectResponse
    {
        $alerts->remove($request->user(), $alert);

        return redirect()->route('account.alerts', ['locale' => $locale]);
    }
}

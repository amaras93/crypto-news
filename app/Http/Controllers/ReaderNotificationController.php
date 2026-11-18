<?php

namespace App\Http\Controllers;

use App\Notifications\PriceThresholdCrossed;
use App\Support\NewsFeed;
use App\Support\ReaderEngagement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReaderNotificationController extends Controller
{
    public function index(Request $request, string $locale): View
    {
        return view('community.notifications', ['privatePage' => true, 'pageTitle' => __('engagement.notifications'),
            'categories' => app(NewsFeed::class)->categories($locale), 'user' => $request->user(),
            'notifications' => $request->user()->notifications()->where('type', PriceThresholdCrossed::class)->latest()->paginate(15)]);
    }

    public function read(Request $request, string $locale, string $notification): RedirectResponse
    {
        $request->user()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return redirect()->route('account.notifications', ['locale' => $locale]);
    }

    public function preferences(Request $request, string $locale, ReaderEngagement $engagement): RedirectResponse
    {
        $engagement->preferences($request->user(), $request->all());

        return redirect()->route('account.notifications', ['locale' => $locale])->with('community_status', __('engagement.updated'));
    }
}

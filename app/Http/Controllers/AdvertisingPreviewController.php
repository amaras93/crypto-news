<?php

namespace App\Http\Controllers;

use App\Models\AdCampaign;
use App\Support\AdCreative;
use App\Support\AdvertisingManager;
use App\Support\AdvertisingService;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class AdvertisingPreviewController extends Controller
{
    public function __invoke(AdCampaign $campaign, string $locale, AdCreative $creatives, AdvertisingManager $manager, AdvertisingService $ads): View
    {
        abort_unless(auth()->user()?->fresh()?->is_admin, 403);
        abort_unless(array_key_exists($locale, config('news.locales')) && in_array($locale, $campaign->languages, true), 404);
        $creatives->inspect($campaign->banner_image);
        $manager->safeUrl($campaign->destination_url);
        App::setLocale($locale);

        return view('advertising.preview', ['banner' => $ads->banner($campaign, $locale)]);
    }
}

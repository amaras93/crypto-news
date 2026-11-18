<?php

namespace App\Providers;

use App\Support\AdvertisingConsent;
use App\Support\MarketDataService;
use App\Support\NoAdvertisingConsent;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AdvertisingConsent::class, NoAdvertisingConsent::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer([
            'components.newspaper-header',
            'components.newspaper-markets',
            'components.market-overview',
        ], function (\Illuminate\View\View $view): void {
            $request = request();
            if (! $request->attributes->has('market_snapshot')) {
                $request->attributes->set('market_snapshot', app(MarketDataService::class)->snapshot());
            }
            $view->with('marketSnapshot', $request->attributes->get('market_snapshot'));
        });
    }
}

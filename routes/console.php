<?php

use App\Jobs\BuildNewsDigests;
use App\Jobs\EvaluatePriceAlerts;
use App\Jobs\RefreshMarketData;
use App\Models\PriceAlert;
use App\Support\AiSettings;
use App\Support\DiscoverySettingsManager;
use App\Support\EngagementSettings;
use App\Support\MarketDataService;
use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:prune-batches --hours=168 --unfinished=168 --cancelled=168')
    ->daily()
    ->withoutOverlapping();

Schedule::command('articles:publish-scheduled')->everyMinute()->withoutOverlapping();

Schedule::command('engagement:dispatch')->everyMinute()->withoutOverlapping();
Schedule::job(new EvaluatePriceAlerts)->everyMinute()->withoutOverlapping()
    ->when(fn (): bool => PriceAlert::whereNotNull('last_price')->exists() || (config('markets.mode') === 'live'
        && app(EngagementSettings::class)->enabled('price_alerts_enabled') && PriceAlert::where('enabled', true)->exists()));
Schedule::job(new BuildNewsDigests)->everyMinute()->withoutOverlapping()
    ->when(fn (): bool => app(EngagementSettings::class)->enabled('newsletters_enabled'));

Schedule::command('ai:dispatch')->everyMinute()->withoutOverlapping()
    ->when(fn (): bool => app(AiSettings::class)->enabledFor('english') || app(AiSettings::class)->enabledFor('translation'));

Schedule::command('news:discover')->everyFifteenMinutes()
    ->when(fn (): bool => app(DiscoverySettingsManager::class)->enabled())
    ->withoutOverlapping();

foreach (['crypto', 'metals'] as $marketGroup) {
    Schedule::job(new RefreshMarketData($marketGroup))
        ->name('markets:'.$marketGroup)
        ->everyMinute()
        ->when(fn (): bool => app(MarketDataService::class)->isDue($marketGroup))
        ->withoutOverlapping();
}

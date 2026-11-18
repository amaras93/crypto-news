<?php

namespace App\Filament\Pages;

use App\Filament\EditorialForm;
use App\Models\AdCampaign;
use App\Models\AdPlacement;
use App\Models\AdvertisingAudit;
use App\Models\Article;
use App\Support\AdvertisingConsent;
use App\Support\AdvertisingSettings;
use App\Support\AdvertisingStatisticsService;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class AdvertisingControlCenter extends Page
{
    protected string $view = 'filament.pages.advertising-control-center';

    protected static string|UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->fresh()?->is_admin;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(app(AdvertisingSettings::class)->current());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Toggle::make('enabled')->label('Enable advertising')->helperText('Also requires ADVERTISING_ENABLED and a permitted preview/live environment. No campaign is approved automatically.'),
            Toggle::make('direct_enabled')->label('Enable reviewed direct banners')->helperText('Also requires DIRECT_ADVERTISING_ENABLED and enabled placements.'),
            Toggle::make('adsense_enabled')->label('Permit optional AdSense integration')->helperText('Server enablement, production/live mode, actual publisher/slot IDs, account/policy review and a real CMP adapter are all required. No certified CMP is connected by this phase.'),
            Toggle::make('adsense_reviewed')->label('I have verified the actual publisher configuration and applicable provider policies'),
            Textarea::make('provider_review_notes')->label('Private provider/account review evidence')->maxLength(4000),
            Textarea::make('ads_txt')->label('Verified ads.txt seller entries')->maxLength(10000)->rows(5)
                ->helperText('One actual authorized seller per line. Blank publishes no seller records. Google entries must match the server publisher ID.'),
            Toggle::make('ads_txt_verified')->label('I verified authorization for every configured seller'),
            Textarea::make('seller_review_notes')->label('Private seller authorization evidence')->maxLength(4000),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $state = $this->form->getState();
        EditorialForm::save(fn () => app(AdvertisingSettings::class)->save(auth()->user(), $state));
        $this->form->fill(app(AdvertisingSettings::class)->current());
        Notification::make()->title('Advertising controls saved; server and consent gates remain authoritative')->success()->send();
    }

    public function stop(): void
    {
        app(AdvertisingSettings::class)->stop(auth()->user());
        $this->form->fill(app(AdvertisingSettings::class)->current());
        Notification::make()->title('Banner and network advertising stopped')->success()->send();
    }

    public function statistics(): array
    {
        $approved = AdCampaign::where('status', 'active')->whereNotNull('approved_at')->whereHas('approver', fn ($query) => $query->where('is_admin', true))->whereHas('advertiser', fn ($query) => $query->where('status', 'active'));
        $metrics = app(AdvertisingStatisticsService::class)->summary();

        return ['Currently scheduled eligible dates' => (clone $approved)->where('starts_at', '<=', now())->where('ends_at', '>', now())->count(),
            'Future approved campaigns' => (clone $approved)->where('starts_at', '>', now())->count(),
            'Expired campaigns' => AdCampaign::where('ends_at', '<=', now())->count(),
            'Configured placements' => AdPlacement::count(), 'Enabled placements' => AdPlacement::where('enabled', true)->count(),
            'Sponsored articles (includes drafts)' => Article::whereNotNull('sponsor_classification')->count(),
            'Origin slot requests' => $metrics['slot_requests'], 'Eligible server HTML deliveries' => $metrics['eligible_server_deliveries'],
            'Visible impressions / clicks / revenue' => 'Unavailable — not measured',
            'AdSense reporting' => 'Not connected', 'Consent integration' => app(AdvertisingConsent::class)->providerName() ?? 'Not connected — external advertising blocked',
            'Global advertising' => app(AdvertisingSettings::class)->enabled() ? 'Enabled by both controls' : 'Disabled',
            'Direct delivery' => app(AdvertisingSettings::class)->directEnabled() ? 'Enabled; campaign eligibility still applies' : 'Disabled',
            'Actual publisher ID configured' => app(AdvertisingSettings::class)->validPublisher() ? 'Configured; external approval unverified' : 'Not configured',
            'Server advertising mode' => (string) config('advertising.mode')];
    }

    public function recentAudits(): Collection
    {
        return AdvertisingAudit::latest('id')->limit(20)->get();
    }
}

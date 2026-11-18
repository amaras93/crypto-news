<?php

namespace App\Filament\Pages;

use App\Filament\EditorialForm;
use App\Models\EngagementDelivery;
use App\Models\NewsletterSubscriber;
use App\Models\PriceAlert;
use App\Models\WatchlistItem;
use App\Support\EngagementSettings;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class EngagementControlCenter extends Page
{
    protected string $view = 'filament.pages.engagement-control-center';

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(app(EngagementSettings::class)->current());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Toggle::make('price_alerts_enabled')->label('Enable price alert processing')->helperText('Also requires the server flag, LIVE mode, current validated cache and a genuine crossing. DEMO never sends alerts.'),
            Toggle::make('newsletters_enabled')->label('Enable scheduled newsletters and audience delivery')->helperText('Also requires NEWSLETTERS_ENABLED. Only double-confirmed subscriptions and eligible published localized stories are selected.'),
            Toggle::make('real_email_enabled')->label('Permit configured SMTP transport')->helperText('Also requires ENGAGEMENT_REAL_EMAIL_ENABLED. Verify SMTP acceptance and sender authentication before activation. Local log/array mail is never recorded as inbox delivery.'),
            TextInput::make('daily_at')->label('Daily digest time (UTC, HH:MM)')->rules(['date_format:H:i'])->required(),
            TextInput::make('weekly_day')->label('Weekly day (0 Sunday–6 Saturday, UTC)')->integer()->minValue(0)->maxValue(6)->required(),
            TextInput::make('weekly_at')->label('Weekly digest time (UTC, HH:MM)')->rules(['date_format:H:i'])->required(),
            TextInput::make('emails_per_minute')->integer()->minValue(1)->maxValue(600)->required(),
            TextInput::make('batch_size')->integer()->minValue(1)->maxValue(500)->required(),
        ]);
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->fresh()?->is_admin, 403);
        $state = $this->form->getState();
        EditorialForm::save(fn () => app(EngagementSettings::class)->save(auth()->user(), $state));
        Notification::make()->title('Engagement controls saved; server flags still apply')->success()->send();
    }

    public function stop(): void
    {
        app(EngagementSettings::class)->stop(auth()->user());
        $this->form->fill(app(EngagementSettings::class)->current());
        Notification::make()->title('Engagement sending and price checks stopped')->success()->send();
    }

    public function statistics(): array
    {
        $confirmed = NewsletterSubscriber::whereNotNull('confirmed_at')->whereNull('unsubscribed_at');

        return [
            'Confirmed subscribers' => (clone $confirmed)->count(),
            'Daily subscriptions' => (clone $confirmed)->where('frequency', 'daily')->count(),
            'Weekly subscriptions' => (clone $confirmed)->where('frequency', 'weekly')->count(),
            'Active personal watchlists' => WatchlistItem::distinct()->count('user_id'),
            'Active price alerts' => PriceAlert::where('enabled', true)->count(),
            'In-app notifications stored' => EngagementDelivery::where('status', 'stored')->count(),
            'Local-only email outputs' => EngagementDelivery::where('status', 'local')->count(),
            'SMTP transport acceptances (not inbox receipts)' => EngagementDelivery::where('status', 'accepted')->count(),
            'Failed unsent / uncertain deliveries' => EngagementDelivery::whereIn('status', ['failed', 'uncertain'])->count(),
            'Configured mailer' => (string) config('mail.default'),
            'Price alert evaluation' => config('markets.mode') === 'live' && app(EngagementSettings::class)->enabled('price_alerts_enabled') ? 'Enabled against current LIVE cache' : 'Paused',
            'Scheduled newsletters' => app(EngagementSettings::class)->enabled('newsletters_enabled') ? 'Enabled' : 'Disabled',
        ];
    }

    public function recentDeliveries(): Collection
    {
        return EngagementDelivery::latest('created_at')->limit(10)->get();
    }
}

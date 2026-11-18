<?php

namespace App\Filament\Resources\NewsletterSubscribers;

use App\Filament\Resources\NewsletterSubscribers\Pages\ManageNewsletterSubscribers;
use App\Models\NewsletterConsent;
use App\Models\NewsletterSubscriber;
use App\Support\NewsletterSubscriptions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class NewsletterSubscriberResource extends Resource
{
    protected static ?string $model = NewsletterSubscriber::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?int $navigationSort = 2;

    public static function status(NewsletterSubscriber $record): string
    {
        return $record->unsubscribed_at ? 'Unsubscribed' : ($record->confirmed_at ? 'Confirmed' : 'Pending double opt-in');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('email'), TextEntry::make('locale'), TextEntry::make('frequency'),
            TextEntry::make('subscription_status')->state(fn (NewsletterSubscriber $record): string => static::status($record)),
            TextEntry::make('consent_version'), TextEntry::make('consent_at')->dateTime(),
            TextEntry::make('confirmed_at')->dateTime(), TextEntry::make('unsubscribed_at')->dateTime(),
            TextEntry::make('confirmation_expires_at')->dateTime(),
            TextEntry::make('consent_audit')->state(fn (NewsletterSubscriber $record): array => NewsletterConsent::where('newsletter_subscriber_id', $record->id)->latest('id')->limit(20)->get()->map(fn ($consent): string => $consent->action.' · '.$consent->locale.' · '.$consent->frequency.' · '.$consent->created_at->toIso8601String())->all())->listWithLineBreaks()->columnSpanFull()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('email')->label('Email (administrator only)')->searchable(query: fn (Builder $query, string $search): Builder => $query->where('email_hash', app(NewsletterSubscriptions::class)->emailHash($search))),
            TextColumn::make('locale')->badge(), TextColumn::make('frequency')->badge(),
            TextColumn::make('subscription_status')->state(fn (NewsletterSubscriber $record): string => static::status($record))->badge(),
            TextColumn::make('confirmed_at')->dateTime(), TextColumn::make('unsubscribed_at')->dateTime()])
            ->filters([SelectFilter::make('locale')->options(config('news.locales')), SelectFilter::make('frequency')->options(['daily' => 'Daily', 'weekly' => 'Weekly'])])
            ->recordActions([ViewAction::make(), Action::make('unsubscribe')->label('Withdraw subscription')->requiresConfirmation()
                ->visible(fn (NewsletterSubscriber $record): bool => ! $record->unsubscribed_at)
                ->action(fn (NewsletterSubscriber $record) => app(NewsletterSubscriptions::class)->unsubscribe($record, auth()->user()))])
            ->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageNewsletterSubscribers::route('/')];
    }
}

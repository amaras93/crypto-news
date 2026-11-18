<?php

namespace App\Filament\Resources\NewsletterCampaigns;

use App\Filament\EditorialForm;
use App\Filament\Resources\NewsletterCampaigns\Pages\ManageNewsletterCampaigns;
use App\Models\NewsletterCampaign;
use App\Support\EngagementDeliveries;
use App\Support\NewsDigests;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class NewsletterCampaignResource extends Resource
{
    protected static ?string $model = NewsletterCampaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?int $navigationSort = 3;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('id'), TextEntry::make('locale'), TextEntry::make('frequency'),
            TextEntry::make('status')->badge(), TextEntry::make('period_start')->dateTime(), TextEntry::make('period_end')->dateTime(),
            TextEntry::make('article_ids')->listWithLineBreaks(), TextEntry::make('approver.name'),
            TextEntry::make('approved_at')->dateTime(), TextEntry::make('audience_cursor'),
            TextEntry::make('delivery_note')->state('Audience queued is not a delivery receipt. Local transports are not inbox delivery; SMTP acceptance is recorded separately.')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('id')->sortable(), TextColumn::make('locale')->badge(), TextColumn::make('frequency'),
            TextColumn::make('status')->badge(), TextColumn::make('period_start')->dateTime(), TextColumn::make('period_end')->dateTime(),
            TextColumn::make('approved_at')->dateTime()])
            ->recordActions([ViewAction::make(),
                Action::make('preview')->url(fn (NewsletterCampaign $record): string => route('engagement.preview', ['campaign' => $record]))->openUrlInNewTab(),
                Action::make('approve')->requiresConfirmation()->visible(fn (NewsletterCampaign $record): bool => $record->status === 'draft')
                    ->action(fn (NewsletterCampaign $record, Action $action) => EditorialForm::saveForAction(fn () => app(NewsDigests::class)->approve(auth()->user(), $record), $action)),
                Action::make('queue_audience')->label('Queue eligible audience')->requiresConfirmation()
                    ->modalDescription('Requires both newsletter enable flags. Only confirmed subscribers whose current language/frequency match can receive the digest. This action does not confirm inbox delivery.')
                    ->visible(fn (NewsletterCampaign $record): bool => in_array($record->status, ['approved', 'queuing'], true))
                    ->action(fn (NewsletterCampaign $record) => app(NewsDigests::class)->dispatchAudience($record)),
                Action::make('test_to_self')->label('Send test to my verified address')->requiresConfirmation()
                    ->modalDescription('Uses the configured mailer. Local log/array mail is recorded as local only. SMTP needs explicit server and administrator enablement. Limited to five tests per hour.')
                    ->action(fn (NewsletterCampaign $record) => app(EngagementDeliveries::class)->manualTest(auth()->user(), $record)),
                Action::make('cancel')->requiresConfirmation()->visible(fn (NewsletterCampaign $record): bool => $record->status !== 'cancelled')
                    ->action(fn (NewsletterCampaign $record) => app(NewsDigests::class)->cancel(auth()->user(), $record)),
            ])->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageNewsletterCampaigns::route('/')];
    }
}

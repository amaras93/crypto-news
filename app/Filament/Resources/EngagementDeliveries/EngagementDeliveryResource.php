<?php

namespace App\Filament\Resources\EngagementDeliveries;

use App\Filament\EditorialForm;
use App\Filament\Resources\EngagementDeliveries\Pages\ManageEngagementDeliveries;
use App\Models\EngagementDelivery;
use App\Support\EngagementDeliveries;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class EngagementDeliveryResource extends Resource
{
    protected static ?string $model = EngagementDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Notification Delivery Logs';

    protected static ?int $navigationSort = 5;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('id'), TextEntry::make('kind'), TextEntry::make('channel'), TextEntry::make('status')->badge(),
            TextEntry::make('locale'), TextEntry::make('user_id'), TextEntry::make('newsletter_subscriber_id'),
            TextEntry::make('attempts'), TextEntry::make('dispatch_failures'), TextEntry::make('transport'),
            TextEntry::make('provider_message_id'), TextEntry::make('error_code'), TextEntry::make('queued_at')->dateTime(),
            TextEntry::make('sending_at')->dateTime(), TextEntry::make('completed_at')->dateTime(),
            TextEntry::make('operator_resolution')->state(fn (EngagementDelivery $record): ?string => isset($record->payload['operator_resolution']) ? json_encode($record->payload['operator_resolution'], JSON_UNESCAPED_UNICODE) : null)->columnSpanFull(),
            TextEntry::make('status_note')->state('queued: pending work; stored: private database notification; local: log/array only; accepted: SMTP transport acceptance, not inbox receipt; uncertain: no automatic resend; closed: audited without resending.')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('kind')->badge(), TextColumn::make('channel'), TextColumn::make('locale'),
            TextColumn::make('status')->badge(), TextColumn::make('attempts'), TextColumn::make('transport'),
            TextColumn::make('error_code')->wrap(), TextColumn::make('created_at')->dateTime()])
            ->filters([SelectFilter::make('status')->options(array_combine(['queued', 'stored', 'local', 'accepted', 'failed', 'uncertain', 'skipped', 'closed'], ['Queued', 'Stored in-app', 'Local only', 'SMTP accepted', 'Failed before send', 'Uncertain — do not resend', 'Skipped', 'Closed without resend']))])
            ->recordActions([ViewAction::make(),
                Action::make('retry_unsent')->label('Recover unsent delivery')->requiresConfirmation()
                    ->modalDescription('Only work with no send attempt can be recovered. Restore queue health first. Unknown SMTP outcomes cannot be retried; consult provider logs. Maximum six explicit recoveries.')
                    ->visible(fn (EngagementDelivery $record): bool => $record->attempts === 0 && ($record->status === 'failed' || ($record->status === 'queued' && $record->queued_at?->lessThan(now()->subHour()))))
                    ->action(fn (EngagementDelivery $record) => app(EngagementDeliveries::class)->retryUnsent(auth()->user(), $record)),
                Action::make('resolve_unknown')->label('Record transport investigation')->requiresConfirmation()
                    ->visible(fn (EngagementDelivery $record): bool => $record->status === 'uncertain' && $record->channel === 'mail')
                    ->modalDescription('Inspect queue and SMTP logs first. This records your evidence and never resends the message. Transport acceptance does not confirm inbox delivery.')
                    ->schema([
                        Select::make('outcome')->options(['closed' => 'Close without resending', 'accepted' => 'SMTP logs confirm transport acceptance'])->required(),
                        TextInput::make('message_id')->label('Verified SMTP message ID')->maxLength(255),
                        Textarea::make('notes')->label('Evidence and investigation notes (private)')->required()->minLength(30)->maxLength(1000),
                    ])
                    ->action(fn (EngagementDelivery $record, array $data, Action $action) => EditorialForm::saveForAction(fn () => app(EngagementDeliveries::class)->resolveUnknown(auth()->user(), $record, $data['outcome'] === 'accepted', $data['message_id'] ?? null, $data['notes']), $action)),
            ])->defaultSort('created_at', 'desc')->paginationPageOptions([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageEngagementDeliveries::route('/')];
    }
}

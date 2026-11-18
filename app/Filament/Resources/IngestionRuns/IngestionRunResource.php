<?php

namespace App\Filament\Resources\IngestionRuns;

use App\Filament\Resources\IngestionRuns\Pages\ManageIngestionRuns;
use App\IngestionStatus;
use App\Models\IngestionRun;
use App\Support\NewsDiscovery;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class IngestionRunResource extends Resource
{
    protected static ?string $model = IngestionRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Editorial';

    protected static ?int $navigationSort = 7;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('source.name'), TextEntry::make('status')->badge(), TextEntry::make('attempts'),
            TextEntry::make('error_code')->placeholder('No error'),
            TextEntry::make('items_seen'), TextEntry::make('created_count'), TextEntry::make('linked_count'),
            TextEntry::make('duplicate_count'), TextEntry::make('invalid_count'),
            TextEntry::make('item_errors_summary')->state(fn (IngestionRun $record): string => json_encode($record->item_errors ?? [], JSON_THROW_ON_ERROR)),
            TextEntry::make('queued_at')->dateTime(), TextEntry::make('started_at')->dateTime(),
            TextEntry::make('finished_at')->dateTime(), TextEntry::make('retry_at')->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(), TextColumn::make('source.name')->searchable(),
            TextColumn::make('status')->badge()->sortable(), TextColumn::make('attempts'),
            TextColumn::make('created_count')->label('New candidates'), TextColumn::make('linked_count')->label('Existing references'),
            TextColumn::make('invalid_count'), TextColumn::make('error_code'),
            TextColumn::make('finished_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->options(['queued' => 'Queued', 'running' => 'Running', 'succeeded' => 'Succeeded', 'failed' => 'Failed', 'skipped' => 'Skipped']),
        ])->recordActions([
            ViewAction::make(),
            Action::make('retry')->requiresConfirmation()->visible(fn (IngestionRun $record): bool => (bool) auth()->user()?->is_admin && $record->status === IngestionStatus::Failed)
                ->action(function (IngestionRun $record): void {
                    $queued = app(NewsDiscovery::class)->retry(auth()->user(), $record);
                    Notification::make()->title($queued ? 'Eligible retry queued' : 'Retry not eligible yet or attempt cap reached')->send();
                }),
        ])->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('source:id,name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageIngestionRuns::route('/')];
    }
}

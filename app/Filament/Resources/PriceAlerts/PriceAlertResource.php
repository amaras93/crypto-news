<?php

namespace App\Filament\Resources\PriceAlerts;

use App\Filament\Resources\PriceAlerts\Pages\ManagePriceAlerts;
use App\Models\PriceAlert;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PriceAlertResource extends Resource
{
    protected static ?string $model = PriceAlert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Price Alert Overview';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('user.name'), TextEntry::make('asset'), TextEntry::make('currency'), TextEntry::make('unit'),
            TextEntry::make('target_price'), TextEntry::make('direction'), TextEntry::make('enabled')->badge(),
            TextEntry::make('last_state'), TextEntry::make('last_price'), TextEntry::make('last_triggered_at')->dateTime(),
            TextEntry::make('in_app'), TextEntry::make('email')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('user.name'), TextColumn::make('asset'), TextColumn::make('target_price'),
            TextColumn::make('direction'), TextColumn::make('unit'), TextColumn::make('enabled')->badge(), TextColumn::make('last_triggered_at')->dateTime()])
            ->recordActions([ViewAction::make()])->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user:id,name');
    }

    public static function getPages(): array
    {
        return ['index' => ManagePriceAlerts::route('/')];
    }
}

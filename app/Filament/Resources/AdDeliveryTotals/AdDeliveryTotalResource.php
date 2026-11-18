<?php

namespace App\Filament\Resources\AdDeliveryTotals;

use App\Filament\Resources\AdDeliveryTotals\Pages\ManageAdDeliveryTotals;
use App\Models\AdDeliveryTotal;
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

class AdDeliveryTotalResource extends Resource
{
    protected static ?string $model = AdDeliveryTotal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?string $navigationLabel = 'Advertising Reports';

    protected static ?int $navigationSort = 6;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('day')->date(), TextEntry::make('placement.key'), TextEntry::make('campaign.name'), TextEntry::make('locale'),
            TextEntry::make('requests')->label('Origin slot lookup requests'), TextEntry::make('eligible_deliveries')->label('Eligible server HTML deliveries — not visible impressions'),
            TextEntry::make('measurement_note')->state('Visible impressions, clicks, CTR, CPM/RPM and revenue: unavailable. AdSense metrics: not connected. No visitor identifiers or click redirects are collected.')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('day')->date(), TextColumn::make('placement.key'), TextColumn::make('campaign.name')->placeholder('Placement lookup only'),
            TextColumn::make('locale'), TextColumn::make('requests')->label('Slot requests'), TextColumn::make('eligible_deliveries')->label('Eligible HTML deliveries')])
            ->recordActions([ViewAction::make()])->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['campaign:id,name', 'placement:id,key']);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAdDeliveryTotals::route('/')];
    }
}

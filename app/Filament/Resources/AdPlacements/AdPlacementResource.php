<?php

namespace App\Filament\Resources\AdPlacements;

use App\Filament\EditorialForm;
use App\Filament\Resources\AdPlacements\Pages\ManageAdPlacements;
use App\Models\AdPlacement;
use App\Support\AdvertisingManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class AdPlacementResource extends Resource
{
    protected static ?string $model = AdPlacement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()->maxLength(120), Toggle::make('enabled'),
            Select::make('provider')->options(['direct' => 'Direct approved image', 'adsense' => 'AdSense — external, blocked until configured and consent authorized'])->required(),
            TextInput::make('adsense_slot_id')->label('Actual AdSense slot ID (optional)')->maxLength(16)->helperText('Use the ID from your approved provider account; leave empty until available. Desktop-only positions serve direct banners only.')]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('key'), TextEntry::make('name'), TextEntry::make('provider'), TextEntry::make('enabled'), TextEntry::make('adsense_slot_id')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('key'), TextColumn::make('name')->wrap(), TextColumn::make('enabled')->badge(), TextColumn::make('provider')])
            ->recordActions([ViewAction::make(), EditAction::make()->using(fn (AdPlacement $record, array $data, Action $action): AdPlacement => EditorialForm::saveForAction(fn (): AdPlacement => app(AdvertisingManager::class)->placement(auth()->user(), $record, $data), $action))])
            ->defaultSort('id')->paginationPageOptions([10, 25]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAdPlacements::route('/')];
    }
}

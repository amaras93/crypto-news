<?php

namespace App\Filament\Resources\Advertisers;

use App\Filament\EditorialForm;
use App\Filament\Resources\Advertisers\Pages\ManageAdvertisers;
use App\Models\Advertiser;
use App\Support\AdvertisingManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class AdvertiserResource extends Resource
{
    protected static ?string $model = Advertiser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('business_name')->required()->maxLength(180),
            TextInput::make('public_brand_name')->required()->maxLength(120), Textarea::make('contact_information')->maxLength(3000),
            TextInput::make('website')->url()->maxLength(2048), Textarea::make('internal_notes')->maxLength(5000),
            Select::make('status')->options(['pending' => 'Pending verification', 'active' => 'Active verified advertiser', 'paused' => 'Paused'])->default('pending')->required()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('business_name'), TextEntry::make('public_brand_name'), TextEntry::make('status'),
            TextEntry::make('contact_information'), TextEntry::make('website'), TextEntry::make('internal_notes')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('business_name')->searchable(), TextColumn::make('public_brand_name')->searchable(),
            TextColumn::make('status')->badge(), TextColumn::make('updated_at')->dateTime()])
            ->recordActions([ViewAction::make(), EditAction::make()->using(fn (Advertiser $record, array $data, Action $action): Advertiser => EditorialForm::saveForAction(fn (): Advertiser => app(AdvertisingManager::class)->advertiser(auth()->user(), $data, $record), $action))])
            ->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAdvertisers::route('/')];
    }
}

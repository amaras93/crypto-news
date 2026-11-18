<?php

namespace App\Filament\Resources\AdCampaigns;

use App\Filament\AdvertisingForms;
use App\Filament\EditorialForm;
use App\Filament\Resources\AdCampaigns\Pages\ManageAdCampaigns;
use App\Models\AdCampaign;
use App\Models\AdPlacement;
use App\Models\Advertiser;
use App\Support\AdCreative;
use App\Support\AdvertisingManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use UnitEnum;

class AdCampaignResource extends Resource
{
    protected static ?string $model = AdCampaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        $alt = [];
        foreach (config('news.locales') as $locale => $name) {
            $alt[] = TextInput::make('alt_text.'.$locale)->label('Truthful banner text — '.$name)->maxLength(240);
        }

        return $schema->components([Select::make('advertiser_id')->options(fn (): array => Advertiser::orderBy('business_name')->pluck('business_name', 'id')->all())->required(),
            TextInput::make('name')->required()->maxLength(180),
            FileUpload::make('banner_image')->image()->disk('public')->visibility('public')->directory('advertising/creatives')->required()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048)
                ->saveUploadedFileUsing(fn (UploadedFile $file): string => app(AdCreative::class)->store(auth()->user(), $file))
                ->helperText('Static raster only; 80×40 to 2400×1600, input up to 2 MB. Re-encoded to PNG without original metadata. Every change returns this campaign to draft.'),
            TextInput::make('destination_url')->url()->required()->maxLength(2048),
            Select::make('placement_ids')->options(fn (): array => AdPlacement::orderBy('id')->pluck('name', 'id')->all())->multiple()->required(),
            Select::make('languages')->options(config('news.locales'))->multiple()->default(['en'])->required(), ...$alt,
            DateTimePicker::make('starts_at')->label('Start (UTC)')->timezone('UTC')->seconds(false)->required(),
            DateTimePicker::make('ends_at')->label('End (UTC, exclusive)')->timezone('UTC')->seconds(false)->required(),
            Select::make('risk_category')->options(config('advertising.risk_categories'))->default('general')->required(),
            TextInput::make('priority')->integer()->minValue(0)->maxValue(1000)->default(0)->required(),
            TextInput::make('contract_amount')->label('Private contracted amount (no billing)')->maxLength(15),
            TextInput::make('currency')->length(3)->default('USD')->required()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('name'), TextEntry::make('advertiser.business_name'), TextEntry::make('status')->badge(),
            TextEntry::make('languages')->badge(), TextEntry::make('starts_at')->dateTime(), TextEntry::make('ends_at')->dateTime(), TextEntry::make('risk_category'),
            TextEntry::make('contract_amount')->label('Private contract amount — not revenue'), TextEntry::make('currency'), TextEntry::make('review_notes'),
            TextEntry::make('approved_at')->dateTime(), TextEntry::make('approver.name')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable(), TextColumn::make('advertiser.public_brand_name'), TextColumn::make('status')->badge(),
            TextColumn::make('risk_category')->wrap(), TextColumn::make('starts_at')->dateTime(), TextColumn::make('ends_at')->dateTime()])
            ->filters([SelectFilter::make('status')->options(['draft' => 'Draft', 'active' => 'Approved active / scheduled', 'paused' => 'Paused'])])
            ->recordActions([ViewAction::make(), EditAction::make()->fillForm(fn (AdCampaign $record): array => app(AdvertisingManager::class)->campaignData($record))
                ->using(fn (AdCampaign $record, array $data, Action $action): AdCampaign => EditorialForm::saveForAction(fn (): AdCampaign => app(AdvertisingManager::class)->campaign(auth()->user(), $data, $record), $action)),
                Action::make('preview')->url(fn (AdCampaign $record): string => route('advertising.preview', ['campaign' => $record, 'locale' => $record->languages[0]]))->openUrlInNewTab(),
                Action::make('approve')->label('Review and approve campaign')->schema(AdvertisingForms::review())->requiresConfirmation()
                    ->modalDescription('Review the actual creative and destination. Language targeting does not restrict countries. This action records human review; it does not provide legal or Google approval.')
                    ->visible(fn (AdCampaign $record): bool => $record->status !== 'active')
                    ->action(fn (AdCampaign $record, array $data, Action $action) => EditorialForm::saveForAction(fn () => app(AdvertisingManager::class)->approve(auth()->user(), $record, $data), $action)),
                Action::make('pause')->requiresConfirmation()->visible(fn (AdCampaign $record): bool => $record->status === 'active')
                    ->action(fn (AdCampaign $record) => app(AdvertisingManager::class)->pause(auth()->user(), $record))])
            ->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['advertiser', 'approver', 'placements']);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAdCampaigns::route('/')];
    }
}

<?php

namespace App\Filament\Resources\Advertisers\Pages;

use App\Filament\EditorialForm;
use App\Filament\Resources\Advertisers\AdvertiserResource;
use App\Models\Advertiser;
use App\Support\AdvertisingManager;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAdvertisers extends ManageRecords
{
    protected static string $resource = AdvertiserResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data, Action $action): Advertiser => EditorialForm::saveForAction(fn (): Advertiser => app(AdvertisingManager::class)->advertiser(auth()->user(), $data), $action))];
    }
}

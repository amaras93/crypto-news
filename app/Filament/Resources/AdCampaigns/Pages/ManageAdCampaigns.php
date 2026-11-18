<?php

namespace App\Filament\Resources\AdCampaigns\Pages;

use App\Filament\EditorialForm;
use App\Filament\Resources\AdCampaigns\AdCampaignResource;
use App\Models\AdCampaign;
use App\Support\AdvertisingManager;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAdCampaigns extends ManageRecords
{
    protected static string $resource = AdCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data, Action $action): AdCampaign => EditorialForm::saveForAction(fn (): AdCampaign => app(AdvertisingManager::class)->campaign(auth()->user(), $data), $action))];
    }
}

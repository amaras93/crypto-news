<?php

namespace App\Filament\Resources\EngagementDeliveries\Pages;

use App\Filament\Resources\EngagementDeliveries\EngagementDeliveryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageEngagementDeliveries extends ManageRecords
{
    protected static string $resource = EngagementDeliveryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

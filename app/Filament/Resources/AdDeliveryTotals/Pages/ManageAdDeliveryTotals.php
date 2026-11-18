<?php

namespace App\Filament\Resources\AdDeliveryTotals\Pages;

use App\Filament\Resources\AdDeliveryTotals\AdDeliveryTotalResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAdDeliveryTotals extends ManageRecords
{
    protected static string $resource = AdDeliveryTotalResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

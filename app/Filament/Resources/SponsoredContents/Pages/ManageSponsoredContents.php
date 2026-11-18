<?php

namespace App\Filament\Resources\SponsoredContents\Pages;

use App\Filament\Resources\SponsoredContents\SponsoredContentResource;
use Filament\Resources\Pages\ManageRecords;

class ManageSponsoredContents extends ManageRecords
{
    protected static string $resource = SponsoredContentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

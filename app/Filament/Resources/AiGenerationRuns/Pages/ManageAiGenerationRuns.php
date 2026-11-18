<?php

namespace App\Filament\Resources\AiGenerationRuns\Pages;

use App\Filament\Resources\AiGenerationRuns\AiGenerationRunResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAiGenerationRuns extends ManageRecords
{
    protected static string $resource = AiGenerationRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

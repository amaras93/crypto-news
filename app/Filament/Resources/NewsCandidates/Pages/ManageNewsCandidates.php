<?php

namespace App\Filament\Resources\NewsCandidates\Pages;

use App\Filament\Resources\NewsCandidates\NewsCandidateResource;
use Filament\Resources\Pages\ManageRecords;

class ManageNewsCandidates extends ManageRecords
{
    protected static string $resource = NewsCandidateResource::class;
}

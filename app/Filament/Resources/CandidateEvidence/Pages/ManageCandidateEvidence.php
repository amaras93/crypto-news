<?php

namespace App\Filament\Resources\CandidateEvidence\Pages;

use App\Filament\Resources\CandidateEvidence\CandidateEvidenceResource;
use App\Models\CandidateEvidence;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCandidateEvidence extends ManageRecords
{
    protected static string $resource = CandidateEvidenceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data, Action $action): CandidateEvidence => CandidateEvidenceResource::persist($data, $action))];
    }
}

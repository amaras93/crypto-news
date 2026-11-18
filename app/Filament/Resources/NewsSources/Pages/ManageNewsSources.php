<?php

namespace App\Filament\Resources\NewsSources\Pages;

use App\Filament\EditorialForm;
use App\Filament\Resources\NewsSources\NewsSourceResource;
use App\Models\NewsSource;
use App\Support\NewsSourceManager;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageNewsSources extends ManageRecords
{
    protected static string $resource = NewsSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data, Action $action): NewsSource => EditorialForm::saveForAction(fn (): NewsSource => app(NewsSourceManager::class)->save(auth()->user(), $data), $action))];
    }
}

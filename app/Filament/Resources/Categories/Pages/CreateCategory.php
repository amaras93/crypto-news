<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\EditorialForm;
use App\Filament\Resources\Categories\CategoryResource;
use App\Support\EditorialTaxonomy;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return EditorialForm::save(fn (): Model => app(EditorialTaxonomy::class)->category(auth()->user(), $data));
    }
}

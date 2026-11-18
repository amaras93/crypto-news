<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\EditorialForm;
use App\Filament\Resources\Categories\CategoryResource;
use App\Support\EditorialTaxonomy;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $translations = [];
        foreach (array_keys(config('news.locales')) as $locale) {
            $translation = $this->getRecord()->translation($locale);
            $translations[$locale] = $translation ? array_merge($translation->only(['name', 'slug', 'description', 'meta_title', 'meta_description']), ['enabled' => true]) : ['enabled' => false];
        }

        return ['sort_order' => $this->getRecord()->sort_order, 'translations' => $translations];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $updated = EditorialForm::save(fn (): Model => app(EditorialTaxonomy::class)->category(auth()->user(), $data, $record));
        $record->setRawAttributes($updated->getAttributes(), true);
        $record->setRelations($updated->getRelations());

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->using(function (Model $record): bool {
            app(EditorialTaxonomy::class)->delete(auth()->user(), $record);

            return true;
        })];
    }
}

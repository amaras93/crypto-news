<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\EditorialForm;
use App\Filament\Resources\Articles\ArticleResource;
use App\Support\ArticleEditor;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data['featured_image'] = $data['featured_image_upload'] ?? ($data['featured_image'] ?? null);

        return EditorialForm::save(fn (): Model => app(ArticleEditor::class)->save(auth()->user(), $data));
    }
}

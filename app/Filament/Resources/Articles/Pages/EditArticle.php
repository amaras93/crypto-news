<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\AiEditorialActions;
use App\Filament\EditorialForm;
use App\Filament\Resources\Articles\ArticleResource;
use App\Support\ArticleEditor;
use App\Support\ArticlePublication;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return app(ArticleEditor::class)->formData($this->getRecord());
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $data['featured_image'] = $data['featured_image_upload'] ?? ($data['featured_image'] ?? null);

        $updated = EditorialForm::save(fn (): Model => app(ArticleEditor::class)->save(auth()->user(), $data, $record));
        $record->setRawAttributes($updated->getAttributes(), true);
        $record->setRelations($updated->getRelations());

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            ...ArticleResource::workflowActions(fn () => $this->save(shouldRedirect: false, shouldSendSavedNotification: false)),
            ...AiEditorialActions::articles(fn () => $this->save(shouldRedirect: false, shouldSendSavedNotification: false)),
            Action::make('trash')->label('Move to trash')->color('danger')->requiresConfirmation()->authorize('delete')
                ->action(function (): void {
                    app(ArticlePublication::class)->trash(auth()->user(), $this->getRecord());
                    $this->redirect(ArticleResource::getUrl());
                }),
        ];
    }
}

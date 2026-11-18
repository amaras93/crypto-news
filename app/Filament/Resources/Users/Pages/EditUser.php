<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\EditorialForm;
use App\Filament\Resources\Users\UserResource;
use App\Support\EditorialUsers;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ['name' => $this->getRecord()->name, 'email' => $this->getRecord()->email,
            'role' => $this->getRecord()->is_admin ? 'administrator' : ($this->getRecord()->is_editor ? 'editor' : 'reader')];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $updated = EditorialForm::save(fn (): Model => app(EditorialUsers::class)->save(auth()->user(), $data, $record));
        $record->setRawAttributes($updated->getAttributes(), true);

        return $record;
    }
}

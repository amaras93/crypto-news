<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\EditorialForm;
use App\Filament\Resources\Users\UserResource;
use App\Support\EditorialUsers;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return EditorialForm::save(fn (): Model => app(EditorialUsers::class)->save(auth()->user(), $data));
    }
}

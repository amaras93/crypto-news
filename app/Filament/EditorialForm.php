<?php

namespace App\Filament;

use Closure;
use Filament\Actions\Action;
use Illuminate\Validation\ValidationException;

class EditorialForm
{
    public static function save(Closure $operation, string $statePath = 'data'): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [$statePath.'.'.$key => $messages])->all());
        }
    }

    public static function saveForAction(Closure $operation, Action $action): mixed
    {
        $livewire = $action->getLivewire();
        $schemaName = $livewire->getMountedActionSchemaName();
        $statePath = $schemaName ? ($livewire->getSchema($schemaName)?->getStatePath() ?? 'data') : 'data';

        return self::save($operation, $statePath);
    }
}

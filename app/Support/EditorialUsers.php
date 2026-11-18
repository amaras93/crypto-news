<?php

namespace App\Support;

use App\Models\User;
use App\Rules\SafePasswordBytes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class EditorialUsers
{
    /** @param array<string, mixed> $data */
    public function save(User $actor, array $data, ?User $user = null): User
    {
        $actor = $actor->fresh() ?? throw new AuthorizationException;
        Gate::forUser($actor)->authorize($user ? 'update' : 'create', $user ?? User::class);

        return DB::transaction(function () use ($actor, $data, $user): User {
            User::query()->where('is_admin', true)->orderBy('id')->lockForUpdate()->get();
            $actor = $actor->fresh() ?? throw new AuthorizationException;
            Gate::forUser($actor)->authorize($user ? 'update' : 'create', $user ?? User::class);
            $data['email'] = is_string($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;
            $values = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
                'role' => ['required', Rule::in(['administrator', 'editor', 'reader'])],
                'password' => ['bail', $user ? 'nullable' : 'required', 'string', 'max:64', new SafePasswordBytes, Password::min(12)->mixedCase()->numbers()->symbols()],
                'is_admin' => ['prohibited'], 'is_editor' => ['prohibited'],
            ])->validate();
            $record = $user ? User::query()->lockForUpdate()->findOrFail($user->id) : new User;
            if ($record->id === $actor->id && $values['role'] !== 'administrator') {
                throw ValidationException::withMessages(['role' => 'You cannot remove your own administrator access.']);
            }
            if ($record->exists && $record->email !== mb_strtolower($values['email'])) {
                $record->email_verified_at = null;
            }
            $record->fill(['name' => $values['name'], 'email' => mb_strtolower($values['email'])]);
            if (! empty($values['password'])) {
                $record->password = $values['password'];
            }
            $record->is_admin = $values['role'] === 'administrator';
            $record->is_editor = $values['role'] === 'editor';
            $record->save();

            return $record;
        });
    }
}

<?php

namespace App\Policies;

use App\Models\PriceAlert;
use App\Models\User;

class PriceAlertPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function view(User $user, PriceAlert $record): bool
    {
        return $record->user_id === $user->id || $user->is_admin;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PriceAlert $record): bool
    {
        return $record->user_id === $user->id;
    }

    public function delete(User $user, PriceAlert $record): bool
    {
        return $record->user_id === $user->id;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

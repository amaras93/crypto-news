<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WatchlistItem;

class WatchlistItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WatchlistItem $record): bool
    {
        return $record->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, WatchlistItem $record): bool
    {
        return $record->user_id === $user->id;
    }

    public function delete(User $user, WatchlistItem $record): bool
    {
        return $record->user_id === $user->id;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

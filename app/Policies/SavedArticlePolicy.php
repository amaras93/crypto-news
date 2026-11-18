<?php

namespace App\Policies;

use App\Models\SavedArticle;
use App\Models\User;

class SavedArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SavedArticle $record): bool
    {
        return $record->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SavedArticle $record): bool
    {
        return $record->user_id === $user->id;
    }

    public function delete(User $user, SavedArticle $record): bool
    {
        return $record->user_id === $user->id;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function view(User $user, Tag $tag): bool
    {
        return (bool) $user->is_admin;
    }

    public function create(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function update(User $user, Tag $tag): bool
    {
        return (bool) $user->is_admin;
    }

    public function delete(User $user, Tag $tag): bool
    {
        $count = $tag->getAttribute('protected_articles_count');

        return $user->is_admin && ($count !== null ? (int) $count === 0 : ! $tag->articles()->withTrashed()->exists());
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Tag $tag): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}

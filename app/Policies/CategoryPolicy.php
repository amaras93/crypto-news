<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function view(User $user, Category $category): bool
    {
        return (bool) $user->is_admin;
    }

    public function create(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function update(User $user, Category $category): bool
    {
        return (bool) $user->is_admin;
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->is_admin && ! $category->articles()->withTrashed()->exists()
            && ! $category->translations()->where('locale', 'hy')->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Category $category): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return (bool) $user->is_admin;
    }
}

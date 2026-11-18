<?php

namespace App\Policies;

use App\Models\NewsSource;
use App\Models\User;

class NewsSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function view(User $user, NewsSource $source): bool
    {
        return (bool) $user->is_admin;
    }

    public function create(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function update(User $user, NewsSource $source): bool
    {
        return (bool) $user->is_admin;
    }

    public function fetch(User $user, NewsSource $source): bool
    {
        return (bool) $user->is_admin;
    }

    public function delete(User $user, NewsSource $source): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

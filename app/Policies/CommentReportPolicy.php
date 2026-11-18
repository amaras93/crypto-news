<?php

namespace App\Policies;

use App\Models\CommentReport;
use App\Models\User;

class CommentReportPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function view(User $user, CommentReport $report): bool
    {
        return (bool) $user->is_admin;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CommentReport $report): bool
    {
        return (bool) $user->is_admin;
    }

    public function delete(User $user, CommentReport $report): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function resolveAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }
}

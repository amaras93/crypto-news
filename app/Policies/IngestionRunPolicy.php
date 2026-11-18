<?php

namespace App\Policies;

use App\Models\IngestionRun;
use App\Models\User;

class IngestionRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isEditorial();
    }

    public function view(User $user, IngestionRun $run): bool
    {
        return $user->isEditorial();
    }

    public function retry(User $user, IngestionRun $run): bool
    {
        return (bool) $user->is_admin;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, IngestionRun $run): bool
    {
        return false;
    }

    public function delete(User $user, IngestionRun $run): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

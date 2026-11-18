<?php

namespace App\Policies;

use App\Models\AiGenerationRun;
use App\Models\User;

class AiGenerationRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isEditorial();
    }

    public function view(User $user, AiGenerationRun $run): bool
    {
        return $user->is_admin || ($user->is_editor && $run->author_id === $user->id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AiGenerationRun $run): bool
    {
        return false;
    }

    public function delete(User $user, AiGenerationRun $run): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function reconcile(User $user, AiGenerationRun $run): bool
    {
        return (bool) $user->is_admin;
    }
}

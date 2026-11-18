<?php

namespace App\Policies;

use App\Models\NewsCandidate;
use App\Models\User;

class NewsCandidatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isEditorial();
    }

    public function view(User $user, NewsCandidate $candidate): bool
    {
        return $user->isEditorial();
    }

    public function review(User $user, NewsCandidate $candidate): bool
    {
        return $user->isEditorial();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, NewsCandidate $candidate): bool
    {
        return false;
    }

    public function delete(User $user, NewsCandidate $candidate): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

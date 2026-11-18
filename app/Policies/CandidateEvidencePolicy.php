<?php

namespace App\Policies;

use App\Models\CandidateEvidence;
use App\Models\User;

class CandidateEvidencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isEditorial();
    }

    public function view(User $user, CandidateEvidence $evidence): bool
    {
        return $user->isEditorial();
    }

    public function create(User $user): bool
    {
        return $user->isEditorial();
    }

    public function update(User $user, CandidateEvidence $evidence): bool
    {
        return $user->isEditorial();
    }

    public function delete(User $user, CandidateEvidence $evidence): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

<?php

namespace App\Policies;

use App\Models\EngagementDelivery;
use App\Models\User;

class EngagementDeliveryPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function view(User $user, EngagementDelivery $record): bool
    {
        return (bool) $user->is_admin;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EngagementDelivery $record): bool
    {
        return false;
    }

    public function delete(User $user, EngagementDelivery $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

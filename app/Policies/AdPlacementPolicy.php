<?php

namespace App\Policies;

use App\Models\AdPlacement;
use App\Models\User;
use App\Support\AdvertisingAuthorization;

class AdPlacementPolicy
{
    public function viewAny(User $user): bool
    {
        return app(AdvertisingAuthorization::class)->isAdministrator($user);
    }

    public function view(User $user, AdPlacement $record): bool
    {
        return app(AdvertisingAuthorization::class)->isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AdPlacement $record): bool
    {
        return app(AdvertisingAuthorization::class)->isAdministrator($user);
    }

    public function delete(User $user, AdPlacement $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

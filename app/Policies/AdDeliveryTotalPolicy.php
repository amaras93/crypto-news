<?php

namespace App\Policies;

use App\Models\AdDeliveryTotal;
use App\Models\User;
use App\Support\AdvertisingAuthorization;

class AdDeliveryTotalPolicy
{
    public function viewAny(User $user): bool
    {
        return app(AdvertisingAuthorization::class)->isAdministrator($user);
    }

    public function view(User $user, AdDeliveryTotal $record): bool
    {
        return app(AdvertisingAuthorization::class)->isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AdDeliveryTotal $record): bool
    {
        return false;
    }

    public function delete(User $user, AdDeliveryTotal $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

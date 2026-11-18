<?php

namespace App\Policies;

use App\Models\Advertiser;
use App\Models\User;
use App\Support\AdvertisingAuthorization;

class AdvertiserPolicy
{
    public function viewAny(User $user): bool
    {
        return app(AdvertisingAuthorization::class)->isAdministrator($user);
    }

    public function view(User $user, Advertiser $record): bool
    {
        return app(AdvertisingAuthorization::class)->isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return app(AdvertisingAuthorization::class)->isAdministrator($user);
    }

    public function update(User $user, Advertiser $record): bool
    {
        return app(AdvertisingAuthorization::class)->isAdministrator($user);
    }

    public function delete(User $user, Advertiser $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

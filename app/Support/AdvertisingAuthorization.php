<?php

namespace App\Support;

use App\Models\User;

class AdvertisingAuthorization
{
    /**
     * Resolve read/form permissions once per HTTP request. Mutating advertising
     * services independently reload the actor before every write.
     */
    public function isAdministrator(User $user): bool
    {
        $request = request();
        $key = 'advertising_admin_permission.'.$user->getKey();
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, (bool) $user->fresh()?->is_admin);
        }

        return $request->attributes->get($key);
    }
}

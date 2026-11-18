<?php

namespace App\Support;

use Illuminate\Http\Request;

class NoAdvertisingConsent implements AdvertisingConsent
{
    public function allowsExternalAds(Request $request): bool
    {
        return false;
    }

    public function providerName(): ?string
    {
        return null;
    }
}

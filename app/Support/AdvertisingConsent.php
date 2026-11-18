<?php

namespace App\Support;

use Illuminate\Http\Request;

interface AdvertisingConsent
{
    /** A future CMP adapter must verify the current visitor's real consent and handle withdrawal; a form/cookie flag alone is insufficient. */
    public function allowsExternalAds(Request $request): bool;

    public function providerName(): ?string;
}

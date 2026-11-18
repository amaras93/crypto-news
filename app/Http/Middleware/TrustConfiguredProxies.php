<?php

namespace App\Http\Middleware;

use App\Support\ProductionReadiness;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

class TrustConfiguredProxies extends TrustProxies
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT;

    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        $request->getHost();
        $proxies = array_values(array_filter(config('security.trusted_proxies', []), ProductionReadiness::validProxy(...)));
        if ($proxies !== []) {
            $this->setTrustedProxyIpAddressesToSpecificIps($request, $proxies);
        }
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        foreach (['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin', 'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => "base-uri 'self'; object-src 'none'; frame-ancestors 'self'"] as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }
        if (app()->isProduction() && $request->isSecure() && config('security.hsts_enabled')) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.config('security.hsts_max_age'));
        }

        return $response;
    }
}

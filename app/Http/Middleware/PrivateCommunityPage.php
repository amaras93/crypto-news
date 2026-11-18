<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrivateCommunityPage
{
    public function handle(Request $request, Closure $next): Response
    {
        $privatePage = $request->routeIs('account*', 'login', 'register', 'password.*', 'verification.*', 'reader.*', 'newsletter.*', 'engagement.*', 'advertising.preview');
        $response = $next($request);
        if ($privatePage || $request->attributes->get('advertising_rendered') || $request->user() || $request->routeIs('news.show', '*livewire.update', 'livewire.*') || $request->is('livewire/*')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        }
        if ($privatePage) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}

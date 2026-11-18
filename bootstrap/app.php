<?php

use App\Http\Middleware\PrivateCommunityPage;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrustConfiguredProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);
        $middleware->trustHosts(at: fn (): array => array_map(fn (string $host): string => '^'.preg_quote($host, '/').'$',
            array_values(array_filter([parse_url((string) config('app.url'), PHP_URL_HOST), ...config('security.additional_hosts', [])]))), subdomains: false);
        $middleware->alias(['locale' => SetLocale::class]);
        $middleware->web(append: [PrivateCommunityPage::class]);
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->is('admin*', 'editorial/*')
            ? '/admin/login' : route('login', ['locale' => $request->route('locale') ?? 'en']));
        $middleware->redirectUsersTo(fn (Request $request): string => route('account', ['locale' => $request->route('locale') ?? $request->user()?->preferredLocale() ?? 'en']));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

<?php

use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\RedirectToCanonicalHost;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SyncViewModeWithRoute;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'profile.complete' => EnsureProfileComplete::class,
            'force_password_change' => ForcePasswordChange::class,
        ]);

        $middleware->appendToGroup('web', ForcePasswordChange::class);
        $middleware->appendToGroup('web', SyncViewModeWithRoute::class);

        // Runs before every other middleware so www.<apex> visitors are
        // 301'd to the canonical origin before session, CSRF, or CSP touch
        // the request. See RedirectToCanonicalHost for the full rationale.
        $middleware->prepend(RedirectToCanonicalHost::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->validateCsrfTokens(except: [
            'webhooks/payfast',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

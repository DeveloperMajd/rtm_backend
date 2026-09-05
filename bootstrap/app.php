<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// add api.php

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->preventRequestForgery(
            except: ['api/auth/register', 'api/auth/login'],
            allowSameSite: true,
        );

        // This is an API-only backend with no server-rendered login page, so
        // there is no "login" named route to redirect guests to. Without this,
        // an unauthenticated request that doesn't send Accept: application/json
        // (e.g. navigator.sendBeacon, which can't set custom headers) crashes
        // with "Route [login] not defined" instead of getting a clean 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

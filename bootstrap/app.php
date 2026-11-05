<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

// add api.php

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // /broadcasting/auth signs channel subscriptions for the session's user,
    // so a session AuthenticateSession has ended, or an account whose email
    // isn't verified yet, mustn't get one signed either (see routes/api.php).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'middleware' => ['web', AuthenticateSession::class, 'verified'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Requests arrive from the shared Caddy by way of nginx, so the
        // visitor's address is in X-Forwarded-For. Without trusting the
        // proxy, every visitor looked like nginx and each guest rate limit
        // was one bucket for everybody. The caller is trusted because only
        // nginx can reach PHP-FPM (the `app` container is on rtm-internal
        // alone), and Caddy ignores any X-Forwarded-* a visitor sends and
        // sets its own. Only For and Proto: nothing here needs a forwarded
        // host or port, and trusting them would only widen what a forged
        // header could change.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Every write needs the CSRF token: the SPA sends it as X-XSRF-TOKEN,
        // from Sanctum's XSRF-TOKEN cookie (withXSRFToken, primed when the
        // app starts and again after a 419). No same-site shortcut: every
        // *.developermajd.com page is "same site", the VM hosts other
        // projects, and the browser sends the session cookie with their
        // requests too. The token is what they can't have: its cookie is
        // only readable on .rtm.developermajd.com (SESSION_DOMAIN). Signing
        // in, signing up and password resets are checked like everything
        // else, so another site can't sign a visitor in to its own account.
        //
        // The one exception is the beacon that says someone has closed the
        // app (navigator.sendBeacon can't send headers). Forged, it makes
        // someone look offline until their next heartbeat, at most 30
        // seconds later.
        $middleware->preventRequestForgery(except: ['api/presence/leave']);

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

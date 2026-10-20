<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Models\User;
use App\Policies\ConversationPolicy;
use App\Services\LastSeenVisibility;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Remembers who may see whose last-seen time for one request (or
        // queued job) at a time — never across them.
        $this->app->scoped(LastSeenVisibility::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Conversation::class, ConversationPolicy::class);

        // A sign-in starts without a password fingerprint from before it.
        // AuthenticateSession (routes/api.php) ends a session whose stored
        // fingerprint no longer matches the account's password, and signing
        // in keeps the session's data: one left from another account, or
        // from before a Google sign-in claimed this one, would end the new
        // sign-in on its next request.
        Event::listen(Login::class, function (Login $event): void {
            if (request()->hasSession()) {
                request()->session()->forget('password_hash_'.$event->guard);
            }
        });

        // Searching one conversation re-runs as the viewer types into the
        // in-chat search bar, so it gets twice the palette's allowance — and a
        // counter of its own, so neither kind of search uses up the other's.
        RateLimiter::for('messages.search', function (Request $request): Limit {
            $scoped = $request->filled('conversation_id');

            return Limit::perMinute($scoped ? 60 : 30)
                ->by(($scoped ? 'messages.search.scoped.' : 'messages.search.').$request->user()?->id);
        });

        // The single source of truth for "strong enough" everywhere a
        // password is set: registration, profile password change, and reset.
        // `uncompromised()` (a live HaveIBeenPwned lookup) is production-only
        // so tests and local dev never depend on network access.
        Password::defaults(fn () => Password::min(8)
            ->mixedCase()
            ->numbers()
            ->symbols()
            ->when($this->app->isProduction(), fn (Password $rule) => $rule->uncompromised()));

        // This is an API-only SPA with no server-rendered "reset password"
        // Blade view, so the emailed link must point at the frontend route
        // instead of Laravel's default named route.
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $query = http_build_query(['token' => $token, 'email' => $user->email]);

            return rtrim(config('services.frontend_url'), '/')."/reset-password?{$query}";
        });

        // The email-verification link goes to the frontend too. It carries
        // what the API's signed route needs (signed relative to the path, so
        // the scheme and host the request arrives with don't matter), and
        // the frontend's /verify-email page posts it back while signed in.
        VerifyEmail::createUrlUsing(function (User $user): string {
            $signed = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(config('auth.verification.expire', 60)),
                ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
                absolute: false,
            );

            parse_str((string) parse_url($signed, PHP_URL_QUERY), $signature);

            $query = http_build_query([
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
                'expires' => $signature['expires'],
                'signature' => $signature['signature'],
            ]);

            return rtrim(config('services.frontend_url'), '/')."/verify-email?{$query}";
        });
    }
}

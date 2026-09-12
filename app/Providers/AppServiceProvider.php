<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Models\User;
use App\Policies\ConversationPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Conversation::class, ConversationPolicy::class);

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
    }
}

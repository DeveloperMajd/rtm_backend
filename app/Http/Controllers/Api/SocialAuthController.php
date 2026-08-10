<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SocialAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialAuthController extends Controller
{
    public function __construct(public SocialAuthService $socialAuthService) {}

    public function redirect(string $provider): RedirectResponse
    {
        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider, Request $request): RedirectResponse
    {
        try {
            $socialiteUser = Socialite::driver($provider)->user();

            $user = $this->socialAuthService->findOrCreateUser($provider, $socialiteUser);

            Auth::login($user);

            $request->session()->regenerate();
        } catch (Throwable) {
            return redirect()->away(config('services.frontend_url').'/login?error=oauth_failed');
        }

        return redirect()->away(config('services.frontend_url').'/conversations');
    }
}

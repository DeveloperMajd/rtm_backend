<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserAuthProvider;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class SocialAuthService
{
    public function findOrCreateUser(string $provider, SocialiteUser $socialiteUser): User
    {
        $authProvider = UserAuthProvider::where('provider', $provider)
            ->where('provider_user_id', $socialiteUser->getId())
            ->first();

        if ($authProvider) {
            return $authProvider->user;
        }

        $user = User::where('email', $socialiteUser->getEmail())->first() ?? User::create([
            'name' => $socialiteUser->getName() ?? $socialiteUser->getNickname(),
            'email' => $socialiteUser->getEmail(),
            'avatar_url' => $socialiteUser->getAvatar(),
            'password' => null,
        ]);

        $user->authProviders()->create([
            'provider' => $provider,
            'provider_user_id' => $socialiteUser->getId(),
            'provider_email' => $socialiteUser->getEmail(),
            'provider_avatar_url' => $socialiteUser->getAvatar(),
        ]);

        return $user;
    }
}

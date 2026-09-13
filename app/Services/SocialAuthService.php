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

        $user = User::where('email', $socialiteUser->getEmail())->first();

        if ($user) {
            // The provider has already verified this email address, so a
            // successful OAuth login is proof enough — even if the account
            // was originally created with a password and never verified.
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
        } else {
            $user = new User([
                'name' => $socialiteUser->getName() ?? $socialiteUser->getNickname(),
                'email' => $socialiteUser->getEmail(),
                'avatar_url' => $socialiteUser->getAvatar(),
                'password' => null,
            ]);
            $user->forceFill(['email_verified_at' => now()]);
            $user->save();
        }

        $user->authProviders()->create([
            'provider' => $provider,
            'provider_user_id' => $socialiteUser->getId(),
            'provider_email' => $socialiteUser->getEmail(),
            'provider_avatar_url' => $socialiteUser->getAvatar(),
        ]);

        return $user;
    }
}

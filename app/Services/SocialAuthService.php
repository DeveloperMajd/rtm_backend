<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserAuthProvider;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser as SocialiteUser;
use RuntimeException;

class SocialAuthService
{
    public function findOrCreateUser(string $provider, SocialiteUser $socialiteUser): User
    {
        $authProvider = UserAuthProvider::where('provider', $provider)
            ->where('provider_user_id', $socialiteUser->getId())
            ->first();

        if ($authProvider) {
            $user = $authProvider->user;

            // Linked before signing in with Google confirmed the address (an
            // older version linked without doing so): this sign-in does, when
            // the provider vouches for the very address the account has.
            if (! $user->hasVerifiedEmail()
                && $this->providerVerifiedEmail($socialiteUser)
                && strcasecmp((string) $socialiteUser->getEmail(), $user->email) === 0) {
                $this->claim($user);
            }

            return $user;
        }

        // Everything from here on goes by the email address, so the provider
        // has to vouch that whoever is signing in owns it.
        if (! $this->providerVerifiedEmail($socialiteUser)) {
            throw new RuntimeException("The provider hasn't verified this email address.");
        }

        $user = User::where('email', $socialiteUser->getEmail())->first();

        if ($user) {
            if (! $user->hasVerifiedEmail()) {
                $this->claim($user);
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

    /**
     * Whether the provider vouches that whoever is signing in owns the email
     * address it gives (Google's `email_verified`).
     */
    private function providerVerifiedEmail(SocialiteUser $socialiteUser): bool
    {
        return filter_var($socialiteUser->getRaw()['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Nobody ever proved they own the account's address, so anyone could have
     * typed it in at sign-up and chosen the password. This sign-in has just
     * proved it, so it claims the account: the password goes, and with it
     * every session signed in with it, since AuthenticateSession ends a
     * session once the password it was signed in with has changed.
     */
    private function claim(User $user): void
    {
        $user->forceFill(['password' => null, 'email_verified_at' => now()]);
        $user->setRememberToken(Str::random(60));
        $user->save();
    }
}

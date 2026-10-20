<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'username', 'avatar_url', 'avatar_path', 'bio'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * An account made through Google has no password: an empty string
     * rather than null, because AuthenticateSession fingerprints every
     * session with an HMAC of this value, and hash_hmac() no longer takes
     * null (deprecated since PHP 8.1).
     */
    public function getAuthPassword(): string
    {
        return $this->password ?? '';
    }

    // Relationships
    public function settings(): HasOne
    {
        return $this->hasOne(UserSettings::class);
    }

    public function createdConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'created_by_user_id');
    }

    public function conversationParticipants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_user_id');
    }

    public function savedMessages(): HasMany
    {
        return $this->hasMany(SavedMessage::class);
    }

    public function authProviders(): HasMany
    {
        return $this->hasMany(UserAuthProvider::class);
    }

    /**
     * The users this user has added to their personal contact list.
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'contacts', 'user_id', 'contact_user_id')
            ->withTimestamps();
    }
}

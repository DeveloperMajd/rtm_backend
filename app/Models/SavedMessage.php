<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A message someone has saved, to find again under Saved. Written only by
 * SavedMessageController, from the signed-in person and the message in the
 * URL, so nothing about it is mass-assignable.
 *
 * @property string $id
 * @property string $user_id
 * @property string $message_id
 */
class SavedMessage extends Model
{
    use HasUuids;

    /** A save is made or removed, never changed. */
    public const UPDATED_AT = null;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}

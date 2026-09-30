<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

class Message extends Model
{
    use HasFactory, HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected $fillable = [
        'conversation_id',
        'sender_user_id',
        'body',
        'reply_to_message_id',
        'body_format',
        'type',
        'event_type',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
            'attachments_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * Real, user-authored messages — excludes system/group-event lines.
     */
    public function scopeUserMessages(Builder $query): Builder
    {
        return $query->where('type', 'user');
    }

    /**
     * Messages $user may read: in a conversation they have a participant
     * row in, that hasn't been deleted, and — for a member who left or was
     * removed — no later than the message that recorded it
     * (left_at_message_id; see ConversationParticipantService::leave()).
     *
     * The one rule behind every endpoint that reads messages, so a new one
     * can't forget part of it. A correlated EXISTS rather than a join, so it
     * composes with any other constraint without duplicating rows.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereExists(function (QueryBuilder $participation) use ($user): void {
            $participation->selectRaw('1')
                ->from('conversation_participants')
                ->join('conversations', 'conversations.id', '=', 'conversation_participants.conversation_id')
                ->whereColumn('conversation_participants.conversation_id', 'messages.conversation_id')
                ->where('conversation_participants.user_id', $user->id)
                ->whereNull('conversations.deleted_at')
                ->where(function (QueryBuilder $cutoff): void {
                    $cutoff->whereNull('conversation_participants.left_at_message_id')
                        ->orWhereColumn('messages.id', '<=', 'conversation_participants.left_at_message_id');
                });
        });
    }

    // Relationships
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_message_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}

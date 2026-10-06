<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ConversationParticipant extends Model
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'joined_at',
        'left_at',
        'left_at_message_id',
        'last_read_message_id',
        'last_read_at',
        'pinned_at',
        'muted_at',
        'archived_at',
        'receipt_stretches',
        'viewer_stretches',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'last_read_at' => 'datetime',
            'pinned_at' => 'datetime',
            'muted_at' => 'datetime',
            'archived_at' => 'datetime',
            'receipt_stretches' => 'array',
            'viewer_stretches' => 'array',
        ];
    }

    /**
     * Participants who have not left (or been removed from) the conversation.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('left_at');
    }

    // Relationships
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastReadMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_read_message_id');
    }

    public function leftAtMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'left_at_message_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's notification and privacy settings. Someone who has never
 * changed one has no row; for() hands back the defaults for them instead,
 * unsaved, so reading a setting never writes.
 *
 * @property bool $read_receipts
 * @property string $last_seen_visibility everyone|contacts|nobody
 * @property bool $typing_indicators
 * @property bool $message_sounds
 * @property bool $desktop_notifications
 */
#[Fillable(['read_receipts', 'last_seen_visibility', 'typing_indicators', 'message_sounds', 'desktop_notifications'])]
class UserSettings extends Model
{
    /** What everyone has until they change it — what the app has always done. */
    public const DEFAULTS = [
        'read_receipts' => true,
        'last_seen_visibility' => 'everyone',
        'typing_indicators' => true,
        'message_sounds' => false,
        'desktop_notifications' => false,
    ];

    public const LAST_SEEN_VISIBILITIES = ['everyone', 'contacts', 'nobody'];

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = self::DEFAULTS;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_receipts' => 'boolean',
            'typing_indicators' => 'boolean',
            'message_sounds' => 'boolean',
            'desktop_notifications' => 'boolean',
        ];
    }

    public static function for(User $user): self
    {
        // The owner is set directly, not filled: user_id is never something
        // a request may choose.
        return self::query()->find($user->id) ?? (new self)->forceFill(['user_id' => $user->id]);
    }

    /**
     * Of these users, the ones who have stopped sharing their read state.
     *
     * @param  array<int, string>  $userIds
     * @return array<int, string>
     */
    public static function withoutReadReceipts(array $userIds): array
    {
        return self::query()->whereIn('user_id', $userIds)->where('read_receipts', false)->pluck('user_id')->all();
    }

    /**
     * @return array<string, bool|string>
     */
    public function toPreferences(): array
    {
        return [
            'read_receipts' => $this->read_receipts,
            'last_seen_visibility' => $this->last_seen_visibility,
            'typing_indicators' => $this->typing_indicators,
            'message_sounds' => $this->message_sounds,
            'desktop_notifications' => $this->desktop_notifications,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

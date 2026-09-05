<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Redis;

class PresenceService
{
    private const int TTL_SECONDS = 30;

    private const string KEY_PREFIX = 'presence:online:';

    public function heartbeat(User $user): void
    {
        Redis::setex(self::KEY_PREFIX.$user->id, self::TTL_SECONDS, now()->toIso8601String());

        if (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinute())) {
            $user->forceFill(['last_seen_at' => now()])->save();
        }
    }

    public function leave(User $user): void
    {
        Redis::del(self::KEY_PREFIX.$user->id);

        $user->forceFill(['last_seen_at' => now()])->save();
    }

    public function isOnline(string $userId): bool
    {
        return (bool) Redis::exists(self::KEY_PREFIX.$userId);
    }

    /**
     * @param  array<int, string>  $userIds
     * @return array<int, string>
     */
    public function onlineUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $results = Redis::pipeline(function ($pipe) use ($userIds): void {
            foreach ($userIds as $userId) {
                $pipe->exists(self::KEY_PREFIX.$userId);
            }
        });

        return array_values(array_filter(
            $userIds,
            fn (string $userId, int $index): bool => (bool) $results[$index],
            ARRAY_FILTER_USE_BOTH,
        ));
    }
}

<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RedisException;

class PresenceService
{
    private const int TTL_SECONDS = 30;

    private const string KEY_PREFIX = 'presence:online:';

    /**
     * Presence is ephemeral and non-critical — a Redis outage (e.g. the
     * Upstash free-tier's 500K/month command cap) should mean "nobody shows
     * as online," not a 500 on every endpoint that touches a conversation.
     * Every public method here fails soft for that reason.
     */
    public function heartbeat(User $user): void
    {
        $this->safely(fn (Connection $redis) => $redis->setex(
            self::KEY_PREFIX.$user->id, self::TTL_SECONDS, now()->toIso8601String(),
        ));

        if (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinute())) {
            $user->forceFill(['last_seen_at' => now()])->save();
        }
    }

    public function leave(User $user): void
    {
        $this->safely(fn (Connection $redis) => $redis->del(self::KEY_PREFIX.$user->id));

        $user->forceFill(['last_seen_at' => now()])->save();
    }

    public function isOnline(string $userId): bool
    {
        return (bool) $this->safely(fn (Connection $redis) => $redis->exists(self::KEY_PREFIX.$userId));
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

        $results = $this->safely(fn (Connection $redis) => $redis->pipeline(function ($pipe) use ($userIds): void {
            foreach ($userIds as $userId) {
                $pipe->exists(self::KEY_PREFIX.$userId);
            }
        }));

        if ($results === null) {
            return [];
        }

        return array_values(array_filter(
            $userIds,
            fn (string $userId, int $index): bool => (bool) $results[$index],
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    private function safely(callable $call): mixed
    {
        try {
            return $call(Redis::connection());
        } catch (RedisException $e) {
            Log::warning('Presence Redis call failed, degrading to offline', ['exception' => $e]);

            return null;
        }
    }
}

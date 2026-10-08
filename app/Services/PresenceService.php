<?php

namespace App\Services;

use App\Enums\PresenceStatus;
use App\Models\User;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RedisException;

class PresenceService
{
    private const int TTL_SECONDS = 30;

    private const string KEY_PREFIX = 'presence:online:';

    /** What a heartbeat stores when the person has left the app idle. */
    private const string AWAY = 'away';

    /**
     * Presence is ephemeral and non-critical — a Redis outage (e.g. the
     * Upstash free-tier's 500K/month command cap) should mean "nobody shows
     * as online," not a 500 on every endpoint that touches a conversation.
     * Every public method here fails soft for that reason.
     *
     * Whether they're using the app or have left it idle is the key's value,
     * so being away costs no Redis command of its own: the same SETEX writes
     * it, and the same one command per person (GET, where it was EXISTS)
     * reads it back.
     */
    public function heartbeat(User $user, bool $away = false): void
    {
        $this->safely(fn (Connection $redis) => $redis->setex(
            self::KEY_PREFIX.$user->id, self::TTL_SECONDS, $away ? self::AWAY : 'active',
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

    public function statusOf(string $userId): PresenceStatus
    {
        return $this->statusFrom($this->safely(fn (Connection $redis) => $redis->get(self::KEY_PREFIX.$userId)));
    }

    /**
     * Everyone's status in one round trip.
     *
     * @param  array<int, string>  $userIds
     * @return array<string, PresenceStatus> keyed by user id
     */
    public function statusesOf(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $values = $this->safely(fn (Connection $redis) => $redis->pipeline(function ($pipe) use ($userIds): void {
            foreach ($userIds as $userId) {
                $pipe->get(self::KEY_PREFIX.$userId);
            }
        }));

        $statuses = [];
        foreach (array_values($userIds) as $index => $userId) {
            $statuses[$userId] = $this->statusFrom($values[$index] ?? null);
        }

        return $statuses;
    }

    /**
     * No key is offline. Any value but "away" is online: "active", or the
     * timestamp a heartbeat from before away presence stored, which can
     * still be live for a few seconds after a deploy.
     */
    private function statusFrom(mixed $value): PresenceStatus
    {
        if ($value === null || $value === false) {
            return PresenceStatus::Offline;
        }

        return $value === self::AWAY ? PresenceStatus::Away : PresenceStatus::Online;
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

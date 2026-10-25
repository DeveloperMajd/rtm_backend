<?php

namespace App\Services;

use App\Enums\PresenceStatus;
use App\Events\PresenceChanged;
use App\Models\Contact;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RedisException;

class PresenceService
{
    /**
     * Three beats' worth, though a beat comes every 30 seconds: a browser can
     * slow a tab that's out of view to one beat a minute, active or away.
     * Chrome does it after five minutes, and in production it was seen
     * within a minute of the tab going to the background; at 30 seconds the
     * person showed offline between beats. A tab that closes still leaves at
     * once (its pagehide beacon), so this is only how long a tab that's gone
     * without saying so (a crash, a lid closed) still shows online, and even
     * then presence:watch tells everyone the moment the key runs out.
     */
    private const int TTL_SECONDS = 90;

    private const string KEY_PREFIX = 'presence:online:';

    /** What a heartbeat stores when the person has left the app idle. */
    private const string AWAY = 'away';

    /**
     * Writes a heartbeat and gives back what the key held before, in the
     * one round trip a plain SETEX took, so a beat knows whether it changed
     * anything.
     */
    private const string SWAP_SCRIPT = <<<'LUA'
        local previous = redis.call('GET', KEYS[1])
        redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2])
        return previous
        LUA;

    /**
     * Statuses prime() fetched for the response being built, keyed by user
     * id, and the request they were fetched for: never answered from in
     * another, where they could be out of date.
     *
     * @var array<string, PresenceStatus>
     */
    private array $primed = [];

    private ?int $primedFor = null;

    /**
     * Presence is ephemeral and non-critical — a Redis outage (e.g. the
     * Upstash free-tier's 500K/month command cap) should mean "nobody shows
     * as online," not a 500 on every endpoint that touches a conversation.
     * Every public method here fails soft for that reason.
     *
     * Whether they're using the app or have left it idle is the key's value,
     * so being away costs no Redis command of its own: the same write sets
     * it, and the same one command per person (GET, where it was EXISTS)
     * reads it back. A beat that changes the status (online from offline,
     * away, back) tells everyone who sees it; one that keeps it tells nobody.
     */
    public function heartbeat(User $user, bool $away = false): void
    {
        $value = $away ? self::AWAY : 'active';
        $status = $this->statusFrom($value);

        $changed = $this->safely(fn (Connection $redis): bool => $this->statusFrom(
            $redis->eval(self::SWAP_SCRIPT, 1, self::KEY_PREFIX.$user->id, $value, self::TTL_SECONDS),
        ) !== $status);

        if (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinute())) {
            $user->forceFill(['last_seen_at' => now()])->save();
        }

        if ($changed === true) {
            $this->announce($user->id, $status);
        }
    }

    public function leave(User $user): void
    {
        $removed = $this->safely(fn (Connection $redis): bool => $redis->del(self::KEY_PREFIX.$user->id) > 0);

        $user->forceFill(['last_seen_at' => now()])->save();

        if ($removed === true) {
            $this->announce($user->id, PresenceStatus::Offline);
        }
    }

    /**
     * A key ran out with nobody leaving: the tab is gone without saying so
     * (a crash, a lid closed, a dropped connection). presence:watch hands over
     * every key Redis expires, so anything that isn't a presence key is
     * ignored. A beat that came in since has already said they're back, so
     * then there's nothing to tell.
     */
    public function expired(string $key): void
    {
        $prefix = config('database.redis.options.prefix').self::KEY_PREFIX;

        if (! str_starts_with($key, $prefix)) {
            return;
        }

        $userId = substr($key, strlen($prefix));

        if ($this->statusOf($userId) === PresenceStatus::Offline) {
            $this->announce($userId, PresenceStatus::Offline);
        }
    }

    public function statusOf(string $userId): PresenceStatus
    {
        return $this->statusesOf([$userId])[$userId];
    }

    /**
     * Everyone's status in one round trip, or none at all for those prime()
     * has already fetched for this request.
     *
     * @param  array<int, string>  $userIds
     * @return array<string, PresenceStatus> keyed by user id
     */
    public function statusesOf(array $userIds): array
    {
        $primed = $this->primedFor === spl_object_id(request()) ? $this->primed : [];
        $fetched = $this->fetchStatuses(array_values(array_filter(
            $userIds,
            fn (string $userId): bool => ! isset($primed[$userId]),
        )));

        $statuses = [];
        foreach ($userIds as $userId) {
            $statuses[$userId] = $primed[$userId] ?? $fetched[$userId];
        }

        return $statuses;
    }

    /**
     * Fetches everyone's status in one round trip, for statusOf() and
     * statusesOf() to answer from for the rest of this request: a whole chat
     * or contacts list at once, rather than a round trip for every row.
     *
     * @param  iterable<string>  $userIds
     */
    public function prime(iterable $userIds): void
    {
        $unique = [];
        foreach ($userIds as $userId) {
            $unique[$userId] = $userId;
        }

        $this->primed = $this->fetchStatuses(array_values($unique));
        $this->primedFor = spl_object_id(request());
    }

    /**
     * One MGET, which (unlike a pipeline) shows up as one command to
     * Laravel's Redis events.
     *
     * @param  list<string>  $userIds
     * @return array<string, PresenceStatus> keyed by user id
     */
    private function fetchStatuses(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $values = $this->safely(fn (Connection $redis) => $redis->mget(
            array_map(fn (string $userId): string => self::KEY_PREFIX.$userId, $userIds),
        ));

        $statuses = [];
        foreach ($userIds as $index => $userId) {
            $statuses[$userId] = $this->statusFrom($values[$index] ?? null);
        }

        return $statuses;
    }

    /**
     * Who sees this person's status, and so is told when it changes: the
     * other current members of the conversations they're still in, and
     * whoever has them as a contact.
     *
     * @return array<int, string>
     */
    public function audienceOf(string $userId): array
    {
        $members = ConversationParticipant::query()
            ->whereIn('conversation_id', ConversationParticipant::query()
                ->select('conversation_id')
                ->where('user_id', $userId)
                ->whereNull('left_at'))
            ->whereHas('conversation', fn (Builder $conversations) => $conversations->visible())
            ->whereNull('left_at')
            ->where('user_id', '!=', $userId)
            ->pluck('user_id');

        $contactOwners = Contact::query()->where('contact_user_id', $userId)->pluck('user_id');

        return $members->merge($contactOwners)->unique()->values()->all();
    }

    private function announce(string $userId, PresenceStatus $status): void
    {
        $audience = $this->audienceOf($userId);

        if ($audience !== []) {
            broadcast(new PresenceChanged($userId, $status, $audience));
        }
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

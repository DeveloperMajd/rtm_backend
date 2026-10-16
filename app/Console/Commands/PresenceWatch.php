<?php

namespace App\Console\Commands;

use App\Services\PresenceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Tells everyone when someone's presence key runs out: the one way of going
 * offline (a crash, a lid closed, a dropped connection) that no request
 * announces. It waits on Redis's own expired-key notifications rather than
 * checking, so it sends nothing at all while nothing expires.
 *
 * Runs for good, like the queue worker; if Redis goes away the subscription
 * fails, the command exits, and its container's restart brings it back with
 * the notifications switched on again (Redis forgets that setting on a
 * restart). An expiry while it's down is missed, and shows at the client's
 * next refresh instead.
 */
#[Signature('presence:watch')]
#[Description('Broadcast an offline presence change whenever a presence key expires')]
class PresenceWatch extends Command
{
    public function handle(PresenceService $presence): int
    {
        // A connection of its own: once subscribed it can't send anything
        // else, and the broadcasts this queues go out on the default one.
        $client = Redis::resolve('default')->client();
        $client->setOption(\Redis::OPT_READ_TIMEOUT, -1);
        // Notification channels are Redis's own names, never prefixed.
        $client->setOption(\Redis::OPT_PREFIX, '');

        $this->enableExpiredNotifications($client);

        $database = (int) config('database.redis.default.database');
        $this->info("Watching presence keys expire (db {$database})");

        $client->subscribe(
            ["__keyevent@{$database}__:expired"],
            fn (\Redis $redis, string $channel, string $key) => $presence->expired($key),
        );

        return self::SUCCESS;
    }

    /**
     * Adds keyevent (E) and expired (x) to whatever notifications are on
     * already, rather than replacing them.
     */
    private function enableExpiredNotifications(\Redis $client): void
    {
        $current = $client->config('GET', 'notify-keyspace-events')['notify-keyspace-events'] ?? '';
        $wanted = count_chars($current.'Ex', 3);

        if ($wanted !== count_chars($current, 3)) {
            $client->config('SET', 'notify-keyspace-events', $wanted);
        }
    }
}

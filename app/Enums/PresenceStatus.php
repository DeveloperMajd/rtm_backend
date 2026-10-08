<?php

namespace App\Enums;

/**
 * Whether someone is here, as others see it. Away is still online, just
 * idle: their app is open, but they haven't touched it for a while (the
 * client says so in its heartbeat). Resources give it as `presence_status`
 * beside `is_online`, which stays true while they're away.
 */
enum PresenceStatus: string
{
    case Online = 'online';
    case Away = 'away';
    case Offline = 'offline';

    public function isOnline(): bool
    {
        return $this !== self::Offline;
    }
}

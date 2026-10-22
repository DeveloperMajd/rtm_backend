<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Each person's own channel is the only one. A conversation has none: who
// may listen to a channel is checked once, when a socket subscribes, so a
// member removed from a group would keep hearing it. Its events go to each
// current member's own channel instead (BroadcastsToCurrentMembers).
Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return $user->id === $id;
});

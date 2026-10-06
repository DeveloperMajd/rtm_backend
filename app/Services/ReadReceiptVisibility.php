<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSettings;

/**
 * Whose read state a viewer may see: the "Read receipts" setting, applied
 * the same way wherever read state is reported (the list of read pointers
 * and a message's info), so one can't drift from the other.
 *
 * Read receipts work both ways. Someone who doesn't share their own read
 * state doesn't see anyone else's, and nobody sees the read state of
 * someone who doesn't share it. A person always sees their own.
 *
 * What a viewer may not see isn't taken out of a list. It reads as "hasn't
 * read anything yet", so a list never gives away who has the setting off.
 */
class ReadReceiptVisibility
{
    /**
     * Whether the viewer shares their own read state, and which of these
     * people's they may see: the viewer's own, and everyone else's only if
     * both of them share.
     *
     * @param  array<int, string>  $userIds  whose read state is being reported
     * @return array{viewer_shares: bool, visible: array<string, true>}
     */
    public function forViewer(User $viewer, array $userIds): array
    {
        $viewerShares = UserSettings::for($viewer)->read_receipts;

        $visible = [];

        if (in_array($viewer->id, $userIds, true)) {
            $visible[$viewer->id] = true;
        }

        if ($viewerShares && $userIds !== []) {
            $notSharing = array_flip(UserSettings::withoutReadReceipts($userIds));

            foreach ($userIds as $id) {
                if (! isset($notSharing[$id])) {
                    $visible[$id] = true;
                }
            }
        }

        return ['viewer_shares' => $viewerShares, 'visible' => $visible];
    }
}

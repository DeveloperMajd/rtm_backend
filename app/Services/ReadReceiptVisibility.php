<?php

namespace App\Services;

use App\Models\ConversationParticipant;
use App\Models\User;
use App\Models\UserSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Who has seen what: the "Read receipts" setting, applied the same way
 * wherever read state is reported (the list of read pointers, its live
 * broadcast, and a message's info), so none of them can drift from the
 * others.
 *
 * Every read is decided when it happens, by both people's settings at that
 * moment. A reader with receipts off doesn't share the read, and a viewer
 * with theirs off doesn't get it: read receipts work both ways. Switching
 * only changes what happens from then on, for either of them, however often
 * they switch. What was shown stays shown, and what wasn't never is.
 *
 * A read pointer is one position per person per conversation, so this is
 * kept as stretches of pointers, each [from, to]: from exclusive and null
 * for the very beginning, to inclusive and null while open, running to the
 * pointer.
 * - A reader's own stretches (ConversationParticipant::receipt_stretches)
 *   are the ranges their pointer covered while they had receipts on.
 * - A viewer's stretches of each other member (viewer_stretches, keyed by
 *   user id) are the ranges that member's pointer covered while the viewer
 *   had receipts on.
 * A read shows only where the two overlap. Both are written only when
 * someone switches: on opens a stretch where each pointer stands, off
 * closes it there, and reading itself writes nothing new. A list that
 * isn't there yet follows the person's current setting for all of its
 * history: everything, or nothing.
 *
 * A person always sees their own reading. What a viewer may not see isn't
 * taken out of a list; it reads as "hasn't read it yet", so a list never
 * gives away who has the setting off.
 *
 * @phpstan-type Stretch array{0: string|null, 1: string|null}
 * @phpstan-type SharedReads array{stretches: list<Stretch>, last_read_message_id: string|null, last_read_at: CarbonInterface|null}
 * @phpstan-type VisibleReads array{stretches: list<Stretch>, viewer_stretches: list<Stretch>, last_read_message_id: string|null, last_read_at: CarbonInterface|null}
 */
class ReadReceiptVisibility
{
    /**
     * Stretches kept per list. Beyond this the oldest is dropped, which
     * hides what it covered; dropping one never shows anything.
     */
    public const MAX_STRETCHES = 20;

    /**
     * What the viewer may see of each member's reading, keyed by user id,
     * and whether the viewer shares their own now.
     *
     * Each entry gives the member's shared `stretches`, the viewer's
     * `viewer_stretches` of them, `last_read_message_id` (the furthest the
     * viewer may see, which an open stretch runs to) and `last_read_at` (only
     * when that latest read is one the viewer may see).
     *
     * @param  Collection<int, ConversationParticipant>  $members  including the viewer's own row
     * @return array{viewer_shares: bool, reads: array<string, VisibleReads>}
     */
    public function forViewer(User $viewer, Collection $members): array
    {
        $viewerShares = UserSettings::for($viewer)->read_receipts;
        $viewing = $members->firstWhere('user_id', $viewer->id)?->viewer_stretches ?? [];
        $others = $members->where('user_id', '!=', $viewer->id)->pluck('user_id')->all();
        $notSharing = $others === [] ? [] : array_flip(UserSettings::withoutReadReceipts($others));

        $reads = [];

        foreach ($members as $member) {
            $reads[$member->user_id] = $member->user_id === $viewer->id
                ? [
                    'stretches' => [[null, null]],
                    'viewer_stretches' => [[null, null]],
                    'last_read_message_id' => $member->last_read_message_id,
                    'last_read_at' => $member->last_read_at,
                ]
                : self::visibleReads(
                    $member,
                    ! isset($notSharing[$member->user_id]),
                    $viewing[$member->user_id] ?? null,
                    $viewerShares,
                );
        }

        return ['viewer_shares' => $viewerShares, 'reads' => $reads];
    }

    /**
     * What a member has shared of their reading, whoever is looking: their
     * stretches, where their shared reading ends, and when their pointer
     * last moved, only if that move was shared.
     *
     * @return SharedReads
     */
    public static function sharedReads(ConversationParticipant $member, bool $sharesNow): array
    {
        $stretches = self::current($member->receipt_stretches, $sharesNow);
        $pointer = $member->last_read_message_id;

        return [
            'stretches' => $stretches,
            'last_read_message_id' => self::endOf($stretches, $pointer),
            'last_read_at' => self::movedWhileOpen($stretches, $pointer) ? $member->last_read_at : null,
        ];
    }

    /**
     * Whether a message lies in what the viewer may see: inside both one of
     * the member's stretches and one of the viewer's stretches of them. An
     * open stretch runs to the pointer the viewer was given. Ids are UUIDv7,
     * so they order by time as strings.
     *
     * @param  SharedReads|VisibleReads  $reads
     */
    public static function covers(array $reads, string $messageId): bool
    {
        $pointer = $reads['last_read_message_id'];

        return self::within($reads['stretches'], $pointer, $messageId)
            && self::within($reads['viewer_stretches'] ?? [[null, null]], $pointer, $messageId);
    }

    /**
     * Records a person switching read receipts on or off, in every
     * conversation they're in: on opens a stretch where each pointer stands
     * (their own, and each other member's as they'll see it), off closes it
     * there. A list that isn't there yet gets one now, from the setting it
     * has followed until this switch.
     */
    public function recordSwitch(User $user, bool $nowSharing): void
    {
        $rows = ConversationParticipant::query()
            ->where('user_id', $user->id)
            ->get(['id', 'conversation_id', 'last_read_message_id', 'receipt_stretches', 'viewer_stretches']);

        $others = ConversationParticipant::query()
            ->whereIn('conversation_id', $rows->pluck('conversation_id'))
            ->where('user_id', '!=', $user->id)
            ->get(['conversation_id', 'user_id', 'last_read_message_id'])
            ->groupBy('conversation_id');

        $switched = fn (?array $stretches, ?string $pointer): array => $nowSharing
            ? self::opened($stretches ?? [], $pointer)
            : self::closed($stretches ?? [[null, null]], $pointer);

        foreach ($rows as $row) {
            $row->receipt_stretches = $switched($row->receipt_stretches, $row->last_read_message_id);

            $viewing = $row->viewer_stretches ?? [];

            foreach ($others->get($row->conversation_id, []) as $other) {
                $viewing[$other->user_id] = $switched($viewing[$other->user_id] ?? null, $other->last_read_message_id);
            }

            $row->viewer_stretches = $viewing;
            $row->save();
        }
    }

    /**
     * @param  list<Stretch>|null  $viewing  the viewer's stretches of this member; null if never recorded
     * @return VisibleReads
     */
    private static function visibleReads(ConversationParticipant $member, bool $sharesNow, ?array $viewing, bool $viewerSharesNow): array
    {
        $shared = self::sharedReads($member, $sharesNow);
        $viewing = self::current($viewing, $viewerSharesNow);
        $pointer = $member->last_read_message_id;

        // The viewer is never given more of the pointer than both sides let
        // them see, so it doesn't give away reads they may not see.
        $sharedEnd = $shared['last_read_message_id'];
        $viewEnd = self::endOf($viewing, $pointer);
        $end = $sharedEnd === null || $viewEnd === null
            ? null
            : (strcmp($sharedEnd, $viewEnd) <= 0 ? $sharedEnd : $viewEnd);

        return [
            'stretches' => $shared['stretches'],
            'viewer_stretches' => $viewing,
            'last_read_message_id' => $end,
            'last_read_at' => $shared['last_read_at'] !== null && self::movedWhileOpen($viewing, $pointer)
                ? $shared['last_read_at']
                : null,
        ];
    }

    /**
     * A list as it stands now: one that isn't there yet follows the current
     * setting for all of its history, and an open stretch is kept only while
     * that setting is on. Should the two ever disagree, the open end is left
     * out, not shown.
     *
     * @param  list<Stretch>|null  $stretches
     * @return list<Stretch>
     */
    private static function current(?array $stretches, bool $onNow): array
    {
        $stretches ??= $onNow ? [[null, null]] : [];
        $last = end($stretches);

        if ($last !== false && $last[1] === null && ! $onNow) {
            array_pop($stretches);
        }

        return array_values($stretches);
    }

    /**
     * Where the reading a list covers ends: the pointer while a stretch is
     * open, otherwise where the last one closed.
     *
     * @param  list<Stretch>  $stretches
     */
    private static function endOf(array $stretches, ?string $pointer): ?string
    {
        $last = end($stretches);

        if ($last === false) {
            return null;
        }

        return $last[1] ?? $pointer;
    }

    /**
     * Whether the pointer has moved since the open stretch opened: the last
     * read was made inside it.
     *
     * @param  list<Stretch>  $stretches
     */
    private static function movedWhileOpen(array $stretches, ?string $pointer): bool
    {
        $last = end($stretches);

        return $last !== false
            && $last[1] === null
            && $pointer !== null
            && ($last[0] === null || strcmp($pointer, $last[0]) > 0);
    }

    /**
     * @param  list<Stretch>  $stretches
     */
    private static function within(array $stretches, ?string $pointer, string $messageId): bool
    {
        foreach ($stretches as [$from, $to]) {
            $end = $to ?? $pointer;

            if ($end !== null
                && ($from === null || strcmp($messageId, $from) > 0)
                && strcmp($messageId, $end) <= 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Stretch>  $stretches
     * @return list<Stretch>
     */
    private static function opened(array $stretches, ?string $pointer): array
    {
        $last = end($stretches);

        if ($last !== false && $last[1] === null) {
            return $stretches;
        }

        $stretches[] = [$pointer, null];

        return array_values(array_slice($stretches, -self::MAX_STRETCHES));
    }

    /**
     * @param  list<Stretch>  $stretches
     * @return list<Stretch>
     */
    private static function closed(array $stretches, ?string $pointer): array
    {
        $last = end($stretches);

        if ($last === false || $last[1] !== null) {
            return $stretches;
        }

        array_pop($stretches);

        // Nothing was read while it was open: there's no stretch to keep.
        if ($pointer !== null && $pointer !== $last[0]) {
            $stretches[] = [$last[0], $pointer];
        }

        return array_values($stretches);
    }
}

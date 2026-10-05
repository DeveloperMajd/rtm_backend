<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\User;
use App\Models\UserSettings;
use Carbon\CarbonInterface;

/**
 * Whether the viewer may see when someone was last online, by that
 * person's "Show last seen" setting: everyone, only people they've added
 * to their contacts, or nobody. The one rule every resource that carries a
 * last_seen_at goes through, so no new one can forget it. The field stays
 * in each payload either way, as null when hidden.
 *
 * Online/offline itself isn't affected: hiding "last seen 3 hours ago" is
 * the setting's promise, not hiding presence.
 *
 * prime() loads a whole list's settings and contact links in two queries
 * and remembers them, so a list of people doesn't cost two queries per
 * person; anyone not primed is looked up, and remembered, the first time
 * they're asked about. Remembered for one request only: a contact added
 * or a setting changed shows on the very next one. The binding is scoped
 * (see AppServiceProvider), and the memory is also dropped whenever the
 * request it was for isn't the current one, for anywhere one instance
 * outlives a request.
 */
class LastSeenVisibility
{
    private ?string $viewerId = null;

    private ?int $requestId = null;

    /** @var array<string, string> user id => their last_seen_visibility */
    private array $visibility = [];

    /** @var array<string, bool> user id => whether they have the viewer in their contacts */
    private array $knowsViewer = [];

    /**
     * @param  iterable<int, string>  $userIds
     */
    public function prime(User $viewer, iterable $userIds): void
    {
        $requestId = spl_object_id(request());
        if ($this->viewerId !== $viewer->id || $this->requestId !== $requestId) {
            $this->viewerId = $viewer->id;
            $this->requestId = $requestId;
            $this->visibility = [];
            $this->knowsViewer = [];
        }

        $unknown = [];
        foreach ($userIds as $id) {
            if ($id !== $viewer->id && ! isset($this->visibility[$id])) {
                $unknown[$id] = $id;
            }
        }
        if ($unknown === []) {
            return;
        }

        $settings = UserSettings::query()->whereIn('user_id', $unknown)->pluck('last_seen_visibility', 'user_id');
        $knowViewer = Contact::query()
            ->whereIn('user_id', $unknown)
            ->where('contact_user_id', $viewer->id)
            ->pluck('user_id')
            ->flip();

        foreach ($unknown as $id) {
            $this->visibility[$id] = $settings[$id] ?? UserSettings::DEFAULTS['last_seen_visibility'];
            $this->knowsViewer[$id] = isset($knowViewer[$id]);
        }
    }

    public function lastSeenFor(?User $viewer, ?User $target): ?CarbonInterface
    {
        if ($target === null || $viewer === null) {
            return null;
        }

        if ($viewer->id === $target->id) {
            return $target->last_seen_at;
        }

        $this->prime($viewer, [$target->id]);

        return match ($this->visibility[$target->id]) {
            'everyone' => $target->last_seen_at,
            'contacts' => $this->knowsViewer[$target->id] ? $target->last_seen_at : null,
            default => null,
        };
    }
}

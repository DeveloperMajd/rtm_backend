# Changelog

API and schema changes, newest first. Each entry says what a deploy runs and
whether a client already in the field keeps working.

## Unreleased

### Notification and privacy settings

- **New:** a `user_settings` table with one row per person:
  `read_receipts`, `last_seen_visibility` (`everyone`, `contacts` or
  `nobody`), `typing_indicators`, `message_sounds` and
  `desktop_notifications`.
  - A row is written only the first time someone changes a setting.
  - Until then the defaults apply, and they're what the app has always
    done: share everything, make no sound.
- **New:** `GET /api/settings` and `PATCH /api/settings`. The PATCH is
  partial, needs at least one setting, and is throttled to 20 a minute.
- **Enforced:** read receipts.
  - Someone with them off still reads (their pointer moves, so unread
    counts stay right), but `ConversationRead` isn't broadcast.
  - `GET /reads` leaves their pointer empty for others.
  - It works both ways: they see nobody else's either.
- **Enforced:** last seen. Every resource that carries `last_seen_at`
  (conversations, participants, contacts, contact search) goes through
  `LastSeenVisibility`.
  - The value is null when the person has chosen not to show it to the
    viewer. "Contacts" means people they've added.
  - Online/offline is unaffected.
  - Lookups are batched per request: a list costs the same few queries
    however long it is.
- **Enforced:** typing. `POST /typing` is accepted but not broadcast for
  someone with typing indicators off.
- **Changed (audit D3, for typing):** a member who has left can no longer
  send typing pings to the group (403).
- Sounds and desktop notifications are stored here, so they follow the
  person between devices. The client plays the sounds and shows the
  notifications.

**Deploy:** one migration creates `user_settings`. It's a new table, so
nothing existing is touched.

**Compatibility:** additive. With nobody having changed a setting, every
response is what it was, except that a member who has left now gets a 403
from the typing endpoint (the deployed frontend never sends them one).

### Pin, mute and archive

- **New:** `pinned_at`, `muted_at` and `archived_at` on
  `conversation_participants`. They are each person's own view of a
  conversation; null means off.
- **New:** `PATCH /api/conversations/{id}/preferences` takes `pinned`,
  `muted` and `archived`, as booleans. At least one is required. It answers
  with all three as they now stand.
  - Archiving unpins, and pinning unarchives.
  - Switching on something already on keeps its original time.
  - Allowed in a group the viewer has left, so they can archive it.
  - 403 outside the conversation, 404 for a deleted group.
  - Throttled to 30 a minute.
- **New:** a `ConversationPreferencesUpdated` event on the viewer's own
  `private-App.Models.User.{id}` channel, so their other tabs and devices
  agree. It's sent only when something changed.
- **New:** `GET /api/conversations` carries the viewer's three fields.
- **Changed:** `GET /api/conversations` has a real order: pinned first,
  then most recent activity. Before, it had no `ORDER BY`.
  - For a group the viewer left, activity stops when they left, so the
    order doesn't give away that it carried on.
  - It still returns archived conversations. Keeping them aside is the
    client's job, so a link to one still opens it.
- **Changed:** sending a message brings an archived conversation back for
  the other members, unless they muted it. The sender's own archive is
  left alone. It's one UPDATE per message.
- **Not yet:** muting doesn't silence anything yet, because there are no
  sounds or notifications until the notification settings arrive. For now
  it keeps the conversation out of the unread badges.

**Deploy:** one migration adds three nullable columns with no default. In
Postgres that's a catalogue change: no table rewrite, and no lock held
while the app runs.

**Compatibility:** additive. The deployed frontend ignores the new fields
and sorts the list itself, as it always has.

### Read receipts

- **New:** `GET /api/conversations/{id}/reads` lists how far every current
  member has read: `user_id`, `last_read_message_id` and `last_read_at`.
  - It answers 403 to anyone who isn't a current member, including someone
    who left.
  - It answers 404 for a deleted group.
  - Throttled to 60 a minute.
- **New:** `POST /api/conversations/{id}/read` takes an optional
  `message_id`: read up to there. Without it, it reads up to the newest
  message, as before.
  - The pointer never moves backwards. The update is one conditional
    UPDATE, so racing requests can't undo each other.
  - A message from another conversation is a 422.
- **New:** a `ConversationRead` event on `private-conversation.{id}`, with
  `user_id`, `last_read_message_id` and `last_read_at`. It is sent only
  when a pointer actually moves, so a repeat or stale report broadcasts
  nothing.
- **Changed (audit D3, for reading):** a member who left can no longer mark
  the conversation as read (403). Before, it moved their pointer past the
  moment they left.
- **Changed:** marking a deleted group as read is a 404.
- **Not yet:** the privacy setting to stop sharing your read state comes
  with the notification and privacy settings. Until then everyone's read
  state is shared, which will be that setting's default.

**Deploy:** no migration. The pointer columns already exist.

**Compatibility:** additive. The deployed frontend's `POST /read` has no
body and works as before. It never calls it for a group it has left.

### Search within a conversation

- **New:** `GET /api/messages/search` takes an optional `conversation_id`
  to search only that conversation.
  - It answers 403 if the viewer isn't in the conversation.
  - It answers 404 if the conversation doesn't exist or is a deleted group.
- **New:** `sort=recent` returns the newest matches first. `relevance`
  stays the default.
- **New:** a `limit` parameter. The maximum is 20 everywhere, as before, and
  50 within one conversation.
- **New:** `meta.total` gives the number of matches, beyond the ones
  returned.
- **Changed:** search runs through `Message::visibleTo()`. It checked only
  that the viewer had a participant row, so two things leaked (audit D1):
  - A member who left a group could still find anything said after they
    left.
  - Messages from deleted groups turned up in results.
- **Changed:** the search limit is a named limiter, `messages.search`.
  Searching one conversation gets 60 a minute and its own counter, since
  it re-runs as the viewer types. Searching everywhere stays at 30.

**Deploy:** no migration.

**Compatibility:** additive. The response still has the same `data`, now
with `meta.total` added. The palette's existing requests get the same
results, minus the leaked ones above.

### Jump to a message

- **New:** `GET /api/conversations/{id}/messages/{message}/context?before=&after=`
  returns up to 20 messages either side of one message (at most 50 each
  way), oldest first. It also returns `meta.target_id`,
  `has_more_before`/`has_more_after`, and a cursor for each direction.
  - It answers 403 to someone outside the conversation.
  - It answers 404 when the message is in another conversation, falls after
    the moment the viewer left, or belongs to a deleted group.
  - A deleted message comes back as its usual placeholder.
  - Throttled to 60 a minute.
- **New:** `GET /api/conversations/{id}/messages?after_id=` reads history
  forwards, towards the newest message. It returns `meta.has_more` and
  `meta.next_after_id`. The existing `before_id` mode is unchanged.
- **Changed:** the history endpoint now validates its cursors. A malformed
  `before_id`/`after_id`, or both at once, is a 422 instead of a 500.
- **Changed:** the history endpoint answers 404 for a deleted group. Before,
  it still returned the group's messages to its former members.
- **Fixed:** re-adding someone to a group clears the cutoff left from when
  they were removed.
  - Before, they rejoined and still saw history and a list preview frozen
    at the moment they were removed.
  - They now see the whole history again, including what was said while
    they were out.
- **Internal:** `Message::visibleTo($user)` is the one rule for which
  messages someone may read: participant, not a deleted group, and not past
  their cutoff. New endpoints use it.

**Deploy:** one migration adds two indexes with `CREATE INDEX CONCURRENTLY`:
`messages(conversation_id, id)` and `conversation_participants(user_id)`. It
doesn't lock either table, so sending carries on while it builds.

**Compatibility:** additive. The currently deployed frontend doesn't call
the new endpoint or parameter, and every existing request gets the same
response as before, except the malformed-cursor and deleted-group cases
above.

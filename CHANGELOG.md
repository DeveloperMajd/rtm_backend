# Changelog

API and schema changes, newest first. Each entry says what a deploy runs and
whether a client already in the field keeps working.

## Unreleased

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

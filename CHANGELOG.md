# Changelog

API and schema changes, newest first. Each entry says what a deploy runs and
whether a client already in the field keeps working.

## Unreleased

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

# Changelog

API and schema changes, newest first, under the day each release went live
(anything not live yet goes under Unreleased, at the top). Each entry says
what a deploy runs and whether a client already in the field keeps working.

## Unreleased

### PHP runs with production settings

- **Fixed:** the image shipped no php.ini, so PHP ran on its built-in
  defaults: an upload over 2 MB was refused although the API takes
  attachments up to 15 MB (avatars 5 MB), errors were displayed rather than
  only logged, and every response said `X-Powered-By: PHP/8.5.12`. The image
  now starts from PHP's `php.ini-production`, and `docker/php.ini` raises
  the upload limits to 16 MB a file and 20 MB a request (nginx's own limit)
  and turns `expose_php` off.
- **Changed:** PHP-FPM runs a static pool of 20 workers
  (`docker/php-fpm.conf`), each replaced after 500 requests, where the
  stock pool ran at most 5 requests at a time. They all start with the
  container: 20 one-second requests arriving together are answered in
  about a second, where the dynamic mode, adding workers gradually, took 3
  to 4.
- **Checked:** `php.ini-production` no longer fills `$_ENV`
  (`variables_order = GPCS`). Laravel reads the environment through
  `getenv()`, and the containers cache config at start, so nothing changes.
  The command-line processes (queue worker, Reverb, presence watcher) keep
  running without a time limit.

**Deploy:** no migration, nothing new in `.env`; the image is rebuilt, as on
every deploy. On the server, `docker exec rtm-app php -i | grep -E
'upload_max|post_max'` and `docker exec rtm-app php-fpm -tt` show the new
values.

**Compatibility:** no API change. A photo over 2 MB, which used to fail,
now uploads.

## 2026-10-22

### The skeleton's npm tools updated, and no major-version proposals

- **Security:** GitHub's Dependabot alerts also cover package-lock.json:
  the build tools the Laravel skeleton brought for its welcome page, and
  concurrently, which `composer run dev` runs. None of it is deployed (the
  image installs no npm packages), but it runs on a developer's machine.
  axios goes, as it has from Laravel's own skeleton since April: only
  resources/js/bootstrap.js used it, to put it on `window` for a page that
  makes no requests. `npm audit fix` updates the rest within range (vite
  8.3.5, concurrently 9.2.5 with shell-quote 1.12.0, postcss, nanoid,
  source-map-js), and a new `.npmrc` stops npm running packages' install
  scripts, as the skeleton does now.
- **Changed:** Dependabot no longer proposes major versions: they're
  upgraded by hand, with what has to move with them. Its first run proposed
  some that couldn't even install on their own. Security fixes still come
  whatever version they need.

**Deploy:** nothing changes on the server.

**Compatibility:** no API change.

### Packages updated, and audited before every deploy

- **Security:** `composer update`, within composer.json's constraints, for
  the 40 advisories `composer audit --no-dev` reported (13 high).
  laravel/framework 13.4.0 → 13.38.0 brings among others the fix for a CRLF
  injection through the `email` validation rule, which signing up and
  resetting a password use, and for array query parameters getting past
  signed URL validation, which the email confirmation link relies on.
  symfony/* 8.0 → 8.1 (mime, http-foundation, http-kernel, routing,
  mailer), guzzlehttp/guzzle 7.10 → 7.16, guzzlehttp/psr7 2.9 → 2.13,
  league/commonmark 2.8 → 2.10 and phpseclib 3 → 4 clear the rest; also
  laravel/reverb 1.10.2 → 1.12.0, laravel/sanctum 4.3.3 and
  laravel/socialite 5.31.0. Pest stays on 4.
- **Checked:** since Laravel 13.20, `trustProxies(at: '*')` trusts every
  hop and takes the first `X-Forwarded-For` entry, where it used to trust
  only the caller. Behind Caddy, which writes that header itself, there's
  one entry, so a visitor's address is what it was. Since 13.33 signing in
  stores the session's password fingerprint itself; the `Login` listener
  from 2026-10-20 still clears it (it's needed for accounts without a
  password) and `AuthenticateSession` stores it again on the next request,
  as before.
- **Changed:** the deploy waits for `composer audit --locked --no-dev` as
  well as Pint and the tests: a known advisory in a package production runs
  stops it until the package is updated, or the advisory is ignored in
  `config.audit.ignore` with the reason. An abandoned package is reported
  without stopping it.
- **New:** Dependabot proposes Composer updates once a week, a week's minor
  and patch releases in one pull request, each release a week old first.

**Deploy:** no migration, nothing new in `.env`. The image is rebuilt with
the new packages, as on every deploy.

**Compatibility:** no API change.

### Removing someone stops their live updates at once

- **Fixed:** a conversation's messages, edits, reactions, read receipts and
  typing went out on a `private-conversation.{id}` channel. Reverb checks
  who may listen to a channel once, when a socket subscribes, so a member
  who was removed from a group, or left it, kept receiving all of it
  (attachment links included) until their socket disconnected, and so did
  the members of a deleted group. These events now go to each current
  member's own channel (`private-App.Models.User.{id}`), and who that is
  is worked out as each one goes out, on the queue worker: a member removed
  in between isn't told, and nobody is once the group is deleted.
- **Removed:** the `private-conversation.{id}` channel. Subscribing to it is
  refused (403). `ConversationParticipantsUpdated` and `ConversationDeleted`
  no longer go out on it either; they already reached everyone's own channel.
- **Changed:** `ConversationRead` carries `conversation_id`, since it now
  arrives on the same channel as every other conversation's.
- **Changed:** Reverb relays no client events ("whispers") any more:
  `accept_client_events_from` is `none`. The app sends none.

**Deploy:** no migration, nothing new in `.env`. Deploy the frontend first:
it reads the open conversation's events from the user's own channel.

**Compatibility:** breaking for a client that listens on
`private-conversation.{id}`: its subscription is refused, and nothing is sent
there. A tab opened before the frontend deploy keeps its chat list live, but
not the open conversation, until it's reloaded. Each member's own channel
now also receives `MessageUpdated`, `MessageReactionUpdated` and
`ConversationRead` for all of their conversations, not just the open one.

## 2026-10-20

### Rate limits count per visitor

- **Fixed:** behind Caddy and nginx, every visitor's request reached Laravel
  from nginx's address, so each guest rate limit was one bucket for
  everybody: five failed sign-ins a minute, from anyone, locked every
  sign-in out for that minute, and the same went for signing up and
  resetting a password. Laravel now trusts the proxy in front for
  `X-Forwarded-For` and `X-Forwarded-Proto` (Caddy sets both and ignores any
  a visitor sends), so `$request->ip()` is the visitor's address and
  `$request->secure()` is true over HTTPS.
- **Changed:** signing in is limited to 5 tries a minute per account and
  address, and 20 a minute per address across accounts, instead of 5 a
  minute per address.

**Deploy:** no migration, nothing new in `.env`.

**Compatibility:** no change for clients.

### Password sign-ups confirm their email

- **New:** signing up with a password emails a link that confirms the
  address (`MustVerifyEmail` on `User`, Laravel's `VerifyEmail`). The link
  goes to the frontend's `/verify-email` page, which posts it back to
  `POST /api/auth/email/verify/{id}/{hash}` (a signature relative to the
  path, valid 60 minutes) while signed in as that account. Opening the
  email while signed in as someone else, or signed out, confirms nothing:
  otherwise whoever signed up with another person's address could have
  that person verify it for them.
- **New:** `POST /api/auth/email/verification-notification` sends another
  link (3 every 10 minutes; a 204 and no email once the address is
  confirmed).
- **Changed:** until the address is confirmed, everything under `/api` but
  `auth/*` answers 403 (`Your email address is not verified.`), and so does
  `/broadcasting/auth`; `auth/me` and `auth/logout` keep working. Every
  profile response (`auth/me`, `login`, `register`, `profile`) now has
  `email_verified_at`. Accounts made or claimed through Google are verified
  already.

**Deploy:** no migration, nothing new in `.env`. Deploy the frontend first:
it shows "Check your email" for `email_verified_at: null` and carries on as
before while the field isn't there, whereas an older frontend would only
see 403s.

**Compatibility:** breaking for the password accounts that exist today,
none of which has confirmed its address: on their next visit they get a
link and confirm it before using the app again.

### Google sign-in claims an unverified account

- **Fixed (security):** a Google sign-in whose email matches an account that
  never verified its address now claims that account: its password is
  removed and its remember token rotated, so every session signed in with
  the old password ends (see below). Before, the Google identity was linked
  and the password kept, so whoever had signed up with someone else's
  address first could still sign in as them afterwards.
- **Changed:** a Google sign-in that goes by the email (a first sign-in, or
  a link to an existing account) needs Google to have verified the address
  (`email_verified`). Without it the callback redirects to
  `/login?error=oauth_failed`, as for any other failure. An identity that's
  already linked signs in by its Google id, as before, and when its account
  never confirmed its address (an older version linked without doing so),
  the sign-in claims it the same way, provided Google vouches for that very
  address.
- **New:** Sanctum's `AuthenticateSession` on every signed-in route, on
  `auth/me` and `auth/logout`, and on `/broadcasting/auth`. A session keeps
  a fingerprint of the password it was signed in with and gets a 401 once
  the account's password is different. So changing or resetting a password
  now also signs the account's other sessions out, while the session that
  changed it stays signed in. A sign-in starts without a fingerprint left in
  the session from before.

**Deploy:** no migration, nothing new in `.env`.

**Compatibility:** sessions from before the deploy have no fingerprint yet
and take one on their next request, so the deploy signs nobody out. Someone
who signed up with a password and later continues with Google loses that
password (Forgot password sets a new one).

## 2026-10-16

### Presence is pushed

- **New:** a `PresenceChanged` event (`user_id`, `presence_status`,
  `is_online`) on the private channel (`App.Models.User.{id}`) of everyone
  who sees the person: the other current members of their conversations
  (not a deleted group's) and whoever has them as a contact. It's sent only
  when the status changes: online from offline, away, back, and offline,
  whether by a leave or by the key running out. A heartbeat that keeps
  things as they were sends nothing.
- **New:** `php artisan presence:watch`, run for good like the queue worker
  (the `presence` container in production, and `composer run dev`). It
  waits on Redis's expired-key notifications, which it switches on itself,
  and announces offline when a presence key runs out with nobody leaving (a
  crash, a lid closed). It sends nothing while nothing expires.
- **Internal:** a heartbeat writes its key and reads the old value in one
  Lua call (the one round trip SETEX took), so it knows whether anything
  changed. `PresenceService::audienceOf()` says who's told.

**Deploy:** no migration. The deploy creates the `presence` container
(`rtm-presence`) beside the queue worker, with nothing new in `.env`.

**Compatibility:** additive. A client that doesn't listen for
`PresenceChanged` sees statuses as it always did, from the API.

### Every heartbeat lasts 90 seconds

- **Changed:** an active heartbeat's Redis key lasts 90 seconds too, like an
  away one. In production a browser slowed a background tab to one beat a
  minute within a minute of hiding it, long before it turned away, so at
  30 seconds its user flickered between online and offline until then.

**Deploy:** no migration. Keys written before it keep their 30 seconds.

**Compatibility:** no API change. Someone whose tab is gone without a leave
(a crash, a lid closed) reads as online for up to 90 seconds instead of 30.

### Away lasts through a background tab's slowed beats

- **Changed:** an away heartbeat's Redis key lasts 90 seconds instead of 30.
  A tab out of view turns away after five minutes, which is just when
  Chrome starts slowing its timers to once a minute, so its beats came a
  minute apart and someone left in the background flickered between away
  and offline. An active beat still lasts 30 seconds, so a tab that's gone
  without saying so (a crash, a lost connection) still shows offline soon.

**Deploy:** no migration. Keys written before it keep their 30 seconds.

**Compatibility:** no API change. Someone whose away tab is gone without a
leave reads as away for up to 90 seconds instead of 30.

### Redis on the VM

- **Changed:** sessions, the cache (the rate limiter's counters), the queue
  and presence all run on a `redis` container in `docker-compose.prod.yml`,
  on the stack's internal network, instead of on Neon (sessions, cache,
  queue) and Upstash (presence). An idle queue worker and Reverb poll their
  store around the clock: on Neon that kept the compute from ever scaling
  to zero and used up the Free plan's compute in mid-October, and on
  Upstash it had used up the command quota. A container's polling costs
  nothing. See the backend README's incident notes.
- **Internal:** the queue container runs `queue:work redis --sleep=0`, and
  the Redis queue waits for jobs with `block_for: 5` (a blocking pop)
  instead of polling, so a broadcast is picked up the moment it's queued
  rather than at the worker's next check. The deploy starts Redis with its
  own `up -d --wait redis` before migrating, outside the forced recreate.
- **Internal:** the workflow, now "Test and deploy", runs Pint and the Pest
  suite against Postgres and Redis service containers on every push to main
  and every pull request into it. The deploy job needs that to pass, and
  only runs for main.

**Deploy:** no migration. Before merging, in the server's `.env`: set
`SESSION_DRIVER`, `CACHE_STORE` and `QUEUE_CONNECTION` to `redis`, set
`REDIS_HOST=redis`, `REDIS_PORT=6379` and `REDIS_PASSWORD=null`, and delete
`REDIS_URL` (it overrides the host, so it would point everything back at
Upstash). `REDIS_CACHE_DB=0` can stay or go. Neon has to be reachable, since
the deploy migrates before it recreates anything.

**Compatibility:** everyone is signed out once, since sessions move from
Postgres to Redis and none are carried over, and anyone online reads as
offline until their next heartbeat (15 seconds at most). Broadcast jobs
still in the `jobs` table at deploy time are never sent. No API change.

## 2026-10-08

### Shared media

- **New:** `GET /api/conversations/{id}/attachments` lists a conversation's
  shared media for its info panel: the photos (`kind=media`) or the other
  files (`kind=files`), newest first. Each is the attachment as a message
  carries it (with a fresh signed `url`), plus who sent it (`sender`) and
  when (`sent_at`), since the list gathers many messages' attachments.
  - It pages from `?before_id=` (an attachment's id), `limit` 12 by default
    and 50 at most, with `meta.has_more` and `meta.next_before_id`, and
    `meta.total` counts them all.
  - Only what the viewer can see in the history (`Message::visibleTo`):
    nothing from a message deleted since, and for a member who left,
    nothing sent after they did. Uploads not sent yet aren't in any
    conversation.
  - 403 outside the conversation and 404 for a deleted group, as the
    history; a page costs the same few queries however many people sent
    the photos. Throttled to 60 a minute.
- **Internal:** the history endpoints' gate (`denyUnlessReadable`) moved
  from `MessageController` into the `GuardsConversationHistory` trait,
  which the new endpoint uses too. Nothing it answers changes.

**Deploy:** no migration.

**Compatibility:** additive. A new endpoint.

### Typing in the chat list

- **Changed:** `TypingIndicator` is broadcast to each other current member's
  own channel (`App.Models.User.{id}`) as well as to the conversation, so a
  chat list can show who's typing on a conversation that isn't open. It's
  still one broadcast: Reverb gets every channel in the one call. Never to
  the typist, nor to someone who has left.
- **New:** its payload carries `conversation_id` beside `user_id` and
  `name`.
- **Internal:** the typing endpoint fetches the current members once, both
  to check the sender is one and to address the others; it costs the same
  few queries however big the group is. Typing still never touches Redis.

**Deploy:** no migration.

**Compatibility:** additive. A client that only listens on the
conversation channel sees what it always did, plus a field it can ignore.

### Bio in a direct conversation

- **New:** a direct conversation's `other_participant` carries their `bio`
  (null if they haven't written one), for the contact panel. Anyone who
  shares a conversation with them sees it, as they see their name and
  avatar.

**Deploy:** no migration.

**Compatibility:** additive. One new field.

### Away presence

- **New:** `POST /api/presence/heartbeat` takes an optional `state`:
  `active` (the default, and what a heartbeat without one means) or
  `away`, for an app that's open but has been left idle. Anything else is
  a 422.
- **New:** `presence_status` (`online`, `away` or `offline`) beside
  `is_online` wherever presence is given: a conversation's
  `other_participant` and `participants`, the participants endpoint, and
  contacts and contact search. `is_online` is unchanged and stays true
  while someone is away. Like `is_online`, it isn't hidden by "last seen".
- **Internal:** the heartbeat's Redis key now holds `active` or `away`
  instead of a timestamp, and presence is read with GET where it was
  EXISTS: the same one command per person, so away costs no Redis command
  of its own (Upstash quota). A key written before this deploy (a
  timestamp, alive for at most 30 seconds) reads as online. The
  `PresenceStatus` enum names the three states, and
  `PresenceService::statusOf()` / `statusesOf()` replace `isOnline()` /
  `onlineUserIds()`.

**Deploy:** no migration.

**Compatibility:** additive. A client that never sends `state` is always
online while its heartbeats arrive, as before.

### Saved messages

- **New:** a `saved_messages` table: one row per person and message they've
  saved, with a UUIDv7 `id` (so ordered by when it was saved),
  `created_at`, a unique `(user_id, message_id)`, and an index on
  `(user_id, id)` for the list. A row goes with its message or its person.
- **New:** `POST /api/messages/{id}/save` saves a message, the viewer's own
  or anyone's; saving it again changes nothing. `DELETE` on the same path
  takes it off the list, whether or not it was on it. Both answer 204.
  - Saving answers 404 for a deleted group, a group event line or a deleted
    message, and 403 in a conversation the viewer isn't in, or isn't in any
    more.
  - Removing touches only the viewer's own row, so it's allowed wherever
    they have a place in the conversation, even after leaving it or once
    the message is deleted. Anyone else gets 403.
  - Each is throttled to 60 a minute, on its own counter.
- **New:** `GET /api/saved-messages` lists what the viewer has saved, most
  recently saved first: `id`, `saved_at`, the `message` (as the history
  shows it, with sender and attachments) and its `conversation` (`id`,
  `type`, and `title`: the group's, or the other person's name). It pages
  from `?before_id=` (a save's id), `limit` 30 by default and 50 at most,
  with `meta.has_more` and `meta.next_before_id`. Throttled to 60 a minute.
  - Only messages still there for the viewer, in conversations still
    theirs (`Message::saveableBy`). A group they left or were removed from
    keeps its saved messages off the list, however early they were, as does
    a deleted group or a message deleted since. The rows are kept, and show
    again if the viewer is added back.
  - A page costs the same few queries however many conversations it spans.
- **New:** `GET /api/saved-messages/ids` returns the ids of every message the
  viewer has saved, for the message menu's Save or Remove. Throttled to 30 a
  minute.
- Whether a message is saved is the viewer's alone: it isn't on the message
  resource, whose broadcast copy everyone in the conversation shares, and
  nothing is broadcast.

**Deploy:** one migration creates `saved_messages`. It's a new table, so
nothing existing is touched.

**Compatibility:** additive. New endpoints; nothing existing changes.

### Read receipts count by the settings at the time of reading

- **Changed:** every read is decided when it happens, by both people's
  "Read receipts" settings at that moment. A read shows to a viewer only if
  the reader and the viewer both had read receipts on when it was made.
  Switching only changes what happens from then on, for either of them,
  however often they switch: what was shown stays shown, and what wasn't
  never is. Before, the setting applied to everything at once: switching on
  showed what had been read while it was off, and switching off hid what
  had already been shown, on both sides.
- **New:** two lists of stretches on `conversation_participants`, each
  `[from, to]` positions of a read pointer (`from` exclusive and null for
  the beginning, `to` inclusive and null while open, running to the
  pointer):
  - `receipt_stretches`: the ranges of the person's own pointer that they
    read with read receipts on;
  - `viewer_stretches`, keyed by user id: the ranges of each other
    member's pointer that were covered while the person had read receipts
    on.
- **New:** `PATCH /api/settings`, on a read receipts switch, opens a stretch
  in each of the person's conversations where each pointer stands (theirs,
  and every other member's), or closes it there. Marking as read writes
  nothing new: an open stretch runs to wherever the pointer is. The newest
  20 per list are kept; dropping one hides what it covered and never shows
  anything.
- **New:** `GET /api/conversations/{id}/reads` gives each member's
  `stretches` and the viewer's `viewer_stretches` of them. A message counts
  as read only inside both (an open one runs to `last_read_message_id`).
  `last_read_message_id` never goes past what the viewer may see, and
  `last_read_at` is null when that read isn't one they may see. The
  `ConversationRead` broadcast carries the reader's `stretches`.
- **Changed:** `GET /api/messages/{id}/info` lists someone in `read_by` only
  if both of them had read receipts on when they read it. While the viewer's
  own are off, the lists are still given (what was read before they
  switched still shows) and `receipts_off: true` says so. That replaces
  `receipts_hidden` and its null lists, which were never released.

**Deploy:** two migrations, each adding a nullable `jsonb` column with no
default: a catalogue change, no table rewrite. Nothing is backfilled. A list
that isn't there yet follows its person's current setting for all of its
history, which is what everyone is shown today, so nobody's screen changes
when this ships; the difference appears the next time someone switches.

**Compatibility:** additive. For a client that doesn't know about
stretches, `last_read_message_id` keeps its meaning: where the reading the
viewer may see ends. Such a client treats everything up to there as read,
so after someone switches off and on it can show messages read while off,
until it's updated.

### Message info

- **New:** `GET /api/messages/{id}/info` returns what there is to know about
  one message: `sender`, `sent_at`, `edited_at` and `deleted_at` and, on the
  viewer's own messages, `read_by` and `not_read`. Each is a list of
  `user_id`, `name` and `avatar_url`, in name order.
  - Only people still in the conversation are listed, and never the viewer.
  - Someone has read it once their pointer has reached it, so reading a
    later message counts.
  - There is no time beside a name. The server knows when a pointer last
    moved, not when its owner read this message.
  - Both lists are `null` on someone else's message, and for a viewer who
    has left.
  - It answers 403 outside the conversation, and 404 for a deleted group,
    for a message after the viewer left, and for a group event line.
  - Throttled to 60 a minute.
- **Enforced:** read receipts, both ways, as `GET /reads` does.
  - Someone who has them off is never in `read_by`. They are in `not_read`,
    like anyone who hasn't read it yet.
  - A viewer with their own off is told so with `receipts_off: true`.
  - (Refined by "Read receipts count by the settings at the time of
    reading", above.)
- **Internal:** the rule for whose read state a viewer may see is now
  `ReadReceiptVisibility`, used by `GET /reads` and by this endpoint so the
  two can't drift apart. `GET /reads` answers exactly as before.

**Deploy:** no migration.

**Compatibility:** additive. A new endpoint; nothing existing changes.

## 2026-10-05

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

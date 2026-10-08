<div align="center">

# RTM Backend

Laravel 13 REST API + WebSocket server for [RTM](https://rtm.developermajd.com),
a real-time messaging app. See the [root README](https://github.com/DeveloperMajd/rtm_backend/blob/main/../README.md)
for the project overview and architecture diagram, or the
[frontend repo](https://github.com/DeveloperMajd/rtm_frontend) for the React client.

**Live**: `https://api.rtm.developermajd.com` · `wss://ws.rtm.developermajd.com`

</div>

## Stack

- **Laravel 13**, PHP 8.5
- **PostgreSQL** (durable data) — hosted on [Neon](https://neon.tech) in production
- **Redis** (sessions, cache, queue, presence/typing, Reverb's pub/sub) —
  [Upstash](https://upstash.com) in production
- **Laravel Reverb** — self-hosted WebSocket server (Pusher-protocol
  compatible), not a third-party WebSocket SaaS
- **Sanctum** — SPA cookie auth, with personal-access-token support for a
  future non-browser client
- **Socialite** — Google OAuth
- **Resend** — transactional email (password reset)
- **Pest** — 127 tests, feature-first

## Feature overview

- Direct + group conversations, replies, edit/delete (redaction, not hard
  delete — a deleted message's reply-quotes update everywhere they appear),
  emoji reactions
- PostgreSQL `tsvector` full-text search across message history, weighted
  by relevance
- Saved messages: a private list of messages to find again, anyone's or
  your own, shown only while their conversation is still yours
- Group admin model: multiple admins, promote/demote, admin-gated
  add/kick, a sole admin can't leave without promoting someone first
- Left/kicked participants keep their row (not deleted) with a
  `left_at` + `left_at_message_id` cutoff — they see history up to the
  moment they left, frozen, with no further live updates and no way to post
- System messages ("Alice added Bob", "You left") are real `Message` rows
  (`type = 'system'`), so they flow through the same pagination/broadcast
  pipeline as everything else instead of being a bolted-on client-side thing
- Attachments via private, short-lived signed URLs — never a public bucket
  path
- Personal contacts (a directory you build, not "every user on the site")
  — adding a contact creates the direct conversation immediately
- Presence (online, away once the app has been left idle, offline) +
  typing indicators, entirely Redis-backed — this state never touches
  Postgres. Away is the heartbeat key's value, so it costs no Redis
  command of its own
- Password policy (`Password::defaults()`) with a HaveIBeenPwned check in
  production only (never in tests/CI, which have no network access), plus
  a full forgot/reset-password flow

## API reference

All routes are under `/api`. Everything except `auth/*` requires a Sanctum
session (`auth:sanctum` + the `web` middleware group, so CSRF/cookie rules
apply — see [Local setup](#local-setup)).

<details>
<summary><strong>Auth</strong></summary>

| Method | Endpoint | Notes |
|---|---|---|
| POST | `/auth/register` | throttled 5/min |
| POST | `/auth/login` | throttled 5/min |
| POST | `/auth/logout` | |
| GET | `/auth/me` | |
| GET | `/auth/google/redirect` | throttled 10/min |
| GET | `/auth/google/callback` | throttled 10/min |
| POST | `/auth/forgot-password` | throttled 5/min |
| POST | `/auth/reset-password` | throttled 5/min |

</details>

<details>
<summary><strong>Conversations</strong></summary>

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/conversations` | pinned first, then most recent activity; each carries the viewer's own `pinned_at`, `muted_at`, `archived_at` (archived ones are included — the client keeps them aside) |
| POST | `/conversations` | throttled 10/min |
| GET | `/conversations/{id}` | |
| PATCH | `/conversations/{id}` | rename a group; throttled 20/min |
| POST | `/conversations/{id}/typing` | current members only; accepted but not broadcast when the sender has typing indicators off; throttled 30/min |
| POST | `/conversations/{id}/read` | moves the viewer's read pointer to `message_id` (or the newest message without one), never backwards; when it moves and the viewer shares read receipts, broadcasts `ConversationRead` with their pointer and read stretches; 403 for a member who left; throttled 30/min |
| PATCH | `/conversations/{id}/preferences` | the viewer's own `pinned` / `muted` / `archived` (booleans, at least one); archiving unpins, pinning unarchives; tells the viewer's other tabs (`ConversationPreferencesUpdated` on their user channel); allowed in a group they left; throttled 30/min |
| GET | `/conversations/{id}/reads` | what the viewer may see of every current member's reading, for "Seen" / "Seen by": `user_id`, `last_read_message_id`, `last_read_at`, `stretches` (the `[from, to]` ranges of their pointer they read with read receipts on; the last one open, `to: null`, while they share) and `viewer_stretches` (the ranges covered while the viewer had theirs on). A read shows only inside both, so it counts only if both people had read receipts on when it was made, however either switches later. `last_read_message_id` never goes past what the viewer may see; current members only; throttled 60/min |

</details>

<details>
<summary><strong>Settings</strong></summary>

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/settings` | the viewer's `read_receipts`, `last_seen_visibility` (`everyone`/`contacts`/`nobody`), `typing_indicators`, `message_sounds`, `desktop_notifications` — defaults until first changed |
| PATCH | `/settings` | any of the above, at least one; switching `read_receipts` opens or closes a read stretch in each of the viewer's conversations (for their own pointer and every other member's), so it only affects reads from then on, theirs and others'; throttled 20/min |

Every `last_seen_at` in a response goes through `LastSeenVisibility`: null
when the person has chosen not to show it to the viewer.

</details>

<details>
<summary><strong>Messages, attachments, reactions</strong></summary>

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/conversations/{id}/messages` | cursor-paginated, one direction per request: `?before_id=` reads older (returns `meta.has_more`, `meta.next_before_id`), `?after_id=` reads newer (returns `meta.has_more`, `meta.next_after_id`); `limit` default 25, max 100; 404 for a deleted group |
| GET | `/conversations/{id}/messages/{message}/context` | a window around one message, for jumping to it: `?before=&after=` (default 20, max 50 each way); returns `meta.target_id`, `has_more_before`/`has_more_after` and a cursor each way; 404 when the message is past the viewer's leave cutoff, in another conversation, or in a deleted group; throttled 60/min |
| POST | `/messages` | throttled 30/min |
| GET | `/messages/search` | Postgres full-text search: `?q=` (2–200 chars), optional `conversation_id` to search one conversation, `sort=relevance` (default) or `recent`, `limit` (max 20 everywhere, 50 in one conversation); returns `meta.total`; only messages the viewer may read (no deleted groups, nothing after they left); throttled 30/min everywhere, 60/min in one conversation |
| PATCH | `/messages/{id}` | throttled 30/min |
| DELETE | `/messages/{id}` | redacts, doesn't hard-delete; throttled 30/min |
| GET | `/messages/{id}/info` | who sent it and when it was sent, edited and deleted; on your own message, who has read it and who hasn't yet (`read_by`, `not_read`, by name, current members only). Someone counts as having read it only if both of you had read receipts on when they did; anyone else reads as "not yet". While yours are off, `receipts_off` says so. Both lists are `null` on someone else's message. 403 outside the conversation; 404 for a deleted group, past the viewer's leave cutoff, or a group event line; throttled 60/min |
| POST | `/attachments` | throttled 30/min |
| GET | `/attachments/{id}` | resolves a signed URL |
| POST | `/messages/{id}/reactions` | throttled 60/min |
| DELETE | `/messages/{id}/reactions/{reaction}` | throttled 60/min |

</details>

<details>
<summary><strong>Saved messages</strong></summary>

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/saved-messages` | what the viewer has saved, most recently saved first: `id`, `saved_at`, the `message` and its `conversation` (`id`, `type`, `title`: the group's, or the other person's name); `?before_id=` (a save's id) pages back, `limit` default 30, max 50, with `meta.has_more` and `meta.next_before_id`. Only messages in conversations still the viewer's: nothing from a group they left or were removed from, a deleted group, or a message deleted since (the rows are kept, and show again if they're added back); throttled 60/min |
| GET | `/saved-messages/ids` | the ids of every message the viewer has saved, for the message menu; throttled 30/min |
| POST | `/messages/{id}/save` | saves a message, the viewer's own or anyone's; saving it again changes nothing; 204. 403 in a conversation the viewer isn't in, or isn't in any more; 404 for a deleted group, a group event line or a deleted message; throttled 60/min |
| DELETE | `/messages/{id}/save` | takes it off the viewer's list, whether or not it was on it; 204. Allowed wherever the viewer has a place in the conversation, even after leaving; 403 for anyone else; throttled 60/min |

</details>

<details>
<summary><strong>Group participants</strong></summary>

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/conversations/{id}/participants` | |
| POST | `/conversations/{id}/participants` | add a member; admin-only; throttled 20/min |
| PATCH | `/conversations/{id}/participants/{user}` | promote/demote; admin-only; throttled 20/min |
| DELETE | `/conversations/{id}/participants/{user}` | leave; blocked for a sole admin with other members present |
| DELETE | `/conversations/{id}/participants/{user}/kick` | admin-only; throttled 20/min |

</details>

<details>
<summary><strong>Contacts, profile, presence</strong></summary>

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/contacts` | |
| GET | `/contacts/search` | throttled 20/min |
| POST | `/contacts` | creates contact + direct conversation; throttled 20/min |
| DELETE | `/contacts/{user}` | |
| GET | `/profile` | |
| PATCH | `/profile` | |
| PATCH | `/profile/password` | throttled 5/min |
| POST | `/profile/avatar` | throttled 10/min |
| DELETE | `/profile/avatar` | |
| POST | `/presence/heartbeat` | online for the next 30s; optional `state`: `active` (the default) or `away` (the app is open but idle), which people see as `presence_status: away` while `is_online` stays true; throttled 20/min |
| POST | `/presence/leave` | |

</details>

## Local setup

Requires Docker (for [Sail](https://laravel.com/docs/sail)) and PHP/Composer
on the host for the CLI commands.

```bash
composer run setup   # composer install, .env, key:generate, migrate, npm i (for boost.json only — no frontend build here)
composer run dev      # Sail up, queue listener, Reverb, and log tailing in one terminal
```

Or step by step:

```bash
cp .env.example .env
composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
```

The default `.env.example` uses `MAIL_MAILER=log` (writes to
`storage/logs/laravel.log`, delivers nowhere) so a fresh clone needs zero
mail-provider setup. To actually test the forgot-password flow locally,
sign up at [resend.com](https://resend.com) and set `MAIL_MAILER=resend` +
`RESEND_API_KEY` — see the comments in `.env.example`.

### Testing

```bash
./vendor/bin/sail artisan test          # full suite (128 tests)
./vendor/bin/sail artisan pint --test   # style check
vendor/bin/pest --filter="test name"    # a single test
```

## Deployment

Not deployed to a PaaS — this runs on a single **Oracle Cloud "Always
Free"** ARM VM via Docker Compose, chosen deliberately: Fly.io's free tier
no longer exists, and a small VM that can host *this project plus future
ones* side by side (one shared reverse proxy, one box) is a better fit for
a portfolio with more than one non-commercial app than paying per-project
PaaS pricing for each.

**Stack on the VM:**
- `docker-compose.prod.yml` — four containers built from one `Dockerfile`
  (PHP 8.5-FPM + the extensions Postgres/Redis/etc. need): the app itself,
  a queue worker, Reverb, and nginx in front of PHP-FPM
- A separate, shared **Caddy** container (not part of this repo — it's
  infra shared with other projects on the same box) terminates TLS and
  reverse-proxies by subdomain
- **Neon** (Postgres) and **Upstash** (Redis) instead of running either as
  a container — keeps the VM's limited RAM for compute, and offloads
  backups/scaling to a managed service

**CI/CD**: `.github/workflows/deploy.yml` — push to `main` triggers an SSH
deploy that rebuilds, runs migrations, and restarts the stack. Getting this
right took three iterations; the second and third fixed real bugs in the
deploy script itself, not the app:

1. The first automated deploy reported success but silently kept the *old*
   code running. Root cause: `docker compose run` (used for
   `php artisan migrate --force`) attaches to stdin by default — and since
   the whole deploy script is fed to `ssh host 'bash -s' <<HEREDOC`, its own
   stdin *is* that heredoc stream. Without redirecting `run`'s stdin away
   from it, `run` consumed the rest of the script itself, cutting execution
   off right after the migrate step — before `up -d` or the nginx restart
   ever ran. Bash hitting EOF isn't an error, so the step kept reporting
   green.
2. Fixed with `-T --no-TTY` plus `< /dev/null` on that line, confirmed by
   direct reproduction, then documented in `docker-compose.prod.yml`'s own
   comment so it doesn't get "simplified" away later.
3. Along the way, `up -d` also needed `--force-recreate` on the three
   containers built from the shared image: a service referenced only by a
   stable `image:` tag (not its own `build:`) can have its "does this need
   recreating" check see the tag string as unchanged even though `build`
   just pointed it at a new image underneath.

See `docker-compose.prod.yml`'s header comment for the full reasoning —
kept there deliberately, not just in this README, since that's what
someone debugging a future deploy will actually be looking at.

### Incident: Upstash free-tier quota exhausted mid-development

A real production outage, not a hypothetical: Upstash's free tier caps at
500K Redis commands/month, and development/testing traffic alone (every
login, page load, and WebSocket reconnect touches Redis at least twice for
session read/write) burned through it before any real user did. Upstash
then rejected the connection outright (`RedisException: AUTH failed while
reconnecting`) — since sessions, cache, and the rate limiter all ran on
Redis, this broke login, every authenticated route, and anything
rate-limited, immediately.

Fixed by moving `SESSION_DRIVER`, `CACHE_STORE`, and `QUEUE_CONNECTION` to
`database` (Neon) instead — no new migration needed, since Laravel's
default starter migration already creates the `sessions`/`cache`/`jobs`
tables up front, whether or not you end up using them. Redis stays
configured and is still used directly by `PresenceService` for online/
offline presence (typing indicators broadcast straight through Reverb and
never touch Redis at all), so that one feature — not the whole app — is
what actually depends on Upstash being up. See
`.env.production.example`'s comment above `SESSION_DRIVER` for the full
reasoning.

**Follow-up**: the free-tier quota was hit a second time weeks later —
believable given how *any* command counts against it, including a 30s
presence heartbeat per active tab — and this time it took `/api/
conversations` down with it. `ConversationResource` calls
`PresenceService::onlineUserIds()` (now `statusesOf()`) unconditionally to compute each
participant's online dot, and that call had no error handling, so a
Redis outage 500'd every conversations-list load, not just presence
itself. Presence is ephemeral, non-critical data by design, so it should
degrade to "nobody online" on a Redis failure rather than take core
messaging down with it. `PresenceService` now wraps every Redis call and
fails soft (`RedisException` → logged as a warning, treated as offline),
covered by a regression test in `PresenceTest.php`.

### Environment reference

`.env.production.example` documents every production variable. The
notable ones that differ from local dev:

| Variable | Why |
|---|---|
| `DB_URL` | Neon's **direct** (non-pooled) connection string — this app's connection count is small and bounded (a handful of PHP-FPM workers + one queue worker + Reverb), nowhere near where PgBouncer pooling starts to matter, and migrations need the direct string regardless |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` = `database` | see the incident above — these ran on `redis` until Upstash's free-tier quota got exhausted |
| `SESSION_DOMAIN=.rtm.developermajd.com` | shared parent of the frontend and this API — required for the Sanctum session cookie to actually be sent on cross-subdomain requests |
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` vs `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | the former is what the process binds to *inside* its container (`0.0.0.0:8080`); the latter is what the outside world (via Caddy) actually connects to (`ws.rtm.developermajd.com:443` over `https`) |

## License

MIT — see `LICENSE`.

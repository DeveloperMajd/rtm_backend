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
- Presence + typing indicators, entirely Redis-backed — this state never
  touches Postgres
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
| GET | `/conversations` | |
| POST | `/conversations` | throttled 10/min |
| GET | `/conversations/{id}` | |
| PATCH | `/conversations/{id}` | rename a group; throttled 20/min |
| POST | `/conversations/{id}/typing` | throttled 30/min |
| POST | `/conversations/{id}/read` | throttled 30/min |

</details>

<details>
<summary><strong>Messages, attachments, reactions</strong></summary>

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/conversations/{id}/messages` | paginated |
| POST | `/messages` | throttled 30/min |
| GET | `/messages/search` | throttled 30/min |
| PATCH | `/messages/{id}` | throttled 30/min |
| DELETE | `/messages/{id}` | redacts, doesn't hard-delete; throttled 30/min |
| POST | `/attachments` | throttled 30/min |
| GET | `/attachments/{id}` | resolves a signed URL |
| POST | `/messages/{id}/reactions` | throttled 60/min |
| DELETE | `/messages/{id}/reactions/{reaction}` | throttled 60/min |

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
| POST | `/presence/heartbeat` | throttled 20/min |
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
configured and is still used directly by `PresenceService` for presence/
typing (deliberately, not through Laravel's cache abstraction), so that one
feature — not the whole app — is what actually depends on Upstash being up.
See `.env.production.example`'s comment above `SESSION_DRIVER` for the
full reasoning.

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

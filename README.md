# brynforum/api-cache

[![Latest Version on Packagist](https://img.shields.io/packagist/v/brynforum/api-cache.svg)](https://packagist.org/packages/brynforum/api-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/brynforum/api-cache.svg)](https://packagist.org/packages/brynforum/api-cache)
[![License](https://img.shields.io/packagist/l/brynforum/api-cache.svg)](LICENSE)

A [Flarum](https://flarum.org) extension that adds **server-side response caching** to the JSON:API. Configure regex-pattern rules with per-rule TTLs to short-circuit expensive endpoints — top-poster widgets, statistics aggregations, public discussion lists — before they touch the database.

Built and used in production by [BrynForum](https://brynforum.com).

## Why

Flarum re-aggregates SQL on every request to certain API endpoints. The canonical example is `/api/users?filter[top_poster]=true` — the popular [afrux/top-posters-widget](https://github.com/afrux/top-posters-widget) hits it on every page load, and the answer changes once an hour, not once a request.

Edge-caching the response at Cloudflare is a workaround at the wrong layer (and on free/pro plans, Set-Cookie blocks caching anyway). The right fix is caching inside Flarum, where the rules can be tuned per-tenant in admin and the cache key can include exactly what matters.

## Installation

```bash
composer require brynforum/api-cache
```

Then enable **API Cache** under Admin → Extensions.

The extension creates a `brynforum_api_cache_rules` table on first enable.

## Optional: Redis backend

The extension uses Flarum's default file cache out of the box. To use Redis (recommended for busy forums), set these environment variables on the container:

```
REDIS_HOST=your-redis-host
REDIS_PORT=6379          # default
REDIS_PASSWORD=secret    # optional
REDIS_DB=0               # default
```

The extension auto-detects the env vars, pings the host, and uses Redis if reachable — otherwise transparently falls back to the file driver. Check the active backend via the `X-BrynForum-Cache-Backend: redis|file` response header.

PHP-FPM defaults to `clear_env = yes` on most distros. Set `clear_env = no` in your `php-fpm.conf` so PHP can read the env vars at runtime.

## How it works

A PSR-15 middleware sits on Flarum's API stack. For each `GET` request:

1. Walks active rules in priority order; checks the path against `path_pattern` (PCRE regex) and the querystring against `query_filter` (optional regex). First match wins.
2. Computes a stable cache key: `sha1(method + path + sorted(query) + scope)`.
3. On **HIT**: returns the cached `200` response (with original headers minus `Set-Cookie`). No handler invocation, no DB queries.
4. On **MISS**: runs the handler. If the response is `200`, caches it with the rule's TTL.

## Configuration

Admin → API Cache. Add rules with:

| Field | Type | Description |
|---|---|---|
| **Name** | text | Your label. |
| **Path pattern** | PCRE regex | Required. Includes delimiters, e.g. `#^/api/users$#`. |
| **Query filter** | PCRE regex | Optional. Matched against the raw querystring, e.g. `#filter\[top_poster\]=true#`. Empty = match any querystring. |
| **TTL (seconds)** | int | How long to keep the cached response (1 – 604800). |
| **Scope** | `public` \| `guest` | See below. |
| **Priority** | int | Higher = checked first. Tiebreaker when multiple rules match. |
| **Enabled** | bool | Toggle without deleting. |
| **Allow authenticated requests to seed cache** | bool, default on | When off, only responses generated for logged-out visitors are written into the cache. Everyone can still read the cached payload. Use on endpoints whose response varies subtly between guests and admins — e.g. `/api/users` includes `email` when the requester is an admin. See "When NOT to cache". |

There's a **Clear all cache** button to invalidate everything in one click.

### Scopes

- **public** — cache shared across all visitors. Use for endpoints whose response doesn't depend on identity (top-poster lists, public stats, discussion index when no per-user filter applies).
- **guest** — cache used only when the request has no session. Authenticated users bypass the cache entirely, so their identity-specific responses never land in a shared bucket.

## Verifying it works

Every matched response goes out with two debug headers:

- `X-BrynForum-Cache` — one of:
  - `HIT` — served from cache, handler never ran.
  - `MISS` — handler ran, response stored.
  - `SKIP-status` — handler ran, response not stored (non-200).
  - `SKIP-private` — handler ran, response not stored (handler returned `Cache-Control: private` or `no-store`).
  - `SKIP-authseed` — handler ran for an authenticated request, response not stored because the rule disallows authenticated seeding (other auth users get fresh handler responses; only guest requests warm this cache).
- `X-BrynForum-Cache-Backend: redis | file` — which backend is active.

Example:

```bash
$ curl -sI 'https://forum.example.com/api/discussions' | grep -i x-bryn
x-brynforum-cache: MISS
x-brynforum-cache-backend: redis

$ curl -sI 'https://forum.example.com/api/discussions' | grep -i x-bryn
x-brynforum-cache: HIT
x-brynforum-cache-backend: redis
```

## Safety properties

The extension enforces these regardless of how rules are configured:

- Only `200 OK` responses are cached. Errors, 4xx and 5xx pass through untouched.
- Only `GET` requests are eligible. Non-GET passes through.
- `Set-Cookie` is stripped from cached responses (avoids session leakage).
- The cache key does **not** include `Authorization` headers or session cookies — only `path + querystring + scope`. This is intentional: `public` scope means "same for everyone"; `guest` scope means "only used when unauthenticated".
- Rule list itself is cached for 60 s and explicitly invalidated when rules change, so admin edits take effect quickly.
- **`Cache-Control: private` and `Cache-Control: no-store` are honoured.** If the route handler explicitly marks a response per-user (Flarum or any extension), it's never cached even if the rule says `scope=public`. The response goes out with `X-BrynForum-Cache: SKIP-private`.
- **The rule validator refuses `scope=public` for known per-user paths** — patterns matching `/api/users/<id>`, `/api/users/me`, `/api/notifications`, `/api/preferences`, `/api/access_tokens`. Operator gets a clear error pointing them at `scope=guest`.
- **Per-rule "guest-only seed" mode.** Each rule has an *Allow authenticated requests to seed cache* flag. When off, authenticated requests bypass the *write* path (their response is returned but not stored); everyone still reads from cache when it's warm. Closes the "admin's response, with `email` included, lands in a shared bucket guests subsequently read" hole on endpoints like `/api/users?filter[top_poster]=true`. Surfaces as `X-BrynForum-Cache: SKIP-authseed` when an auth request would have seeded but didn't.

## When NOT to cache

Some endpoints respond differently to different users even at the same URL. Caching them at `scope=public` will leak one user's data to another. **Use `scope=guest` (or don't cache at all)** for:

| Endpoint pattern | Why it's per-user |
|---|---|
| `/api/users/<id>` | Profile incl. email/preferences may be visible to the owner only. |
| `/api/users/me` | Always the current user. |
| `/api/notifications`, `/api/notifications/<id>` | Per-user notification feed. |
| `/api/preferences` | Per-user prefs. |
| `/api/access_tokens` | The user's API tokens. |
| `/api/discussions` *(sometimes)* | The list response is mostly shared, but if a logged-in user is making the request, fields like `lastReadPostId` and `subscription` are mixed in. **Safe at `scope=guest`, not at `scope=public`.** |
| `/api/posts/<id>/edits` | Edit history may include drafts. |

**Safe at `scope=public`:**

| Endpoint pattern | Why it's safe |
|---|---|
| `/api/users?filter[top_poster]=true` | List, no per-user state. |
| `/api/tags` | Public taxonomy. |
| `/api/statistics` | Site-wide aggregates. |
| Custom public endpoints (`/api/brynforum/top-posters` etc.) | If the extension's response doesn't vary by viewer. |

If you're unsure, set the rule to `scope=guest` first and verify the response shape is identical to what a logged-out visitor sees. Promote to `scope=public` only once you're certain there's no per-user variation.

## Example rules

| Use case | Path pattern | Query filter | TTL | Scope |
|---|---|---|---|---|
| Top-poster widget | `#^/api/users$#` | `#filter\[top_poster\]=true#` | 600 | public |
| Forum index (logged-out) | `#^/api/discussions$#` | — | 60 | guest |
| Tag list | `#^/api/tags$#` | — | 3600 | public |
| Site statistics endpoint | `#^/api/statistics$#` | — | 300 | public |

## Contributing

Issues and PRs welcome. The extension is intentionally small and focused; before opening a large PR, please file an issue to discuss.

## License

[MIT](LICENSE) © BrynForum

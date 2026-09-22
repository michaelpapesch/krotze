# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Krotze (krotze.com) — an anonymous, self-destructing group-chat PWA. No passwords or e-mail: an identity is a globally unique username plus per-device HMAC secrets. Laravel skeleton + SQLite backend, single-file vanilla-JS SPA frontend, Tailwind 4, Vite. No composer packages beyond the skeleton, by design (Web Push, TOTP, crypto are hand-rolled in `app/Support/`).

## Commands

```sh
composer dev          # run everything: serve + queue + pail logs + vite (http://localhost:8000)
php artisan test      # run tests (composer test also clears config first)
php artisan test --filter=SomeTest   # single test class/method
npm run build         # rebuild frontend assets — REQUIRED before committing any frontend change
vendor/bin/pint       # code style (Laravel Pint)
php artisan migrate --seed           # fresh setup (seeds admin@krotze.com)
```

Rules that are easy to get wrong:

- **`public/build` is committed on purpose** (the host has no Node.js; deploy is a plain `git pull`). After any change under `resources/js` or `resources/css`, run `npm run build` and commit the build output too.
- **Never run `php artisan storage:link`.** Uploads are served *only* through capability-token routes (`GET /u/{token}`); a `public/storage` symlink would bypass that access control.
- **Bump `'version'` in `config/app.php`** (semver) with every release-worthy change; clients compare it against `/api/state` and show a "Reload site" notice. Beware: writing this file with PowerShell `Set-Content -Encoding utf8` adds a UTF-8 BOM that PHP then emits before every response, corrupting all API JSON — write it BOM-free.
- **The OpenAPI spec cannot drift**: `tests/Feature/OpenApiSpecTest.php` compares `resources/api/openapi.php` against the real route table in both directions. Adding/removing a route under `api/`, `u/`, `join/`, `register/`, or `embed/` requires a matching spec edit. The narrative guide is `docs/API.md` — keep it in step too.

## Architecture

### Identity & request signing (v2)

There are no bearer tokens. `POST /api/session` mints a `ChatUser` + `ChatDevice` and returns the device secret exactly once. Every authenticated call is signed per request: headers `X-Chat-Device` / `X-Chat-Ts` / `X-Chat-Nonce` / `X-Chat-Sig`, where the signature is HMAC-SHA256 over `METHOD\nREQUEST_URI\nTS\nNONCE\nsha256(body)` (multipart bodies sign the hash of the empty string). `ChatAuth` middleware verifies, burns each nonce once, and sets request attributes `chatUser` and `chatDevice`. Client-side signing lives in `resources/js/crypto.js` (dependency-free SHA-256/HMAC, works on non-secure origins). The test-side mirror is the `SignsChatRequests` trait.

Registration can be gated (`Setting::registrationsOpen()`, admin invite token, optional manual approval → `ChatUser::PENDING` identities may only poll state and pick a username). Usernames are global and unique (case-insensitive); most actions require one. Multi-device: pairing codes, encrypted identity export/import, token swap.

**E2EE (private 1:1 text only)**: each device registers an X25519 public key (`chat_devices.public_key`, `POST /api/profile/devices/key`; private half stays in localStorage `krotze_boxkey`). `resources/js/e2ee.js` (tweetnacl) seals the text with a random secretbox key wrapped per participant device via an ephemeral box; the message is posted with `encrypted: true` and the server stores the JSON envelope (format documented in the OpenAPI spec and docs/API.md). `Message::preview()` and reply excerpts return null text for encrypted messages — clients render a lock. Devices without a wrap (paired later) correctly cannot decrypt. Group channels are NOT E2EE (at-rest encryption only), and the honest limit — the server ships the JS — is stated in the FAQ/about pages; don't claim more than that.

### Multiple servers (v2.15)

One client can hold identities on several Krotze servers at once. The SPA keeps a server list in localStorage `krotze_servers` (see `loadServers()` in `app.js`): the origin the page was loaded from is the **home** server (owns the service worker, the push subscription, the "Reload site" check; cannot be removed), every other entry is a **foreign** server with its own device secret and username. `api(path, { server })` targets a server; the signature is host-free (`RequestSignature` signs path+query only) and `config/cors.php` opens `/api/*` to every origin, so the same signed call works cross-origin. Channels and notifications from every server are merged into `store.channels` / `store.notifications`; each entry carries `.server` and a composite `.key` (`serverId:id`) — never key anything by a bare uuid or integer id. `store.current` is `{ server, uuid, key }`; `store.server` is the active server (the open channel's, else home) and the `store.identity/userId/username/…` getters read through it. Servers are told apart by a local nickname (hostname by default), a computed short tag (`assignSuffixes()`, prefix of base32(sha256(origin)), extended until unique) and a palette colour (`assignColors()`); the tag chip is rendered by `serverChipHtml()` only once there are two or more servers. The list sorts unread first, then pinned.

**Push across servers** works through a relay, because a browser has one push subscription bound to the home server's VAPID key: the client asks home for a relay token (`POST /api/push/relay {origin}`) and registers `{relay_url, relay_token}` as its subscription on the foreign server; that server delivers by POSTing bulk items to `POST /api/push/relay/deliver` on home (unsigned, per-token and per-IP item limits, deferred delivery), which forwards through the real subscription with an added `origin` field (`sw.js` opens `#c=<uuid>&s=<origin>`). `RelayUrl` refuses non-https/private targets unless `PUSH_RELAY_ALLOW_INSECURE` / `PUSH_RELAY_ALLOW_PRIVATE` are set (local two-instance testing only). Every install plays both roles; a server without VAPID keys still relays. Details in `docs/API.md` §8 and `tests/Feature/PushRelayTest.php`.

### API & polling

All routes live in `routes/web.php` (no `routes/api.php`); `/api/*` is CSRF-exempt via `bootstrap/app.php`. No websockets: clients poll `GET /api/state` (~4 s; channels, unread counts, newest-message previews, notifications, `members_hash` roster fingerprint) and `GET /api/channels/{uuid}/messages?after=<id>`. Anything reaching another user goes through `ChatNotification` rows or the next poll; Web Push (`app/Support/WebPush.php`, hand-rolled VAPID) covers closed clients.

### Join modes & the embeddable widget

Channels have `join_mode`: `approval` (owner approves each request) or `open` (join is auto-approved instantly). Open channels power `/embed/{invite_token}` — a text-only iframe widget (`resources/js/embed.js` + `embed.blade.php`), the only route served with `Content-Security-Policy: frame-ancestors *`. Abuse rails, all in controllers via the `RateLimiter` facade: open joins 6/min per IP (`MemberController::joinApply`), open-channel posts 20/min per member with the owner exempt (`MessageController::store`), and switching a channel to open turns its `allow_*` upload flags off unless the request sets them explicitly (`ChannelController`). The embed only works for fresh visitors when registrations are open; the widget explains itself otherwise.

### Ephemeral uploads (security-sensitive)

Files live in `storage/app/public/uploads/<channel-uuid>/` but are reachable only via `GET /u/{token}` in `routes/web.php`. Each member gets a personal `UploadView` token per file; upload lifetime and the per-member view window are admin-configurable (`Setting::uploadRetentionDays()` / `uploadViewMinutes()`); the first full-file access starts the window, after which the token is nulled. The `/thumb` variant never starts the window. Preserve these invariants.

### Retention

`app/Support/Retention.php` prunes inactive channels (per-channel `retention_days`), expired uploads, and abandoned identities. It runs hourly from the scheduler; hosts without cron are covered by a lazy fallback sweep in `SessionController::state()` that stands down when the scheduler heartbeat (`app/Support/Scheduler.php`) is fresh. Don't remove the fallback — shared hosts often have no cron.

### Localized public site

The static pages exist in seven languages driven by `site_locales` in `config/app.php` (en at the bare URLs and x-default; de/es/fr/zh/ar/fa under `/<code>/…`; ar and fa are RTL via `dir` in that registry). One set of blades renders them all via `__('site.…')` with copy in `lang/<code>/site.php` — every locale file must mirror `lang/en/site.php`'s key tree exactly (`LocalizedPagesTest` enforces it). Routes are declared in a locale loop in `routes/web.php` (non-English route names get a `.<code>` suffix). `layouts/site.blade.php` computes the counterpart URLs for hreflang/canonical and the language dropdown (a `<details>` element, no JS); pages push JSON-LD via `@push('jsonld')` (built in `@php` blocks — Blade's `@json()`/`@php()` directive arguments break on multi-line arrays, use `@php … @endphp`). `/sitemap.xml` (with `lastmod` from file mtimes) and `/llms.txt` are generated in routes from `app/Support/SitePages.php`, the page registry that also feeds canonical/hreflang in the layout — add a page there, not in three places. Every absolute URL in canonical, hreflang, og:* and the sitemap is built from `APP_URL`, never from the request host. Each page has its own `description` key in the lang files. The site layout marks anything that is not one of the registry pages (the admin area) `noindex` without hreflang; the app shell (`app.blade.php`) is `noindex` too. `public/.htaccess` sets HSTS (the www/http → https 301 belongs in the server configuration); `public/robots.txt` disallows `/admin`, `/api/` (except the docs) and `/u/`. `laravel-lang/common` is a dev-only dependency used to (re)publish framework strings (`php artisan lang:add <code>`; zh was published as zh_CN and renamed); the published files are committed, so the host never needs it.

### Frontend

`resources/js/app.js` (~3000 lines) is the whole SPA: a global `store`, `render*()` functions, and a signing `api()` helper. No framework — keep new UI in the same imperative style. Other entries: `embed.js` (iframe widget), `landing.js`, `api-docs.js` (browsable OpenAPI reference at `/api/docs`). Message bodies and file names are encrypted at rest via Eloquent `encrypted` casts; clients see plaintext.

The SPA and embed are translated client-side: `resources/js/i18n.js` exports a gettext-style `t(text, params)` where the **English string is the key** (`:name`-style placeholders), with dictionaries in `i18n.js` (de/es) and `i18n-dicts2.js` (fr/zh/ar/fa); a missing entry falls back to English silently. Wrap every new user-visible string and `title` tooltip in `t()` and add it to all six dictionaries — `scratchpad`-style check: extract `t('…')` keys and diff against the dicts. Language comes from localStorage (`krotze_lang`, sidebar-footer selector, reloads on change) or the browser. The app layout stays LTR even for ar/fa (only the static site flips `dir`); bidi text inside messages renders naturally.

## Deployment

See `DEPLOYMENT.md`. Short version: build + commit + push, then `git pull` on the host (no Node.js needed there, the build output is committed). Run `php artisan migrate --force` on the host when migrations changed.

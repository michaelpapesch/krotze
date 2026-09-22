# Krotze — Anonymous Chat PWA

Anonymous, private, self-destructing group chat. No registration, no login for
visitors — identity is a self-chosen username plus a device key held in the
browser's localStorage. Installable as a PWA. Built with Laravel 13,
Tailwind CSS 4 and vanilla JS.

Live: https://krotze.com

## Features

- **Anonymous identities** — a globally unique username you pick yourself, with
  no e-mail, phone or password. The credential is a per-device key that is
  never transmitted (see *Signed requests* below).
- **Profile** — username, registered devices, identity export/import, "Swap my
  token" (rolls every credential, keeps username, channels and messages) and
  "Delete all my data" (wipes everything server-side).
- **Multi-device** — register another device with a one-time pairing code
  (shown as QR + 8 characters), see all registered devices and revoke any of
  them. Each device gets its own key; revoking one signs it out immediately.
  The device you are on is highlighted in the list, and may sign *itself* out —
  with a warning first, since with no other device registered an exported
  identity file is the only way back to the username and its channels.
- **Identity export/import** — export an identity token encrypted with a
  passphrase you choose (encrypted in the browser, before it is ever shown or
  saved), and import it elsewhere to continue as that user. Revocable.
- **Channels** — any visitor can create a channel ("+ Create channel") and
  becomes its owner. Channels are private; messages are relayed only to
  approved members.
- **QR invites** — every channel has an invite QR code. The owner can show it
  in-app or download it as a PNG (channel name printed above the code).
  The app has a "Scan QR code" nav item (camera + jsQR) to apply for
  membership; you join under your username, and the owner gets an
  in-app popup to approve or deny each request. Member lists and the member
  count update live for everyone (roster fingerprint carried in `/api/state`).
- **Seven languages, SEO-ready** — the public site (landing, about, FAQ,
  contact, imprint, privacy, terms) is fully translated into English,
  German, Spanish, French, Chinese, Arabic and Persian (the last two RTL,
  `dir="rtl"`). English lives at the bare URLs, the rest under `/<code>/…`,
  switchable via the language dropdown in the top-right nav. The language
  list is `site_locales` in `config/app.php`; strings live in
  `lang/<code>/site.php` (a test enforces key parity with English), and
  framework strings come from `laravel-lang/common` (dev dependency,
  published files committed). Every page carries hreflang alternates +
  canonical, localized Open Graph tags and JSON-LD structured data
  (WebApplication + Organization on the landing, FAQPage on the FAQ,
  BreadcrumbList on subpages), and `/sitemap.xml` lists all languages with
  cross-linked alternates. The chat app itself (and the embed widget) is
  translated too: a gettext-style `t()` helper in `resources/js/i18n.js`
  (English text as key, dictionaries per language, silent English fallback)
  covers every string and tooltip, with a language selector in the sidebar
  footer that defaults to the browser language.
- **Open join & website embedding** — a channel can switch to "open join"
  (creation dialog or channel settings): anyone with the invite link joins
  instantly, no approval. Open channels get an embed snippet
  (`<iframe src="/embed/{invite_token}">`, channel menu → "Embed on a
  website") so the chat can live on any third-party site — a text-only
  widget (`resources/js/embed.js`) where visitors register an identity,
  pick a username and chat immediately (requires open registrations).
  Safety rails: uploads default to off when a channel goes open (owner can
  re-enable), joining is throttled per IP and posting per member (owner
  exempt). `/embed/{token}` is the only route served with
  `Content-Security-Policy: frame-ancestors *`.
- **Sidebar navigation** — lists all of your channels with unread badges.
  Order: pinned channels first, then channels with unread messages, then the
  rest. The content area re-opens the last opened chat on launch.
- **Ownership transfer** — the owner can hand a channel to another member
  (with confirmation dialog); they lose all owner rights.
- **Private 1:1 conversations** — click a member's name in the unfoldable
  member list to request a private chat; the other side must accept. Both
  participants have a destroy button that deletes the conversation for both.
  Their text messages are **end-to-end encrypted**: each device holds an
  X25519 keypair (`resources/js/e2ee.js`, tweetnacl), messages are sealed
  with a per-message key wrapped for every participant device, and the
  server stores only the envelope. Devices paired later cannot read earlier
  messages (by design); previews and push bodies show a lock instead of
  text. Group channels remain encrypted at rest only.
- **Retention** — every channel auto-deletes all content (messages, users,
  media, meta information) after N days of inactivity (default 7, max 365,
  owner-configurable). Uploads and abandoned identities expire too: an identity
  with no username, no channel and nothing seen for a day is deleted, along with
  its devices — including a pending registration that never chose a name, since
  that gives an admin nothing to decide about. Denied identities are kept, so
  the denial sticks. All of it lives in `app/Support/Retention.php`, and runs
  hourly from the scheduler. Hosts without cron are covered by a fallback sweep
  paid for by API traffic (also at most hourly), which stands down whenever the
  scheduler's heartbeat is fresh — the admin dashboard reports which of the two
  is in charge.
- **Destroy** — the owner can destroy a channel (confirmation dialog); all
  messages/files/members are removed from database and disk. Owners removing
  a member get an optional "delete all files of this user in this channel?"
  checkbox.
- **Media** — upload images (thumbnail + lightbox), audio (mini player),
  video (mini player) and zip archives (download link). Every file has an
  "×" — any member may delete it; the deleter's username is shown in place of
  the file.
- **Notifications** — new messages, private-chat requests, join requests,
  approvals, etc. pop up in the app and as system notifications. With **Web
  Push** configured they arrive even when the app is closed (see below);
  without it they are raised by the poll loop while the app runs. Tapping a
  message notification opens that chat. Each kind can be switched off per
  device under ⚙️ in the sidebar, message text can be kept off the lock screen,
  and individual chats can be muted (unread badge turns grey but still counts).
  A master **“Notify me on this device”** switch silences one browser without
  signing it out. Note that a notification can only ever open the browser whose
  service worker created it — one enabled in a tab opens that browser, never the
  installed app — so the settings dialog says as much when it is opened from a
  tab on a phone.
- **Web Push** — hand-rolled VAPID (RFC 8292) and aes128gcm payload encryption
  (RFC 8291), no composer package. Payloads are encrypted for the receiving
  device, so Google/Mozilla relay ciphertext they cannot read. Generate a
  keypair with `php artisan push:vapid`; without one push is simply off.
  Subscriptions are bound to a device, so revoking the device revokes them.
- **Documented API** — an OpenAPI 3.1 spec at `/api/openapi.json` and a
  browsable reference at [`/api/docs`](https://krotze.com/api/docs), covering
  every endpoint, the upload capability URLs and the deep links. The narrative
  guide — request signing with a worked example, the poll contract, uploads,
  Web Push — is in [docs/API.md](docs/API.md), with a runnable reference client
  in [docs/examples/](docs/examples/krotze_client.py). A test compares the spec
  against the route table in both directions, so the two cannot drift.
- **Chat settings** — long-press (or right-click, or ⋯) a chat in the sidebar
  for one modal holding everything about it: pin, mute, hide, invite QR,
  owner settings, delete all uploads, leave, destroy.
- **PWA** — manifest + service worker, installable from the landing page
  (QR code provided), offline shell caching, app badge with unread count.
- **Admin panel** — `/admin` (session login) with stats, server settings and
  the ability to delete any channel. Seeded admin: `admin@krotze.com`
  (password from `ADMIN_PASSWORD` env at seed time). The **first sign-in forces
  a password change** — the seeded one is public knowledge — and the rest of the
  panel stays locked until it is done. A **profile** page changes the password
  and sign-in address, and turns on **two-factor authentication**: TOTP
  (RFC 6238, hand-rolled in `app/Support/Totp.php`) with a QR code for any
  authenticator app and eight single-use recovery codes. The secret and codes
  are encrypted at rest, the challenge is rate limited, and changing the
  password signs out every other session. A **“Forgot password?”** link resets
  by e-mail (the only mail this app sends); the reset neither signs you in nor
  bypasses two-factor. Settings:
  - *Open registrations* — on, anybody who reaches the site can register.
    Off, the server is invite-only: only the registration link shown in the
    dashboard (`/register/<token>`) can mint an identity, and *Recreate invite
    link* invalidates every link handed out so far.
  - *Manually approve every registration* (invite-only servers) — new
    identities land in a searchable, paginated pending list with check-all and
    bulk approve/deny. Until they are approved they may only pick a username
    and poll their own status; approval reaches their client within one poll.
  - *Automatically delete uploads after N days* and *clients can view uploads
    only for N minutes* (max 60) — both switchable; off means the limit does
    not apply at all.
- **Pages** — landing (header image, intro, install QR), imprint, contact,
  FAQ, privacy.

## Stack / architecture

- **Backend**: Laravel 13, SQLite, no extra composer packages.
  Chat API under `/api/*` (CSRF-exempt). Clients poll `/api/state` (~4 s) for
  the channel list, unread counts, roster fingerprints and notifications, and
  `/api/channels/{uuid}/messages?after=<id>` for new messages.
- **Signed requests** — there is no bearer token. Each device holds a secret
  (`chat_devices.secret`, encrypted at rest) and signs every call:
  `X-Chat-Device`, `X-Chat-Ts`, `X-Chat-Nonce` and an `X-Chat-Sig` of
  HMAC-SHA256 over `METHOD\nPATH\nTS\nNONCE\nSHA256(body)` (multipart bodies
  sign as empty, since PHP consumes them before they can be hashed). The
  server re-derives the signature in `ChatAuth`, rejects clocks off by more
  than 5 minutes and burns each nonce once, so recorded traffic can neither be
  replayed nor turned into a stolen identity. See `app/Support/RequestSignature.php`
  and `resources/js/crypto.js` (dependency-free SHA-256/HMAC/PBKDF2, because
  `crypto.subtle` does not exist on non-secure origins).
- **Frontend**: single-page app in `resources/js/app.js` (vanilla JS),
  Tailwind 4, `qrcode` (QR generation) and `jsqr` (camera QR scanning)
  bundled by Vite. Built assets in `public/build` are **committed** so
  deployment is a plain `git pull`.
- **Message encryption at rest** — `messages.body` and `messages.file_name`
  are stored as ciphertext (Laravel's `encrypted` cast, AES-256-CBC under
  `APP_KEY`), so a database dump or a stray backup reads as noise rather than
  as conversation. Neither column is ever searched or sorted on, so nothing is
  given up for it. **Losing `APP_KEY` means losing every message** — back it up
  with the database, and never rotate it without re-encrypting first.
- **Server settings** — the `settings` table holds the admin's switches
  (registrations, approval, upload lifetimes, push). `App\Models\Setting` caches
  the whole table under one cache key and drops it on write, so queue workers
  and the scheduler see changes too.
- **Web Push internals** — `app/Support/WebPush.php` does VAPID ES256 tokens
  and RFC 8291 encryption with nothing but PHP's `openssl` and `hash`
  extensions; `app/Support/PushSender.php` turns app events into notifications
  and is hooked into `ChatNotification::send()` (all ten notification types)
  and `MessageController::store()`. Sends run in `app()->terminating()`, i.e.
  after the response, because this host cannot keep a queue worker alive.
  **Key generation needs an `openssl.cnf`** and many shared hosts have none, so
  one is shipped at `resources/openssl.cnf` and passed explicitly.
- **Storage**: uploads on the `public` disk (`storage/app/public/uploads/…`,
  served via the `public/storage` symlink).

## Local development

```sh
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan storage:link
npm install
composer dev        # serves app + vite + queue + logs
```

Open http://localhost:8000. Admin panel: http://localhost:8000/admin.

## Tests / build

```sh
npm run build       # rebuild assets (commit public/build afterwards!)
php artisan test
```

## Deployment

See [DEPLOYMENT.md](DEPLOYMENT.md). Short version: push, then `git pull` on
the host (assets are pre-built and committed, no Node.js needed there).

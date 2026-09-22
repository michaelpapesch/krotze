# Building a Krotze client

The API behind [krotze.com](https://krotze.com) is the same one the web app
uses. Everything the official client can do — register, join channels, send
messages and files, receive notifications while closed — a custom client can do.

- **Interactive reference:** <https://krotze.com/api/docs>
- **Machine-readable spec:** <https://krotze.com/api/openapi.json> (OpenAPI 3.1)
- **Reference client:** [`examples/krotze_client.py`](examples/krotze_client.py)

This document covers the parts a spec cannot express on its own.

---

## 1. There is no login

No password, no e-mail, no bearer token. An identity is a username you choose
plus a **device secret** held by each device allowed to act as you.

A device receives its secret exactly once and never transmits it again. Three
ways to get one:

| Situation | Endpoint |
| --- | --- |
| First run — create a new identity | `POST /api/session` |
| Add a second device to an identity | `POST /api/devices/claim` (8-character pairing code) |
| Move an identity to a new device | `POST /api/devices/import` (exported token) |

All three return the same shape:

```json
{
  "device_id": "yYq3…",
  "secret":    "0f1e2d…",
  "user_id":   42,
  "username":  null,
  "status":    "approved"
}
```

Store `device_id` and `secret`. Losing the secret means losing the identity
unless you exported one first (§7).

> **Servers can be invite-only.** If `POST /api/session` answers `403` with
> `reason: "registration_closed"`, this server only accepts registrations that
> present an invite token — see §6.

### Choose a username first

Almost everything else needs one. It is globally unique, compared
case-insensitively:

```
POST /api/profile/username   {"username": "nightowl"}
```

---

## 2. Signing a request

Every authenticated call proves it holds the device secret **without sending
it**. Four headers:

| Header | Value |
| --- | --- |
| `X-Chat-Device` | your `device_id` |
| `X-Chat-Ts` | current Unix time, in seconds |
| `X-Chat-Nonce` | fresh random hex, 16–64 characters |
| `X-Chat-Sig` | the signature, lower-case hex |

The signature is `HMAC-SHA256(secret, canonical)` where `canonical` is five
lines joined with `\n`:

```
METHOD
REQUEST_URI
TIMESTAMP
NONCE
SHA256_HEX(body)
```

### Worked example

Signing `GET /api/state` with

- secret `4f8a2c1e9b7d3a5f6e0c8b2d4a6f1e3c5b7d9a0f2e4c6b8d1a3f5e7c9b0d2a4f`
- timestamp `1769000000`
- nonce `a3f19c04e7b25d8a6f0c1b3e5d7a9f21`

the canonical string is

```
GET
/api/state
1769000000
a3f19c04e7b25d8a6f0c1b3e5d7a9f21
e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
```

`e3b0c442…` is SHA-256 of the empty string, because a `GET` has no body. In
Python:

```python
import hashlib, hmac, os, time

secret = "4f8a2c1e9b7d3a5f6e0c8b2d4a6f1e3c5b7d9a0f2e4c6b8d1a3f5e7c9b0d2a4f"
ts     = str(int(time.time()))
nonce  = os.urandom(16).hex()
body   = b""                                   # or the exact bytes you send

canonical = "\n".join(
    ["GET", "/api/state", ts, nonce, hashlib.sha256(body).hexdigest()]
).encode()

sig = hmac.new(secret.encode(), canonical, hashlib.sha256).hexdigest()
```

### Three things that trip people up

1. **`REQUEST_URI` includes the `/api` prefix and the query string.**
   Sign `/api/channels/{uuid}/messages?after=42` — not the path alone, and not
   without `/api`.
2. **The secret is a literal ASCII string.** It looks like hex, but feed HMAC
   the 64 characters as they are. Do *not* `bytes.fromhex()` it.
3. **Multipart bodies sign as the hash of the empty string.** PHP consumes a
   multipart body before it can be hashed, so both ends apply the same rule.
   Only file uploads are affected — sign `SHA256("")` and send the multipart
   body as normal.

### When signing fails

`401` responses carry a `reason`:

| `reason` | Meaning | What to do |
| --- | --- | --- |
| `unsigned` | a header is missing | send all four |
| `stale` | your clock is more than 5 minutes off | take `server_time` from the body (or the `Date` header), keep the offset, sign again |
| `bad_nonce` | not hex, or not 16–64 characters | generate `os.urandom(16).hex()` |
| `device_unknown` | the device was revoked, or the account deleted | discard your credentials and register again |
| `bad_signature` | the canonical string does not match | check the three gotchas above |
| `replay` | that nonce was already used | never reuse a nonce |

Nonces are burned once and the clock window is five minutes, so a recorded
request can be neither replayed nor turned into a stolen identity.

### One client, several servers

The canonical string names no host, and every `/api/*` route answers any
origin (CORS, no cookies involved). So a client can register a separate
identity on each Krotze server it cares about and sign requests to all of them
with the same code — only the base URL and the credentials differ. The
reference app does this: it shows every server's channels in one list. The one
thing that does not simply work across origins is Web Push, which is what the
relay in §8 is for.

---

## 3. The poll loop

There is no socket and no long-poll. A client lives on two calls:

```
GET /api/state                                  every ~4 seconds
GET /api/channels/{uuid}/messages?after={id}    for the open channel
```

`/api/state` returns your channels with unread counts, pending notifications,
and a **preview of each channel's newest message** — enough to raise a
notification without fetching messages you are not displaying.

Fields worth handling properly:

- **`members_hash`** — changes the moment anyone joins, leaves or renames.
  Reload the roster when it moves instead of polling channel detail.
- **`version`** — the server's version. If it differs from the build you
  shipped, a newer client exists.
- **`account_status`** — `pending` or `denied` means an admin has not let this
  identity in (§6). While pending, only `/api/state`, `/api/profile` and
  `/api/profile/username` work.
- **`notifications`** — see below.

### Notifications

Ten types arrive through `/api/state`. Mark them read with
`POST /api/notifications/read` (omit `id` to clear all).

| Type | Meaning |
| --- | --- |
| `join_request` | somebody wants into a channel you own — `data.member_id` decides it |
| `join_approved` / `join_denied` | your own application was decided |
| `private_invite` | somebody wants a private conversation |
| `private_accepted` / `private_declined` | your request was answered |
| `ownership_received` | a channel was handed to you |
| `member_removed` | you were removed from a channel |
| `channel_destroyed` | a channel you were in is gone |
| `channel_moved` | **handle this or you lose the channel** — see below |

### Joining and join modes

`GET /api/join/{token}` (public) describes an invite before you commit:
`name`, `members`, and `join_mode`. Then `POST /api/join/{token}` (signed,
empty body) applies — you join under your username, so claim one first (§1).

- **`join_mode: "approval"`** (the default) — the answer is
  `status: "pending"` and the owner decides; watch for `join_approved` /
  `join_denied` notifications.
- **`join_mode: "open"`** — the answer is `status: "approved"` and you are in
  immediately. Open joins are throttled per IP (`429` — wait a minute), and
  **posting into an open channel is throttled per member** (§9). Open channels
  are what the embeddable widget at `/embed/{invite_token}` builds on — the
  one page served with `frame-ancestors *` so any site may iframe it.

Applying twice never fails: you get your current status back with a `200`.

### `channel_moved` is not optional

When an owner rotates a leaked invite, the channel gets a **new UUID**. Members
who are kept receive `channel_moved` with `data.old_uuid` and
`data.channel_uuid`. The old UUID stops resolving immediately.

A client must, on seeing it: swap any stored UUID for the new one, re-open the
channel if it was the one on screen, and mark the notification read. The
official client does this silently, without telling the user.

---

## 4. Sending messages

Text is JSON:

```
POST /api/channels/{uuid}/messages   {"body": "hello", "reply_to": 41}
```

Files are `multipart/form-data` with a `file` part — images, audio, video and
zip, up to 50 MB, subject to the channel's `allow_*` settings. Remember the
signing rule for multipart bodies (§2). For video you may attach your own
poster frame as `thumb`; it is re-encoded server-side before use.

Call `POST /api/channels/{uuid}/read` with the newest id you have displayed so
unread counts stay honest.

### End-to-end encrypted private messages

Text messages in **private 1:1 conversations** are end-to-end encrypted between
the participants' devices; the server stores an opaque envelope it cannot read.

- Each device generates an X25519 keypair locally and registers the public half
  once via `POST /api/profile/devices/key`. The private key never leaves the
  device.
- `GET /api/channels/{uuid}` on a private conversation returns `devices`: every
  participant device (both sides) with a registered key.
- To send: encrypt the text once with a random 32-byte key
  (XSalsa20-Poly1305 secretbox), wrap that key for **every** listed device with
  an ephemeral X25519 box, and post `{"encrypted": true, "body": envelope}`
  where the envelope is
  `{"v":1,"n":…,"ct":…,"eph":…,"keys":{"<device public id>":{"n":…,"k":…}}}`
  (all values base64). Wrap for your own other devices too, or they cannot
  show your sent messages.
- To read: take the wrap under your device's public id, open it with your
  secret key and the envelope's `eph`, then open the secretbox. No wrap for
  your device means it was added after the message was sent — show a lock, not
  an error.
- Encrypted messages carry `encrypted: true`; previews (`last_message`,
  reply excerpts, push bodies) have no text for them — render a placeholder.

Group channels are not end-to-end encrypted (they are encrypted at rest
server-side). And the standing caveat of any web client applies: the server
serves the JavaScript, so E2EE here protects stored messages against database
access and data requests, not against a malicious operator.

---

## 5. Uploads are capability URLs

Uploads are never served from a public path. Each member gets their own URL,
handed to them as `file_url` and `thumb_url` on a message. Holding the URL is
the entire authorisation — these requests are **not** signed.

Two independent clocks make them ephemeral:

- **The upload expires** after the operator's retention window
  (`upload_retention_days` in `/api/state`; `null` means uploads are kept until
  their channel goes). Then file, thumbnail and message are deleted for
  everyone.
- **Your personal window starts the first time you fetch the full file** and
  runs for `upload_view_minutes`. After that your token is destroyed and the
  URL 404s — for you alone. Other members are unaffected.

Fetching `/u/{token}/thumb` does **not** start that window. That is what makes
it safe to show previews inline; only fetching the full file counts as viewing.

Message fields `file_expires_at` and `view_expires_at` tell you where you
stand. `view_expires_at` is null until you first open the file.

---

## 6. Servers that are not open

An operator can close registrations. Then:

- `POST /api/session` without a valid `invite` answers `403` with
  `reason: "registration_closed"`. The invite token comes from a
  `/register/{token}` link an admin hands out; read it from the URL and pass it
  as `invite`.
- The operator may additionally require **manual approval**. Registration then
  succeeds with `status: "pending"`, and every endpoint except `/api/state`,
  `/api/profile` and `/api/profile/username` answers `403` with
  `reason: "registration_pending"` until an admin approves you.

Handle this by claiming a username (so the admin sees who is waiting) and
polling `/api/state` until `account_status` becomes `approved`. A refusal is
permanent only for `registration_denied`.

---

## 7. Exporting an identity

`POST /api/profile/transfer` mints a token that `POST /api/devices/import` can
redeem. The server stores only its SHA-256, so a database leak cannot reproduce
it. `DELETE /api/profile/transfer` revokes it.

The official client never shows that token raw — it encrypts it with a
passphrase first, producing a `KRZ1` blob. Implement the format if you want
files to move between your client and the web app:

```
KRZ1.<iterations>.<salt>.<nonce>.<ciphertext>.<tag>
```

Five dot-separated fields after the magic, each base64 (standard alphabet):

| Field | |
| --- | --- |
| `iterations` | PBKDF2-HMAC-SHA256 rounds, decimal (210 000 in secure contexts, 50 000 in the pure-JS fallback) |
| `salt` | 16 bytes |
| `nonce` | 16 bytes |
| `ciphertext` | same length as the plaintext |
| `tag` | 32 bytes |

Derivation and cipher:

1. `keys = PBKDF2-HMAC-SHA256(passphrase, salt, iterations, 64 bytes)`;
   `enc = keys[0:32]`, `mac = keys[32:64]`.
2. Keystream: `HMAC-SHA256(enc, nonce || uint32be(block))` for
   `block = 0, 1, 2, …`, concatenated and truncated. XOR with the plaintext.
3. `tag = HMAC-SHA256(mac, nonce || ciphertext)` — verify before decrypting.

See `resources/js/crypto.js` for the reference implementation.

---

## 8. Web Push

Notifications while your client is **closed** need Web Push (RFC 8291). The
poll loop covers everything else.

```
GET /api/push/key            → {"enabled": true, "public_key": "B…"}
POST /api/push/subscribe     ← endpoint + keys from the browser
PATCH /api/push/subscription ← change what you are notified about
DELETE /api/push/subscription
POST /api/push/test          → sends one notification to this device
```

In a browser:

```js
const { enabled, public_key: key } = await fetch('/api/push/key').then(r => r.json());
if (enabled) {
    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: base64UrlToBytes(key),
    });
    const { endpoint, keys } = sub.toJSON();
    await signedPost('/api/push/subscribe', { endpoint, keys });
}
```

Then handle `push` in your service worker. The payload is JSON:

```json
{ "title": "Lobby · alice", "body": "see you at eight", "tag": "chan-<uuid>", "uuid": "<uuid>" }
```

Notes that save time:

- **Push requires a secure context.** Over plain `http://` — a typical
  development host — `PushManager` does not exist at all. That is not a bug;
  fall back to raising notifications from your poll loop.
- **iOS only supports Web Push for installed PWAs**, 16.4 or newer. Safari on
  a page that has not been added to the home screen will not subscribe.
- **Subscriptions are bound to the calling device.** Revoking a device revokes
  its subscription. Each device keeps its own preferences.
- **Endpoints rotate.** Call `/api/push/subscribe` on every launch; it is
  idempotent and retires this device's previous endpoint.
- **Muted channels are never pushed**, and `hide_message_text` replaces the
  sender and text with "New message in …".
- Payloads are encrypted for your device, so the push service relays bytes it
  cannot read. It still learns that *something* was sent to you, and when.
- If push is unavailable (`enabled: false`), the operator has not configured a
  keypair or has switched it off. Poll instead.

### Relaying push for another server

A browser holds **one** push subscription per site, bound to the VAPID key of
the server that created it. A client with identities on several Krotze servers
therefore cannot subscribe to each of them — the second server's key would not
match. Instead, the server the client was installed from (its *home* server)
relays for the others:

```
POST home/api/push/relay        {"origin": "https://other.example"}    (signed as your home identity)
  → {"relay_url": "https://home.example/api/push/relay/deliver", "relay_token": "…48 chars…", "origin": "https://other.example"}

POST other/api/push/subscribe   {"relay_url": …, "relay_token": …, "notify_messages": true, …}   (signed as your identity there)
  → 201, exactly like a browser subscription
```

From then on, whenever the other server would push to that device, it POSTs to
the relay URL instead — one request per home server, carrying every item for
it:

```json
{"items": [
  {"token": "…", "title": "Lobby · alice", "body": "see you at eight", "tag": "chan-<uuid>", "uuid": "<uuid>"},
  {"token": "…", "title": "Krotze", "body": "New message in Lobby", "tag": "chan-<uuid>", "uuid": "<uuid>"}
]}
```

and the home server answers `202 {"results": {"<token>": "ok" | "gone" | "no_subscription" | "throttled"}}`,
then delivers each item through the device's real subscription with one field
added: `"origin": "https://other.example"`. Your service worker can use it to
open the right server's channel.

Rules that keep this safe, and that a client or server implementing it must
respect:

- **Treat `gone` like a 410** from a push service: the token was withdrawn
  (`DELETE home/api/push/relay`), drop the subscription. `no_subscription`
  means the device currently has no browser subscription on its home server;
  keep the row and try again next time.
- **Limits are per item**: 30 a minute per token, 600 a minute per calling
  address (and 120 requests). Delivery is deferred until after the response,
  so a flood costs the home server lookups, not open connections.
- **The relay URL is checked** before a server agrees to post to it: it must
  end in `/api/push/relay/deliver`, be https, and point at a public address —
  the alternative is a server that can be told to make requests anywhere. An
  operator running two instances on one machine can relax the last two with
  `PUSH_RELAY_ALLOW_INSECURE` / `PUSH_RELAY_ALLOW_PRIVATE`.
- The home server never sees a message's text unless the other server chose
  to send it; `hide_message_text` is honoured where the notification is
  composed. What the home server learns is that *something* happened on the
  other server, and when — the same thing the push service learns about it.
- Relaying needs no VAPID keypair on the relaying-*through* side: a server
  without one still delivers relay subscriptions (`relay: true` in
  `/api/push/key`), it just cannot create browser subscriptions of its own.

---

## 9. Rate limits and errors

| Endpoint | Limit |
| --- | --- |
| `POST /api/session` | 30 / minute |
| `POST /api/devices/claim`, `/import` | 10 / minute |
| `POST /api/push/test` | 10 / minute |
| `POST /api/push/relay/deliver` | 120 requests / minute per IP; 600 items per IP and 30 per token |
| `POST /api/join/{token}` into an **open** channel | 6 / minute per IP |
| `POST /api/channels/{uuid}/messages` in an **open** channel | 20 / minute per member (the owner is exempt) |

Everything else is unthrottled but signed, and every nonce is single-use. A
`429` carries a human-readable `error` — show it and back off.

Errors are JSON: `{"error": "…"}`, sometimes with a machine-readable `reason`.
Validation failures use Laravel's shape:

```json
{"message": "…", "errors": {"username": ["That username is already taken."]}}
```

`426` from `POST /api/session` means you did not send `device_name`; it marks a
client from before the signed-request scheme.

---

## 10. Be a good citizen

Krotze exists because its users want conversations that leave nothing behind.
A client that stores messages forever, uploads them elsewhere, or keeps files
past their view window defeats the point for everybody in the channel — not
just its own user. Retention is a promise the other members of a channel are
relying on.

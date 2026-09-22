# Deployment

Krotze is a plain Laravel application: PHP 8.2+, a database (SQLite or MySQL),
a web server pointing at `public/`. No Node.js is needed on the host because
the Vite build output in `public/build` is committed.

## Standard deploy

```sh
# 0. bump 'version' in config/app.php (semver: major.minor.patch).
#    Running clients poll /api/state, compare against their loaded version
#    and show a "Reload site" notice when it changed.

# 1. locally: build assets and commit everything (public/build is committed!)
npm run build
git add -A && git commit -m "..."
git push

# 2. on the host
git pull
php artisan migrate --force        # only when migrations changed
php artisan cache:clear
```

If `composer.json` changed, run `composer install --no-dev` on the host as
well. If `package.json` changed, run `npm install` locally before the build.

## First-time setup

- Copy `.env.example` to `.env` and set at least `APP_ENV=production`,
  `APP_DEBUG=false`, `APP_URL=https://<your host>`, `APP_NAME` and a strong
  `ADMIN_PASSWORD` **before** seeding. Generate `APP_KEY` with
  `php artisan key:generate`.
- `php artisan migrate --force` and `php artisan db:seed --force` (seeds the
  admin account; `ADMIN_EMAIL` overrides the default address).
- **Do NOT run `php artisan storage:link`** and make sure `public/storage`
  does not exist. Uploads are served exclusively through per-member
  ephemeral routes (`GET /u/{token}` in `routes/web.php`; file lifetime and
  per-member view window are set in the admin panel). A symlink would bypass
  that access control.
- PHP upload limits: the app allows uploads up to 50 MB, so
  `upload_max_filesize` and `post_max_size` should be at least 50M / 52M.
- Canonical-host redirects (www → bare host, http → https) belong in the
  web server configuration, not in the repository. `public/.htaccess` only
  sets HSTS once a request arrives over TLS.

> **Message text is encrypted at rest with `APP_KEY`.** Treat `APP_KEY` as
> part of every database backup: without it stored messages cannot be read
> back, and changing it makes every existing message undecryptable.

## Scheduler / retention

`channels:cleanup` prunes inactive channels, expired uploads and abandoned
identities. Run the Laravel scheduler from cron:

```cron
* * * * * cd /path/to/krotze && php artisan schedule:run >> /dev/null 2>&1
```

Hosts without cron still work: the app falls back to a sweep paid for by
whoever polls the API first (`SessionController::lazyCleanup()`, at most once
an hour). The fallback stands down while the scheduler heartbeat is fresh,
so the two never do the same work twice. The admin dashboard reports which
of the two is in charge; `php artisan cache:clear` wipes the heartbeat, so
give it a minute after a deploy before reading it.

## Web Push

Push is off until a VAPID keypair exists. Generate one **once**:

```sh
php artisan push:vapid          # prints the pair, or writes it if .env is writable
```

```dotenv
VAPID_PUBLIC_KEY=B…             # handed to every subscribing browser; not secret
VAPID_PRIVATE_KEY=…             # must never leave the server
VAPID_SUBJECT=mailto:you@example.com
```

Then switch it on under *Send push notifications* in `/admin`.

- **Replacing the keypair unsubscribes every device**, silently: their
  subscriptions were issued against the old key and the push services reject
  the new one. `push:vapid` refuses to overwrite without `--force`.
- The host needs outbound HTTPS to the push services (`fcm.googleapis.com`,
  `updates.push.services.mozilla.com`, `*.notify.windows.com`).
- **`resources/openssl.cnf` is load-bearing** on hosts that ship no
  `openssl.cnf` of their own: without it EC key generation fails and no
  notification can be encrypted. `WebPush` throws (and logs) rather than
  failing silently, but do not delete the file.
- Pushes are sent after the response inside the same PHP process, so no queue
  worker is required.
- Push needs a secure context: it does nothing over plain http, and iOS only
  supports it for PWAs added to the home screen (16.4+).
- Relaying push for clients that also use other Krotze servers (see
  `docs/API.md` §8) only accepts https targets on public addresses unless
  `PUSH_RELAY_ALLOW_INSECURE` / `PUSH_RELAY_ALLOW_PRIVATE` are set. Leave
  both off in production.

## Mail (admin password reset only)

The password-reset link is the only e-mail this application sends. Configure
SMTP in `.env` (`MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, credentials,
`MAIL_FROM_ADDRESS`). Notes that are easy to get wrong:

- `MAIL_SCHEME` is passed straight through to Symfony's DSN, which only
  accepts `smtp` or `smtps`. For STARTTLS on 587 use `smtp` (or leave it
  unset); use `smtps` on 465. `MAIL_SCHEME=tls` throws.
- `MAIL_HOST` must match the name on the server's certificate, or STARTTLS
  verification fails.
- Shared hosts often have no `sendmail` binary; `MAIL_MAILER=sendmail` then
  fails on every send.

## Admin panel

- URL: `/admin`, seeded with `ADMIN_PASSWORD` from `.env`.
- **The first sign-in forces a password change.** The seeder's fallback
  password is in the repository, so until it is replaced the panel refuses
  to do anything else and holds you on `/admin/profile`.
- The profile page also changes the sign-in e-mail and turns on **two-factor
  authentication** (TOTP). Recovery codes are shown once at enrolment; each
  works a single time.
- Re-seeding never overwrites a password an admin chose for themselves
  (`php artisan db:seed --force` is safe to re-run).
- **Forgot the password?** The login page links to a reset by e-mail (needs
  SMTP). The link lasts an hour and works once. A reset does not sign anybody
  in and does not bypass two-factor.
- **Locked out?** If both the authenticator and the recovery codes are gone,
  clear `two_factor_secret` for that row in `users`; the account then signs
  in with the password alone.

## Notes

- Uploads live in `storage/app/public/uploads/<channel-uuid>/` and are
  deleted together with their channel.
- The API is documented in `docs/API.md` and browsable at `/api/docs`.

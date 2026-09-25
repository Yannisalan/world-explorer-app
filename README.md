# World Explorer

A travel map where you save the countries you have visited and the ones you want
to visit. Plain PHP 8 on Apache, PostgreSQL on Neon. No framework, no build
step.

## Layout

The document root is the repository root. Every `.php` file in the root is a
browser-facing endpoint or page; there is no front controller and no `public/`
directory.

| Path | Role |
| --- | --- |
| `config.php` | Bootstrap: env loading, PDO connection, session cookie, CORS, CSRF, helpers. Include-only, denied by `.htaccess`. |
| `user_theme.php` | Resolves the caller's saved theme into `$theme`. Include-only. |
| `send_verification_email.php` | PHPMailer wrapper for the verification email. Include-only. |
| `index.php` `profile.php` `settings.php` `wishlist.php` `visited.php` | Server-rendered pages. |
| `login.html` `register.html` | Static pages that POST to `login.php` / `register.php`. |
| `get_*.php` `save_*.php` `remove_*.php` `countries.php` | JSON API. All return 401 when the session is missing. |
| `data/*.json` | Static country and population data, read at runtime. |
| `schema.sql` | Idempotent schema. Already applied to the Neon `production` branch. |

## Local development

Needs PHP 8.2+ with `pdo_pgsql`.

```sh
cp .env.example .env.local
# fill in DATABASE_URL and MAPBOX_TOKEN, then:
php -S localhost:8000
```

Leave `FRONTEND_ORIGIN` empty locally. An empty value keeps the session cookie
at `SameSite=Lax`, which works over plain HTTP on localhost. Setting it flips
the cookie to `SameSite=None; Secure`, which browsers require for cross-site
use and which therefore needs a real HTTPS origin.

## Deploying the API to Render

The repository has no commits yet, so Render cannot build from it until you
commit and push it to a Git provider and connect that repo.

1. Commit and push. `.env.local`, `.env`, `.neon` and `node_modules` are
   gitignored, and `.dockerignore` keeps them out of the image, so no local
   credentials are published either way.
2. In Render, create a **Web Service** from the repo. `render.yaml` in the root
   is picked up automatically and selects Docker with `./Dockerfile`.
3. Set the environment variables. All are `sync: false`, so Render will not
   invent values — every one must be supplied:

   | Variable | Notes |
   | --- | --- |
   | `DATABASE_URL` | **Pooled** Neon URL for the `production` branch. |
   | `FRONTEND_ORIGIN` | Comma-separated allowlist, e.g. `https://world-explorer-app.vercel.app`. |
   | `APP_URL` | This API's public base URL, no trailing slash. Used to build the emailed verification link. |
   | `MAPBOX_TOKEN` | Mapbox public token for the street and satellite tiles. |
   | `SMTP_HOST` `SMTP_PORT` `SMTP_USERNAME` `SMTP_PASSWORD` `SMTP_FROM` | Outbound mail for registration. |

4. Deploy. The health check hits `/get_countries.php`.

`send_verification_email.php` refuses to send and logs an error if
`SMTP_USERNAME`, `SMTP_PASSWORD` or `APP_URL` are unset, so a missing variable
shows up as a failed registration rather than a silently skipped email.

### A credential that must be rotated

The Gmail app password for `worldexplorerapp00@gmail.com` was previously
hardcoded in `send_verification_email.php`. It is now read from `SMTP_PASSWORD`
and is no longer in the source, but **it was exposed in plaintext and should be
treated as compromised**. Revoke it in the Google account's app-password page
and issue a new one, then set it as `SMTP_PASSWORD` on Render.

## Cross-origin behaviour

The API is built to be called from a different origin than it is served from.
`config.php` handles this:

- `Access-Control-Allow-Origin` echoes the request origin back, but only if it
  is in the `FRONTEND_ORIGIN` allowlist. A wildcard is not usable here, because
  browsers refuse to attach a session cookie to a `*` response when
  credentials are in play.
- `Vary: Origin` is always set so a cache cannot replay one origin's headers to
  another.
- `OPTIONS` preflights short-circuit with 204 from inside `config.php`, before
  any endpoint emits output.
- The session cookie becomes `SameSite=None; Secure` whenever `FRONTEND_ORIGIN`
  is non-empty, since that is the only combination a browser will send on a
  cross-site fetch.

Allowlist per environment rather than trusting any origin: with credentials
enabled, a permissive `*`-style policy would let any site on the internet read
an authenticated user's travel history through their browser.

## Frontend on Vercel — not done yet

The API is deployable, but the frontend is **not** split off. Vercel does not
run PHP, and five pages are still rendered by PHP with session data baked into
the HTML: `index.php`, `profile.php`, `settings.php`, `wishlist.php`,
`visited.php`. Those have to become static pages that fetch from the API. The
remaining work is:

- **Add `credentials: "include"` to every `fetch` in `script.js`.** Without it
  the session cookie is never sent and every call comes back 401. This is the
  single most common way this split fails.
- **Introduce an API base URL** and rewrite the hardcoded relative endpoints
  (`countries.php`, `get_wishlist.php`, `save_wishlist.php`, …) in `script.js`
  against it.
- **Convert the five PHP-rendered pages** to static HTML plus client-side
  fetches. The theme, username and CSRF token are currently read from
  `$_SESSION` and `user_settings` during render; the client needs an endpoint
  that returns them.
- **Make auth redirects absolute.** `login.php`, `register.php`, `verify.php`,
  `logout.php` and the four authenticated pages redirect to relative targets
  like `login.html`, which will resolve against the API origin. They need a
  `frontend_url()` helper driven by `FRONTEND_ORIGIN` (or a separate
  `FRONTEND_URL`) so the user lands on Vercel.
- **Update `service-worker.js` and `manifest.webmanifest`**, which assume a
  single origin, and repoint the service worker at the API with CORS in mind.
- **Fetch the CSRF token from the client.** `verify_csrf()` compares against
  `$_SESSION`, so a cross-origin form post needs the token delivered by an API
  response and echoed back in the `csrf_token` field.

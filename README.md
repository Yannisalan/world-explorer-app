# World Explorer

A travel map where you save the countries you have visited and the ones you want
to visit. Plain PHP 8 on Apache and PostgreSQL on Neon behind an API, with the
static frontend on Vercel. No framework, no build step.

The two halves are deployed separately: the API answers on `onrender.com` and
the pages are served on `vercel.app`. See
[Cross-origin behaviour](#cross-origin-behaviour) for why that is not optional.

## Layout

The document root is the repository root. Every `.php` file in the root is a
browser-facing endpoint or page; there is no front controller and no `public/`
directory.

| Path | Role |
| --- | --- |
| `config.php` | Bootstrap: env loading, PDO connection, session cookie, CORS, CSRF, helpers. Include-only, denied by `.htaccess`. |
| `user_theme.php` | Resolves the caller's saved theme into `$theme`. Include-only. |
| `send_verification_email.php` | PHPMailer wrapper for the verification email. Include-only. |
| `index.html` `profile.html` `settings.html` `wishlist.html` `visited.html` | Static pages. Data comes from the API at runtime. |
| `login.html` `register.html` | Static pages that POST to `login.php` / `register.php` on the API origin. |
| `config.js` | Declares `window.WE_API_BASE`. The single place the API origin is set. |
| `app.js` | Shared runtime: `apiUrl`, `apiFetch`, `apiPost`, `requireSession`, `applyTheme`. |
| `saved-list.js` | Backs both `wishlist.html` and `visited.html`, which differ only in data attributes. |
| `settings.js` | Backs `settings.html`: save via `update_settings.php`, delete via `delete_account.php`. |
| `get_*.php` `save_*.php` `remove_*.php` `countries.php` `me.php` | JSON API. All return 401 when the session is missing, except `me.php` and `health.php`. |
| `health.php` | Unauthenticated 200/503 probe for the platform health check. |
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

1. Push. `.env.local`, `.env`, `.neon` and `node_modules` are gitignored, and
   `.dockerignore` keeps them out of the image, so no local credentials are
   published either way.
2. In Render, create a **Web Service** from the repo. `render.yaml` in the root
   is picked up automatically and selects Docker with `./Dockerfile`.
3. Set the environment variables. All are `sync: false`, so Render will not
   invent values — every one must be supplied:

   | Variable | Notes |
   | --- | --- |
   | `DATABASE_URL` | **Pooled** Neon URL for the `production` branch. |
   | `FRONTEND_ORIGIN` | Comma-separated allowlist, e.g. `https://world-explorer.vercel.app`. The **first** entry is the canonical frontend and the target of every redirect; the rest exist only for CORS. |
   | `APP_URL` | This API's public base URL, no trailing slash. Used to build the emailed verification link. |
   | `MAPBOX_TOKEN` | Mapbox public token for the street and satellite tiles. |
   | `SMTP_HOST` `SMTP_PORT` `SMTP_USERNAME` `SMTP_PASSWORD` `SMTP_FROM` | Outbound mail for registration. |

4. Deploy. The health check hits `/health.php`, which is unauthenticated. It
   must not point at an authenticated endpoint: every other endpoint answers 401
   without a session, and Render treats anything other than 2xx as unhealthy, so
   the deploy would be rejected even though the app was fine.

`send_verification_email.php` refuses to send and logs an error if
`SMTP_USERNAME`, `SMTP_PASSWORD` or `APP_URL` are unset, so a missing variable
shows up as a failed registration rather than a silently skipped email.

### A credential that must be rotated

The Gmail app password for `worldexplorerapp00@gmail.com` was previously
hardcoded in `send_verification_email.php`. It is now read from `SMTP_PASSWORD`
and is no longer in the source, but **it was exposed in plaintext and should be
treated as compromised**. Revoke it in the Google account's app-password page
and issue a new one, then set it as `SMTP_PASSWORD` on Render.

## Deploying the frontend to Vercel

1. Set the API origin in `config.js`:
   ```js
   window.WE_API_BASE = "https://world-explorer-api.onrender.com";
   ```
   It ships as written — there is no build step, and a Vercel environment
   variable is not readable from a static page, so this file is the source of
   truth. Leave it empty for local development.
2. Import the repo as a Vercel project. `vercel.json` sets no-cache on
   `config.js`, `service-worker.js`, `manifest.webmanifest` and HTML, and a
   short cache on everything else.
3. `.vercelignore` keeps the whole PHP tree out of the upload. This is a
   security control, not housekeeping: Vercel has no PHP runtime, so an
   uploaded `.php` file would be served as plain text and hand over the API
   source. Keep the PHP on Render only.

### What the split changed on the client

Every call goes through `apiFetch`/`apiPost`, which attach
`credentials: "include"`. That option is what makes a `SameSite=None` cookie
actually cross origins; without it every authenticated call is a 401.

Login and registration stay real form POSTs to the API rather than `fetch`,
because the API answers them with a 302 to the frontend. A navigation is not a
CORS request, so no preflight is involved. The remaining auth redirects are
absolute, built by `frontend_url()` from `FRONTEND_ORIGIN`.

The theme, display name, settings, CSRF token and list counts used to be read
from `$_SESSION` while rendering. They now come from `me.php`, which answers
200 with `authenticated: false` when nobody is signed in rather than 401, since
it is called on every page load including the public ones.

`wishlist.html` and `visited.html` were near-identical server-rendered pages
carrying their own inline scripts. They now share `saved-list.js` and differ
only in the data attributes on `<body>`. The visited page needed
`get_visited.php`, which did not exist while the page was rendered server-side.

`data/countries.json` stays on the frontend origin as an offline fallback, and
`countries.php` on the API as the primary source. `script.js` only attaches
credentials to its own API origin, never to the two CDNs it also reads from.

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

Because `FRONTEND_ORIGIN` selects a real cross-site relationship, `config.php`
also enforces an Origin guard on unsafe methods: a `POST` from an origin that is
neither the API's own host nor an allowlisted frontend is refused with 403. A
request with no `Origin` at all is allowed through, which is what keeps `curl`
and server-to-server calls working.

The origin guard is the primary CSRF defence for the endpoints that do not
verify a token. `update_settings.php` and `delete_account.php` additionally
require the CSRF token that `me.php` hands out, which is sent back in the
`csrf_token` field.


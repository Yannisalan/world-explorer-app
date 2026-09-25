<?php
/**
 * Shared bootstrap: environment, database connection, and request helpers.
 *
 * Database: PostgreSQL (Neon) — the previous MySQL driver has been removed.
 * Connection settings come from DATABASE_URL in .env.local, which is the
 * pooled connection string written by `neon link`.
 */

// --- Environment ------------------------------------------------------------

/**
 * Load KEY=VALUE pairs from a dotenv file into the process environment.
 * Existing environment variables win, so real server config overrides the file.
 */
function load_env_file(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

load_env_file(__DIR__ . '/.env.local');
load_env_file(__DIR__ . '/.env');

// --- Cross-origin access ----------------------------------------------------

/**
 * Origins allowed to call this API with credentials, from FRONTEND_ORIGIN.
 *
 * A comma-separated list so that Vercel preview deploys, which get a unique
 * hostname per branch, can be allowlisted without a redeploy of the env var.
 * An empty value means same-origin only.
 */
function cors_allowlist(): array
{
    static $allowlist = null;

    if ($allowlist === null) {
        $raw = trim((string) (getenv('FRONTEND_ORIGIN') ?: ''));
        $allowlist = $raw === '' ? [] : array_values(array_filter(array_map(
            static fn(string $origin): string => rtrim(trim($origin), '/'),
            explode(',', $raw)
        )));
    }

    return $allowlist;
}

/**
 * True when the frontend is served from a different origin than this API.
 *
 * Decides whether the session cookie has to be issued SameSite=None, which is
 * the only value a browser will send on a cross-site fetch.
 */
function is_cross_origin(): bool
{
    return cors_allowlist() !== [];
}

/**
 * Host of the request's own origin, or an empty string when unknown.
 *
 * Compared host-only rather than scheme-qualified: TLS is terminated at
 * Render's proxy, so $_SERVER['HTTPS'] is not a reliable signal and a
 * scheme-qualified comparison would reject legitimate same-origin posts.
 */
function self_host(): string
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '') {
        return '';
    }

    // Strip any :port so the comparison is against the hostname alone.
    $colon = strrpos($host, ':');

    return $colon === false ? $host : substr($host, 0, $colon);
}

/**
 * True when a request Origin is this same host, or an allowlisted frontend.
 *
 * Sandboxed iframes and file:// documents send the literal string "null", which
 * parse_url resolves to no host and therefore never matches.
 */
function origin_is_trusted(string $origin): bool
{
    $origin = rtrim(trim($origin), '/');
    if ($origin === '') {
        return false;
    }

    $self = self_host();
    if ($self !== '') {
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        if ($originHost !== '' && $originHost === $self) {
            return true;
        }
    }

    return in_array($origin, cors_allowlist(), true);
}

/**
 * Reject state-changing requests that arrive from an untrusted origin.
 *
 * CORS is not a CSRF defence. The browser sends a cross-site POST and merely
 * withholds the response, so the write still lands; and once the session
 * cookie is SameSite=None it is attached to that request too, leaving the
 * endpoint looking at a fully authenticated user. Most endpoints here have no
 * csrf_token check, because SameSite=Lax used to be the defence.
 *
 * Checking Origin server-side closes that, and a browser page can neither forge
 * nor suppress the header. Inert while FRONTEND_ORIGIN is empty, so local
 * same-origin work is untouched. Requests carrying no Origin are allowed
 * through, because only browsers set it.
 */
function enforce_origin_guard(): void
{
    if (headers_sent()) {
        return;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
        return;
    }

    if (cors_allowlist() === []) {
        return;
    }

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '' || origin_is_trusted($origin)) {
        return;
    }

    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Origin not allowed.']);
    exit();
}

/**
 * Emit CORS headers for the requesting origin and short-circuit preflights.
 *
 * The origin is echoed back rather than sent as `*` because the browser refuses
 * to attach a session cookie to a wildcard response when credentials are in
 * play, which would break every authenticated call.
 */
function apply_cors_headers(): void
{
    if (headers_sent()) {
        return;
    }

    $allowlist = cors_allowlist();
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));

    // Caches must key on Origin, otherwise a response carrying one allowlisted
    // origin's ACAO gets replayed to a different origin.
    header('Vary: Origin');

    if ($allowlist !== [] && $origin !== '' && in_array(rtrim($origin, '/'), $allowlist, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit();
    }
}

apply_cors_headers();
enforce_origin_guard();

// Harden session cookies before any session_start() call.
// HttpOnly blocks JS access. SameSite=Lax is the right default for a
// same-origin frontend, but a cross-origin frontend on another registrable
// domain needs SameSite=None or the browser withholds the cookie on every POST;
// that in turn forces Secure, since browsers reject SameSite=None without it.
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $crossOrigin = is_cross_origin();
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $crossOrigin ? true : $https,
        'httponly' => true,
        'samesite' => $crossOrigin ? 'None' : 'Lax',
    ]);
}

// --- Database ---------------------------------------------------------------

/**
 * Convert a postgres:// or postgresql:// URL into a PDO `pgsql:` DSN.
 *
 * PDO does not accept URL-style connection strings, so the DATABASE_URL that
 * `neon link` writes has to be unpacked into DSN key/value pairs. Query
 * parameters are carried through, which preserves sslmode and
 * channel_binding=require.
 */
function pgsql_dsn_from_url(string $url): ?string
{
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['host']) || !isset($parts['path'])) {
        return null;
    }

    $dsn = [
        'host'     => $parts['host'],
        'port'     => $parts['port'] ?? 5432,
        'dbname'   => ltrim($parts['path'], '/'),
    ];

    // pdo_pgsql validates DSN keys against a fixed allowlist and raises
    // "invalid connection option" for anything else. Neon additionally emits
    // channel_binding=require, which is a libpq-level option that pdo_pgsql
    // does not accept, so unknown query parameters are dropped rather than
    // forwarded. Only keys libpq and pdo_pgsql both understand are carried
    // across; sslmode is the one Neon actually needs to keep.
    $allowed = [
        'sslmode', 'sslcert', 'sslkey', 'sslrootcert', 'sslcrl',
        'connect_timeout', 'application_name', 'options',
    ];

    if (isset($parts['query'])) {
        parse_str($parts['query'], $query);
        foreach ($allowed as $key) {
            if (isset($query[$key]) && is_string($query[$key])) {
                $dsn[$key] = $query[$key];
            }
        }
    }

    // Neon routes pooled connections by SNI, and only a libpq new enough to
    // send the endpoint ID in the TLS handshake will work without help. The
    // libpq bundled with some Windows PHP builds predates SNI and fails with
    // "Endpoint ID is not specified", so pass the endpoint ID (the first label
    // of the host) explicitly via the libpq `options` parameter. Harmless on
    // newer libpq, which simply overrides the SNI value.
    if (!isset($dsn['options'])) {
        $endpointId = explode('.', (string) $parts['host'])[0];
        if (str_starts_with($endpointId, 'ep-')) {
            $dsn['options'] = 'endpoint=' . $endpointId;
        }
    }

    if ($dsn['dbname'] === '') {
        return null;
    }

    $pairs = [];
    foreach ($dsn as $key => $value) {
        $pairs[] = $key . '=' . $value;
    }

    return 'pgsql:' . implode(';', $pairs);
}

$databaseUrl = (string) (getenv('DATABASE_URL') ?: '');
$dbError = null;
$conn = null;

try {
    if ($databaseUrl === '') {
        throw new RuntimeException('DATABASE_URL is not set. Run `neon link` to create .env.local.');
    }

    $dsn = pgsql_dsn_from_url($databaseUrl);
    if ($dsn === null) {
        throw new RuntimeException('DATABASE_URL is not a valid postgres connection string.');
    }

    $conn = new PDO(
        $dsn,
        (string) (parse_url($databaseUrl, PHP_URL_USER) ?: ''),
        rawurldecode((string) (parse_url($databaseUrl, PHP_URL_PASS) ?: '')),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Emulated prepares are kept deliberately. Native prepares
            // (ATTR_EMULATE_PREPARES => false) make pdo_pgsql abort the
            // surrounding transaction on this build, which breaks the
            // beginTransaction/commit pair in settings.php. Emulated prepares
            // mean booleans must be written as SQL TRUE/FALSE literals rather
            // than bound parameters, because PDO renders PHP false as an empty
            // string, which PostgreSQL rejects for a boolean column.
            PDO::ATTR_EMULATE_PREPARES => true,
        ]
    );
} catch (Throwable $e) {
    $dbError = "Database unavailable. Check DATABASE_URL and that PostgreSQL is reachable.";
}

function db_is_ready(): bool
{
    global $conn;
    return $conn instanceof PDO;
}

function require_db_json(): void
{
    global $dbError;

    if (db_is_ready()) {
        return;
    }

    http_response_code(503);
    echo json_encode([
        "status" => "error",
        "message" => $dbError ?? "Database unavailable."
    ]);
    exit();
}

/**
 * Public base URL of the static frontend.
 *
 * Auth endpoints live on the API origin but must send the browser back to the
 * frontend afterwards, so every redirect that targets a page has to be
 * absolute. Falls back to the request's own origin when unset, which keeps
 * same-origin local development working with no configuration.
 */
function frontend_base_url(): string
{
    $configured = rtrim(trim((string) (getenv('FRONTEND_ORIGIN') ?: '')), '/');

    if ($configured !== '') {
        // A comma-separated allowlist is also valid input; the first entry is
        // the canonical frontend and the rest exist only for CORS.
        $first = trim(explode(',', $configured)[0]);
        return rtrim($first, '/');
    }

    $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

    return $host === '' ? '' : $scheme . '://' . $host;
}

/**
 * Absolute URL for a frontend page, e.g. frontend_url('login.html').
 *
 * With no FRONTEND_ORIGIN and no Host header this returns the path unchanged,
 * which preserves the old relative-redirect behaviour.
 */
function frontend_url(string $path): string
{
    $base = frontend_base_url();
    $path = '/' . ltrim($path, '/');

    return $base === '' ? $path : $base . $path;
}

function redirect_with_error(string $location, string $error): void
{
    header("Location: " . frontend_url($location) . (str_contains($location, "?") ? "&" : "?") . "error=" . urlencode($error));
    exit();
}

/**
 * Tear down the current session: empty it, expire the session cookie so it
 * cannot be replayed, and destroy the backing store.
 */
function destroy_session(): void
{
    // Ensure there is a session to destroy; session_destroy() warns otherwise.
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}

function mapbox_access_token(): string
{
    return trim((string) (getenv("MAPBOX_TOKEN") ?: "MAPBOX_TOKEN_NOT_SET"));
}

// --- Value coercion ---------------------------------------------------------

/**
 * Read a Postgres BOOLEAN column as a PHP bool.
 *
 * pdo_pgsql returns real booleans on PHP 8.1+, but this also tolerates the
 * integer and 't'/'f' string forms so the helpers survive a driver change.
 */
function db_to_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return $value != 0;
    }
    if (is_string($value)) {
        return in_array(strtolower($value), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
    return (bool) $value;
}

/**
 * Canonicalise an email address for storage and lookup.
 *
 * MySQL's default utf8mb4_general_ci collation compared emails
 * case-insensitively, so 'Bob@x.com' and 'bob@x.com' were one account.
 * PostgreSQL text comparison is case-sensitive, so the case fold that MySQL did
 * implicitly is made explicit here.
 */
function normalize_email(string $email): string
{
    return mb_strtolower(trim($email), "UTF-8");
}

// --- CSRF helpers -----------------------------------------------------------

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Decoded JSON request body, read once and cached.
 *
 * The endpoints were form posts when the frontend shared this origin; they are
 * JSON now that the frontend is deployed separately, so the body has to be
 * read from php://input rather than $_POST. Cached because the stream is
 * consumed on first read.
 */
function request_json(): array
{
    static $body = null;

    if ($body === null) {
        $raw = file_get_contents('php://input');
        $decoded = ($raw === false || $raw === '') ? [] : json_decode($raw, true);
        $body = is_array($decoded) ? $decoded : [];
    }

    return $body;
}

function verify_csrf(): void
{
    // Accept either encoding: a form post from a browser that navigated here,
    // or a JSON body from fetch.
    $supplied = (string) ($_POST['csrf_token'] ?? '');

    if ($supplied === '') {
        $body = request_json();
        $supplied = (string) ($body['csrf_token'] ?? '');
    }

    $expected = (string) ($_SESSION['csrf_token'] ?? '');

    if ($expected === '' || !hash_equals($expected, $supplied)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or missing security token. Reload the page and try again.'
        ]);
        exit();
    }
}

// --- Input helpers ----------------------------------------------------------

/** Reject a JSON-endpoint request with a 422 and stop execution. */
function json_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit();
}

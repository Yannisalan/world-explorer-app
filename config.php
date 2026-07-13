<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "globe";
$mapboxAccessToken = getenv("MAPBOX_TOKEN") ?: "MAPBOX_TOKEN_NOT_SET";  // Set in .env or in the server environment

// Harden session cookies before any session_start() call.
// SameSite=Lax blocks cross-site POST forgery; HttpOnly blocks JS access.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,   // set true when served over HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

$conn = null;
$dbError = null;

try {
    $conn = new PDO(
        "mysql:host=$servername;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    $dbError = "Database unavailable. Start MySQL and try again.";
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

function redirect_with_error(string $location, string $error): void
{
    header("Location: {$location}" . (str_contains($location, "?") ? "&" : "?") . "error=" . urlencode($error));
    exit();
}

function mapbox_access_token(): string
{
    global $mapboxAccessToken;
    return trim($mapboxAccessToken);
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

function verify_csrf(): void
{
    $supplied = (string) ($_POST['csrf_token'] ?? '');
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $supplied)) {
        http_response_code(403);
        exit('Invalid or missing security token. Please go back and try again.');
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
?>

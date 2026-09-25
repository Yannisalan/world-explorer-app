<?php
/**
 * Liveness and readiness probe for the container platform.
 *
 * This exists because every other endpoint requires a session and answers 401
 * without one. A health check has no session, so it was pointed at an endpoint
 * that could only ever report unhealthy, and the deploy was rejected even
 * though the app was fine.
 *
 * Returns 200 once PHP is up and the database is reachable, 503 otherwise.
 * Deliberately reports nothing beyond that: no DSN, no driver version, no
 * exception text, since this is reachable without authentication.
 */
include "config.php";

// No session_start() here. The probe is a read-only check and starting a session
// would write a cookie file and a session id for every health check.

header("Content-Type: application/json");
header("Cache-Control: no-store");

if (!db_is_ready()) {
    http_response_code(503);
    echo json_encode(["status" => "unavailable"]);
    exit();
}

try {
    // A trivial round trip, so "DATABASE_URL is set" is not mistaken for
    // "the database actually answers".
    $conn->query("SELECT 1");
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(["status" => "unavailable"]);
    exit();
}

http_response_code(200);
echo json_encode(["status" => "ok"]);

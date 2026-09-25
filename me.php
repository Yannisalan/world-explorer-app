<?php
/**
 * Session bootstrap for the static frontend.
 *
 * The pages used to read the signed-in user's name, theme, settings and CSRF
 * token straight out of $_SESSION while rendering. With the frontend on a
 * separate origin there is no PHP render step, so the browser has to ask.
 *
 * Returns 200 with authenticated:false rather than 401 when nobody is signed
 * in. This endpoint is hit on every page load including the public ones, and a
 * 401 would put a console error on screen for a perfectly normal visit.
 */
include "config.php";
session_start();

header("Content-Type: application/json");

$allowedThemes = ["dark", "light", "auto"];
$allowedLanguages = ["en", "es", "fr", "de"];

if (!isset($_SESSION["user_id"]) || !db_is_ready()) {
    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "authenticated" => false,
    ]);
    exit();
}

/** @var PDO $conn */
$userId = (int) $_SESSION["user_id"];

try {
    $stmt = $conn->prepare("SELECT id, name, email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // The account was deleted or removed out of band while the cookie
        // still held a valid session id.
        destroy_session();

        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "authenticated" => false,
        ]);
        exit();
    }

    $_SESSION["user"] = $user["name"];

    // The row is created lazily on first read rather than at registration, so
    // it may not exist yet for an established account.
    $stmt = $conn->prepare("SELECT theme, language, notifications FROM user_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        $stmt = $conn->prepare("
            INSERT INTO user_settings (user_id, theme, language, notifications)
            VALUES (?, 'dark', 'en', TRUE)
            ON CONFLICT (user_id) DO NOTHING
        ");
        $stmt->execute([$userId]);

        $settings = ["theme" => "dark", "language" => "en", "notifications" => true];
    }

    if (!in_array($settings["theme"], $allowedThemes, true)) {
        $settings["theme"] = "dark";
    }
    if (!in_array($settings["language"], $allowedLanguages, true)) {
        $settings["language"] = "en";
    }

    // Counted by user_id rather than display name so the totals survive a
    // rename and cannot collide between two same-named accounts.
    $stmt = $conn->prepare("
        SELECT
            (SELECT COUNT(*) FROM visited_countries WHERE user_id = ?) AS visited,
            (SELECT COUNT(*) FROM wishlist WHERE user_id = ?) AS wishlist
    ");
    $stmt->execute([$userId, $userId]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "authenticated" => true,
        "user" => [
            "id" => (int) $user["id"],
            "name" => $user["name"],
            "email" => $user["email"],
        ],
        "settings" => [
            "theme" => $settings["theme"],
            "language" => $settings["language"],
            "notifications" => db_to_bool($settings["notifications"]),
        ],
        "counts" => [
            "visited" => (int) ($counts["visited"] ?? 0),
            "wishlist" => (int) ($counts["wishlist"] ?? 0),
        ],
        "csrfToken" => csrf_token(),
    ]);
} catch (Throwable $e) {
    json_error(500, "Unable to load your account right now.");
}

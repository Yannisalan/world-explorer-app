<?php
/**
 * Persists account details and display preferences.
 *
 * Extracted from the POST branch of the old server-rendered settings.php, which
 * mixed saving with markup. Validation and the ordering of the checks are
 * unchanged; only the transport is now JSON.
 */
include "config.php";
session_start();

header("Content-Type: application/json");

require_db_json();

if (!isset($_SESSION["user_id"])) {
    json_error(401, "Authentication required");
}

/** @var PDO $conn */
$userId = (int) $_SESSION["user_id"];

$allowedThemes = ["dark", "light", "auto"];
$allowedLanguages = ["en", "es", "fr", "de"];

try {
    verify_csrf();

    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $currentHash = $stmt->fetchColumn();

    if (!$currentHash) {
        json_error(401, "Authentication required");
    }

    $body = request_json();

    $email = normalize_email((string) ($body["email"] ?? ""));
    $themeValue = (string) ($body["theme"] ?? "dark");
    $language = (string) ($body["language"] ?? "en");
    $notifications = !empty($body["notifications"]);
    $currentPassword = (string) ($body["current_password"] ?? "");
    $newPassword = (string) ($body["new_password"] ?? "");

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error(422, "Enter a valid email address.");
    }

    if (!in_array($themeValue, $allowedThemes, true)) {
        json_error(422, "Choose a valid theme.");
    }

    if (!in_array($language, $allowedLanguages, true)) {
        json_error(422, "Choose a valid language.");
    }

    // Validated before the transaction opens. json_error() exits immediately,
    // so a check placed inside the try block would skip the rollback below.
    if ($newPassword !== "" && strlen($newPassword) < 6) {
        json_error(422, "New password must be at least 6 characters.");
    }

    $conn->beginTransaction();

    $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
    $stmt->execute([$email, $userId]);

    if ($newPassword !== "") {
        if (!password_verify($currentPassword, (string) $currentHash)) {
            throw new RuntimeException("Current password is incorrect.");
        }

        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    }

    // MySQL's ON DUPLICATE KEY UPDATE is PostgreSQL's ON CONFLICT DO UPDATE,
    // with the conflict target on the primary key user_id. notifications is
    // interpolated as a TRUE/FALSE literal because PDO would send a bound PHP
    // false as an empty string, which PostgreSQL rejects for a boolean column.
    // The value is derived from a boolean, so it is never user input.
    $notificationsLiteral = $notifications ? "TRUE" : "FALSE";
    $stmt = $conn->prepare("
        INSERT INTO user_settings (user_id, theme, language, notifications)
        VALUES (?, ?, ?, {$notificationsLiteral})
        ON CONFLICT (user_id) DO UPDATE SET
            theme = EXCLUDED.theme,
            language = EXCLUDED.language,
            notifications = EXCLUDED.notifications
    ");
    $stmt->execute([$userId, $themeValue, $language]);

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Settings saved.",
        "settings" => [
            "theme" => $themeValue,
            "language" => $language,
            "notifications" => $notifications,
        ],
        "email" => $email,
    ]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    $message = $e instanceof RuntimeException
        ? $e->getMessage()
        : "Unable to save settings right now.";

    json_error(422, $message);
}

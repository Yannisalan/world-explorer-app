<?php
/**
 * Permanently deletes the signed-in account and everything it owns.
 *
 * Extracted from the delete_account branch of the old server-rendered
 * settings.php. Gated on the same three independent conditions it always was:
 * a valid CSRF token, the account password to prove the session holder is the
 * owner rather than a hijacked session, and an exact typed confirmation.
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

try {
    verify_csrf();

    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $currentHash = $stmt->fetchColumn();

    if (!$currentHash) {
        json_error(401, "Authentication required");
    }

    $body = request_json();
    $deletePassword = (string) ($body["current_password"] ?? "");
    $confirmation = trim((string) ($body["confirm_delete"] ?? ""));

    if ($confirmation !== "DELETE") {
        json_error(422, "Type DELETE to confirm account removal.");
    }

    if (!password_verify($deletePassword, (string) $currentHash)) {
        json_error(422, "Current password is incorrect.");
    }

    $conn->beginTransaction();

    // wishlist, visited_countries and user_settings all reference users(id) ON
    // DELETE CASCADE, so this one statement removes the account and every row
    // belonging to it.
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$userId]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException("Account could not be deleted. Please try again.");
    }

    $conn->commit();

    // The account is gone; the session that authorised the delete must not
    // outlive it.
    destroy_session();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Your account has been deleted.",
    ]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    $message = $e instanceof RuntimeException
        ? $e->getMessage()
        : "Unable to delete the account right now.";

    json_error(422, $message);
}

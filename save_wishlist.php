<?php
include "config.php";
session_start();

header("Content-Type: application/json");

require_db_json();

/** @var \PDO $conn */
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Authentication required"]);
    exit();
}

// Rows are keyed on user_id, so the session only needs the id. The display
// name is still refreshed because the pages that render it read it from here.
if (!isset($_SESSION['user'])) {
    $u = $conn->prepare("SELECT name FROM users WHERE id = ?");
    $u->execute([$_SESSION['user_id']]);
    $dbName = $u->fetchColumn();
    if (!$dbName) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Authentication required"]);
        exit();
    }
    $_SESSION['user'] = $dbName;
}

$data = json_decode(file_get_contents("php://input"), true);
$country = trim($data['country'] ?? '');

if ($country === '' || mb_strlen($country) > 100) {
    json_error(422, "Country is required and must be under 100 characters.");
}

// The UNIQUE (user_id, country) constraint replaces the previous
// SELECT COUNT(*) + INSERT pair, which could race two concurrent requests
// into a duplicate-key error.
$stmt = $conn->prepare("
    INSERT INTO wishlist (user_id, country)
    VALUES (?, ?)
    ON CONFLICT (user_id, country) DO NOTHING
");
$stmt->execute([$_SESSION['user_id'], $country]);

echo json_encode(["status" => "success"]);
?>

<?php
include "config.php";
session_start();

header("Content-Type: application/json");

require_db_json();

/** @var \PDO $conn */
if (!isset($_SESSION['user'])) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Authentication required"]);
        exit();
    }
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

$stmt = $conn->prepare("SELECT COUNT(*) FROM visited_countries WHERE user_name = ? AND country = ?");
$stmt->execute([$_SESSION['user'], $country]);

if ((int) $stmt->fetchColumn() === 0) {
    $insert = $conn->prepare("INSERT INTO visited_countries (user_name, country) VALUES (?, ?)");
    $insert->execute([$_SESSION['user'], $country]);
}

echo json_encode(["status" => "success"]);
?>

<?php
include "config.php";
session_start();

header("Content-Type: application/json");

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Authentication required"]);
    exit();
}

require_db_json();

$data = json_decode(file_get_contents("php://input"), true);
$country = trim($data['country'] ?? '');

if ($country === '' || mb_strlen($country) > 100) {
    json_error(422, "Country is required and must be under 100 characters.");
}

/** @var \PDO $conn */
$stmt = $conn->prepare("DELETE FROM visited_countries WHERE user_id = ? AND country = ?");
$stmt->execute([$_SESSION['user_id'], $country]);

echo json_encode(["status" => "success"]);

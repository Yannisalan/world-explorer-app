<?php
include "config.php";
session_start();

header("Content-Type: application/json");

require_db_json();

if (!isset($_SESSION['user'])) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([]);
        exit();
    }
    $u = $conn->prepare("SELECT name FROM users WHERE id = ?");
    $u->execute([$_SESSION['user_id']]);
    $dbName = $u->fetchColumn();
    if (!$dbName) {
        http_response_code(401);
        echo json_encode([]);
        exit();
    }
    $_SESSION['user'] = $dbName;
}

$stmt = $conn->prepare("SELECT country FROM wishlist WHERE user_name = ? ORDER BY country ASC");
$stmt->execute([$_SESSION['user']]);

echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
?>

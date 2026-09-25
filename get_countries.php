<?php
include "config.php";
session_start();

header("Content-Type: application/json");

require_db_json();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([]);
    exit();
}

// Rows are keyed on user_id, so only the id is required. The display name is
// still refreshed because the pages that render it read it from the session.
if (!isset($_SESSION['user'])) {
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

$stmt = $conn->prepare("SELECT country FROM visited_countries WHERE user_id = ? ORDER BY country ASC");
$stmt->execute([$_SESSION['user_id']]);

echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
?>

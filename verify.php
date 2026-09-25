<?php
include "config.php";

$token = $_GET['token'] ?? '';

if ($token == '') {
    die("Invalid verification link.");
}

$stmt = $conn->prepare("
    SELECT id
    FROM users
    WHERE verification_token = ?
");

$stmt->execute([$token]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("Invalid or expired verification link.");
}

$stmt = $conn->prepare("
    UPDATE users
    SET
        verified = TRUE,
        verification_token = NULL
    WHERE id = ?
");

$stmt->execute([$user['id']]);

header("Location: " . frontend_url("login.html?verified=1"));
exit();
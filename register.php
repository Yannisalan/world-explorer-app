<?php
include "config.php";
require_once "send_verification_email.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . frontend_url("register.html"));
    exit();
}

if (!db_is_ready()) {
    redirect_with_error("register.html", "db");
}

$name     = trim($_POST['name'] ?? '');
$email    = normalize_email((string) ($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';

// Validate name
if ($name === '' || mb_strlen($name) > 100) {
    header("Location: " . frontend_url("register.html?error=invalid"));
    exit();
}

// Validate email
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
    header("Location: " . frontend_url("register.html?error=invalid"));
    exit();
}

// Validate password
if (mb_strlen($password) < 6 || mb_strlen($password) > 72) {
    header("Location: " . frontend_url("register.html?error=invalid"));
    exit();
}

/** @var PDO $conn */

// Check if email already exists
$check = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
$check->execute([$email]);

if ($check->fetchColumn() > 0) {
    header("Location: " . frontend_url("register.html?error=exists"));
    exit();
}

// Hash password
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// Generate verification token
$token = bin2hex(random_bytes(32));

// Save user
$stmt = $conn->prepare("
    INSERT INTO users
    (name, email, password, verified, verification_token)
    VALUES (?, ?, ?, FALSE, ?)
");

$stmt->execute([
    $name,
    $email,
    $passwordHash,
    $token
]);

// Send verification email
if (sendVerificationEmail($email, $name, $token)) {

    header("Location: " . frontend_url("login.html?verify=1"));
    exit();

} else {

    header("Location: " . frontend_url("register.html?error=email"));
    exit();

}
?>
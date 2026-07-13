<?php
require_once "config.php";
session_start();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.html");
    exit();
}

// Brute-force protection
const LOGIN_MAX_ATTEMPTS = 10;
const LOGIN_WINDOW_SECONDS = 900;

$now = time();

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [
        'count' => 0,
        'since' => $now
    ];
}

if ($now - $_SESSION['login_attempts']['since'] > LOGIN_WINDOW_SECONDS) {
    $_SESSION['login_attempts'] = [
        'count' => 0,
        'since' => $now
    ];
}

if ($_SESSION['login_attempts']['count'] >= LOGIN_MAX_ATTEMPTS) {
    header("Location: login.html?error=locked");
    exit();
}

if (!db_is_ready()) {
    redirect_with_error("login.html", "db");
}

$email = trim($_POST["email"] ?? "");
$password = $_POST["password"] ?? "";

if (
    $email === "" ||
    $password === "" ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    header("Location: login.html?error=1");
    exit();
}

/** @var PDO $conn */
$stmt = $conn->prepare("
    SELECT
        id,
        name,
        password,
        verified
    FROM users
    WHERE email = ?
");

$stmt->execute([$email]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['login_attempts']['count']++;
    header("Location: login.html?error=1");
    exit();
}

if (!password_verify($password, $user["password"])) {
    $_SESSION['login_attempts']['count']++;
    header("Location: login.html?error=1");
    exit();
}

// NEW: Email verification check
if ((int)$user["verified"] !== 1) {
    header("Location: login.html?error=verify");
    exit();
}

// Login successful
unset($_SESSION['login_attempts']);

session_regenerate_id(true);

$_SESSION["user_id"] = $user["id"];
$_SESSION["user"] = $user["name"];

header("Location: index.php");
exit();
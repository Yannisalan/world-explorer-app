<?php
require_once __DIR__ . "/config.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$theme = "dark";

if (isset($_SESSION['user_id']) && db_is_ready()) {
    try {
        /** @var \PDO $conn */
        $stmt = $conn->prepare("SELECT theme FROM user_settings WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($settings && in_array($settings["theme"], ["light", "dark", "auto"], true)) {
            $theme = $settings["theme"];
        }
    } catch (Throwable $e) {
        $theme = "dark";
    }
}
?>

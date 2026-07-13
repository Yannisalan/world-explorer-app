<?php
require_once "user_theme.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.html");
    exit();
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}

function is_selected(string $current, string $candidate): string
{
    return $current === $candidate ? "selected" : "";
}

function is_checked(string $current, string $candidate): string
{
    return $current === $candidate ? "checked" : "";
}

$assetVersion = "20260622-ui-v2";
$allowedThemes = ["dark", "light", "auto"];
$allowedLanguages = ["en", "es", "fr", "de"];
$message = "";
$error = "";
$canSave = false;
$user = [
    "name" => trim((string) ($_SESSION["user"] ?? "Explorer")) ?: "Explorer",
    "email" => "",
    "password" => "",
];
$settings = [
    "theme" => in_array($theme ?? "dark", $allowedThemes, true) ? $theme : "dark",
    "language" => "en",
    "notifications" => 1,
];

if (!db_is_ready()) {
    $error = "Database unavailable. Start MySQL before changing account settings.";
} else {
    try {
        /** @var PDO $conn */
        $conn->exec("
            CREATE TABLE IF NOT EXISTS user_settings (
                user_id INT NOT NULL PRIMARY KEY,
                theme VARCHAR(16) NOT NULL DEFAULT 'dark',
                language VARCHAR(8) NOT NULL DEFAULT 'en',
                notifications TINYINT(1) NOT NULL DEFAULT 1
            )
        ");

        $stmt = $conn->prepare("SELECT id, name, email, password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION["user_id"]]);
        $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dbUser) {
            header("Location: logout.php");
            exit();
        }

        $user = $dbUser;
        $_SESSION["user"] = $dbUser["name"];

        $stmt = $conn->prepare("SELECT theme, language, notifications FROM user_settings WHERE user_id = ?");
        $stmt->execute([$_SESSION["user_id"]]);
        $dbSettings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dbSettings) {
            $stmt = $conn->prepare("
                INSERT INTO user_settings (user_id, theme, language, notifications)
                VALUES (?, 'dark', 'en', 1)
            ");
            $stmt->execute([$_SESSION["user_id"]]);
            $dbSettings = $settings;
        }

        $settings = array_merge($settings, $dbSettings);
        if (!in_array($settings["theme"], $allowedThemes, true)) {
            $settings["theme"] = "dark";
        }
        if (!in_array($settings["language"], $allowedLanguages, true)) {
            $settings["language"] = "en";
        }
        $canSave = true;

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            verify_csrf();
            $email = trim((string) ($_POST["email"] ?? ""));
            $themeValue = (string) ($_POST["theme"] ?? "dark");
            $language = (string) ($_POST["language"] ?? "en");
            $notifications = isset($_POST["notifications"]) ? 1 : 0;
            $currentPassword = (string) ($_POST["current_password"] ?? "");
            $newPassword = (string) ($_POST["new_password"] ?? "");

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("Enter a valid email address.");
            }

            if (!in_array($themeValue, $allowedThemes, true)) {
                throw new RuntimeException("Choose a valid theme.");
            }

            if (!in_array($language, $allowedLanguages, true)) {
                throw new RuntimeException("Choose a valid language.");
            }

            $conn->beginTransaction();

            $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
            $stmt->execute([$email, $_SESSION["user_id"]]);

            if ($newPassword !== "") {
                if (strlen($newPassword) < 6) {
                    throw new RuntimeException("New password must be at least 6 characters.");
                }

                if (!password_verify($currentPassword, $user["password"])) {
                    throw new RuntimeException("Current password is incorrect.");
                }

                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $_SESSION["user_id"]]);
            }

            $stmt = $conn->prepare("
                INSERT INTO user_settings (user_id, theme, language, notifications)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    theme = ?,
                    language = ?,
                    notifications = ?
            ");
            $stmt->execute([
                $_SESSION["user_id"],
                $themeValue,
                $language,
                $notifications,
                $themeValue,
                $language,
                $notifications,
            ]);

            $conn->commit();

            $stmt = $conn->prepare("SELECT id, name, email, password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION["user_id"]]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;

            $settings = [
                "theme" => $themeValue,
                "language" => $language,
                "notifications" => $notifications,
            ];
            $theme = $themeValue;
            $message = "Settings saved.";
        }
    } catch (Throwable $e) {
        if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
            $conn->rollBack();
        }
        $error = $e instanceof RuntimeException ? $e->getMessage() : "Unable to save settings right now.";
    }
}

$themeName = e($settings["theme"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f766e">
    <title>Settings | World Explorer</title>
    <link rel="icon" href="favicon.ico">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="apple-touch-icon" href="app-icon.svg">
    <link rel="stylesheet" href="styles.css?v=<?php echo $assetVersion; ?>">
</head>
<body class="page-shell settings-page" data-theme="<?php echo $themeName; ?>">
<main class="page-wrap">
    <nav class="page-nav" aria-label="Settings navigation">
        <a class="brand" href="index.php" aria-label="World Explorer home">
            <span class="brand-mark" aria-hidden="true">W</span>
            <span>
                <strong>World Explorer</strong>
                <small>Settings</small>
            </span>
        </a>
        <div class="page-nav-actions">
            <a class="btn btn-secondary" href="profile.php">Profile</a>
            <a class="btn btn-primary" href="index.php">Open Globe</a>
        </div>
    </nav>

    <header class="settings-hero">
        <p class="eyebrow">Account controls</p>
        <h1>Settings</h1>
        <p>Manage your account details, display preference, and optional password update.</p>
    </header>

    <?php if ($message !== ""): ?>
        <div class="page-message success"><?php echo e($message); ?></div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="page-message error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <form class="settings-form" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <section class="settings-section" aria-labelledby="accountTitle">
            <div>
                <p class="eyebrow">Identity</p>
                <h2 id="accountTitle">Account</h2>
            </div>
            <div class="field">
                <label for="email">Email address</label>
                <input id="email" type="email" name="email" value="<?php echo e($user["email"]); ?>" required <?php echo $canSave ? "" : "disabled"; ?>>
            </div>
        </section>

        <section class="settings-section" aria-labelledby="securityTitle">
            <div>
                <p class="eyebrow">Security</p>
                <h2 id="securityTitle">Password</h2>
            </div>
            <div class="settings-grid">
                <div class="field">
                    <label for="current_password">Current password</label>
                    <input id="current_password" type="password" name="current_password" autocomplete="current-password" <?php echo $canSave ? "" : "disabled"; ?>>
                </div>
                <div class="field">
                    <label for="new_password">New password</label>
                    <input id="new_password" type="password" name="new_password" autocomplete="new-password" minlength="6" <?php echo $canSave ? "" : "disabled"; ?>>
                </div>
            </div>
        </section>

        <section class="settings-section" aria-labelledby="preferencesTitle">
            <div>
                <p class="eyebrow">Preferences</p>
                <h2 id="preferencesTitle">Display</h2>
            </div>
            <fieldset class="segmented-control" <?php echo $canSave ? "" : "disabled"; ?>>
                <legend class="sr-only">Theme</legend>
                <label>
                    <input type="radio" name="theme" value="dark" <?php echo is_checked($settings["theme"], "dark"); ?>>
                    <span>Dark</span>
                </label>
                <label>
                    <input type="radio" name="theme" value="light" <?php echo is_checked($settings["theme"], "light"); ?>>
                    <span>Light</span>
                </label>
                <label>
                    <input type="radio" name="theme" value="auto" <?php echo is_checked($settings["theme"], "auto"); ?>>
                    <span>Auto</span>
                </label>
            </fieldset>
            <!--<div class="field">
                <label for="language">Language</label>
                <select id="language" name="language" <?php echo $canSave ? "" : "disabled"; ?>>
                    <option value="en" <?php echo is_selected($settings["language"], "en"); ?>>English</option>
                    <option value="es" <?php echo is_selected($settings["language"], "es"); ?>>Spanish</option>
                    <option value="fr" <?php echo is_selected($settings["language"], "fr"); ?>>French</option>
                    <option value="de" <?php echo is_selected($settings["language"], "de"); ?>>German</option>
                </select>
            </div> -->
        </section>

        <section class="settings-section" aria-labelledby="notificationsTitle">
            <div>
                <p class="eyebrow">Updates</p>
                <h2 id="notificationsTitle">Notifications</h2>
            </div>
            <label class="toggle-row">
                <input type="checkbox" name="notifications" value="1" <?php echo ((int) $settings["notifications"] === 1) ? "checked" : ""; ?> <?php echo $canSave ? "" : "disabled"; ?>>
                <span>Email notifications</span>
            </label>
        </section>

        <div class="form-actions">
            <a class="btn btn-secondary" href="index.php">Cancel</a>
            <button class="btn btn-primary" type="submit" <?php echo $canSave ? "" : "disabled"; ?>>Save Settings</button>
        </div>
    </form>
</main>
</body>
</html>

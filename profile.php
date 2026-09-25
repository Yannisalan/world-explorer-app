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

$assetVersion = "20260622-ui-v2";
$themeName = e($theme ?? "dark");
$fallbackName = trim((string) ($_SESSION["user"] ?? "Explorer"));
$user = [
    "name" => $fallbackName !== "" ? $fallbackName : "Explorer",
    "email" => "Unavailable",
];
$visitedCount = 0;
$wishlistCount = 0;
$pageError = "";

if (db_is_ready()) {
    try {
        /** @var PDO $conn */
        $stmt = $conn->prepare("SELECT id, name, email FROM users WHERE id = ?");
        $stmt->execute([$_SESSION["user_id"]]);
        $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($dbUser) {
            $user = $dbUser;
            $_SESSION["user"] = $dbUser["name"];
        }

        // Counted by user_id (not display name) so the totals stay correct when
        // a user changes their name, and cannot collide between same-named users.
        $countVisited = $conn->prepare("SELECT COUNT(*) FROM visited_countries WHERE user_id = ?");
        $countVisited->execute([$_SESSION["user_id"]]);
        $visitedCount = (int) $countVisited->fetchColumn();

        $countWishlist = $conn->prepare("SELECT COUNT(*) FROM wishlist WHERE user_id = ?");
        $countWishlist->execute([$_SESSION["user_id"]]);
        $wishlistCount = (int) $countWishlist->fetchColumn();
    } catch (Throwable $e) {
        $pageError = "Profile details are limited because part of the database schema is unavailable.";
    }
} else {
    $pageError = "Database unavailable. Check DATABASE_URL and that PostgreSQL is reachable.";
}

$initial = strtoupper(substr($user["name"], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f766e">
    <title>Profile | World Explorer</title>
    <link rel="icon" href="favicon.ico">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="apple-touch-icon" href="app-icon.svg">
    <link rel="stylesheet" href="styles.css?v=<?php echo $assetVersion; ?>">
</head>
<body class="page-shell profile-page" data-theme="<?php echo $themeName; ?>">
<main class="page-wrap">
    <nav class="page-nav" aria-label="Profile navigation">
        <a class="brand" href="index.php" aria-label="World Explorer home">
            <span class="brand-mark" aria-hidden="true">W</span>
            <span>
                <strong>World Explorer</strong>
                <small>Profile</small>
            </span>
        </a>
        <div class="page-nav-actions">
            <a class="btn btn-secondary" href="settings.php">Settings</a>
            <a class="btn btn-primary" href="index.php">Open Globe</a>
        </div>
    </nav>

    <?php if ($pageError !== ""): ?>
        <div class="page-message error"><?php echo e($pageError); ?></div>
    <?php endif; ?>

    <section class="profile-layout" aria-labelledby="profileTitle">
        <div class="profile-card">
            <div class="profile-avatar" aria-hidden="true"><?php echo e($initial); ?></div>
            <p class="eyebrow">Explorer profile</p>
            <h1 id="profileTitle"><?php echo e($user["name"]); ?></h1>
            <p><?php echo e($user["email"]); ?></p>
        </div>

        <div class="profile-details">
            <div class="profile-stat">
                <span><?php echo $visitedCount; ?></span>
                <small>Visited countries</small>
            </div>
            <div class="profile-stat">
                <span><?php echo $wishlistCount; ?></span>
                <small>Wishlist countries</small>
            </div>
            <div class="profile-info-list">
                <div>
                    <span>Name</span>
                    <strong><?php echo e($user["name"]); ?></strong>
                </div>
                <div>
                    <span>Email</span>
                    <strong><?php echo e($user["email"]); ?></strong>
                </div>
                <div>
                    <span>Status</span>
                    <strong>Active</strong>
                </div>
            </div>
            <div class="profile-actions">
                <a class="btn btn-secondary" href="logout.php">Logout</a>
                <a class="btn btn-primary" href="settings.php">Edit Settings</a>
            </div>
        </div>
    </section>
</main>
</body>
</html>

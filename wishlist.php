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
$userName = e($_SESSION["user"] ?? "Explorer");
$countries = [];
$pageError = "";

if (db_is_ready()) {
    try {
        /** @var PDO $conn */
        $stmt = $conn->prepare("SELECT country FROM wishlist WHERE user_id = ? ORDER BY country ASC");
        $stmt->execute([$_SESSION["user_id"]]);
        $countries = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $pageError = "Unable to load wishlist.";
    }
} else {
    $pageError = "Database unavailable. Check DATABASE_URL and that PostgreSQL is reachable.";
}

$count = count($countries);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f766e">
    <title>Wishlist | World Explorer</title>
    <link rel="icon" href="favicon.ico">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="apple-touch-icon" href="app-icon.svg">
    <link rel="stylesheet" href="styles.css?v=<?php echo $assetVersion; ?>">
</head>
<body class="page-shell wishlist-page" data-theme="<?php echo $themeName; ?>">
<main class="page-wrap">
    <nav class="page-nav" aria-label="Wishlist navigation">
        <a class="brand" href="index.php" aria-label="World Explorer home">
            <span class="brand-mark" aria-hidden="true">W</span>
            <span>
                <strong>World Explorer</strong>
                <small>Wishlist</small>
            </span>
        </a>
        <div class="page-nav-actions">
            <a class="btn btn-secondary" href="visited.php">Visited</a>
            <a class="btn btn-secondary" href="profile.php">Profile</a>
            <a class="btn btn-primary" href="index.php">Open Globe</a>
        </div>
    </nav>

    <?php if ($pageError !== ""): ?>
        <div class="page-message error"><?php echo e($pageError); ?></div>
    <?php endif; ?>

    <header class="settings-hero">
        <p class="eyebrow">Dream destinations</p>
        <h1>Wishlist</h1>
        <p>Countries you want to visit someday.</p>
    </header>

    <section class="profile-layout" aria-labelledby="wishlistTitle">
        <div class="profile-card">
            <div class="profile-avatar" aria-hidden="true"><?php echo $userName[0] ?? "E"; ?></div>
            <p class="eyebrow">Explorer</p>
            <h2 id="wishlistTitle"><?php echo $userName; ?></h2>
            <div class="profile-stat" style="margin-top:18px">
                <span id="wishlistCount"><?php echo $count; ?></span>
                <small>Countries on wishlist</small>
            </div>
        </div>

        <div class="profile-details" style="display:block;padding:0">
                <ul class="country-list" id="wishlistList" style="padding:18px;gap:10px;overflow:visible">
                    <?php if (empty($countries)): ?>
                        <li style="padding:16px;color:var(--muted);text-align:center" id="wishlistEmptyState">
                            <strong style="display:block;margin-bottom:4px">Your wishlist is empty</strong>
                            <span style="font-size:14px">Click a country on the globe and add it to your wishlist.</span>
                        </li>
                    <?php else: ?>
                        <?php foreach ($countries as $country): ?>
                            <li class="saved-country" data-country="<?php echo e($country); ?>">
                                <span class="country-btn" style="display:flex;align-items:center;padding:10px 12px;border-radius:8px;background:rgba(255,255,255,0.07);min-height:38px">
                                    <?php echo e($country); ?>
                                </span>
                                <button class="remove-btn" type="button" aria-label="Remove <?php echo e($country); ?> from wishlist" title="Remove">&#x2715;</button>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
        </div>
    </section>
</main>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script>
(function () {
    const list = document.getElementById('wishlistList');
    const countEl = document.getElementById('wishlistCount');
    const toast = document.getElementById('toast');
    let toastTimer;

    function showToast(msg) {
        toast.textContent = msg;
        toast.classList.add('is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 2800);
    }

    function makeItem(country) {
        const li = document.createElement('li');
        li.className = 'saved-country';
        li.dataset.country = country;
        li.innerHTML = `
            <span class="country-btn" style="display:flex;align-items:center;padding:10px 12px;border-radius:8px;background:rgba(255,255,255,0.07);min-height:38px">${country.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>
            <button class="remove-btn" type="button" aria-label="Remove ${country.replace(/"/g,'&quot;')} from wishlist" title="Remove">&#x2715;</button>`;
        return li;
    }

    // Sync countries saved in localStorage but missing from the DB
    (async function syncLocalStorage() {
        const LOCAL_KEY = 'worldExplorerWishlist';
        let local = [];
        try { local = JSON.parse(localStorage.getItem(LOCAL_KEY) || '[]'); } catch {}
        if (!Array.isArray(local) || local.length === 0) return;

        const dbCountries = new Set(
            [...document.querySelectorAll('#wishlistList .saved-country')].map(el => el.dataset.country)
        );
        const unsynced = local.filter(c => !dbCountries.has(c));
        if (unsynced.length === 0) return;

        showToast('Syncing ' + unsynced.length + ' local country' + (unsynced.length !== 1 ? 'ies' : '') + ' to your account…');

        let synced = 0;
        for (const country of unsynced) {
            try {
                const res = await fetch('save_wishlist.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ country })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    synced++;
                    const container = document.getElementById('wishlistList');
                    if (container) {
                        document.getElementById('wishlistEmptyState')?.remove();
                        container.appendChild(makeItem(country));
                    }
                    const current = parseInt(countEl?.textContent ?? '0', 10);
                    if (countEl) countEl.textContent = current + 1;
                }
            } catch {}
        }

        if (synced > 0) {
            showToast(synced + ' countr' + (synced !== 1 ? 'ies' : 'y') + ' synced to your account.');
        }
    })();

    if (list) {
        list.addEventListener('click', async function (e) {
            const btn = e.target.closest('.remove-btn');
            if (!btn) return;

            const item = btn.closest('.saved-country');
            const country = item?.dataset.country;
            if (!country) return;

            btn.disabled = true;

            try {
                const res = await fetch('remove_wishlist.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ country })
                });
                const data = await res.json();

                if (data.status === 'success') {
                    item.remove();
                    const current = parseInt(countEl?.textContent ?? '0', 10);
                    if (countEl) countEl.textContent = Math.max(0, current - 1);

                    // Also remove from localStorage
                    try {
                        const LOCAL_KEY = 'worldExplorerWishlist';
                        const local = JSON.parse(localStorage.getItem(LOCAL_KEY) || '[]');
                        localStorage.setItem(LOCAL_KEY, JSON.stringify(local.filter(c => c !== country)));
                    } catch {}

                    if (!list.querySelector('.saved-country')) {
                        list.innerHTML = '<li style="padding:16px;color:var(--muted);text-align:center">Your wishlist is empty.</li>';
                    }

                    showToast(country + ' removed from wishlist.');
                } else {
                    showToast('Could not remove country. Try again.');
                    btn.disabled = false;
                }
            } catch {
                showToast('Network error. Try again.');
                btn.disabled = false;
            }
        });
    }
})();
</script>
</body>
</html>

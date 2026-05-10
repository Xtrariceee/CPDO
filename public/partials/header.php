<?php
$user    = current_user();
$baseUrl = rtrim($config['app']['base_url'], '/');
$cpdoUrl = rtrim($config['app']['cpdo_url'] ?? str_replace('/public', '/cpdo', $baseUrl), '/');
$renteaseLogoUrl = rentease_logo_url($config);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RentEase — <?= e($config['app']['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e($baseUrl) ?>/assets/css/app.css" rel="stylesheet">
    <?php if ($renteaseLogoUrl): ?>
        <link rel="icon" type="image/png" href="<?= e($renteaseLogoUrl) ?>">
    <?php endif; ?>
    <style>
        /* ── RentEase public portal — dark gradient navbar ── */
        body {
            background: #fff;
            min-height: 100vh;
        }

        /* Navbar — matches the login page dark gradient */
        .topbar {
            background: #fff;
            border-bottom: 2px solid #f0dfad;
            box-shadow: 0 2px 16px rgba(36,27,11,.07);
        }

        /* Brand mark */
        .re-nav-mark {
            width: 38px; height: 38px; border-radius: 50%;
            background: #241b0b;
            border: 1px solid #f0dfad;
            overflow: hidden;
            display: inline-flex; align-items: center; justify-content: center;
            font-weight: 900; font-size: .8rem; color: #f6cf4a; flex-shrink: 0;
        }

        .topbar .navbar-brand { font-weight: 800; font-size: 1rem; color: #241b0b !important; }
        .topbar .nav-link { font-size: .85rem; font-weight: 600; opacity: .8; }
        .topbar .nav-link:hover { opacity: 1; }
    </style>
</head>
<body class="rental-interface">
<nav class="navbar navbar-expand-lg navbar-light topbar">
    <div class="container-fluid page-shell py-0">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e($baseUrl) ?>/index.php">
            <span class="re-nav-mark">
                <?php if ($renteaseLogoUrl): ?>
                    <img class="rentease-logo-img" src="<?= e($renteaseLogoUrl) ?>" alt="RentEase logo">
                <?php else: ?>
                    RE
                <?php endif; ?>
            </span>
            <span>RentEase</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <?php if ($user): ?>
                    <?php
                    // ── Notification bell for landlords/tenants ───────────────
                    $pubUnreadStmt = db()->prepare(
                        'SELECT id, application_id, title, message, created_at
                         FROM notifications
                         WHERE user_id = ? AND read_at IS NULL
                         ORDER BY created_at DESC
                         LIMIT 20'
                    );
                    $pubUnreadStmt->execute([(int)$user['id']]);
                    $pubUnreadNotifs = $pubUnreadStmt->fetchAll();
                    $pubUnreadCount  = count($pubUnreadNotifs);
                    $pubBaseUrl      = rtrim($config['app']['base_url'], '/');
                    ?>
                    <li class="nav-item dropdown">
                        <button
                            class="btn btn-link nav-link position-relative px-2"
                            id="pubNotifBell"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Notifications (<?= $pubUnreadCount ?> unread)"
                            style="color:#241b0b;text-decoration:none;"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                                <path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2zM8 1.918l-.797.161A4.002 4.002 0 0 0 4 6c0 .628-.134 2.197-.459 3.742-.16.767-.376 1.566-.663 2.258h10.244c-.287-.692-.502-1.49-.663-2.258C12.134 8.197 12 6.628 12 6a4.002 4.002 0 0 0-3.203-3.92L8 1.917zM14.22 12c.223.447.481.801.78 1H1c.299-.199.557-.553.78-1C2.68 10.2 3 6.88 3 6c0-2.42 1.72-4.44 4.005-4.901a1 1 0 1 1 1.99 0A5.002 5.002 0 0 1 13 6c0 .88.32 4.2 1.22 6z"/>
                            </svg>
                            <?php if ($pubUnreadCount > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                                      id="pub-notif-badge"
                                      style="font-size:.6rem;min-width:18px;padding:3px 5px;">
                                    <?= $pubUnreadCount > 99 ? '99+' : $pubUnreadCount ?>
                                </span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end shadow-lg p-0"
                             aria-labelledby="pubNotifBell"
                             style="width:340px;max-height:440px;overflow:hidden;border-radius:12px;border:1px solid #f0dfad;">
                            <div style="padding:12px 16px;background:#241b0b;border-radius:12px 12px 0 0;display:flex;align-items:center;justify-content:space-between;">
                                <span style="color:#f6cf4a;font-size:.85rem;font-weight:800;">
                                    Notifications
                                    <?php if ($pubUnreadCount > 0): ?>
                                        <span style="background:#ef4444;color:#fff;font-size:.65rem;padding:2px 7px;border-radius:999px;margin-left:6px;"><?= $pubUnreadCount ?> new</span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($pubUnreadCount > 0): ?>
                                    <button type="button" id="pub-mark-all-read"
                                            style="background:none;border:none;color:rgba(246,207,74,.7);font-size:.72rem;cursor:pointer;padding:0;">
                                        Mark all read
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div style="overflow-y:auto;max-height:360px;" id="pub-notif-list">
                                <?php if (empty($pubUnreadNotifs)): ?>
                                    <div style="padding:28px 16px;text-align:center;color:#a89562;font-size:.82rem;">
                                        No new notifications
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($pubUnreadNotifs as $pn): ?>
                                        <div class="pub-notif-item"
                                             data-notif-id="<?= (int)$pn['id'] ?>"
                                             style="padding:12px 16px;border-bottom:1px solid #f0dfad;background:#fffdf5;cursor:pointer;transition:background .15s;"
                                             <?php if ($pn['application_id']): ?>
                                                 onclick="window.location.href='<?= e($pubBaseUrl) ?>/landlord/application-show.php?id=<?= (int)$pn['application_id'] ?>'"
                                             <?php endif; ?>>
                                            <div style="display:flex;align-items:flex-start;gap:10px;">
                                                <span style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:#f6cf4a;margin-top:5px;"></span>
                                                <div style="flex:1;min-width:0;">
                                                    <div style="font-size:.8rem;font-weight:800;color:#241b0b;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                        <?= e($pn['title']) ?>
                                                    </div>
                                                    <div style="font-size:.74rem;color:#76684b;line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                                                        <?= e($pn['message']) ?>
                                                    </div>
                                                    <div style="font-size:.68rem;color:#a89562;margin-top:4px;">
                                                        <?= e(date('M d, Y · g:i A', strtotime($pn['created_at']))) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="<?= e(dashboard_for_role($user['role'])) ?>">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <span class="nav-link"><?= e(user_full_name($user)) ?> &middot; <?= e(role_label($user['role'])) ?></span>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e($baseUrl) ?>/logout.php">Logout</a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= e($baseUrl) ?>/login.php">Sign In</a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-primary btn-sm fw-bold" href="<?= e($baseUrl) ?>/register.php">Get Started</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
<main class="page-shell">
    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger"><?= e($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success"><?= e($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
    <?php endif; ?>

<?php if (!empty($user)): ?>
<script>
(function () {
    var csrfToken   = <?= json_encode(csrf_token()) ?>;
    var markReadUrl = <?= json_encode(rtrim($config['app']['base_url'], '/') . '/mark_notifications_read.php') ?>;

    function markRead(ids) {
        if (!ids || !ids.length) return;
        fetch(markReadUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ csrf_token: csrfToken, ids: ids }),
        });
    }

    document.querySelectorAll('.pub-notif-item').forEach(function (item) {
        item.addEventListener('click', function () {
            var id = parseInt(item.dataset.notifId, 10);
            markRead([id]);
            item.style.background = '#fff';
            var dot = item.querySelector('span[style*="border-radius:50%"]');
            if (dot) dot.style.background = '#d4c89a';
            var badge = document.getElementById('pub-notif-badge');
            if (badge) {
                var count = parseInt(badge.textContent, 10) - 1;
                if (count <= 0) badge.remove(); else badge.textContent = count;
            }
        });
    });

    var markAllBtn = document.getElementById('pub-mark-all-read');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var ids = Array.from(document.querySelectorAll('.pub-notif-item'))
                          .map(function (el) { return parseInt(el.dataset.notifId, 10); });
            markRead(ids);
            document.querySelectorAll('.pub-notif-item').forEach(function (item) {
                item.style.background = '#fff';
                var dot = item.querySelector('span[style*="border-radius:50%"]');
                if (dot) dot.style.background = '#d4c89a';
            });
            var badge = document.getElementById('pub-notif-badge');
            if (badge) badge.remove();
            markAllBtn.remove();
        });
    }
}());
</script>
<?php endif; ?>

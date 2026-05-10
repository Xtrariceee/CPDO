<?php
$user    = current_user();
$cpdoUrl = rtrim($config['app']['cpdo_url'] ?? str_replace('/public', '/cpdo', $config['app']['base_url']), '/');
$pubUrl  = rtrim($config['app']['base_url'], '/');
$cpdoLogoUrl = cpdo_logo_url($config);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CPDO Staff Portal — <?= e($config['app']['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e($pubUrl) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        /* ── CPDO government portal — solid authoritative theme ── */

        /* Page background: subtle dot-grid on light slate */
        body {
            background-color: #eef2f7;
            background-image: radial-gradient(circle, #c8d4e3 1px, transparent 1px);
            background-size: 28px 28px;
            min-height: 100vh;
        }

        /* Top government header bar — matches login page */
        .topbar {
            background: #0b2a4a !important;
            border-bottom: 3px solid #1d6aad;
            box-shadow: 0 2px 12px rgba(11,42,74,.25);
            padding-top: 0;
            padding-bottom: 0;
        }

        /* Seal mark */
        .gov-nav-seal {
            width: 34px; height: 34px; border-radius: 50%;
            background: #fff;
            border: 2px solid rgba(255,255,255,.25);
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .gov-nav-seal-inner {
            width: 26px; height: 26px; border-radius: 50%;
            background: linear-gradient(135deg, #0b2a4a, #1d6aad);
            display: flex; align-items: center; justify-content: center;
            font-size: .55rem; font-weight: 900; color: #fff;
            letter-spacing: .02em; text-align: center; line-height: 1.1;
        }

        /* Staff badge pill */
        .cpdo-portal-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 9px; border-radius: 4px;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.18);
            color: rgba(255,255,255,.7);
            font-size: .65rem; font-weight: 800;
            letter-spacing: .08em; text-transform: uppercase;
        }
        .cpdo-portal-badge-dot {
            width: 5px; height: 5px; border-radius: 50%;
            background: #f59e0b; flex-shrink: 0;
        }

        .topbar .navbar-brand { font-weight: 800; font-size: .95rem; }
        .topbar .nav-link { font-size: .83rem; font-weight: 600; opacity: .8; }
        .topbar .nav-link:hover { opacity: 1; }

        /* Page content area — slightly inset feel */
        .page-shell {
            background: transparent;
        }

        /* Cards in the CPDO portal get a clean white look */
        .gov-card, .gov-card-inner {
            background: #fff;
            border: 1px solid #d0dae6;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(11,42,74,.05), 0 6px 20px rgba(11,42,74,.07);
        }

        /* Override the default app.css card for CPDO portal */
        .gov-card.p-4, .gov-card.p-3 {
            background: #fff;
            border: 1px solid #d0dae6;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(11,42,74,.05), 0 6px 20px rgba(11,42,74,.07);
        }

        /* Sidebar links — government style */
        .side-panel {
            background: #fff;
            border: 1px solid #d0dae6;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(11,42,74,.06);
        }
        .side-link { color: #3a5068; font-weight: 600; }
        .side-link:hover, .side-link.active {
            background: #eef2f7;
            color: #0b2a4a;
        }

        /* Table headers — government slate */
        .table thead th {
            background: #eef2f7;
            color: #0b2a4a;
            border-bottom: 2px solid #c5d3df;
        }

        /* Metric cards */
        .metric-card {
            background: #fff;
            border: 1px solid #d0dae6;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(11,42,74,.06);
        }
        .metric-card strong { color: #0b2a4a; }

        /* Buttons — government primary is navy */
        .btn-primary {
            background: #0b2a4a;
            border-color: #0b2a4a;
            box-shadow: 0 2px 8px rgba(11,42,74,.22);
        }
        .btn-primary:hover, .btn-primary:focus {
            background: #0e3560;
            border-color: #0e3560;
            box-shadow: 0 4px 14px rgba(11,42,74,.3);
        }

        /* Activity items */
        .activity-item {
            background: #f4f8fc;
            border: 1px solid #d0dae6;
            border-radius: 6px;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark topbar">
    <div class="container-fluid page-shell py-0" style="padding-top:10px!important;padding-bottom:10px!important;">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e($cpdoUrl) ?>/index.php">
            <div class="gov-nav-seal">
                <?php if ($cpdoLogoUrl): ?>
                    <img class="cpdo-logo-img cpdo-logo-img--nav" src="<?= e($cpdoLogoUrl) ?>" alt="CPDO logo">
                <?php else: ?>
                    <div class="gov-nav-seal-inner">CPDO</div>
                <?php endif; ?>
            </div>
            <div>
                <span style="display:block;font-size:.9rem;font-weight:800;line-height:1.2;">CPDO Portal</span>
                <span style="display:block;font-size:.62rem;color:rgba(255,255,255,.5);font-weight:500;line-height:1;">City Planning &amp; Development Office</span>
            </div>
            <span class="cpdo-portal-badge ms-1">
                <span class="cpdo-portal-badge-dot"></span>
                Staff
            </span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <?php if ($user): ?>
                    <?php
                    // ── Notification bell (unread count) ──────────────────────
                    $unreadStmt = db()->prepare(
                        'SELECT id, application_id, title, message, created_at
                         FROM notifications
                         WHERE user_id = ? AND read_at IS NULL
                         ORDER BY created_at DESC
                         LIMIT 20'
                    );
                    $unreadStmt->execute([(int)$user['id']]);
                    $unreadNotifs = $unreadStmt->fetchAll();
                    $unreadCount  = count($unreadNotifs);
                    ?>
                    <li class="nav-item dropdown" id="notif-dropdown-item">
                        <button
                            class="btn btn-link nav-link position-relative px-2"
                            id="notifBell"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Notifications (<?= $unreadCount ?> unread)"
                            style="color:rgba(255,255,255,.8);text-decoration:none;"
                        >
                            <!-- Bell icon -->
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                                <path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2zM8 1.918l-.797.161A4.002 4.002 0 0 0 4 6c0 .628-.134 2.197-.459 3.742-.16.767-.376 1.566-.663 2.258h10.244c-.287-.692-.502-1.49-.663-2.258C12.134 8.197 12 6.628 12 6a4.002 4.002 0 0 0-3.203-3.92L8 1.917zM14.22 12c.223.447.481.801.78 1H1c.299-.199.557-.553.78-1C2.68 10.2 3 6.88 3 6c0-2.42 1.72-4.44 4.005-4.901a1 1 0 1 1 1.99 0A5.002 5.002 0 0 1 13 6c0 .88.32 4.2 1.22 6z"/>
                            </svg>
                            <?php if ($unreadCount > 0): ?>
                                <span
                                    class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                                    id="notif-badge"
                                    style="font-size:.6rem;min-width:18px;padding:3px 5px;"
                                >
                                    <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
                                </span>
                            <?php endif; ?>
                        </button>

                        <div
                            class="dropdown-menu dropdown-menu-end shadow-lg p-0"
                            aria-labelledby="notifBell"
                            style="width:360px;max-height:480px;overflow:hidden;border-radius:12px;border:1px solid #d0dae6;"
                        >
                            <!-- Header -->
                            <div style="padding:12px 16px;background:#0b2a4a;border-radius:12px 12px 0 0;display:flex;align-items:center;justify-content:space-between;">
                                <span style="color:#fff;font-size:.85rem;font-weight:800;">
                                    Notifications
                                    <?php if ($unreadCount > 0): ?>
                                        <span style="background:#ef4444;color:#fff;font-size:.65rem;padding:2px 7px;border-radius:999px;margin-left:6px;"><?= $unreadCount ?> new</span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($unreadCount > 0): ?>
                                    <button
                                        type="button"
                                        id="mark-all-read-btn"
                                        style="background:none;border:none;color:rgba(255,255,255,.6);font-size:.72rem;cursor:pointer;padding:0;"
                                    >
                                        Mark all read
                                    </button>
                                <?php endif; ?>
                            </div>

                            <!-- List -->
                            <div style="overflow-y:auto;max-height:380px;" id="notif-list">
                                <?php if (empty($unreadNotifs)): ?>
                                    <div style="padding:32px 16px;text-align:center;color:#62748a;font-size:.82rem;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="#c5d3df" viewBox="0 0 16 16" style="display:block;margin:0 auto 10px;" aria-hidden="true"><path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2zM8 1.918l-.797.161A4.002 4.002 0 0 0 4 6c0 .628-.134 2.197-.459 3.742-.16.767-.376 1.566-.663 2.258h10.244c-.287-.692-.502-1.49-.663-2.258C12.134 8.197 12 6.628 12 6a4.002 4.002 0 0 0-3.203-3.92L8 1.917zM14.22 12c.223.447.481.801.78 1H1c.299-.199.557-.553.78-1C2.68 10.2 3 6.88 3 6c0-2.42 1.72-4.44 4.005-4.901a1 1 0 1 1 1.99 0A5.002 5.002 0 0 1 13 6c0 .88.32 4.2 1.22 6z"/></svg>
                                        No new notifications
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($unreadNotifs as $notif): ?>
                                        <div
                                            class="notif-item"
                                            data-notif-id="<?= (int)$notif['id'] ?>"
                                            style="padding:12px 16px;border-bottom:1px solid #eef2f7;background:#f4f8fc;cursor:pointer;transition:background .15s;"
                                            <?php if ($notif['application_id']): ?>
                                                onclick="window.location.href='<?= e($cpdoUrl) ?>/zoning-officer/pre-evaluation.php?id=<?= (int)$notif['application_id'] ?>'"
                                            <?php endif; ?>
                                        >
                                            <div style="display:flex;align-items:flex-start;gap:10px;">
                                                <span style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:#ef4444;margin-top:5px;"></span>
                                                <div style="flex:1;min-width:0;">
                                                    <div style="font-size:.8rem;font-weight:800;color:#0b2a4a;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                        <?= e($notif['title']) ?>
                                                    </div>
                                                    <div style="font-size:.74rem;color:#3a5068;line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                                                        <?= e($notif['message']) ?>
                                                    </div>
                                                    <div style="font-size:.68rem;color:#8a9ab0;margin-top:4px;">
                                                        <?= e(date('M d, Y · g:i A', strtotime($notif['created_at']))) ?>
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
                        <span class="nav-link"><?= e(user_full_name($user)) ?> · <?= e(role_label($user['role'])) ?></span>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-outline-light btn-sm" href="<?= e($cpdoUrl) ?>/logout.php">Logout</a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= e($cpdoUrl) ?>/login.php">Staff Login</a>
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

<script>
(function () {
    var csrfToken = <?= json_encode(csrf_token()) ?>;
    var markReadUrl = <?= json_encode(rtrim($cpdoUrl, '/') . '/mark_notifications_read.php') ?>;

    function markRead(ids) {
        if (!ids || !ids.length) { return; }
        fetch(markReadUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ csrf_token: csrfToken, ids: ids }),
        });
    }

    // Mark individual notification read when its row is clicked
    document.querySelectorAll('.notif-item').forEach(function (item) {
        item.addEventListener('click', function () {
            var id = parseInt(item.dataset.notifId, 10);
            markRead([id]);
            item.style.background = '#fff';
            var dot = item.querySelector('span[style*="border-radius:50%"]');
            if (dot) { dot.style.background = '#c5d3df'; }
            var badge = document.getElementById('notif-badge');
            if (badge) {
                var count = parseInt(badge.textContent, 10) - 1;
                if (count <= 0) { badge.remove(); }
                else { badge.textContent = count; }
            }
        });
    });

    // Mark all read
    var markAllBtn = document.getElementById('mark-all-read-btn');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var ids = Array.from(document.querySelectorAll('.notif-item'))
                          .map(function (el) { return parseInt(el.dataset.notifId, 10); });
            markRead(ids);
            document.querySelectorAll('.notif-item').forEach(function (item) {
                item.style.background = '#fff';
                var dot = item.querySelector('span[style*="border-radius:50%"]');
                if (dot) { dot.style.background = '#c5d3df'; }
            });
            var badge = document.getElementById('notif-badge');
            if (badge) { badge.remove(); }
            markAllBtn.remove();
        });
    }
}());
</script>

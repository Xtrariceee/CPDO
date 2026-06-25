<?php
$user    = current_user();
$baseUrl = rtrim($config['app']['base_url'], '/');
$cpdoUrl = rtrim($config['app']['cpdo_url'] ?? str_replace('/public', '/cpdo', $baseUrl), '/');
$renteaseLogoUrl = rentease_logo_url($config);

// Load notifications early if user is logged in
$pubUnreadNotifs = [];
$pubUnreadCount = 0;
$pubBaseUrl = $baseUrl;
if ($user) {
    $pubUnreadStmt = db()->prepare(
        'SELECT id, application_id, rental_application_id, title, message, created_at
         FROM notifications
         WHERE user_id = ? AND read_at IS NULL
         ORDER BY created_at DESC
         LIMIT 20'
    );
    $pubUnreadStmt->execute([(int)$user['id']]);
    $pubUnreadNotifs = $pubUnreadStmt->fetchAll();
    $pubUnreadCount  = count($pubUnreadNotifs);

    // Messages unread count for sidebar badge
    $pubMsgUnreadStmt = null;
    $pubMsgUnread = 0;
    if (in_array($user['role'], [ROLE_LANDLORD, ROLE_TENANT])) {
        $msgCol = $user['role'] === ROLE_LANDLORD ? 'unread_landlord' : 'unread_tenant';
        $msgWho = $user['role'] === ROLE_LANDLORD ? 'landlord_id' : 'tenant_id';
        $pubMsgUnreadStmt = db()->prepare("SELECT COALESCE(SUM({$msgCol}),0) FROM message_threads WHERE {$msgWho} = ?");
        $pubMsgUnreadStmt->execute([(int)$user['id']]);
        $pubMsgUnread = (int)$pubMsgUnreadStmt->fetchColumn();
    }
}
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
            z-index: 1030;
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

        /* ── Design Tokens ── */
        :root {
            --color-sidebar: #faf8f5;
            --color-border: #f0dfad;
            --color-text-dark: #241b0b;
            --radius-sm: 8px;
            --radius-md: 12px;
            --color-primary: #c59000;
            --color-primary-light: #f6cf4a;
            --color-white: #ffffff;
            --transition: all 0.2s ease;
        }

        /* ── Sidebar Layout grid ── */
        .page-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar layout animation transitions */
        .sidebar {
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .content-area {
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .topbar {
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Desktop Sidebar (Fixed position) */
        @media (min-width: 992px) {
            .sidebar {
                width: 280px;
                background: linear-gradient(180deg, var(--color-sidebar) 0%, #fcfbfa 100%);
                display: flex;
                flex-direction: column;
                box-shadow: 2px 0 12px rgba(36, 27, 11, 0.08);
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                z-index: 100;
                overflow: visible; /* Allows notification dropdown to display outside sidebar */
                border-right: 1px solid var(--color-border);
            }

            body.sidebar-collapsed .sidebar {
                transform: translateX(-280px);
            }

            .content-area {
                margin-left: 280px;
                flex-grow: 1;
                display: flex;
                flex-direction: column;
                min-width: 0;
                background: #fcfbfa;
                padding-top: 80px !important;
            }

            body.sidebar-collapsed .content-area {
                margin-left: 0;
            }

            .topbar {
                margin-left: 280px;
                width: calc(100% - 280px);
            }

            body.sidebar-collapsed .topbar {
                margin-left: 0;
                width: 100%;
            }

            /* Hide logo in topbar when fixed sidebar shows it, except when collapsed */
            .topbar .navbar-brand {
                display: none !important;
            }
            body.sidebar-collapsed .topbar .navbar-brand {
                display: flex !important;
            }
        }

        .sidebar-header {
            padding: 1.5rem 1.25rem;
            background-color: var(--color-sidebar);
            border-bottom: 1px solid var(--color-border);
        }

        .logo {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--color-text-dark);
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none !important;
        }

        .search-container {
            padding: 0.75rem 1.25rem;
            border-bottom: 1px solid var(--color-border);
        }

        .search-input {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--color-border);
            font-size: 0.9rem;
            background-color: var(--color-white);
            transition: var(--transition);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(197, 144, 0, 0.15);
        }

        .sidebar-nav {
            flex: 1;
            padding: 0.75rem 0;
            overflow-y: auto;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.9rem 1.25rem;
            color: var(--color-text-dark);
            font-size: 0.95rem;
            font-weight: 600;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            border-radius: var(--radius-md);
            margin: 0.25rem 0.75rem;
            text-decoration: none;
        }

        .nav-link::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, var(--color-primary) 0%, var(--color-primary-light) 100%);
            border-radius: 0 4px 4px 0;
            transform: scaleY(0);
            transform-origin: center;
            transition: transform 0.2s ease;
        }

        .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.7);
            color: var(--color-primary);
        }

        .nav-link.active {
            background: linear-gradient(135deg, rgba(197, 144, 0, 0.12) 0%, rgba(246, 207, 74, 0.05) 100%);
            color: var(--color-primary);
            font-weight: 800;
            box-shadow: 0 1px 3px rgba(197, 144, 0, 0.1);
        }

        .nav-link.active::before {
            transform: scaleY(1);
        }

        .nav-icon {
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            transition: transform 0.2s ease;
            flex-shrink: 0;
        }

        .nav-link:hover .nav-icon,
        .nav-link.active .nav-icon {
            transform: scale(1.1);
        }

        .sidebar-footer {
            padding: 1rem 1.25rem;
            background-color: var(--color-white);
            border-top: 1px solid var(--color-border);
            margin-top: auto;
        }

        .btn-logout {
            width: 100%;
            padding: 0.6rem;
            background-color: var(--color-sidebar);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-sm);
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            margin-bottom: 0.75rem;
        }

        .btn-logout:hover {
            background-color: var(--color-border);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: var(--color-primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--color-text-dark);
            font-weight: 700;
            font-size: 0.9rem;
            border: 1px solid var(--color-border);
        }

        .user-details {
            flex: 1;
            min-width: 0;
        }

        .user-name {
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--color-text-dark);
            margin-bottom: 0.15rem;
        }

        .role-pill {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 999px;
            background-color: var(--color-primary);
            color: var(--color-white);
            font-weight: 700;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Mobile Sidebar adjustments */
        @media (max-width: 991.98px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: 280px;
                background: linear-gradient(180deg, var(--color-sidebar) 0%, #fcfbfa 100%);
                display: flex;
                flex-direction: column;
                box-shadow: 2px 0 12px rgba(36, 27, 11, 0.15);
                z-index: 1040;
                transform: translateX(-280px);
                border-right: 1px solid var(--color-border);
                overflow: visible;
            }

            body.sidebar-open .sidebar {
                transform: translateX(0);
            }

            .sidebar-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 1.5rem 1.25rem;
                background-color: var(--color-sidebar);
                border-bottom: 1px solid var(--color-border);
            }

            .sidebar-footer {
                display: block; /* Show logout and user details in drawer footer */
                padding: 1rem 1.25rem;
                background-color: var(--color-white);
                border-top: 1px solid var(--color-border);
                margin-top: auto;
            }

            .content-area {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 80px 20px 20px !important;
            }

            .topbar {
                margin-left: 0 !important;
                width: 100% !important;
            }

            .topbar .navbar-brand {
                display: flex !important;
            }
        }

        /* Overlay Backdrop */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(36, 27, 11, 0.4);
            backdrop-filter: blur(4px);
            z-index: 1035;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }
        body.sidebar-open .sidebar-overlay {
            opacity: 1;
            pointer-events: auto;
        }

        /* Floating Sidebar Toggle Button */
        .sidebar-toggle-btn {
            position: fixed;
            top: 20px;
            left: 300px; /* Positioned right outside the 280px wide sidebar */
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid var(--color-border);
            color: var(--color-text-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 20px rgba(36, 27, 11, 0.1);
            z-index: 1010; /* Above overlay backdrop, below sidebar drawer */
            transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1), transform 0.2s ease, background-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
            cursor: pointer;
            outline: none;
            padding: 0;
        }
        .sidebar-toggle-btn:hover {
            background-color: var(--color-sidebar);
            color: var(--color-primary);
            transform: scale(1.08);
            box-shadow: 0 8px 24px rgba(197, 144, 0, 0.15);
        }
        .sidebar-toggle-btn:active {
            transform: scale(0.92);
        }
        
        /* Slide toggle position left when sidebar collapsed */
        body.sidebar-collapsed .sidebar-toggle-btn {
            left: 20px;
        }

        /* Large desktop alignments to keep toggle aligned with the centered page layout */
        @media (min-width: 992px) {
            /* Open state wide screen: center of the viewport remaining space is adjusted */
            @media (min-width: 1520px) {
                .sidebar-toggle-btn {
                    left: calc(280px + (100vw - 280px - 1200px) / 2 + 20px);
                }
            }
            /* Collapsed state wide screen */
            @media (min-width: 1240px) {
                body.sidebar-collapsed .sidebar-toggle-btn {
                    left: calc((100vw - 1200px) / 2 + 20px);
                }
            }
        }

        @media (max-width: 991.98px) {
            .sidebar-toggle-btn {
                left: 20px;
                z-index: 1050; /* Float above mobile drawer (1040) so toggle remains accessible */
            }
            body.sidebar-open .sidebar-toggle-btn {
                left: 300px;
            }
        }

        /* Sleek Sidebar Nav Scrollbar */
        .sidebar-nav::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar-nav::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar-nav::-webkit-scrollbar-thumb {
            background: var(--color-border);
            border-radius: 10px;
        }
        .sidebar-nav::-webkit-scrollbar-thumb:hover {
            background: var(--color-primary);
        }
    </style>
</head>
<body class="rental-interface">
<script>
    if (window.innerWidth >= 992 && localStorage.getItem('sidebar-collapsed') === 'true') {
        document.body.classList.add('sidebar-collapsed');
    }
</script>
<?php if (!$user || !in_array($user['role'], [ROLE_LANDLORD, ROLE_TENANT])): ?>
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

        <div class="d-flex align-items-center gap-3 ms-auto">
            <?php if ($user): ?>
                <?php if (!in_array($user['role'], [ROLE_LANDLORD, ROLE_TENANT])): ?>
                    <div class="dropdown">
                        <button
                            class="btn btn-link nav-link position-relative p-1"
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
                                             <?php elseif ($pn['rental_application_id']): 
                                                 $targetPage = ($user['role'] === ROLE_LANDLORD) 
                                                     ? 'landlord/application-review.php' 
                                                     : 'tenant/application-status.php';
                                             ?>
                                                 onclick="window.location.href='<?= e($pubBaseUrl) ?>/<?= $targetPage ?>?id=<?= (int)$pn['rental_application_id'] ?>'"
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
                    </div>
                <?php endif; ?>

                <span class="text-dark small fw-semibold d-none d-sm-inline">
                    <?= e(user_full_name($user)) ?> &middot; <span class="text-secondary"><?= e(role_label($user['role'])) ?></span>
                </span>
                <?php if (!in_array($user['role'], [ROLE_LANDLORD, ROLE_TENANT])): ?>
                    <a class="btn btn-outline-secondary btn-sm px-3" href="<?= e($baseUrl) ?>/logout.php" style="border-radius: 8px;">Logout</a>
                <?php endif; ?>
            <?php else: ?>
                <a class="nav-link text-dark fw-semibold" href="<?= e($baseUrl) ?>/login.php">Sign In</a>
                <a class="btn btn-primary btn-sm fw-bold px-3" href="<?= e($baseUrl) ?>/register.php" style="border-radius: 8px;">Get Started</a>
            <?php endif; ?>
        </div>
    </div>
</nav>
<?php endif; ?>

<?php if ($user && in_array($user['role'], [ROLE_LANDLORD, ROLE_TENANT])): 
    $currentScript = basename($_SERVER['SCRIPT_NAME']);
?>
    <div class="page-wrapper">
        <aside class="sidebar">
            <div class="sidebar-header d-flex align-items-center justify-content-between">
                <a class="logo" href="<?= e($baseUrl) ?>/index.php">
                    <span class="re-nav-mark" style="width:30px; height:30px; font-size:.7rem;">RE</span>
                    RentEase
                </a>
                
                <!-- Notification bell for landlords/tenants -->
                <div class="dropdown">
                    <button
                        class="btn btn-link nav-link position-relative p-1"
                        id="pubNotifBell"
                        data-bs-toggle="dropdown"
                        data-bs-boundary="viewport"
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
                    <div class="dropdown-menu dropdown-menu-start shadow-lg p-0"
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
                                         <?php elseif ($pn['rental_application_id']): 
                                             $targetPage = ($user['role'] === ROLE_LANDLORD) 
                                                 ? 'landlord/application-review.php' 
                                                 : 'tenant/application-status.php';
                                         ?>
                                             onclick="window.location.href='<?= e($pubBaseUrl) ?>/<?= $targetPage ?>?id=<?= (int)$pn['rental_application_id'] ?>'"
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
                </div>
            </div>
            
            <div class="search-container">
                <input type="text" class="search-input" id="sidebar-search" placeholder="Search menu...">
            </div>
            
            <nav class="sidebar-nav">
                <?php if ($user['role'] === ROLE_LANDLORD): ?>
                    <a class="nav-link <?= $currentScript === 'dashboard.php' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/landlord/dashboard.php">
                        <span class="nav-icon">📊</span> Dashboard
                    </a>
                    <a class="nav-link" href="<?= e($baseUrl) ?>/landlord/dashboard.php#listings">
                        <span class="nav-icon">🏠</span> Properties &amp; Units
                    </a>
                    <a class="nav-link <?= $currentScript === 'applications.php' || $currentScript === 'application-review.php' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/landlord/applications.php">
                        <span class="nav-icon">📩</span> Screening &amp; Apps
                    </a>
                    <a class="nav-link <?= $currentScript === 'tenant-registry.php' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/landlord/tenant-registry.php">
                        <span class="nav-icon">👥</span> Tenant Registry
                    </a>
                    <a class="nav-link <?= $currentScript === 'billing.php' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/landlord/billing.php">
                        <span class="nav-icon">💵</span> Billing &amp; Financials
                    </a>
                    <a class="nav-link <?= $currentScript === 'messages.php' || $currentScript === 'broadcast.php' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/landlord/messages.php">
                        <span class="nav-icon">💬</span> Messages
                        <?php if ($pubMsgUnread > 0): ?>
                            <span class="badge bg-danger ms-auto rounded-pill" style="font-size:0.7rem; padding:3px 6px;"><?= $pubMsgUnread > 99 ? '99+' : $pubMsgUnread ?></span>
                        <?php endif; ?>
                    </a>
                    <a class="nav-link <?= $currentScript === 'compliance-log.php' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/landlord/compliance-log.php">
                        <span class="nav-icon">🛡️</span> Compliance Log
                    </a>
                    <a class="nav-link <?= $currentScript === 'cpdo-applications.php' || $currentScript === 'application-form.php' || $currentScript === 'application-show.php' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/landlord/cpdo-applications.php">
                        <span class="nav-icon">🏢</span> CPDO Applications
                    </a>
                    <a class="nav-link <?= $currentScript === 'compliance-uploads.php' || $currentScript === 'compliance-gateway.php' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/landlord/compliance-uploads.php">
                        <span class="nav-icon">📤</span> Compliance Uploads
                    </a>

                <?php else: // ROLE_TENANT ?>
                    <a class="nav-link <?= $currentScript === 'dashboard.php' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/tenant/dashboard.php">
                        <span class="nav-icon">📊</span> Dashboard
                    </a>
                    <?php
                    // Check if tenant has an active lease
                    $activeLeaseStmt = db()->prepare('SELECT id FROM leases WHERE tenant_id = ? AND status = "active" LIMIT 1');
                    $activeLeaseStmt->execute([(int)$user['id']]);
                    $hasActiveLease = (bool)$activeLeaseStmt->fetch();
                    if (!$hasActiveLease):
                        // Find the tenant's application if they have one to link to it
                        $hasAppStmt = db()->prepare('SELECT id FROM rental_applications WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 1');
                        $hasAppStmt->execute([(int)$user['id']]);
                        $hasApp = $hasAppStmt->fetch();
                        $appLink = $hasApp ? $baseUrl . '/tenant/application-status.php?id=' . (int)$hasApp['id'] : $baseUrl . '/tenant/dashboard.php';
                    ?>
                        <a class="nav-link <?= $currentScript === 'application-status.php' ? 'active' : '' ?>" href="<?= e($appLink) ?>">
                            <span class="nav-icon">⏱️</span> Application Status
                        </a>
                    <?php endif; ?>
                    <?php
                    $pendingLeaseCount = 0;
                    if ($user) {
                        $chkPendingLease = db()->prepare('SELECT COUNT(*) FROM leases WHERE tenant_id = ? AND status = "pending_signature"');
                        $chkPendingLease->execute([(int)$user['id']]);
                        $pendingLeaseCount = (int)$chkPendingLease->fetchColumn();
                    }
                    ?>
                    <a class="nav-link <?= $currentScript === 'lease-documents.php' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/tenant/lease-documents.php">
                        <span class="nav-icon">📄</span> Lease &amp; Documents
                        <?php if ($pendingLeaseCount > 0): ?>
                            <span class="badge bg-danger ms-auto rounded-pill" style="font-size:0.7rem; padding: 3px 6px;">New</span>
                        <?php endif; ?>
                    </a>
                    <a class="nav-link <?= $currentScript === 'payment-hub.php' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/tenant/payment-hub.php">
                        <span class="nav-icon">💳</span> Payment Hub
                    </a>
                    <a class="nav-link <?= $currentScript === 'infractions-alerts.php' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/tenant/infractions-alerts.php">
                        <span class="nav-icon">⚠️</span> Infractions &amp; Alerts
                    </a>
                    <a class="nav-link <?= $currentScript === 'messages.php' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/tenant/messages.php">
                        <span class="nav-icon">💬</span> Messages
                        <?php if ($pubMsgUnread > 0): ?>
                            <span class="badge bg-danger ms-auto rounded-pill" style="font-size:0.7rem; padding:3px 6px;"><?= $pubMsgUnread > 99 ? '99+' : $pubMsgUnread ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-footer">
                <button onclick="window.location.href='<?= e($baseUrl) ?>/logout.php'" class="btn-logout">
                    Logout
                </button>
                <div class="user-info">
                    <div class="user-avatar">
                        <?= mb_strtoupper(mb_substr($user['first_name'], 0, 1) . mb_substr($user['last_name'], 0, 1)) ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name text-truncate"><?= e(user_full_name($user)) ?></div>
                        <span class="role-pill"><?= $user['role'] === ROLE_LANDLORD ? 'Landlord' : 'Resident' ?></span>
                    </div>
                </div>
            </div>
        </aside>

        <main class="content-area">
            <div class="page-shell">
            <button class="sidebar-toggle-btn" id="sidebarToggle" aria-label="Toggle Sidebar">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                    <path fill-rule="evenodd" d="M2.5 12a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5z"/>
                </svg>
            </button>
<?php else: ?>
    <main class="page-shell">
<?php endif; ?>
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
            document.querySelectorAll('#pub-notif-badge').forEach(function(badge) {
                var count = parseInt(badge.textContent, 10) - 1;
                if (count <= 0) badge.remove(); else badge.textContent = count;
            });
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
            document.querySelectorAll('#pub-notif-badge').forEach(function(badge) {
                badge.remove();
            });
            markAllBtn.remove();
        });
    }

    // Interactive Menu Search Filter
    var searchInput = document.getElementById('sidebar-search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var query = searchInput.value.toLowerCase();
            document.querySelectorAll('.sidebar-nav .nav-link').forEach(function(link) {
                var text = link.textContent.toLowerCase();
                if (text.includes(query)) {
                    link.style.display = 'flex';
                } else {
                    link.style.display = 'none';
                }
            });
        });
    }

    // Sidebar Toggle Functionality
    var sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function (e) {
            e.preventDefault();
            if (window.innerWidth >= 992) {
                document.body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('sidebar-collapsed', document.body.classList.contains('sidebar-collapsed'));
            } else {
                document.body.classList.toggle('sidebar-open');
            }
        });
    }
}());
</script>
<?php endif; ?>

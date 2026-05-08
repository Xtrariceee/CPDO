<?php
require_once __DIR__ . '/../../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_SYSTEM_ADMIN]);
verify_csrf();

$roles = [ROLE_SYSTEM_ADMIN, ROLE_ZONING, ROLE_ADMIN_OFFICER, ROLE_TWG, ROLE_LANDLORD, ROLE_TENANT, ROLE_PENDING_STAFF];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'create') {
        $pdo  = db();
        $stmt = $pdo->prepare(
            'INSERT INTO users (first_name, middle_name, last_name, email, password_hash, role, status, is_verified)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            trim($_POST['first_name']),
            trim($_POST['middle_name']) ?: null,
            trim($_POST['last_name']),
            strtolower(trim($_POST['email'])),
            password_hash($_POST['password'] ?? '12345678', PASSWORD_BCRYPT),
            $_POST['role'],
            $_POST['status'],
        ]);
        audit_log((int)$user['id'], 'ADMIN_USER_CREATED', 'users', (int)$pdo->lastInsertId());
        $_SESSION['flash_success'] = 'User created.';
    }

    if ($action === 'update' && $userId > 0) {
        db()->prepare('UPDATE users SET first_name=?,middle_name=?,last_name=?,email=?,role=?,status=? WHERE id=?')
           ->execute([trim($_POST['first_name']), trim($_POST['middle_name']) ?: null,
                      trim($_POST['last_name']), strtolower(trim($_POST['email'])),
                      $_POST['role'], $_POST['status'], $userId]);
        audit_log((int)$user['id'], 'ADMIN_USER_UPDATED', 'users', $userId);
        $_SESSION['flash_success'] = 'User updated.';
    }

    if ($action === 'disable' && $userId > 0 && $userId !== (int)$user['id']) {
        db()->prepare('UPDATE users SET status="DISABLED" WHERE id=?')->execute([$userId]);
        audit_log((int)$user['id'], 'ADMIN_USER_DISABLED', 'users', $userId);
        $_SESSION['flash_success'] = 'Account disabled.';
    }

    if ($action === 'enable' && $userId > 0) {
        db()->prepare('UPDATE users SET status="ACTIVE" WHERE id=?')->execute([$userId]);
        audit_log((int)$user['id'], 'ADMIN_USER_ENABLED', 'users', $userId);
        $_SESSION['flash_success'] = 'Account re-enabled.';
    }

    if (in_array($action, ['approve_upgrade', 'reject_upgrade'], true)) {
        $requestId  = (int)($_POST['request_id'] ?? 0);
        $adminNotes = trim($_POST['admin_notes'] ?? '');
        $reqStmt    = db()->prepare('SELECT * FROM role_upgrade_requests WHERE id=? AND status="PENDING"');
        $reqStmt->execute([$requestId]);
        $req = $reqStmt->fetch();
        if ($req) {
            if ($action === 'approve_upgrade') {
                db()->prepare('UPDATE users SET role=? WHERE id=?')->execute([$req['to_role'], (int)$req['user_id']]);
                db()->prepare('UPDATE role_upgrade_requests SET status="APPROVED",reviewed_by=?,reviewed_at=NOW(),admin_notes=? WHERE id=?')
                   ->execute([(int)$user['id'], $adminNotes ?: null, $requestId]);
                notify_user((int)$req['user_id'], null, 'Role Upgrade Approved',
                    'Your request to become a Landlord has been approved. Please log out and log back in.');
                audit_log((int)$user['id'], 'ROLE_UPGRADE_APPROVED', 'role_upgrade_requests', $requestId);
                $_SESSION['flash_success'] = 'Upgrade approved.';
            } else {
                db()->prepare('UPDATE role_upgrade_requests SET status="REJECTED",reviewed_by=?,reviewed_at=NOW(),admin_notes=? WHERE id=?')
                   ->execute([(int)$user['id'], $adminNotes ?: null, $requestId]);
                notify_user((int)$req['user_id'], null, 'Role Upgrade Rejected',
                    'Your request was not approved.' . ($adminNotes ? ' Note: '.$adminNotes : ''));
                audit_log((int)$user['id'], 'ROLE_UPGRADE_REJECTED', 'role_upgrade_requests', $requestId);
                $_SESSION['flash_success'] = 'Upgrade request rejected.';
            }
        }
    }

    // ── Staff designation request review ────────────────────────────────────
    if (in_array($action, ['approve_designation', 'reject_designation'], true)) {
        $requestId  = (int)($_POST['request_id'] ?? 0);
        $adminNotes = trim($_POST['admin_notes'] ?? '');
        $reqStmt    = db()->prepare('SELECT * FROM staff_designation_requests WHERE id=? AND status="PENDING"');
        $reqStmt->execute([$requestId]);
        $req = $reqStmt->fetch();
        if ($req) {
            if ($action === 'approve_designation') {
                // Promote the user to their requested role
                db()->prepare('UPDATE users SET role=? WHERE id=?')
                   ->execute([$req['requested_role'], (int)$req['user_id']]);
                db()->prepare(
                    'UPDATE staff_designation_requests
                     SET status="APPROVED", reviewed_by=?, reviewed_at=NOW(), admin_notes=?
                     WHERE id=?'
                )->execute([(int)$user['id'], $adminNotes ?: null, $requestId]);
                notify_user((int)$req['user_id'], null, 'Designation Approved',
                    'Your designation as ' . role_label($req['requested_role']) . ' has been approved. Sign out and back in to activate access.');
                audit_log((int)$user['id'], 'DESIGNATION_APPROVED', 'staff_designation_requests', $requestId,
                    ['role' => $req['requested_role']]);
                $_SESSION['flash_success'] = 'Designation approved — user promoted to ' . role_label($req['requested_role']) . '.';
            } else {
                db()->prepare(
                    'UPDATE staff_designation_requests
                     SET status="REJECTED", reviewed_by=?, reviewed_at=NOW(), admin_notes=?
                     WHERE id=?'
                )->execute([(int)$user['id'], $adminNotes ?: null, $requestId]);
                notify_user((int)$req['user_id'], null, 'Designation Request Rejected',
                    'Your designation request was not approved.' . ($adminNotes ? ' Note: ' . $adminNotes : ''));
                audit_log((int)$user['id'], 'DESIGNATION_REJECTED', 'staff_designation_requests', $requestId);
                $_SESSION['flash_success'] = 'Designation request rejected.';
            }
        }
    }

    redirect('admin/users/');
}

$users = db()->query(
    'SELECT * FROM users ORDER BY CASE status WHEN "ACTIVE" THEN 0 ELSE 1 END, role, last_name, first_name'
)->fetchAll();

$pendingUpgrades = db()->query(
    'SELECT r.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS full_name, u.email
     FROM role_upgrade_requests r JOIN users u ON u.id=r.user_id
     WHERE r.status="PENDING" ORDER BY r.created_at ASC'
)->fetchAll();

$pendingDesignations = db()->query(
    'SELECT d.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS full_name, u.email
     FROM staff_designation_requests d JOIN users u ON u.id=d.user_id
     WHERE d.status="PENDING" ORDER BY d.created_at ASC'
)->fetchAll();

require __DIR__ . '/../../partials/header.php';
?>
<div class="layout-grid">
    <aside class="side-panel">
        <a class="side-link" href="../dashboard/">Overview</a>
        <a class="side-link active" href="../users/">Users</a>
        <a class="side-link" href="../applications/">Applications</a>
        <a class="side-link" href="../audit/">Audit Logs</a>
    </aside>
    <section>
        <h1 class="h3 mb-3">Manage Users</h1>

        <?php if ($pendingUpgrades): ?>
        <div class="gov-card p-4 mb-4">
            <h2 class="h5 mb-3 d-flex align-items-center gap-2">
                Role Upgrade Requests
                <span class="badge text-bg-warning"><?= count($pendingUpgrades) ?> pending</span>
            </h2>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>User</th><th>Email</th><th>From → To</th><th>Reason</th><th>Requested</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($pendingUpgrades as $req): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($req['full_name']) ?></td>
                            <td><?= e($req['email']) ?></td>
                            <td>
                                <span class="status-pill"><?= e(role_label($req['from_role'])) ?></span>
                                → <span class="status-pill status-approved"><?= e(role_label($req['to_role'])) ?></span>
                            </td>
                            <td class="text-secondary small"><?= $req['reason'] ? e($req['reason']) : '<em>No reason given</em>' ?></td>
                            <td class="text-secondary small"><?= e($req['created_at']) ?></td>
                            <td><button type="button" class="btn btn-sm btn-success" data-modal-target="upgrade-modal-<?= (int)$req['id'] ?>">Review</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($pendingDesignations): ?>
        <div class="gov-card p-4 mb-4">
            <h2 class="h5 mb-3 d-flex align-items-center gap-2">
                Staff Designation Requests
                <span class="badge text-bg-danger"><?= count($pendingDesignations) ?> pending</span>
            </h2>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>Name</th><th>Email</th><th>Requested Role</th><th>Submitted</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($pendingDesignations as $dreq): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($dreq['full_name']) ?></td>
                            <td><?= e($dreq['email']) ?></td>
                            <td><span class="status-pill"><?= e(role_label($dreq['requested_role'])) ?></span></td>
                            <td class="text-secondary small"><?= e($dreq['created_at']) ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary"
                                        data-modal-target="desig-modal-<?= (int)$dreq['id'] ?>">Review</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="gov-card p-4 mb-4">
            <h2 class="h5 mb-3">Add New User</h2>            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2"><label class="form-label">First Name</label><input class="form-control" name="first_name" required placeholder="First"></div>
                    <div class="col-md-2"><label class="form-label">Middle Name</label><input class="form-control" name="middle_name" placeholder="Middle"></div>
                    <div class="col-md-2"><label class="form-label">Last Name</label><input class="form-control" name="last_name" required placeholder="Last"></div>
                    <div class="col-md-2"><label class="form-label">Email</label><input class="form-control" type="email" name="email" required></div>
                    <div class="col-md-2"><label class="form-label">Password</label><input class="form-control" name="password" value="12345678" required></div>
                    <div class="col-md-1">
                        <label class="form-label">Role</label>
                        <select class="form-select" name="role">
                            <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>"><?= e(role_label($r)) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status"><option>ACTIVE</option><option>DISABLED</option></select>
                    </div>
                    <div class="col-md-auto"><button class="btn btn-primary">Add User</button></div>
                </div>
            </form>
        </div>

        <div class="gov-card p-4">
            <h2 class="h5 mb-3">All Users</h2>
            <div class="table-responsive">
                <table class="table align-middle" style="min-width:900px;">
                    <thead>
                        <tr>
                            <th style="min-width:110px;">First Name</th>
                            <th style="min-width:110px;">Middle Name</th>
                            <th style="min-width:110px;">Last Name</th>
                            <th style="min-width:180px;">Email</th>
                            <th style="min-width:160px;">Role</th>
                            <th style="min-width:120px;">Status</th>
                            <th style="min-width:130px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $shownDisabled = false; foreach ($users as $row): ?>
                        <?php if (!$shownDisabled && $row['status'] === 'DISABLED'): $shownDisabled = true; ?>
                        <tr><td colspan="7" class="py-2 px-3" style="background:#f8f0f0;border-top:2px solid #f5c6c6;">
                            <span style="font-size:var(--text-xs);font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#c0392b;">⬇ Disabled Accounts</span>
                        </td></tr>
                        <?php endif; ?>
                        <tr <?= $row['status'] === 'DISABLED' ? 'style="opacity:.65;"' : '' ?>>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                                <td><input class="form-control form-control-sm" name="first_name" value="<?= e($row['first_name']) ?>" style="min-width:100px;"></td>
                                <td><input class="form-control form-control-sm" name="middle_name" value="<?= e($row['middle_name']) ?>" style="min-width:100px;"></td>
                                <td><input class="form-control form-control-sm" name="last_name" value="<?= e($row['last_name']) ?>" style="min-width:100px;"></td>
                                <td><input class="form-control form-control-sm" name="email" type="email" value="<?= e($row['email']) ?>" style="min-width:170px;"></td>
                                <td>
                                    <select class="form-select form-select-sm" name="role" style="min-width:150px;">
                                        <?php foreach ($roles as $r): ?>
                                            <option value="<?= e($r) ?>" <?= $row['role']===$r?'selected':'' ?>><?= e(role_label($r)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><?= $row['status']==='ACTIVE' ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Disabled</span>' ?></td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-primary" name="action" value="update">Save</button>
                                        <?php if ((int)$row['id'] !== (int)$user['id']): ?>
                                            <?php if ($row['status']==='ACTIVE'): ?>
                                                <button class="btn btn-sm btn-outline-warning" name="action" value="disable"
                                                        onclick="return confirm('Disable this account?')">Disable</button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-success" name="action" value="enable">Re-enable</button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </form>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<?php foreach ($pendingUpgrades as $req): ?>
<div class="modal-backdrop" id="upgrade-modal-<?= (int)$req['id'] ?>" role="dialog" aria-modal="true">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-header">
            <h3 class="h6 mb-0">Review Upgrade Request</h3>
            <button class="preview-modal-close" data-modal-close>&times;</button>
        </div>
        <div class="modal-body">
            <div class="d-flex align-items-start gap-3 mb-4 p-3" style="background:#f8fbff;border-radius:var(--radius-md);border:1px solid var(--cpdo-border);">
                <div style="width:40px;height:40px;border-radius:50%;background:var(--cpdo-navy);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:.9rem;flex-shrink:0;">
                    <?= mb_strtoupper(mb_substr($req['full_name'], 0, 1)) ?>
                </div>
                <div>
                    <div class="fw-bold"><?= e($req['full_name']) ?></div>
                    <div class="small text-secondary"><?= e($req['email']) ?></div>
                    <div class="mt-1">
                        <span class="status-pill"><?= e(role_label($req['from_role'])) ?></span>
                        <span class="mx-1 text-secondary">→</span>
                        <span class="status-pill status-approved"><?= e(role_label($req['to_role'])) ?></span>
                    </div>
                </div>
            </div>
            <?php if ($req['reason']): ?>
            <div class="mb-3">
                <div class="form-label mb-1">Reason from user</div>
                <div class="p-3" style="background:#fffbeb;border-radius:var(--radius-sm);border-left:3px solid var(--cpdo-amber);font-size:var(--text-sm);"><?= e($req['reason']) ?></div>
            </div>
            <?php endif; ?>
            <div class="mb-4">
                <label class="form-label" for="admin-notes-<?= (int)$req['id'] ?>">Admin Notes <span class="text-secondary fw-normal">(optional)</span></label>
                <textarea class="form-control" id="admin-notes-<?= (int)$req['id'] ?>" rows="2" placeholder="Reason for approval or rejection…"></textarea>
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-modal-close>Cancel</button>
                <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="reject_upgrade">
                    <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                    <input type="hidden" name="admin_notes" id="reject-notes-<?= (int)$req['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="syncNotes(<?= (int)$req['id'] ?>)">Reject</button>
                </form>
                <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="approve_upgrade">
                    <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                    <input type="hidden" name="admin_notes" id="approve-notes-<?= (int)$req['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success" onclick="syncNotes(<?= (int)$req['id'] ?>)">Approve</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php foreach ($pendingDesignations as $dreq): ?>
<div class="modal-backdrop" id="desig-modal-<?= (int)$dreq['id'] ?>" role="dialog" aria-modal="true">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-header">
            <h3 class="h6 mb-0">Review Designation Request</h3>
            <button class="preview-modal-close" data-modal-close>&times;</button>
        </div>
        <div class="modal-body">
            <div class="d-flex align-items-start gap-3 mb-4 p-3" style="background:#f8fbff;border-radius:var(--radius-md);border:1px solid var(--cpdo-border);">
                <div style="width:40px;height:40px;border-radius:50%;background:var(--cpdo-navy);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:.9rem;flex-shrink:0;">
                    <?= mb_strtoupper(mb_substr($dreq['full_name'], 0, 1)) ?>
                </div>
                <div>
                    <div class="fw-bold"><?= e($dreq['full_name']) ?></div>
                    <div class="small text-secondary"><?= e($dreq['email']) ?></div>
                    <div class="mt-1">
                        <span class="status-pill">Pending Staff</span>
                        <span class="mx-1 text-secondary">→</span>
                        <span class="status-pill status-approved"><?= e(role_label($dreq['requested_role'])) ?></span>
                    </div>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label" for="desig-notes-<?= (int)$dreq['id'] ?>">Admin Notes <span class="text-secondary fw-normal">(optional)</span></label>
                <textarea class="form-control" id="desig-notes-<?= (int)$dreq['id'] ?>" rows="2" placeholder="Reason for approval or rejection…"></textarea>
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-modal-close>Cancel</button>
                <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="reject_designation">
                    <input type="hidden" name="request_id" value="<?= (int)$dreq['id'] ?>">
                    <input type="hidden" name="admin_notes" id="desig-reject-notes-<?= (int)$dreq['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"
                            onclick="syncDesigNotes(<?= (int)$dreq['id'] ?>)">Reject</button>
                </form>
                <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="approve_designation">
                    <input type="hidden" name="request_id" value="<?= (int)$dreq['id'] ?>">
                    <input type="hidden" name="admin_notes" id="desig-approve-notes-<?= (int)$dreq['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success"
                            onclick="syncDesigNotes(<?= (int)$dreq['id'] ?>)">Approve &amp; Assign Role</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<script>
function syncNotes(id){var val=(document.getElementById('admin-notes-'+id)||{}).value||'';var rn=document.getElementById('reject-notes-'+id);var an=document.getElementById('approve-notes-'+id);if(rn)rn.value=val;if(an)an.value=val;}
function syncDesigNotes(id){var val=(document.getElementById('desig-notes-'+id)||{}).value||'';var rn=document.getElementById('desig-reject-notes-'+id);var an=document.getElementById('desig-approve-notes-'+id);if(rn)rn.value=val;if(an)an.value=val;}
</script>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
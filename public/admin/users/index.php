<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
$user = require_role([ROLE_SYSTEM_ADMIN]);
verify_csrf();

$roles = [ROLE_SYSTEM_ADMIN, ROLE_ZONING, ROLE_ADMIN_OFFICER, ROLE_TWG, ROLE_LANDLORD, ROLE_TENANT];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'create') {
        $password = $_POST['password'] ?? '12345678';
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
            password_hash($password, PASSWORD_BCRYPT),
            $_POST['role'],
            $_POST['status'],
        ]);
        audit_log((int)$user['id'], 'ADMIN_USER_CREATED', 'users', (int)$pdo->lastInsertId());
        $_SESSION['flash_success'] = 'User created.';
    }

    if ($action === 'update' && $userId > 0) {
        $stmt = db()->prepare(
            'UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ?, role = ?, status = ? WHERE id = ?'
        );
        $stmt->execute([
            trim($_POST['first_name']),
            trim($_POST['middle_name']) ?: null,
            trim($_POST['last_name']),
            strtolower(trim($_POST['email'])),
            $_POST['role'],
            $_POST['status'],
            $userId,
        ]);
        audit_log((int)$user['id'], 'ADMIN_USER_UPDATED', 'users', $userId);
        $_SESSION['flash_success'] = 'User updated.';
    }

    if ($action === 'delete' && $userId > 0 && $userId !== (int)$user['id']) {
        try {
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            audit_log((int)$user['id'], 'ADMIN_USER_DELETED', 'users', $userId);
            $_SESSION['flash_success'] = 'User deleted.';
        } catch (PDOException $exception) {
            db()->prepare('UPDATE users SET status = "DISABLED" WHERE id = ?')->execute([$userId]);
            audit_log((int)$user['id'], 'ADMIN_USER_LOCKED_DUE_TO_LINKED_RECORDS', 'users', $userId);
            $_SESSION['flash_success'] = 'User has linked records — account locked instead of removed.';
        }
    }

    // Handle role upgrade request review
    if (in_array($action, ['approve_upgrade', 'reject_upgrade'], true)) {
        $requestId  = (int)($_POST['request_id'] ?? 0);
        $adminNotes = trim($_POST['admin_notes'] ?? '');

        $reqStmt = db()->prepare('SELECT * FROM role_upgrade_requests WHERE id = ? AND status = "PENDING"');
        $reqStmt->execute([$requestId]);
        $req = $reqStmt->fetch();

        if ($req) {
            if ($action === 'approve_upgrade') {
                db()->prepare('UPDATE users SET role = ? WHERE id = ?')
                   ->execute([$req['to_role'], (int)$req['user_id']]);
                db()->prepare(
                    'UPDATE role_upgrade_requests SET status = "APPROVED", reviewed_by = ?, reviewed_at = NOW(), admin_notes = ? WHERE id = ?'
                )->execute([(int)$user['id'], $adminNotes ?: null, $requestId]);

                notify_user((int)$req['user_id'], null,
                    'Role Upgrade Approved',
                    'Your request to become a Landlord has been approved. Please log out and log back in to access your new dashboard.'
                );
                audit_log((int)$user['id'], 'ROLE_UPGRADE_APPROVED', 'role_upgrade_requests', $requestId, [
                    'target_user' => $req['user_id'],
                    'new_role'    => $req['to_role'],
                ]);
                $_SESSION['flash_success'] = 'Upgrade approved. User role changed to Landlord.';
            } else {
                db()->prepare(
                    'UPDATE role_upgrade_requests SET status = "REJECTED", reviewed_by = ?, reviewed_at = NOW(), admin_notes = ? WHERE id = ?'
                )->execute([(int)$user['id'], $adminNotes ?: null, $requestId]);

                notify_user((int)$req['user_id'], null,
                    'Role Upgrade Rejected',
                    'Your request to become a Landlord was not approved.' . ($adminNotes ? ' Note: ' . $adminNotes : '')
                );
                audit_log((int)$user['id'], 'ROLE_UPGRADE_REJECTED', 'role_upgrade_requests', $requestId);
                $_SESSION['flash_success'] = 'Upgrade request rejected.';
            }
        }
    }

    redirect('admin/users/');
}

$users = db()->query('SELECT * FROM users ORDER BY role, last_name, first_name')->fetchAll();

// Pending upgrade requests
$pendingUpgrades = db()->query(
    'SELECT r.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS full_name, u.email
     FROM role_upgrade_requests r
     JOIN users u ON u.id = r.user_id
     WHERE r.status = "PENDING"
     ORDER BY r.created_at ASC'
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

        <!-- ── Pending Upgrade Requests ── -->
        <?php if ($pendingUpgrades): ?>
        <div class="gov-card p-4 mb-4">
            <h2 class="h5 mb-3 d-flex align-items-center gap-2">
                Role Upgrade Requests
                <span class="badge text-bg-warning"><?= count($pendingUpgrades) ?> pending</span>
            </h2>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>From → To</th>
                            <th>Reason</th>
                            <th>Requested</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingUpgrades as $req): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($req['full_name']) ?></td>
                            <td><?= e($req['email']) ?></td>
                            <td>
                                <span class="status-pill"><?= e(role_label($req['from_role'])) ?></span>
                                → <span class="status-pill status-approved"><?= e(role_label($req['to_role'])) ?></span>
                            </td>
                            <td class="text-secondary small" style="max-width:200px;">
                                <?= $req['reason'] ? e($req['reason']) : '<em>No reason given</em>' ?>
                            </td>
                            <td class="text-secondary small"><?= e($req['created_at']) ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-success"
                                        data-modal-target="upgrade-modal-<?= (int)$req['id'] ?>">
                                    Review
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Create User ── -->
        <div class="gov-card p-4 mb-4">
            <h2 class="h5 mb-3">Add New User</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label">First Name</label>
                        <input class="form-control" name="first_name" required placeholder="First">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Middle Name</label>
                        <input class="form-control" name="middle_name" placeholder="Middle">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Last Name</label>
                        <input class="form-control" name="last_name" required placeholder="Last">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Email</label>
                        <input class="form-control" type="email" name="email" required placeholder="email@example.com">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Password</label>
                        <input class="form-control" name="password" value="12345678" required>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Role</label>
                        <select class="form-select" name="role">
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= e($role) ?>"><?= e(role_label($role)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option>ACTIVE</option>
                            <option>DISABLED</option>
                        </select>
                    </div>
                    <div class="col-md-auto">
                        <button class="btn btn-primary">Add User</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- ── User List ── -->
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
                    <?php foreach ($users as $row): ?>
                        <tr>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                                <td>
                                    <input class="form-control form-control-sm" name="first_name"
                                           value="<?= e($row['first_name']) ?>" style="min-width:100px;">
                                </td>
                                <td>
                                    <input class="form-control form-control-sm" name="middle_name"
                                           value="<?= e($row['middle_name']) ?>" style="min-width:100px;">
                                </td>
                                <td>
                                    <input class="form-control form-control-sm" name="last_name"
                                           value="<?= e($row['last_name']) ?>" style="min-width:100px;">
                                </td>
                                <td>
                                    <input class="form-control form-control-sm" name="email" type="email"
                                           value="<?= e($row['email']) ?>" style="min-width:170px;">
                                </td>
                                <td>
                                    <select class="form-select form-select-sm" name="role" style="min-width:150px;">
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?= e($role) ?>" <?= $row['role'] === $role ? 'selected' : '' ?>>
                                                <?= e(role_label($role)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select class="form-select form-select-sm" name="status" style="min-width:110px;">
                                        <option <?= $row['status'] === 'ACTIVE'   ? 'selected' : '' ?>>ACTIVE</option>
                                        <option <?= $row['status'] === 'DISABLED' ? 'selected' : '' ?>>DISABLED</option>
                                    </select>
                                </td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-primary" name="action" value="update">Save</button>
                                        <?php if ((int)$row['id'] !== (int)$user['id']): ?>
                                            <button class="btn btn-sm btn-outline-danger" name="action" value="delete"
                                                    onclick="return confirm('Delete this user?')">Delete</button>
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

<!-- ══════════════════════════════════════════════════════════
     Review modals — rendered OUTSIDE the table so position:fixed works
     ══════════════════════════════════════════════════════════ -->
<?php foreach ($pendingUpgrades as $req): ?>
<div class="modal-backdrop" id="upgrade-modal-<?= (int)$req['id'] ?>"
     role="dialog" aria-modal="true"
     aria-labelledby="upgrade-modal-title-<?= (int)$req['id'] ?>">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-header">
            <h3 class="h6 mb-0" id="upgrade-modal-title-<?= (int)$req['id'] ?>">
                Review Upgrade Request
            </h3>
            <button class="preview-modal-close" data-modal-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <!-- User summary -->
            <div class="d-flex align-items-start gap-3 mb-4 p-3"
                 style="background:#f8fbff;border-radius:var(--radius-md);border:1px solid var(--cpdo-border);">
                <div style="width:40px;height:40px;border-radius:50%;background:var(--cpdo-navy);
                            color:#fff;display:flex;align-items:center;justify-content:center;
                            font-weight:900;font-size:.9rem;flex-shrink:0;">
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
                <div class="p-3" style="background:#fffbeb;border-radius:var(--radius-sm);
                                        border-left:3px solid var(--cpdo-amber);font-size:var(--text-sm);">
                    <?= e($req['reason']) ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="mb-4">
                <label class="form-label" for="admin-notes-<?= (int)$req['id'] ?>">
                    Admin Notes <span class="text-secondary fw-normal">(optional — sent to user)</span>
                </label>
                <textarea class="form-control" id="admin-notes-<?= (int)$req['id'] ?>"
                          rows="2" placeholder="Reason for approval or rejection…"></textarea>
            </div>

            <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-modal-close>
                    Cancel
                </button>
                <!-- Reject -->
                <form method="post" class="d-inline" id="reject-form-<?= (int)$req['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="reject_upgrade">
                    <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                    <input type="hidden" name="admin_notes" id="reject-notes-<?= (int)$req['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"
                            onclick="syncNotes(<?= (int)$req['id'] ?>)">
                        Reject
                    </button>
                </form>
                <!-- Approve -->
                <form method="post" class="d-inline" id="approve-form-<?= (int)$req['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="approve_upgrade">
                    <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                    <input type="hidden" name="admin_notes" id="approve-notes-<?= (int)$req['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success"
                            onclick="syncNotes(<?= (int)$req['id'] ?>)">
                        Approve
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
function syncNotes(id) {
    var notes = document.getElementById('admin-notes-' + id);
    var val   = notes ? notes.value : '';
    var rn    = document.getElementById('reject-notes-'  + id);
    var an    = document.getElementById('approve-notes-' + id);
    if (rn) rn.value = val;
    if (an) an.value = val;
}
</script>

<?php require __DIR__ . '/../../partials/footer.php'; ?>

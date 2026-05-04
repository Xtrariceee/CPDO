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
        $stmt = db()->prepare('INSERT INTO users (first_name, middle_name, last_name, email, password_hash, role, status, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
        $stmt->execute([
            trim($_POST['first_name']),
            trim($_POST['middle_name']) ?: null,
            trim($_POST['last_name']),
            strtolower(trim($_POST['email'])),
            password_hash($password, PASSWORD_BCRYPT),
            $_POST['role'],
            $_POST['status'],
        ]);
        audit_log((int)$user['id'], 'ADMIN_USER_CREATED', 'users', (int)db()->lastInsertId());
        $_SESSION['flash_success'] = 'User created.';
    }

    if ($action === 'update' && $userId > 0) {
        $stmt = db()->prepare('UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ?, role = ?, status = ? WHERE id = ?');
        $stmt->execute([trim($_POST['first_name']), trim($_POST['middle_name']) ?: null, trim($_POST['last_name']), strtolower(trim($_POST['email'])), $_POST['role'], $_POST['status'], $userId]);
        audit_log((int)$user['id'], 'ADMIN_USER_UPDATED', 'users', $userId);
        $_SESSION['flash_success'] = 'User updated.';
    }

    if ($action === 'delete' && $userId > 0 && $userId !== (int)$user['id']) {
        try {
            $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            audit_log((int)$user['id'], 'ADMIN_USER_DELETED', 'users', $userId);
            $_SESSION['flash_success'] = 'User deleted.';
        } catch (PDOException $exception) {
            $stmt = db()->prepare('UPDATE users SET status = "DISABLED" WHERE id = ?');
            $stmt->execute([$userId]);
            audit_log((int)$user['id'], 'ADMIN_USER_LOCKED_DUE_TO_LINKED_RECORDS', 'users', $userId);
            $_SESSION['flash_success'] = 'User has linked records, so the account was locked instead of removed.';
        }
    }

    redirect('admin/users/');
}

$users = db()->query('SELECT * FROM users ORDER BY role, last_name, first_name')->fetchAll();

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
        <form class="gov-card p-4 mb-4" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <div class="row g-3 align-items-end">
                <div class="col-md-2"><label class="form-label">First</label><input class="form-control" name="first_name" required></div>
                <div class="col-md-2"><label class="form-label">Middle</label><input class="form-control" name="middle_name"></div>
                <div class="col-md-2"><label class="form-label">Last</label><input class="form-control" name="last_name" required></div>
                <div class="col-md-2"><label class="form-label">Email</label><input class="form-control" type="email" name="email" required></div>
                <div class="col-md-2"><label class="form-label">Password</label><input class="form-control" name="password" value="12345678" required></div>
                <div class="col-md-1"><label class="form-label">Role</label><select class="form-select" name="role"><?php foreach ($roles as $role): ?><option value="<?= e($role) ?>"><?= e(role_label($role)) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-1"><label class="form-label">Status</label><select class="form-select" name="status"><option>ACTIVE</option><option>DISABLED</option></select></div>
                <div class="col-md-1"><button class="btn btn-primary w-100">Add</button></div>
            </div>
        </form>
        <div class="gov-card p-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>First</th><th>Middle</th><th>Last</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $row): ?>
                        <tr>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                                <td><input class="form-control form-control-sm" name="first_name" value="<?= e($row['first_name']) ?>"></td>
                                <td><input class="form-control form-control-sm" name="middle_name" value="<?= e($row['middle_name']) ?>"></td>
                                <td><input class="form-control form-control-sm" name="last_name" value="<?= e($row['last_name']) ?>"></td>
                                <td><input class="form-control form-control-sm" name="email" value="<?= e($row['email']) ?>"></td>
                                <td><select class="form-select form-select-sm" name="role"><?php foreach ($roles as $role): ?><option value="<?= e($role) ?>" <?= $row['role'] === $role ? 'selected' : '' ?>><?= e(role_label($role)) ?></option><?php endforeach; ?></select></td>
                                <td><select class="form-select form-select-sm" name="status"><option <?= $row['status'] === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option><option <?= $row['status'] === 'DISABLED' ? 'selected' : '' ?>>DISABLED</option></select></td>
                                <td class="d-flex gap-2">
                                    <button class="btn btn-sm btn-primary" name="action" value="update">Save</button>
                                    <button class="btn btn-sm btn-outline-danger" name="action" value="delete">Delete</button>
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
<?php require __DIR__ . '/../../partials/footer.php'; ?>

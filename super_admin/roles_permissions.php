<?php
// super_admin/roles_permissions.php — Super Admin only: manage admin roles.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require '../config.php';
require __DIR__ . '/auth.php';

$errors  = [];
$success = '';

/* ── Handle role change ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role'])) {
    csrf_verify();
    $targetId  = (int)($_POST['admin_id'] ?? 0);
    $newRole   = $_POST['role'] === 'super_admin' ? 'super_admin' : 'admin';

    // Safety check: don't allow demoting the last remaining Super Admin
    $countStmt = $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin'");
    $superAdminCount = (int)$countStmt->fetchColumn();

    $currentStmt = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
    $currentStmt->execute([$targetId]);
    $currentRole = $currentStmt->fetchColumn();

    if ($currentRole === 'super_admin' && $newRole === 'admin' && $superAdminCount <= 1) {
        $errors[] = 'Cannot demote the last remaining Super Admin.';
    } else {
        $stmt = $pdo->prepare("UPDATE admins SET role = ? WHERE id = ?");
        $stmt->execute([$newRole, $targetId]);
        $success = 'Role updated.';
    }
}

/* ── Handle new admin creation ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_admin'])) {
    csrf_verify();
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $newRole  = $_POST['new_role'] === 'super_admin' ? 'super_admin' : 'admin';

    if ($fullname === '' || $username === '' || $password === '') {
        $errors[] = 'Please fill in all fields.';
    } else {
        $check = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            $errors[] = 'That username is already taken.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO admins (fullname, username, password, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([$fullname, $username, $hash, $newRole]);
            $success = 'New admin account created.';
        }
    }
}

$admins = $pdo->query("SELECT id, fullname, username, role FROM admins ORDER BY role = 'super_admin' DESC, fullname ASC")->fetchAll(PDO::FETCH_ASSOC);

$activePage = 'roles_permissions';
$pageTitle  = 'Roles & Permissions';
include '_layout_top.php';
?>

<style>
  .rp-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:24px; margin-bottom:20px; }
  .rp-title { font-size:18px; font-weight:800; color:#0f172a; margin-bottom:16px; }
  .rp-table { width:100%; border-collapse:collapse; }
  .rp-table th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#94a3b8; padding:8px 10px; border-bottom:2px solid #f1f5f9; }
  .rp-table td { padding:10px; border-bottom:1px solid #f1f5f9; font-size:14px; vertical-align: middle; }
  .rp-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:700; }
  .rp-badge.super { background:rgba(245,158,11,.15); color:#b45309; }
  .rp-badge.admin { background:rgba(37,99,235,.12); color:#1d4ed8; }
  .rp-btn { padding:7px 16px; border-radius:8px; border:none; background:#0f172a; color:#fff; font-weight:700; font-size:13px; cursor:pointer; }
  .rp-btn:hover { background:#1e293b; }
  .rp-alert { padding:11px 14px; border-radius:10px; font-size:13px; margin-bottom:18px; }
  .rp-alert.err { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; }
  .rp-alert.ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; }
  .rp-field { margin-bottom:14px; }
  .rp-field label { display:block; font-size:12px; font-weight:700; color:#374151; margin-bottom:5px; }
  .rp-field input, .rp-field select { width:100%; padding:10px 12px; border-radius:8px; border:1.5px solid #e2e8f0; font-family:inherit; font-size:14px; }

  /* Actions kebab menu, LeadSquared-style */
  .rp-kebab {
    width:32px; height:32px; border-radius:8px; border:1px solid #e2e8f0;
    background:#fff; color:#64748b; font-size:16px; line-height:1; cursor:pointer;
  }
  .rp-kebab:hover { background:#f8fafc; border-color:#cbd5e1; }
  .dropdown-item.text-danger:hover { background:#fef2f2; }
</style>

<?php foreach ($errors as $e): ?>
  <div class="rp-alert err">⚠ <?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
<?php if ($success): ?>
  <div class="rp-alert ok">✓ <?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="rp-card">
  <div class="rp-title">Admins &amp; Roles</div>
  <table class="rp-table">
    <thead>
      <tr><th>Name</th><th>Username</th><th>Current Role</th><th style="width:70px;">Actions</th></tr>
    </thead>
    <tbody>
      <?php foreach ($admins as $a): ?>
      <tr>
        <td><?= htmlspecialchars($a['fullname']) ?></td>
        <td><?= htmlspecialchars($a['username']) ?></td>
        <td>
          <span class="rp-badge <?= $a['role'] === 'super_admin' ? 'super' : 'admin' ?>">
            <?= $a['role'] === 'super_admin' ? 'Super Admin' : 'Admin' ?>
          </span>
        </td>
        <td>
          <div class="dropdown">
            <button class="rp-kebab" type="button" data-bs-toggle="dropdown" aria-expanded="false">⋮</button>
            <ul class="dropdown-menu dropdown-menu-end">
              <?php if ($a['role'] !== 'super_admin'): ?>
              <li>
                <form method="post" onsubmit="return confirm('Promote <?= htmlspecialchars(addslashes($a['fullname'])) ?> to Super Admin?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
                  <input type="hidden" name="role" value="super_admin">
                  <button type="submit" name="update_role" class="dropdown-item">Set as Super Admin</button>
                </form>
              </li>
              <?php else: ?>
              <li>
                <form method="post" onsubmit="return confirm('Set <?= htmlspecialchars(addslashes($a['fullname'])) ?> back to regular Admin?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
                  <input type="hidden" name="role" value="admin">
                  <button type="submit" name="update_role" class="dropdown-item text-danger">Set as Admin</button>
                </form>
              </li>
              <?php endif; ?>
            </ul>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="rp-card">
  <div class="rp-title">Create New Admin</div>
  <form method="post" style="max-width:420px;">
    <?= csrf_field() ?>
    <div class="rp-field">
      <label>Full Name</label>
      <input type="text" name="fullname" required>
    </div>
    <div class="rp-field">
      <label>Username</label>
      <input type="text" name="username" required>
    </div>
    <div class="rp-field">
      <label>Password</label>
      <input type="password" name="password" required>
    </div>
    <div class="rp-field">
      <label>Role</label>
      <select name="new_role">
        <option value="admin">Admin</option>
        <option value="super_admin">Super Admin</option>
      </select>
    </div>
    <button type="submit" name="create_admin" class="rp-btn">Create Admin</button>
  </form>
</div>

<?php include '_layout_bottom.php'; ?>
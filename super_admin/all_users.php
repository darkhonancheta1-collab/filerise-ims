<?php
// super_admin/all_users.php — Super Admin only: view every Admin and Staff account.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require '../config.php';
require '../branch_modules.php';
require __DIR__ . '/auth.php';

$admins = $pdo->query("
    SELECT id, fullname, username, role FROM admins
    ORDER BY role = 'super_admin' DESC, fullname ASC
")->fetchAll(PDO::FETCH_ASSOC);

$staff = $pdo->query("
    SELECT id, fullname, username, branch_name FROM users
    ORDER BY branch_name ASC, fullname ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* -- What each admin role can do -- */
$rolePermissions = [
    'super_admin' => ['Manage Admins & Roles', 'Branch Module Locks', 'All Branches & Reports', 'AI Forecast'],
    'admin'       => ['View All Branches', 'Reports', 'AI Forecast'],
];

/* -- What each staff member can do: every module NOT locked for their branch -- */
$allModules = fr_module_list();
function fr_staff_permissions(PDO $pdo, array $allModules, string $branchName): array {
    if ($branchName === '') return array_values($allModules);
    $locked = fr_locked_modules($pdo, $branchName);
    $allowed = [];
    foreach ($allModules as $key => $label) {
        if (!isset($locked[$key])) $allowed[] = $label;
    }
    return $allowed;
}

$activePage = 'all_users';
$pageTitle  = 'All Users';
include '_layout_top.php';
?>

<style>
  .au-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:24px; margin-bottom:20px; }
  .au-title { font-size:18px; font-weight:800; color:#0f172a; margin-bottom:4px; }
  .au-sub { font-size:13px; color:#94a3b8; margin-bottom:16px; }
  .au-table { width:100%; border-collapse:collapse; }
  .au-table th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#94a3b8; padding:8px 10px; border-bottom:2px solid #f1f5f9; }
  .au-table td { padding:10px; border-bottom:1px solid #f1f5f9; font-size:14px; vertical-align: middle; }
  .au-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:700; }
  .au-badge.super { background:rgba(220,38,38,.12); color:#b91c1c; }
  .au-badge.admin { background:rgba(37,99,235,.12); color:#1d4ed8; }
  .au-badge.staff { background:rgba(16,185,129,.12); color:#047857; }
  .au-search { padding:9px 14px; border-radius:8px; border:1.5px solid #e2e8f0; font-family:inherit; font-size:13px; width:220px; margin-bottom:14px; }

  /* Permission tags */
  .au-perms { display:flex; flex-wrap:wrap; gap:5px; max-width:340px; }
  .au-perm-tag { display:inline-block; padding:2px 9px; border-radius:6px; font-size:11px; font-weight:600; background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; white-space:nowrap; }
  .au-perm-tag.full { background:rgba(220,38,38,.08); color:#b91c1c; border-color:rgba(220,38,38,.2); }
  .au-perm-none { font-size:12px; color:#cbd5e1; font-style:italic; }

  /* Actions kebab menu, LeadSquared-style */
  .au-kebab {
    width:32px; height:32px; border-radius:8px; border:1px solid #e2e8f0;
    background:#fff; color:#64748b; font-size:16px; line-height:1; cursor:pointer;
  }
  .au-kebab:hover { background:#f8fafc; border-color:#cbd5e1; }
  .dropdown-item.text-danger:hover { background:#fef2f2; }
</style>

<div class="au-card">
  <div class="au-title">Admins &amp; Super Admins</div>
  <div class="au-sub"><?= count($admins) ?> account(s)</div>
  <input class="au-search" type="text" id="adminSearch" placeholder="Search admins..." onkeyup="fr_filterTable('adminSearch','adminTable')">
  <table class="au-table" id="adminTable">
    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Permissions</th><th style="width:70px;">Actions</th></tr></thead>
    <tbody>
      <?php foreach ($admins as $a): ?>
      <tr>
        <td><?= htmlspecialchars($a['fullname']) ?></td>
        <td><?= htmlspecialchars($a['username']) ?></td>
        <td>
          <span class="au-badge <?= $a['role'] === 'super_admin' ? 'super' : 'admin' ?>">
            <?= $a['role'] === 'super_admin' ? 'Super Admin' : 'Admin' ?>
          </span>
        </td>
        <td>
          <div class="au-perms">
            <?php foreach ($rolePermissions[$a['role']] ?? [] as $perm): ?>
              <span class="au-perm-tag <?= $a['role'] === 'super_admin' ? 'full' : '' ?>"><?= htmlspecialchars($perm) ?></span>
            <?php endforeach; ?>
          </div>
        </td>
        <td>
          <div class="dropdown">
            <button class="au-kebab" type="button" data-bs-toggle="dropdown" aria-expanded="false">⋮</button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="roles_permissions.php">Manage role</a></li>
              <?php if ($a['role'] !== 'super_admin'): ?>
              <li>
                <form method="post" action="roles_permissions.php" onsubmit="return confirm('Promote <?= htmlspecialchars(addslashes($a['fullname'])) ?> to Super Admin?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
                  <input type="hidden" name="role" value="super_admin">
                  <button type="submit" name="update_role" class="dropdown-item">Set as Super Admin</button>
                </form>
              </li>
              <?php else: ?>
              <li>
                <form method="post" action="roles_permissions.php" onsubmit="return confirm('Set <?= htmlspecialchars(addslashes($a['fullname'])) ?> back to regular Admin?');">
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
      <?php if (empty($admins)): ?>
      <tr><td colspan="5" style="color:#94a3b8;">No admin accounts found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="au-card">
  <div class="au-title">Branch Staff</div>
  <div class="au-sub"><?= count($staff) ?> account(s)</div>
  <input class="au-search" type="text" id="staffSearch" placeholder="Search staff..." onkeyup="fr_filterTable('staffSearch','staffTable')">
  <table class="au-table" id="staffTable">
    <thead><tr><th>Name</th><th>Username</th><th>Branch</th><th>Permissions</th><th style="width:70px;">Actions</th></tr></thead>
    <tbody>
      <?php foreach ($staff as $s): ?>
      <?php $staffPerms = fr_staff_permissions($pdo, $allModules, $s['branch_name'] ?? ''); ?>
      <tr>
        <td><?= htmlspecialchars($s['fullname']) ?></td>
        <td><?= htmlspecialchars($s['username']) ?></td>
        <td><span class="au-badge staff"><?= htmlspecialchars($s['branch_name'] ?: '—') ?></span></td>
        <td>
          <?php if (count($staffPerms) === count($allModules)): ?>
            <div class="au-perms"><span class="au-perm-tag">All Modules</span></div>
          <?php elseif (empty($staffPerms)): ?>
            <span class="au-perm-none">No modules enabled</span>
          <?php else: ?>
            <div class="au-perms">
              <?php foreach ($staffPerms as $perm): ?>
                <span class="au-perm-tag"><?= htmlspecialchars($perm) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!empty($s['branch_name'])): ?>
          <div class="dropdown">
            <button class="au-kebab" type="button" data-bs-toggle="dropdown" aria-expanded="false">⋮</button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="branch_modules.php?branch=<?= urlencode($s['branch_name']) ?>">Manage permissions</a></li>
            </ul>
          </div>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($staff)): ?>
      <tr><td colspan="5" style="color:#94a3b8;">No staff accounts found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
function fr_filterTable(inputId, tableId) {
  const q = document.getElementById(inputId).value.toLowerCase();
  const rows = document.getElementById(tableId).querySelectorAll('tbody tr');
  rows.forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}
</script>

<?php include '_layout_bottom.php'; ?>
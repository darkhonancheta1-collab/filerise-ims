<?php
// super_admin/super_admin_dashboard.php — Super Admin only landing page.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require '../config.php';
require __DIR__ . '/auth.php';

$totalSuperAdmins = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin'")->fetchColumn();
$totalAdmins      = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role <> 'super_admin' OR role IS NULL")->fetchColumn();
$totalStaff       = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalBranches    = (int)$pdo->query("SELECT COUNT(DISTINCT branch_name) FROM users WHERE branch_name IS NOT NULL AND branch_name <> ''")->fetchColumn();

$lockedCount = 0;
try {
    $lockedCount = (int)$pdo->query("SELECT COUNT(*) FROM branch_module_locks WHERE is_locked = 1")->fetchColumn();
} catch (Exception $e) {}

$activePage = 'super_admin_dashboard';
$pageTitle  = 'Super Admin Dashboard';
include '_layout_top.php';
?>

<style>
  .sad-hero { background: linear-gradient(135deg, #1c0f0f, #2a1414); border-radius: 16px; padding: 28px 32px; margin-bottom: 24px; color: #fff; }
  .sad-hero-eyebrow { font-size: 11px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: #fca5a5; margin-bottom: 8px; }
  .sad-hero-title { font-size: 24px; font-weight: 800; }
  .sad-hero-sub { font-size: 13px; color: #cbb0af; margin-top: 6px; }

  .sad-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 28px; }
  .sad-stat { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px; }
  .sad-stat-num { font-size: 28px; font-weight: 800; color: #0f172a; }
  .sad-stat-label { font-size: 12px; color: #94a3b8; font-weight: 600; margin-top: 4px; }

  .sad-actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
  .sad-action-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px;
    text-decoration: none; display: block; transition: .15s;
  }
  .sad-action-card:hover { border-color: #dc2626; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,.06); }
  .sad-action-icon { font-size: 22px; margin-bottom: 10px; }
  .sad-action-title { font-size: 15px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
  .sad-action-desc { font-size: 12.5px; color: #94a3b8; }
</style>

<div class="sad-hero">
  <div class="sad-hero-eyebrow">🔒 Super Admin</div>
  <div class="sad-hero-title">Welcome back, <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Super Admin') ?></div>
  <div class="sad-hero-sub">You have full oversight of admins, staff, roles, and branch module access.</div>
</div>

<div class="sad-grid">
  <div class="sad-stat">
    <div class="sad-stat-num"><?= $totalSuperAdmins ?></div>
    <div class="sad-stat-label">Super Admins</div>
  </div>
  <div class="sad-stat">
    <div class="sad-stat-num"><?= $totalAdmins ?></div>
    <div class="sad-stat-label">Admins</div>
  </div>
  <div class="sad-stat">
    <div class="sad-stat-num"><?= $totalStaff ?></div>
    <div class="sad-stat-label">Staff Accounts</div>
  </div>
  <div class="sad-stat">
    <div class="sad-stat-num"><?= $totalBranches ?></div>
    <div class="sad-stat-label">Branches</div>
  </div>
  <div class="sad-stat">
    <div class="sad-stat-num"><?= $lockedCount ?></div>
    <div class="sad-stat-label">Locked Modules (all branches)</div>
  </div>
</div>

<div class="sad-actions">
  <a class="sad-action-card" href="roles_permissions.php">
    <div class="sad-action-icon">🛡️</div>
    <div class="sad-action-title">Roles &amp; Permissions</div>
    <div class="sad-action-desc">Promote/demote admins, create new admin accounts.</div>
  </a>
  <a class="sad-action-card" href="branch_modules.php">
    <div class="sad-action-icon">🔧</div>
    <div class="sad-action-title">Branch Modules</div>
    <div class="sad-action-desc">Lock or unlock features per branch.</div>
  </a>
  <a class="sad-action-card" href="all_users.php">
    <div class="sad-action-icon">👥</div>
    <div class="sad-action-title">All Users</div>
    <div class="sad-action-desc">View every Admin and Staff account across the system.</div>
  </a>
</div>

<?php include '_layout_bottom.php'; ?>
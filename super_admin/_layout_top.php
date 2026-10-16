<?php
/**
 * super_admin/_layout_top.php — Super Admin Shared Header & Sidebar Navigation
 *
 * Provides shared layout HTML head, Poppins fonts, Bootstrap assets, and
 * the dedicated Super Admin sidebar with system configuration navigation.
 */
require __DIR__ . "/auth.php";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($pageTitle ?? "Super Admin") ?> - FIL-RISE</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="../7.png">

  <style>
    :root {
      --accent: #dc2626;
      --accent-soft: #fef2f2;
      --accent-border: #fecaca;
      --bg: #f7f7f8;
      --card: #ffffff;
      --border: #e4e4e7;
      --text: #18181b;
      --muted: #71717a;
      --radius: 10px;
    }

    * { box-sizing: border-box; }

    body {
      font-family: 'Poppins', sans-serif;
      background: var(--bg);
      color: var(--text);
      margin: 0;
    }

    /* SIDEBAR - plain white, hairline border, no gradients */
    .sidebar {
      position: fixed;
      top: 0;
      left: 0;
      bottom: 0;
      width: 240px;
      padding: 24px 16px;
      background: var(--card);
      border-right: 1px solid var(--border);
      z-index: 1000;
      overflow-y: auto;
    }

    .brand { font-weight: 700; letter-spacing: .2px; font-size: 14px; color: var(--text); margin-bottom: 10px; }
    .brand-badge {
      display: inline-flex; align-items: center; gap: 6px;
      background: var(--accent-soft); border: 1px solid var(--accent-border);
      color: var(--accent); font-size: 10px; font-weight: 700;
      letter-spacing: .6px; text-transform: uppercase;
      padding: 4px 10px; border-radius: 999px; margin-bottom: 20px;
    }

    .navlink {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 12px; color: var(--muted); text-decoration: none;
      border-radius: 8px; margin: 2px 0; font-size: 14px;
      border-left: 3px solid transparent;
    }
    .navlink:hover  { background: #f4f4f5; color: var(--text); }
    .navlink.active { background: var(--accent-soft); color: var(--accent); border-left-color: var(--accent); font-weight: 600; }

    .sidebox {
      margin-top: 20px; padding: 12px;
      border-top: 1px solid var(--border);
    }
    .sidebox-label { font-size: 11px; color: var(--muted); margin-top: 12px; }
    .sidebox-name  { font-weight: 700; font-size: 14px; color: var(--text); }

    .logoutbtn {
      margin-top: 12px; display: inline-block; width: 100%;
      padding: 9px 12px; text-align: center; border-radius: 8px;
      text-decoration: none; color: var(--muted);
      border: 1px solid var(--border);
      font-size: 13px;
    }
    .logoutbtn:hover { border-color: var(--accent); color: var(--accent); }

    .main { margin-left: 240px; padding: 28px 32px; min-height: 100vh; }

    .topbar {
      display: flex; gap: 12px; align-items: center;
      justify-content: space-between; flex-wrap: wrap; margin-bottom: 20px;
    }
    .topbar h3 { font-weight: 700; }

    /* CARDS - flat, hairline border, no shadow */
    .cardx {
      background: var(--card);
      border-radius: var(--radius);
      border: 1px solid var(--border);
    }

    .kpi-title { color: var(--muted); font-size: 12px; font-weight: 600; letter-spacing: .3px; }
    .kpi-value  { font-size: 24px; font-weight: 700; margin-top: 4px; color: var(--text); }

    .pill {
      background: var(--accent-soft); border: 1px solid var(--accent-border); color: var(--accent);
      font-weight: 600; border-radius: 999px; padding: 7px 12px;
      font-size: 12px; white-space: nowrap;
    }

    .table thead th { font-size: 12px; color: var(--text); font-weight: 600; border-bottom: 1px solid var(--border); }
    .table tbody td { font-size: 13px; border-bottom: 1px solid var(--border); }
    .table { border-collapse: collapse; }

    .btn-primary, .btn-dark {
      background: var(--accent) !important;
      border-color: var(--accent) !important;
      border-radius: 8px !important;
      box-shadow: none !important;
    }
    .btn-primary:hover, .btn-dark:hover { background: #b91c1c !important; border-color: #b91c1c !important; }
    .btn-outline-secondary { border-radius: 8px !important; }

    .mobile-header { display: none; }

    @media (max-width:992px) {
      .mobile-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 16px;
        height: 52px;
        background: var(--card);
        border-bottom: 1px solid var(--border);
        color: var(--text);
        font-weight: 700;
        position: fixed;
        top: 0; left: 0; right: 0;
        z-index: 99998;
      }
      .mobile-header span { font-size: 14px; font-weight: 700; }
      .mobile-header button {
        border: 1px solid var(--border);
        background: var(--card);
        border-radius: 8px;
        width: 36px; height: 36px;
        color: var(--text);
        font-size: 16px;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
      }
      .sidebar {
        position: fixed;
        left: -240px;
        width: 240px;
        height: 100%;
        transition: .2s;
        z-index: 99999;
        top: 0;
      }
      .sidebar.open { left: 0; }
      .main {
        margin-left: 0 !important;
        margin-top: 52px !important;
        padding: 16px !important;
        width: 100% !important;
        box-sizing: border-box !important;
        overflow: visible !important;
      }
      html, body { overflow-x: hidden !important; max-width: 100vw !important; }
    }

    #overlay { position:fixed; inset:0; background:rgba(0,0,0,.4); display:none; z-index:99997; }
    #overlay.show { display:block; }
  </style>
</head>
<body>

<style>
.sidebar { top: 0 !important; height: 100vh !important; overflow-y: auto; }
</style>

<div id="overlay" onclick="closeMenu()"></div>

<div class="sidebar" id="sidebar">
  <div class="brand">FIL-RISE · SUPER ADMIN</div>
  <div class="brand-badge">Restricted Access</div>

  <a class="navlink <?= ($activePage??'') === 'super_admin_dashboard' ? 'active' : '' ?>" href="super_admin_dashboard.php">Dashboard</a>
  <a class="navlink <?= ($activePage??'') === 'all_users'             ? 'active' : '' ?>" href="all_users.php">All Users</a>
  <a class="navlink <?= ($activePage??'') === 'roles_permissions'     ? 'active' : '' ?>" href="roles_permissions.php">Roles &amp; Permissions</a>
  <a class="navlink <?= ($activePage??'') === 'branch_modules'        ? 'active' : '' ?>" href="branch_modules.php">Branch Modules</a>
  <a class="navlink <?= ($activePage??'') === 'activity_log'          ? 'active' : '' ?>" href="activity_log.php">Activity Log</a>

  <div class="sidebox">
    <div class="sidebox-label">Logged in as</div>
    <div class="sidebox-name"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Super Admin') ?></div>
    <a class="logoutbtn" href="../admin/logout.php">Logout</a>
  </div>
</div>

<div class="mobile-header d-lg-none">
  <span>FIL-RISE · SUPER ADMIN</span>
  <button onclick="openMenu()">☰</button>
</div>

<div class="main">

<script>
function openMenu()  {
  document.getElementById("sidebar").classList.add("open");
  document.getElementById("overlay").classList.add("show");
  document.body.style.overflow = 'hidden';
}
function closeMenu() {
  document.getElementById("sidebar").classList.remove("open");
  document.getElementById("overlay").classList.remove("show");
  document.body.style.overflow = '';
}
window.addEventListener('resize', function() {
  if (window.innerWidth > 992) {
    closeMenu();
    const main = document.querySelector('.main');
    if (main) main.style.marginTop = '';
  }
});
</script>
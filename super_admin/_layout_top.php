<?php
// super_admin/_layout_top.php
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
      --nav: #1c0f0f;
      --nav2: #2a1414;
      --primary: #dc2626;
      --primary2: #f59e0b;
      --bg: #f4f6fb;
      --card: #ffffff;
      --muted: #6b7280;
      --shadow: 0 10px 25px rgba(15,23,42,.08);
      --radius: 18px;
    }

    body {
      font-family: 'Poppins', sans-serif;
      background: var(--bg);
      margin: 0;
    }

    .sidebar {
      position: fixed;
      top: 0;
      left: 0;
      bottom: 0;
      width: 260px;
      padding: 22px 18px;
      background: linear-gradient(180deg, var(--nav), var(--nav2));
      color: #fff;
      z-index: 1000;
      overflow-y: auto;
    }

    .brand { display:flex; align-items:center; gap:8px; font-weight:800; letter-spacing:.5px; font-size:14px; opacity:.95; margin-bottom: 4px; }
    .brand-badge {
      display:inline-flex; align-items:center; gap:6px;
      background: rgba(220,38,38,.18); border: 1px solid rgba(220,38,38,.35);
      color: #fca5a5; font-size: 10px; font-weight: 700;
      letter-spacing: 1px; text-transform: uppercase;
      padding: 4px 10px; border-radius: 999px; margin-bottom: 18px;
    }

    .navlink {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px; color: #cbb0af; text-decoration: none;
      border-radius: 12px; margin: 6px 0; transition: .2s; font-size: 14px;
    }
    .navlink:hover  { background: rgba(220,38,38,.15); color: #fff; }
    .navlink.active { background: linear-gradient(90deg,var(--primary),var(--primary2)); color: #fff; }

    .sidebox {
      margin-top: 18px; padding: 12px;
      background: rgba(255,255,255,.06);
      border-radius: 14px; border: 1px solid rgba(255,255,255,.06);
    }

    .logoutbtn {
      margin-top: 14px; display: inline-block; width: 100%;
      padding: 10px 12px; text-align: center; border-radius: 12px;
      text-decoration: none; color: #fff;
      background: rgba(239,68,68,.18); border: 1px solid rgba(239,68,68,.25);
      transition: .2s; font-size: 14px;
    }
    .logoutbtn:hover { background: rgba(239,68,68,.35); }

    .main { margin-left: 260px; padding: 26px 22px; min-height: 100vh; }

    .topbar {
      display: flex; gap: 12px; align-items: center;
      justify-content: space-between; flex-wrap: wrap; margin-bottom: 18px;
    }

    .cardx {
      background: var(--card); border-radius: var(--radius);
      box-shadow: var(--shadow); border: 1px solid rgba(15,23,42,.06);
    }

    .kpi-title { color: var(--muted); font-size:12px; font-weight:600; letter-spacing:.3px; }
    .kpi-value  { font-size:26px; font-weight:800; margin-top:4px; }

    .pill {
      background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;
      font-weight: 700; border-radius: 999px; padding: 8px 12px;
      font-size: 12px; white-space: nowrap;
    }

    .table thead th { font-size:12px; color:#111827; }
    .table tbody td { font-size:13px; }

    .mobile-header { display: none; }

    @media (max-width:992px) {
      .mobile-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 16px;
        height: 56px;
        background: linear-gradient(135deg, var(--nav), var(--nav2));
        color: #fff;
        font-weight: 700;
        position: fixed;
        top: 0; left: 0; right: 0;
        z-index: 99998;
        box-shadow: 0 2px 8px rgba(0,0,0,.4);
      }
      .mobile-header span { font-size: 14px; font-weight: 700; color: #fff; }
      .mobile-header button {
        border: none;
        background: rgba(255,255,255,.1);
        border: 1px solid rgba(255,255,255,.15);
        border-radius: 8px;
        width: 38px; height: 38px;
        color: #fff;
        font-size: 18px;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
      }
      .sidebar {
        position: fixed;
        left: -260px;
        width: 260px;
        height: 100%;
        transition: .3s;
        z-index: 99999;
        top: 0;
      }
      .sidebar.open { left: 0; }
      .main {
        margin-left: 0 !important;
        margin-top: 56px !important;
        padding: 16px !important;
        width: 100% !important;
        box-sizing: border-box !important;
        overflow: visible !important;
      }
      html, body { overflow-x: hidden !important; max-width: 100vw !important; }
    }

    #overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); display:none; z-index:99997; }
    #overlay.show { display:block; }
  </style>
</head>
<body>

<style>
.sidebar { top: 0 !important; height: 100vh !important; overflow-y: auto; }
</style>

<div id="overlay" onclick="closeMenu()"></div>

<div class="sidebar" id="sidebar">
  <div class="brand">FIL-RISE • SUPER ADMIN</div>
  <div class="brand-badge">🔒 Restricted Access</div>

  <a class="navlink <?= ($activePage??'') === 'super_admin_dashboard' ? 'active' : '' ?>" href="super_admin_dashboard.php">Dashboard</a>
  <a class="navlink <?= ($activePage??'') === 'all_users'             ? 'active' : '' ?>" href="all_users.php">All Users</a>
  <a class="navlink <?= ($activePage??'') === 'roles_permissions'     ? 'active' : '' ?>" href="roles_permissions.php">Roles &amp; Permissions</a>
  <a class="navlink <?= ($activePage??'') === 'branch_modules'        ? 'active' : '' ?>" href="branch_modules.php">Branch Modules</a>

  <div class="sidebox">
    <div style="font-size:12px;color:#cbb0af;">Logged in as:</div>
    <div style="font-weight:800;"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Super Admin') ?></div>
    <a class="logoutbtn" href="../admin/logout.php">Logout</a>
  </div>
</div>

<div class="mobile-header d-lg-none">
  <span>FIL-RISE • SUPER ADMIN</span>
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
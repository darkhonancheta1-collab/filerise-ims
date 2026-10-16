<?php
/**
 * admin/_layout_top.php — Admin Layout Header (Shared Template)
 *
 * Shared HTML head and sidebar navigation for all admin pages.
 * Includes: Bootstrap, Poppins font, Chart.js, admin sidebar with
 * navigation links, and responsive mobile support.
 *
 * Usage: Set $pageTitle and $activePage before including this file.
 * The sidebar highlights the active page link automatically.
 */
require __DIR__ . "/auth.php";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($pageTitle ?? "Main Admin") ?> - FIL-RISE</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="icon" type="image/png" href="../7.png">

  <style>
    :root {
      --nav: #0f172a;
      --nav2: #111827;
      --primary: #2563eb;
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

    .brand { font-weight:800; letter-spacing:.5px; font-size:14px; opacity:.95; }

    .navlink {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px; color: #cbd5e1; text-decoration: none;
      border-radius: 12px; margin: 6px 0; transition: .2s; font-size: 14px;
    }
    .navlink:hover  { background: rgba(37,99,235,.15); color: #fff; }
    .navlink.active { background: linear-gradient(90deg,var(--primary),#3b82f6); color: #fff; }

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
      background: #eef2ff; border: 1px solid #e0e7ff; color: #1e3a8a;
      font-weight: 700; border-radius: 999px; padding: 8px 12px;
      font-size: 12px; white-space: nowrap;
    }

    .table thead th { font-size:12px; color:#111827; }
    .table tbody td { font-size:13px; }

    #notifWrap { position: relative; }
    #bellIcon   { font-size:20px; display:inline-block; cursor:pointer; }

    #notificationPanel {
      display: none; position: absolute; top: 32px; right: -10px;
      width: 320px; background: white; border-radius: 14px;
      box-shadow: 0 10px 20px rgba(0,0,0,.18); padding: 15px; z-index: 9999;
    }
    #notificationPanel::before {
      content: ""; position: absolute; top: -8px; right: 18px;
      border-width: 8px; border-style: solid;
      border-color: transparent transparent white transparent;
    }

    .mobile-header { display: none; }

    @media (max-width:992px) {
      .mobile-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 16px;
        height: 56px;
        background: linear-gradient(135deg, #0f172a, #111827);
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
  <div class="brand mb-3">FIL-RISE • MAIN ADMIN</div>

  <a class="navlink <?= ($activePage??'') === 'dashboard' ? 'active' : '' ?>" href="admin_dashboard.php">Dashboard</a>
  <a class="navlink <?= ($activePage??'') === 'ai' ? 'active' : '' ?>"
     href="admin_ai_forecast.php"
     style="background:<?= ($activePage??'')==='ai'?'linear-gradient(90deg,#2563eb,#3b82f6)':'linear-gradient(90deg,rgba(6,182,212,.15),rgba(59,130,246,.15))' ?>;color:<?= ($activePage??'')==='ai'?'#fff':'#06b6d4' ?>;border:<?= ($activePage??'')==='ai'?'none':'1px solid rgba(6,182,212,.25)' ?>;">
    AI Dashboard
  </a>
  <a class="navlink <?= ($activePage??'') === 'branches'  ? 'active' : '' ?>" href="branches.php">Branches</a>
  <a class="navlink <?= ($activePage??'') === 'reports'   ? 'active' : '' ?>" href="admin_reports.php">Reports</a>
  <a class="navlink <?= ($activePage??'') === 'stock_requests' ? 'active' : '' ?>" href="stock_requests.php">Stock Requests</a>
  <a class="navlink <?= ($activePage??'') === 'expenses'       ? 'active' : '' ?>" href="expenses.php">Expenses</a>

  <div class="sidebox">
    <div style="font-size:12px;color:#cbd5e1;">Logged in as:</div>
    <div style="font-weight:800;"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Main Admin') ?></div>
    <a class="logoutbtn" href="logout.php">Logout</a>
  </div>
</div>

<div class="mobile-header d-lg-none">
  <span>FIL-RISE • ADMIN</span>
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
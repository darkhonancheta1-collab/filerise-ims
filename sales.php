<?php
require 'stock_manager.php';
require 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

$user_id = $_SESSION['user_id'];
$date    = $_GET['date'] ?? date('Y-m-d');
$mode    = $_GET['mode'] ?? 'current';
$from    = $_GET['from'] ?? null;
$to      = $_GET['to']   ?? null;
$errors   = [];
$new_sale = isset($_GET['new_sale']) ? intval($_GET['new_sale']) : 0;

/* =========================
   PROCESS WALK-IN SALE
========================= */
if (isset($_POST['process_sale'])) {

  $customer_name = trim($_POST['customer_name']);
  $member_id     = null;
  $is_member     = false;

  if ($customer_name) {
    $check = $pdo->prepare("SELECT * FROM members WHERE user_id=? AND LOWER(full_name)=LOWER(?) LIMIT 1");
    $check->execute([$user_id, $customer_name]);
    $existing = $check->fetch();
    if ($existing) {
      $member_id = $existing['id'];
      $is_member = ($existing['status'] === 'ACTIVE');
    } else {
      $ins = $pdo->prepare("INSERT INTO members (user_id,full_name,age,address,membership_fee,status) VALUES (?,?,0,'',0,'INACTIVE')");
      $ins->execute([$user_id, $customer_name]);
      $member_id = $pdo->lastInsertId();
    }
  }

  $product_ids = $_POST['product_ids'] ?? [];
  $units       = $_POST['units']       ?? [];
  $quantities  = $_POST['quantities']  ?? [];

  if (empty($product_ids)) {
    $errors[] = "Please add at least one product.";
  } else {
    foreach ($product_ids as $i => $pid) {
      $pid  = intval($pid);
      $qty  = intval($quantities[$i] ?? 0);
      $unit = trim($units[$i] ?? '');
      if ($qty <= 0) continue;

      $p = $pdo->prepare("
        SELECT p.*,
          p.box_qty
            - IFNULL((SELECT SUM(quantity_sold) FROM distributor_sales WHERE product_id=p.id AND unit='BOX' AND DATE(sale_date)=CURDATE()),0)
            - IFNULL((SELECT SUM(wsi.quantity) FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id=wsi.sale_id WHERE wsi.product_id=p.id AND wsi.unit='BOX' AND DATE(ws.created_at)=CURDATE()),0)
          AS remaining_box,
          p.pcs_qty
            - IFNULL((SELECT SUM(quantity_sold) FROM distributor_sales WHERE product_id=p.id AND unit='PCS' AND DATE(sale_date)=CURDATE()),0)
            - IFNULL((SELECT SUM(wsi.quantity) FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id=wsi.sale_id WHERE wsi.product_id=p.id AND wsi.unit='PCS' AND DATE(ws.created_at)=CURDATE()),0)
          AS remaining_pcs,
          p.bottle_qty
            - IFNULL((SELECT SUM(quantity_sold) FROM distributor_sales WHERE product_id=p.id AND unit='BOTTLE' AND DATE(sale_date)=CURDATE()),0)
            - IFNULL((SELECT SUM(wsi.quantity) FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id=wsi.sale_id WHERE wsi.product_id=p.id AND wsi.unit='BOTTLE' AND DATE(ws.created_at)=CURDATE()),0)
          AS remaining_bottle
        FROM products p WHERE p.id=? AND p.user_id=?
      ");
      $p->execute([$pid, $user_id]);
      $product = $p->fetch();
      if (!$product) continue;

      $available = 0;
      if ($unit === 'BOX')        $available = $product['remaining_box'];
      elseif ($unit === 'PCS')    $available = $product['remaining_pcs'] + ($product['remaining_box'] * 10);
      elseif ($unit === 'BOTTLE') $available = $product['remaining_bottle'];

      if ($available < 1)    $errors[] = $product['name'] . " is OUT OF STOCK.";
      if ($qty > $available) $errors[] = $product['name'] . " only has {$available} {$unit} remaining.";
    }

    if (empty($errors)) {
      try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO walkin_sales (user_id,member_id,total_amount) VALUES (?,?,0)")->execute([$user_id, $member_id]);
        $sale_id = $pdo->lastInsertId();
        $ref_no  = 'FR-' . date('Ymd') . '-' . str_pad($sale_id, 5, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE walkin_sales SET reference_no=? WHERE id=?")->execute([$ref_no, $sale_id]);

        $total = 0;
        foreach ($product_ids as $i => $pid) {
          $pid  = intval($pid);
          $qty  = intval($quantities[$i] ?? 0);
          $unit = trim($units[$i] ?? '');
          if ($qty <= 0) continue;

          $p = $pdo->prepare("SELECT * FROM products WHERE id=? AND user_id=?");
          $p->execute([$pid, $user_id]);
          $product = $p->fetch();
          if (!$product) continue;

          if (!$unit) $unit = $product['unit_type'];
          $price = $is_member ? $product['member_price'] : $product['srp_price'];
          if ($unit === 'PCS' && $product['unit_type'] === 'BOX') $price = ceil($price / 10);

          $subtotal = $price * $qty;
          $total   += $subtotal;
          $pdo->prepare("INSERT INTO walkin_sales_items (sale_id,product_id,unit,quantity,price) VALUES (?,?,?,?,?)")
              ->execute([$sale_id, $pid, $unit, $qty, $price]);
        }

        $pdo->prepare("UPDATE walkin_sales SET total_amount=? WHERE id=?")->execute([$total, $sale_id]);
        $pdo->commit();
        header("Location: receipt.php?id=" . $sale_id);
        exit;
      } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = "Error: " . $e->getMessage();
      }
    }
  }
}

/* =========================
   FETCH PRODUCTS
========================= */
$productsStmt = $pdo->prepare("SELECT * FROM products WHERE user_id=? AND is_active=1 ORDER BY category, name");
$productsStmt->execute([$user_id]);
$products = $productsStmt->fetchAll();

$categories = array_unique(array_filter(array_column($products, 'category')));
sort($categories);

$productsJson = [];
foreach ($products as $p) {
  $productsJson[] = [
    'id'           => $p['id'],
    'name'         => $p['name'],
    'category'     => $p['category'] ?? 'Other',
    'unit_type'    => $p['unit_type'],
    'srp_price'    => (float)$p['srp_price'],
    'member_price' => (float)$p['member_price'],
    'box_qty'      => (int)$p['box_qty'],
    'pcs_qty'      => (int)$p['pcs_qty'],
    'bottle_qty'   => (int)$p['bottle_qty'],
    'image'        => $p['image'] ?? '',
  ];
}

/* =========================
   FETCH MEMBERS
========================= */
$membersStmt = $pdo->prepare("SELECT full_name, status FROM members WHERE user_id=? ORDER BY full_name ASC");
$membersStmt->execute([$user_id]);
$members = $membersStmt->fetchAll();

/* =========================
   FETCH SALES HISTORY
========================= */
if ($mode === 'range' && $from && $to) {
  $salesStmt = $pdo->prepare("
    SELECT ws.id, ws.reference_no, ws.created_at, ws.total_amount,
           m.full_name, m.status,
           GROUP_CONCAT(p.name SEPARATOR ', ') AS products,
           GROUP_CONCAT(wsi.unit SEPARATOR ', ') AS units,
           GROUP_CONCAT(wsi.quantity SEPARATOR ', ') AS quantities
    FROM walkin_sales ws
    LEFT JOIN members m ON ws.member_id = m.id
    JOIN walkin_sales_items wsi ON ws.id = wsi.sale_id
    JOIN products p ON p.id = wsi.product_id
    WHERE ws.user_id=? AND DATE(ws.created_at) BETWEEN ? AND ?
    GROUP BY ws.id ORDER BY ws.id DESC
  ");
  $salesStmt->execute([$user_id, $from, $to]);
} else {
  $salesStmt = $pdo->prepare("
    SELECT ws.id, ws.reference_no, ws.created_at, ws.total_amount,
           m.full_name, m.status,
           GROUP_CONCAT(p.name SEPARATOR ', ') AS products,
           GROUP_CONCAT(wsi.unit SEPARATOR ', ') AS units,
           GROUP_CONCAT(wsi.quantity SEPARATOR ', ') AS quantities
    FROM walkin_sales ws
    LEFT JOIN members m ON ws.member_id = m.id
    JOIN walkin_sales_items wsi ON ws.id = wsi.sale_id
    JOIN products p ON p.id = wsi.product_id
    WHERE ws.user_id=? AND DATE(ws.created_at)=?
    GROUP BY ws.id ORDER BY ws.id DESC
  ");
  $salesStmt->execute([$user_id, $date]);
}
$walkinSales = $salesStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Walk-in Sales — Fil-Rise IMS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/tom-select/dist/css/tom-select.css" rel="stylesheet">
  <link rel="icon" type="image/png" href="7.png?v=3">
  <link rel="shortcut icon" type="image/png" href="7.png?v=3">
  <link rel="stylesheet" href="mobile.css">
  <style>
    :root {
      --sw:     260px;
      --accent: #2563eb;
      --green:  #16a34a;
      --red:    #ef4444;
      --bg:     #f1f5f9;
      --white:  #ffffff;
      --border: #e2e8f0;
      --text:   #0f172a;
      --muted:  #64748b;
    }
    * { box-sizing: border-box; }
    html, body { margin:0; padding:0; font-family:'Poppins',sans-serif; background:var(--bg); min-height:100vh; color:var(--text); }

    /* SIDEBAR */
    /* ===== SIDEBAR ===== */
    .sidebar {
      width: 260px;
      background: linear-gradient(180deg, #0f172a, #111827);
      min-height: 100vh;
      padding: 25px 20px;
      position: fixed;
      display: flex;
      flex-direction: column;
    }
    .sidebar .logo { width: 140px; margin-bottom: 30px; }
    .sidebar .menu-title {
      font-size: 12px; text-transform: uppercase;
      letter-spacing: 1px; color: #94a3b8;
      margin-top: 20px; margin-bottom: 10px;
    }
    .sidebar a {
      color: #cbd5e1; text-decoration: none;
      padding: 10px 15px; border-radius: 10px;
      display: flex; align-items: center; gap: 10px;
      margin-bottom: 5px; transition: all 0.3s ease; font-size: 14px;
    }
    .sidebar a:hover { background: rgba(59,130,246,.2); color:#fff; }
    .sidebar a.active { background: linear-gradient(90deg,#2563eb,#3b82f6); color:#fff; }
    .logout {
      margin-top: auto; padding: 10px 15px; border-radius: 10px;
      background: rgba(121,35,35,.2); color: #cbd5e1;
      cursor: pointer; transition: .3s;
    }
    .logout:hover { background: rgba(239,68,68,.4); }

    /* PAGE */
    .page-wrap { margin-left:var(--sw); display:flex; flex-direction:column; min-height:100vh; }
    .page-header {
      background:#fff; border-bottom:1px solid var(--border); padding:14px 26px;
      display:flex; align-items:center; justify-content:space-between;
    }
    .page-header h4  { margin:0; font-weight:700; font-size:19px; }
    .page-header .sub { font-size:12px; color:var(--muted); }

    /* TABS */
    .tab-bar { background:#fff; border-bottom:2px solid var(--border); display:flex; padding:0 26px; }
    .tab-btn {
      padding:12px 20px; font-size:13px; font-weight:600; color:var(--muted);
      border:none; background:none; border-bottom:3px solid transparent;
      margin-bottom:-2px; cursor:pointer; transition:.2s;
    }
    .tab-btn.active { color:var(--accent); border-bottom-color:var(--accent); }

    /* POS LAYOUT */
    .pos-wrap { display:grid; grid-template-columns:1fr 355px; height:calc(100vh - 108px); overflow:hidden; }

    /* LEFT */
    .product-panel { background:var(--bg); display:flex; flex-direction:column; overflow:hidden; }
    .panel-top     { padding:14px 18px 10px; display:flex; flex-direction:column; gap:10px; }

    .search-bar {
      display:flex; align-items:center; gap:8px; background:#fff;
      border:1.5px solid var(--border); border-radius:12px; padding:8px 14px;
    }
    .search-bar input { border:none; outline:none; font-family:'Poppins',sans-serif; font-size:13px; flex:1; background:transparent; }

    .cat-pills { display:flex; gap:7px; flex-wrap:wrap; }
    .cat-pill {
      padding:4px 13px; border-radius:20px; font-size:12px; font-weight:600;
      border:1.5px solid var(--border); background:#fff; color:var(--muted);
      cursor:pointer; transition:.15s; white-space:nowrap;
    }
    .cat-pill.active { background:var(--accent); color:#fff; border-color:var(--accent); }
    .cat-pill:hover:not(.active) { border-color:var(--accent); color:var(--accent); }

    .product-grid {
      flex:1; overflow-y:auto; padding:10px 18px 20px;
      display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr));
      gap:13px; align-content:start;
    }
    .product-grid::-webkit-scrollbar { width:5px; }
    .product-grid::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:4px; }

    .prod-card {
      background:#fff; border-radius:14px; padding:13px 11px;
      box-shadow:0 2px 8px rgba(0,0,0,.06); cursor:pointer;
      transition:.18s; border:2px solid transparent; position:relative;
      display:flex; flex-direction:column; gap:5px; user-select:none;
    }
    .prod-card:hover:not(.oos) { transform:translateY(-2px); box-shadow:0 6px 18px rgba(37,99,235,.13); border-color:#bfdbfe; }
    .prod-card.oos   { opacity:.42; cursor:not-allowed; }
    .prod-card.flash { border-color:#16a34a !important; background:#f0fdf4 !important; transition:none; }
    .prod-card > * { pointer-events: none; }  /* ALL children — clicks pass to card */

    .prod-img { width:100%; aspect-ratio:1; object-fit:cover; border-radius:9px; background:#f1f5f9; pointer-events:none; }
    .prod-ph  {
      width:100%; aspect-ratio:1; border-radius:9px; pointer-events:none;
      background:linear-gradient(135deg,#dbeafe,#eff6ff);
      display:flex; align-items:center; justify-content:center;
      font-size:16px; font-weight:700; color:#3b82f6; letter-spacing:1px;
    }
    .prod-name  { font-size:11.5px; font-weight:600; line-height:1.3; pointer-events:none; }
    .prod-price { font-size:13px; font-weight:700; color:var(--accent); pointer-events:none; }
    .prod-stock { font-size:10.5px; color:var(--muted); pointer-events:none; }

    .unit-badge {
      position:absolute; top:7px; right:7px; font-size:9.5px; font-weight:700;
      padding:2px 6px; border-radius:20px; pointer-events:none; background:#eff6ff; color:#2563eb;
    }
    .unit-badge.box    { background:#fef3c7; color:#d97706; }
    .unit-badge.bottle { background:#d1fae5; color:#065f46; }
    .unit-badge.pcs    { background:#ede9fe; color:#7c3aed; }
    .out-badge {
      position:absolute; top:7px; left:7px; font-size:9.5px; font-weight:700;
      padding:2px 7px; border-radius:20px; background:var(--red); color:#fff; pointer-events:none;
    }

    /* RIGHT */
    .order-panel { background:#fff; border-left:1px solid var(--border); display:flex; flex-direction:column; overflow:hidden; }
    .order-head  { padding:14px 18px 11px; border-bottom:1px solid var(--border); }
    .order-head h5 { margin:0; font-size:15px; font-weight:700; }

    .cust-row  { padding:10px 18px; border-bottom:1px solid var(--border); }
    .cust-row label { font-size:11px; font-weight:700; color:var(--red); display:block; margin-bottom:4px; }
    .cust-hint { font-size:11px; color:#94a3b8; margin-top:4px; }

    .ts-wrapper.single .ts-control {
      border-radius:10px !important; font-size:13px !important;
      border:1.5px solid var(--border) !important; box-shadow:none !important;
    }

    .cart-list { flex:1; overflow-y:auto; padding:8px 14px; }
    .cart-list::-webkit-scrollbar { width:4px; }
    .cart-list::-webkit-scrollbar-thumb { background:#e2e8f0; border-radius:3px; }

    .cart-empty {
      display:flex; flex-direction:column; align-items:center; justify-content:center;
      height:100%; color:var(--muted); gap:9px; font-size:13px; text-align:center;
    }
    .empty-ico { font-size:40px; opacity:.25; }

    .cart-item  { display:flex; align-items:flex-start; gap:9px; padding:9px 0; border-bottom:1px solid #f1f5f9; }
    .ci-info    { flex:1; min-width:0; }
    .ci-name    { font-size:12px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .ci-sub     { font-size:11px; color:var(--muted); margin-top:1px; }
    .ci-price   { font-size:13px; font-weight:700; color:var(--accent); white-space:nowrap; }

    .unit-toggle { display:flex; gap:4px; margin-top:5px; }
    .utbtn {
      padding:2px 9px; border-radius:6px; font-size:11px; font-weight:600;
      border:1.5px solid var(--border); background:#f8fafc; color:var(--muted);
      cursor:pointer; transition:.12s;
    }
    .utbtn.active { background:var(--accent); color:#fff; border-color:var(--accent); }

    .qty-row { display:flex; align-items:center; gap:6px; margin-top:5px; }
    .qbtn {
      width:26px; height:26px; border-radius:7px; border:1.5px solid var(--border);
      background:#f8fafc; font-size:15px; font-weight:700; cursor:pointer;
      display:flex; align-items:center; justify-content:center; color:var(--text); transition:.12s;
    }
    .qbtn:hover { background:#e0e7ff; border-color:var(--accent); color:var(--accent); }
    .qval { font-size:13px; font-weight:700; min-width:22px; text-align:center; }

    .rm-btn {
      width:28px; height:28px; border-radius:7px; border:1.5px solid #fecaca;
      background:#fef2f2; color:var(--red); font-size:14px; cursor:pointer;
      display:flex; align-items:center; justify-content:center; transition:.15s; flex-shrink:0;
    }
    .rm-btn:hover { background:var(--red); color:#fff; }

    .order-foot { padding:13px 18px; border-top:1px solid var(--border); }
    .total-row  { display:flex; justify-content:space-between; align-items:center; margin-bottom:11px; }
    .total-lbl  { font-size:13px; color:var(--muted); font-weight:500; }
    .total-val  { font-size:24px; font-weight:700; }

    .checkout-btn {
      width:100%; padding:13px; border:none; border-radius:12px; cursor:pointer;
      font-family:'Poppins',sans-serif; font-size:15px; font-weight:700; color:#fff;
      background:linear-gradient(90deg,#16a34a,#22c55e);
      box-shadow:0 4px 14px rgba(22,163,74,.3); transition:.2s;
    }
    .checkout-btn:hover:not(:disabled) { transform:translateY(-1px); box-shadow:0 6px 20px rgba(22,163,74,.4); }
    .checkout-btn:disabled { background:#e2e8f0; color:#94a3b8; box-shadow:none; cursor:not-allowed; }

    /* MODAL */
    .modal-bg {
      display:none; position:fixed; inset:0; background:rgba(15,23,42,.55);
      z-index:9999; align-items:center; justify-content:center;
    }
    .modal-bg.show { display:flex; }
    .modal-box {
      background:#fff; border-radius:20px; width:460px; max-height:90vh;
      overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.25); padding:26px;
    }
    .modal-box h5 { font-weight:700; margin-bottom:16px; }
    .sum-item {
      display:flex; justify-content:space-between; padding:7px 0;
      border-bottom:1px solid #f1f5f9; font-size:13px;
    }
    .sum-item:last-child { border-bottom:none; }
    .sum-grand { font-size:20px; font-weight:700; color:var(--green); }

    /* HISTORY */
    .hist-wrap { padding:22px 26px; }
    .hist-filter { background:#fff; border-radius:14px; padding:16px 20px; margin-bottom:18px; box-shadow:0 2px 8px rgba(0,0,0,.05); }
    .hist-table-wrap { background:#fff; border-radius:14px; box-shadow:0 2px 8px rgba(0,0,0,.05); overflow:hidden; }
    .hist-table-wrap thead th {
      background:#f8fafc; font-size:11px; font-weight:700; color:var(--muted);
      text-transform:uppercase; letter-spacing:.5px; padding:11px 15px; border-bottom:2px solid var(--border);
    }
    .hist-table-wrap tbody td { font-size:13px; padding:10px 15px; vertical-align:middle; border-bottom:1px solid #f1f5f9; }
    .hist-table-wrap tbody tr:last-child td { border-bottom:none; }
    .hist-table-wrap tbody tr:hover td { background:#f8fafc; }

    .err-bar { background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:11px 16px; color:#b91c1c; font-size:13px; margin:10px 18px 0; }
    #memberInd { margin-top:5px; font-size:12px; }
  </style>
</head>
<body>
<?php include 'ticker.php'; ?>

<!-- SIDEBAR -->
<div class="sidebar">
  <img src="7.png" class="logo" alt="Fil-Rise Logo">

  <div class="menu-title">Core</div>
  <a href="dashboard.php">Dashboard</a>
  <a href="ai_forecast.php" style="background:linear-gradient(90deg,rgba(6,182,212,.15),rgba(59,130,246,.15));color:#06b6d4;border:1px solid rgba(6,182,212,.25);">AI Dashboard</a>

  <div class="menu-title">Inventory</div>
  <a href="product.php">Products</a>
  <a href="removed_products.php">Archived Products</a>

  <div class="menu-title">Operations</div>
  <a href="transaction.php">Consignment Transactions</a>
  <a href="sales.php" class="active">Walk-in Sales</a>
  <a href="returns.php">Product Returns</a>
  <a href="stock_requests.php">Stock Requests</a>
  <a href="daily_records.php">Daily Records</a>
  <a href="daily_sales_record.php">Daily Sales Record</a>

  <div class="menu-title">People</div>
  <a href="distributor.php">Distributors</a>
  <a href="members.php">Members</a>

  <div class="logout" onclick="window.location='logout.php'">Logout</div>
</div>

<!-- PAGE -->
<div class="page-wrap">

  <div class="page-header">
    <div>
      <h4>Walk-in Sales</h4>
      <div class="sub">Point of Sale — <?= date('F d, Y') ?></div>
    </div>
    <div style="font-size:13px;color:var(--muted);"><?= count($walkinSales) ?> transaction(s) today</div>
  </div>

  <div class="tab-bar">
    <button class="tab-btn active" id="tab-pos"  onclick="switchTab('pos')">New Sale (POS)</button>
    <button class="tab-btn"        id="tab-hist" onclick="switchTab('hist')">Sales History</button>
  </div>

  <!-- POS TAB -->
  <div id="view-pos">

    <?php if (!empty($errors)): ?>
      <div class="err-bar"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>

    <div class="pos-wrap">

      <!-- LEFT: PRODUCTS -->
      <div class="product-panel">
        <div class="panel-top">
          <div class="search-bar">
            <span style="color:var(--muted);">🔍</span>
            <input type="text" id="productSearch" placeholder="Search product…" oninput="filterProducts()">
          </div>
          <div class="cat-pills">
            <button class="cat-pill active" onclick="filterCat('',this)">All</button>
            <?php foreach ($categories as $cat): ?>
              <button class="cat-pill" onclick="filterCat('<?= htmlspecialchars($cat,ENT_QUOTES) ?>',this)">
                <?= htmlspecialchars($cat) ?>
              </button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="product-grid" id="productGrid">
          <?php foreach ($products as $p):
            if ($p['unit_type'] === 'BOX') {
              $totalStock = $p['box_qty'] + $p['pcs_qty'];
            } elseif ($p['unit_type'] === 'BOTTLE') {
              $totalStock = $p['bottle_qty'];
            } else {
              $totalStock = $p['pcs_qty'];
            }
            $oos      = ($totalStock <= 0);
            $badgeCls = strtolower($p['unit_type']);
            $cat      = $p['category'] ?? '';
            // placeholder uses product initials — no emoji
          ?>
          <div class="prod-card <?= $oos ? 'oos' : '' ?>"
               data-id="<?= $p['id'] ?>"
               data-name="<?= htmlspecialchars($p['name'],ENT_QUOTES) ?>"
               data-unit-type="<?= $p['unit_type'] ?>"
               data-srp="<?= $p['srp_price'] ?>"
               data-member="<?= $p['member_price'] ?>"
               data-box="<?= $p['box_qty'] ?>"
               data-pcs="<?= $p['pcs_qty'] ?>"
               data-bottle="<?= $p['bottle_qty'] ?>"
               data-category="<?= htmlspecialchars($cat,ENT_QUOTES) ?>"
               onclick="<?= $oos ? '' : 'addToCart(this)' ?>">

            <?php if ($oos): ?><span class="out-badge">OUT</span><?php endif; ?>
            <span class="unit-badge <?= $badgeCls ?>"><?= $p['unit_type'] ?></span>

            <?php $initials = strtoupper(substr($p['name'], 0, 2)); ?>
            <?php if (!empty($p['image'])): ?>
              <img class="prod-img"
                   src="uploads/products/<?= htmlspecialchars($p['image']) ?>"
                   alt="<?= htmlspecialchars($p['name']) ?>"
                   onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
              <div class="prod-ph" style="display:none;"><?= $initials ?></div>
            <?php else: ?>
              <div class="prod-ph"><?= $initials ?></div>
            <?php endif; ?>

            <div class="prod-name"><?= htmlspecialchars($p['name']) ?></div>
            <div class="prod-price" id="pp-<?= $p['id'] ?>">₱<?= number_format($p['srp_price'],2) ?></div>
            <div class="prod-stock">
              <?php if ($p['unit_type']==='BOX'): ?>
                <?= $p['box_qty'] ?> box · <?= $p['pcs_qty'] ?> pcs
              <?php elseif ($p['unit_type']==='BOTTLE'): ?>
                <?= $p['bottle_qty'] ?> bottles
              <?php else: ?>
                <?= $p['pcs_qty'] ?> pcs
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- RIGHT: ORDER PANEL -->
      <div class="order-panel">

        <div class="order-head">
          <h5>Order Details
            <span id="cartBadge" style="display:none;background:var(--accent);color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;margin-left:6px;"></span>
          </h5>
        </div>

        <div class="cust-row" id="custRow">
          <label>Select Customer First</label>
          <select id="customerSelect" placeholder="Type or select customer…">
            <option value="">— Type name or select member —</option>
            <?php foreach ($members as $m): ?>
              <option value="<?= htmlspecialchars($m['full_name'],ENT_QUOTES) ?>"
                      data-status="<?= $m['status'] ?>">
                <?= htmlspecialchars($m['full_name']) ?><?= $m['status']==='ACTIVE' ? ' ★' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div id="memberInd"></div>
          <div class="cust-hint">★ Active members get member price · Others pay SRP</div>
        </div>

        <div class="cart-list" id="cartList">
          <div class="cart-empty" id="cartEmpty">
            <div class="empty-ico" style="font-size:38px;opacity:.2;font-weight:700;color:var(--muted);">[ ]</div>
            <div>No items yet.<br>Select a customer, then click a product.</div>
          </div>
        </div>

        <div class="order-foot">
          <div class="total-row">
            <span class="total-lbl">Total</span>
            <span class="total-val" id="orderTotal">₱0.00</span>
          </div>
          <button class="checkout-btn" id="checkoutBtn" disabled onclick="openCheckout()">
            Proceed to Checkout
          </button>
        </div>

      </div>
    </div>
  </div>

  <!-- HISTORY TAB -->
  <div id="view-hist" style="display:none;">
    <div class="hist-wrap">

      <?php if ($new_sale): ?>
      <div style="background:#dcfce7;border:1px solid #86efac;border-radius:12px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">
        <span style="color:#166534;font-weight:600;font-size:14px;">Sale completed successfully!</span>
        <a href="receipt.php?id=<?= $new_sale ?>" target="_blank"
           style="background:#16a34a;color:#fff;padding:7px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;">
          View Receipt
        </a>
      </div>
      <?php endif; ?>
      <div class="hist-filter">
        <form method="get" class="row g-3 align-items-end">
          <div class="col-md-3">
            <label class="form-label fw-semibold" style="font-size:13px;">Record Type</label>
            <select name="mode" class="form-select form-select-sm" onchange="toggleHistMode(this.value)">
              <option value="current" <?= $mode==='current'?'selected':'' ?>>Single Date</option>
              <option value="range"   <?= $mode==='range'  ?'selected':'' ?>>Date Range</option>
            </select>
          </div>
          <div class="col-md-3" id="hSingle" <?= $mode==='range'?'style="display:none"':'' ?>>
            <label class="form-label fw-semibold" style="font-size:13px;">Date</label>
            <input type="date" name="date" value="<?= $date ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-4" id="hRange" <?= $mode!=='range'?'style="display:none"':'' ?>>
            <label class="form-label fw-semibold" style="font-size:13px;">Date Range</label>
            <div class="d-flex gap-2">
              <input type="date" name="from" value="<?= $from ?>" class="form-control form-control-sm">
              <input type="date" name="to"   value="<?= $to ?>"   class="form-control form-control-sm">
            </div>
          </div>
          <div class="col-md-2">
            <button class="btn btn-primary btn-sm w-100">Load</button>
          </div>
        </form>
      </div>

      <div class="hist-table-wrap">
        <?php if (empty($walkinSales)): ?>
          <div style="padding:40px;text-align:center;color:var(--muted);font-size:14px;">No sales found for this period.</div>
        <?php else: ?>
        <table class="table mb-0">
          <thead>
            <tr>
              <th>Ref #</th><th>Customer</th><th>Status</th>
              <th>Date &amp; Time</th><th>Products</th><th>Total</th><th>Receipt</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($walkinSales as $s): ?>
            <tr>
              <td><span style="font-size:11px;font-weight:700;color:var(--accent);"><?= htmlspecialchars($s['reference_no'] ?? '—') ?></span></td>
              <td><?= htmlspecialchars($s['full_name'] ?? 'Guest') ?></td>
              <td>
                <?php if (($s['status']??'') === 'ACTIVE'): ?>
                  <span class="badge bg-success">Member</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Non-Member</span>
                <?php endif; ?>
              </td>
              <td style="font-size:12px;"><?= date('M d, Y g:i A', strtotime($s['created_at'])) ?></td>
              <td style="font-size:12px;max-width:200px;">
                <?php
                  $pn = explode(', ', $s['products']);
                  $qt = explode(', ', $s['quantities']);
                  $un = explode(', ', $s['units']);
                  $parts = [];
                  foreach ($pn as $k => $n) $parts[] = htmlspecialchars($n).' ×'.($qt[$k]??'?').' '.($un[$k]??'');
                  echo implode('<br>', $parts);
                ?>
              </td>
              <td><strong>₱<?= number_format($s['total_amount'],2) ?></strong></td>
              <td><a href="receipt.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary" style="font-size:12px;">View</a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /page-wrap -->

<!-- CHECKOUT MODAL -->
<div class="modal-bg" id="modalBg">
  <div class="modal-box">
    <h5>Confirm Sale</h5>
    <div style="margin-bottom:14px;">
      <div style="font-size:12px;color:var(--muted);">Customer</div>
      <div style="font-size:15px;font-weight:600;" id="confirmCust">—</div>
      <div id="confirmTag"></div>
    </div>
    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:7px;">Items</div>
    <div id="confirmItems"></div>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:13px 0 0;">
      <span style="font-size:14px;font-weight:600;">Grand Total</span>
      <span class="sum-grand" id="confirmTotal">₱0.00</span>
    </div>
    <form method="POST" id="saleForm" style="margin-top:16px;">
      <input type="hidden" name="customer_name" id="formCust">
      <div id="formItems"></div>
      <div style="display:flex;gap:10px;">
        <button type="button" onclick="closeModal()"
          style="flex:1;padding:12px;border-radius:10px;border:1.5px solid var(--border);background:#fff;font-family:'Poppins',sans-serif;font-size:14px;font-weight:600;cursor:pointer;">
          Cancel
        </button>
        <button type="submit" name="process_sale"
          style="flex:2;padding:12px;border-radius:10px;border:none;background:linear-gradient(90deg,#16a34a,#22c55e);color:#fff;font-family:'Poppins',sans-serif;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(22,163,74,.3);">
          Complete Sale
        </button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select/dist/js/tom-select.complete.min.js"></script>
<script>
/* ================================================================
   STATE
================================================================ */
let cart     = [];
let isMember = false;

/* ================================================================
   CUSTOMER  (TomSelect)
================================================================ */
const custTS = new TomSelect('#customerSelect', {
  create: true, createOnBlur: true, persist: false,
  sortField: { field:'text', direction:'asc' },
  onChange(val) {
    const opt = this.options[val];
    isMember  = !!(opt && opt.status === 'ACTIVE');
    const ind = document.getElementById('memberInd');
    if (!val) {
      ind.innerHTML = '';
    } else if (isMember) {
      ind.innerHTML = '<span style="background:#dcfce7;color:#16a34a;font-weight:700;padding:3px 10px;border-radius:6px;font-size:12px;">★ Member — Member Price Applied</span>';
    } else {
      ind.innerHTML = '<span style="background:#f1f5f9;color:#64748b;font-weight:600;padding:3px 10px;border-radius:6px;font-size:12px;">Non-Member — SRP Price</span>';
    }
    const row = document.getElementById('custRow');
    row.style.background   = val ? '#f0fdf4' : '';
    row.style.borderRadius = val ? '10px'    : '';
    recalcPrices();
  }
});

/* ================================================================
   FILTER
================================================================ */
let activeCat = '';
function filterCat(cat, btn) {
  activeCat = cat;
  document.querySelectorAll('.cat-pill').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  filterProducts();
}
function filterProducts() {
  const q = document.getElementById('productSearch').value.toLowerCase();
  document.querySelectorAll('.prod-card').forEach(card => {
    const nm = card.dataset.name.toLowerCase().includes(q);
    const ct = !activeCat || card.dataset.category === activeCat;
    card.style.display = (nm && ct) ? '' : 'none';
  });
}

/* ================================================================
   ADD TO CART
================================================================ */
function addToCart(card) {
  if (!custTS.getValue()) {
    const row = document.getElementById('custRow');
    row.style.background   = '#fef2f2';
    row.style.borderRadius = '10px';
    setTimeout(() => { row.style.background = ''; }, 1400);
    custTS.focus();
    toast('Please select or type a customer name first.');
    return;
  }

  const id       = parseInt(card.dataset.id);
  const name     = card.dataset.name;
  const unitType = card.dataset.unitType;
  const srp      = parseFloat(card.dataset.srp);
  const member   = parseFloat(card.dataset.member);
  const boxQty   = parseInt(card.dataset.box);
  const pcsQty   = parseInt(card.dataset.pcs);
  const botQty   = parseInt(card.dataset.bottle);

  const defUnit = unitType;
  const maxQty  = calcMax(unitType, defUnit, boxQty, pcsQty, botQty);
  const price   = calcPrice(unitType, defUnit, srp, member);

  const ex = cart.find(i => i.id === id && i.unit === defUnit);
  if (ex) {
    if (ex.qty < ex.maxQty) { ex.qty++; renderCart(); }
    flashCard(card);
    return;
  }

  cart.push({ id, name, unitType, unit:defUnit, qty:1, price, srp, member, maxQty, boxQty, pcsQty, botQty });
  renderCart();
  flashCard(card);
}

function flashCard(card) {
  card.classList.add('flash');
  setTimeout(() => card.classList.remove('flash'), 380);
}

function calcMax(ut, unit, bq, pq, btq) {
  if (ut === 'BOX')    return unit === 'BOX' ? bq : pq + bq * 10;
  if (ut === 'BOTTLE') return btq;
  return pq;
}

function calcPrice(ut, unit, srp, member) {
  const base = isMember ? member : srp;
  if (ut === 'BOX' && unit === 'PCS') return Math.ceil(base / 10);
  return base;
}

/* ================================================================
   RENDER CART
================================================================ */
function renderCart() {
  const list  = document.getElementById('cartList');
  const badge = document.getElementById('cartBadge');
  const btn   = document.getElementById('checkoutBtn');

  if (cart.length === 0) {
    list.innerHTML = '<div class="cart-empty" style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:var(--muted);gap:9px;font-size:13px;text-align:center;"><div style="font-size:13px;font-weight:700;opacity:.3;letter-spacing:2px;">EMPTY</div><div>No items yet.<br>Select a customer, then click a product.</div></div>';
    badge.style.display = 'none';
    btn.disabled = true;
    calcTotal();
    return;
  }

  // items exist — empty state handled by innerHTML above
  badge.textContent   = cart.length;
  badge.style.display = 'inline';
  btn.disabled        = false;

  let html = '';
  cart.forEach((item, idx) => {
    const sub = item.price * item.qty;
    let unitToggle = '';
    if (item.unitType === 'BOX') {
      const bDis = item.boxQty <= 0 ? 'disabled' : '';
      const pDis = (item.pcsQty + item.boxQty * 10) <= 0 ? 'disabled' : '';
      unitToggle = `<div class="unit-toggle">
        <button class="utbtn ${item.unit==='BOX'?'active':''}" data-action="unit" data-idx="${idx}" data-unit="BOX" ${bDis}>BOX</button>
        <button class="utbtn ${item.unit==='PCS'?'active':''}" data-action="unit" data-idx="${idx}" data-unit="PCS" ${pDis}>PCS</button>
      </div>`;
    }
    html += `
    <div class="cart-item">
      <div class="ci-info">
        <div class="ci-name">${item.name}</div>
        <div class="ci-sub" id="csub-${idx}">₱${item.price.toFixed(2)} / ${item.unit} · max ${item.maxQty}</div>
        ${unitToggle}
        <div class="qty-row">
          <button class="qbtn" data-action="dec" data-idx="${idx}">−</button>
          <span class="qval" id="cqty-${idx}">${item.qty}</span>
          <button class="qbtn" data-action="inc" data-idx="${idx}">+</button>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;padding-top:2px;">
        <button class="rm-btn" data-action="remove" data-idx="${idx}" title="Remove">✕</button>
        <span class="ci-price" id="cprc-${idx}">₱${sub.toFixed(2)}</span>
      </div>
    </div>`;
  });

  list.innerHTML = html;
  calcTotal();
}

/* Delegated listener — never breaks after re-render */
document.addEventListener('click', function(e) {
  const btn = e.target.closest('[data-action]');
  if (!btn) return;
  const action = btn.dataset.action;
  const idx    = parseInt(btn.dataset.idx);
  if (isNaN(idx) || idx >= cart.length) return;

  if (action === 'remove') {
    cart.splice(idx, 1);
    renderCart();
  } else if (action === 'dec') {
    if (cart[idx].qty <= 1) { cart.splice(idx, 1); renderCart(); }
    else { cart[idx].qty--; patchRow(idx); }
  } else if (action === 'inc') {
    if (cart[idx].qty < cart[idx].maxQty) { cart[idx].qty++; patchRow(idx); }
  } else if (action === 'unit') {
    const item  = cart[idx];
    item.unit   = btn.dataset.unit;
    item.maxQty = calcMax(item.unitType, item.unit, item.boxQty, item.pcsQty, item.botQty);
    item.price  = calcPrice(item.unitType, item.unit, item.srp, item.member);
    if (item.qty > item.maxQty) item.qty = item.maxQty;
    renderCart();
  }
});

function patchRow(idx) {
  const item = cart[idx];
  const qEl  = document.getElementById('cqty-' + idx);
  const pEl  = document.getElementById('cprc-' + idx);
  const sEl  = document.getElementById('csub-' + idx);
  if (qEl) qEl.textContent = item.qty;
  if (pEl) pEl.textContent = '₱' + (item.price * item.qty).toFixed(2);
  if (sEl) sEl.textContent = '₱' + item.price.toFixed(2) + ' / ' + item.unit + ' · max ' + item.maxQty;
  calcTotal();
}

function calcTotal() {
  const t = cart.reduce((s, i) => s + i.price * i.qty, 0);
  document.getElementById('orderTotal').textContent = '₱' + t.toLocaleString('en-PH', {minimumFractionDigits:2});
  document.getElementById('checkoutBtn').disabled   = cart.length === 0;
}

function recalcPrices() {
  cart.forEach(item => { item.price = calcPrice(item.unitType, item.unit, item.srp, item.member); });
  renderCart();
  document.querySelectorAll('.prod-card').forEach(card => {
    const el = document.getElementById('pp-' + card.dataset.id);
    if (!el) return;
    const shown = isMember ? parseFloat(card.dataset.member) : parseFloat(card.dataset.srp);
    el.textContent = '₱' + shown.toLocaleString('en-PH', {minimumFractionDigits:2});
    el.style.color = isMember ? '#16a34a' : 'var(--accent)';
  });
}

/* ================================================================
   CHECKOUT MODAL
================================================================ */
function openCheckout() {
  if (cart.length === 0) return;
  const cust = custTS.getValue() || 'Walk-in Guest';
  document.getElementById('confirmCust').textContent = cust;
  document.getElementById('confirmTag').innerHTML = isMember
    ? '<span style="background:#dcfce7;color:#16a34a;font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px;">★ Member</span>' : '';

  let html = '';
  cart.forEach(item => {
    html += `<div class="sum-item">
      <span>${item.name} <span style="font-size:11px;color:var(--muted);">(${item.unit} ×${item.qty})</span></span>
      <span style="font-weight:600;">₱${(item.price*item.qty).toFixed(2)}</span>
    </div>`;
  });
  document.getElementById('confirmItems').innerHTML = html;

  const total = cart.reduce((s, i) => s + i.price * i.qty, 0);
  document.getElementById('confirmTotal').textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2});

  document.getElementById('formCust').value = cust === 'Walk-in Guest' ? '' : cust;
  let fh = '';
  cart.forEach(item => {
    fh += `<input type="hidden" name="product_ids[]" value="${item.id}">`;
    fh += `<input type="hidden" name="units[]"       value="${item.unit}">`;
    fh += `<input type="hidden" name="quantities[]"  value="${item.qty}">`;
  });
  document.getElementById('formItems').innerHTML = fh;
  document.getElementById('modalBg').classList.add('show');
}

function closeModal() { document.getElementById('modalBg').classList.remove('show'); }
document.getElementById('modalBg').addEventListener('click', e => {
  if (e.target === document.getElementById('modalBg')) closeModal();
});

/* ================================================================
   TABS
================================================================ */
function switchTab(tab) {
  document.getElementById('view-pos').style.display  = tab==='pos'  ? '' : 'none';
  document.getElementById('view-hist').style.display = tab==='hist' ? '' : 'none';
  document.getElementById('tab-pos').classList.toggle('active',  tab==='pos');
  document.getElementById('tab-hist').classList.toggle('active', tab==='hist');
}
<?php if ($mode==='range' || isset($_GET['date']) || ($_GET['tab'] ?? '') === 'history'): ?>switchTab('hist');<?php endif; ?>

function toggleHistMode(val) {
  document.getElementById('hSingle').style.display = val==='range' ? 'none' : '';
  document.getElementById('hRange').style.display  = val==='range' ? '' : 'none';
}

/* ================================================================
   TOAST
================================================================ */
function toast(msg) {
  let t = document.getElementById('posToast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'posToast';
    t.style.cssText = 'position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:600;z-index:99999;opacity:0;transition:opacity .3s;pointer-events:none;';
    document.body.appendChild(t);
  }
  t.textContent   = msg;
  t.style.opacity = '1';
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.style.opacity = '0'; }, 2500);
}
</script>

<?php include 'chatbot.php'; ?>
<?php include 'mobile_sidebar.php'; ?>
</body>
</html>
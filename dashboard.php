<?php
/**
 * dashboard.php — Main Staff Dashboard
 *
 * This is the primary landing page for branch staff after login.
 * It displays:
 *   - KPI cards: Total Sales (Today / Week / Month), Expenses, Net Income
 *   - Top Product This Week & Top Distributor This Month
 *   - Category-based bar charts (Most Bought Products by Category)
 *   - Inventory Overview (stock counts, SRP/member values)
 *   - Low Stock Alerts (popup + notification bell + voice alerts)
 *   - Daily Report Submission reminders (toast notifications)
 *   - AJAX endpoint for date-based sales lookup (no full page reload)
 *   - Live badge for pending inter-branch stock requests
 *
 * Includes: config.php, branch_modules.php, chatbot.php,
 *           ticker.php, lowstock_popup.php, mobile_sidebar.php
 */

require 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* -- Authentication guard: redirect to login if not logged in -- */
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}
$user_id = $_SESSION['user_id'];
require_once 'branch_modules.php';
$today = date('Y-m-d');

/* =========================
   AJAX: SALES DATE LOOKUP
   Must be before any HTML output
========================= */
if (isset($_GET['ajax_sales_date'])) {
  $ajaxDate = isset($_GET['sales_date']) ? $_GET['sales_date'] : $today;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ajaxDate)) { $ajaxDate = $today; }
  $stmt2 = $pdo->prepare("
    SELECT SUM(total) FROM (
      SELECT SUM(CASE WHEN ds.unit='PCS' AND p.unit_type='BOX'
        THEN ds.quantity_sold * CEIL(p.member_price / 10)
        ELSE ds.quantity_sold * p.member_price END) AS total
      FROM distributor_sales ds JOIN products p ON p.id=ds.product_id
      WHERE ds.user_id=? AND DATE(ds.sale_date)=?
      UNION ALL
      SELECT SUM(wsi.quantity * wsi.price) AS total
      FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id=wsi.sale_id
      WHERE ws.user_id=? AND DATE(ws.created_at)=?
      UNION ALL
      SELECT COALESCE(SUM(m.membership_fee),0) AS total
      FROM members m
      WHERE m.user_id=? AND DATE(m.created_at)=?
        AND m.status='ACTIVE' AND m.package_product_id IS NOT NULL
      UNION ALL
      SELECT COALESCE(SUM(upgrade_income),0) AS total
      FROM product_returns
      WHERE user_id=? AND return_type='UPGRADE' AND status='COMPLETED'
        AND DATE(returned_at)=?
    ) t
  ");
  $stmt2->execute([$user_id, $ajaxDate, $user_id, $ajaxDate, $user_id, $ajaxDate, $user_id, $ajaxDate]);
  $ajaxTotal = $stmt2->fetchColumn() ?? 0;
  $ajaxLabel = ($ajaxDate === $today) ? 'Today' : date('M d, Y', strtotime($ajaxDate));
  header('Content-Type: application/json');
  echo json_encode([
    'amount' => number_format($ajaxTotal, 2),
    'label'  => $ajaxLabel
  ]);
  exit;
}

include 'chatbot.php';

/* =========================
   CHECK IF REPORT SUBMITTED
========================= */
$stmt = $pdo->prepare("SELECT id FROM branch_reports WHERE user_id=? AND report_date=? LIMIT 1");
$stmt->execute([$user_id, $today]);
$reportSubmitted = $stmt->fetch() ? true : false;

/* =========================
   TOTAL SALES TODAY
========================= */
$selectedDate = isset($_GET['sales_date']) ? $_GET['sales_date'] : $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) { $selectedDate = $today; }

$stmt = $pdo->prepare("
SELECT SUM(total) FROM (
  SELECT SUM(
    CASE WHEN ds.unit = 'PCS' AND p.unit_type = 'BOX'
      THEN ds.quantity_sold * CEIL(p.member_price / 10)
    ELSE ds.quantity_sold * p.member_price END
  ) AS total
  FROM distributor_sales ds
  JOIN products p ON p.id = ds.product_id
  WHERE ds.user_id=? AND DATE(ds.sale_date)=?

  UNION ALL

  SELECT SUM(wsi.quantity * wsi.price) AS total
  FROM walkin_sales_items wsi
  JOIN walkin_sales ws ON ws.id = wsi.sale_id
  WHERE ws.user_id=? AND DATE(ws.created_at)=?

  UNION ALL

  SELECT COALESCE(SUM(m.membership_fee), 0) AS total
  FROM members m
  WHERE m.user_id=? AND DATE(m.created_at)=?
    AND m.status='ACTIVE' AND m.package_product_id IS NOT NULL

  UNION ALL

  SELECT COALESCE(SUM(upgrade_income), 0) AS total
  FROM product_returns
  WHERE user_id=? AND return_type='UPGRADE' AND status='COMPLETED'
    AND DATE(returned_at)=?
) t
");
$stmt->execute([$user_id, $selectedDate, $user_id, $selectedDate, $user_id, $selectedDate, $user_id, $selectedDate]);
$totalSalesToday = $stmt->fetchColumn() ?? 0;
$salesDateLabel = ($selectedDate === $today) ? 'Today' : date('M d, Y', strtotime($selectedDate));

/* =========================
   TOTAL SALES THIS WEEK
========================= */
$stmt = $pdo->prepare("
SELECT SUM(total) FROM (
  SELECT SUM(
    CASE WHEN ds.unit = 'PCS' AND p.unit_type = 'BOX'
      THEN ds.quantity_sold * CEIL(p.member_price / 10)
    ELSE ds.quantity_sold * p.member_price END
  ) AS total
  FROM distributor_sales ds
  JOIN products p ON p.id = ds.product_id
  WHERE ds.user_id=? AND YEARWEEK(ds.sale_date,1)=YEARWEEK(CURDATE(),1)

  UNION ALL

  SELECT SUM(wsi.quantity * wsi.price) AS total
  FROM walkin_sales_items wsi
  JOIN walkin_sales ws ON ws.id = wsi.sale_id
  WHERE ws.user_id=? AND YEARWEEK(ws.created_at,1)=YEARWEEK(CURDATE(),1)

  UNION ALL

  SELECT COALESCE(SUM(m.membership_fee), 0) AS total
  FROM members m
  WHERE m.user_id=? AND m.status='ACTIVE'
    AND m.package_product_id IS NOT NULL
    AND YEARWEEK(m.created_at,1)=YEARWEEK(CURDATE(),1)

  UNION ALL

  SELECT COALESCE(SUM(upgrade_income), 0) AS total
  FROM product_returns
  WHERE user_id=? AND return_type='UPGRADE' AND status='COMPLETED'
    AND YEARWEEK(returned_at,1)=YEARWEEK(CURDATE(),1)
) t
");
$stmt->execute([$user_id, $user_id, $user_id, $user_id]);
$totalSalesWeek = $stmt->fetchColumn() ?? 0;

/* =========================
   TOTAL SALES THIS MONTH
========================= */
$stmt = $pdo->prepare("
SELECT SUM(total) FROM (
  SELECT SUM(
    CASE WHEN ds.unit = 'PCS' AND p.unit_type = 'BOX'
      THEN ds.quantity_sold * CEIL(p.member_price / 10)
    ELSE ds.quantity_sold * p.member_price END
  ) AS total
  FROM distributor_sales ds
  JOIN products p ON p.id = ds.product_id
  WHERE ds.user_id=? AND MONTH(ds.sale_date)=MONTH(CURDATE()) AND YEAR(ds.sale_date)=YEAR(CURDATE())

  UNION ALL

  SELECT SUM(wsi.quantity * wsi.price) AS total
  FROM walkin_sales_items wsi
  JOIN walkin_sales ws ON ws.id = wsi.sale_id
  WHERE ws.user_id=? AND MONTH(ws.created_at)=MONTH(CURDATE()) AND YEAR(ws.created_at)=YEAR(CURDATE())

  UNION ALL

  SELECT COALESCE(SUM(m.membership_fee), 0) AS total
  FROM members m
  WHERE m.user_id=? AND m.status='ACTIVE'
    AND m.package_product_id IS NOT NULL
    AND MONTH(m.created_at)=MONTH(CURDATE()) AND YEAR(m.created_at)=YEAR(CURDATE())

  UNION ALL

  SELECT COALESCE(SUM(upgrade_income), 0) AS total
  FROM product_returns
  WHERE user_id=? AND return_type='UPGRADE' AND status='COMPLETED'
    AND MONTH(returned_at)=MONTH(CURDATE()) AND YEAR(returned_at)=YEAR(CURDATE())
) t
");
$stmt->execute([$user_id, $user_id, $user_id, $user_id]);
$totalSalesMonth = $stmt->fetchColumn() ?? 0;

/* =========================
   EXPENSES — Today / This Month
   (netted against sales for the "Net" cards below)
========================= */
$pdo->exec("
CREATE TABLE IF NOT EXISTS expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  category VARCHAR(50) NOT NULL,
  description VARCHAR(255) NULL,
  amount DECIMAL(10,2) NOT NULL,
  expense_date DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  INDEX (expense_date)
)
");
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id=? AND expense_date=?");
$stmt->execute([$user_id, $selectedDate]);
$totalExpensesToday = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id=? AND MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())");
$stmt->execute([$user_id]);
$totalExpensesMonth = (float) $stmt->fetchColumn();

$netToday = (float) $totalSalesToday - $totalExpensesToday;
$netMonth = (float) $totalSalesMonth - $totalExpensesMonth;

/* =========================
   TOP SELLING PRODUCT THIS WEEK
========================= */
$stmt = $pdo->prepare("
SELECT p.name, SUM(s.qty) AS total
FROM (
    SELECT product_id, quantity_sold AS qty FROM distributor_sales
    WHERE user_id=? AND YEARWEEK(sale_date,1)=YEARWEEK(CURDATE(),1)
    UNION ALL
    SELECT wsi.product_id, wsi.quantity AS qty
    FROM walkin_sales_items wsi
    JOIN walkin_sales ws ON ws.id = wsi.sale_id
    WHERE ws.user_id=? AND YEARWEEK(ws.created_at,1)=YEARWEEK(CURDATE(),1)
) s
JOIN products p ON p.id = s.product_id
GROUP BY s.product_id ORDER BY total DESC LIMIT 1
");
$stmt->execute([$user_id, $user_id]);
$topProduct = $stmt->fetch();

/* =========================
   TOP DISTRIBUTOR
========================= */
$stmt = $pdo->prepare("
SELECT d.name, SUM(ds.quantity_sold) as total
FROM distributor_sales ds
JOIN distributors d ON d.id = ds.distributor_id
WHERE ds.user_id=? AND MONTH(ds.sale_date)=MONTH(CURDATE()) AND YEAR(ds.sale_date)=YEAR(CURDATE())
GROUP BY ds.distributor_id ORDER BY total DESC LIMIT 1
");
$stmt->execute([$user_id]);
$topDistributor = $stmt->fetch();

/* =========================
   TOP DISTRIBUTOR RANKING
========================= */
$stmt = $pdo->prepare("
SELECT d.name, SUM(ds.quantity_sold) as total
FROM distributor_sales ds
JOIN distributors d ON d.id = ds.distributor_id
WHERE ds.user_id=? AND MONTH(ds.sale_date)=MONTH(CURDATE()) AND YEAR(ds.sale_date)=YEAR(CURDATE())
GROUP BY ds.distributor_id, d.name
ORDER BY total DESC, d.name ASC
");
$stmt->execute([$user_id]);
$topDistributorList = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   [SEARCH TAG: LOW_STOCK_ALERT / LOW_STOCK_THRESHOLD / INVENTORY_WARNING_LIMIT / REORDER_POINT / STOCK_ALERT_QUANTITY]
   Default threshold: <= 10 units for BOX, PCS, and BOTTLE
============================================================ */
$stmt = $pdo->prepare("
SELECT name, box_qty, pcs_qty, bottle_qty, unit_type FROM products
WHERE user_id=? AND (
    (unit_type='BOX' AND box_qty <= 10)
 OR (unit_type='PCS' AND pcs_qty <= 10)
 OR (unit_type='BOTTLE' AND bottle_qty <= 10)
) ORDER BY name
");
$stmt->execute([$user_id]);
$lowStock = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total products
$stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE user_id=? AND is_active=1");
$stmt->execute([$user_id]);
$totalProducts = $stmt->fetchColumn();

// Total quantity
$stmt = $pdo->prepare("SELECT SUM(box_qty + pcs_qty + bottle_qty) FROM products WHERE user_id=? AND is_active=1");
$stmt->execute([$user_id]);
$totalQuantity = $stmt->fetchColumn() ?: 0;

/* ================= TOTAL STOCK VALUE (SRP) ================= */
$stmt = $pdo->prepare("SELECT SUM((box_qty * srp_price) + (pcs_qty * CEIL(srp_price / 10)) + (bottle_qty * srp_price)) FROM products WHERE user_id=? AND is_active=1");
$stmt->execute([$user_id]);
$totalSRPValue = $stmt->fetchColumn() ?: 0;

/* ================= TOTAL STOCK VALUE (MEMBER PRICE) ================= */
$stmt = $pdo->prepare("SELECT SUM((box_qty * member_price) + (pcs_qty * CEIL(member_price / 10)) + (bottle_qty * member_price)) FROM products WHERE user_id=? AND is_active=1");
$stmt->execute([$user_id]);
$totalMemberValue = $stmt->fetchColumn() ?: 0;

/* ================= MOST SOLD PRODUCTS (THIS WEEK) — ALL ================= */
$stmt = $pdo->prepare("
SELECT p.name, COALESCE(SUM(x.qty),0) AS total_sold
FROM products p
LEFT JOIN (
    SELECT product_id, quantity_sold AS qty, sale_date AS d, user_id FROM distributor_sales
    UNION ALL
    SELECT wsi.product_id, wsi.quantity AS qty, DATE(ws.created_at) AS d, ws.user_id
    FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id = wsi.sale_id
) x ON p.id = x.product_id AND x.user_id = ? AND YEARWEEK(x.d,1) = YEARWEEK(CURDATE(),1)
WHERE p.user_id=? AND p.is_active=1
GROUP BY p.id
ORDER BY total_sold DESC, p.name ASC
");
$stmt->execute([$user_id, $user_id]);
$topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= CATEGORY MAPPING ================= */
// Define product categories based on product name patterns
function getProductCategory(string $name): string {
    $n = strtoupper($name);
    if (str_contains($n, 'COFFEE')) return 'Coffee';
    if (str_contains($n, 'LB ') && (
        str_contains($n, 'LIPSTICK') || str_contains($n, 'LIPTINT') ||
        str_contains($n, 'MASCARA') || str_contains($n, 'EYEBROW') ||
        str_contains($n, 'EYELINER') || str_contains($n, 'PRESSED POWDER') ||
        str_contains($n, 'GLASS SKIN') || str_contains($n, 'BULLET')
    )) return 'LB Cosmetics';
    if (str_contains($n, 'LB ')) return 'LB Skincare';
    if (str_contains($n, 'PERFUME') || str_contains($n, 'COLOGNE')) return 'Fragrance';
    if (str_contains($n, 'LINIMENT') || str_contains($n, 'VAPORUB') ||
        str_contains($n, 'HERBAL OIL') || str_contains($n, 'TURMERIC') ||
        str_contains($n, 'CURCUMIN')) return 'Health & Wellness';
    return 'Household';
}

// Category colors
$categoryColors = [
    'Coffee'           => ['bg' => 'rgba(139,90,43,0.85)',    'border' => '#8b5a2b', 'hex' => '#8b5a2b', 'light' => '#f5ede4'],
    'LB Cosmetics'     => ['bg' => 'rgba(219,112,147,0.85)',  'border' => '#db7093', 'hex' => '#db7093', 'light' => '#fdf0f4'],
    'LB Skincare'      => ['bg' => 'rgba(106,168,138,0.85)',  'border' => '#6aa88a', 'hex' => '#6aa88a', 'light' => '#eef7f3'],
    'Fragrance'        => ['bg' => 'rgba(111,99,179,0.85)',   'border' => '#6f63b3', 'hex' => '#6f63b3', 'light' => '#f2f1fb'],
    'Health & Wellness'=> ['bg' => 'rgba(64,170,196,0.85)',   'border' => '#40aac4', 'hex' => '#40aac4', 'light' => '#e9f6fa'],
    'Household'        => ['bg' => 'rgba(90,136,173,0.85)',   'border' => '#5a88ad', 'hex' => '#5a88ad', 'light' => '#edf3f8'],
];

// Group products by category
$byCategory = [];
foreach ($topProducts as $p) {
    $cat = getProductCategory($p['name']);
    if (!isset($byCategory[$cat])) $byCategory[$cat] = [];
    $byCategory[$cat][] = $p;
}
// Remove categories with 0 total sales across all products (optional: keep all)
// We keep all active categories

// Encode for JS
$chartData = [];
foreach ($byCategory as $cat => $products) {
    $names = array_column($products, 'name');
    $sales = array_map(fn($p) => (int)$p['total_sold'], $products);
    $color = $categoryColors[$cat] ?? ['bg'=>'rgba(99,99,99,.8)','border'=>'#636363','hex'=>'#636363','light'=>'#f5f5f5'];
    $chartData[] = [
        'category' => $cat,
        'names'    => $names,
        'sales'    => $sales,
        'color'    => $color,
    ];
}

$notifCount = 0;
$currentHour = date('H');
if (!$reportSubmitted && $currentHour >= 16 && $currentHour <= 23) $notifCount++;
$notifCount += count($lowStock);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Dashboard - Fil-Rise Inventory Management System</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="icon" type="image/png" href="7.png">
  <link rel="stylesheet" href="mobile.css">
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    body {
      font-family: 'Poppins', sans-serif;
      background: #f1f3f6;
      min-height: 100vh;
      margin: 0;
      display: flex;
      color: #fff;
    }

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

    html { background: #f1f3f6; }

    /* ===== MAIN ===== */
    .main {
      margin-left: 260px;
      padding: 32px 36px;
      flex: 1;
      min-height: 100vh;
      background: #f1f3f6;
    }
    .header {
      display: flex; justify-content: space-between;
      align-items: center; margin-bottom: 28px;
    }
    .header h3 { font-size: 28px; font-weight: 700; color: #111827; }

    /* ===== STAT CARDS ===== */
    .stat-card {
      background: #fff;
      border-radius: 14px;
      padding: 20px 22px;
      box-shadow: 0 4px 14px rgba(0,0,0,.07);
      position: relative;
      transition: .25s;
      margin-bottom: 14px;
    }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(0,0,0,.11); }
    .stat-title { font-size: 14px; color: #111827; font-weight: 600; }
    .stat-value { font-size: 24px; font-weight: 700; color: #111827; margin-top: 2px; }
    .stat-icon { position: absolute; right: 18px; top: 16px; width: 20px; height: 20px; color: #6b7280; }
    .border-blue   { border-left: 5px solid #3b82f6; }
    .border-green  { border-left: 5px solid #22c55e; }
    .border-orange { border-left: 5px solid #f97316; }
    .border-purple { border-left: 5px solid #8b5cf6; }
    .icon-blue   { color: #3b82f6; }
    .icon-green  { color: #22c55e; }
    .icon-orange { color: #f97316; }
    .icon-purple { color: #8b5cf6; }

    /* ===== 2-COLUMN LAYOUT ===== */
    .dashboard-grid {
      display: grid;
      grid-template-columns: 1fr 320px;
      gap: 24px;
      align-items: start;
    }
    .charts-col {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 18px;
    }
    .cards-col  { display: flex; flex-direction: column; gap: 0; }

    /* ===== CATEGORY CHART CARD ===== */
    .chart-card {
      background: #fff;
      border-radius: 16px;
      padding: 22px 24px;
      box-shadow: 0 4px 16px rgba(0,0,0,.07);
    }
    .chart-card-header {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 14px;
    }
    .cat-badge {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 13px;
      font-weight: 600;
      color: #fff;
    }
    .chart-card canvas { max-height: 190px; }
    .charts-section-label { grid-column: 1 / -1; }

    /* ===== TOP STATS ROW ===== */
    .top-stat-row {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 16px;
      margin-bottom: 24px;
    }
    .top-stat-card {
      background: #fff;
      border-radius: 14px;
      padding: 18px 20px;
      box-shadow: 0 4px 14px rgba(0,0,0,.07);
      position: relative;
      transition: .2s;
    }
    .top-stat-card:hover { transform: translateY(-2px); }
    .top-stat-card .label { font-size: 13px; color: #111827; font-weight: 600; }
    .top-stat-card .value { font-size: 20px; font-weight: 700; color: #111827; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .top-stat-card .sub { font-size: 13px; color: #374151; font-weight: 500; margin-top: 1px; }
    .sales-cal-btn {
      position: absolute; right: 14px; top: 14px;
      display: flex; align-items: center; justify-content: center;
      width: 36px; height: 36px; border-radius: 8px;
      background: #3b82f6;
      box-shadow: 0 3px 8px rgba(59,130,246,0.45);
      cursor: pointer; overflow: hidden;
      transition: background 0.15s, transform 0.1s;
    }
    .sales-cal-btn:hover { background: #2563eb; transform: scale(1.08); }
    .sales-cal-btn i { color: #fff; pointer-events: none; width: 20px; height: 20px; }
    .sales-cal-btn span { display: none; }
    .sales-cal-btn input[type="date"] {
      position: absolute; inset: 0; opacity: 0; cursor: pointer;
      width: 100%; height: 100%; border: none; padding: 0;
    }
    .sales-cal-btn input[type="date"]::-webkit-calendar-picker-indicator {
      position: absolute; inset: 0; width: 100%; height: 100%;
      opacity: 0; cursor: pointer;
    }

    footer { text-align: center; color: #9ca3af; margin-top: 36px; font-size: 13px; }


    .distributor-card-click { cursor: pointer; }
    .distributor-card-click:hover { transform: translateY(-3px); box-shadow: 0 10px 24px rgba(0,0,0,.13); }

    /* Category color dots in legend */
    .cat-dot { width: 11px; height: 11px; border-radius: 50%; display: inline-block; }
  </style>
</head>
<body>
<?php include 'ticker.php'; ?>
<?php include 'lowstock_popup.php'; ?>
<!-- SIDEBAR -->
<div class="sidebar">
<?= fr_locked_style() ?>
  <img src="7.png" class="logo" alt="Fil-Rise Logo">
  <div class="menu-title">Core</div>
  <a href="dashboard.php" class="active">Dashboard</a>
  <a <?= fr_href('ai_forecast.php') ?> style="background:linear-gradient(90deg,rgba(6,182,212,.15),rgba(59,130,246,.15));color:#06b6d4;border:1px solid rgba(6,182,212,.25);">AI Dashboard</a>
  <div class="menu-title">Inventory</div>
  <a <?= fr_href('product.php') ?>>Products</a>
  <a <?= fr_href('removed_products.php') ?>>Archived Products</a>
  <div class="menu-title">Operations</div>
  <a <?= fr_href('transaction.php') ?>>Consignment Transactions</a>
  <a <?= fr_href('sales.php') ?>>Walk-in Sales</a>
  <a <?= fr_href('returns.php') ?>>Product Returns</a>
  <a <?= fr_href('stock_requests.php') ?> id="srDashNavLink">
    Stock Requests
    <span id="srDashBadge" style="margin-left:auto;background:#ef4444;color:#fff;font-size:11px;font-weight:700;border-radius:999px;padding:2px 7px;display:none;"></span>
  </a>
  <a <?= fr_href('daily_records.php') ?>>Daily Records</a>
  <a <?= fr_href('daily_sales_record.php') ?>>Daily Sales Record</a>
  <a href="expenses.php">Expenses</a>
  <div class="menu-title">People</div>
  <a <?= fr_href('distributor.php') ?>>Distributors</a>
  <a <?= fr_href('members.php') ?>>Members</a>
  <div class="logout" onclick="window.location='logout.php'">Logout</div>
</div>

<!-- MAIN -->
<div class="main">

  <?= fr_locked_banner() ?>

  <!-- HEADER -->
  <div class="header">
    <h3>Welcome, <?php echo htmlspecialchars($_SESSION['fullname']); ?></h3>
    <div class="d-flex align-items-center gap-3">

      <!-- Notification Bell -->
      <div style="position:relative;display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;cursor:pointer;flex-shrink:0;" onclick="toggleNotifications()">
        <span id="bellIcon" style="font-size:20px;line-height:1;">🔔</span>
        <?php if ($notifCount > 0): ?>
          <span id="notifCount" class="position-absolute badge rounded-pill bg-danger"
            style="top:-4px;right:-6px;font-size:10px;padding:2px 5px;line-height:1.2;min-width:18px;text-align:center;"><?= $notifCount ?></span>
        <?php endif; ?>
      </div>

      <div class="text-muted" style="color:#111827!important;font-size:14px;font-weight:600;">
        Today: <?php echo date('F d, Y'); ?>
      </div>

      <?php if (!empty($lowStock)): ?>
        <button id="voiceAlertBtn" onclick="speakStockAlerts()" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:7px 14px;font-size:13px;font-weight:600;color:#374151;cursor:pointer;display:flex;align-items:center;gap:6px;transition:.2s;box-shadow:0 2px 6px rgba(0,0,0,.06);"
          onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='#fff'">
          🔊 Read Stock Alerts
        </button>
      <?php endif; ?>

    </div>
  </div>

  <!-- NOTIFICATION PANEL -->
  <div id="notificationPanel" style="display:none;position:absolute;right:40px;top:80px;width:300px;background:white;border-radius:10px;box-shadow:0 10px 20px rgba(0,0,0,.2);padding:15px;z-index:999;">
    <h6 class="mb-3" style="color:#111827;">Notifications</h6>
    <?php
    $currentHour = date('H');
    if (!$reportSubmitted && $currentHour >= 16 && $currentHour < 18): ?>
      <div class="alert alert-warning p-2 mb-2">⚠ Daily report must be submitted before 6:00 PM.</div>
    <?php elseif (!$reportSubmitted && $currentHour >= 18 && $currentHour <= 23): ?>
      <div class="alert alert-danger p-2 mb-2">⛔ You are LATE. Daily report was due at 6:00 PM.</div>
    <?php endif; ?>
    <?php foreach ($lowStock as $stock): ?>
      <div class="alert alert-danger p-2 mb-2">
        ⚠ Low Stock: <b><?= htmlspecialchars($stock['name']) ?></b><br>
        <?php
          if ($stock['unit_type']==='BOX')    echo "Boxes: ".$stock['box_qty'];
          if ($stock['unit_type']==='PCS')    echo "PCS: ".$stock['pcs_qty'];
          if ($stock['unit_type']==='BOTTLE') echo "Bottles: ".$stock['bottle_qty'];
        ?>
      </div>
    <?php endforeach; ?>
  </div>

  <audio id="notifSound"><source src="notification.mp3" type="audio/mpeg"></audio>

  <!-- TOP 3 KPI CARDS -->
  <div class="top-stat-row">
    <div class="top-stat-card border-blue">
      <div class="label">Total Sales Today</div>
      <div class="value" id="salesTodayValue">₱<?= number_format($totalSalesToday, 2) ?></div>
      <div class="sub" id="salesTodaySub"><?= htmlspecialchars($salesDateLabel) ?></div>
      <i data-lucide="banknote" class="stat-icon icon-blue"></i>
      <div class="sales-cal-btn" id="salesCalBtn" title="Pick a date to view sales">
        <i data-lucide="calendar"></i>
        <input type="date" id="salesDatePicker"
          value="<?= htmlspecialchars($selectedDate) ?>"
          max="<?= $today ?>">
      </div>
    </div>
    <div class="top-stat-card border-green">
      <div class="label">Top Product This Week</div>
      <div class="value"><?= $topProduct['name'] ?? 'No Sales' ?></div>
      <div class="sub"><?= $topProduct['total'] ?? 0 ?> sold</div>
      <i data-lucide="package" class="stat-icon icon-green"></i>
    </div>
    <div class="top-stat-card border-orange distributor-card-click" data-bs-toggle="modal" data-bs-target="#topDistributorModal">
      <div class="label">Top Distributor This Month</div>
      <div class="value"><?= $topDistributor['name'] ?? 'No Sales' ?></div>
      <div class="sub"><?= $topDistributor['total'] ?? 0 ?> sold</div>
      <i data-lucide="users" class="stat-icon icon-orange"></i>
    </div>
  </div>

  <!-- EXPENSES / NET ROW -->
  <div class="top-stat-row">
    <div class="top-stat-card border-orange">
      <div class="label">Expenses (<?= htmlspecialchars($salesDateLabel) ?>)</div>
      <div class="value" style="color:#dc2626;">₱<?= number_format($totalExpensesToday, 2) ?></div>
      <div class="sub"><a href="expenses.php" style="color:#374151;">View / log expenses →</a></div>
      <i data-lucide="receipt" class="stat-icon icon-orange"></i>
    </div>
    <div class="top-stat-card <?= $netToday >= 0 ? 'border-green' : 'border-orange' ?>">
      <div class="label">Net (<?= htmlspecialchars($salesDateLabel) ?>)</div>
      <div class="value" style="color:<?= $netToday >= 0 ? '#16a34a' : '#dc2626' ?>;">₱<?= number_format($netToday, 2) ?></div>
      <div class="sub">Sales minus expenses</div>
      <i data-lucide="wallet" class="stat-icon <?= $netToday >= 0 ? 'icon-green' : 'icon-orange' ?>"></i>
    </div>
    <div class="top-stat-card <?= $netMonth >= 0 ? 'border-green' : 'border-orange' ?>">
      <div class="label">Net This Month</div>
      <div class="value" style="color:<?= $netMonth >= 0 ? '#16a34a' : '#dc2626' ?>;">₱<?= number_format($netMonth, 2) ?></div>
      <div class="sub">₱<?= number_format($totalSalesMonth, 2) ?> sales − ₱<?= number_format($totalExpensesMonth, 2) ?> expenses</div>
      <i data-lucide="calendar-check" class="stat-icon <?= $netMonth >= 0 ? 'icon-green' : 'icon-orange' ?>"></i>
    </div>
  </div>

  <!-- MAIN 2-COLUMN GRID -->
  <div class="dashboard-grid">

    <!-- LEFT: CATEGORY CHARTS -->
    <div class="charts-col">
      <div class="charts-section-label" style="color:#111827;font-size:16px;font-weight:700;margin-bottom:8px;">
        Most Bought Products This Week — By Category
      </div>
      <div class="charts-section-label" style="display:flex;align-items:center;flex-wrap:wrap;gap:16px;margin-bottom:16px;background:#fff;border-radius:10px;padding:10px 16px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <?php foreach ($categoryColors as $catName => $c): ?>
          <div style="display:flex;align-items:center;gap:6px;">
            <span class="cat-dot" style="background:<?= $c['hex'] ?>;"></span>
            <span style="font-size:13px;color:#111827;font-weight:600;"><?= $catName ?></span>
          </div>
        <?php endforeach; ?>
      </div>

      <?php foreach ($chartData as $cd):
        $cat   = $cd['category'];
        $color = $cd['color'];
        $hasData = array_sum($cd['sales']) > 0;
      ?>
      <div class="chart-card">
        <div class="chart-card-header">
          <span class="cat-badge" style="background:<?= $color['hex'] ?>;"><?= htmlspecialchars($cat) ?></span>
          <?php if (!$hasData): ?>
            <span style="font-size:12px;color:#9ca3af;margin-left:4px;">No sales this week</span>
          <?php endif; ?>
        </div>
        <?php if ($hasData): ?>
          <canvas id="chart_<?= md5($cat) ?>" style="max-height:200px;"></canvas>
        <?php else: ?>
          <div style="height:56px;display:flex;align-items:center;justify-content:center;">
            <span style="font-size:13px;color:#d1d5db;">—</span>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- RIGHT: STACKED STAT CARDS -->
    <div class="cards-col">
      <div style="color:#111827;font-size:16px;font-weight:700;margin-bottom:14px;">
         Inventory Overview
      </div>

      <div class="stat-card border-blue" style="border-left-color:#22c55e;">
        <div class="stat-title">Total Sales This Week</div>
        <div class="stat-value" style="font-size:18px;color:#16a34a;">₱<?= number_format($totalSalesWeek, 2) ?></div>
        <div style="font-size:13px;color:#374151;font-weight:500;margin-top:2px;"><?= date('D, M d') ?> (Mon–Sun)</div>
        <i data-lucide="trending-up" class="stat-icon" style="color:#22c55e;"></i>
      </div>

      <div class="stat-card border-blue" style="border-left-color:#3b82f6;">
        <div class="stat-title">Total Sales This Month</div>
        <div class="stat-value" style="font-size:18px;color:#1d4ed8;">₱<?= number_format($totalSalesMonth, 2) ?></div>
        <div style="font-size:13px;color:#374151;font-weight:500;margin-top:2px;"><?= date('F Y') ?></div>
        <i data-lucide="bar-chart-2" class="stat-icon" style="color:#3b82f6;"></i>
      </div>

      <div class="stat-card border-blue">
        <div class="stat-title">Total Active Products</div>
        <div class="stat-value"><?= $totalProducts ?></div>
        <i data-lucide="box" class="stat-icon icon-blue"></i>
      </div>

      <div class="stat-card border-orange">
        <div class="stat-title">Stock Value (Non-Member SRP)</div>
        <div class="stat-value" style="font-size:20px;">₱<?= number_format($totalSRPValue, 2) ?></div>
        <i data-lucide="banknote" class="stat-icon icon-orange"></i>
      </div>

      <div class="stat-card border-purple">
        <div class="stat-title">Stock Value (Member Price)</div>
        <div class="stat-value" style="font-size:20px;">₱<?= number_format($totalMemberValue, 2) ?></div>
        <i data-lucide="banknote" class="stat-icon icon-purple"></i>
      </div>


    </div>
  </div>


  <footer>© 2025 Fil-Rise Inventory Management System</footer>
</div>

<!-- TOP DISTRIBUTOR MODAL -->
<div class="modal fade" id="topDistributorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="border-radius:18px;border:none;">
      <div class="modal-header" style="background:#f97316;color:white;border-top-left-radius:18px;border-top-right-radius:18px;">
        <h5 class="modal-title">Top Distributors This Month</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if (empty($topDistributorList)): ?>
          <div class="text-muted">No distributor sales this month.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-bordered align-middle">
              <thead class="table-light">
                <tr><th style="width:80px;">Rank</th><th>Distributor</th><th class="text-center">Qty Sold</th></tr>
              </thead>
              <tbody>
                <?php foreach ($topDistributorList as $index => $dist): ?>
                  <tr>
                    <td class="fw-bold">#<?= $index + 1 ?></td>
                    <td><?= htmlspecialchars($dist['name']) ?></td>
                    <td class="text-center fw-semibold"><?= (int)$dist['total'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- TOAST CONTAINER -->
<div id="toastContainer" class="position-fixed bottom-0 end-0 p-3" style="z-index:9999;">
  <div id="reportToast" class="toast align-items-center text-bg-warning border-0" role="alert" style="min-width:280px;max-width:350px;">
    <div class="d-flex">
      <div class="toast-body">
        <?php if (date('H') >= 18): ?>⛔ It is past 6:00 PM. You are LATE submitting the Daily Report.
        <?php else: ?>⚠ Submit the Daily Report before 6:00 PM.<?php endif; ?>
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* ============================================================
   CATEGORY CHARTS — one horizontal bar chart per category
============================================================ */
const chartData = <?= json_encode($chartData, JSON_HEX_TAG) ?>;

chartData.forEach(function(cd) {
  if (cd.sales.reduce((a,b) => a+b, 0) === 0) return; // skip empty
  const canvasId = 'chart_' + md5(cd.category);
  const canvas   = document.getElementById(canvasId);
  if (!canvas) return;

  new Chart(canvas, {
    type: 'bar',
    data: {
      labels: cd.names,
      datasets: [{
        label: 'Units Sold',
        data:  cd.sales,
        backgroundColor: cd.color.bg,
        borderColor:     cd.color.border,
        borderWidth: 1.5,
        borderRadius: 5,
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => ` ${ctx.parsed.x} units sold`
          }
        }
      },
      scales: {
        x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.05)' }, ticks: { font: { family:'Poppins', size:11 } } },
        y: { grid: { display: false }, ticks: { font: { family:'Poppins', size:11 } } }
      }
    }
  });
});

// Tiny md5-like hash for canvas IDs (matches PHP md5)
function md5(str) {
  // We replicate the PHP md5 via a lookup generated server-side
  const map = <?= json_encode(array_combine(
    array_map(fn($c) => $c['category'], $chartData),
    array_map(fn($c) => md5($c['category']), $chartData)
  )) ?>;
  return map[str] || str.replace(/\s+/g,'_');
}
</script>

<script>
const reportSubmitted = <?= $reportSubmitted ? 'true' : 'false' ?>;

function checkReportReminder() {
  const hour = new Date().getHours();
  if (hour >= 16 && hour <= 23 && !reportSubmitted) {
    const t = document.getElementById("reportToast");
    new bootstrap.Toast(t, { delay: 10000 }).show();
  }
}
checkReportReminder();

function showToast(message, link = null, type = "warning") {
  const container = document.getElementById("toastContainer");
  const sound = document.getElementById("notifSound");
  if (sound) { sound.currentTime = 0; sound.play(); }
  const toast = document.createElement("div");
  let color = type === "danger" ? "bg-danger" : type === "info" ? "bg-info" : "bg-warning";
  toast.className = `toast align-items-center text-white ${color} border-0 show mb-2 shadow`;
  toast.style.minWidth = "280px";
  toast.style.cursor = link ? "pointer" : "default";
  toast.innerHTML = `<div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto"></button></div>`;
  if (link) toast.onclick = () => window.location = link;
  toast.querySelector(".btn-close").onclick = () => toast.remove();
  container.appendChild(toast);
  setTimeout(() => toast.remove(), 5000);
}

function toggleNotifications() {
  const p = document.getElementById("notificationPanel");
  const isOpen = p.style.display === "block";

  if (isOpen) {
    closeNotifications();
  } else {
    p.style.display = "block";
    const badge = document.getElementById("notifCount");
    if (badge) badge.style.display = "none";
    document.getElementById("bellIcon").classList.remove("alert");

    // Add tap-outside overlay on mobile
    if (window.innerWidth <= 992) {
      let overlay = document.getElementById("notifOverlay");
      if (!overlay) {
        overlay = document.createElement("div");
        overlay.id = "notifOverlay";
        overlay.style.cssText = "position:fixed;inset:0;z-index:99994;background:transparent;";
        overlay.onclick = closeNotifications;
        document.body.appendChild(overlay);
      }
      overlay.style.display = "block";
    }
  }
}

function closeNotifications() {
  const p = document.getElementById("notificationPanel");
  p.style.display = "none";
  const overlay = document.getElementById("notifOverlay");
  if (overlay) overlay.style.display = "none";
}

<?php
if (!empty($lowStock)):
  echo 'showToast("⚠ Some products are LOW STOCK.", "product.php?low=1", "danger");';
endif;
?>

document.addEventListener("DOMContentLoaded", function () {
  const notifCount = <?= $notifCount ?>;
  if (notifCount > 0) document.getElementById("bellIcon").classList.add("alert");
});
</script>

<script>
lucide.createIcons();

// Sales date picker - AJAX, no full page reload
const salesDatePicker = document.getElementById('salesDatePicker');
if (salesDatePicker) {
  salesDatePicker.addEventListener('change', function() {
    const date = this.value;
    const valueEl = document.getElementById('salesTodayValue');
    const subEl   = document.getElementById('salesTodaySub');
    valueEl.style.opacity = '0.4';
    fetch('dashboard.php?ajax_sales_date=1&sales_date=' + encodeURIComponent(date))
      .then(r => r.json())
      .then(data => {
        valueEl.textContent = '₱' + data.amount;
        subEl.textContent   = data.label;
        valueEl.style.opacity = '1';
      })
      .catch(() => { valueEl.style.opacity = '1'; });
  });
}
</script>

<!-- VOICE ALERT -->
<script>
(function () {
  const lowStockItems = <?php
    $voiceItems = [];
    foreach ($lowStock as $s) {
      $qty = 0; $unit = '';
      if ($s['unit_type']==='BOX')    { $qty=(int)$s['box_qty'];    $unit=$qty==1?'box':'boxes'; }
      if ($s['unit_type']==='PCS')    { $qty=(int)$s['pcs_qty'];    $unit=$qty==1?'piece':'pieces'; }
      if ($s['unit_type']==='BOTTLE') { $qty=(int)$s['bottle_qty']; $unit=$qty==1?'bottle':'bottles'; }
      $voiceItems[] = ['name'=>$s['name'],'qty'=>$qty,'unit'=>$unit,'out'=>$qty===0];
    }
    echo json_encode($voiceItems);
  ?>;

  function buildSpeech() {
    if (!lowStockItems.length) return null;
    const out = lowStockItems.filter(i => i.out);
    const low = lowStockItems.filter(i => !i.out);
    let msg = 'Stock alert. ';
    if (out.length) msg += out.length === 1 ? `${out[0].name} is out of stock. ` : `Out of stock: ${out.map(i=>i.name).join(', ')}. `;
    low.forEach(i => { msg += `${i.name} is low with only ${i.qty} ${i.unit} remaining. `; });
    return msg;
  }

  const btn = document.getElementById('voiceAlertBtn');

  async function speakAlerts() {
    const msg = buildSpeech();
    if (!msg) return;
    if (btn) { btn.disabled=true; btn.innerHTML='🔊 Loading...'; }
    try {
      const response = await fetch('voice_proxy.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ text: msg })
      });
      const data = await response.json();
      if (data.error) throw new Error(data.error);
      const binary = atob(data.audio);
      const bytes  = new Uint8Array(binary.length);
      for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
      const blob = new Blob([bytes], { type: 'audio/mpeg' });
      const audio = new Audio(URL.createObjectURL(blob));
      audio.play();
      audio.onended = () => { if (btn) { btn.disabled=false; btn.innerHTML='🔊 Read Stock Alerts'; } };
    } catch (e) {
      const utter = new SpeechSynthesisUtterance(msg);
      utter.lang = 'en-US';
      utter.rate = 0.92;
      utter.pitch = 1.05;
      // Pick a female voice — wait for voices to load first
      function speakWithFemaleVoice() {
        const voices = window.speechSynthesis.getVoices();
        // Prefer a high-quality female English voice
        const femalePrefs = [
          'Google US English',       // Chrome female (US)
          'Samantha',                 // macOS/iOS female
          'Karen',                    // macOS Australian female
          'Moira',                    // macOS Irish female
          'Tessa',                    // macOS South African female
          'Microsoft Zira',           // Windows female
          'Microsoft Jenny',          // Windows female
          'Microsoft Aria',           // Windows female
        ];
        let chosen = null;
        for (const name of femalePrefs) {
          chosen = voices.find(v => v.name.includes(name));
          if (chosen) break;
        }
        // Fallback: any female-sounding en-US voice
        if (!chosen) {
          chosen = voices.find(v => /female/i.test(v.name) && /en[-_]US/i.test(v.lang))
                || voices.find(v => /female/i.test(v.name) && /en/i.test(v.lang))
                || voices.find(v => /en[-_]US/i.test(v.lang) && !/male/i.test(v.name));
        }
        if (chosen) utter.voice = chosen;
        window.speechSynthesis.speak(utter);
        if (btn) { btn.disabled=false; btn.innerHTML='🔊 Read Stock Alerts'; }
      }
      // voices may not be ready yet on first page load
      if (window.speechSynthesis.getVoices().length) {
        speakWithFemaleVoice();
      } else {
        window.speechSynthesis.onvoiceschanged = speakWithFemaleVoice;
      }
    }
  }

  <?php if (!empty($lowStock)): ?>
  window.addEventListener('load', () => setTimeout(speakAlerts, 1500));
  <?php endif; ?>
  window.speakStockAlerts = speakAlerts;
})();
</script>

<script>
  // Live badge for pending inter-branch stock requests / items awaiting receipt
  fetch('stock_requests.php?ajax=notify')
    .then(r => r.json())
    .then(data => {
      const badge = document.getElementById('srDashBadge');
      if (!badge) return;
      const total = (data.incoming || 0) + (data.awaiting_receive || 0);
      if (total > 0) { badge.style.display = 'inline-block'; badge.textContent = total; }
    })
    .catch(() => {});
</script>

<?php include 'mobile_sidebar.php'; ?>
</body>
</html>
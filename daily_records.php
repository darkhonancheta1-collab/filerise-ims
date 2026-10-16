<?php
/**
 * daily_records.php — Daily Inventory Report (Branch Report Submission)
 *
 * Staff must submit a daily inventory report before 6:00 PM. The report
 * captures a snapshot of all product stock levels for the day and is
 * sent to the admin for review. Features:
 *   - Auto-populates current stock levels for all active products
 *   - Submit daily report (creates branch_reports + branch_report_items)
 *   - View past submitted reports with date navigation
 *   - Undo/resubmit capability for same-day reports
 *   - PDF export via daily_records_pdf.php
 *   - Late submission warnings (after 6 PM)
 *
 * Module-gated via branch_modules (daily_records).
 */
require 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* -- Authentication guard -- */
if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

$user_id = $_SESSION['user_id'];
require_once 'branch_modules.php';
require_once 'activity_log.php';
fr_require_module($pdo, 'daily_records');

/* Self-healing schema: lets a submitted report be undone (safe to run repeatedly) */
try { $pdo->exec("ALTER TABLE branch_reports ADD COLUMN undone_at DATETIME NULL"); } catch (Exception $e) {}
$date = $_GET['date'] ?? date('Y-m-d');
$mode = $_GET['mode'] ?? 'current';
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
$week = $_GET['week'] ?? null;
$month = $_GET['month'] ?? null;
$month_week = $_GET['month_week'] ?? null;

/* Preserve what the staff actually picked (for the dropdown + header label),
   then resolve WEEK / MONTH into a normal from/to range so all the existing
   'range' logic below can be reused as-is. */
$selectedMode = $mode;

if ($mode === 'week' && $week && preg_match('/^(\d{4})-W(\d{2})$/', $week, $wm)) {
  $wdt = new DateTime();
  $wdt->setISODate((int)$wm[1], (int)$wm[2]);
  $from = $wdt->format('Y-m-d');
  $wdt->modify('+6 days');
  $to = $wdt->format('Y-m-d');
  $mode = 'range';
} elseif ($mode === 'month' && $month && preg_match('/^\d{4}-\d{2}$/', $month)) {
  $from = date('Y-m-01', strtotime($month . '-01'));
  $to   = date('Y-m-t', strtotime($month . '-01'));

  /* Optional drill-down: a specific week WITHIN the chosen month
     (Week 1 = days 1-7, Week 2 = 8-14, Week 3 = 15-21, Week 4 = 22-28, Week 5 = 29-end) */
  if ($month_week !== null && $month_week !== '' && ctype_digit((string) $month_week)) {
    $wk = (int) $month_week;
    $daysInMonth = (int) date('t', strtotime($from));
    $dayStart = ($wk - 1) * 7 + 1;
    $dayEnd = min($wk * 7, $daysInMonth);
    if ($wk >= 1 && $wk <= 5 && $dayStart <= $daysInMonth) {
      $from = date('Y-m-', strtotime($from)) . str_pad((string) $dayStart, 2, '0', STR_PAD_LEFT);
      $to   = date('Y-m-', strtotime($to))   . str_pad((string) $dayEnd, 2, '0', STR_PAD_LEFT);
    }
  }

  $mode = 'range';
}


/* ============================
   FETCH PRODUCTS
============================= */
$stmt = $pdo->prepare("
    SELECT id, name, member_price, unit_type,
           box_qty, pcs_qty, bottle_qty
    FROM products
    WHERE user_id=? AND is_active=1
    ORDER BY name ASC
");
$stmt->execute([$user_id]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ============================
   FETCH SALES (DAILY OR RANGE)
============================= */

if ($mode === 'range' && $from && $to) {

  $salesStmt = $pdo->prepare("

        /* DISTRIBUTOR SALES */
        SELECT product_id, unit, SUM(quantity_sold) as total_sold
        FROM distributor_sales
        WHERE user_id=? 
        AND DATE(sale_date) BETWEEN ? AND ?
        GROUP BY product_id, unit

        UNION ALL

        /* WALK-IN SALES */
        SELECT wsi.product_id, wsi.unit, SUM(wsi.quantity) as total_sold
        FROM walkin_sales_items wsi
        JOIN walkin_sales ws ON ws.id = wsi.sale_id
        WHERE ws.user_id=? 
        AND DATE(ws.created_at) BETWEEN ? AND ?
        GROUP BY wsi.product_id, wsi.unit

    ");

  $salesStmt->execute([$user_id, $from, $to, $user_id, $from, $to]);

} else {

  $salesStmt = $pdo->prepare("

        /* DISTRIBUTOR SALES */
        SELECT product_id, unit, SUM(quantity_sold) as total_sold
        FROM distributor_sales
        WHERE user_id=? 
        AND DATE(sale_date) = ?
        GROUP BY product_id, unit

        UNION ALL

        /* WALK-IN SALES */
        SELECT wsi.product_id, wsi.unit, SUM(wsi.quantity) as total_sold
        FROM walkin_sales_items wsi
        JOIN walkin_sales ws ON ws.id = wsi.sale_id
        WHERE ws.user_id=? 
        AND DATE(ws.created_at) = ?
        GROUP BY wsi.product_id, wsi.unit

    ");

  $salesStmt->execute([$user_id, $date, $user_id, $date]);
}

$salesRows = $salesStmt->fetchAll(PDO::FETCH_ASSOC);












/* Organize sales properly */
$salesData = [];
$totalIndividualSales = 0;
$totalPackageSales = 0;
$totalOverallSales = 0;
$totalPackageCount = 0;

/* ── PACKAGE COUNTS per product (memberships sold today/range) ── */
/* Match packages to this branch's products by NAME since package product IDs
   may belong to a different branch (e.g. admin-defined packages use Salay IDs) */
$packageCounts = [];

// Get this branch's product name→id map
$branchProdStmt = $pdo->prepare("SELECT id, name FROM products WHERE user_id=? AND is_active=1");
$branchProdStmt->execute([$user_id]);
$branchProdMap = []; // name => id
foreach ($branchProdStmt->fetchAll(PDO::FETCH_ASSOC) as $bp) {
  $branchProdMap[strtolower(trim($bp['name']))] = $bp['id'];
}

if ($mode === 'range' && $from && $to) {
  $pkgStmt = $pdo->prepare("
    SELECT mp.package_name, mp.product1_id, mp.product2_id, COUNT(*) AS cnt,
           p1.name AS prod1_name, p2.name AS prod2_name
    FROM members m
    JOIN member_packages mp ON mp.id = m.package_product_id
    JOIN products p1 ON p1.id = mp.product1_id
    LEFT JOIN products p2 ON p2.id = mp.product2_id
    WHERE m.user_id = ? AND m.status = 'ACTIVE'
    AND DATE(m.created_at) BETWEEN ? AND ?
    AND m.package_product_id IS NOT NULL
    GROUP BY mp.id
  ");
  $pkgStmt->execute([$user_id, $from, $to]);
} else {
  $pkgStmt = $pdo->prepare("
    SELECT mp.package_name, mp.product1_id, mp.product2_id, COUNT(*) AS cnt,
           p1.name AS prod1_name, p2.name AS prod2_name
    FROM members m
    JOIN member_packages mp ON mp.id = m.package_product_id
    JOIN products p1 ON p1.id = mp.product1_id
    LEFT JOIN products p2 ON p2.id = mp.product2_id
    WHERE m.user_id = ? AND m.status = 'ACTIVE'
    AND DATE(m.created_at) = ?
    AND m.package_product_id IS NOT NULL
    GROUP BY mp.id
  ");
  $pkgStmt->execute([$user_id, $date]);
}
foreach ($pkgStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $cnt = (int)$r['cnt'];
  $totalPackageCount += $cnt * 2; // each package has 2 products

  // Match product1 to this branch by name
  $p1key = strtolower(trim($r['prod1_name'] ?? ''));
  if (isset($branchProdMap[$p1key])) {
    $packageCounts[$branchProdMap[$p1key]] = ($packageCounts[$branchProdMap[$p1key]] ?? 0) + $cnt;
  } else {
    // fallback: use original product1_id (works for Salay branch)
    $packageCounts[$r['product1_id']] = ($packageCounts[$r['product1_id']] ?? 0) + $cnt;
  }

  // Match product2 to this branch by name
  $p2key = strtolower(trim($r['prod2_name'] ?? ''));
  if ($p2key && isset($branchProdMap[$p2key])) {
    $packageCounts[$branchProdMap[$p2key]] = ($packageCounts[$branchProdMap[$p2key]] ?? 0) + $cnt;
  } else if ($r['product2_id']) {
    $packageCounts[$r['product2_id']] = ($packageCounts[$r['product2_id']] ?? 0) + $cnt;
  }
}

/* ── TOTAL PACKAGE SALES (sum of membership fees) ── */
if ($mode === 'range' && $from && $to) {
  $pkgSalesStmt = $pdo->prepare("
    SELECT COALESCE(SUM(m.membership_fee), 0) AS total
    FROM members m
    WHERE m.user_id = ? AND m.status = 'ACTIVE'
    AND DATE(m.created_at) BETWEEN ? AND ?
    AND m.package_product_id IS NOT NULL
  ");
  $pkgSalesStmt->execute([$user_id, $from, $to]);
} else {
  $pkgSalesStmt = $pdo->prepare("
    SELECT COALESCE(SUM(m.membership_fee), 0) AS total
    FROM members m
    WHERE m.user_id = ? AND m.status = 'ACTIVE'
    AND DATE(m.created_at) = ?
    AND m.package_product_id IS NOT NULL
  ");
  $pkgSalesStmt->execute([$user_id, $date]);
}
$totalPackageSales = (float)$pkgSalesStmt->fetchColumn();


foreach ($salesRows as $row) {
  $pid = $row['product_id'];
  $unit = $row['unit'];
  $qty = $row['total_sold'];

  if (!isset($salesData[$pid][$unit])) {
    $salesData[$pid][$unit] = 0;
  }

  $salesData[$pid][$unit] += $qty;



}



/* ===============================
SEND DAILY REPORT TO MAIN ADMIN
================================*/
if (isset($_POST['send_report'])) {

  foreach ($products as $p) {

    $pid = $p['id'];

    $soldBox = (int) ($salesData[$pid]['BOX'] ?? 0);
    $soldPcs = (int) ($salesData[$pid]['PCS'] ?? 0);
    $soldBottle = (int) ($salesData[$pid]['BOTTLE'] ?? 0);

    $pdo->prepare("
        UPDATE products
        SET
            box_qty = box_qty - ?,
            pcs_qty = pcs_qty - ?,
            bottle_qty = bottle_qty - ?
        WHERE id=? AND user_id=?
    ")->execute([
          $soldBox,
          $soldPcs,
          $soldBottle,
          $pid,
          $user_id
        ]);
  }
  // use selected mode/date (not always today)
  $reportMode = $_POST['mode'] ?? $mode;
  $reportDate = $_POST['date'] ?? $date;
  $reportFrom = $_POST['from'] ?? $from;
  $reportTo = $_POST['to'] ?? $to;

  // determine report date range
  if ($reportMode === 'range' && $reportFrom && $reportTo) {
    $start = $reportFrom;
    $end = $reportTo;
  } else {
    $start = $reportDate;
    $end = $reportDate;
  }

  // get branch name from users table (THIS fixes blank branch)
  $stmt = $pdo->prepare("SELECT branch_name FROM users WHERE id=?");
  $stmt->execute([$user_id]);
  $branch = $stmt->fetchColumn();
  if (!$branch)
    $branch = "Branch #" . $user_id;

  // check duplicate (same branch + same date range start/end)
  // (simpler: lock by report_date only) — ignore reports that were undone,
  // so the branch can correct and resubmit them
  $stmt = $pdo->prepare("SELECT id FROM branch_reports WHERE user_id=? AND report_date=? AND (status IS NULL OR status <> 'Undone') LIMIT 1");
  $stmt->execute([$user_id, $start]);
  if ($stmt->fetch()) {
    $success = "Daily report already submitted for that date.";
  } else {

    // compute total sales for that date/range (distributor + walkin + upgrade income)
    $stmt = $pdo->prepare("
      SELECT COALESCE(SUM(x.qty * x.price),0) AS total
      FROM (
        SELECT ds.quantity_sold AS qty, p.member_price AS price, DATE(ds.sale_date) AS d
        FROM distributor_sales ds
        JOIN products p ON p.id = ds.product_id
        WHERE ds.user_id=?

        UNION ALL

        SELECT wsi.quantity AS qty, p.member_price AS price, DATE(ws.created_at) AS d
        FROM walkin_sales_items wsi
        JOIN walkin_sales ws ON ws.id = wsi.sale_id
        JOIN products p ON p.id = wsi.product_id
        WHERE ws.user_id=?
      ) x
      WHERE x.d BETWEEN ? AND ?
    ");
    $stmt->execute([$user_id, $user_id, $start, $end]);
    $total_sales = (float) $stmt->fetchColumn();

    // Add upgrade income from returns processed on this date range
    $upgradeStmt = $pdo->prepare("
      SELECT COALESCE(SUM(upgrade_income), 0)
      FROM product_returns
      WHERE user_id=? AND return_type='UPGRADE' AND status='COMPLETED'
        AND DATE(returned_at) BETWEEN ? AND ?
    ");
    $upgradeStmt->execute([$user_id, $start, $end]);
    $upgrade_income_total = (float) $upgradeStmt->fetchColumn();
    $total_sales += $upgrade_income_total;

    // total transactions (count distributor sale rows + walkin sale receipts)
    $stmt = $pdo->prepare("
      SELECT
        (
          SELECT COUNT(*)
          FROM distributor_sales
          WHERE user_id=? AND DATE(sale_date) BETWEEN ? AND ?
        ) +
        (
          SELECT COUNT(*)
          FROM walkin_sales
          WHERE user_id=? AND DATE(created_at) BETWEEN ? AND ?
        ) AS total_txn
    ");
    $stmt->execute([$user_id, $start, $end, $user_id, $start, $end]);
    $total_transactions = (int) $stmt->fetchColumn();

    // build report table data from the already computed $salesData
    $report_table = [];
    foreach ($products as $p) {
      $pid = $p['id'];

      $soldBox = (int) ($salesData[$pid]['BOX'] ?? 0);
      $soldPcs = (int) ($salesData[$pid]['PCS'] ?? 0);
      $soldBottle = (int) ($salesData[$pid]['BOTTLE'] ?? 0);

      $pcs_price = ($p['unit_type'] === 'BOX')
        ? ceil($p['member_price'] / 10)
        : $p['member_price'];

      $amount =
        ($soldBox * (float) $p['member_price']) +
        ($soldPcs * (float) $pcs_price) +
        ($soldBottle * (float) $p['member_price']);

      $report_table[] = [
        "product_id" => $pid,
        "name" => $p['name'],
        "price" => (float) $p['member_price'],
        "sold_box" => $soldBox,
        "sold_pcs" => $soldPcs,
        "sold_bottle" => $soldBottle,
        "amount" => (float) $amount
      ];
    }

    $report_json = json_encode($report_table);

    // INSERT report
    $stmt = $pdo->prepare("
      INSERT INTO branch_reports
      (user_id, branch_name, report_date, total_sales, total_transactions, status, report_data, submitted_at)
      VALUES (?,?,?,?,?,?,?,NOW())
    ");
    $stmt->execute([
      $user_id,
      $branch,
      $start,                  // store start date as report_date
      $total_sales,
      $total_transactions,
      'Submitted',
      $report_json
    ]);

    $success = "Daily report successfully sent to Main Admin.";
  }

}

/* ===============================
UNDO A SUBMITTED DAILY REPORT
(gives the stock back and lets the branch fix + resend it;
 the report row is kept — marked 'Undone' — so the Main Admin
 can still see it happened)
================================*/
if (isset($_POST['undo_report'])) {

  $undoId = (int) ($_POST['report_id'] ?? 0);

  $stmt = $pdo->prepare("SELECT * FROM branch_reports WHERE id=? AND user_id=? AND status='Submitted' LIMIT 1");
  $stmt->execute([$undoId, $user_id]);
  $reportToUndo = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$reportToUndo) {
    $success = "That report can't be undone (already undone or not found).";
  } else {

    $undoItems = json_decode($reportToUndo['report_data'] ?? '[]', true);
    if (is_array($undoItems)) {
      foreach ($undoItems as $it) {
        $pdo->prepare("
          UPDATE products
          SET box_qty = box_qty + ?,
              pcs_qty = pcs_qty + ?,
              bottle_qty = bottle_qty + ?
          WHERE id=? AND user_id=?
        ")->execute([
          (int) ($it['sold_box'] ?? 0),
          (int) ($it['sold_pcs'] ?? 0),
          (int) ($it['sold_bottle'] ?? 0),
          $it['product_id'] ?? 0,
          $user_id
        ]);
      }
    }

    $pdo->prepare("UPDATE branch_reports SET status='Undone', undone_at=NOW() WHERE id=?")
        ->execute([$undoId]);

    fr_log_activity(
      $pdo,
      'daily_records',
      'undo',
      "Undid the daily report for " . date('F d, Y', strtotime($reportToUndo['report_date'])) .
      " — stock was restored so it can be corrected and resent. Main Admin can see this in the report status."
    );

    $success = "Report undone and stock restored. Record the missed item(s) and send the report again for that date.";
  }
}

/* ============================
   FETCH RETURNS SUMMARY FOR DISPLAY
============================= */
if ($mode === 'range' && $from && $to) {
  $retSummaryStmt = $pdo->prepare("
    SELECT return_type, COUNT(*) as cnt, COALESCE(SUM(upgrade_income),0) as income
    FROM product_returns
    WHERE user_id=? AND status='COMPLETED'
      AND DATE(returned_at) BETWEEN ? AND ?
    GROUP BY return_type
  ");
  $retSummaryStmt->execute([$user_id, $from, $to]);
} else {
  $retSummaryStmt = $pdo->prepare("
    SELECT return_type, COUNT(*) as cnt, COALESCE(SUM(upgrade_income),0) as income
    FROM product_returns
    WHERE user_id=? AND status='COMPLETED'
      AND DATE(returned_at) = ?
    GROUP BY return_type
  ");
  $retSummaryStmt->execute([$user_id, $date]);
}
$returnsSummary = [];
foreach ($retSummaryStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $returnsSummary[$r['return_type']] = $r;
}
$totalExchanges = (int)($returnsSummary['EXCHANGE']['cnt'] ?? 0);
$totalUpgrades  = (int)($returnsSummary['UPGRADE']['cnt'] ?? 0);
$totalUpgradeIncome = (float)($returnsSummary['UPGRADE']['income'] ?? 0);

/* ============================
   REPORT STATUS FOR THE DATE BEING VIEWED
   (drives the "Undo Report" button and the "Undone" banner)
============================= */
$displayReportDate = ($mode === 'range' && $from) ? $from : $date;
$stmt = $pdo->prepare("
  SELECT id, status, undone_at
  FROM branch_reports
  WHERE user_id=? AND report_date=?
  ORDER BY id DESC LIMIT 1
");
$stmt->execute([$user_id, $displayReportDate]);
$currentReport = $stmt->fetch(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Everyday Inventory Record</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="mobile.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="7.png">
  <style>
    body {
      font-family: 'Poppins', sans-serif;
      min-height: 100vh;
      margin: 0;
      display: flex;
      color: #0f172a;
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

    .sidebar .logo {
      width: 140px;
      margin-bottom: 30px;
    }

    .sidebar .menu-title {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: #94a3b8;
      margin-top: 20px;
      margin-bottom: 10px;
    }

    .sidebar a {
      color: #cbd5e1;
      text-decoration: none;
      padding: 10px 15px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 5px;
      transition: all 0.3s ease;
      font-size: 14px;
    }

    .sidebar a:hover {
      background: rgba(59, 130, 246, 0.2);
      color: #fff;
    }

    .sidebar a.active {
      background: linear-gradient(90deg, #2563eb, #3b82f6);
      color: #fff;
    }

    .logout {
      margin-top: auto;
      padding: 10px 15px;
      border-radius: 10px;
      background: rgba(239, 68, 68, 0.2);
      color: #cbd5e1;
      cursor: pointer;
      transition: 0.3s;
    }

    .logout:hover {
      background: rgba(239, 68, 68, 0.4);
    }

    .main {
      margin-left: 250px;
      padding: 40px;
      width: 100%;
    }

    .table th,
    .table td {
      text-align: center;
      font-size: 13px;
      vertical-align: middle;
    }

    .table td:first-child {
      text-align: left;
    }

    @media print {

      .sidebar,
      .no-print {
        display: none !important;
      }

      .main {
        margin: 0;
        padding: 0;
      }
    }
  </style>
</head>

<body>
  <?php include 'ticker.php'; ?>
  <div class="sidebar">
<?= fr_locked_style() ?>

    <img src="7.png" class="logo" alt="Fil-Rise Logo">

    <div class="menu-title">Core</div>
    <a href="dashboard.php"> Dashboard</a>
    <a <?= fr_href('ai_forecast.php') ?>
      style="background:linear-gradient(90deg,rgba(6,182,212,.15),rgba(59,130,246,.15));color:#06b6d4;border:1px solid rgba(6,182,212,.25);">
      AI Dashboard</a>
      
    <div class="menu-title">Inventory</div>
    <a <?= fr_href('product.php') ?>> Products</a>
    <a <?= fr_href('removed_products.php') ?>> Archived Products</a>

    <div class="menu-title">Operations</div>
    <a <?= fr_href('transaction.php') ?>>Consignment Transactions</a>
    <a <?= fr_href('sales.php') ?>> Walk-in Sales</a>
    <a <?= fr_href('returns.php') ?>>Product Returns</a>
    <a <?= fr_href('stock_requests.php') ?>>Stock Requests</a>
    <a <?= fr_href('daily_records.php') ?> class="active"> Daily Records</a>
    <a <?= fr_href('daily_sales_record.php') ?>> Daily Sales Record</a>
    <a href="expenses.php">Expenses</a>

    <div class="menu-title">People</div>
    <a <?= fr_href('distributor.php') ?>> Distributors</a>
    <a <?= fr_href('members.php') ?>> Members</a>

    <div class="logout" onclick="window.location='logout.php'">
      Logout
    </div>

  </div>

  <div class="main">
    <?php if (!empty($success)): ?>
      <div class="alert alert-success">
        <?= $success ?>
      </div>
    <?php endif; ?>
    <?php if ($currentReport && $currentReport['status'] === 'Undone'): ?>
      <div class="alert alert-warning no-print">
        This report was <strong>undone</strong> on
        <?= date('M d, Y h:i A', strtotime($currentReport['undone_at'])) ?>
        so the stock was restored. Record the missed item(s) then send the report again for this date.
      </div>
    <?php endif; ?>
    <div class="mb-4 no-print">

      <h4 class="mb-3">Everyday Inventory Record</h4>


      <!-- FILTER FORM (GET) -->
      <form method="GET">

        <div class="row align-items-end">

          <!-- FILTER FORM -->
          <div class="col-md-8">
            <form method="GET">

              <div class="row g-3">

                <div class="col-md-4">
                  <label class="form-label fw-semibold">Record Type</label>
                  <select name="mode" id="modeSelect" class="form-select">
                    <option value="current" <?= $selectedMode === 'current' ? 'selected' : '' ?>>Current Record</option>
                    <option value="range" <?= $selectedMode === 'range' ? 'selected' : '' ?>>Previous Record</option>
                    <option value="week" <?= $selectedMode === 'week' ? 'selected' : '' ?>>Weekly Record</option>
                    <option value="month" <?= $selectedMode === 'month' ? 'selected' : '' ?>>Monthly Record</option>
                  </select>
                </div>

                <div class="col-md-4" id="singleDate">
                  <label class="form-label fw-semibold">Date</label>
                  <input type="date" name="date" value="<?= $date ?>" class="form-control">
                </div>

                <div class="col-md-6 d-none" id="rangeDate">
                  <label class="form-label fw-semibold">Date Range</label>
                  <div class="d-flex gap-2">
                    <input type="date" name="from" value="<?= $from ?>" class="form-control">
                    <input type="date" name="to" value="<?= $to ?>" class="form-control">
                  </div>
                </div>

                <div class="col-md-4 d-none" id="weekPicker">
                  <label class="form-label fw-semibold">Week</label>
                  <input type="week" name="week" value="<?= htmlspecialchars($week ?? '') ?>" class="form-control">
                </div>

                <div class="col-md-4 d-none" id="monthPicker">
                  <label class="form-label fw-semibold">Month</label>
                  <input type="month" name="month" id="monthInput" value="<?= htmlspecialchars($month ?? '') ?>" class="form-control">
                </div>

                <div class="col-md-4 d-none" id="monthWeekPicker">
                  <label class="form-label fw-semibold">Week of Month</label>
                  <select name="month_week" id="monthWeekSelect" class="form-select">
                    <option value="">Whole Month</option>
                    <option value="1" <?= $month_week === '1' ? 'selected' : '' ?>>Week 1</option>
                    <option value="2" <?= $month_week === '2' ? 'selected' : '' ?>>Week 2</option>
                    <option value="3" <?= $month_week === '3' ? 'selected' : '' ?>>Week 3</option>
                    <option value="4" <?= $month_week === '4' ? 'selected' : '' ?>>Week 4</option>
                    <option value="5" <?= $month_week === '5' ? 'selected' : '' ?>>Week 5</option>
                  </select>
                </div>

              </div>

          </div>

          <!-- BUTTON AREA -->
          <div class="col-md-4 text-end d-flex justify-content-end gap-2">

      </form> <!-- CLOSE GET FORM -->

      <!-- SEND REPORT -->
      <form method="POST">
        <input type="hidden" name="send_report" value="1">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
        <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">
        <input type="hidden" name="from" value="<?= htmlspecialchars($from ?? '') ?>">
        <input type="hidden" name="to" value="<?= htmlspecialchars($to ?? '') ?>">

        <button type="submit" class="btn btn-success" onclick="markReportSent()">
          Send Daily Report
        </button>
      </form>

      <!-- UNDO REPORT (only shown when the report for this date is still Submitted) -->
      <?php if ($currentReport && $currentReport['status'] === 'Submitted'): ?>
        <form method="POST" onsubmit="return confirm('Undo this report? Stock will be restored and the Main Admin will see it was undone.');">
          <input type="hidden" name="undo_report" value="1">
          <input type="hidden" name="report_id" value="<?= (int) $currentReport['id'] ?>">
          <button type="submit" class="btn btn-warning">
            Undo Report
          </button>
        </form>
      <?php endif; ?>

      <!-- LOAD -->


      <button type="submit" class="btn btn-primary px-4">
        Load
      </button>
      </form>

      <!-- PRINT -->
      <button type="button" class="btn btn-secondary px-4" onclick="window.print()">
        Print
      </button>

    </div>

  </div>

  </form>

  </div>


  <div class="card p-3">

    <div class="text-center mb-3">
      <div class="fw-bold">EVERYDAY INVENTORY RECORD</div>
      <?php
      if ($mode === 'range' && $from && $to) {
        echo strtoupper(date('F d, Y', strtotime($from))) .
          " - " .
          strtoupper(date('F d, Y', strtotime($to)));
      } else {
        echo strtoupper(date('F d, Y', strtotime($date)));
      }
      ?>

    </div>

    <table class="table table-bordered table-sm">
      <thead class="table-success">
        <tr>

          <th style="width:25%">DESCRIPTION</th>
          <th>PACKAGE</th>
          <th>PRICE</th>

          <?php if ($mode !== 'range'): ?>
            <th>BEGINNING STOCK</th>
          <?php endif; ?>

          <th>INDIVIDUAL SOLD</th>

          <?php if ($mode !== 'range'): ?>
            <th>REMAINING</th>
          <?php endif; ?>

          <th>AMOUNT</th>

        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p): ?>

          <?php
          // ✅ Sold (from BOTH consignment + walk-in)
          $soldBox = (int) ($salesData[$p['id']]['BOX'] ?? 0);
          $soldPcs = (int) ($salesData[$p['id']]['PCS'] ?? 0);
          $soldBottle = (int) ($salesData[$p['id']]['BOTTLE'] ?? 0);

          // ✅ Remaining = current DB stock (already deducted by sales.php)
          /* BEGINNING STOCK = stock at start of day */
          $beginBox = (int) $p['box_qty'];
          $beginPcs = (int) $p['pcs_qty'];
          $beginBottle = (int) $p['bottle_qty'];

          /* REMAINING STOCK = beginning - sold */
          if ($p['unit_type'] === 'BOX') {

            $pcs_per_box = 10;

            $beginTotalPcs = ($beginBox * $pcs_per_box) + $beginPcs;
            $soldTotalPcs = ($soldBox * $pcs_per_box) + $soldPcs;
            $remainTotalPcs = $beginTotalPcs - $soldTotalPcs;

            if ($remainTotalPcs < 0) {
              $remainTotalPcs = 0;
            }

            $remainBox = floor($remainTotalPcs / $pcs_per_box);
            $remainPcs = $remainTotalPcs % $pcs_per_box;
            $remainBottle = max($beginBottle - $soldBottle, 0);

          } else {

            $remainBox = max($beginBox - $soldBox, 0);
            $remainPcs = max($beginPcs - $soldPcs, 0);
            $remainBottle = max($beginBottle - $soldBottle, 0);

          }

          // ✅ Amount (simple: same price per unit)
          $pcs_price = ($p['unit_type'] === 'BOX')
            ? ceil($p['member_price'] / 10)
            : $p['member_price'];

          $amount =
            ($soldBox * $p['member_price']) +
            ($soldPcs * $pcs_price) +
            ($soldBottle * $p['member_price']);
          ?>

          <tr>
            <td>
              <?= htmlspecialchars($p['name']) ?>
            </td>

            <td><?= $packageCounts[$p['id']] ?? 0 ?></td>

            <td>
              ₱<?= number_format($p['member_price'], 2) ?>
            </td>

            <?php if ($mode !== 'range'): ?>
              <td>
                <?php
                if ($beginBox > 0)
                  echo $beginBox . " boxes<br>";
                if ($beginBottle > 0)
                  echo $beginBottle . " bottles<br>";
                if ($beginPcs > 0)
                  echo $beginPcs . " pcs";
                if ($beginBox == 0 && $beginBottle == 0 && $beginPcs == 0)
                  echo "0";
                ?>
              </td>
            <?php endif; ?>

            <td>
              <?php
              if ($soldBox > 0)
                echo $soldBox . " boxes<br>";
              if ($soldBottle > 0)
                echo $soldBottle . " bottles<br>";
              if ($soldPcs > 0)
                echo $soldPcs . " pcs";
              if ($soldBox == 0 && $soldBottle == 0 && $soldPcs == 0)
                echo "0";
              ?>
            </td>

            <?php if ($mode !== 'range'): ?>
              <td>
                <?php
                if ($remainBox > 0)
                  echo $remainBox . " boxes<br>";
                if ($remainBottle > 0)
                  echo $remainBottle . " bottles<br>";
                if ($remainPcs > 0)
                  echo $remainPcs . " pcs";
                if ($remainBox <= 0 && $remainBottle <= 0 && $remainPcs <= 0)
                  echo "0";
                ?>
              </td>
            <?php endif; ?>

            <td>
              ₱<?= number_format($amount, 2) ?>
            </td>
          </tr>

        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
    /* ============================
       SALES REPORT SUMMARY
    ============================= */

    $grandIndividualSales = 0;
    $grandPackageSales = $totalPackageSales;
    
    foreach ($products as $p) {

      $soldBox = $salesData[$p['id']]['BOX'] ?? 0;
      $soldPcs = $salesData[$p['id']]['PCS'] ?? 0;
      $soldBottle = $salesData[$p['id']]['BOTTLE'] ?? 0;

      $pcs_price = ($p['unit_type'] === 'BOX')
        ? ceil($p['member_price'] / 10)
        : $p['member_price'];

      $grandIndividualSales +=
        ($soldBox * $p['member_price']) +
        ($soldPcs * $pcs_price) +
        ($soldBottle * $p['member_price']);
    }

    /* TOTAL SALES including upgrade income */
    $grandTotalSales = $grandIndividualSales + $grandPackageSales + $totalUpgradeIncome;
    ?>

    <hr>

    <div style="font-size:14px;">

      <p><strong>Sales report as of:</strong>
        <?php
        if ($mode === 'range' && $from && $to) {
          echo strtoupper(date('F d, Y', strtotime($from))) .
            " - " .
            strtoupper(date('F d, Y', strtotime($to)));
        } else {
          echo strtoupper(date('F d, Y', strtotime($date)));
        }
        ?>
      </p>

      <p><strong>Package Sold:</strong> ₱<?= number_format($grandPackageSales, 2) ?></p>
      <p><strong>Individual Sales:</strong> ₱<?= number_format($grandIndividualSales, 2) ?></p>

      <?php if ($totalExchanges > 0 || $totalUpgrades > 0): ?>
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-bottom:10px;">
        <strong style="color:#1e40af;">Returns / Exchanges processed today:</strong><br>
        <?php if ($totalExchanges > 0): ?>
          &bull; <?= $totalExchanges ?> Exchange(s) — stock swapped, <strong>no money impact</strong><br>
        <?php endif; ?>
        <?php if ($totalUpgrades > 0): ?>
          &bull; <?= $totalUpgrades ?> Upgrade(s) — customer paid extra:
          <strong style="color:#166534;">+₱<?= number_format($totalUpgradeIncome, 2) ?></strong><br>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($totalUpgradeIncome > 0): ?>
      <p><strong>Upgrade Income (extra paid by customer):</strong>
        <span style="color:#166534;">+₱<?= number_format($totalUpgradeIncome, 2) ?></span>
      </p>
      <?php endif; ?>

      <p style="font-size:16px;">
        <strong>Total Sales:</strong> ₱<?= number_format($grandTotalSales, 2) ?>
        <?php if ($totalUpgradeIncome > 0): ?>
          <span style="font-size:11px;color:#6b7280;">(includes ₱<?= number_format($totalUpgradeIncome,2) ?> upgrade income)</span>
        <?php endif; ?>
      </p>

    </div>


  </div>
  </div>


  <script>
    const modeSelect = document.getElementById("modeSelect");
    const singleDate = document.getElementById("singleDate");
    const rangeDate = document.getElementById("rangeDate");
    const weekPicker = document.getElementById("weekPicker");
    const monthPicker = document.getElementById("monthPicker");
    const monthWeekPicker = document.getElementById("monthWeekPicker");
    const monthInput = document.getElementById("monthInput");
    const monthWeekSelect = document.getElementById("monthWeekSelect");

    function updateMonthWeekLabels() {
      if (!monthInput.value) return;
      const [y, m] = monthInput.value.split("-").map(Number);
      const daysInMonth = new Date(y, m, 0).getDate();
      for (let w = 1; w <= 5; w++) {
        const start = (w - 1) * 7 + 1;
        const end = Math.min(w * 7, daysInMonth);
        const opt = monthWeekSelect.options[w]; // index 0 = "Whole Month"
        if (start > daysInMonth) {
          opt.disabled = true;
          opt.textContent = "Week " + w;
        } else {
          opt.disabled = false;
          opt.textContent = "Week " + w + " (" + start + "\u2013" + end + ")";
        }
      }
    }

    function toggleMode() {
      singleDate.classList.add("d-none");
      rangeDate.classList.add("d-none");
      weekPicker.classList.add("d-none");
      monthPicker.classList.add("d-none");
      monthWeekPicker.classList.add("d-none");

      if (modeSelect.value === "range") {
        rangeDate.classList.remove("d-none");
      } else if (modeSelect.value === "week") {
        weekPicker.classList.remove("d-none");
      } else if (modeSelect.value === "month") {
        monthPicker.classList.remove("d-none");
        monthWeekPicker.classList.remove("d-none");
        updateMonthWeekLabels();
      } else {
        singleDate.classList.remove("d-none");
      }
    }

    monthInput.addEventListener("change", updateMonthWeekLabels);
    modeSelect.addEventListener("change", toggleMode);
    toggleMode();
  </script>

  <script>

    function reportSent() {

      const today = new Date().toISOString().split('T')[0];

      localStorage.setItem("daily_report_sent", today);

    }

    function resetDailyReminder() {

      const today = new Date().toISOString().split('T')[0];
      const stored = localStorage.getItem("daily_report_sent");

      if (stored !== today) {

        localStorage.removeItem("daily_report_sent");

      }

    }

    resetDailyReminder();



    function markReportSent() {

      const today = new Date().toISOString().split('T')[0];

      localStorage.setItem("daily_report_sent", today);

    }


  </script>

<?php include 'chatbot.php'; ?>
<?php include 'mobile_sidebar.php'; ?>
</body>

</html>
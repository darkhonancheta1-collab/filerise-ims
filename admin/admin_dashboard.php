<?php
/**
 * admin/admin_dashboard.php — Main Admin Dashboard (Company-Wide View)
 *
 * The central hub for admins/super admins. Aggregates data across all
 * branches to display:
 *   - Company-wide KPIs (total sales today/week/month across all branches)
 *   - Per-branch sales comparison and ranking
 *   - Branch filter (view individual or all branches)
 *   - Top products/distributors company-wide
 *   - Daily report submission status per branch
 *   - AJAX date picker for historical sales lookup
 *   - Analytics charts (sales trends, category breakdown)
 *   - Admin chatbot (AI assistant for admin-level queries)
 *
 * Requires admin session (admin_id).
 */
require '../config.php';
$pageTitle = "Main Admin Dashboard";
$activePage = "dashboard";

/* ── AJAX: Sales by Date (same pattern as staff dashboard) ── */
if (isset($_GET['ajax_sales_date'])) {
  require __DIR__ . '/auth.php';
  $ajaxDate   = $_GET['sales_date'] ?? date('Y-m-d');
  $ajaxBranch = $_GET['branch'] ?? 'ALL';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ajaxDate)) $ajaxDate = date('Y-m-d');

  $filterSQL = ""; $filterParams = [];
  if ($ajaxBranch !== 'ALL') { $filterSQL = " AND u.branch_name = ? "; $filterParams[] = $ajaxBranch; }

  $stmt = $pdo->prepare("
    SELECT COALESCE(SUM(x.revenue),0) AS total FROM (
      SELECT ds.user_id,
        CASE WHEN ds.unit='PCS' AND p.unit_type='BOX'
          THEN ds.quantity_sold * CEIL(p.member_price/10)
        ELSE ds.quantity_sold * p.member_price END AS revenue
      FROM distributor_sales ds JOIN products p ON p.id=ds.product_id
      WHERE DATE(ds.sale_date)=? AND ds.user_id>0
      UNION ALL
      SELECT ws.user_id, (wsi.quantity * wsi.price) AS revenue
      FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id=wsi.sale_id
      WHERE DATE(ws.created_at)=?
      UNION ALL
      SELECT m.user_id, m.membership_fee AS revenue
      FROM members m WHERE DATE(m.created_at)=? AND m.status='ACTIVE' AND m.package_product_id IS NOT NULL
    ) x LEFT JOIN users u ON u.id=x.user_id WHERE 1=1 $filterSQL
  ");
  $stmt->execute(array_merge([$ajaxDate, $ajaxDate, $ajaxDate], $filterParams));
  $ajaxTotal = (float)$stmt->fetchColumn();
  $ajaxLabel = ($ajaxDate === date('Y-m-d')) ? 'Today' : date('M d, Y', strtotime($ajaxDate));
  header('Content-Type: application/json');
  echo json_encode(['amount' => number_format($ajaxTotal, 2), 'label' => $ajaxLabel]);
  exit;
}

require __DIR__ . "/_layout_top.php";

/* REPORT SUBMISSION ALERT */
$stmt = $pdo->prepare("
SELECT id, branch_name, submitted_at
FROM branch_reports
WHERE DATE(submitted_at) = CURDATE()
ORDER BY submitted_at DESC
");
$stmt->execute();
$reportAlerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$notifCount = count($reportAlerts);

function peso($n) { return "₱" . number_format((float) $n, 2); }

$branch = trim($_GET['branch'] ?? 'ALL');

$stmt = $pdo->prepare("SELECT DISTINCT branch_name FROM users WHERE branch_name IS NOT NULL AND branch_name <> '' ORDER BY branch_name ASC");
$stmt->execute();
$branchNames = $stmt->fetchAll(PDO::FETCH_COLUMN);

$paramsFilter = [];
$filterSQL    = "";
if ($branch !== 'ALL') {
    $filterSQL      = " AND u.branch_name = ? ";
    $paramsFilter[] = $branch;
}

function sales_total(PDO $pdo, string $periodSQL, array $periodParams, string $filterSQL, array $filterParams): float
{
    $sql = "
        SELECT COALESCE(SUM(x.revenue), 0) AS total
        FROM (
            SELECT ds.user_id,
                CASE WHEN ds.unit = 'PCS' AND p.unit_type = 'BOX'
                    THEN ds.quantity_sold * CEIL(p.member_price / 10)
                ELSE ds.quantity_sold * p.member_price END AS revenue,
                ds.sale_date AS d
            FROM distributor_sales ds JOIN products p ON p.id = ds.product_id
            WHERE ds.user_id > 0
            UNION ALL
            SELECT ws.user_id, (wsi.quantity * wsi.price) AS revenue, ws.created_at AS d
            FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id = wsi.sale_id
            UNION ALL
            SELECT m.user_id, m.membership_fee AS revenue, m.created_at AS d
            FROM members m WHERE m.status = 'ACTIVE' AND m.package_product_id IS NOT NULL
        ) x
        LEFT JOIN users u ON u.id = x.user_id
        WHERE 1=1 $periodSQL $filterSQL
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($periodParams, $filterParams));
    return (float) $stmt->fetchColumn();
}

$today        = date('Y-m-d');
$selectedDate = isset($_GET['sales_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['sales_date']) ? $_GET['sales_date'] : $today;
$salesDateLabel = ($selectedDate === $today) ? 'Today' : date('M d, Y', strtotime($selectedDate));

$todaySQL = " AND DATE(x.d) = CURDATE() ";
$weekSQL  = " AND YEARWEEK(x.d, 1) = YEARWEEK(CURDATE(), 1) ";
$monthSQL = " AND YEAR(x.d) = YEAR(CURDATE()) AND MONTH(x.d) = MONTH(CURDATE()) ";

$kpiToday = sales_total($pdo, $todaySQL, [], $filterSQL, $paramsFilter);
$kpiWeek  = sales_total($pdo, $weekSQL,  [], $filterSQL, $paramsFilter);
$kpiMonth = sales_total($pdo, $monthSQL, [], $filterSQL, $paramsFilter);

$branchesToday  = [];
$topBranchName  = "—";
$topBranchSales = 0;

if ($branch === 'ALL') {
    $stmt = $pdo->prepare("
        SELECT u.branch_name,
               COALESCE(SUM(x.revenue), 0) AS sales_today,
               COALESCE(SUM(x.qty_sold), 0) AS products_sold
        FROM users u
        LEFT JOIN (
            SELECT ds.user_id,
                CASE WHEN ds.unit = 'PCS' AND p.unit_type = 'BOX'
                    THEN ds.quantity_sold * CEIL(p.member_price / 10)
                ELSE ds.quantity_sold * p.member_price END AS revenue,
                ds.quantity_sold AS qty_sold
            FROM distributor_sales ds JOIN products p ON p.id = ds.product_id
            WHERE DATE(ds.sale_date) = CURDATE() AND ds.user_id > 0
            UNION ALL
            SELECT ws.user_id, (wsi.quantity * wsi.price) AS revenue, wsi.quantity AS qty_sold
            FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id = wsi.sale_id
            WHERE DATE(ws.created_at) = CURDATE()
            UNION ALL
            SELECT m.user_id, m.membership_fee AS revenue, 1 AS qty_sold
            FROM members m
            WHERE DATE(m.created_at) = CURDATE() AND m.status = 'ACTIVE' AND m.package_product_id IS NOT NULL
        ) x ON x.user_id = u.id
        WHERE u.branch_name IS NOT NULL AND u.branch_name <> ''
        GROUP BY u.id, u.branch_name
        ORDER BY sales_today DESC
    ");
    $stmt->execute();
    $branchesToday  = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $topBranchName  = $branchesToday[0]['branch_name'] ?? '—';
    $topBranchSales = (float)($branchesToday[0]['sales_today'] ?? 0);
}

$invFilterSQL = ""; $invParams = [];
if ($branch !== 'ALL') { $invFilterSQL = " AND u.branch_name = ? "; $invParams[] = $branch; }
$stmt = $pdo->prepare("SELECT COALESCE(COUNT(*),0) AS total_products, COALESCE(SUM((p.box_qty+p.pcs_qty+p.bottle_qty)*p.member_price),0) AS stock_value, COALESCE(SUM(CASE WHEN (p.box_qty<=5 OR p.pcs_qty<=10 OR p.bottle_qty<=5) THEN 1 ELSE 0 END),0) AS low_stock FROM products p JOIN users u ON u.id=p.user_id WHERE p.is_active=1 $invFilterSQL");
$stmt->execute($invParams);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
$totalProducts = (int)($inv['total_products'] ?? 0);
$lowStock      = (int)($inv['low_stock'] ?? 0);

$stmt = $pdo->prepare("
    SELECT p.name, COALESCE(SUM(x.qty), 0) AS total_qty
    FROM (
        SELECT ds.user_id, ds.product_id, ds.quantity_sold AS qty FROM distributor_sales ds WHERE DATE(ds.sale_date)=CURDATE() AND ds.user_id>0
        UNION ALL
        SELECT ws.user_id, wsi.product_id, wsi.quantity AS qty FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id=wsi.sale_id WHERE DATE(ws.created_at)=CURDATE()
        UNION ALL
        SELECT m.user_id, mp.product1_id, 1 AS qty FROM members m JOIN member_packages mp ON mp.id=m.package_product_id WHERE DATE(m.created_at)=CURDATE() AND m.status='ACTIVE' AND m.package_product_id IS NOT NULL
        UNION ALL
        SELECT m.user_id, mp.product2_id, 1 AS qty FROM members m JOIN member_packages mp ON mp.id=m.package_product_id WHERE DATE(m.created_at)=CURDATE() AND m.status='ACTIVE' AND m.package_product_id IS NOT NULL AND mp.product2_id IS NOT NULL
    ) x LEFT JOIN users u ON u.id=x.user_id JOIN products p ON p.id=x.product_id
    WHERE 1=1 $filterSQL GROUP BY p.name ORDER BY total_qty DESC LIMIT 5
");
$stmt->execute($paramsFilter);
$topProducts   = $stmt->fetchAll(PDO::FETCH_ASSOC);
$topProdLabels = array_map(fn($r) => $r['name'], $topProducts);
$topProdData   = array_map(fn($r) => (int)$r['total_qty'], $topProducts);

$stmt = $pdo->prepare("
    SELECT ym, COALESCE(SUM(revenue),0) AS total FROM (
        SELECT DATE_FORMAT(ds.sale_date,'%Y-%m') AS ym,
            CASE WHEN ds.unit = 'PCS' AND p.unit_type = 'BOX'
                THEN ds.quantity_sold * CEIL(p.member_price / 10)
            ELSE ds.quantity_sold * p.member_price END AS revenue,
            ds.user_id FROM distributor_sales ds JOIN products p ON p.id=ds.product_id WHERE ds.user_id>0
        UNION ALL
        SELECT DATE_FORMAT(ws.created_at,'%Y-%m') AS ym, (wsi.quantity*wsi.price) AS revenue, ws.user_id FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id=wsi.sale_id
        UNION ALL
        SELECT DATE_FORMAT(m.created_at,'%Y-%m') AS ym, m.membership_fee AS revenue, m.user_id FROM members m WHERE m.status='ACTIVE' AND m.package_product_id IS NOT NULL
    ) x LEFT JOIN users u ON u.id=x.user_id
    WHERE x.ym >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH),'%Y-%m') $filterSQL
    GROUP BY ym ORDER BY ym ASC
");
$stmt->execute($paramsFilter);
$monthlyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$monthlySalesMap = [];
foreach ($monthlyRows as $r) $monthlySalesMap[$r['ym']] = (float)$r['total'];

$monthLabels = []; $monthData = [];
for ($i = -1; $i < 5; $i++) {
    $ym = date('Y-m', strtotime("$i months"));
    $monthLabels[] = $ym;
    $monthData[]   = $monthlySalesMap[$ym] ?? 0;
}

$scopeLabel = ($branch === 'ALL') ? "All Branches" : $branch;

/* ============================================================
   RETURNS SUMMARY — for admin visibility
============================================================ */
$retFilterSQL = ""; $retFilterParams = [];
if ($branch !== 'ALL') { $retFilterSQL = " AND u.branch_name = ? "; $retFilterParams[] = $branch; }

$stmt = $pdo->prepare("
    SELECT
        pr.return_type,
        pr.return_ref,
        pr.original_ref,
        pr.customer_name,
        pr.upgrade_income,
        pr.returned_at,
        u.branch_name,
        GROUP_CONCAT(CASE WHEN pri.item_type='RETURNED'
            THEN CONCAT(pri.product_name, ' x', pri.quantity, ' ', pri.unit) END
            ORDER BY pri.id SEPARATOR ', ') AS returned_items,
        GROUP_CONCAT(CASE WHEN pri.item_type='REPLACEMENT'
            THEN CONCAT(pri.product_name, ' x', pri.quantity, ' ', pri.unit) END
            ORDER BY pri.id SEPARATOR ', ') AS replacement_items
    FROM product_returns pr
    JOIN users u ON u.id = pr.user_id
    LEFT JOIN product_return_items pri ON pri.return_id = pr.id
    WHERE pr.status = 'COMPLETED'
      AND DATE(pr.returned_at) = CURDATE()
      $retFilterSQL
    GROUP BY pr.id
    ORDER BY pr.returned_at DESC
");
$stmt->execute($retFilterParams);
$todayReturns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalExchangesToday = 0;
$totalUpgradesToday  = 0;
$totalUpgradeIncomeToday = 0;
foreach ($todayReturns as $r) {
    if ($r['return_type'] === 'EXCHANGE') $totalExchangesToday++;
    if ($r['return_type'] === 'UPGRADE')  { $totalUpgradesToday++; $totalUpgradeIncomeToday += (float)$r['upgrade_income']; }
}
$branchAnalytics = [];
foreach ($branchNames as $bn) {
    // Top 5 products this month for this branch
    $stmt = $pdo->prepare("
        SELECT p.name, COALESCE(SUM(x.qty), 0) AS total_qty,
               COALESCE(SUM(x.revenue), 0) AS total_revenue
        FROM (
            SELECT ds.user_id, ds.product_id,
                   ds.quantity_sold AS qty,
                   CASE WHEN ds.unit='PCS' AND p2.unit_type='BOX'
                     THEN ds.quantity_sold * CEIL(p2.member_price/10)
                   ELSE ds.quantity_sold * p2.member_price END AS revenue
            FROM distributor_sales ds
            JOIN products p2 ON p2.id = ds.product_id
            WHERE MONTH(ds.sale_date)=MONTH(CURDATE()) AND YEAR(ds.sale_date)=YEAR(CURDATE())
            UNION ALL
            SELECT ws.user_id, wsi.product_id,
                   wsi.quantity AS qty,
                   (wsi.quantity * wsi.price) AS revenue
            FROM walkin_sales_items wsi
            JOIN walkin_sales ws ON ws.id = wsi.sale_id
            WHERE MONTH(ws.created_at)=MONTH(CURDATE()) AND YEAR(ws.created_at)=YEAR(CURDATE())
        ) x
        JOIN users u ON u.id = x.user_id
        JOIN products p ON p.id = x.product_id
        WHERE u.branch_name = ?
        GROUP BY p.id, p.name
        ORDER BY total_qty DESC
        LIMIT 5
    ");
    $stmt->execute([$bn]);
    $topProds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Monthly total for this branch (includes distributor + walkin + membership fees)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(x.revenue),0) AS total
        FROM (
            SELECT ds.user_id,
                   CASE WHEN ds.unit='PCS' AND p.unit_type='BOX'
                     THEN ds.quantity_sold * CEIL(p.member_price/10)
                   ELSE ds.quantity_sold * p.member_price END AS revenue
            FROM distributor_sales ds JOIN products p ON p.id=ds.product_id
            WHERE MONTH(ds.sale_date)=MONTH(CURDATE()) AND YEAR(ds.sale_date)=YEAR(CURDATE())
            UNION ALL
            SELECT ws.user_id, (wsi.quantity * wsi.price) AS revenue
            FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id=wsi.sale_id
            WHERE MONTH(ws.created_at)=MONTH(CURDATE()) AND YEAR(ws.created_at)=YEAR(CURDATE())
            UNION ALL
            SELECT m.user_id, m.membership_fee AS revenue
            FROM members m
            WHERE m.status='ACTIVE' AND m.package_product_id IS NOT NULL
              AND MONTH(m.created_at)=MONTH(CURDATE()) AND YEAR(m.created_at)=YEAR(CURDATE())
        ) x
        JOIN users u ON u.id = x.user_id
        WHERE u.branch_name = ?
    ");
    $stmt->execute([$bn]);
    $branchMonthTotal = (float)$stmt->fetchColumn();

    // Total units sold this month
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(x.qty),0) AS total
        FROM (
            SELECT ds.user_id, ds.quantity_sold AS qty
            FROM distributor_sales ds
            WHERE MONTH(ds.sale_date)=MONTH(CURDATE()) AND YEAR(ds.sale_date)=YEAR(CURDATE())
            UNION ALL
            SELECT ws.user_id, wsi.quantity AS qty
            FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id=wsi.sale_id
            WHERE MONTH(ws.created_at)=MONTH(CURDATE()) AND YEAR(ws.created_at)=YEAR(CURDATE())
        ) x
        JOIN users u ON u.id = x.user_id
        WHERE u.branch_name = ?
    ");
    $stmt->execute([$bn]);
    $branchMonthUnits = (int)$stmt->fetchColumn();

    // Top distributor this month for this branch
    $stmt = $pdo->prepare("
        SELECT d.name, SUM(ds.quantity_sold) AS total
        FROM distributor_sales ds
        JOIN distributors d ON d.id = ds.distributor_id
        JOIN users u ON u.id = ds.user_id
        WHERE u.branch_name = ?
          AND MONTH(ds.sale_date)=MONTH(CURDATE()) AND YEAR(ds.sale_date)=YEAR(CURDATE())
        GROUP BY ds.distributor_id ORDER BY total DESC LIMIT 1
    ");
    $stmt->execute([$bn]);
    $topDist = $stmt->fetch(PDO::FETCH_ASSOC);

    $branchAnalytics[] = [
        'branch'      => $bn,
        'month_total' => $branchMonthTotal,
        'month_units' => $branchMonthUnits,
        'top_products'=> $topProds,
        'top_dist'    => $topDist,
    ];
}
// Sort by monthly revenue descending
usort($branchAnalytics, fn($a,$b) => $b['month_total'] <=> $a['month_total']);
?>

<style>
  /* ── KPI CARDS ── */
  .kpi-card {
    background: #fff;
    border-radius: 16px;
    padding: 20px 22px;
    position: relative;
    box-shadow: 0 4px 14px rgba(0,0,0,.07);
    transition: transform .2s, box-shadow .2s;
  }
  .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(0,0,0,.11); }
  .kpi-card .kpi-icon {
    position: absolute; right: 18px; top: 18px;
    font-size: 20px; color: #6b7280;
  }
  .kpi-card .kpi-label { font-size: 13px; font-weight: 600; color: #111827; margin-bottom: 4px; }
  .kpi-card .kpi-amount { font-size: 26px; font-weight: 800; color: #111827; line-height: 1.2; margin-bottom: 4px; }
  .kpi-card .kpi-sub { font-size: 13px; color: #374151; font-weight: 500; }

  .kpi-today  { border-left: 5px solid #3b82f6; }
  .kpi-week   { border-left: 5px solid #22c55e; }
  .kpi-month  { border-left: 5px solid #f97316; }

  /* ── TOPBAR ── */
  .admin-topbar {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
  }
  .admin-topbar h3 { font-size: 22px; font-weight: 800; color: #0f172a; margin: 0; }
  .admin-topbar .date-chip {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 20px;
    padding: 6px 14px; font-size: 12px; color: #6b7280; font-weight: 500;
  }

  /* ── SECTION TITLE ── */
  .section-title {
    font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px;
    display: flex; align-items: center; gap: 8px;
  }
  .section-title::after {
    content: ''; flex: 1; height: 1px; background: #f1f5f9;
  }

  /* ── BRANCH TABLE ── */
  .branch-table { width: 100%; border-collapse: collapse; }
  .branch-table th {
    font-size: 11px; font-weight: 700; color: #6b7280;
    text-transform: uppercase; letter-spacing: .5px;
    padding: 10px 14px; border-bottom: 2px solid #f1f5f9;
  }
  .branch-table td {
    padding: 12px 14px; font-size: 13px;
    border-bottom: 1px solid #f8fafc; vertical-align: middle;
  }
  .branch-table tbody tr:last-child td { border-bottom: none; }
  .branch-table tbody tr:hover { background: #f8fafc; }
  .branch-rank {
    width: 26px; height: 26px; border-radius: 8px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 800;
  }
  .rank-1 { background: #fef3c7; color: #d97706; }
  .rank-2 { background: #f1f5f9; color: #64748b; }
  .rank-3 { background: #fff7ed; color: #c2410c; }
  .rank-x { background: #f8fafc; color: #94a3b8; }



  /* ── STATS PILLS ── */
  .chart-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 20px;
    font-size: 11px; font-weight: 700;
  }

  /* ── SCOPE TOGGLE ── */
  .scope-toggle {
    display: flex; background: #f1f5f9; border-radius: 10px; padding: 3px; gap: 3px;
  }
  .scope-toggle a {
    padding: 7px 16px; border-radius: 8px; font-size: 13px;
    font-weight: 600; color: #64748b; text-decoration: none; transition: .2s;
  }
  .scope-toggle a.active { background: #fff; color: #0f172a; box-shadow: 0 1px 4px rgba(0,0,0,.1); }

  /* ── NOTIF BELL ── */
  .bell-wrap {
    position: relative; display: inline-block; cursor: pointer; line-height: 1;
  }

  /* ── SALES CALENDAR BUTTON (same as staff) ── */
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
  .sales-cal-btn input[type="date"] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer;
    width: 100%; height: 100%; border: none; padding: 0;
  }
  .sales-cal-btn input[type="date"]::-webkit-calendar-picker-indicator {
    position: absolute; inset: 0; width: 100%; height: 100%;
    opacity: 0; cursor: pointer;
  }
</style>

<!-- TOPBAR -->
<div class="admin-topbar">
  <div>
    <h3>Main Admin Dashboard</h3>
    <div style="font-size:12px;color:#6b7280;margin-top:2px;">Company-wide overview</div>
  </div>

  <div class="d-flex align-items-center gap-3">

    <!-- Sales Alert Icon -->
    <?php
    $alert_stmt = $pdo->prepare("
      SELECT product_id, weeks_ago, SUM(qty) AS qty
      FROM (
        SELECT product_id,
               FLOOR(DATEDIFF(CURDATE(), DATE(d)) / 7) AS weeks_ago, qty
        FROM (
          SELECT product_id, sale_date AS d, quantity_sold AS qty FROM distributor_sales
          WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 3 WEEK)
          UNION ALL
          SELECT wsi.product_id, DATE(ws.created_at) AS d, wsi.quantity AS qty
          FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id = wsi.sale_id
          WHERE ws.created_at >= DATE_SUB(CURDATE(), INTERVAL 3 WEEK)
        ) inner_t
      ) outer_t
      WHERE weeks_ago BETWEEN 0 AND 2
      GROUP BY product_id, weeks_ago
    ");
    $alert_stmt->execute();
    $alert_weeklyRaw = $alert_stmt->fetchAll(PDO::FETCH_ASSOC);
    $alert_weeklyData = [];
    foreach ($alert_weeklyRaw as $r) {
      $alert_weeklyData[$r['product_id']][(int)$r['weeks_ago']] = (int)$r['qty'];
    }
    $alert_stmt2 = $pdo->prepare("SELECT id, name FROM products WHERE is_active=1");
    $alert_stmt2->execute();
    $alert_products = $alert_stmt2->fetchAll(PDO::FETCH_ASSOC);
    $alert_falling = [];
    foreach ($alert_products as $ap) {
      $pid = $ap['id'];
      $w0  = $alert_weeklyData[$pid][0] ?? 0;
      $w1  = $alert_weeklyData[$pid][1] ?? 0;
      if ($w1 > 0 && $w0 < $w1) {
        $drop = round((($w1 - $w0) / $w1) * 100);
        if ($drop >= 10) {
          $alert_falling[] = ['name' => $ap['name'], 'drop' => $drop];
        }
      }
    }
    usort($alert_falling, fn($a,$b) => $b['drop'] <=> $a['drop']);
    $alert_count = count($alert_falling);
    ?>

    <div style="position:relative;display:inline-block;cursor:pointer;" onclick="openSalesAlertModal()">
      <span style="font-size:22px;" title="Sales Alerts">⚠️</span>
      <?php if ($alert_count > 0): ?>
        <span style="position:absolute;top:-8px;right:-10px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;padding:2px 5px;border-radius:999px;min-width:18px;text-align:center;">
          <?= $alert_count ?>
        </span>
      <?php endif; ?>
    </div>

    <!-- Sales Alert Modal -->
    <div id="salesAlertModal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
      <div style="background:#fff;border-radius:18px;width:100%;max-width:500px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;">
        <!-- Modal Header -->
        <div style="background:linear-gradient(90deg,#f59e0b,#d97706);padding:16px 20px;display:flex;align-items:center;justify-content:space-between;">
          <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:22px;">⚠️</span>
            <div>
              <div style="font-weight:800;font-size:15px;color:#1c1917;">SALES ALERT</div>
              <div style="font-size:11px;color:#78350f;">Products losing momentum this week</div>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <!-- Speaker Button -->
            <button id="speakerBtn" onclick="toggleSpeech()" title="Read alerts aloud"
              style="background:rgba(255,255,255,.25);border:1px solid rgba(255,255,255,.4);border-radius:10px;padding:6px 10px;cursor:pointer;font-size:16px;color:#1c1917;font-weight:700;transition:.2s;"
              onmouseover="this.style.background='rgba(255,255,255,.4)'" onmouseout="this.style.background='rgba(255,255,255,.25)'">
              🔊
            </button>
            <button onclick="closeSalesAlertModal()"
              style="background:rgba(255,255,255,.25);border:1px solid rgba(255,255,255,.4);border-radius:10px;padding:6px 10px;cursor:pointer;font-size:16px;color:#1c1917;font-weight:700;"
              onmouseover="this.style.background='rgba(255,255,255,.4)'" onmouseout="this.style.background='rgba(255,255,255,.25)'">
              ✕
            </button>
          </div>
        </div>
        <!-- Modal Body -->
        <div style="padding:20px;max-height:380px;overflow-y:auto;">
          <?php if (empty($alert_falling)): ?>
            <div style="text-align:center;padding:30px 0;">
              <div style="font-size:36px;margin-bottom:10px;">✅</div>
              <div style="font-weight:700;color:#111827;">All products are stable or rising!</div>
              <div style="font-size:13px;color:#6b7280;margin-top:4px;">Great job this week.</div>
            </div>
          <?php else: ?>
            <?php foreach ($alert_falling as $i => $af): ?>
              <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:12px;margin-bottom:8px;background:<?= $af['drop'] >= 50 ? '#fef2f2' : ($af['drop'] >= 30 ? '#fff7ed' : '#fffbeb') ?>;border:1px solid <?= $af['drop'] >= 50 ? '#fecaca' : ($af['drop'] >= 30 ? '#fed7aa' : '#fde68a') ?>;">
                <div style="font-size:22px;"><?= $af['drop'] >= 50 ? '🔴' : ($af['drop'] >= 30 ? '🟠' : '🟡') ?></div>
                <div style="flex:1;">
                  <div style="font-weight:700;font-size:13px;color:#111827;"><?= htmlspecialchars($af['name']) ?></div>
                  <div style="font-size:12px;color:#6b7280;margin-top:2px;">Down <b style="color:<?= $af['drop'] >= 50 ? '#ef4444' : ($af['drop'] >= 30 ? '#f97316' : '#d97706') ?>;"><?= $af['drop'] ?>%</b> vs last week — consider running a promo</div>
                </div>
                <div style="font-size:20px;font-weight:800;color:<?= $af['drop'] >= 50 ? '#ef4444' : ($af['drop'] >= 30 ? '#f97316' : '#d97706') ?>;">-<?= $af['drop'] ?>%</div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <!-- Modal Footer -->
        <div style="padding:12px 20px;border-top:1px solid #f1f5f9;text-align:right;">
          <button onclick="closeSalesAlertModal()" style="background:#f1f5f9;border:none;border-radius:10px;padding:8px 20px;font-size:13px;font-weight:600;color:#374151;cursor:pointer;">Close</button>
        </div>
      </div>
    </div>

    <script>
    // ── Sales Alert Modal ──
    function openSalesAlertModal() {
      const m = document.getElementById('salesAlertModal');
      m.style.display = 'flex';
    }
    function closeSalesAlertModal() {
      stopSpeech();
      document.getElementById('salesAlertModal').style.display = 'none';
    }
    document.getElementById('salesAlertModal').addEventListener('click', function(e) {
      if (e.target === this) closeSalesAlertModal();
    });

    // ── Text-to-Speech (ElevenLabs Sarah — same as staff voice) ──
    let currentAudio = null;
    let isSpeaking   = false;

    const alertSpeechText = <?= json_encode(
      empty($alert_falling)
        ? 'All products are stable or rising this week. Great job!'
        : 'Sales Alert. The following products are losing momentum this week. ' .
          implode(' ', array_map(fn($p) => 'Watch out! ' . $p['name'] . ' is down ' . $p['drop'] . ' percent this week. Consider running a promotion.', $alert_falling))
    ) ?>;

    async function toggleSpeech() {
      if (isSpeaking) { stopSpeech(); return; }
      const btn = document.getElementById('speakerBtn');
      btn.textContent = '⏳';
      btn.disabled = true;
      isSpeaking = true;
      try {
        const response = await fetch('../admin/admin_voice_proxy.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ text: alertSpeechText })
        });
        const data = await response.json();
        if (data.error) throw new Error(data.error);
        const binary = atob(data.audio);
        const bytes  = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        const blob = new Blob([bytes], { type: 'audio/mpeg' });
        currentAudio = new Audio(URL.createObjectURL(blob));
        currentAudio.play();
        btn.textContent = '🔇';
        btn.disabled = false;
        btn.title = 'Stop reading';
        currentAudio.onended = () => { isSpeaking = false; btn.textContent = '🔊'; btn.title = 'Read alerts aloud'; };
      } catch (e) {
        // Fallback: browser TTS with female voice
        const utter = new SpeechSynthesisUtterance(alertSpeechText);
        utter.lang  = 'en-US';
        utter.rate  = 0.92;
        utter.pitch = 1.05;
        function speakWithFemaleVoice() {
          const voices = window.speechSynthesis.getVoices();
          const femalePrefs = ['Google US English','Samantha','Karen','Moira','Tessa','Microsoft Zira','Microsoft Jenny','Microsoft Aria'];
          let chosen = null;
          for (const name of femalePrefs) { chosen = voices.find(v => v.name.includes(name)); if (chosen) break; }
          if (!chosen) {
            chosen = voices.find(v => /female/i.test(v.name) && /en[-_]US/i.test(v.lang))
                  || voices.find(v => /female/i.test(v.name) && /en/i.test(v.lang))
                  || voices.find(v => /en[-_]US/i.test(v.lang) && !/male/i.test(v.name));
          }
          if (chosen) utter.voice = chosen;
          utter.onend = utter.onerror = () => { isSpeaking = false; btn.textContent = '🔊'; btn.title = 'Read alerts aloud'; btn.disabled = false; };
          window.speechSynthesis.speak(utter);
          btn.textContent = '🔇'; btn.disabled = false; btn.title = 'Stop reading';
        }
        if (window.speechSynthesis.getVoices().length) { speakWithFemaleVoice(); }
        else { window.speechSynthesis.onvoiceschanged = speakWithFemaleVoice; }
      }
    }

    function stopSpeech() {
      if (currentAudio) { currentAudio.pause(); currentAudio = null; }
      if (window.speechSynthesis) window.speechSynthesis.cancel();
      isSpeaking = false;
      const btn = document.getElementById('speakerBtn');
      if (btn) { btn.textContent = '🔊'; btn.title = 'Read alerts aloud'; btn.disabled = false; }
    }
    </script>

    <!-- Bell -->
    <div class="bell-wrap" id="notifWrap" onclick="toggleNotifications()">
      <span id="bellIcon" style="font-size:22px;">🔔</span>
      <?php if ($notifCount > 0): ?>
        <span id="notifBadge" class="badge bg-danger"
          style="position:absolute;top:-8px;right:-10px;font-size:10px;padding:2px 5px;min-width:18px;text-align:center;">
          <?= $notifCount ?>
        </span>
      <?php endif; ?>
      <div id="notificationPanel">
        <h6 class="mb-3" style="font-weight:700;">Branch Reports Today</h6>
        <div id="notificationPanelBody">
          <?php if (empty($reportAlerts)): ?>
            <div class="text-muted" style="font-size:13px;">No reports submitted today.</div>
          <?php else: ?>
            <?php foreach ($reportAlerts as $n): ?>
              <div class="alert alert-success p-2 mb-2" style="font-size:12px;">
                 <b><?= htmlspecialchars($n['branch_name']) ?></b><br>
                submitted their Daily Report<br>
                <small class="text-muted"><?= date("h:i A", strtotime($n['submitted_at'])) ?></small>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <span class="date-chip"> <?= date('F d, Y') ?></span>

    <div class="pill"><?= htmlspecialchars($scopeLabel) ?></div>

    <form method="GET" class="mb-0" id="branchFilterForm">
      <select name="branch" id="branchSelect" class="form-select form-select-sm" style="border-radius:10px;font-size:13px;">
        <option value="ALL" <?= $branch==='ALL'?'selected':'' ?>>Overall (All Branches)</option>
        <?php foreach ($branchNames as $bn): ?>
          <option value="<?= htmlspecialchars($bn) ?>" <?= $branch===$bn?'selected':'' ?>><?= htmlspecialchars($bn) ?></option>
        <?php endforeach; ?>
      </select>
    </form>

  </div>
</div>

<!-- Branch Loading Overlay -->
<div id="branchLoadingOverlay" style="display:none;position:fixed;inset:0;z-index:999999;background:rgba(15,23,42,.7);backdrop-filter:blur(4px);align-items:center;justify-content:center;flex-direction:column;gap:20px;">
  <div style="background:#fff;border-radius:20px;padding:36px 48px;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.3);min-width:300px;">
    <!-- Spinner -->
    <div style="margin:0 auto 20px;width:52px;height:52px;border:5px solid #e5e7eb;border-top-color:#2563eb;border-radius:50%;animation:branchSpin .8s linear infinite;"></div>
    <div id="branchLoadingText" style="font-size:16px;font-weight:700;color:#111827;margin-bottom:6px;">Getting branch data...</div>
    <div id="branchLoadingSub" style="font-size:13px;color:#6b7280;">Please wait a moment</div>
    <!-- Progress bar -->
    <div style="margin-top:18px;background:#f1f5f9;border-radius:999px;height:6px;overflow:hidden;">
      <div id="branchProgressBar" style="height:100%;width:0%;background:linear-gradient(90deg,#2563eb,#3b82f6);border-radius:999px;transition:width .1s linear;"></div>
    </div>
  </div>
</div>
<style>
@keyframes branchSpin { to { transform: rotate(360deg); } }
</style>

<script>
document.getElementById('branchSelect').addEventListener('change', function() {
  const selected = this.options[this.selectedIndex].text;
  const overlay  = document.getElementById('branchLoadingOverlay');
  const textEl   = document.getElementById('branchLoadingText');
  const subEl    = document.getElementById('branchLoadingSub');
  const bar      = document.getElementById('branchProgressBar');

  textEl.textContent = 'Getting ' + selected + ' sales...';
  subEl.textContent  = 'Fetching latest data for you';
  bar.style.width    = '0%';
  overlay.style.display = 'flex';

  // Animate progress bar over 3 seconds
  let progress = 0;
  const interval = setInterval(() => {
    progress += 100 / 30; // 30 steps over 3s (every 100ms)
    bar.style.width = Math.min(progress, 95) + '%'; // stop at 95% until done
    if (progress >= 30) clearInterval(interval);
  }, 100);

  // Submit after 3 seconds
  setTimeout(() => {
    bar.style.width = '100%';
    setTimeout(() => {
      document.getElementById('branchFilterForm').submit();
    }, 150);
  }, 3000);
});
</script>

<div id="toastContainer" class="position-fixed bottom-0 end-0 p-3" style="z-index:9999;"></div>

<!-- KPI ROW -->
<div class="row g-3 mb-4">
  <div class="col-12 col-lg-4">
    <div class="kpi-card kpi-today" style="position:relative;">
      <div class="kpi-icon"></div>
      <div class="kpi-label">Total Sales Today</div>
      <div class="kpi-amount" id="adminSalesTodayValue"><?= peso($kpiToday) ?></div>
      <div class="kpi-sub" id="adminSalesTodaySub">Scope: <?= htmlspecialchars($scopeLabel) ?></div>
      <div class="sales-cal-btn" title="Pick a date to view sales">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
        <input type="date" id="adminSalesDatePicker"
          value="<?= htmlspecialchars($selectedDate) ?>"
          max="<?= $today ?>">
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="kpi-card kpi-week">
      <div class="kpi-icon"></div>
      <div class="kpi-label">This Week</div>
      <div class="kpi-amount"><?= peso($kpiWeek) ?></div>
      <div class="kpi-sub">Mon–Sun (current week)</div>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="kpi-card kpi-month">
      <div class="kpi-icon"></div>
      <div class="kpi-label">This Month</div>
      <div class="kpi-amount"><?= peso($kpiMonth) ?></div>
      <div class="kpi-sub"><?= date('F Y') ?></div>
    </div>
  </div>
</div>

<!-- RETURNS ACTIVITY PANEL -->
<?php if (!empty($todayReturns)): ?>
<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="cardx p-4" style="border-left:4px solid #f59e0b;">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
          <div style="font-size:15px;font-weight:700;color:#111827;">
            🔄 Product Returns Today
          </div>
          <div style="font-size:12px;color:#6b7280;">
            <?= $totalExchangesToday ?> exchange(s) &nbsp;·&nbsp;
            <?= $totalUpgradesToday ?> upgrade(s)
            <?php if ($totalUpgradeIncomeToday > 0): ?>
              &nbsp;·&nbsp; <span style="color:#16a34a;font-weight:600;">+₱<?= number_format($totalUpgradeIncomeToday,2) ?> upgrade income</span>
            <?php endif; ?>
          </div>
        </div>
        <span style="background:#fef3c7;color:#92400e;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;">
          <?= count($todayReturns) ?> return(s)
        </span>
      </div>

      <div style="overflow-x:auto;">
        <table style="width:100%;font-size:12px;border-collapse:collapse;">
          <thead>
            <tr style="background:#f8fafc;color:#64748b;text-transform:uppercase;font-size:11px;">
              <th style="padding:8px 10px;text-align:left;">Return Ref</th>
              <th style="padding:8px 10px;text-align:left;">Branch</th>
              <th style="padding:8px 10px;text-align:left;">Customer</th>
              <th style="padding:8px 10px;text-align:left;">Type</th>
              <th style="padding:8px 10px;text-align:left;">Returned</th>
              <th style="padding:8px 10px;text-align:left;">Replaced With</th>
              <th style="padding:8px 10px;text-align:left;">Income</th>
              <th style="padding:8px 10px;text-align:left;">Time</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($todayReturns as $ret): ?>
            <tr style="border-top:1px solid #f1f5f9;">
              <td style="padding:8px 10px;font-weight:600;color:#1d4ed8;"><?= htmlspecialchars($ret['return_ref']) ?></td>
              <td style="padding:8px 10px;"><?= htmlspecialchars($ret['branch_name'] ?? '—') ?></td>
              <td style="padding:8px 10px;"><?= htmlspecialchars($ret['customer_name'] ?: '—') ?></td>
              <td style="padding:8px 10px;">
                <?php if ($ret['return_type'] === 'EXCHANGE'): ?>
                  <span style="background:#dbeafe;color:#1e40af;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;">EXCHANGE</span>
                <?php else: ?>
                  <span style="background:#dcfce7;color:#166534;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;">UPGRADE</span>
                <?php endif; ?>
              </td>
              <td style="padding:8px 10px;color:#dc2626;"><?= htmlspecialchars($ret['returned_items'] ?? '—') ?></td>
              <td style="padding:8px 10px;color:#16a34a;"><?= htmlspecialchars($ret['replacement_items'] ?? '—') ?></td>
              <td style="padding:8px 10px;font-weight:600;color:#166534;">
                <?= (float)$ret['upgrade_income'] > 0 ? '<span style="color:#166534;">+₱'.number_format((float)$ret['upgrade_income'],2).'</span>' : '<span style="color:#9ca3af;">₱0.00</span>' ?>
              </td>
              <td style="padding:8px 10px;color:#6b7280;"><?= date('h:i A', strtotime($ret['returned_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- BRANCH ANALYTICS SECTION -->
<?php if ($branch === 'ALL' && !empty($branchAnalytics)): ?>
<div class="row g-3 mb-3" id="branchAnalyticsSection">
  <div class="col-12">
    <div class="section-title" style="font-size:16px;font-weight:700;color:#111827;margin-bottom:0;">
       Branch Analytics — <?= date('F Y') ?>
      <span style="font-size:12px;color:#6b7280;font-weight:400;margin-left:8px;">Top products & performance per branch this month</span>
    </div>
  </div>

  <?php
  $rankColors = ['#1d4ed8','#16a34a','#dc2626','#7c3aed','#0891b2'];
  foreach ($branchAnalytics as $bi => $ba):
    $barColor = $rankColors[$bi % count($rankColors)];
    $maxQty = !empty($ba['top_products']) ? max(array_column($ba['top_products'], 'total_qty')) : 1;
  ?>
  <div class="col-12 col-lg-6">
    <div class="cardx p-4" style="border-top: 4px solid <?= $barColor ?>;">

      <!-- Branch Header -->
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
          <div style="font-size:18px;font-weight:800;color:#111827;">
            <?= $bi===0 ? '1 ' : ($bi===1 ? '2 ' : ($bi===2 ? '3 ' : '')) ?>
            <?= htmlspecialchars($ba['branch']) ?>
          </div>
          <div style="font-size:14px;color:#6b7280;margin-top:2px;">
            <?= number_format($ba['month_units']) ?> units sold this month
          </div>
        </div>
        <div style="text-align:right;">
          <div style="font-size:24px;font-weight:900;color:<?= $barColor ?>;">
            ₱<?= number_format($ba['month_total'], 2) ?>
          </div>
          <div style="font-size:13px;color:#9ca3af;">Total Revenue</div>
        </div>
      </div>

      <!-- Top Distributor Badge -->
      <?php if ($ba['top_dist']): ?>
      <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:8px;">
        <span style="font-size:13px;"></span>
        <div>
          <div style="font-size:12px;color:#6b7280;font-weight:700;">TOP DISTRIBUTOR</div>
          <div style="font-size:15px;font-weight:700;color:#111827;">
            <?= htmlspecialchars($ba['top_dist']['name']) ?>
            <span style="color:#6b7280;font-weight:500;font-size:13px;">(<?= number_format((int)$ba['top_dist']['total']) ?> units)</span>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Top Products Bar Chart -->
      <div style="font-size:13px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;">
        Top Products This Month
      </div>

      <?php if (empty($ba['top_products'])): ?>
        <div style="text-align:center;color:#9ca3af;padding:20px 0;font-size:14px;">No sales recorded this month.</div>
      <?php else: ?>
        <?php foreach ($ba['top_products'] as $pi => $prod):
          $pct = $maxQty > 0 ? round(($prod['total_qty'] / $maxQty) * 100) : 0;
          $prodColors = ['#1d4ed8','#7c3aed','#0891b2','#16a34a','#dc2626'];
          $pc = $prodColors[$pi % count($prodColors)];
        ?>
        <div style="margin-bottom:12px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
            <div style="font-size:14px;font-weight:700;color:#111827;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-right:8px;">
              <?= htmlspecialchars($prod['name']) ?>
            </div>
            <div style="font-size:13px;font-weight:700;color:<?= $pc ?>;white-space:nowrap;">
              <?= number_format((int)$prod['total_qty']) ?> units
              <span style="color:#6b7280;font-weight:500;"> · ₱<?= number_format((float)$prod['total_revenue'], 0) ?></span>
            </div>
          </div>
          <div style="background:#f1f5f9;border-radius:999px;height:9px;overflow:hidden;">
            <div style="height:100%;width:<?= $pct ?>%;background:<?= $pc ?>;border-radius:999px;transition:.4s;"></div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>

    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>


<div class="row g-3 mb-3">

  <!-- LEFT: TOP PRODUCTS -->
  <div class="col-12 col-lg-4">

    <!-- TOP PRODUCTS -->
    <div class="cardx p-4">
      <div class="section-title"> Top Products Today</div>
      <?php if (!$topProducts): ?>
        <div class="text-muted" style="font-size:13px;text-align:center;padding:24px 0;">No sales today.</div>
      <?php else: ?>
        <canvas id="topProductsChart" height="280"></canvas>
      <?php endif; ?>
    </div>
  </div>

  <!-- RIGHT: BRANCH PERFORMANCE + MONTHLY CHART -->
  <div class="col-12 col-lg-8">

    <?php if ($branch === 'ALL'): ?>
    <div class="cardx p-4 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="section-title mb-0" style="flex:1;"> Branch Performance Today</div>
        <a class="btn btn-sm btn-outline-primary ms-3" href="branches.php" style="border-radius:8px;font-size:12px;">Manage</a>
      </div>

      <table class="branch-table">
        <thead>
          <tr>
            <th style="width:40px;">#</th>
            <th>Branch</th>
            <th class="text-end">Sales Today</th>
            <th class="text-end">Products Sold</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$branchesToday): ?>
            <tr><td colspan="4" class="text-muted text-center py-4">No branches found.</td></tr>
          <?php else: ?>
            <?php foreach ($branchesToday as $i => $b):
              $rClass = $i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'rank-x'));
              $rLabel = $i===0?'1':($i===1?'2':($i===2?'3':'#'.($i+1)));
            ?>
            <tr>
              <td><span class="branch-rank <?= $rClass ?>"><?= $rLabel ?></span></td>
              <td class="fw-semibold"><?= htmlspecialchars($b['branch_name']) ?></td>
              <td class="text-end fw-bold" style="color:#1d4ed8;"><?= peso($b['sales_today']) ?></td>
              <td class="text-end" style="color:#6b7280;"><?= number_format((int)$b['products_sold']) ?> units</td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

    </div>
    <?php endif; ?>
    <div class="cardx p-4">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
          <div class="section-title mb-1" style="font-size:16px;"> Sales — Last 6 Months</div>
          <div style="font-size:12px;color:#6b7280;">Scope: <?= htmlspecialchars($scopeLabel) ?></div>
        </div>
        <div class="text-end">
          <div style="font-size:24px;font-weight:900;color:#2563eb;"><?= peso($kpiMonth) ?></div>
          <div style="font-size:11px;color:#94a3b8;">This month</div>
        </div>
      </div>

      <div class="d-flex gap-2 flex-wrap mb-3 mt-2">
        <?php
          $maxMonth = !empty($monthData) ? max($monthData) : 0;
          $minMonth = !empty($monthData) ? min($monthData) : 0;
          $avgMonth = !empty($monthData) ? array_sum($monthData)/count($monthData) : 0;
        ?>
        <span class="chart-pill" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;"> Peak: <?= peso($maxMonth) ?></span>
        <span class="chart-pill" style="background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;"> Avg: <?= peso($avgMonth) ?></span>
        <span class="chart-pill" style="background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;"> Low: <?= peso($minMonth) ?></span>
      </div>
      <canvas id="monthlySalesChart" height="110"></canvas>
    </div>
  </div>

</div>

<audio id="notifSound" src="../notification.mp3" preload="auto"></audio>

<script>
  if ("Notification" in window && Notification.permission !== "granted") Notification.requestPermission();
  document.body.addEventListener("click", () => { document.getElementById("notifSound").muted = false; }, { once: true });
</script>

<script>
  <?php if ($topProducts): ?>
    new Chart(document.getElementById('topProductsChart'), {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_map(fn($r) => [$r['name'], (int)$r['total_qty'] . ' units sold'], $topProducts)) ?>,
        datasets: [{ label:'Qty Sold', data:<?= json_encode($topProdData) ?>,
          backgroundColor:['#1d4ed8','#2563eb','#3b82f6','#60a5fa','#93c5fd'],
          borderRadius:8, borderSkipped:false }]
      },
      options: {
        responsive:true, indexAxis:'y',
        plugins:{ legend:{display:false}, tooltip:{backgroundColor:'#0f172a',titleColor:'#94a3b8',bodyColor:'#fff',padding:10,cornerRadius:8} },
        scales:{
          x:{ beginAtZero:true, grid:{color:'rgba(0,0,0,.04)'}, ticks:{font:{family:'Poppins',size:10},color:'#94a3b8'} },
          y:{ grid:{display:false}, ticks:{
            display: false
          }, afterFit(axis) { axis.width = 200; } }
        }
      },
      plugins: [{
        id: 'multilineYLabels',
        afterDraw(chart) {
          const yAxis = chart.scales.y;
          const ctx2  = chart.ctx;
          yAxis.ticks.forEach((tick, i) => {
            const labels = chart.data.labels[i];
            if (!Array.isArray(labels)) return;
            const y = yAxis.getPixelForTick(i);
            const x = yAxis.right - 8;
            ctx2.save();
            ctx2.textAlign = 'right';
            ctx2.textBaseline = 'middle';
            ctx2.font = 'bold 13px Poppins, sans-serif';
            ctx2.fillStyle = '#111827';
            ctx2.fillText(labels[0], x, y - 9);
            ctx2.font = '600 12px Poppins, sans-serif';
            ctx2.fillStyle = '#2563eb';
            ctx2.fillText(labels[1], x, y + 9);
            ctx2.restore();
          });
        }
      }]
    });
  <?php endif; ?>

  (function(){
    const ctx = document.getElementById('monthlySalesChart').getContext('2d');
    const grad = ctx.createLinearGradient(0,0,0,240);
    grad.addColorStop(0,'rgba(37,99,235,.18)');
    grad.addColorStop(1,'rgba(37,99,235,0)');
    const rawLabels = <?= json_encode($monthLabels) ?>;
    const niceLabels = rawLabels.map(ym => {
      const [y,m] = ym.split('-');
      return ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][parseInt(m)-1]+" '"+y.slice(2);
    });
    new Chart(ctx,{
      type:'line',
      data:{ labels:niceLabels, datasets:[{ label:'Sales (₱)', data:<?= json_encode($monthData) ?>,
        tension:.4, fill:true, borderColor:'#2563eb', backgroundColor:grad,
        borderWidth:2.5, pointBackgroundColor:'#2563eb', pointBorderColor:'#fff',
        pointBorderWidth:2, pointRadius:5, pointHoverRadius:7 }]},
      options:{
        responsive:true, interaction:{mode:'index',intersect:false},
        plugins:{ legend:{display:false}, tooltip:{
          backgroundColor:'#0f172a',titleColor:'#94a3b8',bodyColor:'#fff',padding:12,cornerRadius:10,
          callbacks:{label:ctx=>' ₱'+parseFloat(ctx.parsed.y).toLocaleString('en-PH',{minimumFractionDigits:2})}
        }},
        scales:{
          x:{grid:{display:false},ticks:{font:{family:'Poppins',size:11},color:'#94a3b8'}},
          y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.04)'},ticks:{font:{family:'Poppins',size:11},color:'#94a3b8',callback:v=>'₱'+(v>=1000?(v/1000).toFixed(0)+'k':v)}}
        }
      }
    });
  })();

  function toggleNotifications() {
    const panel = document.getElementById("notificationPanel");
    const isOpen = panel.style.display === "block";
    panel.style.display = isOpen ? "none" : "block";
    if (!isOpen) {
      const badge = document.getElementById("notifBadge");
      if (badge) badge.style.display = "none";
    }
  }

  function showToast(message) {
    const container = document.getElementById("toastContainer");
    const toast = document.createElement("div");
    toast.className = "toast align-items-center text-white bg-success border-0 show mb-2 shadow";
    toast.style.minWidth = "280px";
    toast.innerHTML = `<div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto"></button></div>`;
    toast.querySelector(".btn-close").onclick = () => toast.remove();
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 5000);
  }
</script>

<script>
  let shownReports = new Set();
  <?php foreach ($reportAlerts as $r): ?>
    shownReports.add(<?= (int)$r['id'] ?>);
  <?php endforeach; ?>
</script>

<script>
  let audioUnlocked = false;
  document.addEventListener("click", function() {
    if (!audioUnlocked) {
      const audio = document.getElementById("notifSound");
      audio.play().then(()=>{ audio.pause(); audio.currentTime=0; audioUnlocked=true; }).catch(()=>{});
    }
  });

  let originalTitle = document.title;

  function checkNewReports() {
    fetch('check_report_notifications.php').then(res=>res.json()).then(data=>{
      let notifBadge = document.getElementById("notifBadge");
      if (!notifBadge) {
        notifBadge = document.createElement("span");
        notifBadge.id = "notifBadge";
        notifBadge.className = "badge bg-danger";
        notifBadge.style = "position:absolute;top:-8px;right:-10px;font-size:10px;padding:2px 5px;min-width:18px;text-align:center;";
        document.getElementById("bellIcon").parentElement.appendChild(notifBadge);
      }
      let notifList = document.getElementById("notificationPanelBody");
      notifList.innerHTML = "";
      let count = 0;
      data.forEach(report => {
        count++;
        notifList.innerHTML += `<div class="alert alert-success p-2 mb-2" style="font-size:12px;">📩 <b>${report.branch_name}</b><br>submitted their Daily Report<br><small>${report.submitted_at}</small></div>`;
        if (!shownReports.has(report.id)) {
          showToast(`📩 ${report.branch_name} submitted a Daily Report`);
          if (Notification.permission === "granted") new Notification("Branch Report Submitted", { body: report.branch_name+" submitted a Daily Report", icon:"../7.png" });
          const sound = document.getElementById("notifSound");
          if (audioUnlocked) { sound.currentTime=0; sound.play().catch(()=>{}); }
          shownReports.add(report.id);
        }
      });
      if (count > 0) {
        notifBadge.style.display = "inline-block";
        notifBadge.innerText = count;
        document.title = "("+count+") "+originalTitle;
      } else {
        notifBadge.style.display = "none";
        document.title = originalTitle;
      }
    });
  }
  setInterval(checkNewReports, 5000);
  checkNewReports();

  // Auto-refresh Branch Analytics every 30 seconds
  const rankColors = ['#1d4ed8','#16a34a','#dc2626','#7c3aed','#0891b2'];
  const prodColors = ['#1d4ed8','#7c3aed','#0891b2','#16a34a','#dc2626'];

  function refreshBranchAnalytics() {
    const branch = new URLSearchParams(window.location.search).get('branch') || 'ALL';
    if (branch !== 'ALL') return;

    fetch('analytics_refresh.php')
      .then(res => res.json())
      .then(data => {
        const section = document.getElementById('branchAnalyticsSection');
        if (!section || !data.length) return;

        // Remove only branch cards (col-lg-6), keep the title col-12
        section.querySelectorAll('.col-12.col-lg-6').forEach(el => el.remove());

        data.forEach((ba, bi) => {
          const barColor = rankColors[bi % rankColors.length];
          const maxQty = ba.top_products.length ? Math.max(...ba.top_products.map(p => p.total_qty)) : 1;
          const medal = bi===0 ? '1 ' : bi===1 ? '2 ' : bi===2 ? '3 ' : '';

          let prodBars = ba.top_products.length
            ? ba.top_products.map((prod, pi) => {
                const pct = maxQty > 0 ? Math.round((prod.total_qty / maxQty) * 100) : 0;
                const pc = prodColors[pi % prodColors.length];
                return `<div style="margin-bottom:12px;">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                    <div style="font-size:14px;font-weight:700;color:#111827;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-right:8px;">${prod.name}</div>
                    <div style="font-size:13px;font-weight:700;color:${pc};white-space:nowrap;">
                      ${parseInt(prod.total_qty).toLocaleString()} units
                      <span style="color:#6b7280;font-weight:500;"> · ₱${parseInt(prod.total_revenue).toLocaleString()}</span>
                    </div>
                  </div>
                  <div style="background:#f1f5f9;border-radius:999px;height:9px;overflow:hidden;">
                    <div style="height:100%;width:${pct}%;background:${pc};border-radius:999px;"></div>
                  </div>
                </div>`;
              }).join('')
            : '<div style="text-align:center;color:#9ca3af;padding:20px 0;font-size:14px;">No sales recorded this month.</div>';

          const distBadge = ba.top_dist
            ? `<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:8px;">
                <span style="font-size:13px;"></span>
                <div>
                  <div style="font-size:12px;color:#6b7280;font-weight:700;">TOP DISTRIBUTOR</div>
                  <div style="font-size:15px;font-weight:700;color:#111827;">${ba.top_dist.name}
                    <span style="color:#6b7280;font-weight:500;font-size:13px;">(${parseInt(ba.top_dist.total).toLocaleString()} units)</span>
                  </div>
                </div>
              </div>` : '';

          const col = document.createElement('div');
          col.className = 'col-12 col-lg-6';
          col.innerHTML = `
            <div class="cardx p-4" style="border-top:4px solid ${barColor};">
              <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                  <div style="font-size:18px;font-weight:800;color:#111827;">${medal}${ba.branch}</div>
                  <div style="font-size:14px;color:#6b7280;margin-top:2px;">${parseInt(ba.month_units).toLocaleString()} units sold this month</div>
                </div>
                <div style="text-align:right;">
                  <div style="font-size:24px;font-weight:900;color:${barColor};">₱${parseFloat(ba.month_total).toLocaleString('en-PH',{minimumFractionDigits:2})}</div>
                  <div style="font-size:13px;color:#9ca3af;">Total Revenue</div>
                </div>
              </div>
              ${distBadge}
              <div style="font-size:13px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;">Top Products This Month</div>
              ${prodBars}
            </div>`;
          section.appendChild(col);
        });
      })
      .catch(() => {});
  }

  setInterval(refreshBranchAnalytics, 30000);
  refreshBranchAnalytics(); // run immediately on page load
</script>

<!-- ── SALES DATE PICKER (same pattern as staff dashboard) ── -->
<script>
const adminSalesDatePicker = document.getElementById('adminSalesDatePicker');
if (adminSalesDatePicker) {
  adminSalesDatePicker.addEventListener('change', function() {
    const date    = this.value;
    const branch  = <?= json_encode($branch) ?>;
    const valueEl = document.getElementById('adminSalesTodayValue');
    const subEl   = document.getElementById('adminSalesTodaySub');
    valueEl.style.opacity = '0.4';
    fetch('admin_dashboard.php?ajax_sales_date=1&sales_date=' + encodeURIComponent(date) + '&branch=' + encodeURIComponent(branch))
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

<!-- ── LIVE AUTO-REFRESH (no page reload) ── -->
<script>
(function () {
  const branch = <?= json_encode($branch) ?>;

  // Track last known values to detect changes
  let lastToday = <?= $kpiToday ?>;
  let lastWeek  = <?= $kpiWeek ?>;
  let lastMonth = <?= $kpiMonth ?>;
  let lastTopHash = <?= json_encode(implode(',', array_column($topProducts ?? [], 'total_qty'))) ?>;

  // Flash highlight animation
  const style = document.createElement('style');
  style.textContent = `
    @keyframes liveFlash {
      0%   { background: #dbeafe; }
      100% { background: transparent; }
    }
    .live-flash { animation: liveFlash 1.2s ease-out; border-radius: 8px; }
  `;
  document.head.appendChild(style);

  function flash(el) {
    if (!el) return;
    el.classList.remove('live-flash');
    void el.offsetWidth; // reflow to restart animation
    el.classList.add('live-flash');
  }

  function formatPeso(n) {
    return '₱' + parseFloat(n).toLocaleString('en-PH', { minimumFractionDigits: 2 });
  }

  // Rank classes for branch table
  const rankClasses = ['rank-1','rank-2','rank-3','rank-x'];
  const rankLabels  = ['1','2','3'];

  function updateBranchTable(branches) {
    const tbody = document.querySelector('.branch-table tbody');
    if (!tbody) return;
    if (!branches || !branches.length) return;

    tbody.innerHTML = branches.map((b, i) => {
      const rc = i < 3 ? rankClasses[i] : 'rank-x';
      const rl = i < 3 ? rankLabels[i] : '#'+(i+1);
      return `<tr>
        <td><span class="branch-rank ${rc}">${rl}</span></td>
        <td class="fw-semibold">${b.branch_name}</td>
        <td class="text-end fw-bold" style="color:#1d4ed8;">${formatPeso(b.sales_today)}</td>
        <td class="text-end" style="color:#6b7280;">${parseInt(b.products_sold).toLocaleString()} units</td>
      </tr>`;
    }).join('');
  }

  let topChart = null;

  function updateTopProductsChart(products) {
    if (!products || !products.length) return;
    const canvas = document.getElementById('topProductsChart');
    if (!canvas) return;

    const labels  = products.map(p => [p.name, parseInt(p.total_qty) + ' units sold']);
    const data    = products.map(p => parseInt(p.total_qty));

    if (topChart) {
      topChart.data.labels   = labels;
      topChart.data.datasets[0].data = data;
      topChart.update('active');
    }
  }

  // Grab chart reference once it's created
  function grabChart() {
    const canvas = document.getElementById('topProductsChart');
    if (!canvas) return;
    topChart = Chart.getChart(canvas);
  }

  function pollLiveData() {
    fetch('admin_live_refresh.php?branch=' + encodeURIComponent(branch))
      .then(r => r.json())
      .then(data => {

        // ── KPI Today ──
        if (data.kpi_today !== lastToday) {
          const el = document.getElementById('adminSalesTodayValue');
          if (el) { el.textContent = formatPeso(data.kpi_today); flash(el); }
          lastToday = data.kpi_today;
        }

        // ── KPI Week ──
        if (data.kpi_week !== lastWeek) {
          const els = document.querySelectorAll('.kpi-week .kpi-amount');
          els.forEach(el => { el.textContent = formatPeso(data.kpi_week); flash(el); });
          lastWeek = data.kpi_week;
        }

        // ── KPI Month ──
        if (data.kpi_month !== lastMonth) {
          const els = document.querySelectorAll('.kpi-month .kpi-amount');
          els.forEach(el => { el.textContent = formatPeso(data.kpi_month); flash(el); });
          lastMonth = data.kpi_month;
        }

        // ── Branch Performance Today table ──
        if (data.branches_today) {
          updateBranchTable(data.branches_today);
        }

        // ── Top Products Today chart ──
        const newHash = (data.top_products || []).map(p => p.total_qty).join(',');
        if (newHash !== lastTopHash) {
          if (!topChart) grabChart();
          updateTopProductsChart(data.top_products);
          lastTopHash = newHash;
        }

      })
      .catch(() => {}); // silent fail — just try again next interval
  }

  // Start after chart is initialized (slight delay)
  setTimeout(() => {
    grabChart();
    pollLiveData();
    setInterval(pollLiveData, 3000); // every 3 seconds — near real-time
  }, 2000);

})();
</script>

<?php require __DIR__ . "/_layout_bottom.php"; ?>
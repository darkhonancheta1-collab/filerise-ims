<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}

$user_id = $_SESSION['user_id'];
$today = date('Y-m-d');

/* GET PRODUCTS */
$stmt = $pdo->prepare("
  SELECT id, name, box_qty, pcs_qty, bottle_qty, unit_type, member_price
  FROM products WHERE user_id = ? AND is_active = 1 ORDER BY name
");
$stmt->execute([$user_id]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$productMap = [];
foreach ($products as $p)
  $productMap[$p['id']] = $p;

/* WEEKLY SALES */
$stmt = $pdo->prepare("
  SELECT product_id, weeks_ago, SUM(qty) AS qty
  FROM (
    SELECT product_id, FLOOR(DATEDIFF(CURDATE(), DATE(d)) / 7) AS weeks_ago, qty
    FROM (
      SELECT product_id, sale_date AS d, quantity_sold AS qty
      FROM distributor_sales WHERE user_id = ?
        AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
      UNION ALL
      SELECT wsi.product_id, DATE(ws.created_at) AS d, wsi.quantity AS qty
      FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id = wsi.sale_id
      WHERE ws.user_id = ? AND ws.created_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
    ) inner_t
  ) outer_t
  WHERE weeks_ago BETWEEN 0 AND 7
  GROUP BY product_id, weeks_ago
");
$stmt->execute([$user_id, $user_id]);
$weeklyRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$weeklyData = [];
foreach ($weeklyRaw as $row) {
  $weeklyData[$row['product_id']][(int) $row['weeks_ago']] = (int) $row['qty'];
}

/* LAST 7 DAYS CHART */
$stmt = $pdo->prepare("
  SELECT DATE(d) AS d, SUM(qty) AS total
  FROM (
    SELECT sale_date AS d, quantity_sold AS qty FROM distributor_sales
    WHERE user_id = ? AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    UNION ALL
    SELECT DATE(ws.created_at) AS d, wsi.quantity AS qty
    FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id = wsi.sale_id
    WHERE ws.user_id = ? AND ws.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
  ) t GROUP BY DATE(d) ORDER BY d ASC
");
$stmt->execute([$user_id, $user_id]);
$last7 = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chartLabels = [];
$chartData = [];
foreach ($last7 as $row) {
  $chartLabels[] = date('D', strtotime($row['d']));
  $chartData[] = (int) $row['total'];
}

/* BUILD FORECAST */
$forecastRows = [];
foreach ($products as $p) {
  $pid = $p['id'];
  $weeks = $weeklyData[$pid] ?? [];
  $w0 = $weeks[0] ?? 0;
  $w1 = $weeks[1] ?? 0;
  $w2 = $weeks[2] ?? 0;
  $w3 = $weeks[3] ?? 0;
  $w4 = $weeks[4] ?? 0;
  $w5 = $weeks[5] ?? 0;
  $w6 = $weeks[6] ?? 0;
  $w7 = $weeks[7] ?? 0;
  $totalSold = $w0 + $w1 + $w2 + $w3 + $w4 + $w5 + $w6 + $w7;
  $wma = ($w0 * 4 + $w1 * 3 + $w2 * 2 + $w3 * 1) / 10;
  if ($w1 > 0) {
    $changeRate = ($w0 - $w1) / $w1;
  } else {
    $changeRate = $w0 > 0 ? 1 : 0;
  }
  if ($changeRate >= 0.2) {
    $trend = 'rising';
    $trendMult = 1.2;
  } elseif ($changeRate >= 0.05) {
    $trend = 'up';
    $trendMult = 1.1;
  } elseif ($changeRate <= -0.3) {
    $trend = 'falling';
    $trendMult = 0.8;
  } elseif ($changeRate <= -0.1) {
    $trend = 'down';
    $trendMult = 0.9;
  } else {
    $trend = 'stable';
    $trendMult = 1.0;
  }
  $predictedNextWeek = round($wma * $trendMult);
  $demandScore = $predictedNextWeek + ($changeRate * 10);
  $currentStock = $p['box_qty'] + $p['pcs_qty'] + $p['bottle_qty'];
  $forecastRows[] = [
    'id' => $pid,
    'name' => $p['name'],
    'unit_type' => $p['unit_type'],
    'member_price' => (float) $p['member_price'],
    'current_stock' => $currentStock,
    'avg_daily' => $totalSold > 0 ? round($totalSold / 56, 2) : 0,
    'week0' => $w0,
    'week1' => $w1,
    'week2' => $w2,
    'week3' => $w3,
    'predicted_next_week' => $predictedNextWeek,
    'change_rate' => round($changeRate * 100),
    'trend' => $trend,
    'demand_score' => $demandScore,
    'total_sold_8w' => $totalSold,
  ];
}
usort($forecastRows, fn($a, $b) => $b['demand_score'] <=> $a['demand_score']);

$forecastJson = json_encode($forecastRows);
$chartLabelJson = json_encode($chartLabels);
$chartDataJson = json_encode($chartData);
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>AI Forecast — FIL-RISE</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="mobile.css">
  <link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap"
    rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="icon" type="image/png" href="7.png">
  <style>
    body {
      font-family: 'Poppins', sans-serif;
      background: linear-gradient(135deg, #0f172a, #1e293b);
      min-height: 100vh;
      margin: 0;
      display: flex;
      color: #fff;
    }

    /* SIDEBAR */
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
      transition: all 0.3s;
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
      background: rgba(121, 35, 35, 0.2);
      color: #cbd5e1;
      cursor: pointer;
      transition: 0.3s;
    }

    .logout:hover {
      background: rgba(239, 68, 68, 0.4);
    }

    /* MAIN */
    .main {
      margin-left: 260px;
      padding: 36px 40px;
      flex: 1;
      background: #f1f3f6;
      color: #111827;
    }

    /* PAGE HEADER */
    .page-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 28px;
      flex-wrap: wrap;
      gap: 10px;
    }

    .page-title {
      font-size: 28px;
      font-weight: 700;
      color: #111827;
    }

    .page-sub {
      color: #6b7280;
      font-size: 13px;
      margin-top: 3px;
    }

    /* WEATHER PILL — small, top right */
    .weather-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 999px;
      padding: 6px 14px;
      font-size: 13px;
      font-weight: 600;
      color: #374151;
      box-shadow: 0 2px 6px rgba(0, 0, 0, .06);
      white-space: nowrap;
    }

    .weather-pill .temp {
      color: #2563eb;
      font-weight: 700;
    }

    /* STAT CARDS */
    .stat-row {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      margin-bottom: 28px;
    }

    .stat-card {
      background: #fff;
      border-radius: 16px;
      padding: 20px;
      box-shadow: 0 4px 14px rgba(0, 0, 0, .07);
      transition: .2s;
      position: relative;
      overflow: hidden;
    }

    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(0, 0, 0, .11);
    }

    .stat-card-label {
      font-size: 12px;
      color: #6b7280;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .5px;
      margin-bottom: 10px;
    }

    .stat-card-product {
      font-size: 15px;
      font-weight: 700;
      color: #111827;
      margin-bottom: 4px;
      line-height: 1.3;
    }

    .stat-card-count {
      font-size: 12px;
      color: #6b7280;
      margin-bottom: 10px;
    }

    .stat-card-badge {
      display: inline-block;
      font-size: 11px;
      font-weight: 600;
      padding: 3px 10px;
      border-radius: 999px;
    }

    .view-details-btn {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 11px;
      font-weight: 600;
      color: #2563eb;
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      border-radius: 8px;
      padding: 4px 10px;
      cursor: pointer;
      margin-top: 8px;
      transition: .15s;
      text-decoration: none;
    }

    .view-details-btn:hover {
      background: #dbeafe;
    }

    /* CHART CARD */
    .chart-card {
      background: #fff;
      border-radius: 18px;
      box-shadow: 0 4px 14px rgba(0, 0, 0, .07);
      padding: 22px 26px;
      margin-bottom: 24px;
    }

    .chart-title {
      font-size: 15px;
      font-weight: 700;
      color: #111827;
      margin-bottom: 2px;
    }

    .chart-sub {
      font-size: 12px;
      color: #6b7280;
      margin-bottom: 16px;
    }

    /* AI INSIGHT BOX */
    .ai-insight-box {
      background: #fff;
      border-radius: 18px;
      box-shadow: 0 4px 14px rgba(0, 0, 0, .07);
      padding: 24px 28px;
      margin-bottom: 24px;
      border-left: 5px solid #2563eb;
    }

    .ai-insight-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
      flex-wrap: wrap;
      gap: 10px;
    }

    .ai-insight-title {
      font-size: 16px;
      font-weight: 700;
      color: #111827;
    }

    .ai-insight-badge {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      padding: 4px 12px;
      letter-spacing: .3px;
    }

    #aiInsightText {
      font-size: 14.5px;
      line-height: 1.9;
      color: #1f2937;
      white-space: pre-wrap;
      font-weight: 400;
    }

    .ai-loading {
      display: flex;
      align-items: center;
      gap: 10px;
      color: #6b7280;
      font-size: 13px;
    }

    .ai-loading .dot-pulse {
      display: flex;
      gap: 4px;
    }

    .ai-loading .dot-pulse span {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #2563eb;
      animation: dotpulse 1.2s ease-in-out infinite;
    }

    .ai-loading .dot-pulse span:nth-child(2) {
      animation-delay: .2s;
    }

    .ai-loading .dot-pulse span:nth-child(3) {
      animation-delay: .4s;
    }

    @keyframes dotpulse {

      0%,
      80%,
      100% {
        opacity: .2;
        transform: scale(.8)
      }

      40% {
        opacity: 1;
        transform: scale(1)
      }
    }

    .typing-cursor {
      display: inline-block;
      width: 2px;
      height: 15px;
      background: #2563eb;
      margin-left: 2px;
      animation: blink .7s infinite;
      vertical-align: middle;
    }

    @keyframes blink {

      0%,
      100% {
        opacity: 1
      }

      50% {
        opacity: 0
      }
    }

    /* FORECAST TABLE */
    .forecast-card {
      background: #fff;
      border-radius: 18px;
      box-shadow: 0 4px 14px rgba(0, 0, 0, .07);
      padding: 22px 26px;
      margin-bottom: 24px;
    }

    .forecast-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 18px;
      flex-wrap: wrap;
      gap: 12px;
    }

    .search-box {
      background: #f9fafb;
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      padding: 8px 14px;
      color: #111827;
      font-family: 'Poppins', sans-serif;
      font-size: 13px;
      width: 200px;
      outline: none;
    }

    .search-box:focus {
      border-color: #3b82f6;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    thead th {
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .8px;
      color: #6b7280;
      padding: 10px 14px;
      border-bottom: 2px solid #f3f4f6;
      white-space: nowrap;
    }

    tbody tr {
      border-bottom: 1px solid #f3f4f6;
      transition: .15s;
    }

    tbody tr:hover {
      background: #f9fafb;
    }

    tbody td {
      padding: 12px 14px;
      font-size: 13px;
      vertical-align: middle;
      color: #374151;
    }

    .product-name {
      font-weight: 600;
      color: #111827;
    }

    .mono {
      font-family: 'Space Mono', monospace;
      font-size: 12px;
    }

    .badge-urgent {
      background: #fef2f2;
      color: #dc2626;
      border: 1px solid #fecaca;
      font-size: 11px;
      padding: 3px 9px;
      border-radius: 999px;
      font-weight: 600;
    }

    .badge-ok {
      background: #f0fdf4;
      color: #16a34a;
      border: 1px solid #bbf7d0;
      font-size: 11px;
      padding: 3px 9px;
      border-radius: 999px;
      font-weight: 600;
    }

    footer {
      text-align: center;
      color: #6b7280;
      font-size: 12px;
      margin-top: 40px;
    }

    /* MODAL */
    .detail-modal-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .45);
      z-index: 9998;
    }

    .detail-modal-backdrop.show {
      display: block;
    }

    .detail-modal {
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: #fff;
      border-radius: 20px;
      padding: 28px;
      width: 520px;
      max-width: 95vw;
      max-height: 80vh;
      overflow-y: auto;
      z-index: 9999;
      box-shadow: 0 20px 60px rgba(0, 0, 0, .2);
    }

    .detail-modal h5 {
      font-size: 16px;
      font-weight: 700;
      color: #111827;
      margin-bottom: 16px;
    }

    .detail-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 0;
      border-bottom: 1px solid #f3f4f6;
      font-size: 13px;
    }

    .detail-item:last-child {
      border-bottom: none;
    }

    .detail-item .name {
      font-weight: 600;
      color: #111827;
    }

    .detail-item .info {
      color: #6b7280;
      font-size: 12px;
    }

    .modal-close {
      position: absolute;
      top: 16px;
      right: 20px;
      background: none;
      border: none;
      font-size: 20px;
      cursor: pointer;
      color: #6b7280;
    }

    @media (max-width:992px) {
      .stat-row {
        grid-template-columns: repeat(2, 1fr);
      }
    }

    @media (max-width:576px) {
      .stat-row {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
      }

      .page-title {
        font-size: 20px;
      }
    }
  </style>
</head>

<body>
  <?php include 'ticker.php' ?>
  <!-- SIDEBAR -->
  <div class="sidebar">
    <img src="7.png" class="logo" alt="Fil-Rise Logo">
    <div class="menu-title">Core</div>
    <a href="dashboard.php">Dashboard</a>
    <a href="ai_forecast.php" class="active">AI Dashboard</a>
    <div class="menu-title">Inventory</div>
    <a href="product.php">Products</a>
    <a href="removed_products.php">Archived Products</a>
    <div class="menu-title">Operations</div>
    <a href="transaction.php">Consignment Transactions</a>
    <a href="sales.php">Walk-in Sales</a>
    <a href="returns.php">Product Returns</a>
    <a href="stock_requests.php">Stock Requests</a>
    <a href="daily_records.php">Daily Records</a>
    <a href="daily_sales_record.php">Daily Sales Record</a>
    <div class="menu-title">People</div>
    <a href="distributor.php">Distributors</a>
    <a href="members.php">Members</a>
    <div class="logout" onclick="window.location='logout.php'">Logout</div>
  </div>

  <!-- DETAIL MODAL BACKDROP -->
  <div class="detail-modal-backdrop" id="modalBackdrop" onclick="closeModal()"></div>
  <div class="detail-modal" id="detailModal" style="display:none;">
    <button class="modal-close" onclick="closeModal()">x</button>
    <h5 id="modalTitle">Products</h5>
    <div id="modalBody"></div>
  </div>

  <!-- MAIN -->
  <div class="main">

    <!-- PAGE HEADER with weather pill -->
    <div class="page-header">
      <div>
        <h3 class="page-title">AI Sales Forecast</h3>
        <div class="page-sub">Next week demand predictions · Weather intelligence · Sales trends</div>
      </div>
      <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <div class="weather-pill" id="weatherPill">
          <span id="weatherIconSmall">...</span>
          <span class="temp" id="weatherTempSmall">--°C</span>
          <span id="weatherDescSmall" style="color:#6b7280; font-weight:400;">Loading...</span>
        </div>
        <div style="color:#6b7280; font-size:13px; font-weight:500;"><?= date('F d, Y') ?></div>
      </div>
    </div>

    <!-- STAT CARDS — show top product per category -->
    <div class="stat-row" id="statRow">
      <!-- filled by JS -->
      <div class="stat-card" style="border-top:4px solid #3b82f6;">
        <div class="stat-card-label">Rising Demand</div>
        <div class="stat-card-product" id="risingTop">Loading...</div>
        <div class="stat-card-count" id="risingCount"></div>
        <span class="stat-card-badge" style="background:#eff6ff;color:#1d4ed8;">Trending Up</span><br>
        <a class="view-details-btn" onclick="showModal('rising')">View all</a>
      </div>
      <div class="stat-card" style="border-top:4px solid #22c55e;">
        <div class="stat-card-label">Stable Demand</div>
        <div class="stat-card-product" id="stableTop">Loading...</div>
        <div class="stat-card-count" id="stableCount"></div>
        <span class="stat-card-badge" style="background:#f0fdf4;color:#16a34a;">Steady</span><br>
        <a class="view-details-btn" onclick="showModal('stable')">View all</a>
      </div>
      <div class="stat-card" style="border-top:4px solid #ef4444;">
        <div class="stat-card-label">Falling Demand</div>
        <div class="stat-card-product" id="fallingTop">Loading...</div>
        <div class="stat-card-count" id="fallingCount"></div>
        <span class="stat-card-badge" style="background:#fef2f2;color:#dc2626;">Slowing Down</span><br>
        <a class="view-details-btn" onclick="showModal('falling')">View all</a>
      </div>
      <div class="stat-card" style="border-top:4px solid #f97316;">
        <div class="stat-card-label">No Sales Yet</div>
        <div class="stat-card-product" id="newTop">Loading...</div>
        <div class="stat-card-count" id="newCount"></div>
        <span class="stat-card-badge" style="background:#fff7ed;color:#c2410c;">No History</span><br>
        <a class="view-details-btn" onclick="showModal('new')">View all</a>
      </div>
    </div>



    <!-- AI INSIGHT BOX — auto generates on load -->
    <div class="ai-insight-box">
      <div class="ai-insight-header">
        <div class="ai-insight-title">AI Insight Engine</div>
      </div>
      <div id="aiInsightText">
        <div class="ai-loading">
          <div class="dot-pulse"><span></span><span></span><span></span></div>
          Analyzing your sales data and weather conditions...
        </div>
      </div>
    </div>

    <!-- FORECAST TABLE -->
    <div class="forecast-card">
      <div class="forecast-header">
        <div>
          <div class="chart-title">Next Week Demand Prediction</div>
          <div class="chart-sub">Ranked by predicted demand — based on last 4 weeks of sales history</div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
          <select id="filterTrend" onchange="filterTable()"
            style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:8px 12px;color:#111827;font-family:'Poppins',sans-serif;font-size:12px;outline:none;">
            <option value="all">All Products</option>
            <option value="rising">Rising Only</option>
            <option value="stable">Stable Only</option>
            <option value="falling">Falling Only</option>
          </select>
          <input class="search-box" type="text" id="searchInput" placeholder="Search product..."
            oninput="filterTable()">
        </div>
      </div>
      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Product</th>
              <th>Last 4 Weeks</th>
              <th>This Week</th>
              <th>Predicted Next Week</th>
              <th>Change</th>
              <th>Trend</th>
              <th>Stock Ready?</th>
            </tr>
          </thead>
          <tbody id="forecastBody">
            <tr>
              <td colspan="8" style="text-align:center;color:#6b7280;padding:30px;">Loading predictions...</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <footer>2025 FIL-RISE AI Forecast Module</footer>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const forecastData = <?= $forecastJson ?>;
    const chartLabels = <?= $chartLabelJson ?>;
    const chartData = <?= $chartDataJson ?>;

    /* ---- WEATHER ---- */
    const LAT = 8.8667, LON = 124.7833;
    const WMO = {
      0: ['☀', 'Clear sky'], 1: ['🌤', 'Mainly clear'], 2: ['⛅', 'Partly cloudy'], 3: ['☁', 'Overcast'],
      45: ['🌫', 'Foggy'], 51: ['🌦', 'Light drizzle'], 53: ['🌦', 'Moderate drizzle'], 55: ['🌧', 'Dense drizzle'],
      61: ['🌧', 'Slight rain'], 63: ['🌧', 'Moderate rain'], 65: ['🌧', 'Heavy rain'],
      80: ['🌦', 'Rain showers'], 81: ['🌧', 'Moderate showers'], 82: ['⛈', 'Violent showers'],
      95: ['⛈', 'Thunderstorm'], 96: ['⛈', 'Thunderstorm'], 99: ['⛈', 'Thunderstorm'],
    };
    const WEATHER_RULES = [
      { condition: 'rain', keywords: ['vapor rub', 'vaporrub', 'liniment', 'charcoal', 'kojic', 'intensive'], mult: 1.4 },
      { condition: 'hot', keywords: ['sunscreen', 'toner', 'serum', 'foam wash', 'glass skin', 'whitin'], mult: 1.5 },
      { condition: 'hot', keywords: ['cologne', 'perfume', 'car perfume'], mult: 1.3 },
      { condition: 'humid', keywords: ['deodorant', 'tawas', 'calamansi'], mult: 1.35 },
      { condition: 'cold', keywords: ['herbal oil', 'liniment', 'immuno', 'diabete'], mult: 1.3 },
    ];
    let weatherConditionKey = 'any', weatherMultipliers = {};
    let weatherDesc = '', weatherTemp = '', weatherHumid = '';

    async function fetchWeather() {
      try {
        const url = `https://api.open-meteo.com/v1/forecast?latitude=${LAT}&longitude=${LON}&current=temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m&timezone=Asia%2FManila`;
        const res = await fetch(url);
        const data = await res.json();
        const cur = data.current;
        const wcode = cur.weather_code;
        const temp = cur.temperature_2m;
        const humid = cur.relative_humidity_2m;
        const [icon, desc] = WMO[wcode] ?? ['🌡', 'Unknown'];

        document.getElementById('weatherIconSmall').textContent = icon;
        document.getElementById('weatherTempSmall').textContent = `${temp}°C`;
        document.getElementById('weatherDescSmall').textContent = desc;

        weatherDesc = desc + ' in Salay'; weatherTemp = temp + '°C'; weatherHumid = humid + '%';

        const isRainy = [51, 53, 55, 61, 63, 65, 80, 81, 82, 95, 96, 99].includes(wcode);
        const isHot = temp >= 32, isCold = temp < 25, isHumid = humid >= 80;
        if (isRainy) weatherConditionKey = 'rain';
        else if (isHot) weatherConditionKey = 'hot';
        else if (isCold) weatherConditionKey = 'cold';
        else if (isHumid) weatherConditionKey = 'humid';
        else weatherConditionKey = 'any';

        WEATHER_RULES.forEach(rule => {
          if (rule.condition === weatherConditionKey) {
            rule.keywords.forEach(kw => { weatherMultipliers[kw] = rule.mult; });
          }
        });

        renderTable(forecastData);
        populateStatCards();
        generateInsight(); // auto generate after weather loads

      } catch (e) {
        renderTable(forecastData);
        populateStatCards();
        generateInsight();
      }
    }

    function getMultiplier(name) {
      const n = name.toLowerCase();
      for (const [kw, mult] of Object.entries(weatherMultipliers)) {
        if (n.includes(kw)) return mult;
      }
      return 1.0;
    }

    /* ---- STAT CARDS ---- */
    let risingList = [], stableList = [], fallingList = [], newList = [];

    function populateStatCards() {
      risingList = forecastData.filter(r => (r.trend === 'rising' || r.trend === 'up') && r.total_sold_8w > 0);
      stableList = forecastData.filter(r => r.trend === 'stable' && r.total_sold_8w > 0);
      fallingList = forecastData.filter(r => (r.trend === 'falling' || r.trend === 'down') && r.total_sold_8w > 0);
      newList = forecastData.filter(r => r.total_sold_8w === 0);

      const set = (topEl, countEl, list, label) => {
        document.getElementById(topEl).textContent = list.length ? list[0].name : 'None';
        document.getElementById(countEl).textContent = list.length + ' product' + (list.length !== 1 ? 's' : '');
      };
      set('risingTop', 'risingCount', risingList, 'Rising');
      set('stableTop', 'stableCount', stableList, 'Stable');
      set('fallingTop', 'fallingCount', fallingList, 'Falling');
      set('newTop', 'newCount', newList, 'No Sales');
    }

    function showModal(type) {
      const map = {
        rising: { title: 'Rising Demand Products', list: risingList, color: '#1d4ed8' },
        stable: { title: 'Stable Demand Products', list: stableList, color: '#16a34a' },
        falling: { title: 'Falling Demand Products', list: fallingList, color: '#dc2626' },
        new: { title: 'Products with No Sales', list: newList, color: '#c2410c' },
      };
      const { title, list, color } = map[type];
      document.getElementById('modalTitle').textContent = title;
      document.getElementById('modalTitle').style.color = color;

      if (!list.length) {
        document.getElementById('modalBody').innerHTML = '<p style="color:#6b7280;text-align:center;padding:20px;">No products in this category.</p>';
      } else {
        document.getElementById('modalBody').innerHTML = list.map((r, i) => {
          const adj = Math.round(r.predicted_next_week * getMultiplier(r.name));
          const chg = r.change_rate;
          const chgColor = chg > 0 ? '#16a34a' : chg < 0 ? '#dc2626' : '#6b7280';
          return `<div class="detail-item">
        <div>
          <div class="name">${i + 1}. ${r.name}</div>
          <div class="info">${r.unit_type} &middot; Predicted: ${adj} units next week</div>
        </div>
        <div style="text-align:right;">
          <div style="font-weight:700;color:${chgColor};font-size:13px;">${chg > 0 ? '+' : ''}${chg}%</div>
          <div style="font-size:11px;color:#6b7280;">vs last week</div>
        </div>
      </div>`;
        }).join('');
      }

      document.getElementById('modalBackdrop').classList.add('show');
      document.getElementById('detailModal').style.display = 'block';
    }

    function closeModal() {
      document.getElementById('modalBackdrop').classList.remove('show');
      document.getElementById('detailModal').style.display = 'none';
    }

    /* ---- RENDER TABLE ---- */
    function renderTable(data) {
      const tbody = document.getElementById('forecastBody');
      if (!data.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#6b7280;padding:30px;">No products found.</td></tr>';
        return;
      }
      tbody.innerHTML = data.map((row, i) => {
        let trendLabel, trendColor;
        if (row.trend === 'rising') { trendLabel = 'Rising Fast'; trendColor = '#1d4ed8'; }
        else if (row.trend === 'up') { trendLabel = 'Going Up'; trendColor = '#16a34a'; }
        else if (row.trend === 'stable') { trendLabel = 'Stable'; trendColor = '#6b7280'; }
        else if (row.trend === 'down') { trendLabel = 'Slowing'; trendColor = '#f97316'; }
        else if (row.trend === 'falling') { trendLabel = 'Falling'; trendColor = '#dc2626'; }
        else { trendLabel = 'New'; trendColor = '#7c3aed'; }

        const chg = row.change_rate;
        const chgColor = chg > 0 ? '#16a34a' : chg < 0 ? '#dc2626' : '#6b7280';
        const mult = getMultiplier(row.name);
        const adj = Math.round(row.predicted_next_week * mult);
        const multNote = mult > 1 ? `<span style="font-size:10px;color:#2563eb;"> x${mult.toFixed(1)}</span>` : '';

        let stockBadge;
        if (row.total_sold_8w === 0) stockBadge = `<span class="badge-ok">N/A</span>`;
        else if (row.current_stock >= adj) stockBadge = `<span class="badge-ok">Ready (${row.current_stock})</span>`;
        else stockBadge = `<span class="badge-urgent">Need +${adj - row.current_stock} more</span>`;

        const weeks = [row.week3, row.week2, row.week1, row.week0];
        const maxW = Math.max(...weeks, 1);
        const bars = weeks.map((w, idx) => {
          const h = Math.round((w / maxW) * 26);
          return `<div style="display:inline-flex;flex-direction:column;align-items:center;gap:1px;margin:0 1px;">
        <div style="width:9px;height:${h}px;background:${idx === 3 ? '#2563eb' : 'rgba(37,99,235,.2)'};border-radius:2px;min-height:2px;"></div>
        <div style="font-size:9px;color:#9ca3af;">${w}</div>
      </div>`;
        }).join('');

        const rank = i === 0 ? '#1' : i === 1 ? '#2' : i === 2 ? '#3' : `<span style="color:#9ca3af;">${i + 1}</span>`;
        return `<tr>
      <td style="text-align:center;font-weight:700;">${rank}</td>
      <td class="product-name">${row.name}<br><span style="font-size:11px;color:#6b7280;font-weight:400;">${row.unit_type} &middot; P${parseFloat(row.member_price).toFixed(2)}</span></td>
      <td><div style="display:flex;align-items:flex-end;height:30px;gap:1px;">${bars}</div><div style="font-size:10px;color:#9ca3af;margin-top:2px;">w-3 to now</div></td>
      <td class="mono">${row.week0} units</td>
      <td class="mono" style="color:#2563eb;font-weight:700;font-size:14px;">${adj} units${multNote}</td>
      <td class="mono" style="color:${chgColor};font-weight:600;">${chg > 0 ? '+' : ''}${chg}%</td>
      <td><span style="color:${trendColor};font-weight:600;font-size:12px;">${trendLabel}</span></td>
      <td>${stockBadge}</td>
    </tr>`;
      }).join('');
    }

    /* ---- FILTER ---- */
    function filterTable() {
      const q = document.getElementById('searchInput').value.toLowerCase();
      const trend = document.getElementById('filterTrend').value;
      let filtered = forecastData;
      if (q) filtered = filtered.filter(r => r.name.toLowerCase().includes(q));
      if (trend === 'rising') filtered = filtered.filter(r => r.trend === 'rising' || r.trend === 'up');
      if (trend === 'stable') filtered = filtered.filter(r => r.trend === 'stable');
      if (trend === 'falling') filtered = filtered.filter(r => r.trend === 'falling' || r.trend === 'down');
      renderTable(filtered);
    }


    /* ---- AUTO AI INSIGHT ---- */
    async function generateInsight() {
      const textEl = document.getElementById('aiInsightText');
      textEl.innerHTML = `<div class="ai-loading"><div class="dot-pulse"><span></span><span></span><span></span></div> Analyzing sales data, weather, and Philippine market trends...</div>`;

      const rising = forecastData.filter(r => r.trend === 'rising' || r.trend === 'up');
      const falling = forecastData.filter(r => r.trend === 'falling' || r.trend === 'down');
      const stable = forecastData.filter(r => r.trend === 'stable');
      const top5 = forecastData.slice(0, 5);

      const prompt = `You are an AI inventory analyst for FIL-RISE, a direct-selling business in Salay, Misamis Oriental, Philippines that sells beauty, skincare, wellness, and health products.

Current weather: ${weatherDesc}, Temperature: ${weatherTemp}, Humidity: ${weatherHumid}

SALES DATA — NEXT WEEK PREDICTIONS (based on last 8 weeks of actual sales):
${top5.map((r, i) => `${i + 1}. ${r.name} — predicted ${r.predicted_next_week} units next week, trend: ${r.trend} (${r.change_rate > 0 ? '+' : ''}${r.change_rate}% vs last week), current stock: ${r.current_stock} ${r.unit_type}`).join('\n')}

RISING products (do NOT ignore these): ${rising.map(r => r.name + ' (+' + r.change_rate + '%)').join(', ') || 'None'}
STABLE products: ${stable.map(r => r.name).join(', ') || 'None'}
FALLING products (do NOT recommend these): ${falling.map(r => r.name + ' (' + r.change_rate + '%)').join(', ') || 'None'}

Write a sales insight report with exactly these 5 sections. Under each section, use bullet points starting with "•" for EVERY item. Do NOT write paragraphs — only bullets.

1. Hot Products Next Week
   • [Product Name] — [X] units predicted next week
   • [Product Name] — [X] units predicted next week
   • [Product Name] — [X] units predicted next week
   (List top 3 RISING or STABLE products only)

2. Watch Out
   • [Product Name] — down [X]%, action: [specific promo suggestion]
   (One bullet per FALLING product)

3. Weather Opportunity
   • [Product Name] — [reason based on current weather]
   • [Product Name] — [reason based on current weather]

4. One Smart Action
   • [One clear, specific action sentence]

5. Community Trends
   • Trend: [trend name] — FIL-RISE match: [product name or "none"], action: [what to do]
   • Trend: [trend name] — FIL-RISE match: [product name or "none"], action: [what to do]

STRICT RULES:
- Use ONLY bullet points (•) under each section. No paragraph sentences.
- Never recommend a FALLING product in sections 1, 3, or 4.
- Only use data from the sales figures provided.
- Section 5 must be based on real current Philippine market knowledge.
- Keep total response under 350 words. No emojis.`;

      try {
        const response = await fetch('ai_proxy.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ prompt })
        });
        const data = await response.json();
        if (data.error) { textEl.textContent = 'Error: ' + data.error; return; }
        const text = data.text ?? 'Could not generate insights.';

        // Show trend source indicator
        const sourceLabel = data.trends_source === 'live'
          ? `<div style="font-size:11px;color:#16a34a;margin-bottom:10px;font-weight:600;">Live trends fetched: ${data.trends_found} sources found online</div>`
          : `<div style="font-size:11px;color:#6b7280;margin-bottom:10px;">Using built-in Philippine market knowledge</div>`;

        function formatInsight(raw) {
          const lines = raw.split('\n');
          let html = '';
          lines.forEach(line => {
            const t = line.trim();
            if (!t) { html += '<div style="margin-bottom:8px;"></div>'; return; }
            if (/^\d+\.\s/.test(t)) {
              html += `<div style="font-size:14px;font-weight:700;color:#0f172a;margin-top:18px;margin-bottom:8px;padding-bottom:5px;border-bottom:2px solid #e2e8f0;">${t}</div>`;
            } else if (t.startsWith('•') || t.startsWith('-')) {
              const bt = t.replace(/^[•\-]\s*/, '');
              const fmt = bt.replace(/^([^—–:]+)(—|–|:)/, '<strong style="color:#0f172a;">$1</strong>$2');
              html += `<div style="display:flex;gap:10px;align-items:flex-start;padding:5px 0 5px 10px;">
                <span style="color:#2563eb;font-weight:800;font-size:16px;flex-shrink:0;line-height:1.4;">•</span>
                <span style="font-size:13.5px;color:#374151;line-height:1.7;">${fmt}</span>
              </div>`;
            } else {
              html += `<div style="font-size:13.5px;color:#374151;line-height:1.7;padding:2px 0;">${t}</div>`;
            }
          });
          return html;
        }

        textEl.innerHTML = sourceLabel;
        const preEl = document.createElement('div');
        preEl.style.cssText = 'font-size:14px;line-height:1.8;color:#1f2937;white-space:pre-wrap;';
        textEl.appendChild(preEl);
        let i = 0;
        const cursor = document.createElement('span');
        cursor.className = 'typing-cursor';
        textEl.appendChild(cursor);
        function typeChar() {
          if (i < text.length) {
            preEl.textContent += text[i];
            i++;
            setTimeout(typeChar, 8);
          } else {
            cursor.remove();
            preEl.style.whiteSpace = 'normal';
            preEl.innerHTML = formatInsight(text);
          }
        }
        typeChar();
      } catch (e) {
        textEl.textContent = 'Could not connect to AI. Please check your internet connection.';
      }
    }

    /* ---- INIT ---- */
    fetchWeather(); // this triggers everything: weather -> table -> stat cards -> AI insight
  </script>
  <?php include 'chatbot.php'; ?>
<?php include 'mobile_sidebar.php'; ?>
</body>

</html>
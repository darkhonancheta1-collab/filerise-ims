<?php
require 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

$user_id = $_SESSION['user_id'];
$date = $_GET['date'] ?? date('Y-m-d');

$mode = $_GET['mode'] ?? 'current';
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;

/* ============================
   FETCH DISTRIBUTORS (COLUMNS)
============================= */
$distStmt = $pdo->prepare("
    SELECT id, name 
    FROM distributors 
    WHERE user_id=? 
    ORDER BY name ASC
");
$distStmt->execute([$user_id]);
$distributors = $distStmt->fetchAll();

/* ============================
   FETCH PRODUCTS (ROWS)
============================= */
$prodStmt = $pdo->prepare("
    SELECT id, name, product_type, member_price
    FROM products
    WHERE user_id=? AND is_active = 1
    ORDER BY product_type ASC, name ASC
");
$prodStmt->execute([$user_id]);
$products = $prodStmt->fetchAll();

/* ============================
   FETCH SALES (RAW)
============================= */

if ($mode === 'range' && $from && $to) {

  $salesStmt = $pdo->prepare("
        SELECT distributor_id, product_id, unit, quantity_sold
        FROM distributor_sales
        WHERE user_id=?
        AND DATE(sale_date) BETWEEN ? AND ?
    ");
  $salesStmt->execute([$user_id, $from, $to]);

} else {

  $salesStmt = $pdo->prepare("
        SELECT distributor_id, product_id, unit, quantity_sold
        FROM distributor_sales
        WHERE user_id=?
        AND DATE(sale_date)=?
    ");
  $salesStmt->execute([$user_id, $date]);
}

$sales = $salesStmt->fetchAll();


/* ============================
   BUILD LOOKUP
   sold[product][distributor][] = '1 BOX'
============================= */
$sold = [];

$individualItems = 0; // PCS
$packageItems = 0;    // BOX + BOTTLE
$totalSales = 0;

$sold = [];

foreach ($sales as $s) {

  $pid = $s['product_id'];
  $did = $s['distributor_id'];
  $unit = strtoupper($s['unit']);
  $qty = (int)$s['quantity_sold'];

  if ($qty <= 0) continue;

  /* =========================
     BUILD DISPLAY DATA
  ========================= */

  if (!isset($sold[$pid][$did][$unit])) {
    $sold[$pid][$did][$unit] = 0;
  }

  $sold[$pid][$did][$unit] += $qty;

  /* =========================
     COUNT ITEMS
  ========================= */

  if ($unit === 'PCS') {
      $individualItems += $qty;
  } else {
      $packageItems += $qty;
  }

  /* =========================
     CALCULATE TOTAL SALES
  ========================= */

  foreach ($products as $p) {
      if ($p['id'] == $pid) {
          $totalSales += $p['member_price'] * $qty;
          break;
      }
  }

}

/* ============================
   PRODUCT TYPE HEADER
============================= */
function groupTitle($type)
{
  if ($type === 'Product')
    return 'REGULAR PRODUCTS';
  if ($type === 'La Belleza')
    return 'LA BELLEZA PRODUCTS';
  if ($type === 'By-Pcs Product')
    return 'BY-PCS PRODUCTS';
  return strtoupper($type);
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Daily Sales Record</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="7.png">
  <link rel="stylesheet" href="mobile.css">
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
      vertical-align: middle;
      font-size: 13px;
    }

    .group-row td {
      background: #f1f7f0;
      font-weight: bold;
      text-align: left !important;
    }

    @media print {

      .sidebar,
      .no-print {
        display: none !important;
      }

      body {
        background: white;
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

    <img src="7.png" class="logo" alt="Fil-Rise Logo">

    <div class="menu-title">Core</div>
    <a href="dashboard.php"> Dashboard</a>
    <a href="ai_forecast.php"
      style="background:linear-gradient(90deg,rgba(6,182,212,.15),rgba(59,130,246,.15));color:#06b6d4;border:1px solid rgba(6,182,212,.25);">
      AI Dashboard</a>
      
    <div class="menu-title">Inventory</div>
    <a href="product.php"> Products</a>
    <a href="removed_products.php">Archived Products</a>

    <div class="menu-title">Operations</div>
    <a href="transaction.php">Consignment Transactions</a>
    <a href="sales.php"> Walk-in Sales</a>
    <a href="returns.php">Product Returns</a>
    <a href="stock_requests.php">Stock Requests</a>
    <a href="daily_records.php"> Daily Records</a>
    <a href="daily_sales_record.php" class="active"> Daily Sales Record</a>

    <div class="menu-title">People</div>
    <a href="distributor.php"> Distributors</a>
    <a href="members.php"> Members</a>
      
    <div class="logout" onclick="window.location='logout.php'">
      Logout
    </div>

  </div>

  <div class="main">

    <div class="mb-4 no-print">

      <h4 class="mb-3">Individual Daily Sales Record</h4>

      <form method="get">

        <div class="row align-items-end">

          <!-- LEFT SIDE -->
          <div class="col-md-8">
            <div class="row g-3">

              <!-- Mode -->
              <div class="col-md-4">
                <label class="form-label fw-semibold">Record Type</label>
                <select name="mode" id="modeSelect" class="form-select">
                  <option value="current" <?= $mode === 'current' ? 'selected' : '' ?>>
                    Current Record
                  </option>
                  <option value="range" <?= $mode === 'range' ? 'selected' : '' ?>>
                    Previous Record
                  </option>
                </select>
              </div>

              <!-- Single Date -->
              <div class="col-md-4" id="singleDate">
                <label class="form-label fw-semibold">Date</label>
                <input type="date" name="date" class="form-control" value="<?= $date ?>">
              </div>

              <!-- Date Range -->
              <div class="col-md-6 d-none" id="rangeDate">
                <label class="form-label fw-semibold">Date Range</label>
                <div class="d-flex align-items-center gap-2">
                  <input type="date" name="from" class="form-control" value="<?= $from ?>">
                  <span class="fw-bold">-</span>
                  <input type="date" name="to" class="form-control" value="<?= $to ?>">
                </div>
              </div>

            </div>
          </div>

          <!-- RIGHT SIDE BUTTONS -->
          <div class="col-md-4 text-end">
            <a href="daily_sales_record_pdf.php?mode=<?= $mode ?>&date=<?= $date ?>&from=<?= $from ?>&to=<?= $to ?>"
              class="btn btn-danger px-4">
              Download PDF
            </a>
            <button class="btn btn-primary me-2 px-4">Load</button>
            <button type="button" class="btn btn-secondary px-4" onclick="window.print()">
              Print
            </button>
          </div>

        </div>

      </form>
    </div>



    <div class="card p-3">

      <div class="text-center mb-3">
        <div class="fw-bold">SALAY MISAMIS ORIENTAL</div>
        <div class="fw-bold">INDIVIDUAL DAILY SALES RECORD</div>
        <div class="text-danger fw-bold">
          <?php if ($mode === 'range' && $from && $to): ?>
            SALES REPORT AS OF:
            <?= date('F d, Y', strtotime($from)) ?>
            TO
            <?= date('F d, Y', strtotime($to)) ?>
          <?php else: ?>
            <?= strtoupper(date('F d, Y', strtotime($date))) ?>
          <?php endif; ?>
        </div>

      </div>

      <table class="table table-bordered table-sm">
        <thead class="table-success">
          <tr>
            <th style="width:30%; text-align:left;">PRODUCTS</th>
            <th style="width:10%;">PRICE</th>
            <?php foreach ($distributors as $d): ?>
              <th><?= htmlspecialchars($d['name']) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>

        <tbody>
          <?php
          $currentType = null;

          foreach ($products as $p):
            if ($currentType !== $p['product_type']):
              $currentType = $p['product_type'];
              ?>
              <tr class="group-row">
                <td colspan="<?= 2 + count($distributors) ?>">
                  <?= groupTitle($currentType) ?>
                </td>
              </tr>
            <?php endif; ?>

            <tr>
              <td style="text-align:left;"><?= htmlspecialchars($p['name']) ?></td>
              <td><?= number_format($p['member_price'], 2) ?></td>

              <?php foreach ($distributors as $d): ?>
                <td>
                  <?php
                  $data = $sold[$p['id']][$d['id']] ?? [];

                  foreach ($data as $unit => $qty) {
                    echo $qty . ' ' . $unit . '<br>';
                  }
                  ?>
                </td>
              <?php endforeach; ?>
            </tr>

          <?php endforeach; ?>
        </tbody>

        <tfoot>
          <tr class="fw-bold">
            <td colspan="2">INDIVIDUAL ITEMS SOLD</td>
            <td colspan="<?= count($distributors) ?>"><?= $individualItems ?></td>
          </tr>
          <tr class="fw-bold">
            <td colspan="2">PACKAGE SOLD</td>
            <td colspan="<?= count($distributors) ?>"><?= $packageItems ?></td>
          </tr>
          <tr class="fw-bold text-danger">
            <td colspan="2">TOTAL SALES</td>
            <td colspan="<?= count($distributors) ?>"><?= number_format($totalSales, 2) ?></td>
          </tr>
        </tfoot>

      </table>
    </div>
  </div>

  <script>
    const modeSelect = document.getElementById("modeSelect");
    const singleDate = document.getElementById("singleDate");
    const rangeDate = document.getElementById("rangeDate");

    function toggleMode() {
      if (modeSelect.value === "range") {
        singleDate.classList.add("d-none");
        rangeDate.classList.remove("d-none");
      } else {
        singleDate.classList.remove("d-none");
        rangeDate.classList.add("d-none");
      }
    }

    modeSelect.addEventListener("change", toggleMode);
    toggleMode(); // run on page load
  </script>
  <?php include 'chatbot.php'; ?>
  <?php include 'mobile_sidebar.php'; ?>
</body>

</html>
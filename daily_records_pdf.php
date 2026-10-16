<?php
/**
 * daily_records_pdf.php — PDF Export for Daily Inventory Report
 *
 * Generates a downloadable PDF of the daily inventory snapshot report.
 * Uses Dompdf library to render HTML-to-PDF. Fetches the same data
 * as daily_records.php and formats it into a printable table layout.
 *
 * Accessed via: daily_records_pdf.php?date=YYYY-MM-DD
 */
require 'config.php';
require 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/* -- Authentication guard -- */
session_start();
if (!isset($_SESSION['user_id']))
    exit("Unauthorized");

$user_id = $_SESSION['user_id'];

$date = $_GET['date'] ?? date('Y-m-d');
$mode = $_GET['mode'] ?? 'current';
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;

/* ================= FETCH PRODUCTS ================= */
$stmt = $pdo->prepare("
    SELECT id, name, member_price,
           box_qty, pcs_qty, bottle_qty
    FROM products
    WHERE user_id=? AND is_active=1
    ORDER BY name ASC
");
$stmt->execute([$user_id]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= FETCH SALES ================= */
if ($mode === 'range' && $from && $to) {
    $salesStmt = $pdo->prepare("
        SELECT wsi.product_id, wsi.unit, SUM(wsi.quantity) as total_sold
        FROM walkin_sales_items wsi
        JOIN walkin_sales ws ON ws.id = wsi.sale_id
        WHERE ws.user_id=? 
        AND DATE(ws.created_at) BETWEEN ? AND ?
        GROUP BY wsi.product_id, wsi.unit
    ");
    $salesStmt->execute([$user_id, $from, $to]);
} else {
    $salesStmt = $pdo->prepare("
        SELECT wsi.product_id, wsi.unit, SUM(wsi.quantity) as total_sold
        FROM walkin_sales_items wsi
        JOIN walkin_sales ws ON ws.id = wsi.sale_id
        WHERE ws.user_id=? 
        AND DATE(ws.created_at)=?
        GROUP BY wsi.product_id, wsi.unit
    ");
    $salesStmt->execute([$user_id, $date]);
}

$salesRows = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

/* Organize sales */
$salesData = [];
foreach ($salesRows as $row) {
    $salesData[$row['product_id']][$row['unit']] = (float) $row['total_sold'];
}

/* ================= PDF TITLE DATE ================= */
$reportDateText = ($mode === 'range' && $from && $to)
    ? date('F d, Y', strtotime($from)) . ' - ' . date('F d, Y', strtotime($to))
    : date('F d, Y', strtotime($date));

/* ================= BUILD PDF HTML ================= */
$totalAmount = 0;
$totalBegin = 0;
$totalSoldItems = 0;
$totalRemaining = 0;

$rowsHtml = "";

foreach ($products as $p) {

    $beginBox = (float) $p['box_qty'];
    $beginPcs = (float) $p['pcs_qty'];
    $beginBottle = (float) $p['bottle_qty'];

    $soldBox = (float) ($salesData[$p['id']]['BOX'] ?? 0);
    $soldPcs = (float) ($salesData[$p['id']]['PCS'] ?? 0);
    $soldBottle = (float) ($salesData[$p['id']]['BOTTLE'] ?? 0);

    $beginTotal = $beginBox + $beginPcs + $beginBottle;
    $soldTotal = $soldBox + $soldPcs + $soldBottle;
    $remainingTotal = $beginTotal - $soldTotal;

    $amount = $soldTotal * (float) $p['member_price'];

    $totalAmount += $amount;
    $totalBegin += $beginTotal;
    $totalSoldItems += $soldTotal;
    $totalRemaining += $remainingTotal;

    $beginText = [];
    if ($beginBox > 0)
        $beginText[] = $beginBox . " box";
    if ($beginBottle > 0)
        $beginText[] = $beginBottle . " bottle";
    if ($beginPcs > 0)
        $beginText[] = $beginPcs . " pcs";
    if (!$beginText)
        $beginText[] = "0";

    $soldText = [];
    if ($soldBox > 0)
        $soldText[] = $soldBox . " box";
    if ($soldBottle > 0)
        $soldText[] = $soldBottle . " bottle";
    if ($soldPcs > 0)
        $soldText[] = $soldPcs . " pcs";
    if (!$soldText)
        $soldText[] = "0";

    $remainBox = $beginBox - $soldBox;
    $remainBottle = $beginBottle - $soldBottle;
    $remainPcs = $beginPcs - $soldPcs;

    $remainText = [];

    if ($remainBox > 0)
        $remainText[] = $remainBox . " box";

    if ($remainBottle > 0)
        $remainText[] = $remainBottle . " bottle";

    if ($remainPcs > 0)
        $remainText[] = $remainPcs . " pcs";

    /* If absolutely nothing remains */
    if (empty($remainText)) {
        $remainText[] = "0";
    }

    $rowsHtml .= "
      <tr>
        <td class='desc'>" . htmlspecialchars($p['name']) . "</td>
        <td class='center'>" . implode("<br>", $beginText) . "</td>
        <td class='right'>" . number_format((float) $p['member_price'], 2) . "</td>
        <td class='center'>" . implode("<br>", $soldText) . "</td>
<td class='center'>0</td>   /* PACKAGE COLUMN */
<td class='right'>₱" . number_format($amount, 2) . "</td>
<td class='center'>" . implode("<br>", $remainText) . "</td>
      </tr>
    ";
}

$html = '
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>

    table.table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

th:nth-child(1), td:nth-child(1) { width: 160px; } /* Description */
th:nth-child(2), td:nth-child(2) { width: 85px; }
th:nth-child(3), td:nth-child(3) { width: 60px; }
th:nth-child(4), td:nth-child(4) { width: 90px; }
th:nth-child(5), td:nth-child(5) { width: 70px; }
th:nth-child(6), td:nth-child(6) { width: 80px; }
th:nth-child(7), td:nth-child(7) { width: 85px; }

td {
    word-wrap: break-word;
}

    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color:#111; }

    .text-center { text-align:center; }
    .fw-bold { font-weight:700; }
    .mb-3 { margin-bottom: 12px; }

    /* Bootstrap-like table styles (Dompdf-friendly) */
    table.table { width: 100%; border-collapse: collapse; }
    .table th, .table td { border: 1px solid #000; padding: 6px; vertical-align: middle; }
    .table th { font-weight:700; text-transform:uppercase; font-size: 11px; }
    .table-sm th, .table-sm td { padding: 4px; font-size: 11px; }

    .table-bordered { border: 1px solid #000; }

    /* bootstrap-ish header color */
    .table-success th { background: #d1e7dd; }

    /* alignments like your system */
    .table th, .table td { text-align:center; }
    .table td:first-child { text-align:left; }

    /* repeat header on each page */
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
  </style>
</head>

<body>

  <div class="text-center mb-3">
    <div class="fw-bold">EVERYDAY INVENTORY RECORD</div>
    <div>' . htmlspecialchars($reportDateText) . '</div>
  </div>

  <table class="table table-bordered table-sm">
    <thead class="table-success">
      <tr>
        <th style="width:25%">DESCRIPTION</th>
        <th>BEGINNING STOCK</th>
        <th>PRICE</th>
        <th>INDIVIDUAL SOLD</th>
        <th>PACKAGE</th>
        <th>AMOUNT</th>
        <th>REMAINING</th>
      </tr>
    </thead>
    <tbody>
      ' . $rowsHtml . '
      <tr style="font-weight:bold; background:#f8d7da;">
        <td>TOTAL</td>
        <td>' . $totalBegin . ' items</td>
        <td></td>
        <td>' . $totalSoldItems . ' items</td>
        <td></td>
        <td>₱' . number_format($totalAmount, 2) . '</td>
        <td>' . $totalRemaining . ' items</td>
      </tr>
    </tbody>
  </table>

</body>
</html>
';

/* ================= DOMPDF SETTINGS ================= */
$options = new Options();
$options->set('isRemoteEnabled', true); // if you later add logo via URL
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

/* Download */
$dompdf->stream("daily_inventory_record.pdf", ["Attachment" => true]);
exit;
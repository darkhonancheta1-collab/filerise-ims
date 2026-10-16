<?php
/**
 * daily_sales_record_pdf.php — PDF Export for Daily Sales Record
 *
 * Generates a downloadable PDF of the daily sales breakdown.
 * Uses Dompdf library. Shows all revenue streams (distributor sales,
 * walk-in sales, membership fees) for the selected date or range.
 *
 * Accessed via: daily_sales_record_pdf.php?date=YYYY-MM-DD
 */
require 'config.php';
require 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/* -- Authentication guard -- */
session_start();
if (!isset($_SESSION['user_id'])) exit("Unauthorized");

$user_id = $_SESSION['user_id'];
$date = $_GET['date'] ?? date('Y-m-d');
$mode = $_GET['mode'] ?? 'current';
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;

/* ================= FETCH DISTRIBUTORS ================= */
$distStmt = $pdo->prepare("
    SELECT id, name 
    FROM distributors 
    WHERE user_id=? 
    ORDER BY name ASC
");
$distStmt->execute([$user_id]);
$distributors = $distStmt->fetchAll();

/* ================= FETCH PRODUCTS ================= */
$prodStmt = $pdo->prepare("
    SELECT id, name, product_type, member_price
    FROM products
    WHERE user_id=? AND is_active = 1
    ORDER BY product_type ASC, name ASC
");
$prodStmt->execute([$user_id]);
$products = $prodStmt->fetchAll();

/* ================= FETCH SALES ================= */
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

/* ================= BUILD LOOKUP ================= */
$sold = [];
$individualItems = 0;
$packageItems = 0;
$totalSales = 0;

foreach ($sales as $s) {
    $pid = $s['product_id'];
    $did = $s['distributor_id'];
    $unit = strtoupper($s['unit']);
    $qty = (int)$s['quantity_sold'];

    if ($qty <= 0) continue;

    $sold[$pid][$did][] = $qty . ' ' . $unit;

    if ($unit === 'PCS') {
        $individualItems += $qty;
    } else {
        $packageItems += $qty;
    }
}

/* ================= DATE TEXT ================= */
$dateText = ($mode === 'range' && $from && $to)
    ? date('F d, Y', strtotime($from)) . ' - ' . date('F d, Y', strtotime($to))
    : date('F d, Y', strtotime($date));

/* ================= BUILD TABLE ================= */
$rowsHtml = '';
$currentType = null;

foreach ($products as $p) {

    if ($currentType !== $p['product_type']) {
        $currentType = $p['product_type'];
        $rowsHtml .= "
            <tr class='group'>
                <td colspan='".(2 + count($distributors))."'>
                    ".htmlspecialchars(strtoupper($currentType))."
                </td>
            </tr>
        ";
    }

    $rowsHtml .= "
        <tr>
            <td class='left'>".htmlspecialchars($p['name'])."</td>
            <td>".number_format($p['member_price'],2)."</td>
    ";

    foreach ($distributors as $d) {

        $lines = $sold[$p['id']][$d['id']] ?? [];
        $cell = '';

        if (!empty($lines)) {
            $cell = implode('<br>', $lines);

            foreach ($lines as $l) {
                [$q] = explode(' ', $l);
                $totalSales += $q * $p['member_price'];
            }
        }

        $rowsHtml .= "<td>$cell</td>";
    }

    $rowsHtml .= "</tr>";
}

/* ================= FULL HTML ================= */
$html = "
<!doctype html>
<html>
<head>
<meta charset='utf-8'>
<style>

body {
    font-family: DejaVu Sans, Arial;
    font-size: 10px;
}

h2 {
    text-align:center;
    margin-bottom:5px;
}

table {
    width:100%;
    border-collapse: collapse;
    table-layout: fixed;
}

th, td {
    border:1px solid #000;
    padding:4px;
    text-align:center;
    vertical-align:middle;
    word-wrap:break-word;
}

th {
    background:#d1e7dd;
    font-weight:bold;
}

.left { text-align:left; }

.group td {
    background:#f1f7f0;
    font-weight:bold;
    text-align:left;
}

tfoot td {
    font-weight:bold;
}

thead { display: table-header-group; }

</style>
</head>
<body>

<h2>INDIVIDUAL DAILY SALES RECORD</h2>
<div style='text-align:center; margin-bottom:10px;'>$dateText</div>

<table>
<thead>
<tr>
    <th style='width:20%'>PRODUCTS</th>
    <th style='width:8%'>PRICE</th>";

foreach ($distributors as $d) {
    $html .= "<th>".htmlspecialchars($d['name'])."</th>";
}

$html .= "
</tr>
</thead>
<tbody>
$rowsHtml
</tbody>
<tfoot>
<tr>
<td colspan='2'>INDIVIDUAL ITEMS SOLD</td>
<td colspan='".count($distributors)."'>$individualItems</td>
</tr>
<tr>
<td colspan='2'>PACKAGE SOLD</td>
<td colspan='".count($distributors)."'>$packageItems</td>
</tr>
<tr>
<td colspan='2'>TOTAL SALES</td>
<td colspan='".count($distributors)."'>₱".number_format($totalSales,2)."</td>
</tr>
</tfoot>
</table>

</body>
</html>
";

/* ================= DOMPDF ================= */
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);

/* IMPORTANT: use landscape for many distributors */
$dompdf->setPaper('A4', 'portrait');

$dompdf->render();
$dompdf->stream("daily_sales_record.pdf", ["Attachment" => true]);
exit;
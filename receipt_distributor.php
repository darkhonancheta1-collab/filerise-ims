<?php
/**
 * receipt_distributor.php — Distributor Transaction Receipt (Printable)
 *
 * Generates a printable receipt for a single consignment transaction.
 * Accessed via: receipt_distributor.php?id=<transaction_id>
 * Shows: transaction date, distributor name, items sold, prices, total.
 * Optimized for thermal/POS printer output.
 */
require 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* -- Authentication guard -- */
if (!isset($_SESSION['user_id'])) {
    exit("Unauthorized");
}

$user_id = $_SESSION['user_id'];
$transaction_id = intval($_GET['id'] ?? 0);

/* ── TRANSACTION + DISTRIBUTOR ── */
$stmt = $pdo->prepare("
    SELECT t.id, t.transaction_date, t.status, d.name AS distributor_name
    FROM transactions t
    JOIN distributors d ON d.id = t.distributor_id
    WHERE t.id=? AND t.user_id=?
    LIMIT 1
");
$stmt->execute([$transaction_id, $user_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) exit("Transaction not found.");

/* ── BRANCH NAME ── */
$stmt = $pdo->prepare("SELECT branch_name FROM users WHERE id=?");
$stmt->execute([$user_id]);
$branch = $stmt->fetchColumn() ?: "Branch #" . $user_id;

/* ── SOLD ITEMS FOR THIS TRANSACTION ── */
$itemsStmt = $pdo->prepare("
    SELECT p.name, ds.unit, ds.quantity_sold, p.member_price,
           MIN(ds.sale_date) AS sale_date
    FROM distributor_sales ds
    JOIN products p ON p.id = ds.product_id
    WHERE ds.transaction_id=? AND ds.user_id=?
    GROUP BY p.id, ds.unit, ds.quantity_sold, p.member_price
");
$itemsStmt->execute([$transaction_id, $user_id]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$items) exit("No sold items recorded yet for this transaction.");

$total = 0;
$lastSaleDate = null;
foreach ($items as $it) {
    $unitPrice = ($it['unit'] === 'BOX') ? (float)$it['member_price'] : (float)$it['member_price'];
    $total += $unitPrice * (int)$it['quantity_sold'];
    if (!$lastSaleDate || $it['sale_date'] > $lastSaleDate) $lastSaleDate = $it['sale_date'];
}

$refNo = "CN-" . str_pad($transaction_id, 6, '0', STR_PAD_LEFT);
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Consignment Receipt</title>
    <style>

        @page { margin: 6mm; }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        /* SCREEN: card preview */
        @media screen {
            html {
                background: #888;
                min-height: 100vh;
                display: flex;
                justify-content: center;
                align-items: flex-start;
                padding: 30px 0;
            }
            body {
                background: #fff;
                width: 300px;
                padding: 16px 14px 22px;
                box-shadow: 0 6px 30px rgba(0,0,0,0.35);
            }
        }

        /* PRINT: fill the page */
        @media print {
            html, body {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
            }
        }

        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            color: #000;
            line-height: 1.5;
        }

        /* ── HEADER ── */
        .header {
            text-align: center;
            padding-bottom: 8px;
        }
        .header h3 {
            font-size: 16px;
            font-weight: 900;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        .header p {
            font-size: 10px;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #555;
        }

        /* ── DIVIDERS ── */
        .line-solid  { border-top: 1px solid #000;   margin: 6px 0; }
        .line-dashed { border-top: 1px dashed #888;  margin: 6px 0; }
        .line-double {
            border-top: 3px double #000;
            margin: 6px 0;
        }

        /* ── INFO TABLE ── */
        .info-table {
            width: 100%;
            font-size: 10.5px;
            border-collapse: collapse;
            margin: 4px 0;
        }
        .info-table td {
            padding: 1px 0;
            vertical-align: top;
        }
        .info-table td:first-child {
            color: #555;
            white-space: nowrap;
            padding-right: 8px;
            width: 1%;
        }
        .info-table td:last-child {
            font-weight: 600;
        }

        /* ── ITEMS ── */
        .item {
            margin: 5px 0;
        }
        .item-name {
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .item-row {
            display: flex;
            justify-content: space-between;
            font-size: 10.5px;
            color: #333;
            padding-left: 4px;
        }

        /* ── TOTAL ── */
        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            font-weight: 900;
            letter-spacing: 0.5px;
            padding: 4px 0;
        }

        /* ── FOOTER ── */
        .footer {
            text-align: center;
            padding-top: 6px;
        }
        .footer p {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .footer small {
            display: block;
            font-size: 9px;
            color: #666;
            margin-top: 3px;
            letter-spacing: 0.5px;
        }

    </style>
</head>
<body onload="window.print()">

    <!-- HEADER -->
    <div class="header">
        <h3>FIL-RISE</h3>
        <p>Consignment Receipt</p>
        <b><?= htmlspecialchars($branch) ?></b>
    </div>

    <div class="line-solid"></div>

    <!-- TRANSACTION INFO -->
    <table class="info-table">
        <tr>
            <td>Ref #</td>
            <td><?= htmlspecialchars($refNo) ?></td>
        </tr>
        <tr>
            <td>Date</td>
            <td><?= date("M d, Y", strtotime($lastSaleDate ?? $sale['transaction_date'])) ?></td>
        </tr>
        <tr>
            <td>Distributor</td>
            <td><?= htmlspecialchars($sale['distributor_name']) ?></td>
        </tr>
        <tr>
            <td>Status</td>
            <td><?= htmlspecialchars($sale['status']) ?></td>
        </tr>
    </table>

    <div class="line-dashed"></div>

    <!-- ITEMS -->
    <?php foreach ($items as $item): ?>
        <?php $lineTotal = (float)$item['member_price'] * (int)$item['quantity_sold']; ?>
        <div class="item">
            <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
            <div class="item-row">
                <span><?= (int)$item['quantity_sold'] ?> <?= htmlspecialchars($item['unit']) ?> &times; &#8369;<?= number_format((float)$item['member_price'], 2) ?></span>
                <span>&#8369;<?= number_format($lineTotal, 2) ?></span>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="line-double"></div>

    <!-- TOTAL -->
    <div class="total-row">
        <span>TOTAL</span>
        <span>&#8369;<?= number_format($total, 2) ?></span>
    </div>

    <div class="line-solid"></div>

    <!-- FOOTER -->
    <div class="footer">
        <p>Thank you!</p>
        <small>Consignment sale &mdash; keep as proof of transaction.</small>
    </div>

</body>
</html>
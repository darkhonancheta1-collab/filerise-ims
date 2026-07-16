<?php
require 'config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    exit("Unauthorized");
}

$user_id = $_SESSION['user_id'];
$sale_id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT ws.id, ws.reference_no, ws.created_at, ws.total_amount,
           m.full_name, m.status
    FROM walkin_sales ws
    LEFT JOIN members m ON ws.member_id = m.id
    WHERE ws.id=? AND ws.user_id=?
    LIMIT 1
");
$stmt->execute([$sale_id, $user_id]);
$sale = $stmt->fetch();

if (!$sale) exit("Sale not found.");

$items = $pdo->prepare("
    SELECT p.name, wsi.unit, wsi.quantity, wsi.price
    FROM walkin_sales_items wsi
    JOIN products p ON p.id = wsi.product_id
    WHERE wsi.sale_id=?
");
$items->execute([$sale_id]);
$items = $items->fetchAll();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt</title>
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
<body onload="printReceipt()">

    <!-- HEADER -->
    <div class="header">
        <h3>FIL-RISE</h3>
        <p>Walk-in Receipt</p>
        <b>Salay Branch</b>
    </div>

    <div class="line-solid"></div>

    <!-- TRANSACTION INFO -->
    <table class="info-table">
        <tr>
            <td>Transaction Ref #</td>
            <td><?= htmlspecialchars($sale['reference_no']) ?></td>
        </tr>
        <tr>
            <td>Date</td>
            <td><?= date("M d, Y h:i A", strtotime($sale['created_at'])) ?></td>
        </tr>
        <tr>
            <td>Customer</td>
            <td><?= htmlspecialchars($sale['full_name'] ?? 'Guest') ?></td>
        </tr>
        <tr>
            <td>Status</td>
            <td><?= ($sale['status'] === 'ACTIVE') ? 'Member' : 'Non-Member' ?></td>
        </tr>
    </table>

    <div class="line-dashed"></div>

    <!-- ITEMS -->
    <?php foreach ($items as $item): ?>
        <div class="item">
            <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
            <div class="item-row">
                <span><?= $item['quantity'] ?> <?= $item['unit'] ?> &times; &#8369;<?= number_format($item['price'], 2) ?></span>
                <span>&#8369;<?= number_format($item['quantity'] * $item['price'], 2) ?></span>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="line-double"></div>

    <!-- TOTAL -->
    <div class="total-row">
        <span>TOTAL</span>
        <span>&#8369;<?= number_format($sale['total_amount'], 2) ?></span>
    </div>

    <div class="line-solid"></div>

    <!-- FOOTER -->
    <div class="footer">
        <p>Thank you!</p>
        <small>Please keep this as proof of purchase.</small>
    </div>

    <script>
        function printReceipt() {
            window.print();
            setTimeout(function () {
                const now = new Date();
                const localDate = now.getFullYear() + "-" + String(now.getMonth()+1).padStart(2,"0") + "-" + String(now.getDate()).padStart(2,"0");
                window.location.href = "sales.php?tab=history&date=" + localDate;
            }, 500);
        }
    </script>
</body>
</html>
<?php
require 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success = '';

/* ============================================================
 CREATE returns TABLE IF NOT EXISTS
============================================================ */
$pdo->exec("
 CREATE TABLE IF NOT EXISTS product_returns (
 id INT AUTO_INCREMENT PRIMARY KEY,
 return_ref VARCHAR(50) NOT NULL,
 original_ref VARCHAR(50) NOT NULL,
 sale_id INT NULL,
 user_id INT NOT NULL,
 customer_name VARCHAR(150) DEFAULT '',
 reason_type VARCHAR(80) NOT NULL,
 reason_detail TEXT NULL,
 return_type ENUM('EXCHANGE','UPGRADE') NOT NULL,
 upgrade_income DECIMAL(10,2) NOT NULL DEFAULT 0,
 status ENUM('PENDING','COMPLETED','REJECTED') DEFAULT 'PENDING',
 returned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL,
 staff_note TEXT NULL
 )
");
// Add upgrade_income column if it doesn't exist yet (for existing installs)
try { $pdo->exec("ALTER TABLE product_returns ADD COLUMN upgrade_income DECIMAL(10,2) NOT NULL DEFAULT 0"); } catch(Exception $e) {}

$pdo->exec("
 CREATE TABLE IF NOT EXISTS product_return_items (
 id INT AUTO_INCREMENT PRIMARY KEY,
 return_id INT NOT NULL,
 product_id INT NOT NULL,
 product_name VARCHAR(200) NOT NULL,
 unit VARCHAR(20) NOT NULL,
 quantity INT NOT NULL DEFAULT 1,
 price DECIMAL(10,2) NOT NULL DEFAULT 0,
 item_type ENUM('RETURNED','REPLACEMENT') NOT NULL
 )
");

/* ============================================================
 FETCH PRODUCTS (active)
============================================================ */
$prodStmt = $pdo->prepare("SELECT * FROM products WHERE user_id=? AND is_active=1 ORDER BY name ASC");
$prodStmt->execute([$user_id]);
$products = $prodStmt->fetchAll();

$productsJson = [];
foreach ($products as $p) {
    $box = (int) $p['box_qty'];
    $pcs = (int) $p['pcs_qty'];
    $bottle = (int) $p['bottle_qty'];
    $productsJson[] = [
        'id'           => $p['id'],
        'name'         => $p['name'],
        'unit_type'    => $p['unit_type'],
        'srp_price'    => (float) $p['srp_price'],
        'member_price' => (float) $p['member_price'],
        'box_qty'      => $box,
        'pcs_qty'      => $pcs,
        'bottle_qty'   => $bottle,
        'avail_box'    => ($p['unit_type'] === 'BOX')    ? $box : 0,
        'avail_pcs'    => ($p['unit_type'] === 'BOX')    ? ($pcs + $box * 10) : $pcs,
        'avail_bottle' => ($p['unit_type'] === 'BOTTLE') ? $bottle : 0,
    ];
}

/* ============================================================
 LOOKUP SALE BY REFERENCE
============================================================ */
$lookupSale = null;
$lookupItems = [];
if (isset($_GET['lookup_ref']) && trim($_GET['lookup_ref']) !== '') {
    $ref = trim($_GET['lookup_ref']);
    $sl = $pdo->prepare("
 SELECT ws.id, ws.reference_no, ws.created_at, ws.total_amount, ws.member_id,
 m.full_name, m.status AS member_status
 FROM walkin_sales ws
 LEFT JOIN members m ON ws.member_id = m.id
 WHERE ws.reference_no = ? AND ws.user_id = ?
 LIMIT 1
 ");
    $sl->execute([$ref, $user_id]);
    $lookupSale = $sl->fetch();

    if ($lookupSale) {
        // Check if this sale was already returned
        $alreadyRet = $pdo->prepare("SELECT return_ref FROM product_returns WHERE original_ref = ? AND user_id = ? AND status = 'COMPLETED' LIMIT 1");
        $alreadyRet->execute([$ref, $user_id]);
        $alreadyRetRow = $alreadyRet->fetch();
        if ($alreadyRetRow) {
            $errors[] = "This transaction (<strong>" . htmlspecialchars($ref) . "</strong>) has already been returned (Return Ref: <strong>" . htmlspecialchars($alreadyRetRow['return_ref']) . "</strong>). The same sale cannot be returned twice.";
            $lookupSale = null; // Hide the form
        } elseif ((time() - strtotime($lookupSale['created_at'])) > 86400) {
            $saleDate = date('M d, Y h:i A', strtotime($lookupSale['created_at']));
            $errors[] = "Returns are only allowed within <strong>1 day</strong> of purchase. This transaction was made on <strong>{$saleDate}</strong> and can no longer be returned.";
            $lookupSale = null; // Hide the form
        } else {
            $li = $pdo->prepare("
 SELECT wsi.id, wsi.product_id, wsi.unit, wsi.quantity, wsi.price,
 p.name AS product_name
 FROM walkin_sales_items wsi
 JOIN products p ON p.id = wsi.product_id
 WHERE wsi.sale_id = ?
 ");
            $li->execute([$lookupSale['id']]);
            $lookupItems = $li->fetchAll();
        }
    } else {
        $errors[] = "No transaction found for reference: <strong>" . htmlspecialchars($ref) . "</strong>";
    }
}

/* ============================================================
 PROCESS RETURN SUBMISSION
============================================================ */
if (isset($_POST['submit_return'])) {

    $orig_ref = trim($_POST['orig_ref'] ?? '');
    $sale_id_post = intval($_POST['sale_id'] ?? 0);
    $customer_name = trim($_POST['customer_name'] ?? '');
    $reason_type = trim($_POST['reason_type'] ?? '');
    $reason_detail = trim($_POST['reason_detail'] ?? '');
    $return_type = trim($_POST['return_type'] ?? '');

    $ret_product_ids = $_POST['ret_product_ids'] ?? [];
    $ret_units = $_POST['ret_units'] ?? [];
    $ret_quantities = $_POST['ret_quantities'] ?? [];
    $ret_prices = $_POST['ret_prices'] ?? [];
    $ret_names = $_POST['ret_names'] ?? [];

    $new_product_ids = $_POST['new_product_ids'] ?? [];
    $new_units = $_POST['new_units'] ?? [];
    $new_quantities = $_POST['new_quantities'] ?? [];
    $new_names = $_POST['new_names'] ?? [];

    // Filter out blank/unselected rows before validation
    $ret_product_ids_filtered = array_filter($ret_product_ids, fn($id) => intval($id) > 0);
    $new_product_ids_filtered = array_filter($new_product_ids, fn($id) => intval($id) > 0);

    // Validate
    if (!$orig_ref)
        $errors[] = "Original transaction reference is required.";
    if (!$reason_type)
        $errors[] = "Please select a return reason.";
    if ($reason_type === 'Others' && !$reason_detail)
        $errors[] = "Please explain the reason in the details box.";
    if (!$return_type)
        $errors[] = "Please select return type (Exchange or Upgrade).";
    if (empty($ret_product_ids_filtered))
        $errors[] = "Please select at least one item to return.";
    if (empty($new_product_ids_filtered))
        $errors[] = "Please select at least one replacement/upgrade product.";

    // Enforce 1-day return window
    if (empty($errors) && $sale_id_post) {
        $dateCheck = $pdo->prepare("SELECT created_at FROM walkin_sales WHERE id = ? AND user_id = ?");
        $dateCheck->execute([$sale_id_post, $user_id]);
        $dateRow = $dateCheck->fetch();
        if ($dateRow && (time() - strtotime($dateRow['created_at'])) > 86400) {
            $saleDate = date('M d, Y h:i A', strtotime($dateRow['created_at']));
            $errors[] = "Returns are only allowed within <strong>1 day</strong> of purchase. This transaction was made on <strong>{$saleDate}</strong> and can no longer be returned.";
        }
    }

    // Validate returned quantities don't exceed original purchased qty
    if (empty($errors) && $sale_id_post) {
        $origItemsStmt = $pdo->prepare("SELECT product_id, quantity FROM walkin_sales_items WHERE sale_id = ?");
        $origItemsStmt->execute([$sale_id_post]);
        $origQtyMap = [];
        foreach ($origItemsStmt->fetchAll() as $oi) {
            $origQtyMap[$oi['product_id']] = (int) $oi['quantity'];
        }
        foreach ($ret_product_ids_filtered as $i => $pid) {
            $pid = intval($pid);
            $qty = intval($ret_quantities[$i] ?? 0);
            if (isset($origQtyMap[$pid]) && $qty > $origQtyMap[$pid]) {
                $errors[] = "Return quantity ($qty) exceeds the originally purchased quantity ({$origQtyMap[$pid]}) for that product.";
            }
        }
    }

    // Stock check for replacement items
    if (empty($errors)) {
        foreach ($new_product_ids_filtered as $i => $pid) {
            $pid = intval($pid);
            $qty = intval($new_quantities[$i] ?? 0);
            $unit = trim($new_units[$i] ?? '');
            if ($qty <= 0 || !$pid)
                continue;

            $sp = $pdo->prepare("SELECT box_qty, pcs_qty, bottle_qty, name, unit_type FROM products WHERE id=? AND user_id=?");
            $sp->execute([$pid, $user_id]);
            $sp_row = $sp->fetch();
            if (!$sp_row) {
                $errors[] = "Replacement product not found.";
                continue;
            }

            $avail = 0;
            if ($unit === 'BOX')
                $avail = $sp_row['box_qty'];
            elseif ($unit === 'PCS')
                $avail = ($sp_row['unit_type'] === 'BOX')
                    ? ($sp_row['pcs_qty'] + ($sp_row['box_qty'] * 10))
                    : $sp_row['pcs_qty'];
            elseif ($unit === 'BOTTLE')
                $avail = $sp_row['bottle_qty'];

            if ($qty > $avail) {
                $errors[] = htmlspecialchars($sp_row['name']) . " only has {$avail} {$unit} in stock for replacement.";
            }
        }
    }

    // Block if this original_ref was already returned
    if (empty($errors) && $orig_ref) {
        $dupCheck = $pdo->prepare("SELECT return_ref FROM product_returns WHERE original_ref = ? AND user_id = ? AND status = 'COMPLETED' LIMIT 1");
        $dupCheck->execute([$orig_ref, $user_id]);
        $dupRow = $dupCheck->fetch();
        if ($dupRow) {
            $errors[] = "This transaction (<strong>" . htmlspecialchars($orig_ref) . "</strong>) has already been returned. Reference: <strong>" . htmlspecialchars($dupRow['return_ref']) . "</strong>. The same sale cannot be returned twice.";
        }
    }

    // Determine if original sale was a member sale
    $isMemberSale = false;
    if ($sale_id_post) {
        $memberCheck = $pdo->prepare("SELECT m.id, m.status FROM walkin_sales ws LEFT JOIN members m ON ws.member_id = m.id WHERE ws.id = ? AND ws.user_id = ?");
        $memberCheck->execute([$sale_id_post, $user_id]);
        $memberRow = $memberCheck->fetch();
        $isMemberSale = !empty($memberRow['id']) && $memberRow['status'] === 'ACTIVE';
    }

    // Price check for both Exchange and Upgrade
    if (empty($errors)) {
        $retTotal = 0;
        foreach ($ret_product_ids_filtered as $i => $pid) {
            $pid = intval($pid);
            $qty = intval($ret_quantities[$i] ?? 0);
            $price = floatval($ret_prices[$i] ?? 0);
            if ($pid && $qty > 0) $retTotal += $price * $qty;
        }
        $newTotal = 0;
        foreach ($new_product_ids_filtered as $i => $pid) {
            $pid = intval($pid);
            $qty = intval($new_quantities[$i] ?? 0);
            $unit = trim($new_units[$i] ?? '');
            if (!$pid || $qty <= 0) continue;
            $pr = $pdo->prepare("SELECT member_price, srp_price, unit_type FROM products WHERE id=?");
            $pr->execute([$pid]);
            $pr_row = $pr->fetch();
            $price = $pr_row ? (float) ($isMemberSale ? $pr_row['member_price'] : $pr_row['srp_price']) : 0;
            if ($unit === 'PCS' && ($pr_row['unit_type'] ?? '') === 'BOX') $price = ceil($price / 10);
            $newTotal += $price * $qty;
        }

        if ($return_type === 'EXCHANGE' && $newTotal < $retTotal) {
            $errors[] = "For an Exchange, the replacement product(s) total (₱" . number_format($newTotal, 2) . ") must be equal to or higher than the returned product(s) total (₱" . number_format($retTotal, 2) . "). Please select a product of equal or higher value.";
        }
        if ($return_type === 'UPGRADE' && $newTotal <= $retTotal) {
            $errors[] = "For an Upgrade, the replacement product(s) total (₱" . number_format($newTotal, 2) . ") must be strictly higher than the returned product(s) total (₱" . number_format($retTotal, 2) . ").";
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Compute income (difference paid by customer)
            // UPGRADE: always has income (replacement is strictly higher)
            // EXCHANGE: income only when replacement is higher value than returned
            $upgradeIncome = 0;
            if ($return_type === 'UPGRADE') {
                $upgradeIncome = round($newTotal - $retTotal, 2);
            } elseif ($return_type === 'EXCHANGE' && $newTotal > $retTotal) {
                $upgradeIncome = round($newTotal - $retTotal, 2);
            }

            // Create return record
            $pdo->prepare("
 INSERT INTO product_returns
 (return_ref, original_ref, sale_id, user_id, customer_name, reason_type, reason_detail, return_type, upgrade_income, status)
 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETED')
 ")->execute([
                        'RET-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
                        $orig_ref,
                        $sale_id_post,
                        $user_id,
                        $customer_name,
                        $reason_type,
                        $reason_detail,
                        $return_type,
                        $upgradeIncome
                    ]);
            $return_id = $pdo->lastInsertId();

            // Update return_ref with ID-based one
            $ret_ref = 'RET-' . date('Ymd') . '-' . str_pad($return_id, 5, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE product_returns SET return_ref=?, completed_at=NOW() WHERE id=?")->execute([$ret_ref, $return_id]);

            // Save returned items & restore stock
            foreach ($ret_product_ids as $i => $pid) {
                $pid = intval($pid);
                $qty = intval($ret_quantities[$i] ?? 0);
                $unit = trim($ret_units[$i] ?? '');
                $price = floatval($ret_prices[$i] ?? 0);
                $pname = trim($ret_names[$i] ?? '');
                if ($qty <= 0 || !$pid)
                    continue;

                $pdo->prepare("
 INSERT INTO product_return_items (return_id, product_id, product_name, unit, quantity, price, item_type)
 VALUES (?, ?, ?, ?, ?, ?, 'RETURNED')
 ")->execute([$return_id, $pid, $pname, $unit, $qty, $price]);

                // Restore stock for returned items
                if ($unit === 'BOX') {
                    $pdo->prepare("UPDATE products SET box_qty = box_qty + ? WHERE id=? AND user_id=?")->execute([$qty, $pid, $user_id]);
                } elseif ($unit === 'PCS') {
                    $pdo->prepare("UPDATE products SET pcs_qty = pcs_qty + ? WHERE id=? AND user_id=?")->execute([$qty, $pid, $user_id]);
                } elseif ($unit === 'BOTTLE') {
                    $pdo->prepare("UPDATE products SET bottle_qty = bottle_qty + ? WHERE id=? AND user_id=?")->execute([$qty, $pid, $user_id]);
                }
            }

            // Save replacement items & deduct stock
            foreach ($new_product_ids as $i => $pid) {
                $pid = intval($pid);
                $qty = intval($new_quantities[$i] ?? 0);
                $unit = trim($new_units[$i] ?? '');
                $pname = trim($new_names[$i] ?? '');
                if ($qty <= 0 || !$pid)
                    continue;

                // Get price — use srp_price for non-member sales, member_price for member sales
                $priceStmt = $pdo->prepare("SELECT member_price, srp_price, unit_type FROM products WHERE id=?");
                $priceStmt->execute([$pid]);
                $pr = $priceStmt->fetch();
                $price = $pr ? (float) ($isMemberSale ? $pr['member_price'] : $pr['srp_price']) : 0;
                if ($unit === 'PCS' && ($pr['unit_type'] ?? '') === 'BOX')
                    $price = ceil($price / 10);

                $pdo->prepare("
 INSERT INTO product_return_items (return_id, product_id, product_name, unit, quantity, price, item_type)
 VALUES (?, ?, ?, ?, ?, ?, 'REPLACEMENT')
 ")->execute([$return_id, $pid, $pname, $unit, $qty, $price]);

                // Deduct stock for replacement
                if ($unit === 'BOX') {
                    $pdo->prepare("UPDATE products SET box_qty = GREATEST(0, box_qty - ?) WHERE id=? AND user_id=?")->execute([$qty, $pid, $user_id]);
                } elseif ($unit === 'PCS') {
                    // Deduct from pcs first, then convert boxes
                    $stkRow = $pdo->prepare("SELECT pcs_qty, box_qty FROM products WHERE id=?");
                    $stkRow->execute([$pid]);
                    $stk = $stkRow->fetch();
                    $pcs = (int) $stk['pcs_qty'];
                    $box = (int) $stk['box_qty'];
                    $pcs -= $qty;
                    while ($pcs < 0 && $box > 0) {
                        $box--;
                        $pcs += 10;
                    }
                    $pcs = max(0, $pcs);
                    $box = max(0, $box);
                    $pdo->prepare("UPDATE products SET pcs_qty=?, box_qty=? WHERE id=? AND user_id=?")->execute([$pcs, $box, $pid, $user_id]);
                } elseif ($unit === 'BOTTLE') {
                    $pdo->prepare("UPDATE products SET bottle_qty = GREATEST(0, bottle_qty - ?) WHERE id=? AND user_id=?")->execute([$qty, $pid, $user_id]);
                }
            }

            $pdo->commit();
            $success = $ret_ref;

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Error processing return: " . $e->getMessage();
        }
    }
}

/* ============================================================
 FETCH RETURN HISTORY
============================================================ */
$histStmt = $pdo->prepare("
 SELECT r.*,
 (SELECT GROUP_CONCAT(ri.product_name, ' ×', ri.quantity, ' ', ri.unit SEPARATOR ' | ')
 FROM product_return_items ri WHERE ri.return_id = r.id AND ri.item_type='RETURNED') AS returned_items,
 (SELECT GROUP_CONCAT(ri.product_name, ' ×', ri.quantity, ' ', ri.unit SEPARATOR ' | ')
 FROM product_return_items ri WHERE ri.return_id = r.id AND ri.item_type='REPLACEMENT') AS replacement_items
 FROM product_returns r
 WHERE r.user_id = ?
 ORDER BY r.returned_at DESC
 LIMIT 50
");
$histStmt->execute([$user_id]);
$returnHistory = $histStmt->fetchAll();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Product Returns — Fil-Rise IMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="7.png?v=3">
    <style>
        :root {
            --accent: #2563eb;
            --green: #16a34a;
            --red: #dc2626;
            --orange: #ea580c;
            --bg: #f1f5f9;
            --border: #e2e8f0;
            --text: #0f172a;
            --muted: #64748b;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            font-family: 'Poppins', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            color: var(--text);
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
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .sidebar a:hover {
            background: rgba(59, 130, 246, .2);
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
            background: rgba(121, 35, 35, .2);
            color: #cbd5e1;
            cursor: pointer;
            transition: .3s;
        }

        .logout:hover {
            background: rgba(239, 68, 68, .4);
        }

        /* PAGE */
        .page-wrap {
            margin-left: 260px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .page-header {
            background: #fff;
            border-bottom: 1px solid var(--border);
            padding: 14px 26px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h4 {
            margin: 0;
            font-weight: 700;
            font-size: 19px;
        }

        .page-header .sub {
            font-size: 12px;
            color: var(--muted);
        }

        /* TAB BAR */
        .tab-bar {
            background: #fff;
            border-bottom: 2px solid var(--border);
            display: flex;
            padding: 0 26px;
        }

        .tab-btn {
            padding: 12px 20px;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            border: none;
            background: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            transition: .15s;
            margin-bottom: -2px;
            font-family: 'Poppins', sans-serif;
        }

        .tab-btn.active {
            color: var(--accent);
            border-bottom-color: var(--accent);
        }

        /* CONTENT */
        .content {
            padding: 24px 26px;
            flex: 1;
        }

        /* CARD */
        .card-box {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .06);
            padding: 22px 24px;
            margin-bottom: 20px;
        }

        .card-box h6 {
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 8px;
            font-size: 13px;
        }

        .badge-blue {
            background: #dbeafe;
            color: #2563eb;
        }

        .badge-orange {
            background: #ffedd5;
            color: #ea580c;
        }

        .badge-green {
            background: #dcfce7;
            color: #16a34a;
        }

        /* SEARCH REF */
        .ref-search {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .ref-search input {
            flex: 1;
            padding: 10px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            outline: none;
            transition: .15s;
        }

        .ref-search input:focus {
            border-color: var(--accent);
        }

        .btn-primary-custom {
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            background: var(--accent);
            color: #fff;
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: .15s;
        }

        .btn-primary-custom:hover {
            background: #1d4ed8;
        }

        /* SALE INFO BOX */
        .sale-info {
            background: #f8fafc;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 16px;
        }

        .sale-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
        }

        .sinfo-label {
            font-size: 10px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .sinfo-val {
            font-size: 14px;
            font-weight: 700;
            margin-top: 2px;
        }

        /* ITEMS TABLE */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .items-table th {
            background: #f8fafc;
            font-size: 11px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .5px;
            padding: 9px 12px;
            border-bottom: 2px solid var(--border);
            text-align: left;
        }

        .items-table td {
            padding: 9px 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .items-table tr:last-child td {
            border-bottom: none;
        }

        /* REASON PILLS */
        .reason-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .reason-pill {
            padding: 7px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            border: 1.5px solid var(--border);
            background: #fff;
            color: var(--muted);
            cursor: pointer;
            transition: .15s;
            user-select: none;
        }

        .reason-pill:hover {
            border-color: var(--orange);
            color: var(--orange);
        }

        .reason-pill.selected {
            background: var(--orange);
            color: #fff;
            border-color: var(--orange);
        }

        .reason-pill input[type=radio] {
            display: none;
        }

        /* RETURN TYPE TOGGLE */
        .type-toggle {
            display: flex;
            gap: 12px;
        }

        .type-card {
            flex: 1;
            padding: 14px 16px;
            border-radius: 12px;
            border: 2px solid var(--border);
            cursor: pointer;
            transition: .2s;
            user-select: none;
            text-align: center;
        }

        .product-row select:disabled,
        .product-row select:disabled option {
            background: #f1f5f9;
            color: #64748b;
            cursor: not-allowed;
            border-color: #e2e8f0;
        }

        .unit-sel option:disabled {
            color: #cbd5e1;
        }

        .type-card:hover {
            border-color: #94a3b8;
        }

        .type-card.selected.exchange {
            border-color: #2563eb;
            background: #eff6ff;
        }

        .type-card.selected.upgrade {
            border-color: #16a34a;
            background: #f0fdf4;
        }

        .type-card input[type=radio] {
            display: none;
        }

        .type-card-icon {
            font-size: 24px;
            margin-bottom: 6px;
        }

        .type-card-title {
            font-size: 13px;
            font-weight: 700;
        }

        .type-card-desc {
            font-size: 11px;
            color: var(--muted);
            margin-top: 2px;
        }

        /* PRODUCT ROWS */
        .product-row {
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .product-row:last-child {
            border-bottom: none;
        }

        .product-row select {
            flex: 2;
            padding: 8px 10px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-family: 'Poppins', sans-serif;
            font-size: 12px;
            outline: none;
        }

        .product-row select:focus {
            border-color: var(--accent);
        }

        .product-row input[type=number] {
            width: 70px;
            padding: 8px 10px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-family: 'Poppins', sans-serif;
            font-size: 12px;
            outline: none;
            text-align: center;
        }

        .product-row input[type=number]:focus {
            border-color: var(--accent);
        }

        .unit-sel {
            width: 90px !important;
        }

        .rm-row-btn {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1.5px solid #fecaca;
            background: #fef2f2;
            color: var(--red);
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: .15s;
            flex-shrink: 0;
        }

        .rm-row-btn:hover {
            background: var(--red);
            color: #fff;
        }

        .add-row-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 8px;
            border: 1.5px dashed var(--border);
            background: transparent;
            color: var(--muted);
            font-family: 'Poppins', sans-serif;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
            transition: .15s;
        }

        .add-row-btn:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        /* PRICE DIFF */
        .price-diff-box {
            background: #f0fdf4;
            border: 1.5px solid #bbf7d0;
            border-radius: 10px;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .price-diff-box.negative {
            background: #fff7ed;
            border-color: #fed7aa;
        }

        .price-diff-label {
            font-size: 12px;
            color: var(--muted);
        }

        .price-diff-val {
            font-size: 18px;
            font-weight: 700;
        }

        /* SUBMIT BTN */
        .submit-return-btn {
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(90deg, #ea580c, #f97316);
            box-shadow: 0 4px 14px rgba(234, 88, 12, .3);
            transition: .2s;
            margin-top: 6px;
        }

        .submit-return-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(234, 88, 12, .4);
        }

        /* ALERTS */
        .err-bar {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 11px 16px;
            color: #b91c1c;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .suc-bar {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 13px 18px;
            color: #166534;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .suc-bar strong {
            font-size: 15px;
            display: block;
            margin-bottom: 4px;
        }

        /* HISTORY TABLE */
        .hist-table-wrap {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .05);
            overflow: hidden;
        }

        .hist-table-wrap table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .hist-table-wrap thead th {
            background: #f8fafc;
            font-size: 11px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .5px;
            padding: 11px 15px;
            border-bottom: 2px solid var(--border);
        }

        .hist-table-wrap tbody td {
            padding: 10px 15px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .hist-table-wrap tbody tr:last-child td {
            border-bottom: none;
        }

        .hist-table-wrap tbody tr:hover td {
            background: #f8fafc;
        }

        /* STATUS BADGE */
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .status-COMPLETED {
            background: #dcfce7;
            color: #166534;
        }

        .status-PENDING {
            background: #fff7ed;
            color: #9a3412;
        }

        .status-REJECTED {
            background: #fef2f2;
            color: #991b1b;
        }

        .type-EXCHANGE {
            background: #dbeafe;
            color: #1e40af;
        }

        .type-UPGRADE {
            background: #fef9c3;
            color: #854d0e;
        }

        /* TEXTAREA */
        .reason-textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            outline: none;
            resize: vertical;
            min-height: 80px;
            transition: .15s;
            margin-top: 10px;
        }

        .reason-textarea:focus {
            border-color: var(--orange);
        }

        .label-sm {
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 6px;
            display: block;
        }

        .price-tag {
            font-size: 12px;
            font-weight: 700;
            color: var(--accent);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
            font-size: 13px;
        }

        .empty-state-icon {
            font-size: 36px;
            opacity: .3;
            margin-bottom: 8px;
        }
    </style>
</head>

<body>
    <?php include 'ticker.php'; ?>

    <!-- SIDEBAR -->
    <div class="sidebar">
        <img src="7.png" class="logo" alt="Fil-Rise Logo">

        <div class="menu-title">Core</div>
        <a href="dashboard.php">Dashboard</a>
        <a href="ai_forecast.php"
            style="background:linear-gradient(90deg,rgba(6,182,212,.15),rgba(59,130,246,.15));color:#06b6d4;border:1px solid rgba(6,182,212,.25);">AI
            Dashboard</a>

        <div class="menu-title">Inventory</div>
        <a href="product.php">Products</a>
        <a href="removed_products.php">Archived Products</a>

        <div class="menu-title">Operations</div>
        <a href="transaction.php">Consignment Transactions</a>
        <a href="sales.php">Walk-in Sales</a>
        <a href="returns.php" class="active">Product Returns</a>
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
                <h4>Product Returns</h4>
                <div class="sub">Exchange or upgrade returned products — <?= date('F d, Y') ?></div>
            </div>
            <div style="font-size:13px;color:var(--muted);"><?= count($returnHistory) ?> return(s) recorded</div>
        </div>

        <!-- TAB BAR -->
        <div class="tab-bar">
            <button class="tab-btn <?= !isset($_GET['tab']) || $_GET['tab'] === 'new' ? 'active' : '' ?>"
                onclick="switchTab('new')" id="tab-new">New Return</button>
            <button class="tab-btn <?= (isset($_GET['tab']) && $_GET['tab'] === 'history') ? 'active' : '' ?>"
                onclick="switchTab('history')" id="tab-history">Return History</button>
        </div>

        <!-- ============ NEW RETURN TAB ============ -->
        <div id="view-new" class="content"
            style="<?= (isset($_GET['tab']) && $_GET['tab'] === 'history') ? 'display:none' : '' ?>">

            <?php if (!empty($errors)): ?>
                <div class="err-bar"> <?= implode('<br> ', $errors) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="suc-bar">
                    <strong>Return processed successfully!</strong>
                    Return Reference: <strong><?= htmlspecialchars($success) ?></strong> — Stock has been updated.
                    <div style="margin-top:8px;">
                        <a href="returns.php?tab=history"
                            style="color:#166534;font-weight:700;text-decoration:underline;">View Return History →</a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- STEP 1: LOOK UP TRANSACTION -->
            <div class="card-box">
                <h6><span class="section-badge badge-blue">1</span> Look Up Original Transaction</h6>
                <form method="GET" action="returns.php">
                    <input type="hidden" name="tab" value="new">
                    <div class="ref-search">
                        <input type="text" name="lookup_ref" value="<?= htmlspecialchars($_GET['lookup_ref'] ?? '') ?>"
                            placeholder="Enter transaction reference (e.g. FR-20250323-00012)" style="font-size:13px;">
                        <button type="submit" class="btn-primary-custom"> Look Up</button>
                        <?php if ($lookupSale): ?>
                            <a href="returns.php"
                                style="padding:10px 16px;border-radius:10px;border:1.5px solid var(--border);font-size:13px;font-weight:600;color:var(--muted);text-decoration:none;">
                                Clear</a>
                        <?php endif; ?>
                    </div>
                    <p style="font-size:11px;color:var(--muted);margin-top:7px;">
                        You can find the reference number on the customer's receipt (format: FR-YYYYMMDD-XXXXX)
                    </p>
                </form>

                <?php if ($lookupSale): ?>
                    <div style="margin-top:16px;">
                        <div class="sale-info">
                            <div class="sale-info-grid">
                                <div>
                                    <div class="sinfo-label">Reference #</div>
                                    <div class="sinfo-val"><?= htmlspecialchars($lookupSale['reference_no']) ?></div>
                                </div>
                                <div>
                                    <div class="sinfo-label">Date</div>
                                    <div class="sinfo-val"><?= date('M d, Y h:i A', strtotime($lookupSale['created_at'])) ?>
                                    </div>
                                </div>
                                <div>
                                    <div class="sinfo-label">Customer</div>
                                    <div class="sinfo-val"><?= htmlspecialchars($lookupSale['full_name'] ?? 'Guest') ?>
                                    </div>
                                </div>
                                <div>
                                    <div class="sinfo-label">Total Amount</div>
                                    <div class="sinfo-val" style="color:var(--green);">
                                        ₱<?= number_format($lookupSale['total_amount'], 2) ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Original Items -->
                        <p class="label-sm" style="margin-top:12px;">Items in this transaction:</p>
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Unit</th>
                                    <th>Qty</th>
                                    <th>Price</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lookupItems as $li): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($li['product_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($li['unit']) ?></td>
                                        <td><?= $li['quantity'] ?></td>
                                        <td>₱<?= number_format($li['price'], 2) ?></td>
                                        <td>₱<?= number_format($li['quantity'] * $li['price'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif (isset($_GET['lookup_ref']) && !$lookupSale && empty($errors)): ?>
                    <div style="margin-top:12px;color:var(--red);font-size:13px;"> Transaction not found.</div>
                <?php endif; ?>
            </div>

            <!-- RETURN FORM (shown only when sale found or manual entry) -->
            <form method="POST" action="returns.php" id="returnForm">
                <?php if ($lookupSale): ?>
                    <input type="hidden" name="orig_ref" value="<?= htmlspecialchars($lookupSale['reference_no']) ?>">
                    <input type="hidden" name="sale_id" value="<?= $lookupSale['id'] ?>">
                    <input type="hidden" name="customer_name"
                        value="<?= htmlspecialchars($lookupSale['full_name'] ?? '') ?>">
                <?php else: ?>
                    <!-- Manual entry if no lookup -->
                    <div class="card-box">
                        <h6><span class="section-badge badge-blue"></span> Or Fill In Manually</h6>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div>
                                <label class="label-sm">Original Receipt / Reference No.</label>
                                <input type="text" name="orig_ref" placeholder="e.g. FR-20250323-00012" required
                                    style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;font-family:'Poppins',sans-serif;font-size:13px;outline:none;">
                            </div>
                            <div>
                                <label class="label-sm">Customer Name</label>
                                <input type="text" name="customer_name" placeholder="Customer name (optional)"
                                    style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;font-family:'Poppins',sans-serif;font-size:13px;outline:none;">
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- STEP 2: REASON -->
                <div class="card-box">
                    <h6><span class="section-badge badge-orange">2</span> Return Reason</h6>
                    <p class="label-sm">Select a reason:</p>
                    <div class="reason-pills">
                        <?php
                        $reasons = [
                            'Wrong Product' => '',
                            'Expired Product' => '',
                            'Defective/Damaged' => '',
                            'Wrong Quantity' => '',
                            'Customer Changed Mind' => '',
                            'Others' => '',
                        ];
                        foreach ($reasons as $reason => $icon): ?>
                            <label class="reason-pill" id="pill-<?= preg_replace('/\W/', '', $reason) ?>">
                                <input type="radio" name="reason_type" value="<?= htmlspecialchars($reason) ?>"
                                    <?= (isset($_POST['reason_type']) && $_POST['reason_type'] === $reason) ? 'checked' : '' ?>
                                    onchange="handleReasonChange(this)">
                                <?= $icon ?>     <?= $reason ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- Others text area -->
                    <div id="othersDetail"
                        style="<?= (isset($_POST['reason_type']) && $_POST['reason_type'] === 'Others') ? '' : 'display:none' ?>">
                        <textarea name="reason_detail" class="reason-textarea"
                            placeholder="Please explain the reason for return..."><?= htmlspecialchars($_POST['reason_detail'] ?? '') ?></textarea>
                    </div>
                    <div id="otherDetailNote"
                        style="<?= (isset($_POST['reason_type']) && $_POST['reason_type'] !== 'Others' && isset($_POST['reason_type'])) ? '' : 'display:none' ?>">
                        <textarea name="reason_detail" class="reason-textarea"
                            placeholder="Additional notes (optional)..."><?= htmlspecialchars($_POST['reason_detail'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- STEP 3: RETURN TYPE -->
                <div class="card-box">
                    <h6><span class="section-badge badge-orange">3</span> Return Type</h6>
                    <div class="type-toggle">
                        <label
                            class="type-card exchange <?= (isset($_POST['return_type']) && $_POST['return_type'] === 'EXCHANGE') ? 'selected' : '' ?>"
                            id="card-exchange">
                            <input type="radio" name="return_type" value="EXCHANGE" <?= (isset($_POST['return_type']) && $_POST['return_type'] === 'EXCHANGE') ? 'checked' : '' ?>
                                onchange="handleTypeChange(this)">
                            <div class="type-card-icon"></div>
                            <div class="type-card-title">Exchange</div>
                            <div class="type-card-desc">Swap for the same or different product of equal value</div>
                        </label>
                        <label
                            class="type-card upgrade <?= (isset($_POST['return_type']) && $_POST['return_type'] === 'UPGRADE') ? 'selected' : '' ?>"
                            id="card-upgrade">
                            <input type="radio" name="return_type" value="UPGRADE" <?= (isset($_POST['return_type']) && $_POST['return_type'] === 'UPGRADE') ? 'checked' : '' ?>
                                onchange="handleTypeChange(this)">
                            <div class="type-card-icon"></div>
                            <div class="type-card-title">Upgrade</div>
                            <div class="type-card-desc">Customer pays the difference for a higher-priced product</div>
                        </label>
                    </div>
                </div>

                <!-- STEP 4: RETURNED ITEMS -->
                <div class="card-box">
                    <h6><span class="section-badge badge-orange">4</span> Items Being Returned</h6>
                    <p style="font-size:12px;color:var(--muted);margin-bottom:12px;">Select the products the customer is
                        returning (stock will be restored).</p>
                    <div id="returned-items-wrap">
                        <?php
                        // Pre-populate from lookup or from POST errors
                        $preItems = [];
                        if ($lookupSale && !isset($_POST['submit_return'])) {
                            foreach ($lookupItems as $li) {
                                $preItems[] = ['pid' => $li['product_id'], 'name' => $li['product_name'], 'unit' => $li['unit'], 'qty' => $li['quantity'], 'price' => $li['price']];
                            }
                        } elseif (isset($_POST['ret_product_ids'])) {
                            foreach ($_POST['ret_product_ids'] as $i => $pid) {
                                $preItems[] = ['pid' => $pid, 'name' => $_POST['ret_names'][$i] ?? '', 'unit' => $_POST['ret_units'][$i] ?? '', 'qty' => $_POST['ret_quantities'][$i] ?? 1, 'price' => $_POST['ret_prices'][$i] ?? 0];
                            }
                        } else {
                            $preItems[] = ['pid' => '', 'name' => '', 'unit' => '', 'qty' => 1, 'price' => 0];
                        }
                        foreach ($preItems as $idx => $pi):
                            // Find original purchased qty for this product to use as max
                            $origQty = $pi['qty']; // this IS the original purchased qty
                        ?>
                            <div class="product-row" id="ret-row-<?= $idx ?>">
                                <select name="ret_product_ids[]" style="flex:3;" disabled>
                                    <option value="">— Select product —</option>
                                    <?php foreach ($products as $p): ?>
                                        <option value="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>"
                                            data-unit="<?= $p['unit_type'] ?>" data-price="<?= $p['member_price'] ?>"
                                            <?= ($pi['pid'] == $p['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($p['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <!-- Hidden real input since disabled fields don't submit -->
                                <input type="hidden" name="ret_product_ids[]" value="<?= htmlspecialchars($pi['pid']) ?>">
                                <input type="hidden" name="ret_names[]" id="ret-name-<?= $idx ?>"
                                    value="<?= htmlspecialchars($pi['name']) ?>">
                                <select name="ret_units[]" class="unit-sel" id="ret-unit-<?= $idx ?>" disabled>
                                    <option value="BOX" <?= $pi['unit'] === 'BOX' ? 'selected' : '' ?>>BOX</option>
                                    <option value="PCS" <?= $pi['unit'] === 'PCS' ? 'selected' : '' ?>>PCS</option>
                                    <option value="BOTTLE" <?= $pi['unit'] === 'BOTTLE' ? 'selected' : '' ?>>BOTTLE</option>
                                </select>
                                <!-- Hidden real unit since disabled fields don't submit -->
                                <input type="hidden" name="ret_units[]" value="<?= htmlspecialchars($pi['unit']) ?>">
                                <input type="number" name="ret_quantities[]" value="<?= (int) $pi['qty'] ?>" min="1"
                                    max="<?= (int) $origQty ?>"
                                    placeholder="Qty" id="ret-qty-<?= $idx ?>" oninput="clampRetQty(this)"
                                    title="Max returnable: <?= (int) $origQty ?>">
                                <input type="hidden" name="ret_prices[]" id="ret-price-<?= $idx ?>"
                                    value="<?= (float) $pi['price'] ?>">
                                <span class="price-tag"
                                    id="ret-ptag-<?= $idx ?>">₱<?= number_format((float) $pi['price'], 2) ?></span>
                                <span style="font-size:11px;color:var(--muted);white-space:nowrap;">max: <?= (int) $origQty ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p style="font-size:11px;color:var(--muted);margin-top:8px;">⚠ Items and units are locked to the original transaction. You can only adjust the quantity (up to what was originally purchased).</p>
                </div>

                <!-- STEP 5: REPLACEMENT ITEMS -->
                <div class="card-box">
                    <h6><span class="section-badge badge-green">5</span> Replacement / Upgrade Products</h6>
                    <p style="font-size:12px;color:var(--muted);margin-bottom:12px;">Select the product(s) the customer
                        will receive (stock will be deducted).</p>
                    <div id="new-items-wrap">
                        <?php
                        $newItems = [];
                        if (isset($_POST['new_product_ids'])) {
                            foreach ($_POST['new_product_ids'] as $i => $pid) {
                                $newItems[] = ['pid' => $pid, 'name' => $_POST['new_names'][$i] ?? '', 'unit' => $_POST['new_units'][$i] ?? '', 'qty' => $_POST['new_quantities'][$i] ?? 1];
                            }
                        } else {
                            $newItems[] = ['pid' => '', 'name' => '', 'unit' => '', 'qty' => 1];
                        }
                        foreach ($newItems as $idx => $ni): ?>
                            <div class="product-row" id="new-row-<?= $idx ?>">
                                <select name="new_product_ids[]" onchange="fillNewRow(this, <?= $idx ?>)" style="flex:3;">
                                    <option value="">— Select replacement product —</option>
                                    <?php foreach ($products as $p):
                                        $box = (int)$p['box_qty'];
                                        $pcs = (int)$p['pcs_qty'];
                                        $bottle = (int)$p['bottle_qty'];
                                        $availBox    = ($p['unit_type'] === 'BOX')    ? $box : 0;
                                        $availPcs    = ($p['unit_type'] === 'BOX')    ? ($pcs + $box * 10) : $pcs;
                                        $availBottle = ($p['unit_type'] === 'BOTTLE') ? $bottle : 0;
                                    ?>
                                        <option value="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>"
                                            data-unit="<?= $p['unit_type'] ?>" data-member="<?= $p['member_price'] ?>"
                                            data-srp="<?= $p['srp_price'] ?>"
                                            data-avail-box="<?= $availBox ?>"
                                            data-avail-pcs="<?= $availPcs ?>"
                                            data-avail-bottle="<?= $availBottle ?>"
                                            <?= ($ni['pid'] == $p['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($p['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="new_names[]" id="new-name-<?= $idx ?>"
                                    value="<?= htmlspecialchars($ni['name']) ?>">
                                <select name="new_units[]" class="unit-sel" id="new-unit-<?= $idx ?>">
                                    <option value="BOX" <?= ($ni['unit'] === 'BOX') ? 'selected' : '' ?>>BOX</option>
                                    <option value="PCS" <?= ($ni['unit'] === 'PCS') ? 'selected' : '' ?>>PCS</option>
                                    <option value="BOTTLE" <?= ($ni['unit'] === 'BOTTLE') ? 'selected' : '' ?>>BOTTLE</option>
                                </select>
                                <input type="number" name="new_quantities[]" value="<?= (int) $ni['qty'] ?>" min="1"
                                    placeholder="Qty" id="new-qty-<?= $idx ?>">
                                <span class="price-tag" id="new-ptag-<?= $idx ?>">—</span>
                                <button type="button" class="rm-row-btn"
                                    onclick="removeRow('new-row-<?= $idx ?>')">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="add-row-btn" onclick="addNewRow()"> Add another replacement</button>
                </div>

                <!-- STEP 6: PRICE DIFFERENCE SUMMARY -->
                <div class="card-box">
                    <h6><span class="section-badge badge-green">6</span> Price Summary</h6>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
                        <div style="background:#fef2f2;border-radius:10px;padding:12px 16px;">
                            <div
                                style="font-size:11px;color:#b91c1c;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">
                                Returned Value</div>
                            <div style="font-size:20px;font-weight:800;color:#b91c1c;margin-top:4px;"
                                id="total-returned">₱0.00</div>
                        </div>
                        <div style="background:#eff6ff;border-radius:10px;padding:12px 16px;">
                            <div
                                style="font-size:11px;color:#1e40af;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">
                                Replacement Value</div>
                            <div style="font-size:20px;font-weight:800;color:#1e40af;margin-top:4px;"
                                id="total-replacement">₱0.00</div>
                        </div>
                        <div id="diff-box" style="background:#f0fdf4;border-radius:10px;padding:12px 16px;">
                            <div style="font-size:11px;color:#166534;font-weight:700;text-transform:uppercase;letter-spacing:.5px;"
                                id="diff-label">Balance</div>
                            <div style="font-size:20px;font-weight:800;color:#166534;margin-top:4px;" id="total-diff">
                                ₱0.00</div>
                        </div>
                    </div>
                    <div id="diff-note" style="font-size:12px;color:var(--muted);"></div>
                    <button type="submit" name="submit_return" class="submit-return-btn">
                        Process Return
                    </button>
                </div>

            </form>
        </div>

        <!-- ============ HISTORY TAB ============ -->
        <div id="view-history" class="content"
            style="<?= (!isset($_GET['tab']) || $_GET['tab'] !== 'history') ? 'display:none' : '' ?>">

            <div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
                <h6 style="margin:0;font-weight:700;">Return Records</h6>
            </div>

            <?php if (empty($returnHistory)): ?>
                <div class="hist-table-wrap">
                    <div class="empty-state">
                        <div class="empty-state-icon"></div>
                        <div>No returns recorded yet.</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="hist-table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Return Ref</th>
                                <th>Original Ref</th>
                                <th>Customer</th>
                                <th>Reason</th>
                                <th>Type</th>
                                <th>Returned Items</th>
                                <th>Replacement Items</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($returnHistory as $rh): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($rh['return_ref']) ?></strong></td>
                                    <td style="color:var(--muted);"><?= htmlspecialchars($rh['original_ref']) ?></td>
                                    <td><?= htmlspecialchars($rh['customer_name'] ?: '—') ?></td>
                                    <td>
                                        <?= htmlspecialchars($rh['reason_type']) ?>
                                        <?php if ($rh['reason_detail']): ?>
                                            <br><small
                                                style="color:var(--muted);"><?= htmlspecialchars(mb_substr($rh['reason_detail'], 0, 60)) ?>...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><span
                                            class="status-badge type-<?= $rh['return_type'] ?>"><?= $rh['return_type'] ?></span>
                                    </td>
                                    <td style="font-size:12px;"><?= htmlspecialchars($rh['returned_items'] ?? '—') ?></td>
                                    <td style="font-size:12px;"><?= htmlspecialchars($rh['replacement_items'] ?? '—') ?></td>
                                    <td style="font-size:12px;color:var(--muted);">
                                        <?= date('M d, Y h:i A', strtotime($rh['returned_at'])) ?></td>
                                    <td><span class="status-badge status-<?= $rh['status'] ?>"><?= $rh['status'] ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
        // PRODUCTS DATA 
        const PRODUCTS = <?= json_encode($productsJson, JSON_UNESCAPED_UNICODE) ?>;
        const IS_MEMBER_SALE = <?= json_encode(!empty($lookupSale['member_id']) && $lookupSale['member_status'] === 'ACTIVE') ?>;

        // TAB SWITCHING 
        function switchTab(tab) {
            document.getElementById('view-new').style.display = (tab === 'new') ? '' : 'none';
            document.getElementById('view-history').style.display = (tab === 'history') ? '' : 'none';
            document.getElementById('tab-new').classList.toggle('active', tab === 'new');
            document.getElementById('tab-history').classList.toggle('active', tab === 'history');
        }

        // REASON PILL SELECTION 
        function handleReasonChange(radio) {
            document.querySelectorAll('.reason-pill').forEach(el => el.classList.remove('selected'));
            radio.closest('.reason-pill').classList.add('selected');
            const isOthers = radio.value === 'Others';
            document.getElementById('othersDetail').style.display = isOthers ? '' : 'none';
            document.getElementById('otherDetailNote').style.display = isOthers ? 'none' : '';
        }
        // Init pills on page load
        document.querySelectorAll('input[name="reason_type"]:checked').forEach(r => {
            r.closest('.reason-pill').classList.add('selected');
            handleReasonChange(r);
        });

        // RETURN TYPE CARD 
        function handleTypeChange(radio) {
            document.getElementById('card-exchange').classList.remove('selected');
            document.getElementById('card-upgrade').classList.remove('selected');
            document.getElementById('card-' + radio.value.toLowerCase()).classList.add('selected');

            if (radio.value === 'EXCHANGE') {
                // Auto-fill replacement rows with the same product(s) being returned
                const retRows = document.querySelectorAll('#returned-items-wrap .product-row');
                const newWrap = document.getElementById('new-items-wrap');
                // Clear existing replacement rows
                newWrap.innerHTML = '';
                newRowCount = 0;
                retRows.forEach((row, idx) => {
                    const retSel = row.querySelector('select[name="ret_product_ids[]"]');
                    const retUnit = row.querySelector('select[name="ret_units[]"]');
                    const retQty = row.querySelector('input[name="ret_quantities[]"]');
                    const selPid = retSel ? retSel.value : '';
                    const selUnit = retUnit ? retUnit.value : 'BOX';
                    const selQty = retQty ? retQty.value : 1;
                    if (!selPid) return;
                    const prod = PRODUCTS.find(p => p.id == selPid);
                    if (!prod) return;
                    const opts = buildNewOpts();
                    const rowIdx = newRowCount++;
                    const div = document.createElement('div');
                    div.className = 'product-row'; div.id = 'new-row-' + rowIdx;
                    div.innerHTML = `
                        <select name="new_product_ids[]" onchange="fillNewRow(this,${rowIdx})" style="flex:3;">
                            <option value="">— Select replacement product —</option>${opts}
                        </select>
                        <input type="hidden" name="new_names[]" id="new-name-${rowIdx}" value="${escHtml(prod.name)}">
                        <select name="new_units[]" class="unit-sel" id="new-unit-${rowIdx}" onchange="onNewUnitChange(this,${rowIdx})">
                            <option value="BOX" ${selUnit==='BOX'?'selected':''}>BOX</option>
                            <option value="PCS" ${selUnit==='PCS'?'selected':''}>PCS</option>
                            <option value="BOTTLE" ${selUnit==='BOTTLE'?'selected':''}>BOTTLE</option>
                        </select>
                        <input type="number" name="new_quantities[]" value="${parseInt(selQty)||1}" min="1" placeholder="Qty" id="new-qty-${rowIdx}" oninput="updateTotals()">
                        <span class="price-tag" id="new-ptag-${rowIdx}">—</span>
                        <button type="button" class="rm-row-btn" onclick="removeRow('new-row-${rowIdx}')">×</button>
                    `;
                    newWrap.appendChild(div);
                    // Set selected product and trigger price update
                    const newSel = div.querySelector('select[name="new_product_ids[]"]');
                    newSel.value = selPid;
                    const opt = newSel.options[newSel.selectedIndex];
                    updateNewPrice(rowIdx, opt, selUnit);
                });
                if (newRowCount === 0) {
                    // fallback: add one blank row
                    addNewRow();
                }
            }
        }
        document.querySelectorAll('input[name="return_type"]:checked').forEach(r => handleTypeChange(r));

        // Clamp returned qty to its max (original purchased qty)
        function clampRetQty(input) {
            const max = parseInt(input.max) || 1;
            const min = parseInt(input.min) || 1;
            if (parseInt(input.value) > max) input.value = max;
            if (parseInt(input.value) < min) input.value = min;
            updateTotals();
        }

        // RETURNED ITEMS ROWS (locked — no add/remove, JS only for totals)
        let retRowCount = <?= count($preItems) ?>;

        function buildNewOpts() {
            return PRODUCTS.map(p => {
                const avail = p.unit_type === 'BOX' ? p.avail_box
                            : p.unit_type === 'PCS' ? p.avail_pcs
                            : p.avail_bottle;
                return `<option value="${p.id}" data-name="${escHtml(p.name)}" data-unit="${p.unit_type}" data-member="${p.member_price}" data-srp="${p.srp_price}" data-avail-box="${p.avail_box}" data-avail-pcs="${p.avail_pcs}" data-avail-bottle="${p.avail_bottle}">${escHtml(p.name)}</option>`;
            }).join('');
        }

        // NEW/REPLACEMENT ROWS 
        let newRowCount = <?= count($newItems) ?>;

        function fillNewRow(sel, idx) {
            const opt = sel.options[sel.selectedIndex];
            document.getElementById('new-name-' + idx).value = opt.dataset.name || '';
            const unitType = opt.dataset.unit || '';  // product's unit_type
            const unitSel = document.getElementById('new-unit-' + idx);

            // Lock unit dropdown to product's unit_type only
            if (unitType) {
                Array.from(unitSel.options).forEach(o => {
                    o.disabled = (o.value !== unitType);
                    o.selected = (o.value === unitType);
                });
                unitSel.value = unitType;
            } else {
                // No product selected — re-enable all options
                Array.from(unitSel.options).forEach(o => { o.disabled = false; });
            }
            updateNewPrice(idx, opt, unitSel.value);
        }

        function updateNewPrice(idx, opt, unit) {
            if (!opt || !opt.dataset.member) {
                document.getElementById('new-ptag-' + idx).textContent = '—';
                updateTotals();
                return;
            }
            let price = IS_MEMBER_SALE ? parseFloat(opt.dataset.member) : parseFloat(opt.dataset.srp);
            if (unit === 'PCS' && opt.dataset.unit === 'BOX') price = Math.ceil(price / 10);

            // Show available stock for selected unit
            let avail = 0;
            if (unit === 'BOX') avail = parseInt(opt.dataset.availBox || 0);
            else if (unit === 'PCS') avail = parseInt(opt.dataset.availPcs || 0);
            else if (unit === 'BOTTLE') avail = parseInt(opt.dataset.availBottle || 0);

            document.getElementById('new-ptag-' + idx).textContent = '₱' + price.toFixed(2) + ' (stock: ' + avail + ')';
            updateTotals();
        }

        function addNewRow() {
            const wrap = document.getElementById('new-items-wrap');
            const idx = newRowCount++;
            const opts = buildNewOpts();
            const div = document.createElement('div');
            div.className = 'product-row'; div.id = 'new-row-' + idx;
            div.innerHTML = `
                <select name="new_product_ids[]" onchange="fillNewRow(this,${idx})" style="flex:3;">
                    <option value="">— Select replacement product —</option>${opts}
                </select>
                <input type="hidden" name="new_names[]" id="new-name-${idx}">
                <select name="new_units[]" class="unit-sel" id="new-unit-${idx}" onchange="onNewUnitChange(this,${idx})">
                    <option value="BOX">BOX</option><option value="PCS">PCS</option><option value="BOTTLE">BOTTLE</option>
                </select>
                <input type="number" name="new_quantities[]" value="1" min="1" placeholder="Qty" id="new-qty-${idx}" oninput="updateTotals()">
                <span class="price-tag" id="new-ptag-${idx}">—</span>
                <button type="button" class="rm-row-btn" onclick="removeRow('new-row-${idx}')">×</button>
            `;
            wrap.appendChild(div);
        }

        function onNewUnitChange(sel, idx) {
            const prodSel = sel.closest('.product-row').querySelector('select[name="new_product_ids[]"]');
            const opt = prodSel.options[prodSel.selectedIndex];
            updateNewPrice(idx, opt, sel.value);
        }

        // REMOVE ROW 
        function removeRow(id) {
            const el = document.getElementById(id);
            if (el) el.remove();
            updateTotals();
        }

        // TOTALS 
        function updateTotals() {
            let retTotal = 0, newTotal = 0;

            document.querySelectorAll('#returned-items-wrap .product-row').forEach(row => {
                const price = parseFloat(row.querySelector('input[name="ret_prices[]"]')?.value || 0);
                const qty = parseInt(row.querySelector('input[name="ret_quantities[]"]')?.value || 0);
                retTotal += price * qty;
            });

            document.querySelectorAll('#new-items-wrap .product-row').forEach(row => {
                const tag = row.querySelector('[id^="new-ptag-"]');
                const qty = parseInt(row.querySelector('input[name="new_quantities[]"]')?.value || 0);
                if (tag && tag.textContent.startsWith('₱')) {
                    newTotal += parseFloat(tag.textContent.replace('₱', '').replace(/,/g, '')) * qty;
                }
            });

            const diff = newTotal - retTotal;
            document.getElementById('total-returned').textContent = '₱' + retTotal.toFixed(2);
            document.getElementById('total-replacement').textContent = '₱' + newTotal.toFixed(2);
            document.getElementById('total-diff').textContent = '₱' + Math.abs(diff).toFixed(2);

            const diffBox = document.getElementById('diff-box');
            const diffLabel = document.getElementById('diff-label');
            const diffNote = document.getElementById('diff-note');

            if (diff > 0) {
                diffBox.style.background = '#fff7ed';
                diffBox.style.borderRadius = '10px'; diffBox.style.padding = '12px 16px';
                document.getElementById('total-diff').style.color = '#ea580c';
                diffLabel.style.color = '#c2410c'; diffLabel.textContent = 'Customer Pays';
                diffNote.textContent = ' Upgrade: Customer needs to pay ₱' + diff.toFixed(2) + ' extra.';
            } else if (diff < 0) {
                diffBox.style.background = '#fef2f2';
                document.getElementById('total-diff').style.color = '#b91c1c';
                diffLabel.style.color = '#b91c1c'; diffLabel.textContent = 'Refund / Store Credit';
                diffNote.textContent = ' Exchange: Give customer ₱' + Math.abs(diff).toFixed(2) + ' store credit or change.';
            } else {
                diffBox.style.background = '#f0fdf4';
                document.getElementById('total-diff').style.color = '#166534';
                diffLabel.style.color = '#166534'; diffLabel.textContent = 'Balance';
                diffNote.textContent = ' Even exchange — no extra payment needed.';
            }
        }

        function escHtml(str) {
            return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // Init totals
        updateTotals();

        // Client-side price enforcement on submit (Exchange >= , Upgrade >)
        document.getElementById('returnForm').addEventListener('submit', function(e) {
            const returnType = document.querySelector('input[name="return_type"]:checked');
            if (!returnType) return;

            let retTotal = 0;
            document.querySelectorAll('#returned-items-wrap .product-row').forEach(row => {
                const price = parseFloat(row.querySelector('input[name="ret_prices[]"]')?.value || 0);
                const qty = parseInt(row.querySelector('input[name="ret_quantities[]"]')?.value || 0);
                retTotal += price * qty;
            });

            let newTotal = 0;
            document.querySelectorAll('#new-items-wrap .product-row').forEach(row => {
                const tag = row.querySelector('[id^="new-ptag-"]');
                const qty = parseInt(row.querySelector('input[name="new_quantities[]"]')?.value || 0);
                if (tag && tag.textContent.startsWith('₱')) {
                    const priceStr = tag.textContent.split('(')[0].replace('₱','').replace(/,/g,'').trim();
                    newTotal += parseFloat(priceStr || 0) * qty;
                }
            });

            if (returnType.value === 'EXCHANGE' && newTotal < retTotal) {
                e.preventDefault();
                alert('Exchange Error: The replacement product(s) total (₱' + newTotal.toFixed(2) + ') must be equal to or higher than the returned product(s) total (₱' + retTotal.toFixed(2) + ').\n\nPlease select a product of equal or higher value.');
            } else if (returnType.value === 'UPGRADE' && newTotal <= retTotal) {
                e.preventDefault();
                alert('Upgrade Error: The replacement product(s) total (₱' + newTotal.toFixed(2) + ') must be strictly higher than the returned product(s) total (₱' + retTotal.toFixed(2) + ').\n\nPlease select a more expensive replacement.');
            }
        });

        // Listen to qty changes on pre-filled rows
        document.querySelectorAll('input[name="ret_quantities[]"], input[name="new_quantities[]"]').forEach(el => {
            el.addEventListener('input', updateTotals);
        });
        document.querySelectorAll('select[name="new_units[]"]').forEach((sel, idx) => {
            sel.addEventListener('change', () => {
                const prodSel = sel.closest('.product-row').querySelector('select[name="new_product_ids[]"]');
                const opt = prodSel.options[prodSel.selectedIndex];
                updateNewPrice(idx, opt, sel.value);
            });
        });
    </script>
</body>

</html>
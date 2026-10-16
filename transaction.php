<?php
/**
 * transaction.php — Consignment Transactions (Distributor Sales)
 *
 * Manages consignment-based sales where products are sold through
 * distributors. Features:
 *   - Record new distributor sales (product, quantity, unit type)
 *   - Auto-deduct stock using stock_manager.php
 *   - View/filter transaction history by date range
 *   - Print receipts for individual transactions
 *   - Delete transactions with stock restoration
 *   - Activity logging for all operations
 *
 * This page is module-gated via branch_modules (transactions).
 */
require 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* -- Authentication guard -- */
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
require_once 'branch_modules.php';
fr_require_module($pdo, 'transactions');
require_once 'activity_log.php';
$errors = [];
$success = "";

// Fetch distributors
$distStmt = $pdo->prepare("SELECT * FROM distributors WHERE user_id=? ORDER BY name ASC");
$distStmt->execute([$user_id]);
$distributors = $distStmt->fetchAll();

// Fetch products
$prodStmt = $pdo->prepare("
SELECT 
    p.*,
    p.box_qty AS remaining_box,
    p.pcs_qty AS remaining_pcs,
    p.bottle_qty AS remaining_bottle
FROM products p
WHERE p.user_id=?
ORDER BY p.name ASC
");
$prodStmt->execute([$user_id]);
$allProducts = $prodStmt->fetchAll();

// Group products by type
$productGroups = [
    "Product" => [],
    "La Belleza" => [],
    "By-Pcs Product" => []
];

foreach ($allProducts as $p) {
    if (isset($productGroups[$p['product_type']])) {
        $productGroups[$p['product_type']][] = $p;
    }
}

/* ============================================================
   [SEARCH TAG: PROCESS_CONSIGNMENT / CONSIGNMENT_TRANSACTIONS / DISTRIBUTOR_SALES_RECORD / RECORD_DISTRIBUTOR_SALE]
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_consignment'])) {

    $transaction_ids = $_POST['transaction_ids'] ?? [];
    $product_ids = $_POST['product_ids'] ?? [];
    $actions = $_POST['action_type'] ?? [];

    $itemsToProcess = [];

    /* ============================================================
       [SEARCH TAG: CONSIGNMENT_VALIDATION / AVAILABLE_CONSIGNMENT_CHECK / EXCEEDS_CONSIGNMENT_GUARD]
       STEP 1: VALIDATE AVAILABLE CONSIGNMENT QUANTITIES
    ============================================================ */

    foreach ($product_ids as $i => $pid) {

        $transaction_id = $transaction_ids[$i];
        $action = $actions[$i];

        $box_qty = intval($_POST['box_qty'][$i] ?? 0);
        $pcs_qty = intval($_POST['pcs_qty'][$i] ?? 0);
        $bottle_qty = intval($_POST['bottle_qty'][$i] ?? 0);

        // Get distributor
        $distStmt = $pdo->prepare("SELECT distributor_id FROM transactions WHERE id=?");
        $distStmt->execute([$transaction_id]);
        $distRow = $distStmt->fetch();

        if (!$distRow)
            continue;

        $distributor_id = $distRow['distributor_id'];

        // Get consigned quantity
        $stmt = $pdo->prepare("
            SELECT tp.quantity, tp.unit, p.name
            FROM transaction_products tp
            JOIN products p ON p.id = tp.product_id
            WHERE tp.transaction_id=? AND tp.product_id=?
        ");
        $stmt->execute([$transaction_id, $pid]);
        $item = $stmt->fetch();

        if (!$item)
            continue;

        $unit = $item['unit'];
        $productName = $item['name'];

        /* GET ALREADY SOLD FROM THIS TRANSACTION */
        $soldStmt = $pdo->prepare("
        SELECT quantity_sold
        FROM transaction_products
        WHERE transaction_id=? AND product_id=?
        ");

        $soldStmt->execute([$transaction_id, $pid]);
        $soldRow = $soldStmt->fetch();

        $already_sold = $soldRow ? intval($soldRow['quantity_sold']) : 0;

        /* DETERMINE AVAILABLE */
        $available_box = 0;
        $available_pcs = 0;
        $available_bottle = 0;

        if ($unit === 'BOX') {

            $available_box = $item['quantity'] - $already_sold;

            if ($box_qty > $available_box) {

                $errors[] = "$productName exceeds available consignment.
        Only $available_box BOX remaining.";

            }

        }

        if ($unit === 'PCS') {

            $available_pcs = $item['quantity'] - $already_sold;

            if ($pcs_qty > $available_pcs) {

                $errors[] = "$productName exceeds available consignment.
        Only $available_pcs PCS remaining.";

            }

        }

        if ($unit === 'BOTTLE') {

            $available_bottle = $item['quantity'] - $already_sold;

            if ($bottle_qty > $available_bottle) {

                $errors[] = "$productName exceeds available consignment.
        Only $available_bottle BOTTLE remaining.";

            }

        }


        $itemsToProcess[] = [
            'transaction_id' => $transaction_id,
            'product_id' => $pid,
            'action' => $action,
            'box_qty' => $box_qty,
            'pcs_qty' => $pcs_qty,
            'bottle_qty' => $bottle_qty,
            'distributor_id' => $distributor_id
        ];
    }

    /* =========================
       STOP IF ERROR EXISTS
    ========================= */

    if (!empty($errors)) {

        // just stop saving
        // errors will show above

    } else {

        foreach ($itemsToProcess as $item) {

            $transaction_id = $item['transaction_id'];
            $pid = $item['product_id'];
            $action = $item['action'];
            $distributor_id = $item['distributor_id'];

            if ($action === 'sold') {

    if ($item['box_qty'] > 0) {
        $pdo->prepare("
            INSERT INTO distributor_sales
            (distributor_id, transaction_id, product_id, unit, quantity_sold, sale_date, user_id)
            VALUES (?, ?, ?, 'BOX', ?, NOW(), ?)
        ")->execute([
            $distributor_id,
            $transaction_id,
            $pid,
            $item['box_qty'],
            $user_id
        ]);
    }

    if ($item['pcs_qty'] > 0) {
        $pdo->prepare("
            INSERT INTO distributor_sales
            (distributor_id, transaction_id, product_id, unit, quantity_sold, sale_date, user_id)
            VALUES (?, ?, ?, 'PCS', ?, NOW(), ?)
        ")->execute([
            $distributor_id,
            $transaction_id,
            $pid,
            $item['pcs_qty'],
            $user_id
        ]);
    }

    if ($item['bottle_qty'] > 0) {
        $pdo->prepare("
            INSERT INTO distributor_sales
            (distributor_id, transaction_id, product_id, unit, quantity_sold, sale_date, user_id)
            VALUES (?, ?, ?, 'BOTTLE', ?, NOW(), ?)
        ")->execute([
            $distributor_id,
            $transaction_id,
            $pid,
            $item['bottle_qty'],
            $user_id
        ]);
    }

            }
        }

        /* UPDATE TRANSACTION STATUS */
        $pdo->prepare("
    UPDATE transactions
    SET status='Completed'
    WHERE id=?
    ")->execute([$transaction_id]);

        fr_log_activity($pdo, 'transactions', 'update', "Processed consignment transaction #{$transaction_id} — marked Completed");
        header("Location: transaction.php?success=1");
        exit;
    }


}

/* ------------------- CANCEL TRANSACTION ------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_transaction'])) {
    $tid = intval($_POST['transaction_id'] ?? 0);

    // Verify it belongs to this user and is still Pending
    $check = $pdo->prepare("SELECT id FROM transactions WHERE id=? AND user_id=? AND status='Pending'");
    $check->execute([$tid, $user_id]);

    if ($check->fetch()) {
        // Delete transaction products first
        $pdo->prepare("DELETE FROM transaction_products WHERE transaction_id=?")->execute([$tid]);
        // Delete the transaction
        $pdo->prepare("DELETE FROM transactions WHERE id=? AND user_id=?")->execute([$tid, $user_id]);
        $success = "Transaction cancelled successfully.";
        fr_log_activity($pdo, 'transactions', 'cancel', "Cancelled pending consignment transaction #{$tid}");
    } else {
        $errors[] = "Cannot cancel this transaction. It may already be completed.";
    }
}

// ==============================
// ADD NEW TRANSACTION (WITH DATE)
// ==============================
// ==============================
// UPDATED ADD NEW TRANSACTION
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_transaction'])) {

    $distributor_id = intval($_POST['distributor_id']);
    $transaction_date = $_POST['transaction_date'];
    $product_ids = $_POST['product_ids'] ?? [];
    $quantities = $_POST['quantities'] ?? [];
    $units = $_POST['units'] ?? [];

    // 1. Initial validation
    if (!$distributor_id || empty($product_ids) || !$transaction_date) {
        $errors[] = "Please complete all fields, including date and at least one product.";
    } else {
        try {
            $pdo->beginTransaction();

            // 2. Insert the main transaction
            $insert = $pdo->prepare("INSERT INTO transactions (distributor_id, transaction_date, status, user_id) VALUES (?, ?, 'Pending', ?)");
            $insert->execute([$distributor_id, $transaction_date, $user_id]);
            $transaction_id = $pdo->lastInsertId();

            $hasValidItems = false; // Track if we actually saved anything

            foreach ($product_ids as $index => $pid) {
                $pid = intval($pid);
                $qty = intval($quantities[$index] ?? 0);
                $unit = $units[$index] ?? '';

                if ($pid <= 0 || $qty <= 0 || empty($unit))
                    continue;

                // Check if product belongs to user
                // Get current stock
                $stockStmt = $pdo->prepare("
                SELECT 
                p.box_qty,
                p.pcs_qty,
                p.bottle_qty,

                COALESCE(SUM(
                CASE WHEN tp.unit='BOX' AND t.status='Pending'
                THEN tp.quantity ELSE 0 END
                ),0) AS consigned_box,

                COALESCE(SUM(
                CASE WHEN tp.unit='PCS' AND t.status='Pending'
                THEN tp.quantity ELSE 0 END
                ),0) AS consigned_pcs,

                COALESCE(SUM(
                CASE WHEN tp.unit='BOTTLE' AND t.status='Pending'
                THEN tp.quantity ELSE 0 END
                ),0) AS consigned_bottle

                FROM products p

                LEFT JOIN transaction_products tp
                ON tp.product_id = p.id

                LEFT JOIN transactions t
                ON t.id = tp.transaction_id
                AND t.status='Pending'

                WHERE p.id=? AND p.user_id=?
                GROUP BY p.id
                ");
                $stockStmt->execute([$pid, $user_id]);
                $productStock = $stockStmt->fetch();


                if (!$productStock) {
                    continue;
                }

                /* Determine available stock depending on unit */
                $available = 0;

                if ($unit === 'BOX') {
                    $available = $productStock['box_qty'] - $productStock['consigned_box'];
                }

                if ($unit === 'PCS') {

                    $pcs_per_box = 10; // same conversion used in your system

                    $total_pcs =
                        ($productStock['box_qty'] * $pcs_per_box) +
                        $productStock['pcs_qty'];

                    $consigned_total_pcs =
                        ($productStock['consigned_box'] * $pcs_per_box) +
                        $productStock['consigned_pcs'];

                    $available = $total_pcs - $consigned_total_pcs;

                }

                if ($unit === 'BOTTLE') {
                    $available = $productStock['bottle_qty'] - $productStock['consigned_bottle'];
                }

                /* Validate stock */
                if ($qty > $available) {

                    $errors[] = "Cannot consign product. Only $available $unit available in stock.";

                    continue;
                }

                /* Save consignment */
                $pdo->prepare("
    INSERT INTO transaction_products
    (transaction_id, product_id, unit, quantity, quantity_sold)
    VALUES (?, ?, ?, ?, 0)
")->execute([
                            $transaction_id,
                            $pid,
                            $unit,
                            $qty
                        ]);

                $hasValidItems = true;
            }

            // 3. Final check: If no products were actually saved, cancel the whole thing
            if (!$hasValidItems) {
                $pdo->rollBack();
                $errors[] = "";
            } else {
                $pdo->commit();
                $distNameStmt = $pdo->prepare("SELECT name FROM distributors WHERE id=? AND user_id=?");
                $distNameStmt->execute([$distributor_id, $user_id]);
                $distName = $distNameStmt->fetchColumn() ?: "distributor #{$distributor_id}";
                fr_log_activity($pdo, 'transactions', 'add', "Created consignment transaction with \"{$distName}\" dated {$transaction_date}");
                header("Location: transaction.php?success=1");
                exit;
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Transaction failed: " . $e->getMessage();
        }
    }
}


// =====================================
// FETCH ALL TRANSACTIONS
// =====================================
$transStmt = $pdo->prepare("
    SELECT t.*, d.name AS distributor_name 
    FROM transactions t
    JOIN distributors d ON t.distributor_id = d.id
    WHERE t.user_id=?
    ORDER BY t.id DESC
");
$transStmt->execute([$user_id]);
$transactions = $transStmt->fetchAll();

function getTransactionProducts($pdo, $transaction_id)
{
    $stmt = $pdo->prepare("
        SELECT tp.*, p.name, p.product_type 
        FROM transaction_products tp
        JOIN products p ON tp.product_id = p.id
        WHERE tp.transaction_id=?
    ");
    $stmt->execute([$transaction_id]);
    return $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Transactions - Fil-Rise IMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="mobile.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* MAIN */
        .main {
            margin-left: 260px;
            padding: 40px;
            width: calc(100% - 260px);
            box-sizing: border-box;
        }

        .card {
            border-radius: 20px;
            padding: 25px;
            background: white;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        /* ============ PRODUCT AUTOCOMPLETE ============ */
        .product-autocomplete {
            position: relative;
        }

        .product-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            z-index: 1080;
            background: #fff;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            max-height: 340px;
            overflow-y: auto;
            padding: 6px 0;
        }

        .pd-group-label {
            position: sticky;
            top: 0;
            background: #eafaf1;
            color: #198754;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 6px 14px;
        }

        .pd-item {
            padding: 8px 14px;
            font-size: 14px;
            color: #1f2937;
            cursor: pointer;
        }

        .pd-item:hover,
        .pd-item.pd-active {
            background: #f0fdf4;
            color: #198754;
        }

        .pd-item mark {
            background: #d1fae5;
            color: #15803d;
            font-weight: 700;
            padding: 0;
        }

        .pd-empty {
            padding: 12px 14px;
            font-size: 13px;
            color: #9ca3af;
            text-align: center;
        }
    </style>

    <script>
        // Update product list on type select
        function addProductRow() {
            const container = document.getElementById("products_container");

            let rowIndex = 1;

            function addProductRow() {

                const container = document.getElementById("products_container");

                let row = document.createElement("div");
                row.className = "row g-3 align-items-end mb-3";

                row.innerHTML = `
        <div class="col-md-3">
            <select id="productType_${rowIndex}"
                    class="form-select"
                    onchange="updateProductList(${rowIndex})"
                    required>
                <option value="">Select</option>
                <option value="Product">Product</option>
                <option value="La Belleza">La Belleza</option>
                <option value="By-Pcs Product">By-Pcs Product</option>
            </select>
        </div>

        <div class="col-md-3">
            <select name="product_ids[]"
                    id="productSelect_${rowIndex}"
                    class="form-select"
                    required>
                <option value="">Select Product</option>
            </select>
        </div>

        <div class="col-md-2">
            <select name="units[]" class="form-select" required>
                <option value="">Unit</option>
                <option value="BOX">Box</option>
                <option value="PCS">Pcs</option>
                <option value="BOTTLE">Bottle</option>
            </select>
        </div>

        <div class="col-md-2">
            <input type="number"
                   name="quantities[]"
                   class="form-control"
                   min="1"
                   value="1"
                   required>
        </div>

        <div class="col-md-2">
            <button type="button"
                    class="btn btn-danger w-100"
                    onclick="this.closest('.row').remove()">
                Remove
            </button>
        </div>
    `;

                container.appendChild(row);
                rowIndex++;
            }


    </script>

</head>

<body>
    <?php include 'ticker.php'; ?>

    <!-- SIDEBAR -->
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
        <a <?= fr_href('transaction.php') ?> class="active">Consignment Transactions</a>
        <a <?= fr_href('sales.php') ?>> Walk-in Sales</a>
        <a <?= fr_href('returns.php') ?>>Product Returns</a>
        <a <?= fr_href('stock_requests.php') ?>>Stock Requests</a>
        <a <?= fr_href('daily_records.php') ?>> Daily Records</a>
        <a <?= fr_href('daily_sales_record.php') ?>> Daily Sales Record</a>
        <a href="expenses.php">Expenses</a>

        <div class="menu-title">People</div>
        <a <?= fr_href('distributor.php') ?>> Distributors</a>
        <a <?= fr_href('members.php') ?>> Members</a>


        <div class="logout" onclick="window.location='logout.php'">
            Logout
        </div>

    </div>

    <!-- MAIN CONTENT -->
    <div class="main">

        <h3>Consignment Transactions</h3>

        <?php if (isset($_GET['success']) && empty($errors)): ?>
            <div class="alert alert-success">
                Transaction added successfully!
            </div>
        <?php endif; ?>

        <!-- ADD TRANSACTION -->
        <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
            + Add New Consignment
        </button>

        <!-- ADD TRANSACTION MODAL -->
        <div class="modal fade" id="addTransactionModal" tabindex="-1">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">Add New Consignment</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">

                        <?php if (!empty($errors) && isset($_POST['add_transaction'])): ?>
                            <div class="alert alert-danger">
                                <?= implode("<br>", $errors) ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" onsubmit="return validateProducts()">

                            <div class="row g-3">

                                <div class="col-md-3">
                                    <label class="form-label">Transaction Date</label>
                                    <input type="date" name="transaction_date" class="form-control"
                                        value="<?= date('Y-m-d') ?>" required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Distributor</label>
                                    <select name="distributor_id" class="form-select" required>
                                        <option value="">Select Distributor</option>
                                        <?php foreach ($distributors as $d): ?>
                                            <option value="<?= $d['id'] ?>">
                                                <?= htmlspecialchars($d['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                            </div>

                            <hr class="my-4">

                            <h6>Products</h6>

                            <div id="products_container">

                                <div class="row g-3 align-items-end mb-3">

                                    <div class="col-md-4">
                                        <div class="product-autocomplete">
                                            <input type="text" class="form-control product-search"
                                                placeholder="Type product name..." autocomplete="off"
                                                oninput="filterProducts(this)" onfocus="filterProducts(this)"
                                                onkeydown="handleProductKeydown(event, this)" required>
                                            <input type="hidden" name="product_ids[]" class="product-id">
                                            <div class="product-dropdown"></div>
                                        </div>
                                    </div>

                                    <div class="col-md-2">
                                        <select name="units[]" class="form-select" required>
                                            <option value="">Unit</option>
                                            <option value="BOX">Box</option>
                                            <option value="PCS">Pcs</option>
                                            <option value="BOTTLE">Bottle</option>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <input type="number" name="quantities[]" class="form-control" min="1" value="1"
                                            required>
                                    </div>

                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-outline-primary" onclick="addProductRow()">
                                            Add Product
                                        </button>
                                    </div>

                                </div>

                            </div>

                            <div class="modal-footer mt-4">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    Cancel
                                </button>

                                <button type="submit" name="add_transaction" class="btn btn-success">
                                    Save Transaction
                                </button>
                            </div>

                        </form>

                    </div>
                </div>
            </div>
        </div>

        <!-- LIST OF TRANSACTIONS -->
        <div class="card">
            <h5>Transaction List</h5>
            <div style="overflow-x: auto;">
            <table class="table table-hover align-middle">
                <thead class="table-success">
                    <tr>

                        <th>Distributor</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>

                    <?php foreach ($transactions as $t): ?>
                        <tr>
                            <div class="modal fade" id="processModal<?= $t['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">

                                        <div class="modal-header bg-success text-white">
                                            <h5 class="modal-title">
                                                Process Consignment - <?= htmlspecialchars($t['distributor_name']) ?>
                                            </h5>
                                            <button type="button" class="btn-close btn-close-white"
                                                data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body">

                                            <?php if ($errors): ?>
                                                <div class="alert alert-danger">
                                                    <?= implode("<br>", $errors) ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php
                                            $items = getTransactionProducts($pdo, $t['id']);
                                            ?>

                                            <form method="POST">

                                                <input type="hidden" name="process_consignment" value="1">

                                                <?php foreach ($items as $item): ?>

                                                    <div class="row align-items-center mb-3">

                                                        <input type="hidden" name="transaction_ids[]" value="<?= $t['id'] ?>">
                                                        <input type="hidden" name="product_ids[]"
                                                            value="<?= $item['product_id'] ?>">

                                                        <div class="col-md-4">
                                                            <strong>
                                                                <?= htmlspecialchars($item['name']) ?>
                                                                (<?= $item['quantity'] ?>         <?= $item['unit'] ?>)
                                                            </strong>
                                                        </div>

                                                        <div class="col-md-3">
                                                            <select name="action_type[]" class="form-select" required>
                                                                <option value="">Select</option>
                                                                <option value="sold">Sold</option>
                                                                <option value="return">Return</option>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-3">

                                                            <?php if ($item['unit'] === 'BOX'): ?>

                                                                <div class="d-flex gap-2">
                                                                    <input type="number" name="box_qty[]" class="form-control"
                                                                        placeholder="BOX" min="0" required>
                                                                    <input type="number" name="pcs_qty[]" class="form-control"
                                                                        placeholder="PCS" min="0" required>
                                                                    <input type="hidden" name="bottle_qty[]" value="0" required>
                                                                </div>

                                                            <?php elseif ($item['unit'] === 'PCS'): ?>

                                                                <input type="hidden" name="box_qty[]" value="0" required>
                                                                <input type="number" name="pcs_qty[]" class="form-control"
                                                                    placeholder="PCS" min="0" required>
                                                                <input type="hidden" name="bottle_qty[]" value="0" required>

                                                            <?php else: ?>

                                                                <input type="hidden" name="box_qty[]" value="0" required>
                                                                <input type="hidden" name="pcs_qty[]" value="0" required>
                                                                <input type="number" name="bottle_qty[]" class="form-control"
                                                                    placeholder="BOTTLE" min="0" required>

                                                            <?php endif; ?>

                                                        </div>

                                                    </div>

                                                <?php endforeach; ?>

                                                <div class="text-end">
                                                    <button class="btn btn-success">Save</button>
                                                </div>

                                            </form>

                                        </div>
                                    </div>
                                </div>
                            </div>

                            <td><?= htmlspecialchars($t['distributor_name']) ?></td>
                            <td><?= $t['transaction_date'] ?></td>


                            <td>
                                <?php if ($t['status'] == "Pending"): ?>
                                    <span class="badge bg-warning text-dark">Pending</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Completed</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($t['status'] == "Pending"): ?>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-success btn-sm" data-bs-toggle="modal"
                                            data-bs-target="#processModal<?= $t['id'] ?>">
                                            View Status
                                        </button>
                                        <button class="btn btn-danger btn-sm"
                                            onclick="confirmCancel(<?= $t['id'] ?>, '<?= htmlspecialchars($t['distributor_name'], ENT_QUOTES) ?>', '<?= $t['transaction_date'] ?>')">
                                            Cancel
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <span style="color:#6b7280;font-size:13px;">Recorded</span>
                                        <a class="btn btn-outline-secondary btn-sm" target="_blank"
                                            href="receipt_distributor.php?id=<?= $t['id'] ?>">
                                            View Receipt
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                </tbody>
            </table>
            </div><!-- /overflow-x -->
        </div>

    </div>

    <?php if ($errors && isset($_POST['process_consignment'])): ?>

        <script>

                document.addEventListener("DOMContentLoaded", function () {

                    let modalId = "processModal<?= $_POST['transaction_ids'][0] ?>";

                    let modal = new bootstrap.Modal(document.getElementById(modalId));

                    modal.show();

                });

        </script>

    <?php endif; ?>

    <?php if ($errors && isset($_POST['add_transaction'])): ?>

        <script>
                document.addEventListener("DOMContentLoaded", function () {

                    let modal = new bootstrap.Modal(
                        document.getElementById("addTransactionModal")
                    );

                    modal.show();

                });
        </script>

    <?php endif; ?>

    <script>
            /* ============ PRODUCT AUTOCOMPLETE (grouped by category) ============ */

            const categoryLabels = {
                "Coffee": "Coffee",
                "Health / Liniment / Oil": "Herbal",
                "Supplements / Capsule": "Capsule",
                "Fragrance": "Fragrance",
                "Household": "Household",
                "La Belleza": "La Belleza"
            };

            function escapeHtml(str) {
                return String(str)
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;");
            }

            function highlightMatch(name, query) {
                if (!query) return escapeHtml(name);
                const idx = name.toLowerCase().indexOf(query);
                if (idx === -1) return escapeHtml(name);
                const before = escapeHtml(name.slice(0, idx));
                const match = escapeHtml(name.slice(idx, idx + query.length));
                const after = escapeHtml(name.slice(idx + query.length));
                return `${before}<mark>${match}</mark>${after}`;
            }

            function closeAllProductDropdowns() {
                document.querySelectorAll('.product-dropdown').forEach(dd => {
                    dd.style.display = 'none';
                    dd.innerHTML = '';
                });
            }

            function filterProducts(input) {
                const wrapper = input.closest('.product-autocomplete');
                const dropdown = wrapper.querySelector('.product-dropdown');
                const hiddenInput = wrapper.querySelector('.product-id');

                // Typing invalidates any previously confirmed selection
                hiddenInput.value = "";

                const query = input.value.trim().toLowerCase();

                let matches = (typeof productStock !== 'undefined' ? productStock : [])
                    .filter(p => p.name.toLowerCase().includes(query));

                matches.sort((a, b) => {
                    const aStarts = a.name.toLowerCase().startsWith(query) ? 0 : 1;
                    const bStarts = b.name.toLowerCase().startsWith(query) ? 0 : 1;
                    if (aStarts !== bStarts) return aStarts - bStarts;
                    return a.name.localeCompare(b.name);
                });

                if (matches.length === 0) {
                    dropdown.innerHTML = '<div class="pd-empty">No matching products</div>';
                    dropdown.style.display = 'block';
                    return;
                }

                // Group matches by category
                const groups = {};
                matches.forEach(p => {
                    const cat = p.category || "Other";
                    if (!groups[cat]) groups[cat] = [];
                    groups[cat].push(p);
                });

                let html = '';
                Object.keys(groups).forEach(cat => {
                    const label = categoryLabels[cat] || cat;
                    html += `<div class="pd-group-label">${escapeHtml(label)}</div>`;
                    groups[cat].forEach(p => {
                        html += `<div class="pd-item"
                                    onclick="selectProduct(this)"
                                    data-id="${p.id}"
                                    data-name="${escapeHtml(p.name)}"
                                    data-unit="${p.unit_type}">
                                    ${highlightMatch(p.name, query)}
                                 </div>`;
                    });
                });

                dropdown.innerHTML = html;
                dropdown.style.display = 'block';
            }

            function selectProduct(el) {
                const wrapper = el.closest('.product-autocomplete');
                const input = wrapper.querySelector('.product-search');
                const hiddenInput = wrapper.querySelector('.product-id');
                const dropdown = wrapper.querySelector('.product-dropdown');
                const unitSelect = wrapper.closest('.row').querySelector('select[name="units[]"]');

                input.value = el.dataset.name;
                hiddenInput.value = el.dataset.id;

                unitSelect.innerHTML = '<option value="">Unit</option>';
                const unitType = el.dataset.unit;

                if (unitType === "BOTTLE") {
                    unitSelect.innerHTML = `<option value="BOTTLE" selected>BOTTLE</option>`;
                } else if (unitType === "PCS") {
                    unitSelect.innerHTML = `<option value="PCS" selected>PCS</option>`;
                } else if (unitType === "BOX") {
                    unitSelect.innerHTML = `
                <option value="BOX">BOX</option>
                <option value="PCS">PCS</option>
            `;
                }

                dropdown.style.display = 'none';
                dropdown.innerHTML = '';
            }

            function handleProductKeydown(event, input) {
                const wrapper = input.closest('.product-autocomplete');
                const dropdown = wrapper.querySelector('.product-dropdown');

                if (dropdown.style.display !== 'block') return;

                const items = Array.from(dropdown.querySelectorAll('.pd-item'));
                if (items.length === 0) return;

                let activeIndex = items.findIndex(i => i.classList.contains('pd-active'));

                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    if (activeIndex >= 0) items[activeIndex].classList.remove('pd-active');
                    activeIndex = (activeIndex + 1) % items.length;
                    items[activeIndex].classList.add('pd-active');
                    items[activeIndex].scrollIntoView({ block: 'nearest' });
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    if (activeIndex >= 0) items[activeIndex].classList.remove('pd-active');
                    activeIndex = (activeIndex - 1 + items.length) % items.length;
                    items[activeIndex].classList.add('pd-active');
                    items[activeIndex].scrollIntoView({ block: 'nearest' });
                } else if (event.key === 'Enter') {
                    if (activeIndex >= 0) {
                        event.preventDefault();
                        selectProduct(items[activeIndex]);
                    }
                } else if (event.key === 'Escape') {
                    dropdown.style.display = 'none';
                }
            }

            document.addEventListener('click', function (e) {
                if (!e.target.closest('.product-autocomplete')) {
                    closeAllProductDropdowns();
                }
            });


            const productGroups = <?= json_encode($productGroups) ?>;

            let rowIndex = 1;

            /* ================= UPDATE PRODUCT LIST ================= */

            function updateProductList(index) {

                const typeSelect = document.getElementById("productType_" + index);
                const productSelect = document.getElementById("productSelect_" + index);

                const selectedType = typeSelect.value;

                productSelect.innerHTML = '<option value="">Select Product</option>';

                if (productGroups[selectedType]) {
                    productGroups[selectedType].forEach(product => {
                        let option = document.createElement("option");
                        option.value = product.id;
                        option.textContent = product.name;
                        productSelect.appendChild(option);
                    });
                }
            }

            /* ================= ADD NEW PRODUCT ROW ================= */

            function addProductRow() {

                const container = document.getElementById("products_container");

                let row = document.createElement("div");
                row.className = "row g-3 align-items-end mb-3";

                row.innerHTML = `

                <!-- REMOVE -->
            <div class="col-md-1 d-grid">
              <button type="button" class="btn btn-danger mt-4" onclick="this.closest('.row').remove()">
                🗑️
              </button>
            </div>
        <div class="col-md-4">
    <div class="product-autocomplete">
        <input type="text"
               class="form-control product-search"
               placeholder="Type product name..."
               autocomplete="off"
               oninput="filterProducts(this)"
               onfocus="filterProducts(this)"
               onkeydown="handleProductKeydown(event, this)"
               required>

        <input type="hidden"
               name="product_ids[]"
               class="product-id">

        <div class="product-dropdown"></div>
    </div>
</div>

        <div class="col-md-2">
            <select name="units[]" class="form-select" required>
                <option value="">Unit</option>
                <option value="BOX">Box</option>
                <option value="PCS">Pcs</option>
                <option value="BOTTLE">Bottle</option>
            </select>
        </div>

        <div class="col-md-2">
            <input type="number"
                   name="quantities[]"
                   class="form-control"
                   min="1"
                   value="1"
                   required>
        </div>

        
    `;

                container.appendChild(row);
                rowIndex++;
            }

            function validateProducts() {


                const hiddenInputs = document.querySelectorAll(".product-id");

                for (let input of hiddenInputs) {
                    if (!input.value) {
                        alert("Please select a product from the suggestion list.");
                        return false;
                    }
                }

                const rows = document.querySelectorAll("#products_container .row");

                for (let row of rows) {

                    const productId = row.querySelector(".product-id").value;
                    const unit = row.querySelector('select[name="units[]"]').value;
                    const qty = parseInt(row.querySelector('input[name="quantities[]"]').value);

                    if (!productId) {
                        alert("Please select a valid product.");
                        return false;
                    }

                    const product = productStock.find(p => p.id == productId);

                    if (!product) continue;

                    let available = 0;

                    if (unit === "BOX") {
                        available = parseInt(product.remaining_box || 0);
                    }

                    if (unit === "PCS") {
                        const pcsPerBox = 10;
                        const boxQty = parseInt(product.remaining_box || 0);
                        const pcsQty = parseInt(product.remaining_pcs || 0);
                        available = (boxQty * pcsPerBox) + pcsQty;
                    }

                    if (unit === "BOTTLE") {
                        available = parseInt(product.remaining_bottle || 0);
                    }

                    if (qty > available) {

                        alert(
                            product.name +
                            " only has " + available + " " + unit +
                            " remaining in stock."
                        );

                        return false;
                    }
                }

                return true;
            }

    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
            const productStock = <?= json_encode($allProducts) ?>;
    </script>

    <?php include 'chatbot.php'; ?>
<?php include 'mobile_sidebar.php'; ?>

<!-- CANCEL CONFIRMATION MODAL -->
<div class="modal fade" id="cancelModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;border:none;">
      <div class="modal-header" style="background:#ef4444;color:white;border-radius:16px 16px 0 0;">
        <h5 class="modal-title">⚠ Cancel Transaction</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="padding:24px;">
        <p style="font-size:14px;color:#374151;">Are you sure you want to cancel this consignment?</p>
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px;margin-top:8px;">
          <div style="font-size:13px;font-weight:600;color:#111827;" id="cancelDistName"></div>
          <div style="font-size:12px;color:#6b7280;margin-top:4px;" id="cancelDate"></div>
        </div>
        <p style="font-size:13px;color:#ef4444;margin-top:12px;margin-bottom:0;">
          ⚠ This will permanently delete the transaction and cannot be undone.
        </p>
      </div>
      <div class="modal-footer" style="border:none;padding:16px 24px;">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep It</button>
        <form method="POST" id="cancelForm" style="display:inline;">
          <input type="hidden" name="cancel_transaction" value="1">
          <input type="hidden" name="transaction_id" id="cancelTid">
          <button type="submit" class="btn btn-danger">Yes, Cancel Transaction</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function confirmCancel(tid, distName, date) {
  document.getElementById('cancelTid').value      = tid;
  document.getElementById('cancelDistName').textContent = ' ' + distName;
  document.getElementById('cancelDate').textContent     = ' ' + date;
  new bootstrap.Modal(document.getElementById('cancelModal')).show();
}
</script>
</body>

</html>
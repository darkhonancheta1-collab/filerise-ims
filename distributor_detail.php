<?php
/**
 * distributor_detail.php — Individual Distributor Profile & Sales History
 *
 * Shows a single distributor's profile, consignment balance, and
 * full sales history. Features:
 *   - View distributor info (name, contact, address, photo)
 *   - Record new consignment sales (product + quantity → stock deducted)
 *   - View all past sales with date filtering
 *   - Generate printable receipts (receipt_distributor.php)
 *   - Delete individual sale records with stock restoration
 *   - Activity logging for all operations
 *
 * Accessed via: distributor_detail.php?id=<distributor_id>
 * Module-gated via branch_modules (distributors).
 */
require 'stock_manager.php';
require 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* -- Authentication guard -- */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id        = $_SESSION['user_id'];
require_once 'branch_modules.php';
fr_require_module($pdo, 'distributors');
require_once 'activity_log.php';
$distributor_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM distributors WHERE id=? AND user_id=?");
$stmt->execute([$distributor_id, $user_id]);
$distributor = $stmt->fetch();
if (!$distributor) die("Distributor not found");

$stmt = $pdo->prepare("SELECT * FROM products WHERE user_id=? ORDER BY name ASC");
$stmt->execute([$user_id]);
$products = $stmt->fetchAll();

// RANGE FILTER
$mode = $_GET['mode'] ?? 'current';
$date = $_GET['date'] ?? date('Y-m-d');
$from = $_GET['from'] ?? null;
$to   = $_GET['to']   ?? null;

if ($mode === 'range' && $from && $to) {
    $dateSQL     = " AND DATE(ds.sale_date) BETWEEN ? AND ?";
    $params      = [$distributor_id, $user_id, $from, $to];
    $filterLabel = "From $from to $to";
} else {
    $dateSQL     = " AND DATE(ds.sale_date) = ?";
    $params      = [$distributor_id, $user_id, $date];
    $filterLabel = $date;
}

$errors  = [];
$success = "";

/* EDIT PROFILE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_profile'])) {
    $newName    = trim($_POST['name']);
    $newGender  = trim($_POST['gender']);
    $newPos     = trim($_POST['position']);
    $newContact = trim($_POST['contact_number']);
    $newAddress = trim($_POST['address']);
    $newPhoto   = $distributor['photo'];

    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png'])) {
            $errors[] = "Invalid photo format.";
        } else {
            $newFile = "dist_" . time() . "_" . rand(100,999) . "." . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], __DIR__."/uploads/".$newFile))
                $newPhoto = $newFile;
        }
    }
    if (!$errors) {
        $pdo->prepare("UPDATE distributors SET name=?,gender=?,position=?,contact_number=?,address=?,photo=? WHERE id=? AND user_id=?")
            ->execute([$newName,$newGender,$newPos,$newContact,$newAddress,$newPhoto,$distributor_id,$user_id]);
        $success = "Profile updated successfully!";
        fr_log_activity($pdo, 'distributors', 'update', "Edited distributor profile \"{$newName}\"");
        $stmt = $pdo->prepare("SELECT * FROM distributors WHERE id=? AND user_id=?");
        $stmt->execute([$distributor_id, $user_id]);
        $distributor = $stmt->fetch();
    }
}

/* MANUAL SALE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sale'])) {
    $sale_date = $_POST['sale_date'];
    $items     = $_POST['products']   ?? [];
    $quantities= $_POST['quantities'] ?? [];
    if (!$sale_date) $errors[] = "Please choose date.";
    if (!$errors) {
        $loggedQty = 0;
        foreach ($items as $i => $pid) {
            $qty = intval($quantities[$i] ?? 0);
            if ($qty <= 0) continue;
            $unit = $_POST['units'][$i] ?? 'PCS';
            $pdo->prepare("INSERT INTO distributor_sales (distributor_id,product_id,unit,quantity_sold,sale_date,user_id) VALUES (?,?,?,?,?,?)")
                ->execute([$distributor_id,$pid,$unit,$qty,$sale_date,$user_id]);
            $loggedQty += $qty;
        }
        fr_log_activity($pdo, 'distributors', 'sale', "Recorded sale for distributor \"{$distributor['name']}\" — {$loggedQty} unit(s) on {$sale_date}");
        header("Location: distributor_detail.php?id=$distributor_id&success=1");
        exit;
    }
}

if (isset($_GET['success'])) $success = "Sale added.";

/* PENDING CONSIGNMENTS */
$pendingStmt = $pdo->prepare("
    SELECT t.id AS transaction_id, tp.*, p.name, p.product_type
    FROM transactions t
    JOIN transaction_products tp ON t.id = tp.transaction_id
    JOIN products p ON p.id = tp.product_id
    WHERE t.distributor_id=? AND t.user_id=? AND t.status='Pending'
");
$pendingStmt->execute([$distributor_id, $user_id]);
$pendingTransactions = $pendingStmt->fetchAll();

/* SALES FOR FILTER */
$stmt = $pdo->prepare("
    SELECT ds.*, p.name AS product_name, p.product_type, p.member_price, p.unit_type
    FROM distributor_sales ds
    JOIN products p ON p.id = ds.product_id
    WHERE ds.distributor_id=? AND ds.user_id=? $dateSQL
    ORDER BY ds.sale_date DESC
");
$stmt->execute($params);
$sales = $stmt->fetchAll();

/* TOTALS for filtered period */
$total_qty     = 0;
$total_revenue = 0;
foreach ($sales as $s) {
    $total_qty += $s['quantity_sold'];
    $price = ($s['unit'] === 'PCS' && $s['unit_type'] === 'BOX')
        ? ceil($s['member_price'] / 10)
        : $s['member_price'];
    $total_revenue += $s['quantity_sold'] * $price;
}

/* ALL-TIME STATS */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(ds.quantity_sold),0) AS all_qty,
           COALESCE(SUM(ds.quantity_sold * p.member_price),0) AS all_revenue
    FROM distributor_sales ds
    JOIN products p ON p.id = ds.product_id
    WHERE ds.distributor_id=? AND ds.user_id=?
");
$stmt->execute([$distributor_id, $user_id]);
$allTime = $stmt->fetch();

/* MONTHLY STATS */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(ds.quantity_sold),0) AS mo_qty,
           COALESCE(SUM(ds.quantity_sold * p.member_price),0) AS mo_revenue
    FROM distributor_sales ds
    JOIN products p ON p.id = ds.product_id
    WHERE ds.distributor_id=? AND ds.user_id=?
      AND MONTH(ds.sale_date)=MONTH(CURDATE()) AND YEAR(ds.sale_date)=YEAR(CURDATE())
");
$stmt->execute([$distributor_id, $user_id]);
$monthly = $stmt->fetch();

/* RANKING */
$rankStmt = $pdo->prepare("
    SELECT d.id, COALESCE(SUM(ds.quantity_sold),0) AS total
    FROM distributors d
    LEFT JOIN distributor_sales ds
        ON d.id=ds.distributor_id AND ds.user_id=?
        AND MONTH(ds.sale_date)=MONTH(CURDATE()) AND YEAR(ds.sale_date)=YEAR(CURDATE())
    WHERE d.user_id=?
    GROUP BY d.id
    ORDER BY total DESC
");
$rankStmt->execute([$user_id, $user_id]);
$allRanks = $rankStmt->fetchAll();
$currentRank = 1;
foreach ($allRanks as $ri => $r) {
    if ($r['id'] == $distributor_id) { $currentRank = $ri + 1; break; }
}
$totalDistributors = count($allRanks);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Distributor Details - <?= htmlspecialchars($distributor['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="mobile.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="icon" type="image/png" href="7.png">
    <style>
        :root {
            --green: #7b9b52;
            --dark:  #0f172a;
        }
        body {
            font-family: 'Poppins', sans-serif;
            background: #f1f5f9;
            min-height: 100vh;
            margin: 0;
            display: flex;
            color: #0f172a;
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
        .sidebar .logo { width: 140px; margin-bottom: 30px; }
        .sidebar .menu-title { font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-top:20px;margin-bottom:10px; }
        .sidebar a { color:#cbd5e1;text-decoration:none;padding:10px 15px;border-radius:10px;display:flex;align-items:center;gap:10px;margin-bottom:5px;transition:all 0.3s;font-size:14px; }
        .sidebar a:hover { background:rgba(59,130,246,.2);color:#fff; }
        .sidebar a.active { background:linear-gradient(90deg,#2563eb,#3b82f6);color:#fff; }
        .logout { margin-top:auto;padding:10px 15px;border-radius:10px;background:rgba(239,68,68,.2);color:#cbd5e1;cursor:pointer;transition:.3s; }
        .logout:hover { background:rgba(239,68,68,.4); }

        /* MAIN */
        .main { margin-left: 260px; padding: 36px 40px; flex: 1; }

        /* PROFILE CARD */
        .profile-card {
            background: #fff;
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 4px 18px rgba(0,0,0,.07);
            margin-bottom: 24px;
        }
        .profile-photo {
            width: 100px; height: 100px;
            border-radius: 16px; overflow: hidden;
            border: 3px solid #e5e7eb; flex-shrink: 0;
            cursor: pointer; transition: .2s;
            position: relative;
        }
        .profile-photo:hover { border-color: #3b82f6; transform: scale(1.05); box-shadow: 0 4px 16px rgba(37,99,235,.3); }
        .profile-photo:hover::after {
            content: '🔍';
            position: absolute; inset: 0;
            background: rgba(0,0,0,.35);
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; border-radius: 13px;
        }
        .profile-photo img { width:100%; height:100%; object-fit:cover; }

        /* LIGHTBOX */
        #photoLightbox {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.88); z-index: 99999;
            align-items: center; justify-content: center;
            animation: lbIn .2s ease;
        }
        #photoLightbox.open { display: flex; }
        @keyframes lbIn { from{opacity:0} to{opacity:1} }
        #photoLightbox img {
            max-width: 88vw; max-height: 88vh;
            border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,.6);
            animation: lbScale .25s cubic-bezier(.34,1.56,.64,1);
        }
        @keyframes lbScale { from{transform:scale(.85)} to{transform:scale(1)} }
        #lbClose {
            position: fixed; top: 20px; right: 24px;
            color: #fff; font-size: 32px; cursor: pointer;
            background: rgba(255,255,255,.15); border: none;
            border-radius: 50%; width: 44px; height: 44px;
            display: flex; align-items: center; justify-content: center;
            transition: .2s; line-height: 1;
        }
        #lbClose:hover { background: rgba(255,255,255,.3); }
        .profile-name { font-size:22px; font-weight:700; color:#111827; margin-bottom:4px; }
        .profile-pos  { font-size:13px; color:#6b7280; margin-bottom:10px; }
        .profile-meta span {
            display:inline-flex; align-items:center; gap:5px;
            font-size:12px; color:#6b7280; margin-right:16px;
        }

        /* STAT CARDS */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 18px 20px;
            box-shadow: 0 4px 14px rgba(0,0,0,.06);
            border-left: 4px solid #e5e7eb;
        }
        .stat-card.green  { border-color: #7b9b52; }
        .stat-card.blue   { border-color: #3b82f6; }
        .stat-card.orange { border-color: #f97316; }
        .stat-card.gold   { border-color: #f59e0b; }
        .stat-lbl { font-size:11px; color:#6b7280; font-weight:500; margin-bottom:4px; }
        .stat-val { font-size:20px; font-weight:800; color:#111827; }
        .stat-sub { font-size:11px; color:#9ca3af; margin-top:2px; }

        /* FILTER CARD */
        .filter-card {
            background: #fff; border-radius: 16px;
            padding: 20px 24px; box-shadow: 0 4px 14px rgba(0,0,0,.06);
            margin-bottom: 20px;
        }

        /* SALES CARD */
        .sales-card {
            background: #fff; border-radius: 16px;
            box-shadow: 0 4px 14px rgba(0,0,0,.06);
            overflow: hidden;
        }
        .sales-card-header {
            display: flex; justify-content: space-between;
            align-items: center; padding: 18px 24px;
            border-bottom: 1px solid #f1f5f9;
        }
        .sales-card-header h5 { font-size:15px; font-weight:700; color:#111827; margin:0; }

        /* TOTALS ROW */
        .totals-row {
            display: flex; gap: 24px; align-items: center;
        }
        .total-pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: 20px;
            font-size: 13px; font-weight: 700;
        }
        .total-pill.qty     { background:#f0fdf4; color:#15803d; }
        .total-pill.revenue { background:#eff6ff; color:#1d4ed8; }

        /* TABLE */
        .table { margin:0; }
        .table thead th {
            background: #f8fafc; font-size:12px; font-weight:600;
            color:#6b7280; padding:12px 20px;
            border-bottom: 2px solid #f1f5f9; text-transform: uppercase; letter-spacing: .5px;
        }
        .table tbody td {
            padding: 12px 20px; font-size:13px;
            border-bottom: 1px solid #f8fafc; vertical-align: middle;
        }
        .table tbody tr:last-child td { border-bottom: none; }
        .table tbody tr:hover { background: #fafafa; }

        .badge-type {
            padding: 3px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 600;
        }
        .badge-product  { background:#eff6ff; color:#1d4ed8; }
        .badge-labelleza{ background:#fdf2f8; color:#9d174d; }
        .badge-bypcs    { background:#f0fdf4; color:#15803d; }

        .unit-badge {
            background:#f1f5f9; color:#475569;
            padding:2px 8px; border-radius:6px;
            font-size:11px; font-weight:600;
        }

        /* BUTTONS */
        .btn-primary {
            background: linear-gradient(135deg,#7b9b52,#5f7a3f);
            border:none; font-weight:600; border-radius:10px;
        }
        .btn-warning { font-weight:600; border-radius:10px; }

        @media print {
            body * { visibility:hidden !important; }
            #receiptPrintArea, #receiptPrintArea * { visibility:visible !important; }
            #receiptPrintArea { position:absolute; left:0; top:0; width:100%; padding:20px; }
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
<?= fr_locked_style() ?>
    <img src="7.png" alt="Logo" class="logo">
    <a <?= fr_href('distributor.php') ?>>← Back to Distributors</a>
    <a href="dashboard.php">Dashboard</a>
    <div class="logout" onclick="window.location='logout.php'">Logout</div>
</div>

<div class="main">

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show mb-3">
            <?= $success ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger mb-3"><?= implode('<br>', $errors) ?></div>
    <?php endif; ?>

    <!-- PROFILE CARD -->
    <div class="profile-card">
        <div class="d-flex justify-content-between align-items-start">
            <div class="d-flex gap-4 align-items-center">
                <div class="profile-photo" onclick="openLightbox(this.querySelector('img').src)">
                    <img src="uploads/<?= htmlspecialchars($distributor['photo'] ?: 'default.png') ?>">
                </div>

<!-- LIGHTBOX -->
<div id="photoLightbox" onclick="closeLightbox()">
  <button id="lbClose" onclick="closeLightbox()">✕</button>
  <img id="lbImg" src="" alt="Distributor Photo">
</div>
                <div>
                    <div class="profile-name"><?= htmlspecialchars($distributor['name']) ?></div>
                    <div class="profile-pos"><?= htmlspecialchars($distributor['position']) ?></div>
                    <div class="profile-meta">
                        <span>📞 <?= htmlspecialchars($distributor['contact_number']) ?></span>
                        <span>⚧ <?= htmlspecialchars($distributor['gender']) ?></span>
                        <span>📍 <?= htmlspecialchars($distributor['address']) ?></span>
                    </div>
                </div>
            </div>
            <button class="btn btn-warning px-4" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                 Edit Profile
            </button>
        </div>
    </div>

    <!-- STAT CARDS -->
    <div class="stat-grid">
        <div class="stat-card green">
            <div class="stat-lbl">This Month — Qty Sold</div>
            <div class="stat-val"><?= number_format($monthly['mo_qty']) ?></div>
            <div class="stat-sub"><?= date('F Y') ?></div>
        </div>
        <div class="stat-card blue">
            <div class="stat-lbl">This Month — Revenue</div>
            <div class="stat-val" style="font-size:17px;">₱<?= number_format($monthly['mo_revenue']) ?></div>
            <div class="stat-sub"><?= date('F Y') ?></div>
        </div>
        <div class="stat-card orange">
            <div class="stat-lbl">All-Time — Total Sold</div>
            <div class="stat-val"><?= number_format($allTime['all_qty']) ?></div>
            <div class="stat-sub">Since record start</div>
        </div>
        <div class="stat-card gold">
            <div class="stat-lbl">Monthly Rank</div>
            <div class="stat-val"><?= $currentRank === 1 ? '1' : ($currentRank === 2 ? '2' : ($currentRank === 3 ? '3' : '#'.$currentRank)) ?></div>
            <div class="stat-sub">of <?= $totalDistributors ?> distributors</div>
        </div>
    </div>

    <!-- FILTER -->
    <div class="filter-card">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="id" value="<?= $distributor_id ?>">
            <div class="col-md-3">
                <label class="form-label fw-semibold" style="font-size:13px;">Record Type</label>
                <select name="mode" id="modeSelect" class="form-select">
                    <option value="current" <?= $mode=='current'?'selected':'' ?>>Single Date</option>
                    <option value="range"   <?= $mode=='range'?'selected':'' ?>>Date Range</option>
                </select>
            </div>
            <div class="col-md-3" id="singleDate">
                <label class="form-label fw-semibold" style="font-size:13px;">Date</label>
                <input type="date" name="date" value="<?= $date ?>" class="form-select">
            </div>
            <div class="col-md-4 d-none" id="rangeDate">
                <label class="form-label fw-semibold" style="font-size:13px;">Date Range</label>
                <div class="d-flex gap-2">
                    <input type="date" name="from" value="<?= $from ?>" class="form-control">
                    <input type="date" name="to"   value="<?= $to ?>"   class="form-control">
                </div>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100">Load</button>
            </div>
        </form>
    </div>

    <!-- SALES TABLE -->
    <div class="sales-card">
        <div class="sales-card-header">
            <h5>Sales — <?= htmlspecialchars($filterLabel) ?></h5>
            <div class="totals-row">
                <span class="total-pill qty"> <?= number_format($total_qty) ?> units sold</span>
                <span class="total-pill revenue">₱<?= number_format($total_revenue, 2) ?> revenue</span>
            </div>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Type</th>
                    <th>Unit</th>
                    <th>Qty Sold</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sales)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            No sales found for this period.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sales as $s):
                        $typeClass = $s['product_type'] === 'La Belleza' ? 'badge-labelleza'
                                   : ($s['product_type'] === 'By-Pcs Product' ? 'badge-bypcs' : 'badge-product');
                    ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($s['product_name']) ?></td>
                        <td><span class="badge-type <?= $typeClass ?>"><?= htmlspecialchars($s['product_type']) ?></span></td>
                        <td><span class="unit-badge"><?= $s['unit'] ?></span></td>
                        <td class="fw-bold"><?= number_format($s['quantity_sold']) ?></td>
                        <td style="color:#6b7280;"><?= $s['sale_date'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div><!-- /.main -->

<!-- EDIT PROFILE MODAL -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content p-4" style="border-radius:20px;border:none;">
            <h5 class="mb-4 fw-bold">Edit Distributor Profile</h5>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="edit_profile" value="1">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Full Name</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($distributor['name']) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Gender</label>
                        <select name="gender" class="form-select">
                            <option <?= $distributor['gender']=='Male'?'selected':'' ?>>Male</option>
                            <option <?= $distributor['gender']=='Female'?'selected':'' ?>>Female</option>
                            <option <?= $distributor['gender']=='Other'?'selected':'' ?>>Other</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Position</label>
                        <input type="text" name="position" class="form-control" value="<?= htmlspecialchars($distributor['position']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Contact Number</label>
                        <input type="text" name="contact_number" class="form-control" value="<?= htmlspecialchars($distributor['contact_number']) ?>">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Address</label>
                        <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($distributor['address']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Profile Photo (optional)</label>
                        <input type="file" name="photo" class="form-control" accept="image/*">
                    </div>
                </div>
                <div class="text-end mt-4 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const modeSelect = document.getElementById("modeSelect");
    const singleDate = document.getElementById("singleDate");
    const rangeDate  = document.getElementById("rangeDate");

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
    toggleMode();

    function addSaleRow() {
        let container = document.getElementById("saleItems");
        let row = document.createElement("div");
        row.className = "row g-3 mb-2 sale-row";
        row.innerHTML = `
            <div class="col-md-4">
                <select name="products[]" class="form-control product-select" onchange="setUnit(this)" required>
                    <option value="">Select Product</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" data-unit="<?= $p['unit_type'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="units[]" class="form-control unit-select" required><option value="">Unit</option></select>
            </div>
            <div class="col-md-2">
                <input type="number" name="quantities[]" class="form-control" min="1" required>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-danger w-100" onclick="this.closest('.sale-row').remove()">Remove</button>
            </div>`;
        container.appendChild(row);
    }

    function setUnit(select) {
        const unitType = select.options[select.selectedIndex].dataset.unit;
        const unitSelect = select.closest('.sale-row').querySelector('.unit-select');
        unitSelect.innerHTML = "";
        if (unitType === "BOTTLE") {
            unitSelect.innerHTML = `<option value="BOTTLE">BOTTLE</option>`;
            unitSelect.disabled = true;
        } else if (unitType === "PCS") {
            unitSelect.innerHTML = `<option value="PCS">PCS</option>`;
            unitSelect.disabled = true;
        } else {
            unitSelect.innerHTML = `<option value="BOX">BOX</option><option value="PCS">PCS</option>`;
            unitSelect.disabled = false;
        }
    }
</script>

<?php include 'chatbot.php'; ?>
<?php include 'mobile_sidebar.php'; ?>
<script>
function openLightbox(src) {
  document.getElementById('lbImg').src = src;
  document.getElementById('photoLightbox').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeLightbox() {
  document.getElementById('photoLightbox').classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeLightbox();
});
</script>
</body>
</html>
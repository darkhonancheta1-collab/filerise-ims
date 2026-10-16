<?php
/**
 * distributor.php — Distributor Management (CRUD)
 *
 * Manages the branch's distributor network. Features:
 *   - Add new distributors (name, contact, address, photo)
 *   - Edit distributor details
 *   - View all distributors in a card-based grid layout
 *   - Click a distributor card to go to distributor_detail.php
 *   - Photo uploads validated via security.php helpers
 *   - Activity logging for all CRUD operations
 *
 * Self-healing schema: auto-creates distributors table if missing.
 * Module-gated via branch_modules (distributors).
 */
require 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* -- Authentication guard -- */
if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

$user_id = $_SESSION['user_id'];
require_once 'branch_modules.php';
fr_require_module($pdo, 'distributors');
require_once 'activity_log.php';
$errors  = [];
$success = "";

$pdo->exec("
CREATE TABLE IF NOT EXISTS distributors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    gender VARCHAR(20) NOT NULL DEFAULT 'Not Set',
    position VARCHAR(100) NOT NULL DEFAULT '',
    contact_number VARCHAR(20),
    address TEXT,
    photo VARCHAR(255) DEFAULT NULL,
    user_id INT NOT NULL
)
");

/* RANKING SCOPE */
$scope = $_GET['scope'] ?? 'monthly';

if ($scope === 'alltime') {
    $rankQuery = $pdo->prepare("
        SELECT d.*,
               COALESCE(SUM(ds.quantity_sold), 0) AS total_sold,
               COALESCE(SUM(ds.quantity_sold * p.member_price), 0) AS total_revenue
        FROM distributors d
        LEFT JOIN distributor_sales ds ON d.id = ds.distributor_id AND ds.user_id = ?
        LEFT JOIN products p ON p.id = ds.product_id
        WHERE d.user_id = ?
        GROUP BY d.id
        ORDER BY total_sold DESC
    ");
} else {
    $rankQuery = $pdo->prepare("
        SELECT d.*,
               COALESCE(SUM(ds.quantity_sold), 0) AS total_sold,
               COALESCE(SUM(ds.quantity_sold * p.member_price), 0) AS total_revenue
        FROM distributors d
        LEFT JOIN distributor_sales ds
            ON d.id = ds.distributor_id
            AND ds.user_id = ?
            AND MONTH(ds.sale_date) = MONTH(CURDATE())
            AND YEAR(ds.sale_date) = YEAR(CURDATE())
        LEFT JOIN products p ON p.id = ds.product_id
        WHERE d.user_id = ?
        GROUP BY d.id
        ORDER BY total_sold DESC
    ");
}
$rankQuery->execute([$user_id, $user_id]);
$distributors = $rankQuery->fetchAll();

/* ADD NEW DISTRIBUTOR */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_distributor'])) {
    $name     = trim($_POST['name']);
    $gender   = trim($_POST['gender']);
    $position = 'Distributor'; // Always force position to "Distributor"
    $contact  = trim($_POST['contact']);
    $address  = trim($_POST['address']);
    $photo    = null;

    if (!$name || !$gender || !$contact || !$address)
        $errors[] = "Please fill all fields.";

    // CHECK FOR DUPLICATE NAME (case-insensitive, same user)
    if ($name) {
        $dupCheck = $pdo->prepare("SELECT COUNT(*) FROM distributors WHERE LOWER(name) = LOWER(?) AND user_id = ?");
        $dupCheck->execute([$name, $user_id]);
        if ($dupCheck->fetchColumn() > 0) {
            $errors[] = "A distributor with the name \"" . htmlspecialchars($name) . "\" already exists.";
        }
    }

    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $file    = $_FILES['photo'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png'])) {
            $errors[] = "Invalid photo format. JPG, JPEG, PNG only.";
        } else {
            $file_name = time() . "_" . rand(1000,9999) . "." . $ext;
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $file_name))
                $photo = $file_name;
            else
                $errors[] = "Failed to upload photo.";
        }
    }

    if (!$errors) {
        $pdo->prepare("INSERT INTO distributors (name,gender,position,contact_number,address,photo,user_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$name,$gender,$position,$contact,$address,$photo,$user_id]);
        $success = "Distributor added successfully!";
        fr_log_activity($pdo, 'distributors', 'add', "Added distributor \"{$name}\"");
        $rankQuery->execute([$user_id, $user_id]);
        $distributors = $rankQuery->fetchAll();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Distributors - Fil-Rise IMS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
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
    .sidebar .menu-title {
      font-size: 12px; text-transform: uppercase;
      letter-spacing: 1px; color: #94a3b8;
      margin-top: 20px; margin-bottom: 10px;
    }
    .sidebar a {
      color: #cbd5e1; text-decoration: none;
      padding: 10px 15px; border-radius: 10px;
      display: flex; align-items: center; gap: 10px;
      margin-bottom: 5px; transition: all 0.3s; font-size: 14px;
    }
    .sidebar a:hover { background: rgba(59,130,246,.2); color:#fff; }
    .sidebar a.active { background: linear-gradient(90deg,#2563eb,#3b82f6); color:#fff; }
    .logout {
      margin-top: auto; padding: 10px 15px; border-radius: 10px;
      background: rgba(239,68,68,.2); color:#cbd5e1; cursor: pointer; transition: .3s;
    }
    .logout:hover { background: rgba(239,68,68,.4); }

    /* MAIN */
    .main { margin-left: 260px; padding: 40px; flex: 1; background: #f1f5f9; min-height: 100vh; }

    /* PAGE HEADER */
    .page-header {
      display: flex; justify-content: space-between;
      align-items: center; margin-bottom: 24px;
    }
    .page-header h3 { font-size: 26px; font-weight: 700; color: #0f172a; margin: 0; }

    /* SCOPE TOGGLE */
    .scope-toggle {
      display: flex; background: #fff; border-radius: 12px;
      padding: 4px; box-shadow: 0 2px 8px rgba(0,0,0,.08); gap: 4px;
    }
    .scope-toggle a {
      padding: 7px 18px; border-radius: 9px; font-size: 13px;
      font-weight: 600; color: #64748b; text-decoration: none; transition: .2s;
    }
    .scope-toggle a.active {
      background: linear-gradient(135deg, #7b9b52, #5f7a3f);
      color: #fff; box-shadow: 0 2px 8px rgba(123,155,82,.35);
    }

    /* CARD */
    .card {
      border: none; border-radius: 20px; background: white;
      box-shadow: 0 4px 18px rgba(0,0,0,.07); margin-bottom: 30px;
    }

    /* TABLE */
    .table { margin: 0; }
    .table thead th {
      background: #f0fdf4; color: #374151;
      font-size: 13px; font-weight: 600;
      padding: 14px 16px; border-bottom: 2px solid #e5e7eb;
    }
    .table tbody tr { transition: background .15s; }
    .table tbody tr:hover { background: #f8fafc; }
    .table tbody td {
      padding: 12px 16px; vertical-align: middle;
      border-bottom: 1px solid #f1f5f9; font-size: 14px;
    }
    .table tbody tr:last-child td { border-bottom: none; }

    /* PHOTO */
    .dist-photo {
      width: 46px; height: 46px; border-radius: 50%;
      object-fit: cover; border: 2px solid #e5e7eb;
    }

    /* RANK BADGE */
    .rank-badge {
      display: inline-flex; align-items: center; justify-content: center;
      padding: 4px 12px; border-radius: 20px;
      font-size: 12px; font-weight: 700;
    }
    .rank-1 { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
    .rank-2 { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }
    .rank-3 { background: #fff7ed; color: #b45309; border: 1px solid #fed7aa; }
    .rank-other { background: #f8fafc; color: #94a3b8; border: 1px solid #e5e7eb; }

    /* SOLD COUNT */
    .sold-count { font-weight: 700; color: #111827; }
    .revenue-val { font-weight: 600; color: #7b9b52; font-size: 13px; }

    /* BUTTONS */
    .btn-primary {
      background: linear-gradient(135deg, #7b9b52, #5f7a3f);
      border: none; border-radius: 8px;
      font-size: 13px; font-weight: 600; padding: 6px 16px;
    }
    .btn-add {
      background: linear-gradient(135deg, #7b9b52, #5f7a3f);
      color: #fff; border: none; border-radius: 12px;
      padding: 10px 22px; font-size: 14px; font-weight: 600;
      cursor: pointer; transition: .2s; display: inline-flex; align-items: center; gap: 8px;
    }
    .btn-add:hover { opacity: .88; }

    /* ADD FORM */
    .form-card {
      background: #fff; border-radius: 20px; padding: 28px;
      box-shadow: 0 4px 18px rgba(0,0,0,.07); margin-bottom: 24px; display: none;
    }
    .form-card.open { display: block; animation: slideDown .3s ease; }
    @keyframes slideDown {
      from { opacity:0; transform:translateY(-10px); }
      to   { opacity:1; transform:translateY(0); }
    }

    /* READONLY FIELD */
    .form-control[readonly] {
      background: #f1f5f9;
      color: #6b7280;
      cursor: not-allowed;
    }
  </style>
</head>
<body>
<?php include 'ticker.php'; ?>

<div class="sidebar">
<?= fr_locked_style() ?>
  <img src="7.png" class="logo" alt="Fil-Rise Logo">
  <div class="menu-title">Core</div>
  <a href="dashboard.php">Dashboard</a>
  <a <?= fr_href('ai_forecast.php') ?> style="background:linear-gradient(90deg,rgba(6,182,212,.15),rgba(59,130,246,.15));color:#06b6d4;border:1px solid rgba(6,182,212,.25);">AI Dashboard</a>
  <div class="menu-title">Inventory</div>
  <a <?= fr_href('product.php') ?>>Products</a>
  <a <?= fr_href('removed_products.php') ?>>Archived Products</a>
  <div class="menu-title">Operations</div>
  <a <?= fr_href('transaction.php') ?>>Consignment Transactions</a>
  <a <?= fr_href('sales.php') ?>>Walk-in Sales</a>
  <a <?= fr_href('returns.php') ?>>Product Returns</a>
  <a <?= fr_href('stock_requests.php') ?>>Stock Requests</a>
  <a <?= fr_href('daily_records.php') ?>>Daily Records</a>
  <a <?= fr_href('daily_sales_record.php') ?>>Daily Sales Record</a>
  <a href="expenses.php">Expenses</a>
  <div class="menu-title">People</div>
  <div class="dropdown">
    <a href="#" class="dropdown-toggle" data-bs-toggle="collapse" data-bs-target="#distributorMenu">Distributors</a>
    <div class="collapse show" id="distributorMenu">
      <a <?= fr_href('distributor.php') ?> class="active ms-3">Distributor List</a>
      <a <?= fr_href('monthly_quota.php') ?> class="ms-3">Set Monthly Goal</a>
    </div>
  </div>
  <a <?= fr_href('members.php') ?>>Members</a>
  <div class="logout" onclick="window.location='logout.php'">Logout</div>
</div>

<div class="main">

  <div class="page-header">
    <div>
      <h3>Distributors</h3>
      <div style="font-size:13px;color:#6b7280;margin-top:2px;">
        <?= $scope === 'monthly' ? date('F Y') . ' Rankings' : 'All-Time Rankings' ?>
        — <?= count($distributors) ?> distributor<?= count($distributors) != 1 ? 's' : '' ?>
      </div>
    </div>
    <div class="d-flex align-items-center gap-3">
      <div class="scope-toggle">
        <a href="?scope=monthly" class="<?= $scope==='monthly'?'active':'' ?>">This Month</a>
        <a href="?scope=alltime" class="<?= $scope==='alltime'?'active':'' ?>">All Time</a>
      </div>
      <button class="btn-add" onclick="toggleForm()">＋ Add Distributor</button>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger mb-3"><?= implode('<br>', $errors) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success mb-3"><?= $success ?></div>
  <?php endif; ?>

  <!-- ADD FORM — stays open if there were errors so user doesn't lose their input -->
  <div class="form-card <?= $errors ? 'open' : '' ?>" id="addForm">
    <h5 class="mb-4" style="font-weight:700;">Add New Distributor</h5>
    <form method="post" enctype="multipart/form-data">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold">Full Name</label>
          <input type="text" name="name" class="form-control <?= isset($_POST['name']) && $errors ? 'is-invalid' : '' ?>"
                 value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">Gender</label>
          <select name="gender" class="form-select" required>
            <option value="">Select</option>
            <option <?= ($_POST['gender'] ?? '') === 'Male'   ? 'selected' : '' ?>>Male</option>
            <option <?= ($_POST['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
            <option <?= ($_POST['gender'] ?? '') === 'Other'  ? 'selected' : '' ?>>Other</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">Position</label>
          <input type="text" name="position" class="form-control" value="Distributor" readonly>
          <small class="text-muted">Fixed as Distributor</small>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Contact Number</label>
          <input type="number" name="contact" class="form-control"
                 value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Photo</label>
          <input type="file" name="photo" class="form-control" accept="image/*">
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">Address</label>
          <textarea name="address" class="form-control" rows="2" required><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
        </div>
        <div class="col-12 text-end">
          <button type="button" class="btn btn-secondary me-2" onclick="toggleForm()">Cancel</button>
          <button type="submit" name="add_distributor" class="btn-add">Save Distributor</button>
        </div>
      </div>
    </form>
  </div>

  <!-- DISTRIBUTOR TABLE -->
  <div class="card">
    <div class="card-body p-0">
      <table class="table">
        <thead>
          <tr>
            <th>Photo</th>
            <th>Name</th>
            <th>Position</th>
            <th>Sold</th>
            <th>Revenue</th>
            <th>Rank</th>
            <th>Manage</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($distributors): ?>
            <?php foreach ($distributors as $i => $d):
              $rank = $i + 1;
              $rankClass = $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : ($rank === 3 ? 'rank-3' : 'rank-other'));
              $rankLabel = $rank === 1 ? 'Top 1' : ($rank === 2 ? 'Top 2' : ($rank === 3 ? 'Top 3' : 'Top '.$rank));
            ?>
            <tr>
              <td>
                <img src="uploads/<?= $d['photo'] ?: 'default.png' ?>"
                     class="dist-photo" alt="<?= htmlspecialchars($d['name']) ?>">
              </td>
              <td class="fw-semibold"><?= htmlspecialchars($d['name']) ?></td>
              <td style="color:#6b7280;"><?= htmlspecialchars($d['position']) ?></td>
              <td><span class="sold-count"><?= number_format($d['total_sold']) ?></span></td>
              <td><span class="revenue-val">₱<?= number_format($d['total_revenue']) ?></span></td>
              <td><span class="rank-badge <?= $rankClass ?>"><?= $rankLabel ?></span></td>
              <td>
                <a href="distributor_detail.php?id=<?= $d['id'] ?>" class="btn btn-primary btn-sm">View</a>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="text-center text-muted py-5">No distributors yet.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  function toggleForm() {
    const f = document.getElementById('addForm');
    f.classList.toggle('open');
    if (f.classList.contains('open')) f.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
</script>
<?php include 'chatbot.php'; ?>
<?php include 'mobile_sidebar.php'; ?>
</body>
</html>
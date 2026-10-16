<?php
/* ============================================================
   EXPENSES.PHP — Branch Expense Tracking
   Lets staff log outgoing payments (bills, supplies, snacks,
   etc). Expenses are netted against sales so staff and the
   Super Admin can see the real take-home, not just gross sales.
   Not a lockable module — always accessible.
============================================================ */
require 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}

$user_id = $_SESSION['user_id'];
require_once 'branch_modules.php';
require_once 'activity_log.php';
$today   = date('Y-m-d');
$errors  = [];
$success = '';

/* ============================================================
   SELF-HEALING SCHEMA (safe to run repeatedly)
============================================================ */
$pdo->exec("
CREATE TABLE IF NOT EXISTS expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  category VARCHAR(50) NOT NULL,
  description VARCHAR(255) NULL,
  amount DECIMAL(10,2) NOT NULL,
  expense_date DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  INDEX (expense_date)
)
");

$categories = ['Electricity', 'Water', 'Internet/WiFi', 'Rent', 'Supplies', 'Snacks/Food', 'Transportation', 'Maintenance/Repairs', 'Other'];

/* ============================================================
   POST ACTIONS
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  /* ---------- ADD EXPENSE (deducts immediately, no approval) ---------- */
  if ($action === 'add_expense') {
    $category = clean_str($_POST['category'] ?? '', 50);
    $desc     = clean_str($_POST['description'] ?? '', 255);
    $amount   = (float) ($_POST['amount'] ?? 0);
    $date     = clean_str($_POST['expense_date'] ?? $today, 10);

    if (!in_array($category, $categories, true)) {
      $errors[] = 'Please choose a valid category.';
    }
    if ($amount <= 0) {
      $errors[] = 'Amount must be greater than 0.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      $date = $today;
    }

    if (!$errors) {
      $pdo->prepare("INSERT INTO expenses (user_id, category, description, amount, expense_date) VALUES (?,?,?,?,?)")
          ->execute([$user_id, $category, $desc, $amount, $date]);
      $success = 'Expense logged.';
      fr_log_activity($pdo, 'expenses', 'add', "Logged {$category} expense — ₱" . number_format($amount, 2) . ($desc ? " ({$desc})" : ''));
    }
  }

  /* ---------- DELETE EXPENSE (own branch only) ---------- */
  if ($action === 'delete_expense') {
    $id = clean_int($_POST['expense_id'] ?? 0);
    $delStmt = $pdo->prepare("SELECT category, amount FROM expenses WHERE id=? AND user_id=?");
    $delStmt->execute([$id, $user_id]);
    $delRow = $delStmt->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("DELETE FROM expenses WHERE id=? AND user_id=?")->execute([$id, $user_id]);
    $success = 'Expense removed.';
    if ($delRow) {
      fr_log_activity($pdo, 'expenses', 'delete', "Removed {$delRow['category']} expense — ₱" . number_format((float)$delRow['amount'], 2));
    }
  }
}

/* ============================================================
   TOTAL SALES TODAY (same source of truth as dashboard.php)
============================================================ */
$stmt = $pdo->prepare("
SELECT SUM(total) FROM (
  SELECT SUM(
    CASE WHEN ds.unit = 'PCS' AND p.unit_type = 'BOX'
      THEN ds.quantity_sold * CEIL(p.member_price / 10)
    ELSE ds.quantity_sold * p.member_price END
  ) AS total
  FROM distributor_sales ds
  JOIN products p ON p.id = ds.product_id
  WHERE ds.user_id=? AND DATE(ds.sale_date)=?

  UNION ALL

  SELECT SUM(wsi.quantity * wsi.price) AS total
  FROM walkin_sales_items wsi
  JOIN walkin_sales ws ON ws.id = wsi.sale_id
  WHERE ws.user_id=? AND DATE(ws.created_at)=?

  UNION ALL

  SELECT COALESCE(SUM(m.membership_fee), 0) AS total
  FROM members m
  WHERE m.user_id=? AND DATE(m.created_at)=?
    AND m.status='ACTIVE' AND m.package_product_id IS NOT NULL

  UNION ALL

  SELECT COALESCE(SUM(upgrade_income), 0) AS total
  FROM product_returns
  WHERE user_id=? AND return_type='UPGRADE' AND status='COMPLETED'
    AND DATE(returned_at)=?
) t
");
$stmt->execute([$user_id, $today, $user_id, $today, $user_id, $today, $user_id, $today]);
$salesToday = (float) ($stmt->fetchColumn() ?? 0);

/* ============================================================
   TOTAL SALES THIS MONTH (same source of truth as dashboard.php)
============================================================ */
$stmt = $pdo->prepare("
SELECT SUM(total) FROM (
  SELECT SUM(
    CASE WHEN ds.unit = 'PCS' AND p.unit_type = 'BOX'
      THEN ds.quantity_sold * CEIL(p.member_price / 10)
    ELSE ds.quantity_sold * p.member_price END
  ) AS total
  FROM distributor_sales ds
  JOIN products p ON p.id = ds.product_id
  WHERE ds.user_id=? AND MONTH(ds.sale_date)=MONTH(CURDATE()) AND YEAR(ds.sale_date)=YEAR(CURDATE())

  UNION ALL

  SELECT SUM(wsi.quantity * wsi.price) AS total
  FROM walkin_sales_items wsi
  JOIN walkin_sales ws ON ws.id = wsi.sale_id
  WHERE ws.user_id=? AND MONTH(ws.created_at)=MONTH(CURDATE()) AND YEAR(ws.created_at)=YEAR(CURDATE())

  UNION ALL

  SELECT COALESCE(SUM(m.membership_fee), 0) AS total
  FROM members m
  WHERE m.user_id=? AND m.status='ACTIVE'
    AND m.package_product_id IS NOT NULL
    AND MONTH(m.created_at)=MONTH(CURDATE()) AND YEAR(m.created_at)=YEAR(CURDATE())

  UNION ALL

  SELECT COALESCE(SUM(upgrade_income), 0) AS total
  FROM product_returns
  WHERE user_id=? AND return_type='UPGRADE' AND status='COMPLETED'
    AND MONTH(returned_at)=MONTH(CURDATE()) AND YEAR(returned_at)=YEAR(CURDATE())
) t
");
$stmt->execute([$user_id, $user_id, $user_id, $user_id]);
$salesMonth = (float) ($stmt->fetchColumn() ?? 0);

/* ============================================================
   EXPENSE TOTALS
============================================================ */
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id=? AND expense_date=?");
$stmt->execute([$user_id, $today]);
$expensesToday = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id=? AND MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())");
$stmt->execute([$user_id]);
$expensesMonth = (float) $stmt->fetchColumn();

$netToday = $salesToday - $expensesToday;
$netMonth = $salesMonth - $expensesMonth;

/* ============================================================
   EXPENSE HISTORY (this branch)
============================================================ */
$stmt = $pdo->prepare("SELECT * FROM expenses WHERE user_id=? ORDER BY expense_date DESC, created_at DESC LIMIT 200");
$stmt->execute([$user_id]);
$expenseList = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Expenses - Fil-Rise IMS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="7.png">
  <link rel="stylesheet" href="mobile.css">
  <style>
    body { font-family:'Poppins',sans-serif; min-height:100vh; margin:0; display:flex; color:#0f172a; background:#f4f6fb; }
    .sidebar { width:260px; background:linear-gradient(180deg,#0f172a,#111827); min-height:100vh; padding:25px 20px; position:fixed; display:flex; flex-direction:column; }
    .sidebar .logo { width:140px; margin-bottom:30px; }
    .sidebar .menu-title { font-size:12px; text-transform:uppercase; letter-spacing:1px; color:#94a3b8; margin-top:20px; margin-bottom:10px; }
    .sidebar a { color:#cbd5e1; text-decoration:none; padding:10px 15px; border-radius:10px; display:flex; align-items:center; gap:10px; margin-bottom:5px; transition:.3s; font-size:14px; }
    .sidebar a:hover { background:rgba(59,130,246,.2); color:#fff; }
    .sidebar a.active { background:linear-gradient(90deg,#2563eb,#3b82f6); color:#fff; }
    .logout { margin-top:auto; padding:10px 15px; border-radius:10px; background:rgba(239,68,68,.2); color:#cbd5e1; cursor:pointer; transition:.3s; }
    .logout:hover { background:rgba(239,68,68,.4); }
    .main { margin-left:260px; padding:40px; width:100%; }
    .kpi { background:#fff; border-radius:18px; box-shadow:0 10px 25px rgba(15,23,42,.06); padding:18px; }
    .kpi .num { font-size:22px; font-weight:800; }
    .kpi .lbl { font-size:12px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:.3px; }
    .kpi-section-title { font-size:13px; font-weight:700; color:#374151; margin:22px 0 10px; text-transform:uppercase; letter-spacing:.4px; }
    .text-net-pos { color:#16a34a; }
    .text-net-neg { color:#dc2626; }
    .card { border-radius:20px; }
    .cat-badge { padding:4px 10px; border-radius:999px; font-size:11px; font-weight:700; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; }
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
    <a href="expenses.php" class="active">Expenses</a>

    <div class="menu-title">People</div>
    <a <?= fr_href('distributor.php') ?>>Distributors</a>
    <a <?= fr_href('members.php') ?>>Members</a>

    <div class="logout" onclick="window.location='logout.php'">Logout</div>
  </div>

  <div class="main">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
      <div>
        <h3 class="fw-bold mb-0">Expenses</h3>
        <div class="text-muted" style="font-size:13px;">Log bills, supplies, and other outgoing payments — netted against sales below.</div>
      </div>
      <button class="btn btn-primary rounded-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
        + Log Expense
      </button>
    </div>

    <?php if ($success): ?>
      <div class="alert alert-success rounded-3"><?= e($success) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
      <div class="alert alert-danger rounded-3"><?= e($err) ?></div>
    <?php endforeach; ?>

    <!-- TODAY -->
    <div class="kpi-section-title">Today</div>
    <div class="row g-3 mb-2">
      <div class="col-6 col-md-4">
        <div class="kpi"><div class="lbl">Sales Today</div><div class="num">₱<?= number_format($salesToday, 2) ?></div></div>
      </div>
      <div class="col-6 col-md-4">
        <div class="kpi"><div class="lbl">Expenses Today</div><div class="num text-danger">₱<?= number_format($expensesToday, 2) ?></div></div>
      </div>
      <div class="col-6 col-md-4">
        <div class="kpi"><div class="lbl">Net Today</div><div class="num <?= $netToday >= 0 ? 'text-net-pos' : 'text-net-neg' ?>">₱<?= number_format($netToday, 2) ?></div></div>
      </div>
    </div>

    <!-- THIS MONTH -->
    <div class="kpi-section-title">This Month</div>
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-4">
        <div class="kpi"><div class="lbl">Sales This Month</div><div class="num">₱<?= number_format($salesMonth, 2) ?></div></div>
      </div>
      <div class="col-6 col-md-4">
        <div class="kpi"><div class="lbl">Expenses This Month</div><div class="num text-danger">₱<?= number_format($expensesMonth, 2) ?></div></div>
      </div>
      <div class="col-6 col-md-4">
        <div class="kpi"><div class="lbl">Net This Month</div><div class="num <?= $netMonth >= 0 ? 'text-net-pos' : 'text-net-neg' ?>">₱<?= number_format($netMonth, 2) ?></div></div>
      </div>
    </div>

    <!-- EXPENSE HISTORY -->
    <div class="card p-3">
      <div class="table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Logged</th><th style="width:90px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$expenseList): ?>
              <tr><td colspan="6" class="text-muted text-center py-4">No expenses logged yet.</td></tr>
            <?php else: foreach ($expenseList as $ex): ?>
              <tr>
                <td><?= date('M d, Y', strtotime($ex['expense_date'])) ?></td>
                <td><span class="cat-badge"><?= e($ex['category']) ?></span></td>
                <td><?= e($ex['description'] ?: '—') ?></td>
                <td class="fw-semibold text-danger">₱<?= number_format((float)$ex['amount'], 2) ?></td>
                <td style="font-size:12px;color:#6b7280;"><?= date('M d, g:i A', strtotime($ex['created_at'])) ?></td>
                <td>
                  <form method="post" onsubmit="return confirm('Remove this expense?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_expense">
                    <input type="hidden" name="expense_id" value="<?= (int)$ex['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">Remove</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ADD EXPENSE MODAL -->
  <div class="modal fade" id="addExpenseModal" tabindex="-1">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_expense">
        <div class="modal-header">
          <h5 class="modal-title">Log an Expense</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Category</label>
            <select name="category" class="form-select" required>
              <option value="">-- Select category --</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= e($c) ?>"><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Amount</label>
            <input type="number" name="amount" step="0.01" min="0.01" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Date</label>
            <input type="date" name="expense_date" class="form-control" value="<?= $today ?>" max="<?= $today ?>" required>
          </div>
          <div class="mb-1">
            <label class="form-label fw-semibold">Note (optional)</label>
            <textarea name="description" class="form-control" rows="2" maxlength="255" placeholder="e.g. Meralco bill for July"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary">Save Expense</button>
        </div>
      </form>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <?php include 'chatbot.php'; ?>
  <?php include 'mobile_sidebar.php'; ?>
</body>
</html>
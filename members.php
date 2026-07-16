<?php
require 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

$user_id = $_SESSION['user_id'];
$success = '';
$errors  = [];

/* ── Fetch defined packages (IMMUNO + Coffee combos) ── */
$stmt = $pdo->prepare("
  SELECT mp.id, mp.package_name, mp.total_price,
         p1.name AS immuno_name, p2.name AS coffee_name,
         p1.id AS immuno_id, p2.id AS coffee_id
  FROM member_packages mp
  JOIN products p1 ON p1.id = mp.product1_id
  JOIN products p2 ON p2.id = mp.product2_id
  WHERE mp.is_active = 1
  ORDER BY mp.package_name ASC
");
$stmt->execute();
$memberPackages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Also fetch IMMUNO and coffees for auto-generating packages if none exist
$stmt = $pdo->prepare("
  SELECT id, name, member_price FROM products
  WHERE user_id = ? AND is_active = 1
  AND name LIKE '%IMMUNO%'
  LIMIT 1
");
$stmt->execute([$user_id]);
$immunoProduct = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
  SELECT id, name, member_price FROM products
  WHERE user_id = ? AND is_active = 1
  AND (name LIKE '%COFFEE%' OR name LIKE '%KAPE%')
  ORDER BY name ASC
");
$stmt->execute([$user_id]);
$coffeeProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ── ADD MEMBER ── */
if (isset($_POST['add_member'])) {
  $name       = trim($_POST['full_name']);
  $age        = intval($_POST['age'] ?? 0);
  $address    = trim($_POST['address'] ?? '');

  if (!$name) {
    $errors[] = "Full name is required.";
  } else {
    // CHECK FOR DUPLICATE NAME (case-insensitive, same user)
    $dupCheck = $pdo->prepare("SELECT COUNT(*) FROM members WHERE LOWER(full_name) = LOWER(?) AND user_id = ?");
    $dupCheck->execute([$name, $user_id]);
    if ($dupCheck->fetchColumn() > 0) {
      $errors[] = "A member with the name \"" . htmlspecialchars($name) . "\" already exists.";
    }
  }

  if (!$errors && $name) {
    $package_id = intval($_POST['package_id'] ?? 0);
    if ($package_id <= 0) {
      $errors[] = "Please select a membership package.";
    } else {
      $pkg = $pdo->prepare("SELECT total_price, product1_id FROM member_packages WHERE id=?");
      $pkg->execute([$package_id]);
      $pkgRow = $pkg->fetch();
      $fee = $pkgRow ? (float)$pkgRow['total_price'] : 0;

      $stmt = $pdo->prepare("
        INSERT INTO members (user_id, full_name, age, address, membership_fee, status, package_product_id)
        VALUES (?, ?, ?, ?, ?, 'ACTIVE', ?)
      ");
      $stmt->execute([$user_id, $name, $age, $address, $fee, $package_id]);
      $success = "Member added successfully!";
    }
  }
}

/* ── FETCH MEMBERS ── */
$stmt = $pdo->prepare("
  SELECT m.*, mp.package_name, mp.total_price AS package_price
  FROM members m
  LEFT JOIN member_packages mp ON mp.id = m.package_product_id
  WHERE m.user_id = ?
  ORDER BY m.id DESC
");
$stmt->execute([$user_id]);
$members = $stmt->fetchAll();

// Stats
$totalMembers    = count(array_filter($members, fn($m) => $m['status']==='ACTIVE'));
$totalNonMembers = count(array_filter($members, fn($m) => $m['status']!=='ACTIVE'));
$totalRevenue    = array_sum(array_column(array_filter($members, fn($m) => $m['status']==='ACTIVE'), 'membership_fee'));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Members — Fil-Rise IMS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="mobile.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="7.png">
  <style>
    body { font-family:'Poppins',sans-serif; min-height:100vh; margin:0; display:flex; color:#0f172a; }

    .sidebar {
      width:260px; background:linear-gradient(180deg,#0f172a,#111827);
      min-height:100vh; padding:25px 20px; position:fixed;
      display:flex; flex-direction:column; overflow-y:auto;
    }
    .sidebar .logo { width:140px; margin-bottom:30px; }
    .sidebar .menu-title { font-size:12px; text-transform:uppercase; letter-spacing:1px; color:#94a3b8; margin-top:20px; margin-bottom:10px; }
    .sidebar a { color:#cbd5e1; text-decoration:none; padding:10px 15px; border-radius:10px; display:flex; align-items:center; gap:10px; margin-bottom:5px; transition:all .3s; font-size:14px; }
    .sidebar a:hover { background:rgba(59,130,246,.2); color:#fff; }
    .sidebar a.active { background:linear-gradient(90deg,#2563eb,#3b82f6); color:#fff; }
    .logout { margin-top:auto; padding:10px 15px; border-radius:10px; background:rgba(239,68,68,.2); color:#cbd5e1; cursor:pointer; transition:.3s; font-size:14px; }
    .logout:hover { background:rgba(239,68,68,.4); color:#fff; }

    .main { margin-left:260px; padding:40px; flex:1; }
    .card { border-radius:20px; }

    /* Stat cards */
    .stat-row { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:24px; }
    .stat-card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 4px 14px rgba(0,0,0,.07); }
    .stat-card-val { font-size:28px; font-weight:800; }
    .stat-card-lbl { font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:.4px; }

    /* Package badge */
    .pkg-badge {
      display:inline-flex; align-items:center; gap:6px;
      background:#eff6ff; color:#1d4ed8;
      border:1px solid #bfdbfe; border-radius:20px;
      font-size:11px; font-weight:700; padding:3px 10px;
    }
  </style>
</head>
<body>
<?php include 'ticker.php'; ?>

<div class="sidebar">
  <img src="7.png" class="logo" alt="Fil-Rise Logo">
  <div class="menu-title">Core</div>
  <a href="dashboard.php">Dashboard</a>
  <a href="ai_forecast.php" style="background:linear-gradient(90deg,rgba(6,182,212,.15),rgba(59,130,246,.15));color:#06b6d4;border:1px solid rgba(6,182,212,.25);">AI Dashboard</a>
  <div class="menu-title">Inventory</div>
  <a href="product.php">Products</a>
  <a href="removed_products.php">Archived Products</a>
  <div class="menu-title">Operations</div>
  <a href="transaction.php">Consignment Transactions</a>
  <a href="sales.php">Walk-in Sales</a>
  <a href="returns.php">Product Returns</a>
  <a href="stock_requests.php">Stock Requests</a>
  <a href="daily_records.php">Daily Records</a>
  <a href="daily_sales_record.php">Daily Sales Record</a>
  <div class="menu-title">People</div>
  <a href="distributor.php">Distributors</a>
  <a href="members.php" class="active">Members</a>
  <div class="logout" onclick="window.location='logout.php'">Logout</div>
</div>

<div class="main">
  <h3 class="fw-bold mb-4">Membership Management</h3>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?= implode("<br>", array_map('htmlspecialchars', $errors)) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <!-- STAT CARDS -->
  <div class="stat-row">
    <div class="stat-card" style="border-left:4px solid #16a34a;">
      <div class="stat-card-val" style="color:#16a34a;"><?= $totalMembers ?></div>
      <div class="stat-card-lbl">Active Members</div>
    </div>
    <div class="stat-card" style="border-left:4px solid #64748b;">
      <div class="stat-card-val" style="color:#64748b;"><?= $totalNonMembers ?></div>
      <div class="stat-card-lbl">Non-Members</div>
    </div>
    <div class="stat-card" style="border-left:4px solid #2563eb;">
      <div class="stat-card-val" style="color:#2563eb;">₱<?= number_format($totalRevenue,2) ?></div>
      <div class="stat-card-lbl">Total Membership Revenue</div>
    </div>
  </div>

  <!-- ADD BUTTON -->
  <button class="btn btn-primary mb-3" id="toggleForm">+ Add Member / Non-Member</button>

  <!-- ADD FORM — stays open if there were errors -->
  <div class="card p-4 mb-4" id="addForm" style="display:<?= !empty($errors) ? 'block' : 'none' ?>;">
    <form method="POST">
      <div class="row g-3">

        <div class="col-md-6">
          <label class="form-label fw-semibold">Full Name</label>
          <input class="form-control <?= !empty($errors) ? 'is-invalid' : '' ?>"
                 name="full_name"
                 value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                 placeholder="Enter full name" required>
        </div>

        <div class="col-md-3">
          <label class="form-label fw-semibold">Age</label>
          <input class="form-control" name="age" type="number" min="0"
                 value="<?= htmlspecialchars($_POST['age'] ?? '') ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label fw-semibold">Address</label>
          <textarea class="form-control" name="address" rows="2" placeholder="Optional"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
        </div>

        <!-- PACKAGE — always shown since all additions are Members -->
        <div class="col-12">
          <label class="form-label fw-semibold">
            Membership Package
            <span style="font-size:11px;color:#64748b;font-weight:400;">— IMMUNO paired with a Coffee</span>
          </label>

          <?php if (empty($memberPackages) && $immunoProduct && !empty($coffeeProducts)): ?>
            <div class="alert alert-info py-2" style="font-size:13px;">
              No packages configured yet. Please ask your admin to set up packages in phpMyAdmin using the SQL below.
            </div>
            <input type="hidden" name="package_id" value="0">
          <?php elseif (empty($memberPackages)): ?>
            <div class="alert alert-warning py-2" style="font-size:13px;">
              No packages available. Make sure IMMUNO and Coffee products are in your inventory and packages are configured.
            </div>
            <input type="hidden" name="package_id" value="0">
          <?php else: ?>
            <div class="row g-3" id="packageCards">
              <?php foreach ($memberPackages as $pkg): ?>
                <div class="col-md-6">
                  <label style="cursor:pointer;display:block;">
                    <input type="radio" name="package_id" value="<?= $pkg['id'] ?>"
                           data-price="<?= $pkg['total_price'] ?>"
                           style="display:none;" class="pkg-radio"
                           <?= (($_POST['package_id'] ?? '') == $pkg['id']) ? 'checked' : '' ?>>
                    <div class="pkg-card" style="
                      border:2px solid <?= (($_POST['package_id'] ?? '') == $pkg['id']) ? '#16a34a' : '#e2e8f0' ?>;
                      border-radius:14px; padding:16px; transition:.2s;
                      background:<?= (($_POST['package_id'] ?? '') == $pkg['id']) ? '#f0fdf4' : '#f8fafc' ?>;
                    ">
                      <div style="font-size:13px;font-weight:700;color:#0f172a;margin-bottom:8px;">
                         <?= htmlspecialchars($pkg['package_name']) ?>
                      </div>
                      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                        <span style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;">
                           <?= htmlspecialchars($pkg['immuno_name']) ?>
                        </span>
                        <span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;">
                           <?= htmlspecialchars($pkg['coffee_name']) ?>
                        </span>
                      </div>
                      <div style="font-size:24px;font-weight:800;color:#16a34a;">
                        ₱<?= number_format($pkg['total_price'],2) ?>
                      </div>
                      <div style="font-size:11px;color:#94a3b8;">Membership Fee — IMMUNO + Coffee Package</div>
                    </div>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
            <div id="packagePriceNote" style="font-size:13px;color:#16a34a;margin-top:10px;font-weight:700;display:<?= !empty($_POST['package_id']) ? 'block' : 'none' ?>;">
              ✓ Selected — Fee: <span id="packagePriceVal">₱0.00</span>
            </div>
          <?php endif; ?>
        </div>

        <div class="col-12 text-end">
          <button class="btn btn-success px-5 fw-semibold" name="add_member">Save</button>
        </div>
      </div>
    </form>
  </div>

  <!-- SEARCH -->
  <input type="text" id="searchInput" class="form-control mb-3" placeholder="🔍 Search member name...">

  <!-- TABLE -->
  <div class="card p-4">
    <table class="table table-hover align-middle">
      <thead class="table-success">
        <tr>
          <th>Name</th>
          <th>Age</th>
          <th>Address</th>
          <th>Package</th>
          <th>Fee</th>
          <th>Status</th>
          <th>Date Joined</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($members as $m): ?>
          <tr>
            <td class="fw-semibold"><?= htmlspecialchars($m['full_name']) ?></td>
            <td><?= $m['age'] ?: '—' ?></td>
            <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars($m['address']) ?: '—' ?></td>
            <td>
              <?php if (!empty($m['package_name'])): ?>
                <span class="pkg-badge"> <?= htmlspecialchars($m['package_name']) ?></span>
              <?php elseif ($m['status'] === 'ACTIVE'): ?>
                <span class="pkg-badge">Package</span>
              <?php else: ?>
                <span style="color:#94a3b8;font-size:12px;">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($m['membership_fee'] > 0): ?>
                <span style="font-weight:700;color:#16a34a;">₱<?= number_format($m['membership_fee'],2) ?></span>
              <?php else: ?>
                <span style="color:#94a3b8;">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($m['status']==='ACTIVE'): ?>
                <span class="badge bg-success">Member</span>
              <?php else: ?>
                <span class="badge bg-secondary">Non-Member</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;"><?= date("M d, Y", strtotime($m['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($members)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No members yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  // Toggle add form
  document.getElementById('toggleForm').onclick = function() {
    const f = document.getElementById('addForm');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
  };

  // Package card selection highlight
  document.querySelectorAll('.pkg-radio').forEach(radio => {
    radio.addEventListener('change', function() {
      // Reset all cards
      document.querySelectorAll('.pkg-card').forEach(card => {
        card.style.border = '2px solid #e2e8f0';
        card.style.background = '#f8fafc';
      });
      // Highlight selected
      const card = this.closest('label').querySelector('.pkg-card');
      if (card) {
        card.style.border = '2px solid #16a34a';
        card.style.background = '#f0fdf4';
      }
      // Show price
      const note = document.getElementById('packagePriceNote');
      const val  = document.getElementById('packagePriceVal');
      if (note && val) {
        val.textContent = '₱' + parseFloat(this.dataset.price).toLocaleString('en-PH', {minimumFractionDigits:2});
        note.style.display = 'block';
      }
    });
  });

  // Search
  document.getElementById('searchInput').addEventListener('keyup', function() {
    const v = this.value.toLowerCase();
    document.querySelectorAll('tbody tr').forEach(row => {
      row.style.display = row.textContent.toLowerCase().includes(v) ? '' : 'none';
    });
  });
</script>

<?php include 'chatbot.php'; ?>
<?php include 'mobile_sidebar.php'; ?>
</body>
</html>
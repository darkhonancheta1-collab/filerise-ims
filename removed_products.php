<?php
require 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }


if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}

$user_id = $_SESSION['user_id'];
$success = '';

/* =============================
   RESTORE PRODUCT
============================= */
if (isset($_POST['restore_product'])) {

  $id = intval($_POST['restore_id']);

  $pdo->prepare("
    UPDATE products 
    SET is_active=1 
    WHERE id=? AND user_id=?
  ")->execute([$id, $user_id]);

  $success = "✅ Product restored successfully!";
}

/* =============================
   FETCH REMOVED PRODUCTS
============================= */
$stmt = $pdo->prepare("
  SELECT * FROM products 
  WHERE user_id=? AND is_active=0 
  ORDER BY id DESC
");
$stmt->execute([$user_id]);
$products = $stmt->fetchAll();
?>

<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Removed Products - Fil-Rise IMS</title>
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

    .main {
      margin-left: 250px;
      padding: 40px;
      width: 100%;
    }

    .card {
      border-radius: 20px;
    }
  </style>
</head>

<body>
  <?php include 'ticker.php'; ?>

  <div class="sidebar">

    <img src="7.png" class="logo" alt="Fil-Rise Logo">

    <div class="menu-title">Core</div>
    <a href="dashboard.php"> Dashboard</a>
    <a href="ai_forecast.php"
      style="background:linear-gradient(90deg,rgba(6,182,212,.15),rgba(59,130,246,.15));color:#06b6d4;border:1px solid rgba(6,182,212,.25);">
      AI Dashboard</a>

    <div class="menu-title">Inventory</div>
    <a href="product.php"> Products</a>
    <a href="removed_products.php" class="active"> Archived Products</a>

    <div class="menu-title">Operations</div>
    <a href="transaction.php">Consignment Transactions</a>
    <a href="sales.php"> Walk-in Sales</a>
    <a href="returns.php">Product Returns</a>
    <a href="stock_requests.php">Stock Requests</a>
    <a href="daily_records.php"> Daily Records</a>
    <a href="daily_sales_record.php"> Daily Sales Record</a>

    <div class="menu-title">People</div>
    <a href="distributor.php"> Distributors</a>
    <a href="members.php"> Members</a>

    <div class="logout" onclick="window.location='logout.php'">
      Logout
    </div>

  </div>

  <div class="main">

    <h3>Archived Products</h3>

    <?php if ($success): ?>
      <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>

    <div class="card p-4">
      <table class="table table-bordered text-center align-middle">
        <thead class="table-success">
          <tr>
            <th>Name</th>
            <th>Member</th>
            <th>SRP</th>
            <th>Box</th>
            <th>Pcs</th>
            <th>Bottle</th>
            <th>Action</th>
          </tr>
        </thead>

        <tbody>

          <?php if (count($products) == 0): ?>
            <tr>
              <td colspan="8">No removed products found.</td>
            </tr>
          <?php endif; ?>

          <?php foreach ($products as $p): ?>
            <tr>

              <td><?= htmlspecialchars($p['name']) ?></td>
              <td><?= number_format($p['member_price'], 2) ?></td>
              <td><?= number_format($p['srp_price'], 2) ?></td>
              <td><?= $p['box_qty'] ?></td>
              <td><?= $p['pcs_qty'] ?></td>
              <td><?= $p['bottle_qty'] ?></td>
              <td>
                <form method="POST">
                  <input type="hidden" name="restore_id" value="<?= $p['id'] ?>">
                  <button class="btn btn-sm btn-success" name="restore_product"
                    onclick="return confirm('Restore this product?')">
                    Restore
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>

        </tbody>
      </table>
    </div>

  </div>

  <?php include 'chatbot.php'; ?>
  <?php include 'mobile_sidebar.php'; ?>
</body>

</html>
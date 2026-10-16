<?php
/**
 * product.php — Product Management (CRUD + Stock)
 *
 * Branch staff page for managing the product inventory:
 *   - Add new products (with image upload, prices, stock unit/quantity)
 *   - Edit existing products (name, prices, stock levels, image)
 *   - Add stock to existing products (box/pcs/bottle)
 *   - Archive/remove products (soft delete via is_active flag)
 *   - View all active products in a table grouped by product type
 *   - Low stock badges (Out of Stock / Low Stock / In Stock)
 *
 * All actions are logged to the activity_log via fr_log_activity().
 * Module access is controlled by branch_modules (fr_require_module).
 * Image uploads are validated via security.php helpers.
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
fr_require_module($pdo, 'products');
require_once 'activity_log.php';
$errors = [];
$success = '';
$imageName = null;

if (!empty($_FILES['image']['name'])) {

  $uploadDir = "uploads/products/";
  if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);  // 0755 not 0777
  }

  // Validate upload — checks mime type, extension, size, valid image
  $uploadErrors = validate_upload($_FILES['image'], ['jpg','jpeg','png','gif','webp'], 2);
  if (!empty($uploadErrors)) {
    $errors = array_merge($errors, $uploadErrors);
  } else {
    $imageName = safe_filename($_FILES['image']['name']);
    move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $imageName);
  }
}
/* =============================
   LOG PRICE CHANGE
============================= */
function logPriceChange(PDO $pdo, int $user_id, int $product_id, float $old_member, float $new_member, float $old_srp, float $new_srp): void
{

  if (round($old_member, 2) == round($new_member, 2) && round($old_srp, 2) == round($new_srp, 2))
    return;


}

/* =============================
   ADD OR UPDATE PRODUCT
============================= */
if (isset($_POST['save_product'])) {

  $name = strtoupper(trim($_POST['name']));
  $name = preg_replace('/\s+/', ' ', $name);

  $member_price = floatval($_POST['member_price']);
  $srp_price = floatval($_POST['srp_price']);

  /* AUTO COMPUTE PCS PRICE IF PRODUCT SOLD PER PCS */
  $pcs_per_box = 10;

  if ($_POST['stock_unit'] === 'BOX') {
    $pcs_price = ceil($member_price / $pcs_per_box);
  } else {
    $pcs_price = $member_price;
  }
  $product_type = trim($_POST['product_type']);
  $category = trim($_POST['category']);

  $stock_unit = $_POST['stock_unit'] ?? '';
  $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
  $unit_type = $_POST['stock_unit'];

  if (!$name || $member_price <= 0 || $srp_price <= 0) {
    $errors[] = "Please fill all fields correctly.";
  } elseif ($member_price > $srp_price) {
    $errors[] = "Member price cannot be higher than SRP price.";
  } else {

    $box = $pcs = $bottle = 0;
    if ($stock_unit === 'BOX')
      $box = $stock_quantity;
    if ($stock_unit === 'PCS')
      $pcs = $stock_quantity;
    if ($stock_unit === 'BOTTLE')
      $bottle = $stock_quantity;

    try {

      // 🔎 Check if product exists (active or archived)
      $check = $pdo->prepare("
                SELECT id FROM products
                WHERE user_id = ?
                AND name = ?
                LIMIT 1
            ");
      $check->execute([$user_id, $name]);
      $existing = $check->fetch(PDO::FETCH_ASSOC);

      if ($existing) {

        // 🔄 Reactivate + Update
        $pdo->prepare("
                    UPDATE products
                    SET
                        product_type = ?,
                        unit_type = ?,
                        member_price = ?,
                        srp_price = ?,
                        box_qty = box_qty + ?,
                        pcs_qty = pcs_qty + ?,
                        bottle_qty = bottle_qty + ?,
                        is_active = 1
                    WHERE id = ?
                ")->execute([
              $product_type,
              $unit_type,
              $member_price,
              $srp_price,
              $box,
              $pcs,
              $bottle,
              $existing['id']
            ]);
        fr_log_activity($pdo, 'products', 'update', "Restored/merged stock into product \"{$name}\"");

      } else {

        // ➕ Insert new
        $pdo->prepare("
                    INSERT INTO products
                    (image, user_id, name, product_type, category, unit_type, member_price, srp_price, box_qty, pcs_qty, bottle_qty, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ")->execute([
              $imageName,
              $user_id,
              $name,
              $product_type,
              $category,
              $unit_type,
              $pcs_price,
              $srp_price,
              $box,
              $pcs,
              $bottle
            ]);
        fr_log_activity($pdo, 'products', 'add', "Added new product \"{$name}\"");
      }

      $success = "Product added or restored successfully!";

    } catch (PDOException $e) {
      $errors[] = $e->getMessage();
    }
  }
}

if (isset($_POST['edit_product'])) {

  $id = intval($_POST['edit_id']);

  $name = strtoupper(trim($_POST['edit_name']));
  $name = preg_replace('/\s+/', ' ', $name);

  $member_price = floatval($_POST['edit_member_price']);
  $srp_price = floatval($_POST['edit_srp_price']);

  $box = intval($_POST['edit_box'] ?? 0);
  $pcs = intval($_POST['edit_pcs'] ?? 0);
  $bottle = intval($_POST['edit_bottle'] ?? 0);

  /* IMAGE UPLOAD */
  $newImage = null;

  if (!empty($_FILES['edit_image']['name'])) {

    $uploadDir = "uploads/products/";
    if (!is_dir($uploadDir)) {
      mkdir($uploadDir, 0755, true);
    }

    $uploadErrors = validate_upload($_FILES['edit_image'], ['jpg','jpeg','png','gif','webp'], 2);
    if (!empty($uploadErrors)) {
      $errors = array_merge($errors, $uploadErrors);
    } else {
      $newImage = safe_filename($_FILES['edit_image']['name']);
      move_uploaded_file($_FILES['edit_image']['tmp_name'], $uploadDir . $newImage);
    }
  }

  if (!$name) {
    $errors[] = "Product name cannot be empty.";
  }

  if ($member_price <= 0 || $srp_price <= 0) {
    $errors[] = "Prices must be greater than 0.";
  }

  if ($member_price > $srp_price) {
    $errors[] = "Member price cannot be higher than SRP price.";
  }

  if (!$errors) {

    $pdo->prepare("
      UPDATE products
      SET name=?, member_price=?, srp_price=?,
          box_qty=?, pcs_qty=?, bottle_qty=?,
          image = COALESCE(?, image)
      WHERE id=? AND user_id=?
    ")->execute([
          $name,
          $member_price,
          $srp_price,
          $box,
          $pcs,
          $bottle,
          $newImage,
          $id,
          $user_id
        ]);

    $success = "Product updated successfully!";
    fr_log_activity($pdo, 'products', 'update', "Edited product \"{$name}\"");
  }
}


/* =============================
   ADD NEW STOCK
============================= */
if (isset($_POST['add_stock'])) {

  $id = intval($_POST['stock_id']);
  $add_box = intval($_POST['add_box'] ?? 0);
  $add_pcs = intval($_POST['add_pcs'] ?? 0);
  $add_bottle = intval($_POST['add_bottle'] ?? 0);

  $pdo->prepare("
        UPDATE products
        SET 
            box_qty = box_qty + ?,
            pcs_qty = pcs_qty + ?,
            bottle_qty = bottle_qty + ?
        WHERE id=? AND user_id=?
    ")->execute([
        $add_box,
        $add_pcs,
        $add_bottle,
        $id,
        $user_id
      ]);

  $success = "New stock added successfully!";

  $nameStmt = $pdo->prepare("SELECT name FROM products WHERE id=? AND user_id=?");
  $nameStmt->execute([$id, $user_id]);
  $stockedName = $nameStmt->fetchColumn() ?: "product #{$id}";
  fr_log_activity($pdo, 'products', 'restock', "Added stock to \"{$stockedName}\" (+{$add_box} box, +{$add_pcs} pcs, +{$add_bottle} bottle)");
}

/* =============================
   REMOVE PRODUCT (SOFT DELETE)
============================= */
if (isset($_POST['remove_product'])) {

  $id = intval($_POST['remove_id']);

  $nameStmt = $pdo->prepare("SELECT name FROM products WHERE id=? AND user_id=?");
  $nameStmt->execute([$id, $user_id]);
  $removedName = $nameStmt->fetchColumn() ?: "product #{$id}";

  $pdo->prepare("
    UPDATE products 
    SET is_active=0 
    WHERE id=? AND user_id=?
  ")->execute([$id, $user_id]);

  $success = "🗑 Product removed successfully!";
  fr_log_activity($pdo, 'products', 'delete', "Archived/removed product \"{$removedName}\"");
}

/* =============================
   FETCH ONLY ACTIVE PRODUCTS
============================= */
$stmt = $pdo->prepare("
  SELECT * FROM products 
  WHERE user_id=? AND is_active=1 
  ORDER BY product_type ASC, name ASC
");
$stmt->execute([$user_id]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>


<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Products - Fil-Rise IMS</title>
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
      margin-left: 260px;
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
<?= fr_locked_style() ?>

    <img src="7.png" class="logo" alt="Fil-Rise Logo">

    <div class="menu-title">Core</div>
    <a href="dashboard.php"> Dashboard</a>
    <a <?= fr_href('ai_forecast.php') ?>
      style="background:linear-gradient(90deg,rgba(6,182,212,.15),rgba(59,130,246,.15));color:#06b6d4;border:1px solid rgba(6,182,212,.25);">
      AI Dashboard</a>

    <div class="menu-title">Inventory</div>
    <a <?= fr_href('product.php') ?> class="active"> Products</a>
    <a <?= fr_href('removed_products.php') ?>> Archived Products</a>

    <div class="menu-title">Operations</div>
    <a <?= fr_href('transaction.php') ?>>Consignment Transactions</a>
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

  <div class="main">
    
    <h3> <b>Add Products</b> </h3>

    <?php if ($errors): ?>
      <div class="alert alert-danger"><?= implode("<br>", array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addProductModal">
      + Add Product
    </button>

    <!-- ADD PRODUCT MODAL -->
    <div class="modal fade" id="addProductModal" tabindex="-1">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title">Add New Product</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">

            <form method="POST" enctype="multipart/form-data">

              <div class="col-md-3">
                <label class="form-label">Product Image</label>
                <input type="file" class="form-control" name="image" accept="image/*">
              </div>

              <div class="row g-3">

                <div class="col-md-4">
                  <label class="form-label">Product Name</label>
                  <input class="form-control" name="name" required>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Product Type</label>
                  <select class="form-select" name="product_type">
                    <option value="Product">Regular Product</option>
                    <option value="La Belleza">La Belleza</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Category</label>
                  <select class="form-select" name="category" required>

                    <option value="">Select Category</option>

                    <option value="Coffee">Coffee</option>
                    <option value="Health / Liniment / Oil">Herbal</option>
                    <option value="Supplements / Capsule">Capsule</option>
                    <option value="Fragrance">Fragrance</option>
                    <option value="Household">Household</option>
                    <option value="La Belleza">La Belleza</option>

                  </select>
                </div>

                <div class="col-md-2">
                  <label class="form-label">Member Price</label>
                  <input class="form-control" name="member_price" id="add_member_price" type="number" step="0.01" min="0.01" required oninput="validateAddPrices()">
                  <div id="add_price_error" class="text-danger mt-1" style="font-size:11px;display:none;">Member price cannot exceed SRP.</div>
                </div>

                <div class="col-md-2">
                  <label class="form-label">SRP Price</label>
                  <input class="form-control" name="srp_price" id="add_srp_price" type="number" step="0.01" min="0.01" required oninput="validateAddPrices()">
                </div>

              </div>

              <hr class="my-4">

              <h6 class="mb-3">Stock Quantity</h6>

              <div class="row g-3">

                <div class="col-md-4">
                  <label class="form-label">Product Unit Type</label>
                  <select class="form-select" name="stock_unit" required>
                    <option value="">Select Unit</option>
                    <option value="BOX">Box</option>
                    <option value="PCS">Pcs</option>
                    <option value="BOTTLE">Bottle</option>
                  </select>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Quantity</label>
                  <input class="form-control" type="number" name="stock_quantity" min="0" value="0" required>
                </div>

              </div>

              <div class="modal-footer mt-4">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-success" name="save_product">Add</button>
              </div>

            </form>

          </div>
        </div>
      </div>
    </div>


    <div class="card p-4">
      <table class="table table-bordered text-center align-middle">
        <thead class="table-success">
          <tr>
            <th>Image</th>
            <th>Name</th>
            <th>Category</th>
            <th>Member Price</th>
            <th>SRP</th>
            <th>Box</th>
            <th>Pcs</th>
            <th>Bottle</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>

        <tbody>
          <?php
          $currentType = null;
          foreach ($products as $p):

            if ($currentType !== $p['product_type']):
              $currentType = $p['product_type'];
              ?>
              <tr style="background:#f1f7f0; font-weight:bold;">
                <td colspan="9" style="text-align:left;">
                  <?= strtoupper(
                    $currentType === 'Product'
                    ? 'PRODUCT'
                    : ($currentType === 'La Belleza'
                      ? 'LA BELLEZA PRODUCTS'
                      : $currentType)
                  ) ?>
                </td>
              </tr>
            <?php endif; ?>
            <?php
            $totalStock = $p['box_qty'] + $p['pcs_qty'] + $p['bottle_qty'];
            ?>

            <tr>

              <td>
                <?php if (!empty($p['image'])): ?>
                  <img src="uploads/products/<?= htmlspecialchars($p['image']) ?>"
                    style="width:64px;height:64px;object-fit:contain;border-radius:10px;background:#f8fafc;border:1px solid #e2e8f0;cursor:pointer;display:block;margin:auto;"
                    onclick="showImage(this.src)">
                <?php else: ?>
                  <div style="width:64px;height:64px;border-radius:10px;background:#f1f5f9;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;font-size:10px;color:#94a3b8;margin:auto;font-weight:600;">NO IMG</div>
                <?php endif; ?>
              </td>

              <td><?= htmlspecialchars($p['name']) ?></td>
              <td><?= htmlspecialchars($p['category']) ?></td>
              <td><?= number_format((float) $p['member_price'], 2) ?></td>

              <td><?= number_format((float) $p['srp_price'], 2) ?></td>

              <td><?= (int) $p['box_qty'] ?></td>

              <td><?= (int) $p['pcs_qty'] ?></td>

              <td><?= (int) $p['bottle_qty'] ?></td>

              <td>
                <?php
                  // Determine low stock based on unit type
                  if ($p['unit_type']==='BOX')    $mainQty = $p['box_qty'];
                  elseif ($p['unit_type']==='PCS') $mainQty = $p['pcs_qty'];
                  else                             $mainQty = $p['bottle_qty'];
                ?>
                <?php if ($totalStock <= 0): ?>
                  <span class="badge bg-danger">Out of Stock</span>
                <?php elseif ($mainQty <= 10): ?>
                  <span class="badge" style="background:#f97316;">⚠ Low Stock</span>
                <?php else: ?>
                  <span class="badge bg-success">In Stock</span>
                <?php endif; ?>
              </td>

              <td>

                <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?= $p['id'] ?>">
                  Edit
                </button>

                <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#stockModal<?= $p['id'] ?>">
                  Add Stock
                </button>

                <form method="POST" style="display:inline;">
                  <input type="hidden" name="remove_id" value="<?= (int) $p['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" name="remove_product"
                    onclick="return confirm('Remove this product?')">
                    Archive
                  </button>
                </form>

              </td>

            </tr>

            <div class="modal fade" id="editModal<?= $p['id'] ?>">
              <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">

                  <div class="modal-header bg-warning">
                    <h5 class="modal-title">Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>

                  <div class="modal-body">

                    <form method="POST" enctype="multipart/form-data">

                      <input type="hidden" name="edit_id" value="<?= $p['id'] ?>">

                      <div class="row g-3">

                        <div class="col-md-4">
                          <label>Product Name</label>
                          <input class="form-control" name="edit_name" value="<?= htmlspecialchars($p['name']) ?>">
                        </div>

                        <div class="col-md-3">
                          <label>Member Price</label>
                          <input class="form-control" type="number" step="0.01" name="edit_member_price"
                            value="<?= $p['member_price'] ?>">
                        </div>

                        <div class="col-md-3">
                          <label>SRP Price</label>
                          <input class="form-control" type="number" step="0.01" name="edit_srp_price"
                            value="<?= $p['srp_price'] ?>">
                        </div>

                      </div>

                      <hr>

                      <div class="row g-3">

                        <?php if ($p['unit_type'] === 'BOX'): ?>

                          <div class="col-md-2">
                            <label>Box</label>
                            <input class="form-control" type="number" name="edit_box" value="<?= $p['box_qty'] ?>">
                          </div>

                          <div class="col-md-2">
                            <label>Pcs</label>
                            <input class="form-control" type="number" name="edit_pcs" value="<?= $p['pcs_qty'] ?>">
                          </div>

                        <?php elseif ($p['unit_type'] === 'PCS'): ?>

                          <div class="col-md-2">
                            <label>Pcs</label>
                            <input class="form-control" type="number" name="edit_pcs" value="<?= $p['pcs_qty'] ?>">
                          </div>

                        <?php elseif ($p['unit_type'] === 'BOTTLE'): ?>

                          <div class="col-md-2">
                            <label>Bottle</label>
                            <input class="form-control" type="number" name="edit_bottle" value="<?= $p['bottle_qty'] ?>">
                          </div>

                        <?php endif; ?>

                        <div class="col-md-4">
                          <label>Change Image</label>
                          <input type="file" class="form-control" name="edit_image">
                        </div>

                      </div>

                      <div class="modal-footer mt-3">
                        <button class="btn btn-success" name="edit_product">Save Changes</button>
                      </div>

                    </form>

                  </div>
                </div>
              </div>
            </div>

            <div class="modal fade" id="stockModal<?= $p['id'] ?>">
              <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius:16px;overflow:hidden;">

                  <div class="modal-header" style="background:linear-gradient(135deg,#0891b2,#06b6d4);color:#fff;">
                    <div>
                      <h5 class="modal-title mb-0">Add Stock</h5>
                      <div style="font-size:12px;opacity:.8;"><?= htmlspecialchars($p['name']) ?></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>

                  <div class="modal-body" style="padding:24px;">

                    <form method="POST">
                      <input type="hidden" name="stock_id" value="<?= $p['id'] ?>">
                      <!-- Current stock display -->
                      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:20px;">
                        <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Current Stock</div>
                        <div style="font-size:20px;font-weight:800;color:#0f172a;">
                          <?php if ($p['unit_type']==='BOX'): ?>
                            <?= $p['box_qty'] ?> <span style="font-size:13px;font-weight:500;color:#64748b;">boxes</span>
                            <?php if ($p['pcs_qty'] > 0): ?>
                              &nbsp;+&nbsp; <?= $p['pcs_qty'] ?> <span style="font-size:13px;font-weight:500;color:#64748b;">pcs</span>
                            <?php endif; ?>
                          <?php elseif ($p['unit_type']==='PCS'): ?>
                            <?= $p['pcs_qty'] ?> <span style="font-size:13px;font-weight:500;color:#64748b;">pcs</span>
                          <?php else: ?>
                            <?= $p['bottle_qty'] ?> <span style="font-size:13px;font-weight:500;color:#64748b;">bottles</span>
                          <?php endif; ?>
                        </div>
                      </div>

                      <!-- Only show the relevant unit -->
                      <?php if ($p['unit_type']==='BOX'): ?>
                        <div class="row g-3">
                          <div class="col-6">
                            <label class="form-label fw-semibold" style="font-size:13px;">Add Boxes 📦</label>
                            <input class="form-control form-control-lg" type="number"
                                   name="add_box" value="0" min="0"
                                   style="border-radius:10px;font-weight:700;font-size:18px;text-align:center;">
                          </div>
                          <div class="col-6">
                            <label class="form-label fw-semibold" style="font-size:13px;">Add Pcs (loose)</label>
                            <input class="form-control form-control-lg" type="number"
                                   name="add_pcs" value="0" min="0"
                                   style="border-radius:10px;font-weight:700;font-size:18px;text-align:center;">
                          </div>
                        </div>
                        <input type="hidden" name="add_bottle" value="0">
                        <div style="font-size:11px;color:#94a3b8;margin-top:8px;">1 box = 10 pcs. You can add both at the same time.</div>
                      <?php elseif ($p['unit_type']==='PCS'): ?>
                        <label class="form-label fw-semibold" style="font-size:13px;">Add Pcs</label>
                        <input class="form-control form-control-lg" type="number"
                               name="add_pcs" value="0" min="0"
                               style="border-radius:10px;font-weight:700;font-size:22px;text-align:center;">
                        <input type="hidden" name="add_box"    value="0">
                        <input type="hidden" name="add_bottle" value="0">
                      <?php else: ?>
                        <label class="form-label fw-semibold" style="font-size:13px;">Add Bottles 🧴</label>
                        <input class="form-control form-control-lg" type="number"
                               name="add_bottle" value="0" min="0"
                               style="border-radius:10px;font-weight:700;font-size:22px;text-align:center;">
                        <input type="hidden" name="add_box" value="0">
                        <input type="hidden" name="add_pcs" value="0">
                      <?php endif; ?>

                      <div class="d-grid mt-4">
                        <button class="btn btn-success btn-lg fw-bold" name="add_stock"
                                style="border-radius:12px;">
                          ✓ Add Stock
                        </button>
                      </div>

                    </form>

                  </div>
                </div>
              </div>
            </div>
            <!-- EDIT ROW -->
            <tr id="editRow<?= (int) $p['id'] ?>" style="display:none;">
              <td colspan="8">
                <form method="POST" enctype="multipart/form-data" class="row g-2">

                  <input type="hidden" name="edit_id" value="<?= (int) $p['id'] ?>">

                  <div class="col-md-2">
                    <input class="form-control" name="edit_name" value="<?= htmlspecialchars($p['name']) ?>">
                  </div>

                  <div class="col-md-2">
                    <input class="form-control price-field" type="number" step="0.01" min="0.01" name="edit_member_price"
                      value="<?= htmlspecialchars($p['member_price']) ?>" placeholder="Member Price">
                  </div>

                  <div class="col-md-2">
                    <input class="form-control price-field" type="number" step="0.01" min="0.01" name="edit_srp_price"
                      value="<?= htmlspecialchars($p['srp_price']) ?>" placeholder="SRP Price">
                  </div>


                  <?php if ($p['unit_type'] === 'BOX'): ?>

                    <div class="col-md-1">
                      <input class="form-control" type="number" name="edit_box" value="<?= (int) $p['box_qty'] ?>">
                    </div>

                    <div class="col-md-1">
                      <input class="form-control" type="number" name="edit_pcs" value="<?= (int) $p['pcs_qty'] ?>">
                    </div>

                    <div class="col-md-1">
                      <input class="form-control" type="number" value="0" disabled>
                    </div>

                  <?php elseif ($p['unit_type'] === 'PCS'): ?>

                    <div class="col-md-1">
                      <input class="form-control" type="number" value="0" disabled>
                    </div>

                    <div class="col-md-1">
                      <input class="form-control" type="number" name="edit_pcs" value="<?= (int) $p['pcs_qty'] ?>">
                    </div>

                    <div class="col-md-1">
                      <input class="form-control" type="number" value="0" disabled>
                    </div>

                  <?php elseif ($p['unit_type'] === 'BOTTLE'): ?>

                    <div class="col-md-1">
                      <input class="form-control" type="number" value="0" disabled>
                    </div>

                    <div class="col-md-1">
                      <input class="form-control" type="number" value="0" disabled>
                    </div>

                    <div class="col-md-1">
                      <input class="form-control" type="number" name="edit_bottle" value="<?= (int) $p['bottle_qty'] ?>">
                    </div>

                  <?php endif; ?>


                  <div class="col-md-2">
                    <label style="font-size:12px;">Change Image</label>
                    <input type="file" class="form-control" name="edit_image" accept="image/*">
                  </div>

                  <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-success" name="edit_product">Save Changes</button>
                  </div>
      </div>

      </form>
      </td>
      </tr>

      <!-- ADD STOCK ROW -->
      <tr id="stockRow<?= (int) $p['id'] ?>" style="display:none;">
        <td colspan="8">

          <form method="POST" enctype="multipart/form-data" class="row g-2">

            <input type="hidden" name="stock_id" value="<?= (int) $p['id'] ?>">

            <?php if ($p['unit_type'] === 'BOX'): ?>

              <div class="col-md-3">
                Add Box
                <input class="form-control" type="number" name="add_box" value="0">
              </div>

              <div class="col-md-3">
                Add Pcs
                <input class="form-control" type="number" name="add_pcs" value="0">
              </div>

            <?php elseif ($p['unit_type'] === 'PCS'): ?>

              <div class="col-md-3">
                Add Pcs
                <input class="form-control" type="number" name="add_pcs" value="0">
              </div>

            <?php elseif ($p['unit_type'] === 'BOTTLE'): ?>

              <div class="col-md-3">
                Add Bottle
                <input class="form-control" type="number" name="add_bottle" value="0">
              </div>

            <?php endif; ?>

            <div class="col-md-2 d-flex align-items-end">
              <button class="btn btn-success" name="add_stock">
                Add Stock
              </button>
            </div>

          </form>

        </td>
      </tr>

    <?php endforeach; ?>
    </tbody>
    </table>
  </div>

  </div>

  <script>
    function toggleForm() {
      let form = document.getElementById("addForm");
      form.style.display = form.style.display === "none" ? "block" : "none";
    }
    function toggleEdit(id) {
      let row = document.getElementById("editRow" + id);
      row.style.display = row.style.display === "none" ? "table-row" : "none";
    }
    function toggleStock(id) {
      let row = document.getElementById("stockRow" + id);
      row.style.display = row.style.display === "none" ? "table-row" : "none";
    }

    /* Auto format prices to 2 decimals + prevent negative */
    document.addEventListener("DOMContentLoaded", () => {
      document.querySelectorAll(".price-field").forEach(inp => {
        inp.addEventListener("blur", () => {
          if (inp.disabled) return;

          let v = parseFloat(inp.value);
          if (isNaN(v) || v <= 0) {
            inp.value = "";
            return;
          }
          inp.value = v.toFixed(2);
        });

        inp.addEventListener("input", () => {
          if (inp.disabled) return;
          // block negative typing
          if (inp.value.startsWith("-")) inp.value = inp.value.replace("-", "");
        });
      });
    });

    function toggleForm() {
      let form = document.getElementById("addForm");
      form.style.display = form.style.display === "none" ? "block" : "none";
    }
    function showImage(src) {
      document.getElementById("previewImg").src = src;
      new bootstrap.Modal(document.getElementById("imageModal")).show();
    }

    /* ── PRICE VALIDATION: member price must not exceed SRP ── */
    function validateAddPrices() {
      const member = parseFloat(document.getElementById('add_member_price').value);
      const srp    = parseFloat(document.getElementById('add_srp_price').value);
      const err    = document.getElementById('add_price_error');
      const btn    = document.querySelector('#addProductModal button[name="save_product"]');
      if (!isNaN(member) && !isNaN(srp) && member > srp) {
        err.style.display = 'block';
        if (btn) btn.disabled = true;
      } else {
        err.style.display = 'none';
        if (btn) btn.disabled = false;
      }
    }

    /* ── EDIT MODAL PRICE VALIDATION ── */
    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('[id^="editModal"]').forEach(modal => {
        const memberInput = modal.querySelector('[name="edit_member_price"]');
        const srpInput    = modal.querySelector('[name="edit_srp_price"]');
        const saveBtn     = modal.querySelector('[name="edit_product"]');
        if (!memberInput || !srpInput) return;

        let errDiv = document.createElement('div');
        errDiv.style.cssText = 'color:#dc2626;font-size:11px;margin-top:4px;display:none;';
        errDiv.textContent   = 'Member price cannot exceed SRP price.';
        memberInput.parentNode.appendChild(errDiv);

        function checkEditPrices() {
          const m = parseFloat(memberInput.value);
          const s = parseFloat(srpInput.value);
          if (!isNaN(m) && !isNaN(s) && m > s) {
            errDiv.style.display = 'block';
            if (saveBtn) saveBtn.disabled = true;
          } else {
            errDiv.style.display = 'none';
            if (saveBtn) saveBtn.disabled = false;
          }
        }
        memberInput.addEventListener('input', checkEditPrices);
        srpInput.addEventListener('input', checkEditPrices);
      });
    });
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <div class="modal fade" id="imageModal">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-body text-center" style="padding:16px;">
          <img id="previewImg" style="max-width:100%;max-height:70vh;object-fit:contain;border-radius:10px;">
        </div>
      </div>
    </div>
  </div>

<?php include 'chatbot.php'; ?>
<?php include 'mobile_sidebar.php'; ?>
</body>

</html>
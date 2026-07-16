<?php
require '../config.php';
$pageTitle = "View Report";
$activePage = "reports";
require __DIR__ . "/_layout_top.php";

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die("Invalid report.");

$stmt = $pdo->prepare("SELECT * FROM branch_reports WHERE id=?");
$stmt->execute([$id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$report) die("Report not found.");

$items = [];
if (!empty($report['report_data'])) {
  $decoded = json_decode($report['report_data'], true);
  if (is_array($decoded)) $items = $decoded;
}

// helper
function peso($n){ return "₱" . number_format((float)$n, 2); }
?>

<h3 class="fw-bold mb-3">Branch Report</h3>

<div class="cardx p-3 mb-3">
  <div class="row g-2">
    <div class="col-md-4"><strong>Branch:</strong> <?= htmlspecialchars($report['branch_name']) ?></div>
    <div class="col-md-4"><strong>Date:</strong> <?= date("F d, Y", strtotime($report['report_date'])) ?></div>
    <div class="col-md-4"><strong>Total Sales:</strong> <?= peso($report['total_sales']) ?></div>
  </div>
  <div class="mt-2 text-muted"><strong>Transactions:</strong> <?= (int)$report['total_transactions'] ?></div>
</div>

<div class="cardx p-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h5 class="fw-bold m-0">Inventory Sales Table</h5>
    <a class="btn btn-sm btn-outline-secondary" href="admin_reports.php">Back</a>
  </div>

  <div class="table-responsive">
    <table class="table table-bordered align-middle">
      <thead class="table-light">
        <tr>
          <th>Product</th>
          <th class="text-end">Price</th>
          <th class="text-center">Boxes Sold</th>
          <th class="text-center">Pcs Sold</th>
          <th class="text-center">Bottles Sold</th>
          <th class="text-end">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$items): ?>
          <tr>
            <td colspan="6" class="text-muted text-center">
              No detailed inventory data available for this report.
              <br>Make sure daily_records.php is saving report_data.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= htmlspecialchars($it['name'] ?? '') ?></td>
              <td class="text-end"><?= peso($it['price'] ?? 0) ?></td>
              <td class="text-center"><?= (int)($it['sold_box'] ?? 0) ?></td>
              <td class="text-center"><?= (int)($it['sold_pcs'] ?? 0) ?></td>
              <td class="text-center"><?= (int)($it['sold_bottle'] ?? 0) ?></td>
              <td class="text-end"><?= peso($it['amount'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . "/_layout_bottom.php"; ?>
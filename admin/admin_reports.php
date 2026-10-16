<?php
/**
 * admin/admin_reports.php — Admin Branch Daily Reports Overview
 *
 * Lists all end-of-day inventory and sales reports submitted by branch staff.
 * Allows admins to view submission timestamps, total sales, transaction counts,
 * and drill into detailed item-by-item breakdown per report.
 */
require '../config.php';
$pageTitle = "Branch Reports";
$activePage = "reports";
require __DIR__ . "/_layout_top.php";

// show latest first
$stmt = $pdo->prepare("
  SELECT id, user_id, branch_name, report_date, total_sales, total_transactions, status, submitted_at
  FROM branch_reports
  ORDER BY report_date DESC, id DESC
");
$stmt->execute();
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h3 class="fw-bold mb-3">Branch Daily Reports</h3>

<div class="cardx p-3">
  <div class="table-responsive">
    <table class="table table-bordered align-middle">
      <thead class="table-light">
        <tr>
          <th>Branch</th>
          <th>Date</th>
          <th class="text-end">Total Sales</th>
          <th class="text-center">Transactions</th>
          <th class="text-center">Status</th>
          <th class="text-center" style="width:120px;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$reports): ?>
          <tr>
            <td colspan="6" class="text-muted">No reports submitted yet.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($reports as $r): ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($r['branch_name'] ?: ("Branch #" . $r['user_id'])) ?></td>
              <td>
                <?= date("F d, Y", strtotime($r['report_date'])) ?><br>
                <small class="text-muted">
                  <?= date("h:i A", strtotime($r['submitted_at'])) ?>
                </small>
              </td>
              <td class="text-end">₱<?= number_format((float) $r['total_sales'], 2) ?></td>
              <td class="text-center"><?= (int) $r['total_transactions'] ?></td>
              <td class="text-center">
                <?php if (($r['status'] ?? '') === 'Undone'): ?>
                  <span class="badge bg-danger">Undone</span>
                <?php else: ?>
                  <?php
                  $submittedTime = strtotime($r['submitted_at']);
                  $deadline = strtotime($r['report_date'] . ' 18:00:00');

                  if ($submittedTime > $deadline) {
                    echo '<span class="badge bg-warning">Late Submitted</span>';
                  } else {
                    echo '<span class="badge bg-success">Submitted</span>';
                  }
                  ?>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <a class="btn btn-sm btn-primary" href="view_report.php?id=<?= (int) $r['id'] ?>">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . "/_layout_bottom.php"; ?>
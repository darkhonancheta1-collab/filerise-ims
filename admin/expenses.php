<?php
/* ============================================================
   ADMIN / EXPENSES.PHP
   Main Admin view of ALL branch expenses — totals, per-branch
   breakdown, and full expense log across the company.
============================================================ */
require '../config.php';

/* Self-healing schema (harmless if already created by expenses.php) */
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

/* Branch filter */
$branches = $pdo->query("
    SELECT DISTINCT branch_name FROM users
    WHERE branch_name IS NOT NULL AND branch_name <> ''
    ORDER BY branch_name ASC
")->fetchAll(PDO::FETCH_COLUMN);

$selectedBranch = $_GET['branch'] ?? 'ALL';

/* Totals across ALL branches (today / this month) */
$totalTodayAll = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date = CURDATE()")->fetchColumn();
$totalMonthAll = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())")->fetchColumn();

/* Per-branch breakdown, this month */
$perBranch = $pdo->query("
    SELECT u.branch_name,
           COALESCE(SUM(CASE WHEN MONTH(e.expense_date)=MONTH(CURDATE()) AND YEAR(e.expense_date)=YEAR(CURDATE()) THEN e.amount ELSE 0 END),0) AS month_total,
           COALESCE(SUM(CASE WHEN e.expense_date = CURDATE() THEN e.amount ELSE 0 END),0) AS today_total
    FROM users u
    LEFT JOIN expenses e ON e.user_id = u.id
    WHERE u.branch_name IS NOT NULL AND u.branch_name <> ''
    GROUP BY u.branch_name
    ORDER BY u.branch_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* Expense log (filtered by branch, or all) */
if ($selectedBranch !== 'ALL' && in_array($selectedBranch, $branches, true)) {
    $stmt = $pdo->prepare("
        SELECT e.*, u.branch_name, u.fullname
        FROM expenses e
        JOIN users u ON u.id = e.user_id
        WHERE u.branch_name = ?
        ORDER BY e.expense_date DESC, e.created_at DESC
        LIMIT 300
    ");
    $stmt->execute([$selectedBranch]);
} else {
    $stmt = $pdo->query("
        SELECT e.*, u.branch_name, u.fullname
        FROM expenses e
        JOIN users u ON u.id = e.user_id
        ORDER BY e.expense_date DESC, e.created_at DESC
        LIMIT 300
    ");
}
$expenseLog = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle  = 'Expenses';
$activePage = 'expenses';
require __DIR__ . '/_layout_top.php';
?>

<style>
  .ex-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:24px; }
  .ex-pills { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:20px; }
  .ex-pill { padding:8px 16px; border-radius:999px; border:1.5px solid #e2e8f0; background:#f8fafc; font-size:13px; font-weight:600; color:#374151; text-decoration:none; }
  .ex-pill.active { background:#0f172a; color:#fff; border-color:#0f172a; }
  .cat-badge { padding:4px 10px; border-radius:999px; font-size:11px; font-weight:700; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; }
</style>

<div class="topbar">
  <div>
    <h3 class="fw-bold mb-0">Expenses</h3>
    <div style="color:var(--muted);font-size:13px;">Company-wide view of branch bills, supplies, and other outgoing payments.</div>
  </div>
</div>

<div class="ex-grid">
  <div class="cardx p-3">
    <div class="kpi-title">All Branches — Expenses Today</div>
    <div class="kpi-value" style="color:#dc2626;">₱<?= number_format($totalTodayAll, 2) ?></div>
  </div>
  <div class="cardx p-3">
    <div class="kpi-title">All Branches — Expenses This Month</div>
    <div class="kpi-value" style="color:#dc2626;">₱<?= number_format($totalMonthAll, 2) ?></div>
  </div>
  <div class="cardx p-3">
    <div class="kpi-title">Branches Tracked</div>
    <div class="kpi-value"><?= count($branches) ?></div>
  </div>
</div>

<div class="cardx p-3 mb-4">
  <h6 class="fw-bold mb-3">This Month, By Branch</h6>
  <?php if (empty($perBranch)): ?>
    <p style="color:var(--muted);font-size:14px;">No branches found yet.</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr><th>Branch</th><th>Expenses Today</th><th>Expenses This Month</th></tr>
        </thead>
        <tbody>
          <?php foreach ($perBranch as $b): ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($b['branch_name']) ?></td>
              <td style="color:#dc2626;">₱<?= number_format((float)$b['today_total'], 2) ?></td>
              <td style="color:#dc2626;">₱<?= number_format((float)$b['month_total'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="cardx p-3">
  <h6 class="fw-bold mb-3">Expense Log</h6>
  <div class="ex-pills">
    <a class="ex-pill <?= $selectedBranch === 'ALL' ? 'active' : '' ?>" href="?branch=ALL">All Branches</a>
    <?php foreach ($branches as $b): ?>
      <a class="ex-pill <?= $selectedBranch === $b ? 'active' : '' ?>" href="?branch=<?= urlencode($b) ?>"><?= htmlspecialchars($b) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="table-responsive">
    <table class="table align-middle">
      <thead class="table-light">
        <tr><th>Date</th><th>Branch</th><th>Logged By</th><th>Category</th><th>Description</th><th>Amount</th></tr>
      </thead>
      <tbody>
        <?php if (!$expenseLog): ?>
          <tr><td colspan="6" class="text-muted text-center py-4">No expenses logged yet.</td></tr>
        <?php else: foreach ($expenseLog as $ex): ?>
          <tr>
            <td><?= date('M d, Y', strtotime($ex['expense_date'])) ?></td>
            <td><?= htmlspecialchars($ex['branch_name']) ?></td>
            <td><?= htmlspecialchars($ex['fullname'] ?? '—') ?></td>
            <td><span class="cat-badge"><?= htmlspecialchars($ex['category']) ?></span></td>
            <td><?= htmlspecialchars($ex['description'] ?: '—') ?></td>
            <td class="fw-semibold" style="color:#dc2626;">₱<?= number_format((float)$ex['amount'], 2) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . "/_layout_bottom.php"; ?>
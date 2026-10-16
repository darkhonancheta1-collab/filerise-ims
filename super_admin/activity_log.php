<?php
// super_admin/activity_log.php — Super Admin only: full audit trail of
// what every branch has done (products, sales, distributors, returns,
// members, stock requests, expenses).
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require '../config.php';
require __DIR__ . '/auth.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    branch_name VARCHAR(150) NOT NULL,
    module      VARCHAR(50) NOT NULL,
    action      VARCHAR(50) NOT NULL,
    description VARCHAR(500) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (branch_name),
    INDEX (module),
    INDEX (created_at)
)");

$moduleLabels = [
    'products'       => 'Products',
    'sales'          => 'Walk-in Sales',
    'distributors'   => 'Distributors',
    'transactions'   => 'Transactions',
    'returns'        => 'Returns',
    'members'        => 'Members',
    'stock_requests' => 'Stock Requests',
    'expenses'       => 'Expenses',
];

/* ── Filters ── */
$branches = $pdo->query("
    SELECT DISTINCT branch_name FROM users
    WHERE branch_name IS NOT NULL AND branch_name <> ''
    ORDER BY branch_name ASC
")->fetchAll(PDO::FETCH_COLUMN);

$selectedBranch = $_GET['branch'] ?? 'ALL';
$selectedModule = $_GET['module'] ?? 'ALL';
$search         = trim($_GET['q'] ?? '');

$where  = [];
$params = [];

if ($selectedBranch !== 'ALL' && in_array($selectedBranch, $branches, true)) {
    $where[]  = 'branch_name = ?';
    $params[] = $selectedBranch;
}
if ($selectedModule !== 'ALL' && isset($moduleLabels[$selectedModule])) {
    $where[]  = 'module = ?';
    $params[] = $selectedModule;
}
if ($search !== '') {
    $where[]  = 'description LIKE ?';
    $params[] = '%' . $search . '%';
}

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT * FROM activity_log $whereSQL ORDER BY created_at DESC LIMIT 300");
$stmt->execute($params);
$logEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Quick totals for the header strip */
$totalToday = (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$totalAll   = (int) $pdo->query("SELECT COUNT(*) FROM activity_log")->fetchColumn();

$activePage = 'activity_log';
$pageTitle  = 'Activity Log';
include '_layout_top.php';
?>

<style>
  .al-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:24px; }
  .al-stat { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px; }
  .al-stat-num { font-size:26px; font-weight:800; color:#0f172a; }
  .al-stat-label { font-size:12px; color:#94a3b8; font-weight:600; margin-top:4px; }
  .al-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px; }
  .al-filters { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:18px; align-items:center; }
  .al-select { border:1.5px solid #e2e8f0; border-radius:10px; padding:8px 12px; font-size:13px; font-weight:600; color:#374151; background:#f8fafc; }
  .al-search { border:1.5px solid #e2e8f0; border-radius:10px; padding:8px 12px; font-size:13px; flex:1; min-width:180px; }
  .mod-badge { padding:4px 10px; border-radius:999px; font-size:11px; font-weight:700; }
  .mod-products       { background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; }
  .mod-sales          { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
  .mod-distributors   { background:#fdf4ff; color:#a21caf; border:1px solid #f5d0fe; }
  .mod-transactions   { background:#fff7ed; color:#f97316; border:1px solid #fed7aa; }
  .mod-returns        { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
  .mod-members        { background:#f5f3ff; color:#7c3aed; border:1px solid #ddd6fe; }
  .mod-stock_requests { background:#ecfeff; color:#0891b2; border:1px solid #a5f3fc; }
  .mod-expenses       { background:#fef9c3; color:#a16207; border:1px solid #fde68a; }
  .act-badge { padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; background:#f1f5f9; color:#475569; }
</style>

<div class="topbar">
  <div>
    <h3 class="fw-bold mb-0">Activity Log</h3>
    <div style="color:var(--muted);font-size:13px;">Full history of what every branch has done — products, sales, distributors, returns, members, stock requests, and expenses.</div>
  </div>
</div>

<div class="al-grid">
  <div class="al-stat">
    <div class="al-stat-num"><?= $totalToday ?></div>
    <div class="al-stat-label">Actions Today</div>
  </div>
  <div class="al-stat">
    <div class="al-stat-num"><?= $totalAll ?></div>
    <div class="al-stat-label">Actions Logged (All Time)</div>
  </div>
  <div class="al-stat">
    <div class="al-stat-num"><?= count($branches) ?></div>
    <div class="al-stat-label">Branches Tracked</div>
  </div>
</div>

<div class="al-card">
  <form method="get" class="al-filters">
    <select name="branch" class="al-select" onchange="this.form.submit()">
      <option value="ALL" <?= $selectedBranch === 'ALL' ? 'selected' : '' ?>>All Branches</option>
      <?php foreach ($branches as $b): ?>
        <option value="<?= htmlspecialchars($b) ?>" <?= $selectedBranch === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
      <?php endforeach; ?>
    </select>

    <select name="module" class="al-select" onchange="this.form.submit()">
      <option value="ALL" <?= $selectedModule === 'ALL' ? 'selected' : '' ?>>All Modules</option>
      <?php foreach ($moduleLabels as $key => $label): ?>
        <option value="<?= $key ?>" <?= $selectedModule === $key ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>

    <input type="text" name="q" class="al-search" placeholder="Search description…" value="<?= htmlspecialchars($search) ?>">
    <button class="btn btn-dark btn-sm">Filter</button>
    <?php if ($selectedBranch !== 'ALL' || $selectedModule !== 'ALL' || $search !== ''): ?>
      <a href="activity_log.php" class="btn btn-outline-secondary btn-sm">Clear</a>
    <?php endif; ?>
  </form>

  <div class="table-responsive">
    <table class="table align-middle">
      <thead class="table-light">
        <tr><th style="width:150px;">When</th><th>Branch</th><th style="width:130px;">Module</th><th style="width:100px;">Action</th><th>Details</th></tr>
      </thead>
      <tbody>
        <?php if (!$logEntries): ?>
          <tr><td colspan="5" class="text-muted text-center py-4">No activity recorded yet.</td></tr>
        <?php else: foreach ($logEntries as $log): ?>
          <tr>
            <td style="font-size:12px;color:#6b7280;"><?= date('M d, Y g:i A', strtotime($log['created_at'])) ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($log['branch_name']) ?></td>
            <td><span class="mod-badge mod-<?= htmlspecialchars($log['module']) ?>"><?= htmlspecialchars($moduleLabels[$log['module']] ?? $log['module']) ?></span></td>
            <td><span class="act-badge"><?= htmlspecialchars($log['action']) ?></span></td>
            <td style="font-size:13px;"><?= htmlspecialchars($log['description']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($logEntries) === 300): ?>
    <div class="text-muted" style="font-size:12px;margin-top:8px;">Showing the most recent 300 matching entries. Narrow with the filters above to see more specific history.</div>
  <?php endif; ?>
</div>

<?php include '_layout_bottom.php'; ?>
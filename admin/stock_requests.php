<?php
/* ============================================================
   ADMIN / STOCK_REQUESTS.PHP
   Main Admin view of ALL inter-branch stock requests — who
   requested, who sent, full timestamped history for proof.
============================================================ */
require '../config.php';

/* Self-healing schema (harmless if already created by stock_requests.php) */
$pdo->exec("
CREATE TABLE IF NOT EXISTS stock_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_ref VARCHAR(20) NOT NULL,
  requesting_user_id INT NOT NULL,
  requesting_branch VARCHAR(150) NOT NULL,
  requesting_product_id INT NOT NULL,
  product_name VARCHAR(200) NOT NULL,
  unit_type VARCHAR(20) NOT NULL,
  qty_requested INT NOT NULL,
  qty_sent INT NULL,
  note VARCHAR(255) NULL,
  status ENUM('PENDING','SENT','RECEIVED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  fulfilling_user_id INT NULL,
  fulfilling_branch VARCHAR(150) NULL,
  fulfilling_product_id INT NULL,
  requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  received_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  INDEX (requesting_user_id),
  INDEX (fulfilling_user_id),
  INDEX (status)
)
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS stock_request_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  action VARCHAR(20) NOT NULL,
  branch_name VARCHAR(150) NOT NULL,
  performed_by_user_id INT NOT NULL,
  notes VARCHAR(255) NULL,
  performed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (request_id)
)
");

/* AJAX: history detail for a single request (used by modal) — must run
   BEFORE _layout_top.php outputs any HTML */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'history') {
  require __DIR__ . '/auth.php';
  header('Content-Type: application/json');
  $rid = (int) ($_GET['id'] ?? 0);
  $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id=?");
  $stmt->execute([$rid]);
  $req = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$req) { echo json_encode(['error' => 'Not found']); exit; }
  $stmt = $pdo->prepare("SELECT * FROM stock_request_logs WHERE request_id=? ORDER BY performed_at ASC");
  $stmt->execute([$rid]);
  echo json_encode(['request' => $req, 'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
  exit;
}

$pageTitle  = 'Stock Requests';
$activePage = 'stock_requests';
require __DIR__ . '/_layout_top.php';

function safe($s) { return htmlspecialchars($s ?? ''); }

/* ---------- FILTERS ---------- */
$statusFilter = $_GET['status'] ?? '';
$branchFilter = trim($_GET['branch'] ?? '');
$search       = trim($_GET['q'] ?? '');

$where  = [];
$params = [];

if ($statusFilter !== '' && in_array($statusFilter, ['PENDING','SENT','RECEIVED','CANCELLED'], true)) {
  $where[] = 'status = ?';
  $params[] = $statusFilter;
}
if ($branchFilter !== '') {
  $where[] = '(requesting_branch = ? OR fulfilling_branch = ?)';
  $params[] = $branchFilter;
  $params[] = $branchFilter;
}
if ($search !== '') {
  $where[] = '(product_name LIKE ? OR request_ref LIKE ?)';
  $params[] = "%$search%";
  $params[] = "%$search%";
}

$sql = "SELECT * FROM stock_requests";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY requested_at DESC LIMIT 300";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* KPI counts (unfiltered, whole system) */
$stmt = $pdo->query("SELECT status, COUNT(*) c FROM stock_requests GROUP BY status");
$kpi = ['PENDING'=>0,'SENT'=>0,'RECEIVED'=>0,'CANCELLED'=>0];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $kpi[$row['status']] = (int)$row['c']; }
$kpiTotal = array_sum($kpi);

/* Branch list for filter dropdown */
$stmt = $pdo->query("SELECT DISTINCT branch_name FROM users WHERE branch_name IS NOT NULL AND branch_name<>'' ORDER BY branch_name ASC");
$branchList = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h3 class="fw-bold mb-0">Inter-Branch Stock Requests</h3>
</div>

<!-- KPI STRIP -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-2">
    <div class="cardx p-3"><div class="kpi-title">Total</div><div class="kpi-value"><?= $kpiTotal ?></div></div>
  </div>
  <div class="col-6 col-md-2">
    <div class="cardx p-3"><div class="kpi-title">Pending</div><div class="kpi-value text-warning"><?= $kpi['PENDING'] ?></div></div>
  </div>
  <div class="col-6 col-md-2">
    <div class="cardx p-3"><div class="kpi-title">In Transit</div><div class="kpi-value text-primary"><?= $kpi['SENT'] ?></div></div>
  </div>
  <div class="col-6 col-md-2">
    <div class="cardx p-3"><div class="kpi-title">Received</div><div class="kpi-value text-success"><?= $kpi['RECEIVED'] ?></div></div>
  </div>
  <div class="col-6 col-md-2">
    <div class="cardx p-3"><div class="kpi-title">Cancelled</div><div class="kpi-value text-muted"><?= $kpi['CANCELLED'] ?></div></div>
  </div>
</div>

<!-- FILTERS -->
<div class="cardx p-3 mb-3">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label small fw-semibold">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach (['PENDING','SENT','RECEIVED','CANCELLED'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-semibold">Branch</label>
      <select name="branch" class="form-select form-select-sm">
        <option value="">All Branches</option>
        <?php foreach ($branchList as $b): ?>
          <option value="<?= safe($b) ?>" <?= $branchFilter===$b?'selected':'' ?>><?= safe($b) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label small fw-semibold">Search product / ref</label>
      <input type="text" name="q" value="<?= safe($search) ?>" class="form-control form-control-sm" placeholder="e.g. SR-AB12CD or product name">
    </div>
    <div class="col-md-2">
      <button class="btn btn-sm btn-primary w-100">Filter</button>
    </div>
  </form>
</div>

<div class="cardx p-3">
  <div class="table-responsive">
    <table class="table table-bordered align-middle">
      <thead class="table-light">
        <tr>
          <th>Ref</th>
          <th>Product</th>
          <th>Qty</th>
          <th>Requested By</th>
          <th>Sent By</th>
          <th>Status</th>
          <th>Requested At</th>
          <th>Sent At</th>
          <th>Received At</th>
          <th style="width:90px;">History</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$requests): ?>
          <tr><td colspan="10" class="text-muted text-center py-4">No stock requests match this filter.</td></tr>
        <?php else: foreach ($requests as $r): ?>
          <tr>
            <td class="fw-semibold"><?= safe($r['request_ref']) ?></td>
            <td><?= safe($r['product_name']) ?></td>
            <td><?= (int)$r['qty_requested'] ?> <?= strtolower(safe($r['unit_type'])) ?><?= $r['qty_sent'] ? ' (sent '.(int)$r['qty_sent'].')' : '' ?></td>
            <td><?= safe($r['requesting_branch']) ?></td>
            <td><?= safe($r['fulfilling_branch'] ?: '—') ?></td>
            <td>
              <?php
                $badgeMap = ['PENDING'=>'bg-warning text-dark','SENT'=>'bg-primary','RECEIVED'=>'bg-success','CANCELLED'=>'bg-secondary'];
                echo '<span class="badge ' . ($badgeMap[$r['status']] ?? 'bg-secondary') . '">' . safe($r['status']) . '</span>';
              ?>
            </td>
            <td style="font-size:12px;"><?= date('M d, Y g:i A', strtotime($r['requested_at'])) ?></td>
            <td style="font-size:12px;"><?= $r['sent_at'] ? date('M d, Y g:i A', strtotime($r['sent_at'])) : '—' ?></td>
            <td style="font-size:12px;"><?= $r['received_at'] ? date('M d, Y g:i A', strtotime($r['received_at'])) : '—' ?></td>
            <td><button class="btn btn-sm btn-outline-secondary" onclick="openHistoryModal(<?= (int)$r['id'] ?>)">View</button></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- HISTORY MODAL -->
<div class="modal fade" id="historyModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Request History</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="historyModalBody">Loading…</div>
    </div>
  </div>
</div>

<script>
function openHistoryModal(requestId) {
  const body = document.getElementById('historyModalBody');
  body.innerHTML = 'Loading…';
  new bootstrap.Modal(document.getElementById('historyModal')).show();
  fetch('stock_requests.php?ajax=history&id=' + requestId)
    .then(r => r.json())
    .then(data => {
      if (data.error) { body.innerHTML = '<p class="text-danger">Not found.</p>'; return; }
      let html = '<p class="mb-2"><strong>' + data.request.request_ref + '</strong> — '
        + data.request.product_name + ' (' + data.request.qty_requested + ' ' + data.request.unit_type.toLowerCase() + ')</p>';
      html += '<p class="mb-3" style="font-size:13px;color:#6b7280;">Requested by <strong>' + data.request.requesting_branch + '</strong>'
        + (data.request.fulfilling_branch ? ' • Fulfilled by <strong>' + data.request.fulfilling_branch + '</strong>' : '') + '</p>';
      html += '<ul class="list-group">';
      data.logs.forEach(log => {
        html += '<li class="list-group-item">'
          + '<div class="fw-semibold">' + log.action + '</div>'
          + '<div style="font-size:12px;color:#6b7280;">' + log.branch_name + ' • ' + log.performed_at + '</div>'
          + (log.notes ? '<div style="font-size:12px;">' + log.notes + '</div>' : '')
          + '</li>';
      });
      html += '</ul>';
      body.innerHTML = html;
    });
}
</script>

<?php require __DIR__ . "/_layout_bottom.php"; ?>
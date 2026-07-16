<?php
/* ============================================================
   STOCK_REQUESTS.PHP — Inter-Branch Stock Request Module
   Lets a branch request a product from other branches when
   running low, lets other branches see + fulfill the request,
   and lets the requesting branch confirm receipt.
   Full timestamped history is kept in stock_request_logs for
   proof of who requested / sent / received and when.
============================================================ */
require 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}

require_once __DIR__ . '/stock_manager.php'; // deductStock() / addStock()

$user_id     = $_SESSION['user_id'];
$branch_name = $_SESSION['branch_name'] ?? ('Branch #' . $user_id);
$errors      = [];
$success     = '';

/* ============================================================
   SELF-HEALING SCHEMA (safe to run repeatedly)
============================================================ */
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

function sr_log(PDO $pdo, int $requestId, string $action, string $branch, int $uid, string $notes = ''): void {
  $pdo->prepare("INSERT INTO stock_request_logs (request_id, action, branch_name, performed_by_user_id, notes) VALUES (?,?,?,?,?)")
      ->execute([$requestId, $action, $branch, $uid, $notes]);
}

function sr_ref(): string {
  return 'SR-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

function sr_unit_label(string $unit, int $qty): string {
  $map = ['BOX' => 'box(es)', 'PCS' => 'pcs', 'BOTTLE' => 'bottle(s)'];
  return $qty . ' ' . ($map[$unit] ?? strtolower($unit));
}

/* ============================================================
   AJAX: NOTIFICATION POLL (badge counts + toast for new "sent")
============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'notify') {
  header('Content-Type: application/json');

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE requesting_user_id<>? AND status='PENDING'");
  $stmt->execute([$user_id]);
  $incoming = (int) $stmt->fetchColumn();

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE requesting_user_id=? AND status='SENT'");
  $stmt->execute([$user_id]);
  $awaitingReceive = (int) $stmt->fetchColumn();

  $since = $_GET['since'] ?? date('Y-m-d H:i:s', time() - 40);
  if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) $since = date('Y-m-d H:i:s', time() - 40);

  $stmt = $pdo->prepare("SELECT request_ref, product_name, fulfilling_branch, qty_sent, unit_type, sent_at
                          FROM stock_requests
                          WHERE requesting_user_id=? AND status='SENT' AND sent_at > ?
                          ORDER BY sent_at DESC");
  $stmt->execute([$user_id, $since]);
  $newSent = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'incoming'          => $incoming,
    'awaiting_receive'  => $awaitingReceive,
    'new_sent'          => $newSent,
    'server_time'       => date('Y-m-d H:i:s'),
  ]);
  exit;
}

/* ============================================================
   AJAX: REQUEST HISTORY (for the "History" modal)
============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'history') {
  header('Content-Type: application/json');
  $rid = (int) ($_GET['id'] ?? 0);

  $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id=? AND (requesting_user_id=? OR fulfilling_user_id=?)");
  $stmt->execute([$rid, $user_id, $user_id]);
  $req = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$req) { echo json_encode(['error' => 'Not found']); exit; }

  $stmt = $pdo->prepare("SELECT * FROM stock_request_logs WHERE request_id=? ORDER BY performed_at ASC");
  $stmt->execute([$rid]);
  echo json_encode(['request' => $req, 'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
  exit;
}

/* ============================================================
   POST ACTIONS
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  /* ---------- CREATE NEW REQUEST ---------- */
  if ($action === 'create_request') {
    $product_id = clean_int($_POST['product_id'] ?? 0);
    $qty        = clean_int($_POST['qty'] ?? 0);
    $note       = clean_str($_POST['note'] ?? '', 255);

    $stmt = $pdo->prepare("SELECT id,name,unit_type FROM products WHERE id=? AND user_id=? AND is_active=1");
    $stmt->execute([$product_id, $user_id]);
    $prod = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prod) {
      $errors[] = 'Please choose a valid product from your inventory.';
    } elseif ($qty <= 0) {
      $errors[] = 'Quantity needed must be greater than 0.';
    } else {
      $ref = sr_ref();
      $pdo->prepare("INSERT INTO stock_requests
          (request_ref, requesting_user_id, requesting_branch, requesting_product_id, product_name, unit_type, qty_requested, note, status, requested_at)
          VALUES (?,?,?,?,?,?,?,?,'PENDING', NOW())")
        ->execute([$ref, $user_id, $branch_name, $prod['id'], $prod['name'], $prod['unit_type'], $qty, $note]);

      $newId = (int) $pdo->lastInsertId();
      sr_log($pdo, $newId, 'REQUESTED', $branch_name, $user_id, "Requested " . sr_unit_label($prod['unit_type'], $qty) . " of {$prod['name']}");
      $success = "Request {$ref} submitted. Other branches can now see it.";
    }
  }

  /* ---------- CANCEL REQUEST (requester, while still PENDING) ---------- */
  if ($action === 'cancel_request') {
    $rid = clean_int($_POST['request_id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE stock_requests SET status='CANCELLED', cancelled_at=NOW()
                            WHERE id=? AND requesting_user_id=? AND status='PENDING'");
    $stmt->execute([$rid, $user_id]);
    if ($stmt->rowCount() === 1) {
      sr_log($pdo, $rid, 'CANCELLED', $branch_name, $user_id, 'Cancelled by requesting branch');
      $success = 'Request cancelled.';
    } else {
      $errors[] = 'Unable to cancel — it may already be in progress.';
    }
  }

  /* ---------- SEND / FULFILL A REQUEST (another branch) ---------- */
  if ($action === 'send_request') {
    $rid     = clean_int($_POST['request_id'] ?? 0);
    $qtySend = clean_int($_POST['qty_send'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id=? AND status='PENDING'");
    $stmt->execute([$rid]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
      $errors[] = 'This request is no longer available (already fulfilled or cancelled).';
    } elseif ((int) $req['requesting_user_id'] === $user_id) {
      $errors[] = 'You cannot fulfill your own branch\'s request.';
    } elseif ($qtySend <= 0) {
      $errors[] = 'Quantity to send must be greater than 0.';
    } else {
      // find matching product in MY inventory (same name + same unit type)
      $stmt = $pdo->prepare("SELECT id, box_qty, pcs_qty, bottle_qty, unit_type FROM products
                              WHERE user_id=? AND is_active=1 AND LOWER(name)=LOWER(?) AND unit_type=? LIMIT 1");
      $stmt->execute([$user_id, $req['product_name'], $req['unit_type']]);
      $myProd = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$myProd) {
        $errors[] = 'You don\'t currently carry a matching product to send.';
      } else {
        $available = $req['unit_type'] === 'BOX' ? (int) $myProd['box_qty']
                   : ($req['unit_type'] === 'PCS' ? (int) $myProd['pcs_qty'] : (int) $myProd['bottle_qty']);

        if ($qtySend > $available) {
          $errors[] = "Not enough stock — you only have {$available} " . strtolower($req['unit_type']) . " available.";
        } else {
          try {
            $pdo->beginTransaction();

            // Atomic claim — only succeeds if still PENDING, prevents 2 branches sending at once
            $claim = $pdo->prepare("UPDATE stock_requests
                SET status='SENT', fulfilling_user_id=?, fulfilling_branch=?, fulfilling_product_id=?, qty_sent=?, sent_at=NOW()
                WHERE id=? AND status='PENDING'");
            $claim->execute([$user_id, $branch_name, $myProd['id'], $qtySend, $rid]);

            if ($claim->rowCount() !== 1) {
              $pdo->rollBack();
              $errors[] = 'Another branch just fulfilled this request first.';
            } else {
              deductStock($pdo, $myProd['id'], $req['unit_type'], $qtySend, $user_id);
              sr_log($pdo, $rid, 'SENT', $branch_name, $user_id, "Sent " . sr_unit_label($req['unit_type'], $qtySend) . " of {$req['product_name']} to {$req['requesting_branch']}");
              $pdo->commit();
              $success = "You sent {$qtySend} " . strtolower($req['unit_type']) . " of {$req['product_name']} to {$req['requesting_branch']}.";
            }
          } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Failed to send: ' . $e->getMessage();
          }
        }
      }
    }
  }

  /* ---------- RECEIVE (requester confirms delivery) ---------- */
  if ($action === 'receive_request') {
    $rid = clean_int($_POST['request_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id=? AND requesting_user_id=? AND status='SENT'");
    $stmt->execute([$rid, $user_id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
      $errors[] = 'This request is not awaiting receipt.';
    } else {
      try {
        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE stock_requests SET status='RECEIVED', received_at=NOW() WHERE id=? AND status='SENT'");
        $upd->execute([$rid]);

        if ($upd->rowCount() !== 1) {
          $pdo->rollBack();
          $errors[] = 'Unable to confirm receipt — please refresh and try again.';
        } else {
          addStock($pdo, $req['requesting_product_id'], $req['unit_type'], (int) $req['qty_sent'], $user_id);
          sr_log($pdo, $rid, 'RECEIVED', $branch_name, $user_id, "Received " . sr_unit_label($req['unit_type'], (int)$req['qty_sent']) . " of {$req['product_name']} from {$req['fulfilling_branch']}");
          $pdo->commit();
          $success = "Receipt confirmed — stock added to your inventory.";
        }
      } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'Failed to confirm receipt: ' . $e->getMessage();
      }
    }
  }
}

/* ============================================================
   FETCH DATA FOR PAGE
============================================================ */
// My own active products (for the "Request Product" dropdown)
$stmt = $pdo->prepare("SELECT id,name,unit_type,box_qty,pcs_qty,bottle_qty FROM products WHERE user_id=? AND is_active=1 ORDER BY name ASC");
$stmt->execute([$user_id]);
$myProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Incoming requests from OTHER branches, still pending
$stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE requesting_user_id<>? AND status='PENDING' ORDER BY requested_at ASC");
$stmt->execute([$user_id]);
$incomingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Attach my matching stock info to each incoming request so I can judge who has more / can fulfill
foreach ($incomingRequests as &$ir) {
  $stmt = $pdo->prepare("SELECT id, box_qty, pcs_qty, bottle_qty FROM products
                          WHERE user_id=? AND is_active=1 AND LOWER(name)=LOWER(?) AND unit_type=? LIMIT 1");
  $stmt->execute([$user_id, $ir['product_name'], $ir['unit_type']]);
  $m = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($m) {
    $ir['my_available'] = $ir['unit_type'] === 'BOX' ? (int)$m['box_qty'] : ($ir['unit_type'] === 'PCS' ? (int)$m['pcs_qty'] : (int)$m['bottle_qty']);
  } else {
    $ir['my_available'] = null; // I don't carry this product
  }
}
unset($ir);

// My own requests (all statuses)
$stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE requesting_user_id=? ORDER BY requested_at DESC");
$stmt->execute([$user_id]);
$myRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Requests I'm fulfilling / have fulfilled
$stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE fulfilling_user_id=? ORDER BY sent_at DESC");
$stmt->execute([$user_id]);
$sentByMe = $stmt->fetchAll(PDO::FETCH_ASSOC);

$incomingCount         = count($incomingRequests);
$awaitingReceiveCount  = 0;
foreach ($myRequests as $r) { if ($r['status'] === 'SENT') $awaitingReceiveCount++; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Stock Requests - Fil-Rise IMS</title>
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
    .sidebar .badge-pill { margin-left:auto; background:#ef4444; color:#fff; font-size:11px; font-weight:700; border-radius:999px; padding:2px 7px; }
    .logout { margin-top:auto; padding:10px 15px; border-radius:10px; background:rgba(239,68,68,.2); color:#cbd5e1; cursor:pointer; transition:.3s; }
    .logout:hover { background:rgba(239,68,68,.4); }
    .main { margin-left:260px; padding:40px; width:100%; }
    .card { border-radius:20px; }
    .kpi { background:#fff; border-radius:18px; box-shadow:0 10px 25px rgba(15,23,42,.06); padding:18px; }
    .kpi .num { font-size:24px; font-weight:800; }
    .kpi .lbl { font-size:12px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:.3px; }
    .status-badge { padding:4px 10px; border-radius:999px; font-size:11px; font-weight:700; letter-spacing:.3px; }
    .status-PENDING   { background:#fff7ed; color:#f97316; border:1px solid #fed7aa; }
    .status-SENT      { background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; }
    .status-RECEIVED  { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
    .status-CANCELLED { background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0; }
    .nav-tabs .nav-link.active { font-weight:700; color:#2563eb; }
    .toast-sr { position:fixed; top:20px; right:20px; z-index:99999; }
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
    
    <a href="stock_requests.php" class="active" id="srNavLink">
      Stock Requests
      <span class="badge-pill" id="srNavBadge" style="<?= ($incomingCount + $awaitingReceiveCount) > 0 ? '' : 'display:none;' ?>">
        <?= $incomingCount + $awaitingReceiveCount ?>
      </span>
    </a>
    <a href="daily_records.php">Daily Records</a>
    <a href="daily_sales_record.php">Daily Sales Record</a>
    <div class="menu-title">People</div>
    <a href="distributor.php">Distributors</a>
    <a href="members.php">Members</a>

    <div class="logout" onclick="window.location='logout.php'">Logout</div>
  </div>

  <div class="main">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
      <div>
        <h3 class="fw-bold mb-0">Stock Requests</h3>
        <div class="text-muted" style="font-size:13px;">Request low-stock products from other branches, or fulfill their requests.</div>
      </div>
      <button class="btn btn-primary rounded-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#newRequestModal">
        + Request Product
      </button>
    </div>

    <?php if ($success): ?>
      <div class="alert alert-success rounded-3"><?= e($success) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
      <div class="alert alert-danger rounded-3"><?= e($err) ?></div>
    <?php endforeach; ?>

    <!-- KPI STRIP -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="kpi"><div class="lbl">Incoming Requests</div><div class="num text-warning"><?= $incomingCount ?></div></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="kpi"><div class="lbl">Awaiting My Receipt</div><div class="num text-primary"><?= $awaitingReceiveCount ?></div></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="kpi"><div class="lbl">My Requests</div><div class="num"><?= count($myRequests) ?></div></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="kpi"><div class="lbl">Sent By Me</div><div class="num"><?= count($sentByMe) ?></div></div>
      </div>
    </div>

    <ul class="nav nav-tabs mb-3" id="srTabs">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-incoming">Incoming Requests <?= $incomingCount ? "({$incomingCount})" : '' ?></button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-mine">My Requests</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-sent">Sent By Me</button></li>
    </ul>

    <div class="tab-content">

      <!-- INCOMING REQUESTS (from other branches) -->
      <div class="tab-pane fade show active" id="tab-incoming">
        <div class="card p-3">
          <div class="table-responsive">
            <table class="table align-middle">
              <thead class="table-light">
                <tr>
                  <th>Ref</th><th>Requesting Branch</th><th>Product</th><th>Qty Needed</th>
                  <th>Your Stock</th><th>Requested At</th><th>Note</th><th style="width:160px;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$incomingRequests): ?>
                  <tr><td colspan="8" class="text-muted text-center py-4">No pending requests from other branches right now.</td></tr>
                <?php else: foreach ($incomingRequests as $r): ?>
                  <tr>
                    <td class="fw-semibold"><?= e($r['request_ref']) ?></td>
                    <td><?= e($r['requesting_branch']) ?></td>
                    <td><?= e($r['product_name']) ?></td>
                    <td><?= (int)$r['qty_requested'] ?> <?= strtolower(e($r['unit_type'])) ?></td>
                    <td>
                      <?php if ($r['my_available'] === null): ?>
                        <span class="text-muted">Not in my inventory</span>
                      <?php else: ?>
                        <span class="fw-semibold <?= $r['my_available'] >= $r['qty_requested'] ? 'text-success' : 'text-warning' ?>">
                          <?= $r['my_available'] ?> <?= strtolower(e($r['unit_type'])) ?> available
                        </span>
                      <?php endif; ?>
                    </td>
                    <td style="font-size:12px;"><?= date('M d, Y g:i A', strtotime($r['requested_at'])) ?></td>
                    <td style="font-size:12px; max-width:150px;" class="text-truncate" title="<?= attr($r['note']) ?>"><?= e($r['note'] ?: '—') ?></td>
                    <td>
                      <?php if ($r['my_available'] !== null && $r['my_available'] > 0): ?>
                        <button class="btn btn-sm btn-primary" onclick='openSendModal(<?= (int)$r['id'] ?>, <?= (int)min($r['my_available'],$r['qty_requested']) ?>, <?= (int)$r['my_available'] ?>, "<?= e(addslashes($r['product_name'])) ?>", "<?= e($r['unit_type']) ?>")'>Send</button>
                      <?php else: ?>
                        <button class="btn btn-sm btn-outline-secondary" disabled>No Stock</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- MY REQUESTS -->
      <div class="tab-pane fade" id="tab-mine">
        <div class="card p-3">
          <div class="table-responsive">
            <table class="table align-middle">
              <thead class="table-light">
                <tr>
                  <th>Ref</th><th>Product</th><th>Qty</th><th>Status</th>
                  <th>Fulfilling Branch</th><th>Requested</th><th>Sent</th><th>Received</th><th style="width:170px;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$myRequests): ?>
                  <tr><td colspan="9" class="text-muted text-center py-4">You haven't requested any products yet.</td></tr>
                <?php else: foreach ($myRequests as $r): ?>
                  <tr>
                    <td class="fw-semibold"><?= e($r['request_ref']) ?></td>
                    <td><?= e($r['product_name']) ?></td>
                    <td><?= (int)$r['qty_requested'] ?> <?= strtolower(e($r['unit_type'])) ?><?= $r['qty_sent'] ? ' (sent: '.(int)$r['qty_sent'].')' : '' ?></td>
                    <td><span class="status-badge status-<?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
                    <td><?= e($r['fulfilling_branch'] ?: '—') ?></td>
                    <td style="font-size:12px;"><?= date('M d, g:i A', strtotime($r['requested_at'])) ?></td>
                    <td style="font-size:12px;"><?= $r['sent_at'] ? date('M d, g:i A', strtotime($r['sent_at'])) : '—' ?></td>
                    <td style="font-size:12px;"><?= $r['received_at'] ? date('M d, g:i A', strtotime($r['received_at'])) : '—' ?></td>
                    <td>
                      <?php if ($r['status'] === 'SENT'): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Confirm you received this product?');">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="receive_request">
                          <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                          <button class="btn btn-sm btn-success">Receive</button>
                        </form>
                      <?php elseif ($r['status'] === 'PENDING'): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Cancel this request?');">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="cancel_request">
                          <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                          <button class="btn btn-sm btn-outline-danger">Cancel</button>
                        </form>
                      <?php endif; ?>
                      <button class="btn btn-sm btn-outline-secondary" onclick="openHistoryModal(<?= (int)$r['id'] ?>)">History</button>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- SENT BY ME -->
      <div class="tab-pane fade" id="tab-sent">
        <div class="card p-3">
          <div class="table-responsive">
            <table class="table align-middle">
              <thead class="table-light">
                <tr>
                  <th>Ref</th><th>Product</th><th>Qty Sent</th><th>Status</th>
                  <th>Requesting Branch</th><th>Sent</th><th>Received</th><th style="width:110px;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$sentByMe): ?>
                  <tr><td colspan="8" class="text-muted text-center py-4">You haven't fulfilled any requests yet.</td></tr>
                <?php else: foreach ($sentByMe as $r): ?>
                  <tr>
                    <td class="fw-semibold"><?= e($r['request_ref']) ?></td>
                    <td><?= e($r['product_name']) ?></td>
                    <td><?= (int)$r['qty_sent'] ?> <?= strtolower(e($r['unit_type'])) ?></td>
                    <td><span class="status-badge status-<?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
                    <td><?= e($r['requesting_branch']) ?></td>
                    <td style="font-size:12px;"><?= $r['sent_at'] ? date('M d, g:i A', strtotime($r['sent_at'])) : '—' ?></td>
                    <td style="font-size:12px;"><?= $r['received_at'] ? date('M d, g:i A', strtotime($r['received_at'])) : 'Not yet confirmed' ?></td>
                    <td><button class="btn btn-sm btn-outline-secondary" onclick="openHistoryModal(<?= (int)$r['id'] ?>)">History</button></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- NEW REQUEST MODAL -->
  <div class="modal fade" id="newRequestModal" tabindex="-1">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_request">
        <div class="modal-header">
          <h5 class="modal-title">Request Product From Other Branches</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Product</label>
            <select name="product_id" class="form-select" required>
              <option value="">-- Select product --</option>
              <?php foreach ($myProducts as $p):
                $qty = $p['unit_type']==='BOX' ? $p['box_qty'] : ($p['unit_type']==='PCS' ? $p['pcs_qty'] : $p['bottle_qty']);
              ?>
                <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> — currently <?= (int)$qty ?> <?= strtolower(e($p['unit_type'])) ?> in stock</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Quantity Needed</label>
            <input type="number" name="qty" min="1" class="form-control" required>
          </div>
          <div class="mb-1">
            <label class="form-label fw-semibold">Note (optional)</label>
            <textarea name="note" class="form-control" rows="2" maxlength="255" placeholder="e.g. Urgent, running out by tomorrow"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary">Submit Request</button>
        </div>
      </form>
    </div>
  </div>

  <!-- SEND MODAL -->
  <div class="modal fade" id="sendModal" tabindex="-1">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_request">
        <input type="hidden" name="request_id" id="sendRequestId">
        <div class="modal-header">
          <h5 class="modal-title">Send Product</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p id="sendModalText" class="mb-3"></p>
          <label class="form-label fw-semibold">Quantity to Send</label>
          <input type="number" name="qty_send" id="sendQty" min="1" class="form-control" required>
          <div class="form-text" id="sendMaxText"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary">Confirm & Send</button>
        </div>
      </form>
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

  <div class="toast-sr">
    <div id="srToast" class="toast align-items-center text-bg-primary border-0" role="alert">
      <div class="d-flex">
        <div class="toast-body" id="srToastBody"></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>
  </div>

  <?php include 'mobile_sidebar.php'; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    function openSendModal(requestId, suggestedQty, maxQty, productName, unitType) {
      document.getElementById('sendRequestId').value = requestId;
      document.getElementById('sendQty').value = suggestedQty;
      document.getElementById('sendQty').max = maxQty;
      document.getElementById('sendModalText').textContent = 'Sending "' + productName + '" (' + unitType + '). You have ' + maxQty + ' available.';
      document.getElementById('sendMaxText').textContent = 'Maximum you can send: ' + maxQty;
      new bootstrap.Modal(document.getElementById('sendModal')).show();
    }

    function openHistoryModal(requestId) {
      const body = document.getElementById('historyModalBody');
      body.innerHTML = 'Loading…';
      new bootstrap.Modal(document.getElementById('historyModal')).show();
      fetch('stock_requests.php?ajax=history&id=' + requestId)
        .then(r => r.json())
        .then(data => {
          if (data.error) { body.innerHTML = '<p class="text-danger">Not found.</p>'; return; }
          let html = '<p class="mb-2"><strong>' + data.request.request_ref + '</strong> — ' + data.request.product_name + '</p>';
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

    // ---- Live notification polling ----
    let lastCheck = new Date().toISOString().slice(0,19).replace('T',' ');
    function pollNotifications() {
      fetch('stock_requests.php?ajax=notify&since=' + encodeURIComponent(lastCheck))
        .then(r => r.json())
        .then(data => {
          const badge = document.getElementById('srNavBadge');
          const total = data.incoming + data.awaiting_receive;
          if (total > 0) { badge.style.display = ''; badge.textContent = total; }
          else { badge.style.display = 'none'; }

          if (data.new_sent && data.new_sent.length) {
            data.new_sent.forEach(item => {
              showToast(item.fulfilling_branch + ' sent ' + item.qty_sent + ' ' + item.unit_type.toLowerCase() + ' of ' + item.product_name + '. Click "My Requests" to confirm receipt.');
            });
          }
          lastCheck = data.server_time;
        })
        .catch(() => {});
    }
    function showToast(msg) {
      document.getElementById('srToastBody').textContent = msg;
      new bootstrap.Toast(document.getElementById('srToast')).show();
    }
    setInterval(pollNotifications, 25000);
  </script>
</body>
</html>
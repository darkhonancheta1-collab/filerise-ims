<?php
// super_admin/branch_modules.php — Super Admin only: per-branch module locking.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require '../config.php';
require '../branch_modules.php';
require __DIR__ . '/auth.php';

$success = '';

/* ── Save toggles for a branch ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_locks'])) {
    csrf_verify();
    $branchName = trim($_POST['branch_name'] ?? '');
    $submitted  = $_POST['locked'] ?? []; // array of module_key => '1' for locked modules

    if ($branchName !== '') {
        foreach (array_keys(fr_module_list()) as $key) {
            $isLocked = isset($submitted[$key]) ? 1 : 0;
            $stmt = $pdo->prepare("
                INSERT INTO branch_module_locks (branch_name, module_key, is_locked)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE is_locked = VALUES(is_locked)
            ");
            $stmt->execute([$branchName, $key, $isLocked]);
        }
        $success = 'Saved locks for ' . htmlspecialchars($branchName) . '.';
    }
}

/* ── Branch list (derived from users.branch_name, same as admin/branches.php) ── */
$branches = $pdo->query("
    SELECT DISTINCT branch_name FROM users
    WHERE branch_name IS NOT NULL AND branch_name <> ''
    ORDER BY branch_name ASC
")->fetchAll(PDO::FETCH_COLUMN);

$selectedBranch = $_POST['branch_name'] ?? ($_GET['branch'] ?? ($branches[0] ?? ''));
$currentLocks   = $selectedBranch !== '' ? fr_locked_modules($pdo, $selectedBranch) : [];

$activePage = 'branch_modules';
$pageTitle  = 'Branch Modules';
include '_layout_top.php';
?>

<style>
  .bm-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:24px; margin-bottom:20px; }
  .bm-title { font-size:18px; font-weight:800; color:#0f172a; margin-bottom:16px; }
  .bm-pills { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:22px; }
  .bm-pill { padding:8px 16px; border-radius:999px; border:1.5px solid #e2e8f0; background:#f8fafc; font-size:13px; font-weight:600; color:#374151; text-decoration:none; cursor:pointer; }
  .bm-pill.active { background:#0f172a; color:#fff; border-color:#0f172a; }
  .bm-row { display:flex; align-items:center; justify-content:space-between; padding:14px 4px; border-bottom:1px solid #f1f5f9; }
  .bm-row:last-child { border-bottom:none; }
  .bm-label { font-size:14px; font-weight:600; color:#0f172a; }
  .bm-switch { position:relative; width:46px; height:26px; }
  .bm-switch input { opacity:0; width:0; height:0; }
  .bm-slider { position:absolute; cursor:pointer; inset:0; background:#e2e8f0; border-radius:999px; transition:.2s; }
  .bm-slider::before { content:''; position:absolute; width:20px; height:20px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.2s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
  .bm-switch input:checked + .bm-slider { background:#dc2626; }
  .bm-switch input:checked + .bm-slider::before { transform:translateX(20px); }
  .bm-hint { font-size:12px; color:#94a3b8; margin-top:2px; }
  .bm-btn { padding:10px 22px; border-radius:8px; border:none; background:#0f172a; color:#fff; font-weight:700; font-size:14px; cursor:pointer; margin-top:18px; }
  .bm-btn:hover { background:#1e293b; }
  .bm-alert { padding:11px 14px; border-radius:10px; font-size:13px; margin-bottom:18px; background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; }
</style>

<?php if ($success): ?>
  <div class="bm-alert">✓ <?= $success ?></div>
<?php endif; ?>

<div class="bm-card">
  <div class="bm-title">Branch Module Locks</div>

  <?php if (empty($branches)): ?>
    <p style="color:#94a3b8; font-size:14px;">No branches found yet — add branch staff first.</p>
  <?php else: ?>
    <div class="bm-pills">
      <?php foreach ($branches as $b): ?>
        <a class="bm-pill <?= $b === $selectedBranch ? 'active' : '' ?>" href="?branch=<?= urlencode($b) ?>">
          <?= htmlspecialchars($b) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="branch_name" value="<?= htmlspecialchars($selectedBranch) ?>">

      <?php foreach (fr_module_list() as $key => $label): ?>
        <div class="bm-row">
          <div>
            <div class="bm-label"><?= htmlspecialchars($label) ?></div>
            <div class="bm-hint">Toggle on to lock this module for <?= htmlspecialchars($selectedBranch) ?></div>
          </div>
          <label class="bm-switch">
            <input type="checkbox" name="locked[<?= htmlspecialchars($key) ?>]" value="1" <?= isset($currentLocks[$key]) ? 'checked' : '' ?>>
            <span class="bm-slider"></span>
          </label>
        </div>
      <?php endforeach; ?>

      <button type="submit" name="save_locks" class="bm-btn">Save Changes</button>
    </form>
  <?php endif; ?>
</div>

<?php include '_layout_bottom.php'; ?>
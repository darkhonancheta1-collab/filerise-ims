<?php
require '../config.php';
$pageTitle  = 'Branches';
$activePage = 'branches';
require __DIR__ . '/_layout_top.php';

function safe($s) { return htmlspecialchars($s ?? ''); }
function peso($n) { return '₱' . number_format((float)$n, 2); }

$success = '';
$error   = '';

// CREATE BRANCH ACCOUNT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_branch_account'])) {
  $branch_name = trim($_POST['branch_name'] ?? '');
  $fullname    = trim($_POST['fullname']    ?? '');
  $username    = trim($_POST['username']    ?? '');
  $password    = trim($_POST['password']    ?? '');
  if ($branch_name===''||$fullname===''||$username===''||$password==='') {
    $error = 'Please fill all fields.';
  } else {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username=?');
    $stmt->execute([$username]);
    if ((int)$stmt->fetchColumn() > 0) {
      $error = 'Username already exists.';
    } else {
      $hash          = password_hash($password, PASSWORD_DEFAULT);
      $secret_answer = trim($_POST['secret_answer'] ?? '');
      $secret_hash   = !empty($secret_answer) ? md5(strtolower($secret_answer)) : null;

      $stmt = $pdo->prepare('INSERT INTO users (fullname,username,password,branch_name,secret_answer) VALUES (?,?,?,?,?)');
      $stmt->execute([$fullname,$username,$hash,$branch_name,$secret_hash]);

      // Handle branch image upload
      if (!empty($_FILES['branch_image']['name'])) {
        $file     = $_FILES['branch_image'];
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed  = ['jpg','jpeg','png','webp'];
        if (in_array($ext, $allowed) && $file['size'] <= 5 * 1024 * 1024) {
          $imgName = str_replace(' ', '_', $branch_name) . '.' . $ext;
          $dest    = __DIR__ . '/../uploads/' . $imgName;
          move_uploaded_file($file['tmp_name'], $dest);
        }
      }

      $success = 'Branch account created!';
    }
  }
}

// RESET BRANCH ACCOUNT PASSWORD (admin-initiated, no secret answer needed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_branch_password'])) {
  $target_user_id = (int) ($_POST['target_user_id'] ?? 0);
  $new_password   = trim($_POST['new_password'] ?? '');
  $confirm        = trim($_POST['confirm_password'] ?? '');
  $new_answer     = trim($_POST['new_secret_answer'] ?? '');

  if ($new_password === '' || strlen($new_password) < 6) {
    $error = 'New password must be at least 6 characters.';
  } elseif ($new_password !== $confirm) {
    $error = 'Passwords do not match.';
  } else {
    // Only ever allow resetting accounts that are actual branch accounts
    $stmt = $pdo->prepare("SELECT id, fullname, branch_name FROM users WHERE id=? AND branch_name IS NOT NULL AND branch_name<>''");
    $stmt->execute([$target_user_id]);
    $target = $stmt->fetch();

    if (!$target) {
      $error = 'Branch account not found.';
    } else {
      $hash = password_hash($new_password, PASSWORD_DEFAULT);
      if ($new_answer !== '') {
        $pdo->prepare("UPDATE users SET password=?, secret_answer=? WHERE id=?")
            ->execute([$hash, md5(strtolower($new_answer)), $target_user_id]);
      } else {
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")
            ->execute([$hash, $target_user_id]);
      }
      $success = "Password reset for {$target['fullname']} ({$target['branch_name']}). Share the new password with them securely.";
    }
  }
}

/* ── FETCH DATA ── */
$stmt = $pdo->query("SELECT id,branch_name,fullname,username FROM users WHERE branch_name IS NOT NULL AND branch_name<>'' ORDER BY branch_name ASC");
$branchUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT user_id,COUNT(*) AS cnt FROM products WHERE is_active=1 GROUP BY user_id");
$productCounts = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $productCounts[$r['user_id']]=(int)$r['cnt'];

$stmt = $pdo->query("SELECT user_id,COUNT(*) AS cnt FROM products WHERE is_active=1 AND ((unit_type='BOX' AND box_qty<=10) OR (unit_type='PCS' AND pcs_qty<=10) OR (unit_type='BOTTLE' AND bottle_qty<=10)) GROUP BY user_id");
$lowStockCounts = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $lowStockCounts[$r['user_id']]=(int)$r['cnt'];

$stmt = $pdo->query("SELECT user_id,COUNT(*) AS cnt FROM distributors GROUP BY user_id");
$distCounts = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $distCounts[$r['user_id']]=(int)$r['cnt'];

$stmt = $pdo->query("SELECT user_id,COUNT(*) AS cnt FROM members GROUP BY user_id");
$memberCounts = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $memberCounts[$r['user_id']]=(int)$r['cnt'];

$stmt = $pdo->query("
  SELECT x.user_id, COALESCE(SUM(x.revenue),0) AS total FROM (
    SELECT ds.user_id,
      CASE WHEN ds.unit = 'PCS' AND p.unit_type = 'BOX'
        THEN ds.quantity_sold * CEIL(p.member_price / 10)
      ELSE ds.quantity_sold * p.member_price END AS revenue
      FROM distributor_sales ds JOIN products p ON p.id=ds.product_id
      WHERE DATE(ds.sale_date)=CURDATE()
    UNION ALL
    SELECT ws.user_id, wsi.quantity * wsi.price AS revenue
      FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id=wsi.sale_id
      WHERE DATE(ws.created_at)=CURDATE()
    UNION ALL
    SELECT m.user_id, m.membership_fee AS revenue
      FROM members m
      WHERE DATE(m.created_at)=CURDATE() AND m.status='ACTIVE' AND m.package_product_id IS NOT NULL
  ) x GROUP BY x.user_id");
$salesToday = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $salesToday[$r['user_id']]=(float)$r['total'];

$stmt = $pdo->query("
  SELECT x.user_id, COALESCE(SUM(x.revenue),0) AS total FROM (
    SELECT ds.user_id,
      CASE WHEN ds.unit = 'PCS' AND p.unit_type = 'BOX'
        THEN ds.quantity_sold * CEIL(p.member_price / 10)
      ELSE ds.quantity_sold * p.member_price END AS revenue
      FROM distributor_sales ds JOIN products p ON p.id=ds.product_id
      WHERE MONTH(ds.sale_date)=MONTH(CURDATE()) AND YEAR(ds.sale_date)=YEAR(CURDATE())
    UNION ALL
    SELECT ws.user_id, wsi.quantity * wsi.price AS revenue
      FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id=wsi.sale_id
      WHERE MONTH(ws.created_at)=MONTH(CURDATE()) AND YEAR(ws.created_at)=YEAR(CURDATE())
    UNION ALL
    SELECT m.user_id, m.membership_fee AS revenue
      FROM members m
      WHERE MONTH(m.created_at)=MONTH(CURDATE()) AND YEAR(m.created_at)=YEAR(CURDATE())
        AND m.status='ACTIVE' AND m.package_product_id IS NOT NULL
  ) x GROUP BY x.user_id");
$salesMonth = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $salesMonth[$r['user_id']]=(float)$r['total'];

$stmt = $pdo->query("
  SELECT x.user_id,p.name,SUM(x.qty) AS total FROM (
    SELECT ds.user_id,ds.product_id,ds.quantity_sold qty FROM distributor_sales ds WHERE DATE(ds.sale_date)=CURDATE()
    UNION ALL
    SELECT ws.user_id,wsi.product_id,wsi.quantity qty FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id=wsi.sale_id WHERE DATE(ws.created_at)=CURDATE()
  ) x JOIN products p ON p.id=x.product_id GROUP BY x.user_id,x.product_id ORDER BY x.user_id,total DESC");
$topProduct = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
  if (!isset($topProduct[$r['user_id']])) $topProduct[$r['user_id']] = $r['name'];
}

// Aggregate per branch
$branches = [];
foreach ($branchUsers as $u) {
  $bn=$u['branch_name']; $uid=$u['id'];
  if (!isset($branches[$bn])) {
    $branches[$bn]=['branch_name'=>$bn,'users'=>[],'products'=>0,'low_stock'=>0,'distributors'=>0,'members'=>0,'sales_today'=>0,'sales_month'=>0,'top_product'=>'— No sales today'];
  }
  $branches[$bn]['users'][]=$u;
  $branches[$bn]['products']     += $productCounts[$uid]  ?? 0;
  $branches[$bn]['low_stock']    += $lowStockCounts[$uid] ?? 0;
  $branches[$bn]['distributors'] += $distCounts[$uid]     ?? 0;
  $branches[$bn]['members']      += $memberCounts[$uid]   ?? 0;
  $branches[$bn]['sales_today']  += $salesToday[$uid]     ?? 0;
  $branches[$bn]['sales_month']  += $salesMonth[$uid]     ?? 0;
  if (isset($topProduct[$uid]) && $branches[$bn]['top_product']==='— No sales today')
    $branches[$bn]['top_product'] = $topProduct[$uid];
}
$branches = array_values($branches);

// Add image path per branch
foreach ($branches as &$b) {
  $imgName = str_replace(' ', '_', $b['branch_name']);
  $b['img'] = '';
  foreach (['jpg','jpeg','png','webp'] as $ext) {
    if (file_exists(__DIR__ . '/../uploads/' . $imgName . '.' . $ext)) {
      $b['img'] = '../uploads/' . $imgName . '.' . $ext;
      break;
    }
  }
}
unset($b);

$colors  = ['#2563eb','#16a34a','#f97316','#8b5cf6','#ef4444','#06b6d4','#d97706'];
$emojis  = ['🏢','🏪','🏬','🏭','🏩','🏦','🏗️'];
?>

<style>
  .page-head { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:24px; }
  .page-head h3 { font-size:22px; font-weight:700; color:#0f172a; margin:0; }
  .page-head .sub { font-size:13px; color:#64748b; }

  /* ── CARD GRID ── */
  .branch-grid {
    display:grid;
    grid-template-columns: repeat(auto-fill, minmax(200px,1fr));
    gap:18px;
  }

  .branch-card {
    background:#fff;
    border-radius:16px;
    overflow:hidden;
    box-shadow:0 2px 10px rgba(0,0,0,.07);
    transition:.2s;
    border:2px solid transparent;
  }
  .branch-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(0,0,0,.12); border-color:#bfdbfe; }

  /* Clickable image area */
  .branch-img-wrap {
    cursor:pointer;
    height:140px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:52px;
    position:relative;
    transition:.2s;
  }
  .branch-img-wrap:hover { filter:brightness(.92); }

  .branch-card-body {
    padding:14px 16px 16px;
  }
  .branch-card-name {
    font-size:14px; font-weight:700; color:#0f172a; margin-bottom:2px;
  }
  .branch-card-sales {
    font-size:18px; font-weight:800; color:#16a34a;
  }
  .branch-card-lbl {
    font-size:11px; color:#94a3b8; margin-bottom:8px;
  }
  .branch-card-stats {
    display:flex; gap:6px;
  }
  .bcs {
    flex:1; background:#f8fafc; border-radius:8px; padding:6px 6px; text-align:center;
    min-width:0;
  }
  .bcs-val { font-size:14px; font-weight:700; color:#0f172a; }
  .bcs-lbl { font-size:9px; color:#94a3b8; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  .low-badge {
    display:inline-flex; align-items:center; gap:4px;
    background:#fef2f2; color:#ef4444; border:1px solid #fecaca;
    font-size:11px; font-weight:700; padding:3px 9px; border-radius:20px;
    margin-top:8px;
  }

  /* ── MODAL ── */
  .bmodal-backdrop {
    display:none; position:fixed; inset:0;
    background:rgba(15,23,42,.5); z-index:9998;
    align-items:center; justify-content:center;
  }
  .bmodal-backdrop.show { display:flex; }

  .bmodal {
    background:#fff; border-radius:20px;
    width:440px; max-width:95vw; max-height:90vh;
    overflow-y:auto;
    box-shadow:0 24px 60px rgba(0,0,0,.25);
    animation:bmodalIn .25s ease;
  }
  @keyframes bmodalIn {
    from { opacity:0; transform:scale(.94) translateY(16px); }
    to   { opacity:1; transform:scale(1)   translateY(0); }
  }

  .bmodal-close {
    background:rgba(0,0,0,.3); border:none; color:#fff;
    width:30px; height:30px; border-radius:50%;
    font-size:14px; cursor:pointer; display:flex;
    align-items:center; justify-content:center; transition:.15s;
  }
  .bmodal-close:hover { background:rgba(0,0,0,.5); }

  .bmodal-body { padding:20px 22px; }

  .bkpi-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:18px; }
  .bkpi {
    background:#f8fafc; border-radius:10px; padding:12px 14px;
  }
  .bkpi-val { font-size:19px; font-weight:800; color:#0f172a; }
  .bkpi-lbl { font-size:11px; color:#64748b; margin-top:2px; }

  .bstat-row { display:flex; justify-content:space-between; align-items:center; padding:9px 0; border-bottom:1px solid #f1f5f9; font-size:13px; }
  .bstat-row:last-child { border-bottom:none; }
  .bstat-lbl { color:#64748b; }
  .bstat-val { font-weight:600; color:#0f172a; }

  .btop-product {
    background:linear-gradient(135deg,#eff6ff,#f0fdf4);
    border:1px solid #bfdbfe; border-radius:10px;
    padding:11px 14px; margin-bottom:18px;
    display:flex; align-items:center; gap:10px;
  }
  .btop-product-label { font-size:11px; color:#2563eb; font-weight:700; }
  .btop-product-name  { font-size:13px; font-weight:700; color:#0f172a; }

  .baccounts { display:flex; flex-wrap:wrap; gap:6px; margin-top:4px; }
  .baccount-chip {
    display:inline-flex; align-items:center; gap:5px;
    background:#f1f5f9; border-radius:8px;
    padding:5px 10px; font-size:12px; font-weight:500; color:#374151;
  }
  .baccount-dot { width:7px; height:7px; border-radius:50%; background:#22c55e; flex-shrink:0; }
  .baccount-reset {
    border:none; background:none; color:#2563eb; font-size:11px; font-weight:700;
    cursor:pointer; padding:0 0 0 4px; text-decoration:underline;
  }
  .baccount-reset:hover { color:#1d4ed8; }

  .bsection-title {
    font-size:11px; font-weight:700; color:#94a3b8;
    text-transform:uppercase; letter-spacing:.7px; margin-bottom:10px;
  }

  .btn-view-sales {
    display:block; width:100%; padding:14px;
    background:linear-gradient(90deg,#1d4ed8,#2563eb);
    color:#fff; text-align:center;
    font-family:'Poppins',sans-serif; font-size:14px; font-weight:700;
    border:none; border-radius:0 0 20px 20px;
    cursor:pointer; text-decoration:none; transition:.2s;
    letter-spacing:.2px;
  }
  .btn-view-sales:hover { background:linear-gradient(90deg,#1e40af,#1d4ed8); color:#fff; }
</style>

<!-- PAGE HEADER -->
<div class="page-head">
  <div>
    <h3>Branches</h3>
    <div class="sub">Click a branch to view its details</div>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBranchModal">
    + Create Branch Account
  </button>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= safe($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><?= safe($error) ?></div><?php endif; ?>

<!-- BRANCH CARDS -->
<div class="branch-grid">
  <?php if (empty($branches)): ?>
    <div class="text-muted p-4">No branches yet. Create one using the button above.</div>
  <?php else: ?>
    <?php foreach ($branches as $i => $b): ?>
      <?php
        $color = $colors[$i % count($colors)];
        $emoji = $emojis[$i % count($emojis)];
      ?>
      <div class="branch-card">

        <!-- Clickable image/icon area -->
        <?php
          // Check for branch image: ../uploads/Branch_Name.jpg or .png
          $imgName   = str_replace(' ', '_', $b['branch_name']);
          $imgFound  = '';
          foreach (['jpg','jpeg','png','webp'] as $ext) {
            if (file_exists(__DIR__ . '/../uploads/' . $imgName . '.' . $ext)) {
              $imgFound = '../uploads/' . $imgName . '.' . $ext;
              break;
            }
          }
        ?>
        <div class="branch-img-wrap"
             style="background:linear-gradient(135deg,<?= $color ?>22,<?= $color ?>44);"
             onclick="openBranch(<?= $i ?>)">
          <?php if ($imgFound): ?>
            <img src="<?= htmlspecialchars($imgFound) ?>"
                 style="width:100%;height:100%;object-fit:cover;display:block;pointer-events:none;">
          <?php else: ?>
            <?= $emoji ?>
          <?php endif; ?>
          <!-- hover hint -->
          <div style="position:absolute;bottom:8px;right:10px;font-size:10px;color:<?php echo $imgFound ? '#fff' : $color ?>;font-weight:700;opacity:.8;text-shadow:0 1px 3px rgba(0,0,0,.3);">
            TAP TO VIEW
          </div>
        </div>

        <!-- Card body (not clickable — just info) -->
        <div class="branch-card-body">
          <div class="branch-card-name"><?= safe($b['branch_name']) ?></div>
          <div class="branch-card-sales"><?= peso($b['sales_today']) ?></div>
          <div class="branch-card-lbl">Sales today</div>
          <div class="branch-card-stats">
            <div class="bcs">
              <div class="bcs-val"><?= $b['products'] ?></div>
              <div class="bcs-lbl">Products</div>
            </div>
            <div class="bcs">
              <div class="bcs-val"><?= $b['distributors'] ?></div>
              <div class="bcs-lbl">Distributors</div>
            </div>
            <div class="bcs">
              <div class="bcs-val"><?= $b['members'] ?></div>
              <div class="bcs-lbl">Members</div>
            </div>
          </div>
          <?php if ($b['low_stock'] > 0): ?>
            <div class="low-badge">⚠ <?= $b['low_stock'] ?> low stock</div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- BRANCH DETAIL MODAL -->
<div class="bmodal-backdrop" id="branchModal">
  <div class="bmodal" id="branchModalBox">

    <!-- Header: full image or colored bg with emoji -->
    <div class="bmodal-header" id="bmodalHeader" style="position:relative;height:280px;overflow:hidden;border-radius:20px 20px 0 0;background:#0f172a;">
      <!-- Full image (shown when available) -->
      <img id="bmodalImg" src="" alt=""
           style="display:none;width:100%;height:100%;object-fit:contain;position:absolute;inset:0;background:#111;">
      <!-- Emoji fallback -->
      <div id="bmodalEmojiBg" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:64px;"></div>
      <!-- Dark overlay for text readability -->
      <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.65) 0%,rgba(0,0,0,.1) 60%);"></div>
      <!-- Close button -->
      <button class="bmodal-close" onclick="closeBranch()" style="position:absolute;top:12px;right:14px;z-index:2;">✕</button>
      <!-- Branch name over image -->
      <div style="position:absolute;bottom:18px;left:20px;z-index:2;">
        <div id="bmodalName" style="font-size:22px;font-weight:800;color:#fff;text-shadow:0 2px 6px rgba(0,0,0,.4);">—</div>
        <div style="font-size:12px;color:rgba(255,255,255,.8);"><?= date('F d, Y') ?></div>
      </div>
    </div>

    <!-- Body -->
    <div class="bmodal-body">

      <!-- KPIs -->
      <div class="bkpi-row">
        <div class="bkpi" style="border-left:3px solid #16a34a;">
          <div class="bkpi-val" id="bmodalToday">₱0.00</div>
          <div class="bkpi-lbl">Sales Today</div>
        </div>
        <div class="bkpi" style="border-left:3px solid #f97316;">
          <div class="bkpi-val" id="bmodalMonth">₱0.00</div>
          <div class="bkpi-lbl">This Month</div>
        </div>
      </div>

      <!-- Top product -->
      <div class="btop-product">
        <div style="font-size:20px;"></div>
        <div>
          <div class="btop-product-label">TOP PRODUCT TODAY</div>
          <div class="btop-product-name" id="bmodalTopProduct">—</div>
        </div>
      </div>

      <!-- Stats -->
      <div class="bsection-title">Branch Overview</div>
      <div style="margin-bottom:18px;">
        <div class="bstat-row">
          <span class="bstat-lbl">Active Products</span>
          <span class="bstat-val" id="bmodalProducts">—</span>
        </div>
        <div class="bstat-row">
          <span class="bstat-lbl">Low Stock Alerts</span>
          <span class="bstat-val" id="bmodalLowStock">—</span>
        </div>
        <div class="bstat-row">
          <span class="bstat-lbl">Distributors</span>
          <span class="bstat-val" id="bmodalDist">—</span>
        </div>
        <div class="bstat-row">
          <span class="bstat-lbl">Members</span>
          <span class="bstat-val" id="bmodalMembers">—</span>
        </div>
      </div>

      <!-- Accounts -->
      <div class="bsection-title">Branch Accounts</div>
      <div class="baccounts" id="bmodalAccounts"></div>

    </div>

    <!-- View Sales button -->
    <a id="bmodalViewSales" href="#" class="btn-view-sales">
      View Sales of This Branch →
    </a>

  </div>
</div>

<!-- CREATE BRANCH MODAL -->
<div class="modal fade" id="createBranchModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px;">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="create_branch_account" value="1">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Create Branch Account</h5>
          <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Branch Name</label>
              <input class="form-control" name="branch_name" placeholder="e.g. Cagayan Branch" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Full Name</label>
              <input class="form-control" name="fullname" placeholder="e.g. Juan dela Cruz" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Username</label>
              <input class="form-control" name="username" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Password</label>
              <input class="form-control" name="password" type="password" required>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Security Answer
                <span style="font-weight:400;color:#6b7280;font-size:12px;">— for Forgot Password</span>
              </label>
              <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;font-size:13px;color:#64748b;margin-bottom:8px;">
                🔐 Security Question: <strong style="color:#0f172a;">What is the name of the FIL-RISE company owner?</strong>
              </div>
              <input class="form-control" name="secret_answer"
                     placeholder="Type the answer (e.g. Darkhon James P Ancheta)" required>
              <div style="font-size:11px;color:#94a3b8;margin-top:4px;">
                This is stored securely and used when the staff forgets their password.
              </div>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Branch Image
                <span style="font-weight:400;color:#6b7280;font-size:12px;">— optional</span>
              </label>
              <input class="form-control" name="branch_image" type="file"
                     accept="image/jpeg,image/png,image/webp">
              <div style="font-size:11px;color:#94a3b8;margin-top:4px;">
                JPG, PNG or WEBP · Max 5MB · Will show on the branch card. File name will be saved as <em>Branch_Name.jpg</em> automatically.
              </div>
              <!-- Preview -->
              <div id="imgPreviewWrap" style="display:none;margin-top:10px;">
                <img id="imgPreview" src="" alt="Preview"
                     style="width:100%;max-height:160px;object-fit:contain;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;">
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary fw-semibold">Create Account</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- RESET PASSWORD MODAL -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px;">
      <form method="POST">
        <input type="hidden" name="reset_branch_password" value="1">
        <input type="hidden" name="target_user_id" id="rpUserId">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Reset Password</h5>
          <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;font-size:13px;color:#374151;margin-bottom:14px;">
            Resetting password for <strong id="rpFullname">—</strong>
            (<span id="rpUsername">—</span>) — <span id="rpBranch">—</span>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">New Password</label>
            <input class="form-control" name="new_password" type="password" minlength="6" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Confirm New Password</label>
            <input class="form-control" name="confirm_password" type="password" minlength="6" required>
          </div>
          <div class="mb-1">
            <label class="form-label fw-semibold">
              Reset Security Answer <span style="font-weight:400;color:#6b7280;font-size:12px;">— optional</span>
            </label>
            <input class="form-control" name="new_secret_answer" placeholder="Leave blank to keep the current answer unchanged">
            <div style="font-size:11px;color:#94a3b8;margin-top:4px;">
              Only needed if the staff also forgot / never set their "Forgot Password" security answer.
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary fw-semibold">Reset Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const BRANCHES = <?= json_encode(array_values($branches), JSON_HEX_TAG) ?>;
const COLORS   = <?= json_encode($colors) ?>;
const EMOJIS   = ['🏢','🏪','🏬','🏭','🏩','🏦','🏗️'];

function peso(n) {
  return '₱' + parseFloat(n).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function openBranch(idx) {
  const b     = BRANCHES[idx];
  const color = COLORS[idx % COLORS.length];
  const emoji = EMOJIS[idx % EMOJIS.length];

  // Header — show full image or emoji fallback
  const header  = document.getElementById('bmodalHeader');
  const imgEl   = document.getElementById('bmodalImg');
  const emojiBg = document.getElementById('bmodalEmojiBg');

  if (b.img) {
    imgEl.src = b.img;
    imgEl.style.display = 'block';
    emojiBg.style.display = 'none';
    header.style.background = 'transparent';
  } else {
    imgEl.style.display = 'none';
    emojiBg.style.display = 'flex';
    emojiBg.textContent = emoji;
    header.style.background = 'linear-gradient(135deg,' + color + 'cc,' + color + ')';
  }
  document.getElementById('bmodalName').textContent = b.branch_name;

  // KPIs
  document.getElementById('bmodalToday').textContent    = peso(b.sales_today);
  document.getElementById('bmodalMonth').textContent    = peso(b.sales_month);
  document.getElementById('bmodalTopProduct').textContent = b.top_product;
  document.getElementById('bmodalProducts').textContent = b.products;
  document.getElementById('bmodalDist').textContent     = b.distributors;
  document.getElementById('bmodalMembers').textContent  = b.members;

  const lowEl = document.getElementById('bmodalLowStock');
  lowEl.textContent = b.low_stock > 0 ? b.low_stock + ' products' : 'None';
  lowEl.style.color = b.low_stock > 0 ? '#ef4444' : '#16a34a';

  // Accounts
  document.getElementById('bmodalAccounts').innerHTML = b.users.map(u =>
    `<span class="baccount-chip">
      <span class="baccount-dot"></span>
      ${u.fullname}
      <span style="color:#94a3b8;font-size:11px;">(${u.username})</span>
      <button type="button" class="baccount-reset" onclick="openResetPassword(${u.id}, '${u.fullname.replace(/'/g,"\\'")}', '${u.username.replace(/'/g,"\\'")}', '${b.branch_name.replace(/'/g,"\\'")}')">Reset Password</button>
    </span>`
  ).join('');

  // View Sales link
  document.getElementById('bmodalViewSales').href =
    'branch_view.php?branch=' + encodeURIComponent(b.branch_name);

  // Show modal
  document.getElementById('branchModal').classList.add('show');
}

function closeBranch() {
  document.getElementById('branchModal').classList.remove('show');
}

function openResetPassword(userId, fullname, username, branchName) {
  document.getElementById('rpUserId').value   = userId;
  document.getElementById('rpFullname').textContent = fullname;
  document.getElementById('rpUsername').textContent = username;
  document.getElementById('rpBranch').textContent   = branchName;
  new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
}

// Close on backdrop click
document.getElementById('branchModal').addEventListener('click', function(e) {
  if (e.target === this) closeBranch();
});

// Branch image preview
document.addEventListener('change', function(e) {
  if (e.target.name === 'branch_image') {
    const file = e.target.files[0];
    if (!file) return;
    const wrap = document.getElementById('imgPreviewWrap');
    const img  = document.getElementById('imgPreview');
    const reader = new FileReader();
    reader.onload = function(ev) {
      img.src = ev.target.result;
      wrap.style.display = 'block';
    };
    reader.readAsDataURL(file);
  }
});
</script>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
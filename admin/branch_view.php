<?php
/**
 * admin/branch_view.php — Individual Branch Detail View (Admin)
 *
 * Shows detailed stats for a single branch: products, sales, stock
 * value, recent transactions, and distributor/member counts.
 * Accessed via: branch_view.php?branch=<branch_name>
 */
require '../config.php';
$pageTitle = "Branch View";
$activePage = "branches";
require __DIR__ . "/_layout_top.php";

function peso($n){ return "₱" . number_format((float)$n, 2); }
function safe($s){ return htmlspecialchars($s ?? ''); }

$branch = trim($_GET['branch'] ?? '');
if ($branch==='') {
  echo "<div class='alert alert-danger'>Branch not specified.</div>";
  require __DIR__ . "/_layout_bottom.php";
  exit;
}

// Sales today/week/month for this branch
$params = [$branch];

$stmt = $pdo->prepare("
  SELECT COALESCE(SUM(x.qty*x.price),0)
  FROM (
    SELECT ds.user_id, ds.quantity_sold qty, p.member_price price, ds.sale_date d
    FROM distributor_sales ds
    JOIN products p ON p.id=ds.product_id
    UNION ALL
    SELECT ws.user_id, wsi.quantity qty, p.member_price price, ws.created_at d
    FROM walkin_sales_items wsi
    JOIN walkin_sales ws ON ws.id=wsi.sale_id
    JOIN products p ON p.id=wsi.product_id
  ) x
  JOIN users u ON u.id=x.user_id
  WHERE u.branch_name=? AND DATE(x.d)=CURDATE()
");
$stmt->execute($params);
$today = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("
  SELECT COALESCE(SUM(x.qty*x.price),0)
  FROM (
    SELECT ds.user_id, ds.quantity_sold qty, p.member_price price, ds.sale_date d
    FROM distributor_sales ds
    JOIN products p ON p.id=ds.product_id
    UNION ALL
    SELECT ws.user_id, wsi.quantity qty, p.member_price price, ws.created_at d
    FROM walkin_sales_items wsi
    JOIN walkin_sales ws ON ws.id=wsi.sale_id
    JOIN products p ON p.id=wsi.product_id
  ) x
  JOIN users u ON u.id=x.user_id
  WHERE u.branch_name=? AND YEARWEEK(x.d,1)=YEARWEEK(CURDATE(),1)
");
$stmt->execute($params);
$week = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("
  SELECT COALESCE(SUM(x.qty*x.price),0)
  FROM (
    SELECT ds.user_id, ds.quantity_sold qty, p.member_price price, ds.sale_date d
    FROM distributor_sales ds
    JOIN products p ON p.id=ds.product_id
    UNION ALL
    SELECT ws.user_id, wsi.quantity qty, p.member_price price, ws.created_at d
    FROM walkin_sales_items wsi
    JOIN walkin_sales ws ON ws.id=wsi.sale_id
    JOIN products p ON p.id=wsi.product_id
  ) x
  JOIN users u ON u.id=x.user_id
  WHERE u.branch_name=? AND YEAR(x.d)=YEAR(CURDATE()) AND MONTH(x.d)=MONTH(CURDATE())
");
$stmt->execute($params);
$month = (float)$stmt->fetchColumn();

// Top products today (branch)
$stmt = $pdo->prepare("
  SELECT p.name, COALESCE(SUM(x.qty),0) total_qty
  FROM (
    SELECT ds.user_id, ds.product_id, ds.quantity_sold qty, ds.sale_date d
    FROM distributor_sales ds
    WHERE DATE(ds.sale_date)=CURDATE()
    UNION ALL
    SELECT ws.user_id, wsi.product_id, wsi.quantity qty, ws.created_at d
    FROM walkin_sales_items wsi
    JOIN walkin_sales ws ON ws.id=wsi.sale_id
    WHERE DATE(ws.created_at)=CURDATE()
  ) x
  JOIN users u ON u.id=x.user_id
  JOIN products p ON p.id=x.product_id
  WHERE u.branch_name=?
  GROUP BY p.name
  ORDER BY total_qty DESC
  LIMIT 5
");
$stmt->execute($params);
$topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labels = array_map(fn($r)=>$r['name'], $topProducts);
$data   = array_map(fn($r)=>(int)$r['total_qty'], $topProducts);
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
  <div>
    <h3 class="fw-bold m-0"><?= safe($branch) ?></h3>
    <div class="text-muted">Branch overview • <?= date('F d, Y') ?></div>
  </div>
  <a class="btn btn-outline-secondary" href="branches.php">← Back</a>
</div>

<div class="row g-3 mb-3">
  <div class="col-12 col-lg-4">
    <div class="cardx p-3">
      <div class="kpi-title">SALES TODAY</div>
      <div class="kpi-value text-success"><?= peso($today) ?></div>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="cardx p-3">
      <div class="kpi-title">THIS WEEK</div>
      <div class="kpi-value"><?= peso($week) ?></div>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="cardx p-3">
      <div class="kpi-title">THIS MONTH</div>
      <div class="kpi-value"><?= peso($month) ?></div>
    </div>
  </div>
</div>

<div class="cardx p-3">
  <h5 class="fw-bold mb-2">Top Products (Today)</h5>
  <?php if(!$topProducts): ?>
    <div class="text-muted">No sales today.</div>
  <?php else: ?>
    <canvas id="tp" height="120"></canvas>
  <?php endif; ?>
</div>

<script>
<?php if($topProducts): ?>
new Chart(document.getElementById('tp'), {
  type: 'bar',
  data: { labels: <?= json_encode($labels) ?>, datasets: [{ label:'Qty Sold', data: <?= json_encode($data) ?> }] },
  options: { responsive:true, plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true } } }
});
<?php endif; ?>
</script>

<?php require __DIR__ . "/_layout_bottom.php"; ?>
<!-- <?php
/* ============================================================
   FIL-RISE TOP TICKER BANNER
   Shows falling/watch-out products from last 8 weeks of sales
   Include this right after <body> in every page
   NOTE: All variables prefixed with ticker_ to avoid conflicts
============================================================ */

if (!isset($pdo) || !isset($_SESSION['user_id'])) return;

$ticker_user_id = $_SESSION['user_id'];

/* Get weekly sales data */
$ticker_stmt = $pdo->prepare("
  SELECT product_id, weeks_ago, SUM(qty) AS qty
  FROM (
    SELECT product_id,
           FLOOR(DATEDIFF(CURDATE(), DATE(d)) / 7) AS weeks_ago,
           qty
    FROM (
      SELECT product_id, sale_date AS d, quantity_sold AS qty
      FROM distributor_sales
      WHERE user_id = ? AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 3 WEEK)
      UNION ALL
      SELECT wsi.product_id, DATE(ws.created_at) AS d, wsi.quantity AS qty
      FROM walkin_sales_items wsi
      JOIN walkin_sales ws ON ws.id = wsi.sale_id
      WHERE ws.user_id = ? AND ws.created_at >= DATE_SUB(CURDATE(), INTERVAL 3 WEEK)
    ) inner_t
  ) outer_t
  WHERE weeks_ago BETWEEN 0 AND 2
  GROUP BY product_id, weeks_ago
");
$ticker_stmt->execute([$ticker_user_id, $ticker_user_id]);
$ticker_weeklyRaw = $ticker_stmt->fetchAll(PDO::FETCH_ASSOC);

/* Build weekly data per product */
$ticker_weeklyData = [];
foreach ($ticker_weeklyRaw as $ticker_row) {
  $ticker_weeklyData[$ticker_row['product_id']][(int)$ticker_row['weeks_ago']] = (int)$ticker_row['qty'];
}

/* Get product names — use different variable name */
$ticker_stmt2 = $pdo->prepare("SELECT id, name FROM products WHERE user_id=? AND is_active=1");
$ticker_stmt2->execute([$ticker_user_id]);
$ticker_products = $ticker_stmt2->fetchAll(PDO::FETCH_ASSOC);

/* Find falling products */
$ticker_falling = [];
foreach ($ticker_products as $ticker_p) {
  $ticker_pid = $ticker_p['id'];
  $ticker_w0  = $ticker_weeklyData[$ticker_pid][0] ?? 0;
  $ticker_w1  = $ticker_weeklyData[$ticker_pid][1] ?? 0;
  if ($ticker_w1 > 0 && $ticker_w0 < $ticker_w1) {
    $ticker_drop = round((($ticker_w1 - $ticker_w0) / $ticker_w1) * 100);
    if ($ticker_drop >= 10) {
      $ticker_falling[] = ['name' => $ticker_p['name'], 'drop' => $ticker_drop];
    }
  }
}

usort($ticker_falling, fn($a,$b) => $b['drop'] <=> $a['drop']);

if (empty($ticker_falling)) {
  $ticker_items = ['All products are stable or rising this week — great job!'];
} else {
  $ticker_items = array_map(
    fn($p) => 'WATCH OUT: ' . $p['name'] . ' is down ' . $p['drop'] . '% this week — consider running a promo',
    $ticker_falling
  );
  array_unshift($ticker_items, 'SALES ALERT — Products losing momentum this week:');
}

$ticker_text = implode('     •     ', $ticker_items);
?>

<style>
#filrise-ticker {
  position: fixed;
  top: 0;
  left: 260px;
  right: 0;
  width: calc(100% - 260px);
  background: linear-gradient(90deg, #f59e0b, #d97706);
  color: #1c1917;
  font-family: 'Poppins', sans-serif;
  font-size: 13px;
  font-weight: 600;
  padding: 9px 0;
  overflow: hidden;
  z-index: 99997;
  box-shadow: 0 2px 8px rgba(217,119,6,.3);
  white-space: nowrap;
}
#filrise-ticker-inner {
  display: inline-block;
  animation: tickerScroll <?= max(20, min(60, strlen($ticker_text) / 5)) ?>s linear infinite;
  padding-left: 100%;
}
#filrise-ticker-inner:hover { animation-play-state: paused; }
@keyframes tickerScroll {
  0%   { transform: translateX(0); }
  100% { transform: translateX(-100%); }
}
.ticker-label {
  display: inline-block;
  background: #92400e;
  color: #fef3c7;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 10px;
  border-radius: 999px;
  margin-right: 14px;
  letter-spacing: .5px;
  text-transform: uppercase;
}
@media (max-width: 992px) {
  #filrise-ticker {
    left: 0;
    width: 100%;
    top: 56px;
    z-index: 99996;
  }
}
</style>

<div id="filrise-ticker">
  <div id="filrise-ticker-inner">
    <span class="ticker-label">WATCH OUT</span>
    <?= htmlspecialchars($ticker_text) ?>
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    <span class="ticker-label">WATCH OUT</span>
    <?= htmlspecialchars($ticker_text) ?>
  </div>
</div>

<script>
function adjustTickerLayout() {
  const ticker = document.getElementById('filrise-ticker');
  const main   = document.querySelector('.main');
  if (!ticker || !main) return;

  if (window.innerWidth <= 992) {
    // Mobile: clear any inline marginTop — mobile.css handles it
    main.style.marginTop = '';
  } else {
    // Desktop: push main down by ticker height
    const h = ticker.offsetHeight;
    main.style.marginTop = h + 'px';
  }
}

document.addEventListener('DOMContentLoaded', adjustTickerLayout);
window.addEventListener('resize', adjustTickerLayout);
</script>

<style>
/* Sidebar stays full height from top — no gap */
.sidebar {
  top: 0 !important;
  height: 100vh !important;
  overflow-y: auto;
}
/* Ticker sits on top of sidebar area — sidebar scrolls under it */
@media (max-width: 992px) {
  body::before { display: none; }
}
</style> -->
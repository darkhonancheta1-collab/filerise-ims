<?php /* ============================================================
   LOW STOCK POPUP — paste this RIGHT AFTER:
   <?php include 'ticker.php'; ?>
   in dashboard.php
============================================================ */ ?>

<?php if (!empty($lowStock)): ?>
<style>
/* backdrop */
#lowStockBackdrop {
  position: fixed; inset: 0;
  background: rgba(15,23,42,.45);
  z-index: 9989;
  animation: lsBdIn .2s ease;
}
@keyframes lsBdIn { from{opacity:0} to{opacity:1} }

#lowStockPopup {
  position: fixed;
  top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  width: 360px;
  max-width: calc(100vw - 32px);
  background: #fff;
  border-radius: 20px;
  box-shadow: 0 24px 60px rgba(0,0,0,.25);
  border: 1.5px solid #fecaca;
  z-index: 9990;
  overflow: hidden;
  animation: lsPopIn .35s cubic-bezier(.34,1.56,.64,1);
}
@keyframes lsPopIn {
  from { opacity:0; transform:translate(-50%,-50%) scale(.92); }
  to   { opacity:1; transform:translate(-50%,-50%) scale(1);   }
}
.ls-popup-header {
  background: linear-gradient(135deg, #ef4444, #dc2626);
  padding: 12px 16px;
  display: flex; align-items: center; justify-content: space-between;
}
.ls-popup-title {
  display: flex; align-items: center; gap: 8px;
  font-size: 13px; font-weight: 700; color: #fff;
  font-family: 'Poppins', sans-serif;
}
.ls-popup-badge {
  background: rgba(255,255,255,.25);
  color: #fff; font-size: 11px; font-weight: 700;
  padding: 2px 8px; border-radius: 20px;
}
.ls-popup-close {
  background: rgba(255,255,255,.2); border: none; color: #fff;
  width: 26px; height: 26px; border-radius: 50%;
  font-size: 14px; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: .15s; flex-shrink: 0;
}
.ls-popup-close:hover { background: rgba(255,255,255,.35); }

.ls-popup-body {
  max-height: 260px;
  overflow-y: auto;
  padding: 10px 0;
}
.ls-popup-body::-webkit-scrollbar { width: 4px; }
.ls-popup-body::-webkit-scrollbar-thumb { background: #fecaca; border-radius: 4px; }

.ls-item {
  display: flex; align-items: center; justify-content: space-between;
  padding: 9px 16px; border-bottom: 1px solid #fef2f2;
  font-family: 'Poppins', sans-serif;
}
.ls-item:last-child { border-bottom: none; }
.ls-item-name {
  font-size: 13px; font-weight: 600; color: #1e293b;
  max-width: 180px; white-space: nowrap;
  overflow: hidden; text-overflow: ellipsis;
}
.ls-item-qty {
  font-size: 12px; font-weight: 700;
  padding: 3px 9px; border-radius: 20px;
  white-space: nowrap;
}
.ls-item-qty.critical { background: #fef2f2; color: #ef4444; border: 1px solid #fecaca; }
.ls-item-qty.low      { background: #fff7ed; color: #f97316; border: 1px solid #fed7aa; }

.ls-popup-footer {
  padding: 10px 16px;
  border-top: 1px solid #fef2f2;
  text-align: center;
}
.ls-popup-footer a {
  font-size: 12px; font-weight: 700; color: #ef4444;
  text-decoration: none; font-family: 'Poppins', sans-serif;
}
.ls-popup-footer a:hover { text-decoration: underline; }
</style>

<div id="lowStockBackdrop" onclick="closeLowStock()"></div>
<div id="lowStockPopup">
  <div class="ls-popup-header">
    <div class="ls-popup-title">
      ⚠ Low Stock Alert
      <span class="ls-popup-badge"><?= count($lowStock) ?> item<?= count($lowStock) > 1 ? 's' : '' ?></span>
    </div>
    <button class="ls-popup-close" onclick="closeLowStock()">✕</button>
  </div>

  <div class="ls-popup-body">
    <?php foreach ($lowStock as $s): ?>
      <?php
        if ($s['unit_type'] === 'BOX')    { $qty = $s['box_qty'];    $unit = 'boxes'; }
        elseif ($s['unit_type'] === 'PCS'){ $qty = $s['pcs_qty'];    $unit = 'pcs';   }
        else                              { $qty = $s['bottle_qty']; $unit = 'btls';  }
        $cls = $qty <= 3 ? 'critical' : 'low';
      ?>
      <div class="ls-item">
        <div class="ls-item-name" title="<?= htmlspecialchars($s['name']) ?>">
          <?= htmlspecialchars($s['name']) ?>
        </div>
        <span class="ls-item-qty <?= $cls ?>">
          <?= $qty ?> <?= $unit ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="ls-popup-footer">
    <a href="product.php?low=1">View all low stock products →</a>
  </div>
</div>
<script>
function closeLowStock() {
  document.getElementById('lowStockPopup').style.display = 'none';
  document.getElementById('lowStockBackdrop').style.display = 'none';
}
</script>
<?php endif; ?>
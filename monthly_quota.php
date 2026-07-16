<?php
require 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$success = "";

$pdo->exec("CREATE TABLE IF NOT EXISTS monthly_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product1_id INT NULL, product2_category VARCHAR(100) NULL,
    product1_target INT DEFAULT 0, product2_target INT DEFAULT 0,
    reward TEXT, goal_month VARCHAR(20), target_date DATE NULL,
    status VARCHAR(20) DEFAULT 'active', user_id INT
)");

$productQuery = $pdo->prepare("SELECT id,name FROM products WHERE user_id=? AND is_active=1 ORDER BY name");
$productQuery->execute([$user_id]);
$products = $productQuery->fetchAll();

$catQuery = $pdo->prepare("SELECT DISTINCT category FROM products WHERE user_id=? AND is_active=1 ORDER BY category");
$catQuery->execute([$user_id]);
$categories = $catQuery->fetchAll();

if (isset($_POST['save_goal'])) {
    $goal_types=$_POST['goal_type']??[]; $goal_values=$_POST['goal_value']??[]; $goal_targets=$_POST['goal_target']??[];
    $reward=$_POST['reward']??''; $target_date=$_POST['target_date']??null; $month=date("F");
    $product1=$product2=null; $product1_target=$product2_target=0;
    foreach($goal_types as $i=>$type){
        $value=$goal_values[$i]??''; $target=(int)($goal_targets[$i]??0);
        if($type==='product'&&!$product1){$product1=$value;$product1_target=$target;}
        if($type==='category'&&!$product2){$product2=$value;$product2_target=$target;}
    }
    $goal_id=$_POST['goal_id']??null;
    if($goal_id){
        $pdo->prepare("UPDATE monthly_goals SET product1_id=?,product2_category=?,product1_target=?,product2_target=?,reward=?,target_date=? WHERE id=? AND user_id=?")
            ->execute([$product1,$product2,$product1_target,$product2_target,$reward,$target_date,$goal_id,$user_id]);
        $success="Monthly goal updated!";
    } else {
        $pdo->prepare("UPDATE monthly_goals SET status='expired' WHERE user_id=? AND status='active'")->execute([$user_id]);
        $pdo->prepare("INSERT INTO monthly_goals(product1_id,product2_category,product1_target,product2_target,reward,goal_month,target_date,status,user_id)VALUES(?,?,?,?,?,?,?,'active',?)")
            ->execute([$product1,$product2,$product1_target,$product2_target,$reward,$month,$target_date,$user_id]);
        $success="Monthly goal saved!";
    }
}

$pdo->prepare("UPDATE monthly_goals SET status='expired' WHERE user_id=? AND status='active' AND target_date IS NOT NULL AND target_date < CURDATE()")->execute([$user_id]);

$goalStmt=$pdo->prepare("SELECT mg.*,p.name AS product1_name FROM monthly_goals mg LEFT JOIN products p ON p.id=mg.product1_id WHERE mg.user_id=? AND mg.status='active' ORDER BY mg.id DESC LIMIT 1");
$goalStmt->execute([$user_id]);
$currentGoal=$goalStmt->fetch();

$rankings=[];
if($currentGoal){
    $rq=$pdo->prepare("SELECT d.id,d.name,
        SUM(CASE WHEN p.id=? THEN ds.quantity_sold ELSE 0 END) AS product1_sold,
        SUM(CASE WHEN p.category=? THEN ds.quantity_sold ELSE 0 END) AS product2_sold,
        SUM(CASE WHEN p.id=? OR p.category=? THEN(ds.quantity_sold*p.member_price)ELSE 0 END) AS total_sales
        FROM distributors d
        LEFT JOIN distributor_sales ds ON ds.distributor_id=d.id AND ds.user_id=?
        LEFT JOIN products p ON p.id=ds.product_id AND p.user_id=?
        WHERE d.user_id=? GROUP BY d.id ORDER BY total_sales DESC");
    $rq->execute([$currentGoal['product1_id'],$currentGoal['product2_category'],$currentGoal['product1_id'],$currentGoal['product2_category'],$user_id,$user_id,$user_id]);
    $rankings=$rq->fetchAll();
}

$daysLeft=null;
if($currentGoal&&$currentGoal['target_date']){
    $daysLeft=max(0,(int)ceil((strtotime($currentGoal['target_date'])-time())/86400));
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Monthly Goal & Ranking — Fil-Rise</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="7.png">
<link rel="stylesheet" href="mobile.css">
<style>
:root{--gold:#f59e0b;--navy:#0f172a;--green:#16a34a;--blue:#2563eb;--bg:#f1f5f9;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Poppins',sans-serif;min-height:100vh;display:flex;background:var(--bg);color:var(--navy);}

.sidebar{width:260px;background:linear-gradient(180deg,#0f172a,#111827);min-height:100vh;padding:25px 20px;position:fixed;display:flex;flex-direction:column;overflow-y:auto;}
.sidebar .logo{width:140px;margin-bottom:30px;}
.sidebar .menu-title{font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-top:20px;margin-bottom:10px;}
.sidebar a{color:#cbd5e1;text-decoration:none;padding:10px 15px;border-radius:10px;display:flex;align-items:center;gap:10px;margin-bottom:5px;transition:.3s;font-size:14px;}
.sidebar a:hover{background:rgba(59,130,246,.2);color:#fff;}
.sidebar a.active{background:linear-gradient(90deg,#2563eb,#3b82f6);color:#fff;}
.logout{margin-top:auto;padding:10px 15px;border-radius:10px;background:rgba(239,68,68,.2);color:#cbd5e1;cursor:pointer;transition:.3s;font-size:14px;}
.logout:hover{background:rgba(239,68,68,.4);color:#fff;}

.main{margin-left:260px;padding:36px 40px;flex:1;}

/* PAGE HEADER */
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;flex-wrap:wrap;gap:12px;}
.page-title{font-size:26px;font-weight:800;}
.page-sub{font-size:13px;color:#64748b;margin-top:2px;}

/* GOAL BANNER */
.goal-banner{background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);border-radius:20px;padding:28px 32px;margin-bottom:24px;position:relative;overflow:hidden;}
.goal-banner::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 80% 50%,rgba(245,158,11,.18) 0%,transparent 65%);}
.goal-banner-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;position:relative;z-index:1;}
.gkpi{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:16px 18px;}
.gkpi-lbl{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px;}
.gkpi-val{font-size:17px;font-weight:800;color:#fff;line-height:1.2;}
.gkpi-sub{font-size:11px;color:#94a3b8;margin-top:3px;}
.gkpi-countdown{background:rgba(245,158,11,.15);border-color:rgba(245,158,11,.3);}
.gkpi-countdown .gkpi-val{color:var(--gold);font-family:'Space Mono',monospace;font-size:28px;}
.reward-box{background:linear-gradient(135deg,rgba(245,158,11,.18),rgba(251,191,36,.08));border:1px solid rgba(245,158,11,.35);border-radius:14px;padding:16px 18px;display:flex;align-items:center;gap:14px;}
.reward-icon{width:46px;height:46px;border-radius:12px;flex-shrink:0;background:linear-gradient(135deg,#f59e0b,#fcd34d);display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 4px 14px rgba(245,158,11,.45);}
.reward-lbl{font-size:11px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.6px;}
.reward-val{font-size:15px;font-weight:700;color:#fff;margin-top:3px;}

/* RANKING */
.rank-card{background:#fff;border-radius:20px;box-shadow:0 4px 20px rgba(0,0,0,.07);overflow:hidden;}
.rank-head{padding:20px 24px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #f1f5f9;}
.rank-title{font-size:17px;font-weight:700;}

/* PODIUM */
.podium{display:flex;align-items:flex-end;justify-content:center;gap:14px;padding:28px 24px 0;background:linear-gradient(180deg,#f8fafc,#fff);border-bottom:4px solid #e2e8f0;overflow:hidden;}
.pod-item{display:flex;flex-direction:column;align-items:center;}
.pod-avatar{width:54px;height:54px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:19px;font-weight:800;color:#fff;margin-bottom:8px;position:relative;}
.pod-avatar.gold  {background:linear-gradient(135deg,#f59e0b,#fcd34d);box-shadow:0 4px 16px rgba(245,158,11,.5);}
.pod-avatar.silver{background:linear-gradient(135deg,#94a3b8,#cbd5e1);box-shadow:0 4px 12px rgba(148,163,184,.4);}
.pod-avatar.bronze{background:linear-gradient(135deg,#b45309,#d97706);box-shadow:0 4px 12px rgba(180,83,9,.35);}
.pod-crown{position:absolute;top:-12px;left:50%;transform:translateX(-50%);font-size:16px;}
.pod-name{font-size:12px;font-weight:700;text-align:center;max-width:84px;line-height:1.3;}
.pod-sales{font-size:11px;color:#64748b;margin-top:2px;}
.pod-block{border-radius:10px 10px 0 0;width:84px;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:900;color:rgba(255,255,255,.7);}
.pod-block.gold  {height:84px;background:linear-gradient(180deg,#fbbf24,#f59e0b);margin-bottom:-1px;}
.pod-block.silver{height:62px;background:linear-gradient(180deg,#cbd5e1,#94a3b8);margin-bottom:-1px;}
.pod-block.bronze{height:46px;background:linear-gradient(180deg,#d97706,#b45309);margin-bottom:-1px;}

/* TABLE */
.rtable{width:100%;border-collapse:collapse;}
.rtable thead th{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#64748b;padding:12px 16px;border-bottom:2px solid #f1f5f9;white-space:nowrap;text-align:center;}
.rtable thead th:nth-child(2){text-align:left;}
.rtable tbody tr{border-bottom:1px solid #f8fafc;transition:.15s;}
.rtable tbody tr:hover{background:#f9fafb;}
.rtable tbody tr.goal-met{background:#f0fdf4;}
.rtable tbody td{padding:13px 16px;font-size:13px;text-align:center;vertical-align:middle;}
.rtable tbody td:nth-child(2){text-align:left;}

.rnum{width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;}
.rnum.r1{background:linear-gradient(135deg,#f59e0b,#fcd34d);color:#fff;box-shadow:0 2px 8px rgba(245,158,11,.5);}
.rnum.r2{background:#f1f5f9;color:#64748b;}
.rnum.r3{background:#f1f5f9;color:#64748b;}
.rnum.rn{background:#f1f5f9;color:#64748b;}
.dname{font-weight:700;}
.gmb{display:inline-flex;align-items:center;gap:3px;background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:6px;}
.sval{font-family:'Space Mono',monospace;font-size:13px;font-weight:700;}
.prog-wrap{background:#f1f5f9;border-radius:4px;height:6px;margin-top:4px;min-width:60px;}
.prog-fill{height:6px;border-radius:4px;transition:width .6s ease;}
.status-badge{font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px;}
.status-ok{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;}
.status-wip{background:#f8fafc;color:#94a3b8;border:1px solid #e2e8f0;}

/* EMPTY */
.empty{text-align:center;padding:60px 24px;color:#94a3b8;}

/* BUTTONS */
.btn-gold{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;border-radius:12px;padding:10px 20px;font-family:'Poppins',sans-serif;font-size:14px;font-weight:700;cursor:pointer;transition:.2s;box-shadow:0 4px 14px rgba(245,158,11,.4);}
.btn-gold:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(245,158,11,.5);}
.btn-dark{background:var(--navy);color:#fff;border:none;border-radius:12px;padding:10px 20px;font-family:'Poppins',sans-serif;font-size:14px;font-weight:700;cursor:pointer;transition:.2s;}
.btn-dark:hover{background:#1e293b;transform:translateY(-1px);}
.btn-outline{background:transparent;color:var(--navy);border:1.5px solid #e2e8f0;border-radius:12px;padding:9px 18px;font-family:'Poppins',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:.2s;}
.btn-outline:hover{background:#f8fafc;}

/* PRINT */
.print-header{display:none;}
@media print{
  @page{
    margin:8mm;
    size:A4 portrait;
  }

  /* Hide everything except content */
  html, body {
    display:block !important;
    background:#fff !important;
    margin:0 !important;
    padding:0 !important;
    width:210mm !important;
    max-width:210mm !important;
  }

  body > * { display:none !important; }
  body > .main { display:block !important; }

  .sidebar, .no-print, .btn, .btn-gold, .btn-dark, .btn-outline,
  .modal, .modal-backdrop,
  .chatbot-container, .chat-btn, .chat-bubble, .chat-widget,
  #chatbot, #chat-widget, #filbot, #adminbot,
  [id*="chat"], [id*="bot"],
  [class*="chat"], [class*="bot"],
  [class*="ticker"], [id*="ticker"],
  .ticker, .ticker-wrap, .ticker-container, .ticker-bar,
  .watch-out, .sales-alert,
  iframe, canvas,
  .fixed-bottom, .fixed-top, .sticky-top,
  .alert { display:none !important; }

  .main {
    margin-left:0 !important;
    padding:0 !important;
    width:100% !important;
    display:block !important;
  }

  /* Page header */
  .page-header { margin-bottom:8px !important; }
  .page-title  { font-size:16px !important; }
  .page-sub    { font-size:10px !important; }

  /* Goal banner */
  .goal-banner {
    background:#0f172a !important;
    border-radius:8px !important;
    padding:12px 16px !important;
    margin-bottom:10px !important;
    -webkit-print-color-adjust:exact;
    print-color-adjust:exact;
  }
  .goal-banner-grid { gap:8px !important; }
  .gkpi { padding:8px 10px !important; }
  .gkpi-val { font-size:12px !important; }
  .gkpi-lbl { font-size:9px !important; }
  .gkpi-sub { font-size:9px !important; }
  .reward-box { padding:8px 10px !important; }
  .reward-val { font-size:12px !important; }
  .reward-lbl { font-size:9px !important; }
  .reward-icon { width:32px !important; height:32px !important; font-size:16px !important; }

  /* Rank card */
  .rank-card {
    box-shadow:none !important;
    border:1px solid #e2e8f0 !important;
    border-radius:10px !important;
    page-break-inside:avoid;
  }
  .rank-head { padding:12px 16px !important; }

  /* Print header show */
  .print-header { display:block !important; }

  /* Table */
  .rtable { width:100% !important; font-size:11px !important; }
  .rtable thead th {
    background:#0f172a !important; color:#fff !important;
    padding:8px 10px !important; font-size:10px !important;
    -webkit-print-color-adjust:exact; print-color-adjust:exact;
  }
  .rtable tbody td { padding:8px 10px !important; font-size:11px !important; }
  .goal-met { background:#f0fdf4 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .prog-wrap { display:none !important; }
  tr { page-break-inside:avoid; }

  /* Rank circles */
  .rnum.r1 { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .status-badge { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
</style>
</head>
<body>
<div class='no-print'><?php include 'ticker.php'; ?></div>

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
  <a href="stock_requests.php">Stock Requests</a>
  <a href="daily_records.php">Daily Records</a>
  <a href="daily_sales_record.php">Daily Sales Record</a>
  <div class="menu-title">People</div>
  <div class="dropdown">
    <a href="#" data-bs-toggle="collapse" data-bs-target="#distMenu">Distributors ▾</a>
    <div class="collapse show" id="distMenu">
      <a href="distributor.php" class="ms-3">Distributor List</a>
      <a href="monthly_quota.php" class="active ms-3">Monthly Goal</a>
    </div>
  </div>
  <a href="members.php">Members</a>
  <div class="logout" onclick="window.location='logout.php'">Logout</div>
</div>

<div class="main">

  <div class="page-header">
    <div>
      <div class="page-title"> Monthly Goal & Ranking</div>
      <div class="page-sub">Set targets · Track progress · Reward top distributors</div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;" class="no-print">
      <?php if($currentGoal): ?>
        <button class="btn-outline" onclick="window.print()">Print</button>
        <button class="btn-gold" data-bs-toggle="modal" data-bs-target="#goalModal"> Edit Goal</button>
      <?php else: ?>
        <button class="btn-gold" data-bs-toggle="modal" data-bs-target="#goalModal">+ Set Monthly Goal</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if($success): ?>
    <div class="alert alert-success no-print mb-4"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <?php if($currentGoal): ?>
  <div class="goal-banner mb-4">
    <div class="goal-banner-grid">

      <?php if($currentGoal['product1_id']): ?>
      <div class="gkpi">
        <div class="gkpi-lbl">Product Goal</div>
        <div class="gkpi-val"><?= htmlspecialchars($currentGoal['product1_name']) ?></div>
        <div class="gkpi-sub">Target: <?= $currentGoal['product1_target'] ?> units</div>
      </div>
      <?php endif; ?>

      <?php if($currentGoal['product2_category']): ?>
      <div class="gkpi">
        <div class="gkpi-lbl">Category Goal</div>
        <div class="gkpi-val"><?= htmlspecialchars($currentGoal['product2_category']) ?></div>
        <div class="gkpi-sub">Target: <?= $currentGoal['product2_target'] ?> units</div>
      </div>
      <?php endif; ?>

      <div class="gkpi gkpi-countdown">
        <div class="gkpi-lbl">Days Left</div>
        <div class="gkpi-val"><?= $daysLeft !== null ? $daysLeft : '—' ?></div>
        <div class="gkpi-sub">Deadline: <?= $currentGoal['target_date'] ?></div>
      </div>

      <?php if($currentGoal['reward']): ?>
      <div class="reward-box">
        <div class="reward-icon">🏅</div>
        <div>
          <div class="reward-lbl">🥇 Reward — #1 Ranked Winner Only</div>
          <div class="reward-val"><?= htmlspecialchars($currentGoal['reward']) ?></div>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
  <?php else: ?>
  <div style="background:#fff;border-radius:20px;box-shadow:0 4px 20px rgba(0,0,0,.07);padding:48px;text-align:center;margin-bottom:24px;">
    <div style="font-size:52px;margin-bottom:12px;"></div>
    <div style="font-size:18px;font-weight:800;margin-bottom:6px;">No Active Goal</div>
    <div style="font-size:13px;color:#64748b;margin-bottom:20px;">Set a monthly goal to start tracking distributor performance and reward top achievers.</div>
    <button class="btn-gold no-print" data-bs-toggle="modal" data-bs-target="#goalModal">+ Set Monthly Goal</button>
  </div>
  <?php endif; ?>

  <!-- RANKING CARD -->
  <div class="rank-card">
    <div class="rank-head">
      <div class="rank-title">Distributor Ranking</div>
      <?php if($currentGoal): ?>
        <div style="font-size:12px;color:#64748b;"><?= date('F Y') ?> · <?= count($rankings) ?> distributors</div>
      <?php endif; ?>
    </div>

    <?php
    // Check if #1 has met ALL goals
    $winner = null;
    if (!empty($rankings)) {
      $top = $rankings[0];
      $topMp = !$currentGoal['product1_target'] || $top['product1_sold'] >= $currentGoal['product1_target'];
      $topMc = !$currentGoal['product2_category'] || !$currentGoal['product2_target'] || $top['product2_sold'] >= $currentGoal['product2_target'];
      if ($topMp && $topMc) $winner = $top;
    }
    ?>
    <?php if($currentGoal && $winner): ?>
    <div style="background:linear-gradient(135deg,#fef3c7,#fffbeb);border-bottom:1px solid #fde68a;padding:16px 24px;display:flex;align-items:center;gap:14px;">
      <div style="font-size:32px;"></div>
      <div>
        <div style="font-size:13px;font-weight:800;color:#92400e;"> We have a winner!</div>
        <div style="font-size:16px;font-weight:800;color:#0f172a;"><?= htmlspecialchars($winner['name']) ?> has hit 100% of all targets!</div>
        <div style="font-size:12px;color:#92400e;margin-top:2px;">Reward: <strong><?= htmlspecialchars($currentGoal['reward']) ?></strong></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Print header -->
    <div class="print-header" style="padding:16px 24px 0;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
        <img src="7.png" style="height:40px;">
        <div>
          <div style="font-size:16px;font-weight:800;">Fil-Rise — Distributor Monthly Ranking</div>
          <div style="font-size:11px;color:#64748b;">Printed: <?= date('F d, Y') ?> · Goal month: <?= $currentGoal['goal_month']??'' ?> · Deadline: <?= $currentGoal['target_date']??'' ?></div>
        </div>
      </div>
      <hr style="border-color:#e2e8f0;">
    </div>

    <?php if(!$currentGoal||empty($rankings)): ?>
      <div class="empty">
        <div style="font-size:48px;margin-bottom:12px;"></div>
        <div style="font-size:15px;font-weight:700;margin-bottom:6px;">No data yet</div>
        <div style="font-size:13px;"><?= !$currentGoal?'Set a goal to see rankings.':'No distributor sales recorded yet.' ?></div>
      </div>
    <?php else: ?>



      <!-- TABLE -->
      <div style="position:relative;z-index:1;">
      <table class="rtable">
        <thead>
          <tr>
            <th style="width:60px;">Rank</th>
            <th>Distributor</th>
            <?php if($currentGoal['product1_id']): ?>
              <th><?= htmlspecialchars($currentGoal['product1_name']) ?></th>
            <?php endif; ?>
            <?php if($currentGoal['product2_category']): ?>
              <th><?= htmlspecialchars($currentGoal['product2_category']) ?></th>
            <?php endif; ?>
            <th>Total Sales</th>
            <th>Reward Status</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $rank=1;
          foreach($rankings as $r):
            $mp = !$currentGoal['product1_target'] || $r['product1_sold'] >= $currentGoal['product1_target'];
            $mc = !$currentGoal['product2_category'] || !$currentGoal['product2_target'] || $r['product2_sold'] >= $currentGoal['product2_target'];
            $met = $mp && $mc; // must hit ALL targets — 100% across the board
            $rc=$rank===1?'r1':($rank===2?'r2':($rank===3?'r3':'rn'));
            $p1p=$currentGoal['product1_target']?min(100,round($r['product1_sold']/$currentGoal['product1_target']*100)):0;
            $p2p=$currentGoal['product2_target']?min(100,round($r['product2_sold']/$currentGoal['product2_target']*100)):0;
          ?>
          <tr class="<?= ($met&&$rank===1)?'goal-met':'' ?>" style="<?= ($met&&$rank!==1)?'background:#eff6ff;':'' ?>">
            <td><span class="rnum <?= $rc ?>"><?= $rank ?></span></td>
            <td>
              <span class="dname"><?= htmlspecialchars($r['name']) ?></span>
              <?php if($rank === 1 && $met): ?><span class="gmb"> Winner</span>
              <?php elseif($rank > 1 && $met): ?><span class="gmb" style="background:#fffbeb;color:#92400e;border-color:#fde68a;">✔ Completed</span>
              <?php endif; ?>
            </td>
            <?php if($currentGoal['product1_id']): ?>
            <td>
              <div style="font-weight:700;color:<?= $mp?'#16a34a':'#0f172a' ?>;"><?= $r['product1_sold'] ?><?= $mp?' ✔':'' ?></div>
              <div class="prog-wrap"><div class="prog-fill" style="width:<?= $p1p ?>%;background:<?= $mp?'#16a34a':'#2563eb' ?>;"></div></div>
              <div style="font-size:10px;color:#94a3b8;margin-top:2px;"><?= $p1p ?>% of <?= $currentGoal['product1_target'] ?></div>
            </td>
            <?php endif; ?>
            <?php if($currentGoal['product2_category']): ?>
            <td>
              <div style="font-weight:700;color:<?= $mc?'#16a34a':'#0f172a' ?>;"><?= $r['product2_sold'] ?><?= $mc?' ✔':'' ?></div>
              <div class="prog-wrap"><div class="prog-fill" style="width:<?= $p2p ?>%;background:<?= $mc?'#16a34a':'#f59e0b' ?>;"></div></div>
              <div style="font-size:10px;color:#94a3b8;margin-top:2px;"><?= $p2p ?>% of <?= $currentGoal['product2_target'] ?></div>
            </td>
            <?php endif; ?>
            <td><span class="sval">₱<?= number_format($r['total_sales'],2) ?></span></td>
            <td>
              <?php if($rank === 1 && $met): ?>
                <span class="status-badge status-ok"> WINNER</span>
              <?php elseif($rank > 1 && $met): ?>
                <span class="status-badge" style="background:#fffbeb;color:#92400e;border:1px solid #fde68a;font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px;">✔ Completed</span>
              <?php elseif(!$met): ?>
                <span class="status-badge status-wip">In Progress</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php $rank++; endforeach; ?>
        </tbody>
      </table>
      </div>

    <?php endif; ?>
  </div>

</div>

<!-- MODAL -->
<div class="modal fade" id="goalModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="border-radius:20px;overflow:hidden;">
      <div class="modal-header" style="background:#0f172a;color:#fff;">
        <h5 class="modal-title fw-bold">🎯 Set Monthly Goal</h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="padding:28px;">
        <form method="POST">
          <input type="hidden" name="goal_id" value="<?= $currentGoal['id']??'' ?>">
          <div id="goal_rows">
            <div class="row g-2 goal-row mb-3">
              <div class="col-md-3">
                <label class="form-label fw-semibold">Type</label>
                <select name="goal_type[]" class="form-select goal-type" onchange="toggleGoalInput(this)" required>
                  <option value="">Select</option>
                  <option value="product">Specific Product</option>
                  <option value="category">Category</option>
                </select>
              </div>
              <div class="col-md-5">
                <label class="form-label fw-semibold">Product / Category</label>
                <select name="goal_value[]" class="form-select goal-value"><option value="">Select</option></select>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-semibold">Target (units)</label>
                <input type="number" name="goal_target[]" class="form-control" min="1">
              </div>
              <div class="col-md-1 d-flex align-items-end">
                <button type="button" class="btn btn-outline-danger w-100" onclick="removeGoalRow(this)">🗑</button>
              </div>
            </div>
          </div>
          <button type="button" class="btn btn-outline-primary btn-sm mb-4" onclick="addGoalRow()">+ Add Row</button>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Target Date</label>
              <input type="date" name="target_date" class="form-control" required value="<?= $currentGoal['target_date']??'' ?>">
            </div>
          </div>
          <div class="mb-4">
            <label class="form-label fw-semibold">🏅 Reward for Goal Achievers</label>
            <textarea name="reward" class="form-control" rows="3" placeholder="e.g. ₱500 cash incentive + recognition certificate"><?= htmlspecialchars($currentGoal['reward']??'') ?></textarea>
            <div style="font-size:12px;color:#94a3b8;margin-top:5px;">Only 1 winner — the #1 ranked distributor who hits 100% of ALL targets gets this reward.</div>
          </div>
          <div class="d-flex gap-2 justify-content-end">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button name="save_goal" class="btn btn-warning fw-bold px-4">💾 Save Goal</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
const productOptions=`<option value="">Select Product</option><?php foreach($products as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>`;
const categoryOptions=`<option value="">Select Category</option><?php foreach($categories as $c): ?><option value="<?= htmlspecialchars($c['category']) ?>"><?= htmlspecialchars($c['category']) ?></option><?php endforeach; ?>`;
function toggleGoalInput(s){const r=s.closest('.goal-row');const v=r.querySelector('.goal-value');if(s.value==='product')v.innerHTML=productOptions;if(s.value==='category')v.innerHTML=categoryOptions;}
function addGoalRow(){const c=document.getElementById('goal_rows');const r=document.querySelector('.goal-row').cloneNode(true);r.querySelectorAll('input').forEach(i=>i.value='');r.querySelectorAll('select').forEach(s=>s.selectedIndex=0);c.appendChild(r);}
function removeGoalRow(b){if(document.querySelectorAll('.goal-row').length>1)b.closest('.goal-row').remove();}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<div class='no-print'>
<?php include 'chatbot.php'; ?>
<?php include 'mobile_sidebar.php'; ?>
</div>
</body>
</html>
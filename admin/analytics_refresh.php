<?php
require '../config.php';
session_start();

// Only accessible to logged-in admin
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['admin_name'])) {
    http_response_code(401);
    exit;
}

header('Content-Type: application/json');

$branchNames = $pdo->query("SELECT DISTINCT branch_name FROM users WHERE branch_name IS NOT NULL AND branch_name <> '' ORDER BY branch_name ASC")->fetchAll(PDO::FETCH_COLUMN);

$branchAnalytics = [];
foreach ($branchNames as $bn) {

    // Top 5 products this month
    $stmt = $pdo->prepare("
        SELECT p.name, COALESCE(SUM(x.qty), 0) AS total_qty,
               COALESCE(SUM(x.revenue), 0) AS total_revenue
        FROM (
            SELECT ds.user_id, ds.product_id,
                   ds.quantity_sold AS qty,
                   CASE WHEN ds.unit='PCS' AND p2.unit_type='BOX'
                     THEN ds.quantity_sold * CEIL(p2.member_price/10)
                   ELSE ds.quantity_sold * p2.member_price END AS revenue
            FROM distributor_sales ds
            JOIN products p2 ON p2.id = ds.product_id
            WHERE MONTH(ds.sale_date)=MONTH(CURDATE()) AND YEAR(ds.sale_date)=YEAR(CURDATE())
            UNION ALL
            SELECT ws.user_id, wsi.product_id,
                   wsi.quantity AS qty,
                   (wsi.quantity * wsi.price) AS revenue
            FROM walkin_sales_items wsi
            JOIN walkin_sales ws ON ws.id = wsi.sale_id
            WHERE MONTH(ws.created_at)=MONTH(CURDATE()) AND YEAR(ws.created_at)=YEAR(CURDATE())
        ) x
        JOIN users u ON u.id = x.user_id
        JOIN products p ON p.id = x.product_id
        WHERE u.branch_name = ?
        GROUP BY p.id, p.name
        ORDER BY total_qty DESC
        LIMIT 5
    ");
    $stmt->execute([$bn]);
    $topProds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Monthly total
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(x.revenue),0) AS total
        FROM (
            SELECT ds.user_id,
                   CASE WHEN ds.unit='PCS' AND p.unit_type='BOX'
                     THEN ds.quantity_sold * CEIL(p.member_price/10)
                   ELSE ds.quantity_sold * p.member_price END AS revenue
            FROM distributor_sales ds JOIN products p ON p.id=ds.product_id
            WHERE MONTH(ds.sale_date)=MONTH(CURDATE()) AND YEAR(ds.sale_date)=YEAR(CURDATE())
            UNION ALL
            SELECT ws.user_id, (wsi.quantity * wsi.price) AS revenue
            FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id=wsi.sale_id
            WHERE MONTH(ws.created_at)=MONTH(CURDATE()) AND YEAR(ws.created_at)=YEAR(CURDATE())
            UNION ALL
            SELECT m.user_id, m.membership_fee AS revenue
            FROM members m
            WHERE m.status='ACTIVE' AND m.package_product_id IS NOT NULL
              AND MONTH(m.created_at)=MONTH(CURDATE()) AND YEAR(m.created_at)=YEAR(CURDATE())
        ) x
        JOIN users u ON u.id = x.user_id
        WHERE u.branch_name = ?
    ");
    $stmt->execute([$bn]);
    $branchMonthTotal = (float)$stmt->fetchColumn();

    // Units sold
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(x.qty),0) AS total
        FROM (
            SELECT ds.user_id, ds.quantity_sold AS qty
            FROM distributor_sales ds
            WHERE MONTH(ds.sale_date)=MONTH(CURDATE()) AND YEAR(ds.sale_date)=YEAR(CURDATE())
            UNION ALL
            SELECT ws.user_id, wsi.quantity AS qty
            FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id=wsi.sale_id
            WHERE MONTH(ws.created_at)=MONTH(CURDATE()) AND YEAR(ws.created_at)=YEAR(CURDATE())
        ) x
        JOIN users u ON u.id = x.user_id
        WHERE u.branch_name = ?
    ");
    $stmt->execute([$bn]);
    $branchMonthUnits = (int)$stmt->fetchColumn();

    // Top distributor
    $stmt = $pdo->prepare("
        SELECT d.name, SUM(ds.quantity_sold) AS total
        FROM distributor_sales ds
        JOIN distributors d ON d.id = ds.distributor_id
        JOIN users u ON u.id = ds.user_id
        WHERE u.branch_name = ?
          AND MONTH(ds.sale_date)=MONTH(CURDATE()) AND YEAR(ds.sale_date)=YEAR(CURDATE())
        GROUP BY ds.distributor_id ORDER BY total DESC LIMIT 1
    ");
    $stmt->execute([$bn]);
    $topDist = $stmt->fetch(PDO::FETCH_ASSOC);

    $branchAnalytics[] = [
        'branch'       => $bn,
        'month_total'  => $branchMonthTotal,
        'month_units'  => $branchMonthUnits,
        'top_products' => $topProds,
        'top_dist'     => $topDist,
    ];
}

usort($branchAnalytics, fn($a,$b) => $b['month_total'] <=> $a['month_total']);

echo json_encode($branchAnalytics);
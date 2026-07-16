<?php
require '../config.php';
require __DIR__ . '/auth.php';

header('Content-Type: application/json');

$branch = trim($_GET['branch'] ?? 'ALL');

$filterSQL    = "";
$filterParams = [];
if ($branch !== 'ALL') {
    $filterSQL      = " AND u.branch_name = ? ";
    $filterParams[] = $branch;
}

function sales_total(PDO $pdo, string $periodSQL, array $periodParams, string $filterSQL, array $filterParams): float {
    $sql = "
        SELECT COALESCE(SUM(x.revenue), 0) AS total
        FROM (
            SELECT ds.user_id,
                CASE WHEN ds.unit = 'PCS' AND p.unit_type = 'BOX'
                    THEN ds.quantity_sold * CEIL(p.member_price / 10)
                ELSE ds.quantity_sold * p.member_price END AS revenue,
                ds.sale_date AS d
            FROM distributor_sales ds JOIN products p ON p.id = ds.product_id
            WHERE ds.user_id > 0
            UNION ALL
            SELECT ws.user_id, (wsi.quantity * wsi.price) AS revenue, ws.created_at AS d
            FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id = wsi.sale_id
            UNION ALL
            SELECT m.user_id, m.membership_fee AS revenue, m.created_at AS d
            FROM members m WHERE m.status = 'ACTIVE' AND m.package_product_id IS NOT NULL
        ) x
        LEFT JOIN users u ON u.id = x.user_id
        WHERE 1=1 $periodSQL $filterSQL
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($periodParams, $filterParams));
    return (float) $stmt->fetchColumn();
}

$kpiToday = sales_total($pdo, " AND DATE(x.d) = CURDATE() ",          [], $filterSQL, $filterParams);
$kpiWeek  = sales_total($pdo, " AND YEARWEEK(x.d, 1) = YEARWEEK(CURDATE(), 1) ", [], $filterSQL, $filterParams);
$kpiMonth = sales_total($pdo, " AND YEAR(x.d) = YEAR(CURDATE()) AND MONTH(x.d) = MONTH(CURDATE()) ", [], $filterSQL, $filterParams);

// Branch performance today
$branchesToday = [];
if ($branch === 'ALL') {
    $stmt = $pdo->prepare("
        SELECT u.branch_name,
               COALESCE(SUM(x.revenue), 0) AS sales_today,
               COALESCE(SUM(x.qty_sold), 0) AS products_sold
        FROM users u
        LEFT JOIN (
            SELECT ds.user_id,
                CASE WHEN ds.unit = 'PCS' AND p.unit_type = 'BOX'
                    THEN ds.quantity_sold * CEIL(p.member_price / 10)
                ELSE ds.quantity_sold * p.member_price END AS revenue,
                ds.quantity_sold AS qty_sold
            FROM distributor_sales ds JOIN products p ON p.id = ds.product_id
            WHERE DATE(ds.sale_date) = CURDATE() AND ds.user_id > 0
            UNION ALL
            SELECT ws.user_id, (wsi.quantity * wsi.price) AS revenue, wsi.quantity AS qty_sold
            FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id = wsi.sale_id
            WHERE DATE(ws.created_at) = CURDATE()
            UNION ALL
            SELECT m.user_id, m.membership_fee AS revenue, 1 AS qty_sold
            FROM members m
            WHERE DATE(m.created_at) = CURDATE() AND m.status = 'ACTIVE' AND m.package_product_id IS NOT NULL
        ) x ON x.user_id = u.id
        WHERE u.branch_name IS NOT NULL AND u.branch_name <> ''
        GROUP BY u.id, u.branch_name
        ORDER BY sales_today DESC
    ");
    $stmt->execute();
    $branchesToday = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Top products today
$stmt = $pdo->prepare("
    SELECT p.name, COALESCE(SUM(x.qty), 0) AS total_qty
    FROM (
        SELECT ds.user_id, ds.product_id, ds.quantity_sold AS qty FROM distributor_sales ds WHERE DATE(ds.sale_date)=CURDATE() AND ds.user_id>0
        UNION ALL
        SELECT ws.user_id, wsi.product_id, wsi.quantity AS qty FROM walkin_sales_items wsi JOIN walkin_sales ws ON ws.id=wsi.sale_id WHERE DATE(ws.created_at)=CURDATE()
    ) x LEFT JOIN users u ON u.id=x.user_id JOIN products p ON p.id=x.product_id
    WHERE 1=1 $filterSQL GROUP BY p.name ORDER BY total_qty DESC LIMIT 5
");
$stmt->execute($filterParams);
$topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'kpi_today'      => $kpiToday,
    'kpi_week'       => $kpiWeek,
    'kpi_month'      => $kpiMonth,
    'branches_today' => $branchesToday,
    'top_products'   => $topProducts,
]);
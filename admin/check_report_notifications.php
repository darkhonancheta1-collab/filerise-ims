<?php
/**
 * admin/check_report_notifications.php — Polling Endpoint for Today's Submitted Reports
 *
 * Checks if any branch has submitted their daily report today.
 * Returns a JSON array of submitted branch reports to trigger admin UI notification alerts.
 */
require '../config.php';

header('Content-Type: application/json');

$stmt = $pdo->prepare("
    SELECT id, branch_name, submitted_at
    FROM branch_reports
    WHERE DATE(submitted_at) = CURDATE()
    ORDER BY submitted_at DESC
");
$stmt->execute();

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
<?php
// activity_log.php — Company-wide activity/audit trail.
// Include this from any staff-facing page AFTER config.php / session_start().
// Requires $pdo (from config.php) to already be available.

/* ── Self-healing schema (safe to run repeatedly) ── */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        branch_name VARCHAR(150) NOT NULL,
        module      VARCHAR(50) NOT NULL,
        action      VARCHAR(50) NOT NULL,
        description VARCHAR(500) NOT NULL,
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (branch_name),
        INDEX (module),
        INDEX (created_at)
    )");
} catch (Exception $e) {}

/**
 * Record one activity entry for the currently logged-in staff member.
 * $module  — e.g. 'products', 'sales', 'distributors', 'returns',
 *            'members', 'stock_requests', 'expenses'
 * $action  — e.g. 'add', 'update', 'delete', 'sale', 'request', 'send',
 *            'receive', 'cancel', 'restock'
 * $description — short human-readable summary, e.g.
 *            "Added product LOVER BOY COFFEE (50 pcs)"
 */
function fr_log_activity(PDO $pdo, string $module, string $action, string $description): void {
    $userId     = $_SESSION['user_id'] ?? 0;
    $branchName = $_SESSION['branch_name'] ?? '';
    if (!$userId || $branchName === '') return;

    try {
        $pdo->prepare("INSERT INTO activity_log (user_id, branch_name, module, action, description) VALUES (?,?,?,?,?)")
            ->execute([$userId, $branchName, $module, $action, $description]);
    } catch (Exception $e) {}
}
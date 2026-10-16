<?php
// branch_modules.php — Super Admin per-branch module locking engine.
// Include this from any staff-facing page AFTER config.php / session_start().
// Requires $pdo (from config.php) to already be available.

/* ── The 9 lockable modules: key => display label ── */
function fr_module_list(): array {
    return [
        'ai_forecast'     => 'AI Forecast',
        'products'        => 'Products',
        'transactions'    => 'Transactions',
        'walkin_sales'    => 'Walk-in Sales',
        'returns'         => 'Returns',
        'daily_records'   => 'Daily Records',
        'distributors'    => 'Distributors',
        'members'         => 'Members',
        'stock_requests'  => 'Stock Requests',
    ];
}

/* ── Self-healing schema (safe to run repeatedly) ── */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS branch_module_locks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_name VARCHAR(150) NOT NULL,
        module_key  VARCHAR(50)  NOT NULL,
        is_locked   TINYINT(1)   NOT NULL DEFAULT 0,
        updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY branch_module_unique (branch_name, module_key)
    )");
} catch (Exception $e) {}

/**
 * Returns an array of module_key => true for every module locked
 * for the given branch. Empty array = nothing locked.
 */
function fr_locked_modules(PDO $pdo, string $branchName): array {
    if ($branchName === '') return [];
    static $cache = [];
    if (isset($cache[$branchName])) return $cache[$branchName];

    $locked = [];
    try {
        $stmt = $pdo->prepare("SELECT module_key FROM branch_module_locks WHERE branch_name = ? AND is_locked = 1");
        $stmt->execute([$branchName]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $locked[$key] = true;
        }
    } catch (Exception $e) {}

    $cache[$branchName] = $locked;
    return $locked;
}

/**
 * Quick boolean check for a single module.
 */
function fr_is_module_locked(PDO $pdo, string $branchName, string $moduleKey): bool {
    $locked = fr_locked_modules($pdo, $branchName);
    return isset($locked[$moduleKey]);
}

/**
 * Map of module_key => the sidebar href(s) that belong to it.
 * Shared by fr_href() and fr_locked_banner().
 */
function fr_module_hrefs(): array {
    return [
        'ai_forecast'    => ['ai_forecast.php'],
        'products'       => ['product.php', 'removed_products.php'],
        'transactions'   => ['transaction.php'],
        'walkin_sales'   => ['sales.php'],
        'returns'        => ['returns.php'],
        'daily_records'  => ['daily_records.php', 'daily_sales_record.php'],
        'distributors'   => ['distributor.php', 'distributor_detail.php', 'monthly_quota.php'],
        'members'        => ['members.php'],
        'stock_requests' => ['stock_requests.php'],
    ];
}

/**
 * Returns the set of href strings (e.g. "distributor.php") that are
 * locked for the logged-in staff member's branch, keyed by href for
 * fast lookup.
 */
function fr_locked_hrefs(): array {
    static $result = null;
    if ($result !== null) return $result;

    $result = [];
    $branchName = $_SESSION['branch_name'] ?? '';
    if ($branchName === '') return $result;

    global $pdo;
    $locked = fr_locked_modules($pdo, $branchName);
    foreach (array_keys($locked) as $key) {
        foreach (fr_module_hrefs()[$key] ?? [] as $href) {
            $result[$href] = true;
        }
    }
    return $result;
}

/**
 * Renders the href attribute (and, when locked, disabling attributes)
 * for a single sidebar link. Use like: <a <?= fr_href('product.php') ?>>Products</a>
 *
 * This is a SERVER-SIDE decision: a locked link is rendered with no
 * working href at all — there's nothing for the browser to navigate
 * to, so there's no JS timing window where a click could slip through
 * before a script finishes loading. The visual "disabled" look (grey,
 * 🔒 badge, not-allowed cursor) comes from the .fr-locked-link class,
 * styled in fr_locked_style().
 */
function fr_href(string $href): string {
    if (isset(fr_locked_hrefs()[$href])) {
        return 'href="javascript:void(0)" onclick="return false;" aria-disabled="true" tabindex="-1" class="fr-locked-link"';
    }
    return 'href="' . htmlspecialchars($href, ENT_QUOTES) . '"';
}

/**
 * Call at the top of a staff page (after the user_id session check).
 * If the module is locked for the logged-in staff member's branch,
 * redirects to dashboard.php with a notice flag and stops execution.
 */
function fr_require_module(PDO $pdo, string $moduleKey): void {
    $branchName = $_SESSION['branch_name'] ?? '';
    if ($branchName !== '' && fr_is_module_locked($pdo, $branchName, $moduleKey)) {
        header('Location: dashboard.php?locked=' . urlencode($moduleKey));
        exit;
    }
}

/**
 * Static CSS for the .fr-locked-link class that fr_href() applies to
 * locked sidebar links: greyed out, not-allowed cursor, 🔒 badge.
 * No JS involved — the link is already un-clickable server-side.
 */
function fr_locked_style(): string {
    return '<style>'
         . '.fr-locked-link {'
         . ' opacity: .45 !important;'
         . ' filter: grayscale(70%);'
         . ' cursor: not-allowed !important;'
         . ' pointer-events: none !important;'
         . ' position: relative;'
         . ' }'
         . '.fr-locked-link::after {'
         . ' content: "\\1F512";'
         . ' margin-left: 6px;'
         . ' font-size: 11px;'
         . ' opacity: .9;'
         . ' }'
         . '</style>';
}

/**
 * Dashboard notice banner — shown when a staff member gets bounced
 * from a locked module (dashboard.php?locked=xxx) or as a general
 * summary of what's locked for their branch.
 */
function fr_locked_banner(): string {
    $branchName = $_SESSION['branch_name'] ?? '';
    if ($branchName === '') return '';

    global $pdo;
    $bouncedKey = $_GET['locked'] ?? '';
    if ($bouncedKey === '') return '';

    $labels = fr_module_list();
    $label  = $labels[$bouncedKey] ?? $bouncedKey;

    if (!fr_is_module_locked($pdo, $branchName, $bouncedKey)) return '';

    return '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;'
         . 'padding:12px 16px;margin-bottom:16px;color:#b91c1c;font-size:14px;font-weight:600;">'
         . '⚠ "' . htmlspecialchars($label) . '" has been disabled for your branch by an administrator.'
         . '</div>';
}
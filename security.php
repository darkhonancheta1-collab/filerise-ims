<?php
/**
 * security.php — Filrise Security Layer
 * Fixes all OWASP ZAP alerts + additional attack surface hardening
 */

/* ============================================================
   1. SECURE SESSION START
   [SEARCH TAG: SESSION_COOKIE_CONFIG / COOKIE_LIFETIME]
============================================================ */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,       // set true on HTTPS
        'httponly' => true,        // Fix: Cookie HttpOnly
        'samesite' => 'Strict',    // Fix: Cookie SameSite
    ]);
    session_start();
}

/* ============================================================
   [SEARCH TAG: SESSION_TIMEOUT / SESSION_EXPIRATION / AUTO_LOGOUT_TIME / SESSION_TIME]
   Auto logout inactive users after 7200 seconds (2 hours).
   To change inactivity duration:
     - 15 minutes = 900
     - 30 minutes = 1800
     - 1 hour     = 3600
     - 2 hours    = 7200 (default)
============================================================ */
if ((isset($_SESSION['user_id']) || isset($_SESSION['admin_id'])) &&
    isset($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > 7200) {
    session_unset();
    session_destroy();
    $isAdmin = strpos($_SERVER['PHP_SELF'], '/admin/') !== false;
    header('Location: ' . ($isAdmin ? '../login.php' : '/filrise/login.php'));
    exit;
}
// Track last activity timestamp for logged-in sessions [SEARCH TAG: SESSION_ACTIVITY_TRACKER]
if (isset($_SESSION['user_id']) || isset($_SESSION['admin_id'])) {
    $_SESSION['last_activity'] = time();
}

/* ============================================================
   2. SECURITY HEADERS
============================================================ */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header_remove('X-Powered-By');
header("Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://code.jquery.com; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
    "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; " .
    "img-src 'self' data: blob:; " .
    "connect-src 'self' https://api.anthropic.com; " .
    "frame-ancestors 'self'; " .
    "form-action 'self';"
);
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
@ini_set('expose_php', '0');

/* ============================================================
   3. RATE LIMITER — blocks brute force on any endpoint
   [SEARCH TAG: RATE_LIMIT / MAX_LOGIN_ATTEMPTS / LOCKOUT_TIME / BRUTE_FORCE_PROTECTION]
   Usage: rate_limit('login', 5, 300)  → max 5 attempts per 5 min (300 sec)
============================================================ */
function rate_limit(string $key, int $max = 10, int $window = 300): void {
    $skey = 'rl_' . $key . '_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
    if (!isset($_SESSION[$skey])) {
        $_SESSION[$skey] = ['count' => 0, 'start' => time()];
    }
    // Reset window if expired
    if ((time() - $_SESSION[$skey]['start']) > $window) {
        $_SESSION[$skey] = ['count' => 0, 'start' => time()];
    }
    $_SESSION[$skey]['count']++;
    if ($_SESSION[$skey]['count'] > $max) {
        $wait = $window - (time() - $_SESSION[$skey]['start']);
        http_response_code(429);
        die('<p style="font-family:sans-serif;padding:40px;color:#dc2626;">
            <strong>Too many requests.</strong> Please wait ' . ceil($wait/60) . ' minute(s) and try again.
        </p>');
    }
}

/* ============================================================
   4. CSRF TOKEN GENERATION & VERIFICATION
   [SEARCH TAG: CSRF_PROTECTION / CSRF_TOKEN / CSRF_VERIFY / FORM_SECURITY]
============================================================ */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_verify(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $submitted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:40px;color:#dc2626;">
            <strong>Security Error:</strong> Invalid or missing CSRF token.
            <a href="javascript:history.back()">Go back</a>
        </p>');
    }
}

/* ============================================================
   5. SECURE FILE UPLOAD VALIDATOR
   [SEARCH TAG: FILE_UPLOAD_VALIDATION / MAX_FILE_SIZE / ALLOWED_IMAGE_EXTENSIONS / IMAGE_UPLOAD_SECURITY]
   Usage: validate_upload($_FILES['image'], ['jpg','png','gif','webp'], 2)  → 2MB max
============================================================ */
function validate_upload(array $file, array $allowed_exts = ['jpg','jpeg','png','gif','webp'], float $max_mb = 2): array {
    $errors = [];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload error.';
        return $errors;
    }

    // Check real file size (Default: 2MB) [SEARCH TAG: MAX_UPLOAD_SIZE_BYTES]
    $max_bytes = $max_mb * 1024 * 1024;
    if ($file['size'] > $max_bytes) {
        $errors[] = "File too large. Maximum size is {$max_mb}MB.";
    }

    // Check extension [SEARCH TAG: ALLOWED_FILE_EXTENSIONS]
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) {
        $errors[] = 'Invalid file type. Allowed: ' . implode(', ', $allowed_exts);
    }

    // Check REAL mime type (not just extension — prevents disguised PHP files)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed_mimes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    if (!in_array($mime, $allowed_mimes, true)) {
        $errors[] = 'File content does not match its extension. Upload rejected.';
    }

    // Verify it is a valid image (prevents image-based PHP injection)
    if (empty($errors)) {
        $imgInfo = @getimagesize($file['tmp_name']);
        if ($imgInfo === false) {
            $errors[] = 'File is not a valid image.';
        }
    }

    return $errors;
}

/**
 * Generate a safe filename — strips dangerous characters
 */
function safe_filename(string $original_name): string {
    $ext  = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    // Never allow executable extensions
    $blocked = ['php','php3','php4','php5','phtml','phar','asp','aspx','exe','sh','cgi','pl'];
    if (in_array($ext, $blocked, true)) {
        die('Blocked file type.');
    }
    return bin2hex(random_bytes(16)) . '.' . $ext;
}

/* ============================================================
   6. OUTPUT ESCAPING HELPERS (XSS prevention)
============================================================ */
function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function attr(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/* ============================================================
   7. INPUT SANITIZATION HELPERS
============================================================ */
function clean_int(mixed $val): int {
    return (int) filter_var($val, FILTER_SANITIZE_NUMBER_INT);
}

function clean_float(mixed $val): float {
    return (float) filter_var($val, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

function clean_str(mixed $val, int $max = 255): string {
    $val = strip_tags(trim((string)$val));
    return mb_substr($val, 0, $max);
}

/* ============================================================
   8. SESSION SECURITY HELPERS
============================================================ */
function session_regenerate_secure(): void {
    session_regenerate_id(true);
    // Rotate CSRF token on login too
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function require_auth(string $session_key = 'user_id', string $redirect = '../login.php'): void {
    if (empty($_SESSION[$session_key])) {
        header('Location: ' . $redirect);
        exit;
    }
}
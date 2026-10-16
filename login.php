<?php
/**
 * login.php — Unified Login Page (Staff + Admin)
 *
 * Handles authentication for both admin users (admins table) and
 * branch staff users (users table). On successful login:
 *   - Admins/Super Admins → redirected to admin/admin_dashboard.php
 *   - Branch Staff → redirected to dashboard.php
 *
 * Security features:
 *   - Rate limiting (5 attempts per 5 minutes per IP)
 *   - CSRF token verification
 *   - Session regeneration on login (prevents session fixation)
 *   - Brute-force delay (1 second sleep on failed login)
 *   - Self-healing schema: auto-adds 'role' columns if missing
 */
require 'config.php';
$errors = [];

// ── Self-healing schema: role columns (safe to run repeatedly) ──
// admins.role: 'super_admin' | 'admin'   users.role: reserved for future granular staff permissions
try { $pdo->exec("ALTER TABLE admins ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE users  ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'staff'"); } catch (Exception $e) {}

/* ============================================================
   [SEARCH TAG: LOGIN_HANDLER / LOGIN_AUTHENTICATION / LOGIN_PROCESS / SUBMIT_LOGIN]
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [SEARCH TAG: LOGIN_RATE_LIMIT / BRUTE_FORCE_RATE_LIMIT]
    rate_limit('login', 5, 300);
    csrf_verify();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // 1) Try the admins table first (Super Admin / Admin — company-wide accounts)
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2) Otherwise try the users table (Branch Staff accounts)
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    /* ============================================================
       [SEARCH TAG: ADMIN_LOGIN_CHECK / ADMIN_SESSION_VARS / ADMIN_REDIRECT / PASSWORD_VERIFY]
    ============================================================ */
    if ($admin && password_verify($password, $admin['password'])) {
        // Fix: Regenerate session ID on login (prevents session fixation)
        session_regenerate_secure();

        $_SESSION['admin_id']   = $admin['id'];
        $_SESSION['admin_name'] = $admin['fullname'];
        $_SESSION['role']       = $admin['role'] ?: 'admin'; // 'super_admin' or 'admin'
        header('Location: admin/admin_dashboard.php');
        exit;
    /* ============================================================
       [SEARCH TAG: STAFF_LOGIN_CHECK / STAFF_SESSION_VARS / STAFF_REDIRECT / USER_SESSION_START]
    ============================================================ */
    } elseif ($user && password_verify($password, $user['password'])) {
        // Fix: Regenerate session ID on login (prevents session fixation)
        session_regenerate_secure();

        $_SESSION['user_id']     = $user['id'];
        $_SESSION['username']    = $user['username'];
        $_SESSION['fullname']    = $user['fullname'];
        $_SESSION['branch_name'] = $user['branch_name'];
        $_SESSION['role']        = 'staff';
        header('Location: dashboard.php');
        exit;
    } else {
        // [SEARCH TAG: FAILED_LOGIN_DELAY / BRUTE_FORCE_SLEEP / INVALID_CREDENTIALS]
        sleep(1);
        $errors[] = 'Invalid username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Fil-Rise IMS — Login</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="7.png?v=3">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Poppins', sans-serif;
      min-height: 100vh;
      display: grid;
      grid-template-columns: 1fr 1fr;
      background: #f1f5f9;
    }

    /* ══════════════════════════
       LEFT — Branding
    ══════════════════════════ */
    .left-panel {
      background: linear-gradient(150deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 52px 56px;
      position: relative;
      overflow: hidden;
      min-height: 100vh;
    }

    /* decorative ring top-right */
    .left-panel::before {
      content: '';
      position: absolute;
      width: 480px; height: 480px;
      border-radius: 50%;
      border: 70px solid rgba(37,99,235,.07);
      top: -160px; right: -160px;
    }
    /* small ring bottom-left */
    .left-panel::after {
      content: '';
      position: absolute;
      width: 300px; height: 300px;
      border-radius: 50%;
      border: 50px solid rgba(245,158,11,.06);
      bottom: -80px; left: -80px;
    }

    .left-logo { width: 130px; position: relative; z-index: 1; }

    .left-middle { position: relative; z-index: 1; }

    .left-tagline {
      font-size: 40px; font-weight: 800;
      color: #f8fafc; line-height: 1.15;
      letter-spacing: -.5px; margin-bottom: 16px;
    }
    .left-tagline em {
      font-style: normal;
      background: linear-gradient(90deg, #f59e0b, #fcd34d);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .left-desc {
      font-size: 14px; color: #64748b;
      line-height: 1.8; max-width: 360px;
    }

    .feat-list {
      margin-top: 36px;
      display: flex; flex-direction: column; gap: 12px;
    }
    .feat-item {
      display: flex; align-items: center; gap: 12px;
    }
    .feat-dot {
      width: 32px; height: 32px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 15px; flex-shrink: 0;
    }
    .feat-text { font-size: 13px; font-weight: 500; color: #94a3b8; }

    .left-footer { position: relative; z-index: 1; font-size: 12px; color: #334155; }

    /* ══════════════════════════
       RIGHT — Form
    ══════════════════════════ */
    .right-panel {
      display: flex;
      align-items: center;
      justify-content: center;
      background: #fff;
      min-height: 100vh;
      padding: 40px 32px;
      position: relative;
    }

    /* top accent */
    .right-panel::before {
      content: '';
      position: absolute; top: 0; left: 0; right: 0; height: 4px;
      background: linear-gradient(90deg, #2563eb, #f59e0b);
    }

    .form-box {
      width: 100%;
      max-width: 380px;
    }

    .form-eyebrow {
      font-size: 11px; font-weight: 700;
      letter-spacing: 1.5px; text-transform: uppercase;
      color: #2563eb; margin-bottom: 10px;
    }
    .form-title {
      font-size: 30px; font-weight: 800;
      color: #0f172a; margin-bottom: 6px;
      letter-spacing: -.3px;
    }
    .form-sub {
      font-size: 13px; color: #94a3b8;
      margin-bottom: 36px;
    }

    .field-group { margin-bottom: 20px; }
    .field-group label {
      display: block; font-size: 11px; font-weight: 700;
      color: #374151; margin-bottom: 7px;
      text-transform: uppercase; letter-spacing: .6px;
    }
    .field-wrap { position: relative; }
    .field-icon {
      position: absolute; left: 14px; top: 50%;
      transform: translateY(-50%);
      font-size: 15px; color: #cbd5e1;
      pointer-events: none; transition: color .2s;
    }
    .field-wrap:focus-within .field-icon { color: #2563eb; }
    .field-wrap input {
      width: 100%;
      padding: 13px 16px 13px 44px;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      font-family: 'Poppins', sans-serif;
      font-size: 14px; color: #0f172a;
      outline: none; transition: .2s;
      background: #f8fafc;
    }
    .field-wrap input:focus {
      border-color: #2563eb; background: #fff;
      box-shadow: 0 0 0 4px rgba(37,99,235,.08);
    }

    .error-box {
      display: flex; align-items: center; gap: 10px;
      background: #fef2f2; border: 1px solid #fecaca;
      border-radius: 10px; padding: 11px 14px;
      font-size: 13px; color: #b91c1c;
      margin-bottom: 22px;
    }

    .btn-login {
      width: 100%; padding: 14px;
      background: #0f172a;
      color: #fff; font-family: 'Poppins', sans-serif;
      font-size: 15px; font-weight: 700;
      border: none; border-radius: 12px;
      cursor: pointer; transition: .2s;
      margin-top: 6px; letter-spacing: .2px;
    }
    .btn-login:hover {
      background: #1e293b;
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(15,23,42,.2);
    }

    .form-footer {
      margin-top: 28px; padding-top: 24px;
      border-top: 1px solid #f1f5f9;
      text-align: center;
      font-size: 12px; color: #cbd5e1;
    }

    @media (max-width: 768px) {
      body { grid-template-columns: 1fr; }
      .left-panel { display: none; }
      .right-panel { padding: 40px 24px; }
    }
  </style>
</head>
<body>

  <!-- LEFT -->
  <div class="left-panel">
    <img src="6.png" alt="Fil-Rise" class="left-logo">

    <div class="left-middle">
      <div class="left-tagline">
        Smarter sales.<br><em>Better results.</em>
      </div>
      <div class="left-desc">
        The complete inventory and sales management platform built for direct-selling businesses across the Philippines.
      </div>
      <div class="feat-list">
        <div class="feat-item">
          <div class="feat-dot" style="background:rgba(37,99,235,.15);"></div>
          <span class="feat-text">Real-time inventory tracking</span>
        </div>
        <div class="feat-item">
          <div class="feat-dot" style="background:rgba(245,158,11,.15);"></div>
          <span class="feat-text">AI-powered sales forecasting</span>
        </div>
        <div class="feat-item">
          <div class="feat-dot" style="background:rgba(16,185,129,.15);"></div>
          <span class="feat-text">Branch-wide analytics dashboard</span>
        </div>
      </div>
    </div>

    <div class="left-footer">© 2025 Fil-Rise Inventory Management System</div>
  </div>

  <!-- RIGHT -->
  <div class="right-panel">
    <div class="form-box">

      <div class="form-eyebrow">Fil-Rise IMS</div>
      <div class="form-title">Welcome back</div>
      <div class="form-sub">Sign in with your account to continue</div>

      <?php if (!empty($errors)): ?>
        <div class="error-box">
          <span>⚠</span>
          <span><?= htmlspecialchars($errors[0]) ?></span>
        </div>
      <?php endif; ?>

      <form method="post">
        <?= csrf_field() ?>
        <div class="field-group">
          <label>Username</label>
          <div class="field-wrap">
            <span class="field-icon">👤</span>
            <input type="text" name="username" placeholder="Enter your username" required autofocus>
          </div>
        </div>
        <div class="field-group">
          <label>Password</label>
          <div class="field-wrap">
            <span class="field-icon">🔒</span>
            <input type="password" name="password" placeholder="Enter your password" required>
          </div>
        </div>
        <button type="submit" name="login" class="btn-login">Sign In</button>
      </form>

      <div style="text-align:center;margin-top:16px;">
        <a href="forgot_password.php"
           style="font-size:12px;color:#2563eb;font-weight:600;text-decoration:none;">
          Forgot password?
        </a>
      </div>

      <div class="form-footer">© 2025 Fil-Rise IMS</div>
    </div>
  </div>

</body>
</html>
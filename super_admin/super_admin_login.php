<?php
require '../config.php';
$errors = [];

// ── Self-healing schema: role column (safe to run repeatedly) ──
try { $pdo->exec("ALTER TABLE admins ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin'"); } catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limit: max 5 login attempts per 5 minutes per IP
    rate_limit('super_admin_login', 5, 300);
    csrf_verify();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin && password_verify($password, $admin['password']) && ($admin['role'] ?? 'admin') === 'super_admin') {
        // Fix: Regenerate session ID on login (prevents session fixation)
        session_regenerate_secure();

        $_SESSION['admin_id']   = $admin['id'];
        $_SESSION['admin_name'] = $admin['fullname'];
        $_SESSION['role']       = 'super_admin';
        header('Location: super_admin_dashboard.php');
        exit;
    } elseif ($admin && password_verify($password, $admin['password'])) {
        // Valid admin credentials, but not a Super Admin — send them to the right portal
        sleep(1);
        $errors[] = 'This account is not a Super Admin. Please use the regular login page.';
    } else {
        // Fix: Brute-force delay (makes automated attacks slower)
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
  <title>Super Admin Portal — Fil-Rise IMS</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="../7.png?v=3">
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
       LEFT — Branding (darker / restricted-access tone)
    ══════════════════════════ */
    .left-panel {
      background: linear-gradient(150deg, #1c0f0f 0%, #2a1414 50%, #1c0f0f 100%);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 52px 56px;
      position: relative;
      overflow: hidden;
      min-height: 100vh;
    }

    .left-panel::before {
      content: '';
      position: absolute;
      width: 480px; height: 480px;
      border-radius: 50%;
      border: 70px solid rgba(220,38,38,.08);
      top: -160px; right: -160px;
    }
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

    .badge-restricted {
      display: inline-flex; align-items: center; gap: 8px;
      background: rgba(220,38,38,.15); border: 1px solid rgba(220,38,38,.35);
      color: #fca5a5; font-size: 11px; font-weight: 700;
      letter-spacing: 1px; text-transform: uppercase;
      padding: 6px 14px; border-radius: 999px; margin-bottom: 20px;
    }

    .left-tagline {
      font-size: 38px; font-weight: 800;
      color: #f8fafc; line-height: 1.15;
      letter-spacing: -.5px; margin-bottom: 16px;
    }
    .left-tagline em {
      font-style: normal;
      background: linear-gradient(90deg, #dc2626, #f59e0b);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .left-desc {
      font-size: 14px; color: #94706e;
      line-height: 1.8; max-width: 360px;
    }

    .feat-list { margin-top: 36px; display: flex; flex-direction: column; gap: 12px; }
    .feat-item { display: flex; align-items: center; gap: 12px; }
    .feat-dot {
      width: 32px; height: 32px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 15px; flex-shrink: 0; background: rgba(220,38,38,.15);
    }
    .feat-text { font-size: 13px; font-weight: 500; color: #cbb0af; }

    .left-footer { position: relative; z-index: 1; font-size: 12px; color: #4b2e2c; }

    /* ══════════════════════════
       RIGHT — Form
    ══════════════════════════ */
    .right-panel {
      display: flex; align-items: center; justify-content: center;
      background: #fff; min-height: 100vh; padding: 40px 32px; position: relative;
    }
    .right-panel::before {
      content: '';
      position: absolute; top: 0; left: 0; right: 0; height: 4px;
      background: linear-gradient(90deg, #dc2626, #f59e0b);
    }

    .form-box { width: 100%; max-width: 380px; }

    .form-eyebrow {
      font-size: 11px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase;
      color: #dc2626; margin-bottom: 10px;
    }
    .form-title { font-size: 30px; font-weight: 800; color: #0f172a; margin-bottom: 6px; letter-spacing: -.3px; }
    .form-sub { font-size: 13px; color: #94a3b8; margin-bottom: 36px; }

    .field-group { margin-bottom: 20px; }
    .field-group label {
      display: block; font-size: 11px; font-weight: 700; color: #374151;
      margin-bottom: 7px; text-transform: uppercase; letter-spacing: .6px;
    }
    .field-wrap { position: relative; }
    .field-icon {
      position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
      font-size: 15px; color: #cbd5e1; pointer-events: none; transition: color .2s;
    }
    .field-wrap:focus-within .field-icon { color: #dc2626; }
    .field-wrap input {
      width: 100%; padding: 13px 16px 13px 44px;
      border: 1.5px solid #e2e8f0; border-radius: 12px;
      font-family: 'Poppins', sans-serif; font-size: 14px; color: #0f172a;
      outline: none; transition: .2s; background: #f8fafc;
    }
    .field-wrap input:focus {
      border-color: #dc2626; background: #fff;
      box-shadow: 0 0 0 4px rgba(220,38,38,.08);
    }

    .error-box {
      display: flex; align-items: center; gap: 10px;
      background: #fef2f2; border: 1px solid #fecaca;
      border-radius: 10px; padding: 11px 14px;
      font-size: 13px; color: #b91c1c; margin-bottom: 22px;
    }

    .btn-login {
      width: 100%; padding: 14px; background: #1c0f0f; color: #fff;
      font-family: 'Poppins', sans-serif; font-size: 15px; font-weight: 700;
      border: none; border-radius: 12px; cursor: pointer; transition: .2s;
      margin-top: 6px; letter-spacing: .2px;
    }
    .btn-login:hover {
      background: #2a1414; transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(28,15,15,.25);
    }

    .form-footer {
      margin-top: 28px; padding-top: 24px; border-top: 1px solid #f1f5f9;
      text-align: center; font-size: 12px; color: #cbd5e1;
    }
    .back-link {
      display: block; text-align: center; margin-top: 16px;
      font-size: 12px; color: #94a3b8; font-weight: 600; text-decoration: none;
    }
    .back-link:hover { color: #dc2626; }

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
    <img src="../6.png" alt="Fil-Rise" class="left-logo">

    <div class="left-middle">
      <div class="badge-restricted">🔒 Restricted Access</div>
      <div class="left-tagline">
        Super Admin<br><em>Control Center.</em>
      </div>
      <div class="left-desc">
        Full oversight of admins, staff, roles, and branch-level feature access across Fil-Rise IMS.
      </div>
      <div class="feat-list">
        <div class="feat-item">
          <div class="feat-dot"></div>
          <span class="feat-text">Manage admin &amp; staff roles</span>
        </div>
        <div class="feat-item">
          <div class="feat-dot"></div>
          <span class="feat-text">Lock/unlock modules per branch</span>
        </div>
        <div class="feat-item">
          <div class="feat-dot"></div>
          <span class="feat-text">View every user across the system</span>
        </div>
      </div>
    </div>

    <div class="left-footer">© 2025 Fil-Rise Inventory Management System</div>
  </div>

  <!-- RIGHT -->
  <div class="right-panel">
    <div class="form-box">

      <div class="form-eyebrow">Super Admin Portal</div>
      <div class="form-title">Restricted sign in</div>
      <div class="form-sub">This login is for Super Admin accounts only</div>

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

      <a href="../login.php" class="back-link">← Not a Super Admin? Staff &amp; Admin login here</a>

      <div class="form-footer">© 2025 Fil-Rise IMS</div>
    </div>
  </div>

</body>
</html>
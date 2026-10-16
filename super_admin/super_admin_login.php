<?php
/**
 * super_admin/super_admin_login.php — Super Admin Dedicated Login Interface
 *
 * Authenticates super administrator accounts with rate-limiting and CSRF protection.
 */
require '../config.php';
$errors = [];

// -- Self-healing schema: role column (safe to run repeatedly) --
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
        // Valid admin credentials, but not a Super Admin -- send them to the right portal
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
  <title>Super Admin Portal - Fil-Rise IMS</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="../7.png?v=3">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Poppins', sans-serif;
      min-height: 100vh;
      background:
        radial-gradient(circle at 50% 30%, #2a1414 0%, #1c0f0f 60%);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      position: relative;
      overflow: hidden;
    }

    body::before {
      content: '';
      position: absolute;
      width: 620px; height: 620px;
      border-radius: 50%;
      border: 90px solid rgba(220,38,38,.05);
      top: -260px; left: 50%;
      transform: translateX(-50%);
      pointer-events: none;
    }
    body::after {
      content: '';
      position: absolute;
      width: 380px; height: 380px;
      border-radius: 50%;
      border: 60px solid rgba(245,158,11,.04);
      bottom: -220px; right: -120px;
      pointer-events: none;
    }

    .wrap {
      position: relative;
      z-index: 1;
    }

    .wrap {
      width: 100%;
      max-width: 380px;
    }

    .card {
      background: #fff;
      border-radius: 10px;
      padding: 40px 36px;
    }

    .logo-row { display: flex; align-items: center; gap: 8px; margin-bottom: 36px; }
    .logo-row img { width: 26px; height: 26px; }
    .logo-word { font-size: 17px; font-weight: 700; color: #18181b; }
    .logo-tag { font-size: 12px; color: #a1a1aa; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; }

    .badge-restricted {
      display: inline-flex; align-items: center; gap: 6px;
      background: #fef2f2; border: 1px solid #fecaca;
      color: #dc2626; font-size: 10px; font-weight: 700;
      letter-spacing: .6px; text-transform: uppercase;
      padding: 4px 10px; border-radius: 999px; margin-bottom: 22px;
    }

    .field-group { margin-bottom: 22px; }
    .field-group label {
      display: block; font-size: 12px; font-weight: 600; color: #52525b;
      margin-bottom: 6px;
    }
    .field-group input {
      width: 100%;
      border: none;
      border-bottom: 1px solid #d4d4d8;
      padding: 6px 2px;
      font-family: 'Poppins', sans-serif; font-size: 14px; color: #18181b;
      outline: none;
      background: transparent;
    }
    .field-group input:focus { border-bottom-color: #dc2626; }

    .error-box {
      background: #fef2f2; border: 1px solid #fecaca;
      border-radius: 8px; padding: 10px 12px;
      font-size: 13px; color: #b91c1c; margin-bottom: 20px;
    }

    .btn-login {
      width: 100%; padding: 12px; background: #dc2626; color: #fff;
      font-family: 'Poppins', sans-serif; font-size: 14px; font-weight: 600;
      border: none; border-radius: 8px; cursor: pointer; margin-top: 4px;
    }
    .btn-login:hover { background: #b91c1c; }

    .back-link {
      display: block; text-align: center; margin-top: 18px;
      font-size: 12px; color: #71717a; font-weight: 500; text-decoration: none;
    }
    .back-link:hover { color: #dc2626; }

    .footer {
      text-align: center; margin-top: 28px; padding: 0 20px 40px;
      color: #a1a1aa; font-size: 11px; line-height: 1.7;
    }
    .footer a { color: #a1a1aa; }
  </style>
</head>
<body>

  <div class="wrap">
    <div class="card">
      <div class="logo-row">
        <img src="../7.png" alt="Fil-Rise">
        <span class="logo-word">FIL-RISE</span>
        <span class="logo-tag">Super Admin</span>
      </div>

      <div class="badge-restricted">Restricted Access</div>

      <?php if (!empty($errors)): ?>
        <div class="error-box"><?= htmlspecialchars($errors[0]) ?></div>
      <?php endif; ?>

      <form method="post">
        <?= csrf_field() ?>
        <div class="field-group">
          <label>Username</label>
          <input type="text" name="username" required autofocus>
        </div>
        <div class="field-group">
          <label>Password</label>
          <input type="password" name="password" required>
        </div>
        <button type="submit" name="login" class="btn-login">Log In</button>
      </form>

      <a href="../login.php" class="back-link">Not a Super Admin? Staff &amp; Admin login here</a>
    </div>

    <div class="footer">
      &copy; 2025 Fil-Rise Inventory Management System<br>
      This portal is for Super Admin accounts only.
    </div>
  </div>

</body>
</html>
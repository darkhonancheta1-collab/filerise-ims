<?php
require '../config.php';

/*
  SECURITY: 3-layer verification
  Step 1 — Username must exist in admins table
  Step 2 — Must answer the secret security question correctly
  Step 3 — Set new password (with confirmation)

  HOW TO SET SECRET ANSWER:
  Run this SQL once in phpMyAdmin to add the column and set your answer:

  ALTER TABLE admins ADD COLUMN secret_answer VARCHAR(255) NULL;
  UPDATE admins SET secret_answer = MD5('your_secret_answer_here') WHERE username = 'your_admin_username';

  The answer is stored as MD5 hash. Case-insensitive comparison is used.
*/

// Rate limiting — max 5 attempts per session
if (!isset($_SESSION)) session_start();
if (!isset($_SESSION['fp_attempts'])) $_SESSION['fp_attempts'] = 0;
if (!isset($_SESSION['fp_locked_until'])) $_SESSION['fp_locked_until'] = 0;

$step     = (int)($_POST['step'] ?? $_GET['step'] ?? 1);
$error    = '';
$success  = '';
$username = trim($_POST['username'] ?? '');

// Check lockout
$locked = false;
if ($_SESSION['fp_locked_until'] > time()) {
  $wait   = ceil(($_SESSION['fp_locked_until'] - time()) / 60);
  $error  = "Too many failed attempts. Please wait {$wait} minute(s) before trying again.";
  $step   = 1;
  $locked = true;
}

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {

  /* ── STEP 1: Verify username ── */
  if (isset($_POST['check_username'])) {
    if (!$username) {
      $error = 'Please enter your admin username.';
      $step  = 1;
    } else {
      $stmt = $pdo->prepare('SELECT id, username, secret_answer FROM admins WHERE username = ?');
      $stmt->execute([$username]);
      $admin = $stmt->fetch();

      if (!$admin) {
        $_SESSION['fp_attempts']++;
        if ($_SESSION['fp_attempts'] >= 5) {
          $_SESSION['fp_locked_until'] = time() + 1800; // 30 min lockout
          $_SESSION['fp_attempts']     = 0;
          $error = 'Too many failed attempts. Locked for 30 minutes.';
          $step  = 1;
        } else {
          $remaining = 5 - $_SESSION['fp_attempts'];
          $error = "Username not found. {$remaining} attempt(s) remaining.";
          $step  = 1;
        }
      } elseif (empty($admin['secret_answer'])) {
        // No security answer set — cannot reset via this method
        $error = 'Password reset is not configured for this account. Please contact your system administrator.';
        $step  = 1;
      } else {
        $_SESSION['fp_username'] = $username;
        $_SESSION['fp_admin_id'] = $admin['id'];
        $step = 2;
      }
    }
  }

  /* ── STEP 2: Verify secret answer ── */
  elseif (isset($_POST['check_answer'])) {
    $answer = strtolower(trim($_POST['secret_answer'] ?? ''));
    $uid    = $_SESSION['fp_admin_id'] ?? 0;

    if (!$answer || !$uid) {
      $error = 'Session expired. Please start over.';
      $step  = 1;
    } else {
      $stmt = $pdo->prepare('SELECT secret_answer FROM admins WHERE id = ?');
      $stmt->execute([$uid]);
      $row = $stmt->fetch();

      if ($row && md5($answer) === $row['secret_answer']) {
        $_SESSION['fp_verified'] = true;
        $_SESSION['fp_attempts'] = 0;
        $step = 3;
      } else {
        $_SESSION['fp_attempts']++;
        if ($_SESSION['fp_attempts'] >= 5) {
          $_SESSION['fp_locked_until'] = time() + 1800;
          $_SESSION['fp_attempts']     = 0;
          // Clear session data
          unset($_SESSION['fp_username'], $_SESSION['fp_admin_id'], $_SESSION['fp_verified']);
          $error = 'Too many failed attempts. Locked for 30 minutes.';
          $step  = 1;
        } else {
          $remaining = 5 - $_SESSION['fp_attempts'];
          $error = "Wrong answer. {$remaining} attempt(s) remaining.";
          $step  = 2;
        }
      }
    }
  }

  /* ── STEP 3: Reset password ── */
  elseif (isset($_POST['reset_password'])) {
    $new_pass  = $_POST['new_password']      ?? '';
    $conf_pass = $_POST['confirm_password']  ?? '';
    $uid       = $_SESSION['fp_admin_id']    ?? 0;
    $verified  = $_SESSION['fp_verified']    ?? false;

    if (!$verified || !$uid) {
      $error = 'Session expired. Please start over.';
      $step  = 1;
    } elseif (!$new_pass || !$conf_pass) {
      $error = 'Please fill all fields.';
      $step  = 3;
    } elseif ($new_pass !== $conf_pass) {
      $error = 'Passwords do not match.';
      $step  = 3;
    } elseif (strlen($new_pass) < 8) {
      $error = 'Password must be at least 8 characters.';
      $step  = 3;
    } elseif (!preg_match('/[A-Z]/', $new_pass) || !preg_match('/[0-9]/', $new_pass)) {
      $error = 'Password must contain at least one uppercase letter and one number.';
      $step  = 3;
    } else {
      $hash = password_hash($new_pass, PASSWORD_DEFAULT);
      $pdo->prepare('UPDATE admins SET password = ? WHERE id = ?')->execute([$hash, $uid]);
      // Clear all session reset data
      unset($_SESSION['fp_username'], $_SESSION['fp_admin_id'], $_SESSION['fp_verified'], $_SESSION['fp_attempts']);
      $success = 'Password reset successfully! You can now log in with your new password.';
      $step    = 4;
    }
  }
}

// Restore step from session if going back
if ($step === 2 && !isset($_SESSION['fp_username'])) $step = 1;
if ($step === 3 && !($_SESSION['fp_verified'] ?? false)) $step = 1;

$username = $_SESSION['fp_username'] ?? $username;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot Password — Fil-Rise Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="../7.png?v=3">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Poppins', sans-serif;
      min-height: 100vh; display: flex;
      align-items: center; justify-content: center;
      background: #0f172a; position: relative; overflow: hidden;
    }
    body::before {
      content: ''; position: absolute;
      width: 600px; height: 600px;
      background: radial-gradient(circle, rgba(37,99,235,.18) 0%, transparent 70%);
      top: -200px; left: -200px; border-radius: 50%;
      animation: blob1 8s ease-in-out infinite;
    }
    body::after {
      content: ''; position: absolute;
      width: 500px; height: 500px;
      background: radial-gradient(circle, rgba(245,158,11,.12) 0%, transparent 70%);
      bottom: -150px; right: -150px; border-radius: 50%;
      animation: blob2 10s ease-in-out infinite;
    }
    @keyframes blob1 { 0%,100%{transform:translate(0,0) scale(1)} 50%{transform:translate(40px,30px) scale(1.1)} }
    @keyframes blob2 { 0%,100%{transform:translate(0,0) scale(1)} 50%{transform:translate(-30px,-40px) scale(1.08)} }

    .card {
      position: relative; z-index: 1;
      width: 440px; max-width: calc(100vw - 32px);
      background: rgba(255,255,255,.04);
      backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,.1);
      border-radius: 24px; padding: 36px 40px;
      box-shadow: 0 24px 60px rgba(0,0,0,.5);
      animation: slideUp .5s ease-out;
    }
    @keyframes slideUp { from{opacity:0;transform:translateY(24px)} to{opacity:1;transform:translateY(0)} }

    .card-accent {
      position: absolute; top: 0; left: 40px; right: 40px; height: 3px;
      background: linear-gradient(90deg,#2563eb,#f59e0b,#2563eb);
      border-radius: 0 0 4px 4px;
    }

    .logo-wrap { display:flex; justify-content:center; margin-bottom:20px; }
    .logo-wrap img { width: 90px; }

    .badge {
      display:flex; align-items:center; justify-content:center;
      gap:8px; margin-bottom:18px;
    }
    .badge-icon {
      width:36px; height:36px; border-radius:10px;
      background:linear-gradient(135deg,#1d4ed8,#2563eb);
      display:flex; align-items:center; justify-content:center; font-size:16px;
    }
    .badge-title { font-size:14px; font-weight:700; color:#fff; }
    .badge-sub   { font-size:11px; color:#64748b; }

    /* Step indicator */
    .steps {
      display:flex; align-items:center; justify-content:center;
      gap:0; margin-bottom:22px;
    }
    .step-item {
      display:flex; flex-direction:column; align-items:center; gap:4px;
    }
    .step-circle {
      width:28px; height:28px; border-radius:50%;
      display:flex; align-items:center; justify-content:center;
      font-size:12px; font-weight:700;
      background:rgba(255,255,255,.08); color:#64748b;
      border:1.5px solid rgba(255,255,255,.1); transition:.3s;
    }
    .step-circle.active { background:#2563eb; color:#fff; border-color:#2563eb; }
    .step-circle.done   { background:#16a34a; color:#fff; border-color:#16a34a; }
    .step-label { font-size:9px; color:#64748b; white-space:nowrap; }
    .step-label.active { color:#60a5fa; }
    .step-label.done   { color:#4ade80; }
    .step-line { width:40px; height:1.5px; background:rgba(255,255,255,.1); margin-bottom:14px; }
    .step-line.done { background:#16a34a; }

    h2 { font-size:20px; font-weight:800; color:#f8fafc; text-align:center; margin-bottom:4px; }
    .subtitle { font-size:12px; color:#64748b; text-align:center; margin-bottom:22px; }

    .security-note {
      background:rgba(245,158,11,.1); border:1px solid rgba(245,158,11,.25);
      border-radius:10px; padding:10px 14px; margin-bottom:18px;
      font-size:12px; color:#fbbf24; display:flex; gap:8px; align-items:flex-start;
    }

    .field-group { margin-bottom:14px; }
    .field-group label {
      display:block; font-size:11px; font-weight:600; color:#94a3b8;
      margin-bottom:5px; text-transform:uppercase; letter-spacing:.6px;
    }
    .field-wrap { position:relative; }
    .field-icon {
      position:absolute; left:13px; top:50%; transform:translateY(-50%);
      font-size:15px; color:#475569; pointer-events:none;
    }
    .field-wrap input {
      width:100%; padding:12px 14px 12px 42px;
      background:rgba(255,255,255,.06);
      border:1.5px solid rgba(255,255,255,.1);
      border-radius:12px; font-family:'Poppins',sans-serif;
      font-size:13px; color:#f1f5f9; outline:none; transition:.2s;
    }
    .field-wrap input::placeholder { color:#475569; }
    .field-wrap input:focus {
      border-color:#2563eb; background:rgba(37,99,235,.08);
      box-shadow:0 0 0 3px rgba(37,99,235,.12);
    }

    .pwd-hint {
      font-size:11px; color:#64748b; margin-top:5px; padding-left:2px;
    }

    .error-box {
      background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25);
      border-radius:10px; padding:10px 14px; font-size:13px; color:#fca5a5;
      margin-bottom:16px; display:flex; align-items:flex-start; gap:8px;
    }
    .success-box {
      background:rgba(22,163,74,.12); border:1px solid rgba(22,163,74,.25);
      border-radius:10px; padding:10px 14px; font-size:13px; color:#86efac;
      margin-bottom:16px; display:flex; align-items:flex-start; gap:8px;
    }

    .btn {
      width:100%; padding:12px;
      background:linear-gradient(135deg,#1d4ed8,#2563eb);
      color:#fff; font-family:'Poppins',sans-serif;
      font-size:14px; font-weight:700; border:none;
      border-radius:12px; cursor:pointer; transition:.2s; margin-top:6px;
      box-shadow:0 4px 16px rgba(37,99,235,.35);
    }
    .btn:hover { background:linear-gradient(135deg,#1e40af,#1d4ed8); transform:translateY(-1px); }
    .btn-green {
      background:linear-gradient(135deg,#16a34a,#22c55e);
      box-shadow:0 4px 16px rgba(22,163,74,.35);
    }
    .btn-green:hover { background:linear-gradient(135deg,#15803d,#16a34a); }

    .back-link {
      display:block; text-align:center; margin-top:16px;
      font-size:12px; color:#475569; text-decoration:none; transition:.2s;
    }
    .back-link:hover { color:#94a3b8; }
    .footer-note { text-align:center; margin-top:20px; font-size:11px; color:#334155; }
  </style>
</head>
<body>
<div class="card">
  <div class="card-accent"></div>

  <div class="logo-wrap"><img src="../7.png" alt="Fil-Rise"></div>

  <div class="badge">
    <div class="badge-icon">🔑</div>
    <div>
      <div class="badge-title">Password Recovery</div>
      <div class="badge-sub">Main Admin Portal</div>
    </div>
  </div>

  <!-- Step indicator -->
  <div class="steps">
    <div class="step-item">
      <div class="step-circle <?= $step===1?'active':($step>1?'done':'') ?>">
        <?= $step>1 ? '✓' : '1' ?>
      </div>
      <div class="step-label <?= $step===1?'active':($step>1?'done':'') ?>">Username</div>
    </div>
    <div class="step-line <?= $step>1?'done':'' ?>"></div>
    <div class="step-item">
      <div class="step-circle <?= $step===2?'active':($step>2?'done':'') ?>">
        <?= $step>2 ? '✓' : '2' ?>
      </div>
      <div class="step-label <?= $step===2?'active':($step>2?'done':'') ?>">Security</div>
    </div>
    <div class="step-line <?= $step>2?'done':'' ?>"></div>
    <div class="step-item">
      <div class="step-circle <?= $step===3?'active':($step>3?'done':'') ?>">
        <?= $step>3 ? '✓' : '3' ?>
      </div>
      <div class="step-label <?= $step===3?'active':($step>3?'done':'') ?>">New Password</div>
    </div>
    <div class="step-line <?= $step>3?'done':'' ?>"></div>
    <div class="step-item">
      <div class="step-circle <?= $step===4?'active':'' ?>">4</div>
      <div class="step-label <?= $step===4?'active':'' ?>">Done</div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="error-box">⚠ <span><?= htmlspecialchars($error) ?></span></div>
  <?php endif; ?>

  <?php if ($step === 1): ?>
    <h2>Forgot Password</h2>
    <div class="subtitle">Enter your admin username to begin</div>
    <form method="POST">
      <input type="hidden" name="step" value="1">
      <div class="field-group">
        <label>Admin Username</label>
        <div class="field-wrap">
          <span class="field-icon">👤</span>
          <input type="text" name="username" placeholder="Enter your admin username"
                 value="<?= htmlspecialchars($username) ?>" required autofocus <?= $locked?'disabled':'' ?>>
        </div>
      </div>
      <button type="submit" name="check_username" class="btn" <?= $locked?'disabled':'' ?>>Continue →</button>
    </form>

  <?php elseif ($step === 2): ?>
    <h2>Security Verification</h2>
    <div class="subtitle">Answer your security question to continue</div>
    <div class="security-note">
      🛡️ <span>This extra step ensures only the real admin can reset the password.</span>
    </div>
    <form method="POST">
      <input type="hidden" name="step" value="2">
      <input type="hidden" name="username" value="<?= htmlspecialchars($username) ?>">
      <div class="field-group">
        <label>Security Question</label>
        <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:11px 14px;font-size:13px;color:#94a3b8;margin-bottom:10px;">
          What is the name of the FIL-RISE company owner?
        </div>
      </div>
      <div class="field-group">
        <label>Your Answer</label>
        <div class="field-wrap">
          <span class="field-icon">🔐</span>
          <input type="text" name="secret_answer" placeholder="Type your answer here"
                 required autofocus autocomplete="off">
        </div>
      </div>
      <button type="submit" name="check_answer" class="btn">Verify Answer →</button>
    </form>

  <?php elseif ($step === 3): ?>
    <h2>Set New Password</h2>
    <div class="subtitle">Choose a strong new password</div>
    <form method="POST">
      <input type="hidden" name="step" value="3">
      <div class="field-group">
        <label>New Password</label>
        <div class="field-wrap">
          <span class="field-icon">🔒</span>
          <input type="password" name="new_password" placeholder="Min. 8 chars, 1 uppercase, 1 number"
                 required autofocus>
        </div>
        <div class="pwd-hint">Must be at least 8 characters, include 1 uppercase letter and 1 number</div>
      </div>
      <div class="field-group">
        <label>Confirm New Password</label>
        <div class="field-wrap">
          <span class="field-icon">🔒</span>
          <input type="password" name="confirm_password" placeholder="Re-enter new password" required>
        </div>
      </div>
      <button type="submit" name="reset_password" class="btn">Reset Password</button>
    </form>

  <?php elseif ($step === 4): ?>
    <h2>Password Reset!</h2>
    <div class="subtitle">Your password has been updated successfully</div>
    <div class="success-box">✓ <span><?= htmlspecialchars($success) ?></span></div>
    <a href="admin_login.php" class="btn btn-green"
       style="display:block;text-align:center;text-decoration:none;padding:12px;border-radius:12px;">
      Go to Login
    </a>
  <?php endif; ?>

  <?php if ($step < 4): ?>
    <a href="admin_login.php" class="back-link">← Back to Login</a>
  <?php endif; ?>

  <div class="footer-note">© 2025 Fil-Rise Inventory Management System</div>
</div>
</body>
</html>
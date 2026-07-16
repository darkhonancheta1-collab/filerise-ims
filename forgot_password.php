<?php
require 'config.php';

if (!isset($_SESSION)) session_start();
if (!isset($_SESSION['sfp_attempts']))    $_SESSION['sfp_attempts']    = 0;
if (!isset($_SESSION['sfp_locked_until'])) $_SESSION['sfp_locked_until'] = 0;

$step     = (int)($_POST['step'] ?? 1);
$error    = '';
$success  = '';
$username = trim($_POST['username'] ?? '');

// Check lockout
$locked = false;
if ($_SESSION['sfp_locked_until'] > time()) {
  $wait   = ceil(($_SESSION['sfp_locked_until'] - time()) / 60);
  $error  = "Too many failed attempts. Please wait {$wait} minute(s) before trying again.";
  $step   = 1;
  $locked = true;
}

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {

  /* ── STEP 1: Verify username ── */
  if (isset($_POST['check_username'])) {
    if (!$username) {
      $error = 'Please enter your username.';
      $step  = 1;
    } else {
      $stmt = $pdo->prepare('SELECT id, username, secret_answer FROM users WHERE username = ?');
      $stmt->execute([$username]);
      $user = $stmt->fetch();

      if (!$user) {
        $_SESSION['sfp_attempts']++;
        if ($_SESSION['sfp_attempts'] >= 5) {
          $_SESSION['sfp_locked_until'] = time() + 1800;
          $_SESSION['sfp_attempts']     = 0;
          $error = 'Too many failed attempts. Locked for 30 minutes.';
          $step  = 1;
        } else {
          $remaining = 5 - $_SESSION['sfp_attempts'];
          $error = "Username not found. {$remaining} attempt(s) remaining.";
          $step  = 1;
        }
      } elseif (empty($user['secret_answer'])) {
        $error = 'Password reset is not configured for this account. Please contact your branch admin.';
        $step  = 1;
      } else {
        $_SESSION['sfp_username'] = $username;
        $_SESSION['sfp_user_id']  = $user['id'];
        $step = 2;
      }
    }
  }

  /* ── STEP 2: Verify secret answer ── */
  elseif (isset($_POST['check_answer'])) {
    $answer = strtolower(trim($_POST['secret_answer'] ?? ''));
    $uid    = $_SESSION['sfp_user_id'] ?? 0;

    if (!$answer || !$uid) {
      $error = 'Session expired. Please start over.';
      $step  = 1;
    } else {
      $stmt = $pdo->prepare('SELECT secret_answer FROM users WHERE id = ?');
      $stmt->execute([$uid]);
      $row = $stmt->fetch();

      if ($row && md5($answer) === $row['secret_answer']) {
        $_SESSION['sfp_verified'] = true;
        $_SESSION['sfp_attempts'] = 0;
        $step = 3;
      } else {
        $_SESSION['sfp_attempts']++;
        if ($_SESSION['sfp_attempts'] >= 5) {
          $_SESSION['sfp_locked_until'] = time() + 1800;
          $_SESSION['sfp_attempts']     = 0;
          unset($_SESSION['sfp_username'], $_SESSION['sfp_user_id'], $_SESSION['sfp_verified']);
          $error = 'Too many failed attempts. Locked for 30 minutes.';
          $step  = 1;
        } else {
          $remaining = 5 - $_SESSION['sfp_attempts'];
          $error = "Wrong answer. {$remaining} attempt(s) remaining.";
          $step  = 2;
        }
      }
    }
  }

  /* ── STEP 3: Reset password ── */
  elseif (isset($_POST['reset_password'])) {
    $new_pass  = $_POST['new_password']     ?? '';
    $conf_pass = $_POST['confirm_password'] ?? '';
    $uid       = $_SESSION['sfp_user_id']   ?? 0;
    $verified  = $_SESSION['sfp_verified']  ?? false;

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
      $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $uid]);
      unset($_SESSION['sfp_username'], $_SESSION['sfp_user_id'], $_SESSION['sfp_verified'], $_SESSION['sfp_attempts']);
      $success = 'Password reset successfully! You can now log in with your new password.';
      $step    = 4;
    }
  }
}

if ($step === 2 && !isset($_SESSION['sfp_username'])) $step = 1;
if ($step === 3 && !($_SESSION['sfp_verified'] ?? false)) $step = 1;

$username = $_SESSION['sfp_username'] ?? $username;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot Password — Fil-Rise IMS</title>
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

    /* ── LEFT panel — same as login.php ── */
    .left-panel {
      background: linear-gradient(150deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
      display: flex; flex-direction: column;
      justify-content: space-between;
      padding: 52px 56px;
      position: relative; overflow: hidden; min-height: 100vh;
    }
    .left-panel::before {
      content: ''; position: absolute;
      width: 480px; height: 480px; border-radius: 50%;
      border: 70px solid rgba(37,99,235,.07);
      top: -160px; right: -160px;
    }
    .left-panel::after {
      content: ''; position: absolute;
      width: 300px; height: 300px; border-radius: 50%;
      border: 50px solid rgba(245,158,11,.06);
      bottom: -80px; left: -80px;
    }
    .left-logo { width: 130px; position: relative; z-index: 1; }
    .left-middle { position: relative; z-index: 1; }
    .left-tagline {
      font-size: 40px; font-weight: 800; color: #f8fafc;
      line-height: 1.15; letter-spacing: -.5px; margin-bottom: 16px;
    }
    .left-tagline em {
      font-style: normal;
      background: linear-gradient(90deg,#f59e0b,#fcd34d);
      -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    }
    .left-desc { font-size: 14px; color: #64748b; line-height: 1.8; max-width: 360px; }
    .left-footer { position: relative; z-index: 1; font-size: 12px; color: #334155; }

    /* ── RIGHT panel ── */
    .right-panel {
      display: flex; align-items: center; justify-content: center;
      background: #fff; min-height: 100vh; padding: 40px 32px; position: relative;
    }
    .right-panel::before {
      content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
      background: linear-gradient(90deg, #2563eb, #f59e0b);
    }

    .form-box { width: 100%; max-width: 380px; }

    /* Step indicator */
    .steps {
      display: flex; align-items: center; margin-bottom: 28px; gap: 0;
    }
    .step-item { display: flex; flex-direction: column; align-items: center; gap: 4px; }
    .step-circle {
      width: 30px; height: 30px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 700;
      background: #f1f5f9; color: #94a3b8;
      border: 1.5px solid #e2e8f0; transition: .3s;
    }
    .step-circle.active { background: #2563eb; color: #fff; border-color: #2563eb; }
    .step-circle.done   { background: #16a34a; color: #fff; border-color: #16a34a; }
    .step-label { font-size: 9px; color: #94a3b8; white-space: nowrap; }
    .step-label.active { color: #2563eb; font-weight: 700; }
    .step-label.done   { color: #16a34a; }
    .step-line { flex: 1; height: 1.5px; background: #e2e8f0; margin-bottom: 14px; }
    .step-line.done { background: #16a34a; }

    .form-eyebrow {
      font-size: 11px; font-weight: 700; letter-spacing: 1.5px;
      text-transform: uppercase; color: #2563eb; margin-bottom: 8px;
    }
    .form-title { font-size: 26px; font-weight: 800; color: #0f172a; margin-bottom: 4px; }
    .form-sub   { font-size: 13px; color: #94a3b8; margin-bottom: 24px; }

    .security-note {
      background: #fffbeb; border: 1px solid #fde68a;
      border-radius: 10px; padding: 10px 14px; margin-bottom: 18px;
      font-size: 12px; color: #92400e;
      display: flex; gap: 8px; align-items: flex-start;
    }

    .question-box {
      background: #f8fafc; border: 1px solid #e2e8f0;
      border-radius: 10px; padding: 11px 14px;
      font-size: 13px; color: #64748b; margin-bottom: 12px;
    }

    .field-group { margin-bottom: 18px; }
    .field-group label {
      display: block; font-size: 11px; font-weight: 700; color: #374151;
      margin-bottom: 6px; text-transform: uppercase; letter-spacing: .6px;
    }
    .field-wrap { position: relative; }
    .field-icon {
      position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
      font-size: 15px; color: #cbd5e1; pointer-events: none; transition: color .2s;
    }
    .field-wrap:focus-within .field-icon { color: #2563eb; }
    .field-wrap input {
      width: 100%; padding: 13px 16px 13px 44px;
      border: 1.5px solid #e2e8f0; border-radius: 12px;
      font-family: 'Poppins', sans-serif; font-size: 14px; color: #0f172a;
      outline: none; transition: .2s; background: #f8fafc;
    }
    .field-wrap input:focus {
      border-color: #2563eb; background: #fff;
      box-shadow: 0 0 0 4px rgba(37,99,235,.08);
    }
    .pwd-hint { font-size: 11px; color: #94a3b8; margin-top: 5px; padding-left: 2px; }

    .error-box {
      display: flex; align-items: flex-start; gap: 10px;
      background: #fef2f2; border: 1px solid #fecaca;
      border-radius: 10px; padding: 11px 14px;
      font-size: 13px; color: #b91c1c; margin-bottom: 20px;
    }
    .success-box {
      display: flex; align-items: flex-start; gap: 10px;
      background: #f0fdf4; border: 1px solid #bbf7d0;
      border-radius: 10px; padding: 11px 14px;
      font-size: 13px; color: #166534; margin-bottom: 20px;
    }

    .btn-submit {
      width: 100%; padding: 13px;
      background: #0f172a; color: #fff;
      font-family: 'Poppins', sans-serif; font-size: 15px; font-weight: 700;
      border: none; border-radius: 12px; cursor: pointer; transition: .2s; margin-top: 4px;
    }
    .btn-submit:hover { background: #1e293b; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(15,23,42,.2); }
    .btn-submit.green {
      background: linear-gradient(135deg,#16a34a,#22c55e);
      box-shadow: 0 4px 14px rgba(22,163,74,.3);
    }
    .btn-submit.green:hover { background: linear-gradient(135deg,#15803d,#16a34a); }

    .back-link {
      display: block; text-align: center; margin-top: 20px;
      font-size: 12px; color: #94a3b8; text-decoration: none; transition: .2s;
    }
    .back-link:hover { color: #64748b; }

    .form-footer {
      margin-top: 28px; padding-top: 20px;
      border-top: 1px solid #f1f5f9;
      text-align: center; font-size: 12px; color: #cbd5e1;
    }

    @media (max-width: 768px) {
      body { grid-template-columns: 1fr; }
      .left-panel { display: none; }
      .right-panel { padding: 40px 24px; }
    }
  </style>
</head>
<body>

  <!-- LEFT — same as login.php -->
  <div class="left-panel">
    <img src="6.png" alt="Fil-Rise" class="left-logo">
    <div class="left-middle">
      <div class="left-tagline">Smarter sales.<br><em>Better results.</em></div>
      <div class="left-desc">The complete inventory and sales management platform built for direct-selling businesses across the Philippines.</div>
    </div>
    <div class="left-footer">© 2025 Fil-Rise Inventory Management System</div>
  </div>

  <!-- RIGHT -->
  <div class="right-panel">
    <div class="form-box">

      <div class="form-eyebrow">Staff Portal</div>

      <!-- Step indicator -->
      <div class="steps">
        <div class="step-item">
          <div class="step-circle <?= $step===1?'active':($step>1?'done':'') ?>"><?= $step>1?'✓':'1' ?></div>
          <div class="step-label <?= $step===1?'active':($step>1?'done':'') ?>">Username</div>
        </div>
        <div class="step-line <?= $step>1?'done':'' ?>"></div>
        <div class="step-item">
          <div class="step-circle <?= $step===2?'active':($step>2?'done':'') ?>"><?= $step>2?'✓':'2' ?></div>
          <div class="step-label <?= $step===2?'active':($step>2?'done':'') ?>">Security</div>
        </div>
        <div class="step-line <?= $step>2?'done':'' ?>"></div>
        <div class="step-item">
          <div class="step-circle <?= $step===3?'active':($step>3?'done':'') ?>"><?= $step>3?'✓':'3' ?></div>
          <div class="step-label <?= $step===3?'active':($step>3?'done':'') ?>">New Password</div>
        </div>
        <div class="step-line <?= $step>3?'done':'' ?>"></div>
        <div class="step-item">
          <div class="step-circle <?= $step===4?'active':'' ?>">4</div>
          <div class="step-label <?= $step===4?'active':'' ?>">Done</div>
        </div>
      </div>

      <?php if ($error): ?>
        <div class="error-box"><span>⚠</span><span><?= htmlspecialchars($error) ?></span></div>
      <?php endif; ?>

      <?php if ($step === 1): ?>
        <div class="form-title">Forgot Password</div>
        <div class="form-sub">Enter your username to begin</div>
        <form method="POST">
          <input type="hidden" name="step" value="1">
          <div class="field-group">
            <label>Username</label>
            <div class="field-wrap">
              <span class="field-icon">👤</span>
              <input type="text" name="username" placeholder="Enter your username"
                     value="<?= htmlspecialchars($username) ?>" required autofocus <?= $locked?'disabled':'' ?>>
            </div>
          </div>
          <button type="submit" name="check_username" class="btn-submit" <?= $locked?'disabled':'' ?>>Continue →</button>
        </form>

      <?php elseif ($step === 2): ?>
        <div class="form-title">Security Check</div>
        <div class="form-sub">Answer your security question to continue</div>
        <div class="security-note">🛡️ <span>This step ensures only you can reset your password.</span></div>
        <form method="POST">
          <input type="hidden" name="step" value="2">
          <input type="hidden" name="username" value="<?= htmlspecialchars($username) ?>">
          <div class="field-group">
            <label>Security Question</label>
            <div class="question-box">What is the name of the FIL-RISE company owner?</div>
          </div>
          <div class="field-group">
            <label>Your Answer</label>
            <div class="field-wrap">
              <span class="field-icon">🔐</span>
              <input type="text" name="secret_answer" placeholder="Type your answer"
                     required autofocus autocomplete="off">
            </div>
          </div>
          <button type="submit" name="check_answer" class="btn-submit">Verify Answer →</button>
        </form>

      <?php elseif ($step === 3): ?>
        <div class="form-title">New Password</div>
        <div class="form-sub">Choose a strong password for your account</div>
        <form method="POST">
          <input type="hidden" name="step" value="3">
          <div class="field-group">
            <label>New Password</label>
            <div class="field-wrap">
              <span class="field-icon">🔒</span>
              <input type="password" name="new_password" placeholder="Min. 8 chars, 1 uppercase, 1 number" required autofocus>
            </div>
            <div class="pwd-hint">At least 8 characters, 1 uppercase letter, and 1 number</div>
          </div>
          <div class="field-group">
            <label>Confirm Password</label>
            <div class="field-wrap">
              <span class="field-icon">🔒</span>
              <input type="password" name="confirm_password" placeholder="Re-enter new password" required>
            </div>
          </div>
          <button type="submit" name="reset_password" class="btn-submit">Reset Password</button>
        </form>

      <?php elseif ($step === 4): ?>
        <div class="form-title">Password Reset!</div>
        <div class="form-sub">Your password has been updated successfully</div>
        <div class="success-box">✓ <span><?= htmlspecialchars($success) ?></span></div>
        <a href="login.php" class="btn-submit green"
           style="display:block;text-align:center;text-decoration:none;padding:13px;border-radius:12px;">
          Go to Login
        </a>
      <?php endif; ?>

      <?php if ($step < 4): ?>
        <a href="login.php" class="back-link">← Back to Login</a>
      <?php endif; ?>

      <div class="form-footer">© 2025 Fil-Rise IMS · Staff Access Only</div>
    </div>
  </div>

</body>
</html>
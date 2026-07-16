<?php
require '../config.php';
session_start();

$errors = [];
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username']);
    $new_password = $_POST['new_password'] ?? '';

    // Step 1: Check Username
    if (isset($_POST['check_username'])) {

        $stmt = $pdo->prepare("SELECT * FROM distributor_accounts WHERE username=?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user) {
            $success = "Username found! Set your new password below.";
        } else {
            $errors[] = "Username not found.";
        }
    }

    // Step 2: Reset Password
    if (isset($_POST['reset_password'])) {

        if (!$username || !$new_password) {
            $errors[] = "Fill all fields.";
        } else {

            $stmt = $pdo->prepare("SELECT * FROM distributor_accounts WHERE username=?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user) {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);

                $update = $pdo->prepare("UPDATE distributor_accounts SET password=? WHERE username=?");
                $update->execute([$hash, $username]);

                $success = "Password updated! <a href='distributor_login.php' class='text-success fw-bold'>Login</a>";
            } else {
                $errors[] = "Username not found.";
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Distributor Forgot Password - Fil-Rise IMS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
/* Same styling as Distributor Login */

body {
  font-family: 'Poppins', sans-serif;
  height: 100vh;
  width: 100%;
  margin: 0;

  display: flex;
  align-items: center;
  justify-content: center;

  background: url("../2.jpg") no-repeat center center fixed;
  background-size: cover;

  position: relative;
}

body::before {
  content: "";
  position: absolute;
  top: 0; left: 0;
  width: 100%; height: 100%;
  background: rgba(0, 0, 0, 0.55);
  z-index: 0;
}
.container {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  z-index: 1;
}


.card {
  background: rgba(255, 255, 255, 0.93);
  backdrop-filter: blur(15px);
  border-radius: 25px;
  border: 1px solid rgba(255,255,255,0.3);
  width: 400px;
  padding: 40px 35px;
  box-shadow: 0 15px 40px rgba(0,0,0,0.25);
  transition: 0.4s;
}
.card:hover {
  transform: translateY(-6px);
  box-shadow: 0 20px 50px rgba(0,0,0,0.3);
}

.logo {
  display: block;
  margin: 0 auto 15px;
  width: 120px;
}

h3 {
  text-align: center;
  color: #4a5a2a;
  font-weight: 600;
  margin-bottom: 20px;
}

.form-control {
  border-radius: 12px;
  padding: 12px 14px;
}

.btn-primary {
  background: linear-gradient(135deg, #7b9b52, #5f7a3f);
  border-radius: 12px;
  padding: 12px;
  border: none;
  font-weight: 600;
}

.btn-primary:hover {
  background: linear-gradient(135deg, #89b35a, #4c6431);
}

.toggle-link {
  color: #5f7a3f;
  font-weight: 600;
  text-decoration: none;
}

.toggle-link:hover {
  color: #88b04b;
  text-decoration: underline;
}

footer {
  text-align: center;
  margin-top: 20px;
  color: #ffffffd0;
  font-size: 0.9rem;
}
</style>

</head>
<body>

<div class="container">
<div class="card">

    <img src="../6.png" class="logo">

    <h3>Forgot Password</h3>

    <?php if ($errors): ?>
        <div class="alert alert-danger text-center"><?= implode('<br>', $errors) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success text-center"><?= $success ?></div>
    <?php endif; ?>

    <form method="POST">

        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control mb-3" required>

        <?php if (empty($success)): ?>
            <button name="check_username" class="btn btn-primary w-100">Check Username</button>
        <?php endif; ?>

        <?php if (str_contains(strtolower($success), "username found")): ?>

            <label class="form-label mt-3">New Password</label>
            <input type="password" name="new_password" class="form-control">

            <button name="reset_password" class="btn btn-primary w-100 mt-3">Reset Password</button>

        <?php endif; ?>

    </form>

    <div class="text-center mt-3">
        <a href="distributor_login.php" class="toggle-link">Back to Login</a>
    </div>

</div>


</div>

</body>
</html>

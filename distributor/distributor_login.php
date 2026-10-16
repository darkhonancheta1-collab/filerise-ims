<?php
/**
 * distributor/distributor_login.php — Distributor Portal Login Interface
 *
 * Authenticates distributor accounts using distributor_accounts credentials.
 * Handles password verification and starts distributor session.
 */
require '../config.php';
session_start();

$errors = [];

// LOGIN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM distributor_accounts WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['dist_id'] = $user['id'];
        $_SESSION['dist_name'] = $user['fullname'];
        header("Location: distributor_dashboard.php");
        exit;
    } else {
        $errors[] = "Invalid username or password.";
    }
}

// REGISTER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $fullname = trim($_POST['fullname']);
    $new_username = trim($_POST['new_username']);
    $new_password = $_POST['new_password'];

    if (!$fullname || !$new_username || !$new_password) {
        $errors[] = "All fields are required.";
    } else {
        $check = $pdo->prepare("SELECT id FROM distributor_accounts WHERE username = ?");
        $check->execute([$new_username]);

        if ($check->fetch()) {
            $errors[] = "Username already exists.";
        } else {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);

            $insert = $pdo->prepare("
                INSERT INTO distributor_accounts (fullname, username, password)
                VALUES (?, ?, ?)
            ");
            $insert->execute([$fullname, $new_username, $hash]);

            header("Location: distributor_login.php?registered=1");
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Distributor Login - Fil-Rise IMS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
body {
  font-family: 'Poppins', sans-serif;
  min-height: 100vh;
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
.container { position: relative; z-index: 1; }
.card {
  background: rgba(255, 255, 255, 0.93);
  backdrop-filter: blur(15px);
  border-radius: 25px;
  border: 1px solid rgba(255,255,255,0.3);
  box-shadow: 0 15px 40px rgba(0,0,0,0.25);
  transition: 0.4s;
}
.card:hover {
  transform: translateY(-6px);
  box-shadow: 0 20px 50px rgba(0,0,0,0.3);
}
.card-body {
  padding: 40px 35px;
}
.logo {
  display: block;
  margin: 0 auto 15px;
  width: 120px;
}
.subtitle {
  text-align: center;
  color: #667c47;
  font-weight: 500;
  letter-spacing: 1px;
  margin-bottom: 30px;
  text-transform: uppercase;
}
h4 {
  font-weight: 600;
  text-align: center;
  color: #4a5a2a;
  margin-bottom: 20px;
}
.form-label { font-weight: 500; }
.form-control {
  border-radius: 12px;
  padding: 12px 14px;
}
.btn-primary {
  background: linear-gradient(135deg, #7b9b52, #5f7a3f);
  border-radius: 12px;
  padding: 12px;
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
#registerForm { display: none; }
.fade-in { animation: fadeIn 0.8s ease-in-out; }
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>
</head>
<body>

<div class="container">
  <div class="row justify-content-center align-items-center">
    <div class="col-md-5 fade-in">
      <div class="card">
        <div class="card-body">

          <img src="../6.png" alt="Fil-Rise Logo" class="logo">

          <div class="subtitle">Distributor Portal</div>

          <?php if (isset($_GET['registered'])): ?>
            <div class="alert alert-success text-center">Account created! Please login.</div>
          <?php endif; ?>

          <?php if ($errors): ?>
            <div class="alert alert-danger text-center"><?php echo implode("<br>", $errors); ?></div>
          <?php endif; ?>

          <!-- LOGIN FORM -->
          <div id="loginForm">
            <h4>Sign In</h4>

            <form method="post">
              <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" required>
              </div>

              <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required>
              </div>

              <button type="submit" name="login" class="btn btn-primary w-100 mt-2">Login</button>
            </form>

            <div class="text-center mt-3">
              <small><a href="forgot_password.php" class="toggle-link">Forgot Password?</a></small><br>
              <small>Don't have an account? <a href="#" id="showRegister" class="toggle-link">Register</a></small>
            </div>
          </div>

          <!-- REGISTER FORM -->
          <div id="registerForm">
            <h4>Create Account</h4>

            <form method="post">

              <div class="mb-3">
                <label class="form-label">Full Name</label>
                <input type="text" name="fullname" class="form-control" required>
              </div>

              <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="new_username" class="form-control" required>
              </div>

              <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="new_password" class="form-control" required>
              </div>

              <button type="submit" name="register" class="btn btn-primary w-100 mt-2">Register</button>
            </form>

            <div class="text-center mt-3">
              <small>Already have an account? <a href="#" id="showLogin" class="toggle-link">Login</a></small>
            </div>
          </div>

        </div>
      </div>

      
    </div>
  </div>
</div>

<script>
document.getElementById('showRegister').onclick = function(e){
  e.preventDefault();
  document.getElementById('loginForm').style.display = 'none';
  document.getElementById('registerForm').style.display = 'block';
};
document.getElementById('showLogin').onclick = function(e){
  e.preventDefault();
  document.getElementById('registerForm').style.display = 'none';
  document.getElementById('loginForm').style.display = 'block';
};
</script>

</body>
</html>

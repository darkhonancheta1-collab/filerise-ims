<?php
// config.example.php
// Copy this file to config.php and fill in your own values.
// NEVER commit the real config.php with actual keys/credentials.

date_default_timezone_set('Asia/Manila');

$__secPath = __DIR__ . '/security.php';
if (file_exists($__secPath)) require_once $__secPath;

/* ==========================================
   API KEYS — replace with your own
   In production: move these to environment variables or
   a file outside the web root (e.g. C:\xampp\api_keys.php)
   ========================================== */
define('GROQ_API_KEY',       'YOUR_GROQ_API_KEY_HERE');
define('ELEVENLABS_API_KEY', 'YOUR_ELEVENLABS_API_KEY_HERE');

/* ==========================================
   GMAIL SMTP — used by mailer.php (PHPMailer)
   to send the Forgot Password reset code.

   1. Go to https://myaccount.google.com/apppasswords
   2. Generate a 16-character "App Password" for Mail
      (requires 2-Step Verification to be turned ON).
   3. Paste your Gmail address + that app password below.
   ========================================== */
define('SMTP_HOST',      'smtp.gmail.com');
define('SMTP_PORT',      587);
define('SMTP_USERNAME',  'YOUR_GMAIL_ADDRESS@gmail.com');
define('SMTP_PASSWORD',  'YOUR_16_CHAR_APP_PASSWORD');
define('SMTP_FROM_NAME', 'Fil-Rise IMS');

$host = '127.0.0.1';
$db   = 'filrise';   // change to your DB name
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
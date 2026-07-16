<?php
// super_admin/auth.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['admin_id'])) {
  header("Location: ../login.php");
  exit;
}
if (($_SESSION['role'] ?? '') !== 'super_admin') {
  header("Location: ../admin/admin_dashboard.php");
  exit;
}
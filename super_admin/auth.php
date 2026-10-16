<?php
/**
 * super_admin/auth.php — Super Admin Authentication & Authorization Guard
 *
 * Verifies that the current session is authenticated with role='super_admin'.
 * Redirects unauthenticated users to login or standard admins to admin dashboard.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['admin_id'])) {
  header("Location: ../login.php");
  exit;
}
if (($_SESSION['role'] ?? '') !== 'super_admin') {
  header("Location: ../admin/admin_dashboard.php");
  exit;
}
<?php
/**
 * admin/auth.php — Admin Authentication Guard
 *
 * Include this at the top of every admin page. Checks for an active
 * admin session (admin_id). Redirects to the unified login page
 * if not authenticated.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['admin_id'])) {
  header("Location: ../login.php");
  exit;
}
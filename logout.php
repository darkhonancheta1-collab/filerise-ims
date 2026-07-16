<?php
session_start();

// Unset ALL session variables
$_SESSION = [];

// Destroy session
session_destroy();

// Optional: Prevent back button page access
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Redirect to login
header("Location: login.php");
exit;

<?php
/**
 * logout.php — Staff Session Destruction & Logout Handler
 *
 * [SEARCH TAG: LOGOUT_HANDLER / DESTROY_SESSION / USER_LOGOUT]
 * Clears all active session variables, invalidates browser cache headers,
 * and redirects the user back to the login page.
 */
session_start();

// Unset ALL session variables [SEARCH TAG: CLEAR_SESSION_DATA]
$_SESSION = [];

// Destroy session on server [SEARCH TAG: SESSION_DESTROY]
session_destroy();

// Prevent back-button caching of protected pages [SEARCH TAG: CACHE_CONTROL_LOGOUT]
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Redirect to login page [SEARCH TAG: LOGOUT_REDIRECT]
header("Location: login.php");
exit;

<?php
/**
 * admin/admin_login.php — Admin Login Redirect Shim
 *
 * [SEARCH TAG: ADMIN_LOGIN_SHIM / UNIFIED_LOGIN_REDIRECT]
 * Admin logins have been merged into the unified /login.php portal.
 * This file remains as a backwards-compatibility redirect for legacy links.
 */
header('Location: ../login.php');
exit;

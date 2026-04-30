<?php
// Start session if possible, then clear and destroy safely
if (session_status() === PHP_SESSION_NONE) { @session_start(); }

// Unset all session variables
$_SESSION = [];

// Delete the session cookie on the client
if (ini_get('session.use_cookies')) {
	$params = session_get_cookie_params();
	setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
}

// Destroy and close
@session_destroy();
@session_write_close();

// Regenerate to avoid reusing the old ID
@session_start();
@session_regenerate_id(true);
@session_write_close();

header('Location: login.php');
exit;
?>

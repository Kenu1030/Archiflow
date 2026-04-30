<?php
// Redirect helper to the actual admin path, preserving the query string (e.g., ?id=123)
$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: admin/user-management/user-details.php' . $qs);
exit;
?>



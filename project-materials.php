<?php
// Compatibility route: redirect to architect Project Materials manager
$qs = isset($_SERVER['QUERY_STRING']) ? (string)$_SERVER['QUERY_STRING'] : '';
$root = '/' . explode('/', trim($_SERVER['SCRIPT_NAME'], '/'))[0] . '/';
$target = $root . 'employees/architects/project-materials.php' . ($qs ? ('?' . $qs) : '');
header('Location: ' . $target, true, 302);
exit;
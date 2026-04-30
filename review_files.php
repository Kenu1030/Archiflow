<?php
// Root alias/redirect for client review files page.
// Allows access via /Archiflow/review_files.php?project_id=...&id=...
// Redirect to canonical client path to avoid duplicate logic inclusion.
$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = 'client/review_files.php' . ($qs ? ('?' . $qs) : '');
header('Location: ' . $target, true, 302);
exit;

<?php
// Root alias for senior architect discussion page.
// Allows direct access via /Archiflow/client_review_discuss.php?project_id=...&id=...
// Redirects to employees/senior_architects/client_review_discuss.php retaining query string.
$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = 'employees/senior_architects/client_review_discuss.php' . ($qs ? ('?' . $qs) : '');
header('Location: ' . $target, true, 302);
exit;

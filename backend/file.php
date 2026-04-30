<?php
// Secure file download endpoint for documents
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = (int)($_SESSION['user_id'] ?? 0);
$userType = (string)($_SESSION['user_type'] ?? '');
$position = strtolower((string)($_SESSION['position'] ?? ''));

$docId = isset($_GET['doc_id']) ? (int)$_GET['doc_id'] : 0;
if ($docId <= 0) {
    http_response_code(400);
    echo 'Invalid document id';
    exit;
}

// Resolve employee id for architect checks
$employeeId = 0;
if ($userType === 'employee') {
    $emp = $db->prepare('SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1');
    $emp->execute([$userId]);
    $e = $emp->fetch(PDO::FETCH_ASSOC);
    $employeeId = $e ? (int)$e['employee_id'] : 0;
}

$stmt = $db->prepare('SELECT d.document_id, d.document_name, d.document_type, d.document_data, d.file_type, d.uploaded_by, p.architect_id
                       FROM documents d
                       LEFT JOIN projects p ON p.project_id = d.project_id
                       WHERE d.document_id = ? LIMIT 1');
$stmt->execute([$docId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    http_response_code(404);
    echo 'File not found';
    exit;
}

// Permission: Admins can download; architects can download if they own the project or uploaded it
$allowed = false;
if ($userType === 'admin') {
    $allowed = true;
} elseif ($userType === 'employee' && $position === 'architect') {
    if ((int)$doc['architect_id'] === $employeeId || (int)$doc['uploaded_by'] === $userId) {
        $allowed = true;
    }
} elseif ($userId === (int)$doc['uploaded_by']) {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// Output the file
$filename = $doc['document_name'] ?: ('document_' . $docId);
$mime = $doc['file_type'] ?: 'application/octet-stream';
$data = $doc['document_data'];

// Clean output buffering to avoid corrupting binary
if (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"','', $filename) . '"');
header('Content-Length: ' . strlen($data));
header('X-Content-Type-Options: nosniff');
echo $data;
exit;
?>

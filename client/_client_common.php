<?php
// Common helpers for client pages
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['user_type'] ?? null) !== 'client') {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../backend/connection/connect.php';
$pdo = getDB();
if (!$pdo) { http_response_code(500); echo 'Database connection failed.'; exit; }

// CSRF
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_token'];

$hasColumn = function(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
};

// Resolve client_id from current user
$userId = (int)($_SESSION['user_id'] ?? 0);
$clientId = null;
try {
    // Primary: treat session user id as users.user_id (new schema)
    $stmt = $pdo->prepare('SELECT client_id FROM clients WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $clientId = $stmt->fetchColumn();
} catch (Throwable $e) { $clientId = null; }

// Fallback: if no row, session might be users.id (legacy). Map users.id -> users.user_id -> clients.user_id
if (!$clientId && $userId > 0) {
    try {
        $u = $pdo->prepare('SELECT user_id FROM users WHERE id = ? LIMIT 1');
        $u->execute([$userId]);
        $mappedUserId = (int)$u->fetchColumn();
        if ($mappedUserId > 0) {
            $c = $pdo->prepare('SELECT client_id FROM clients WHERE user_id = ? LIMIT 1');
            $c->execute([$mappedUserId]);
            $clientId = $c->fetchColumn();
        }
    } catch (Throwable $e) { /* ignore */ }
}
if ($clientId !== null) { $clientId = (int)$clientId; }
?>

<?php
// Robust user deletion (soft-delete by default; optional hard delete with best-effort unlink)
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Accept both newer and legacy admin checks
$isAdmin = false;
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') { $isAdmin = true; }
if (isset($_SESSION['role']) && in_array(strtolower((string)$_SESSION['role']), ['admin','administrator'], true)) { $isAdmin = true; }
if (!$isAdmin || !isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { header('Location: admin/dashboard.php'); exit; }
$targetId = (int)$_GET['id'];
if ($targetId === (int)$_SESSION['user_id']) { header('Location: admin/dashboard.php?error=cannot_delete_self'); exit; }

require_once __DIR__ . '/backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Detect users primary key column and soft-delete capability
$uCols = [];
foreach ($db->query('SHOW COLUMNS FROM users') as $uc) { $uCols[$uc['Field']] = true; }
$pk = isset($uCols['user_id']) ? 'user_id' : (isset($uCols['id']) ? 'id' : null);
if ($pk === null) { header('Location: admin/dashboard.php?error=users_pk_not_found'); exit; }
$hasIsDeleted = isset($uCols['is_deleted']);
$hasStatus = isset($uCols['status']);

// If missing, add is_deleted for soft delete
if (!$hasIsDeleted && !$hasStatus) {
    try { $db->exec("ALTER TABLE users ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0"); $hasIsDeleted = true; } catch (Throwable $e) { /* ignore */ }
}

// Prefer soft delete to avoid FK issues
try {
    if ($hasIsDeleted) {
        $st = $db->prepare("UPDATE users SET is_deleted = 1 WHERE `$pk` = ?");
        $st->execute([$targetId]);
        header('Location: admin/dashboard.php?deleted=soft');
        exit;
    } elseif ($hasStatus) {
        // Mark as inactive/deleted depending on existing values
        $st = $db->prepare("UPDATE users SET status = 'deleted' WHERE `$pk` = ?");
        $st->execute([$targetId]);
        header('Location: admin/dashboard.php?deleted=soft');
        exit;
    }
} catch (Throwable $e) {
    // fall through to hard delete attempt
}

// If requested via hard=1, attempt best-effort unlink then hard delete
$doHard = isset($_GET['hard']) && (($_GET['hard'] === '1') || (strtolower((string)$_GET['hard']) === 'true'));
if ($doHard) {
    try {
        $db->beginTransaction();
        // Best-effort unlink in common referencing tables (schema-tolerant)
        $safeNull = function(string $table, string $col) use ($db, $targetId) {
            try {
                $chk = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
                $chk->execute([$table, $col]);
                if ((int)$chk->fetchColumn() > 0) {
                    $sql = "UPDATE `$table` SET `$col` = NULL WHERE `$col` = ?";
                    $st = $db->prepare($sql);
                    $st->execute([$targetId]);
                }
            } catch (Throwable $e) { /* ignore individual failures */ }
        };
        $safeDeleteByUser = function(string $table, string $col) use ($db, $targetId) {
            try {
                $chk = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
                $chk->execute([$table, $col]);
                if ((int)$chk->fetchColumn() > 0) {
                    $sql = "DELETE FROM `$table` WHERE `$col` = ?";
                    $st = $db->prepare($sql);
                    $st->execute([$targetId]);
                }
            } catch (Throwable $e) { /* ignore */ }
        };

        // Nullify assignments and recipients
        $safeNull('public_inquiries', 'assigned_to');
        $safeNull('client_inquiries', 'recipient_id');
        $safeNull('employees', 'user_id'); // beware: may orphan employee record

        // Remove ephemeral rows
        $safeDeleteByUser('notifications', 'user_id');
        $safeDeleteByUser('project_users', 'user_id');

        // Finally, try to delete the user
        $del = $db->prepare("DELETE FROM users WHERE `$pk` = ?");
        $del->execute([$targetId]);
        $db->commit();
        header('Location: admin/dashboard.php?deleted=hard');
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        $msg = urlencode('Cannot delete: linked via foreign keys. Consider unlinking dependent records or switching to soft delete.');
        header('Location: admin/dashboard.php?error='.$msg);
        exit;
    }
}

// If we reach here, neither soft nor hard delete executed
header('Location: admin/dashboard.php?error=delete_not_possible');
exit;

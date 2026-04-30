<?php
session_start();
require_once '../../backend/auth.php';
require_once '../../backend/connection/connect.php';

$auth = new Auth();
if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

// Get user ID from URL
$userId = $_GET['id'] ?? null;
if (!$userId) {
    header('Location: user-index.php');
    exit();
}

// Prevent admin from deleting themselves
if ($userId == $_SESSION['user_id']) {
    header('Location: user-index.php?error=cannot_delete_self');
    exit();
}

// Get user details from database
$db = getDB();
$userData = null;
if ($db) {
    $query = "SELECT * FROM users WHERE user_id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$userId]);
    $userData = $stmt->fetch();
}

if (!$userData) {
    header('Location: user-index.php');
    exit();
}

// Handle deletion
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form token.';
    } else {
        try {
            // Use a transaction to keep operations atomic
            $db->beginTransaction();

            // If this user is a client and linked to clients.user_id via FK, try to unlink first
            $uType = strtolower((string)($userData['user_type'] ?? ''));
            if ($uType === 'client') {
                try {
                    // Check if a clients row exists for this user
                    $stCli = $db->prepare('SELECT client_id FROM clients WHERE user_id = ? LIMIT 1');
                    $stCli->execute([$userId]);
                    $cliRow = $stCli->fetch(PDO::FETCH_ASSOC);
                    if ($cliRow) {
                        // Ensure clients.user_id is nullable; if not, make it nullable
                        $stNull = $db->prepare("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'user_id'");
                        $stNull->execute();
                        $isNullable = strtoupper((string)$stNull->fetchColumn()) === 'YES';
                        if (!$isNullable) {
                            // Best-effort alter to allow NULLs (safe: does not drop FK)
                            try { $db->exec('ALTER TABLE clients MODIFY user_id INT NULL'); } catch (Throwable $ae) { /* continue; may already be nullable */ }
                        }
                        // Unlink the user from clients to satisfy FK
                        $stUpd = $db->prepare('UPDATE clients SET user_id = NULL WHERE user_id = ?');
                        $stUpd->execute([$userId]);
                    }
                } catch (Throwable $te) {
                    // Re-throw as Exception to trigger rollback and user-friendly message
                    throw new Exception($te->getMessage(), (int)($te->getCode() ?: 0), $te);
                }
            }

            // Now delete the user (or soft delete if constraints persist)
            $result = false;
            try {
                $deleteStmt = $db->prepare('DELETE FROM users WHERE user_id = ?');
                $result = $deleteStmt->execute([$userId]);
            } catch (Throwable $de) {
                // Fallback: soft-delete when hard delete fails due to constraints
                try {
                    // Detect soft-delete capability
                    $uCols = [];
                    foreach ($db->query('SHOW COLUMNS FROM users') as $uc) { $uCols[$uc['Field']] = true; }
                    if (isset($uCols['is_deleted'])) {
                        $sd = $db->prepare('UPDATE users SET is_deleted = 1 WHERE user_id = ?');
                        $sd->execute([$userId]);
                        $result = true; // treat as success
                    } elseif (isset($uCols['status'])) {
                        $sd = $db->prepare("UPDATE users SET status = 'deleted' WHERE user_id = ?");
                        $sd->execute([$userId]);
                        $result = true;
                    }
                } catch (Throwable $se) {
                    // keep $result as false
                }
            }

            if ($result) {
                $db->commit();
                header('Location: user-index.php?success=user_deleted');
                exit();
            }

            // If execute returned false without exception
            $db->rollBack();
            $error = 'Failed to delete user';
        } catch (Exception $e) {
            // Roll back on any failure
            if ($db->inTransaction()) { $db->rollBack(); }
            // Provide clearer guidance for FK violations
            $msg = $e->getMessage();
            if (stripos($msg, 'foreign key') !== false || stripos($msg, '23000') !== false) {
                $error = 'Cannot hard-delete due to linked records. The system attempted to unlink client references and will soft-delete the user if supported (is_deleted/status).';
            } else {
                $error = 'Error deleting user: ' . $msg;
            }
        }
    }
}
?>

<?php include '../../backend/core/header.php'; ?>

<main class="p-6">
<div class="max-w-full">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex items-center mb-4">
                <a href="user-details.php?id=<?php echo $userData['user_id']; ?>" class="text-blue-600 hover:text-blue-800 mr-4">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-3xl font-bold text-red-600">Delete User</h1>
                    <p class="text-gray-600 mt-2">This action cannot be undone</p>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation -->
        <div class="bg-white rounded-lg shadow-lg p-8">
            <div class="text-center mb-8">
                <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-4">
                    <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-900 mb-2">Are you sure?</h2>
                <p class="text-gray-600">This will permanently delete the user account and all associated data.</p>
            </div>

            <!-- User Information -->
            <div class="bg-gray-50 rounded-lg p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">User to be deleted:</h3>
                <div class="flex items-center">
                    <div class="flex-shrink-0 h-12 w-12">
                        <div class="h-12 w-12 rounded-full bg-blue-600 flex items-center justify-center">
                            <i class="fas fa-user text-white"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <div class="text-lg font-medium text-gray-900">
                            <?php echo htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']); ?>
                        </div>
                        <div class="text-sm text-gray-500">
                            @<?php echo htmlspecialchars($userData['username']); ?> • <?php echo htmlspecialchars($userData['email']); ?>
                        </div>
                        <div class="mt-1">
                            <?php
                            $roleColor = '';
                            $roleIcon = '';
                            $roleText = $userData['user_type'];
                            
                            switch ($userData['user_type']) {
                                case 'admin':
                                    $roleColor = 'bg-red-100 text-red-800';
                                    $roleIcon = 'fas fa-crown';
                                    break;
                                case 'employee':
                                    $roleColor = 'bg-blue-100 text-blue-800';
                                    $roleIcon = 'fas fa-user-tie';
                                    $roleText = $userData['position'] ?? 'Employee';
                                    break;
                                case 'hr':
                                    $roleColor = 'bg-green-100 text-green-800';
                                    $roleIcon = 'fas fa-users-cog';
                                    break;
                                case 'client':
                                    $roleColor = 'bg-purple-100 text-purple-800';
                                    $roleIcon = 'fas fa-handshake';
                                    break;
                            }
                            ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $roleColor; ?>">
                                <i class="<?php echo $roleIcon; ?> mr-1"></i>
                                <?php echo htmlspecialchars($roleText); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Warning -->
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-8">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-circle text-red-400"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800">Warning</h3>
                        <div class="mt-2 text-sm text-red-700">
                            <ul class="list-disc list-inside space-y-1">
                                <li>This action cannot be undone</li>
                                <li>All user data will be permanently deleted</li>
                                <li>Any projects or records associated with this user may be affected</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Error Message -->
            <?php if (isset($error)): ?>
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle text-red-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-red-700"><?php echo htmlspecialchars($error); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Form Actions -->
            <form method="POST" class="flex justify-end space-x-4">
                <a href="user-details.php?id=<?php echo $userData['user_id']; ?>" class="px-6 py-3 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition duration-300">
                    Cancel
                </a>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" />
                <button type="submit" name="confirm_delete" value="1" class="px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 transition duration-300 flex items-center">
                    <i class="fas fa-trash mr-2"></i>
                    Delete User
                </button>
            </form>
        </div>
    </div>
</main>

<?php include '../../backend/core/footer.php'; ?>

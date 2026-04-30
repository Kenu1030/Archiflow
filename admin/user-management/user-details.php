<?php
session_start();
require_once '../../backend/auth.php';
require_once '../../backend/connection/connect.php';

$auth = new Auth();
if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$user = $auth->getCurrentUser();

// Get user ID from URL
$userId = $_GET['id'] ?? null;
if (!$userId) {
    header('Location: user-index.php');
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
?>

<?php include '../../backend/core/header.php'; ?>

<main class="p-6">
<div class="max-w-full">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex items-center mb-4">
                <a href="admin/user-management/user-index.php" class="text-blue-600 hover:text-blue-800 mr-4">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">User Details</h1>
                    <p class="text-gray-600 mt-2">View detailed information about this user</p>
                </div>
            </div>
        </div>

        <!-- User Details -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <!-- User Header -->
            <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-8 py-6 text-white">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="h-20 w-20 rounded-full bg-white bg-opacity-20 flex items-center justify-center">
                            <i class="fas fa-user text-3xl"></i>
                        </div>
                    </div>
                    <div class="ml-6">
                        <h2 class="text-2xl font-bold">
                            <?php echo htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']); ?>
                        </h2>
                        <p class="text-blue-100">@<?php echo htmlspecialchars($userData['username']); ?></p>
                        <div class="mt-2">
                            <?php
                            $roleColor = '';
                            $roleIcon = '';
                            $roleText = $userData['user_type'];
                            
                            switch ($userData['user_type']) {
                                case 'admin':
                                    $roleColor = 'bg-red-500';
                                    $roleIcon = 'fas fa-crown';
                                    break;
                                case 'employee':
                                    $roleColor = 'bg-blue-500';
                                    $roleIcon = 'fas fa-user-tie';
                                    $roleText = $userData['position'] ?? 'Employee';
                                    break;
                                case 'hr':
                                    $roleColor = 'bg-green-500';
                                    $roleIcon = 'fas fa-users-cog';
                                    break;
                                case 'client':
                                    $roleColor = 'bg-purple-500';
                                    $roleIcon = 'fas fa-handshake';
                                    break;
                            }
                            ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?php echo $roleColor; ?>">
                                <i class="<?php echo $roleIcon; ?> mr-2"></i>
                                <?php echo htmlspecialchars($roleText); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- User Information -->
            <div class="p-8">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Personal Information -->
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Personal Information</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-500">Full Name</label>
                                <p class="text-gray-900"><?php echo htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-500">Username</label>
                                <p class="text-gray-900">@<?php echo htmlspecialchars($userData['username']); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-500">Email Address</label>
                                <p class="text-gray-900"><?php echo htmlspecialchars($userData['email']); ?></p>
                            </div>
                            <?php if ($userData['phone']): ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-500">Phone Number</label>
                                <p class="text-gray-900"><?php echo htmlspecialchars($userData['phone']); ?></p>
                            </div>
                            <?php endif; ?>
                            <?php if ($userData['address']): ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-500">Address</label>
                                <p class="text-gray-900"><?php echo htmlspecialchars($userData['address']); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Account Information -->
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Account Information</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-500">User Type</label>
                                <p class="text-gray-900 capitalize"><?php echo htmlspecialchars($userData['user_type']); ?></p>
                            </div>
                            <?php if ($userData['position']): ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-500">Position</label>
                                <p class="text-gray-900 capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $userData['position'])); ?></p>
                            </div>
                            <?php endif; ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-500">Account Status</label>
                                <?php if ($userData['is_active']): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <i class="fas fa-check-circle mr-1"></i>
                                        Active
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                        <i class="fas fa-times-circle mr-1"></i>
                                        Inactive
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-500">Member Since</label>
                                <p class="text-gray-900"><?php echo date('F j, Y', strtotime($userData['created_at'])); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="mt-8 pt-6 border-t border-gray-200">
                    <div class="flex justify-end space-x-4">
                        <a href="admin/user-management/user-index.php" class="px-6 py-3 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition duration-300">
                            Back to Users
                        </a>
                        <a href="admin/user-management/user-edit.php?id=<?php echo $userData['user_id']; ?>" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-300">
                            <i class="fas fa-edit mr-2"></i>
                            Edit User
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../../backend/core/footer.php'; ?>

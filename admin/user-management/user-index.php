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

// Get all users from database
$db = getDB();
$users = [];
if ($db) {
    $query = "SELECT user_id, username, email, user_type, position, first_name, last_name, phone, address, is_active, created_at FROM users ORDER BY created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll();
}
?>

<?php include '../../backend/core/header.php'; ?>

<main class="p-6">
    <div class="max-w-full">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">User Management</h1>
                    <p class="text-gray-600 mt-2">Manage all system users and their roles</p>
                </div>
                <a href="admin/user-management/user-add.php" class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition duration-300 flex items-center">
                    <i class="fas fa-user-plus mr-2"></i>
                    Add New User
                </a>
            </div>
        </div>

        <!-- Users Table -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">All Users</h2>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                    <i class="fas fa-users text-4xl mb-4"></i>
                                    <p class="text-lg">No users found</p>
                                    <p class="text-sm">Start by adding your first user</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $userData): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                <div class="h-10 w-10 rounded-full bg-blue-600 flex items-center justify-center">
                                                    <i class="fas fa-user text-white"></i>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    @<?php echo htmlspecialchars($userData['username']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
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
                                                    $pos = strtolower($userData['position'] ?? '');
                                                    if ($pos === 'senior_architect') {
                                                        $roleIcon = 'fas fa-sitemap';
                                                        $roleText = 'Senior Architect';
                                                    } elseif ($pos === 'architect') {
                                                        $roleIcon = 'fas fa-drafting-compass';
                                                        $roleText = 'Architect';
                                                    } elseif ($pos === 'project_manager') {
                                                        $roleIcon = 'fas fa-user-tie';
                                                        $roleText = 'Project Manager';
                                                    } else {
                                                        $roleIcon = 'fas fa-user-tie';
                                                        $roleText = $userData['position'] ?? 'Employee';
                                                    }
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
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($userData['email']); ?></div>
                                        <?php if ($userData['phone']): ?>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($userData['phone']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
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
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('M j, Y', strtotime($userData['created_at'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <a href="admin/user-management/user-details.php?id=<?php echo $userData['user_id']; ?>" class="text-blue-600 hover:text-blue-900">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="admin/user-management/user-edit.php?id=<?php echo $userData['user_id']; ?>" class="text-indigo-600 hover:text-indigo-900">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if ($userData['user_id'] != $_SESSION['user_id']): ?>
                                                <a href="admin/user-management/user-delete.php?id=<?php echo $userData['user_id']; ?>" class="text-red-600 hover:text-red-900" onclick="return confirm('Are you sure you want to delete this user?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php include '../../backend/core/footer.php'; ?>

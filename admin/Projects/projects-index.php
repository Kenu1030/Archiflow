<?php
session_start();
require_once '../../backend/auth.php';
require_once '../../backend/connection/connect.php';

$errorMessage = null;
$projects = [];

try {
    $auth = new Auth();
    if (!$auth->isLoggedIn() || ($_SESSION['user_type'] ?? '') !== 'admin') {
        header('Location: ../../login.php');
        exit();
    }
    $user = $auth->getCurrentUser();

    // Get projects from database
    $db = getDB();
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    $query = "SELECT p.*, 
                     c.contact_person AS client_name, 
                     u.first_name AS architect_name, 
                     u.last_name AS architect_last_name
              FROM projects p 
              LEFT JOIN clients c ON p.client_id = c.client_id 
              LEFT JOIN employees e ON p.architect_id = e.employee_id 
              LEFT JOIN users u ON e.user_id = u.user_id
              ORDER BY p.created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $projects = $stmt->fetchAll();
} catch (Throwable $e) {
    // Avoid fatal 500s by surfacing a friendly message in the UI
    $errorMessage = $e->getMessage();
}

// Helper for status badge classes (avoid PHP 8 match for broader compatibility)
function project_status_badge_class($status) {
    switch ($status) {
        case 'planning':
            return 'bg-yellow-100 text-yellow-800';
        case 'design':
            return 'bg-blue-100 text-blue-800';
        case 'construction':
            return 'bg-green-100 text-green-800';
        case 'completed':
            return 'bg-gray-100 text-gray-800';
        case 'cancelled':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}
?>

<?php include '../../backend/core/header.php'; ?>

<main class="p-6">
    <div class="max-w-full">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Project Management</h1>
                    <p class="text-gray-600 mt-2">Manage all architectural projects</p>
                </div>
                <!-- View-only: creation disabled -->
            </div>
        </div>

        <!-- Error state -->
        <?php if ($errorMessage): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-red-500"></i>
                    </div>
                    <div class="ml-3 text-sm text-red-800">
                        <?php echo htmlspecialchars($errorMessage); ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Projects Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if (empty($projects)): ?>
                <div class="col-span-full">
                    <div class="bg-white rounded-lg shadow-lg p-12 text-center">
                        <i class="fas fa-project-diagram text-6xl text-gray-300 mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-900 mb-2">No Projects Found</h3>
                        <p class="text-gray-600 mb-6">Start by creating your first architectural project</p>
                        <!-- View-only: remove create action -->
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($projects as $project): ?>
                    <div class="bg-white rounded-lg shadow-lg overflow-hidden hover:shadow-xl transition duration-300">
                        <!-- Project Image Placeholder -->
                        <div class="h-48 bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center">
                            <i class="fas fa-building text-white text-4xl"></i>
                        </div>
                        
                        <!-- Project Content -->
                        <div class="p-6">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($project['project_name']); ?></h3>
                                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($project['project_code']); ?></p>
                                </div>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo project_status_badge_class($project['status'] ?? ''); ?>">
                                    <?php echo ucfirst($project['status']); ?>
                                </span>
                            </div>
                            
                            <div class="space-y-2 mb-4">
                                <div class="flex items-center text-sm text-gray-600">
                                    <i class="fas fa-user mr-2"></i>
                                    <span><?php echo htmlspecialchars($project['client_name'] ?? 'No client assigned'); ?></span>
                                </div>
                                <div class="flex items-center text-sm text-gray-600">
                                    <i class="fas fa-drafting-compass mr-2"></i>
                                    <span><?php echo htmlspecialchars(($project['architect_name'] ?? 'No architect') . ' ' . ($project['architect_last_name'] ?? '')); ?></span>
                                </div>
                                <div class="flex items-center text-sm text-gray-600">
                                    <i class="fas fa-tag mr-2"></i>
                                    <span class="capitalize"><?php echo htmlspecialchars($project['project_type']); ?></span>
                                </div>
                            </div>
                            
                            <?php if ($project['budget']): ?>
                                <div class="mb-4">
                                    <div class="text-sm text-gray-500">Budget</div>
                                    <div class="text-lg font-semibold text-green-600">₱<?php echo number_format($project['budget'], 2); ?></div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="flex justify-between items-center">
                                <div class="text-sm text-gray-500">
                                    Created <?php echo date('M j, Y', strtotime($project['created_at'])); ?>
                                </div>
                                <div class="flex space-x-2">
                                    <button onclick="viewProject(<?php echo $project['project_id']; ?>)" class="text-blue-600 hover:text-blue-800">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button onclick="editProject(<?php echo $project['project_id']; ?>)" class="text-indigo-600 hover:text-indigo-800">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="deleteProject(<?php echo $project['project_id']; ?>)" class="text-red-600 hover:text-red-800">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>

function viewProject(projectId) {
    // Implement view project functionality
    alert('View project: ' + projectId);
}

function editProject(projectId) {
    // Implement edit project functionality
    alert('Edit project: ' + projectId);
}

function deleteProject(projectId) {
    if (confirm('Are you sure you want to delete this project?')) {
        // Implement delete project functionality
        alert('Delete project: ' + projectId);
    }
}

// View-only: creation logic removed
</script>

<?php include '../../backend/core/footer.php'; ?>

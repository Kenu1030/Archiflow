<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ArchiFlow - Architectural Works Monitoring and Management System</title>
    <?php 
        $ROOT = '/' . explode('/', trim($_SERVER['SCRIPT_NAME'], '/'))[0] . '/';
        $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $relativePath = ltrim(str_replace($ROOT, '', $currentPath), '/');
        function sidebar_link_classes(string $targetPath): string {
            global $relativePath;
            $base = 'flex items-center space-x-3 px-3 py-2 rounded-lg transition duration-200 ';
            if ($targetPath !== '' && strpos($relativePath, $targetPath) === 0) {
                return $base . 'bg-gray-700';
            }
            return $base . 'hover:bg-gray-700';
        }
    ?>
    <base href="<?php echo htmlspecialchars($ROOT); ?>">
    
    <!-- TailwindCSS v4 -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" integrity="sha512-2SwdPD6INVrV/lHTZbO2nodKhrnDdJK9/kg2XD1r9uGqPo1cUbujc+IYdlYdEErWNu69gVcYgdxlmVmzTWnetw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    
    <!-- Google Fonts: Montserrat -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* Essential CSS that cannot be replaced with Tailwind */
        html {
            scroll-behavior: smooth;
        }
        
        /* Custom scrollbar - Browser specific, cannot use Tailwind */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #3b82f6;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #2563eb;
        }
        
        /* Grid Pattern Background - SVG data URL */
        .bg-grid-pattern {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3e%3cdefs%3e%3cpattern id='grid' width='10' height='10' patternUnits='userSpaceOnUse'%3e%3cpath d='M 10 0 L 0 0 0 10' fill='none' stroke='white' stroke-width='0.5'/%3e%3c/pattern%3e%3c/defs%3e%3crect width='100' height='100' fill='url(%23grid)'/%3e%3c/svg%3e");
        }
    </style>
</head>
<?php 
// Ensure session save path is writable and isolated for this app before starting session
if (session_status() === PHP_SESSION_NONE) {
    $sessDir = __DIR__ . '/../../tmp/sessions';
    if (!is_dir($sessDir)) { @mkdir($sessDir, 0777, true); }
    if (is_dir($sessDir) && is_writable($sessDir)) { @ini_set('session.save_path', $sessDir); }
    @session_start();
}
?>
<?php 
    $isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    $userType = $_SESSION['user_type'] ?? null;
        // Optional: admin badge count for new public inquiries (safe, best-effort)
        $adminNewInquiriesCount = null;
        if ($isLoggedIn && $userType === 'admin') {
            // Try to include DB and count new inquiries
            $dbPath = __DIR__ . '/../../db.php';
            if (file_exists($dbPath)) {
                @include_once $dbPath;
                if (isset($conn) && is_object($conn)) {
                    try {
                        $tbl = @$conn->query("SHOW TABLES LIKE 'public_inquiries'");
                        if ($tbl && $tbl->num_rows) {
                            $res = @$conn->query("SELECT COUNT(*) AS c FROM public_inquiries WHERE status = 'new' OR status IS NULL");
                            if ($res) {
                                $row = $res->fetch_assoc();
                                $adminNewInquiriesCount = (int)($row['c'] ?? 0);
                            }
                        }
                    } catch (\Throwable $e) {
                        // ignore to keep header robust
                    }
                }
            }
        }
?>
<body class="bg-gray-50 text-gray-800 min-h-screen flex flex-col">
    <!-- Top Navigation (guests only) -->
    <?php if (!$isLoggedIn): ?>
    <nav class="bg-white shadow-md sticky top-0 z-50">
        <div class="max-w-full px-4">
            <div class="flex justify-between items-center py-4">
                <!-- Left cluster: Logo + primary links -->
                <div class="flex items-center">
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-building text-blue-600 text-2xl"></i>
                        <span class="text-xl font-bold text-blue-600">ArchiFlow</span>
                    </div>
                    <div class="hidden md:flex items-center space-x-6 ml-4">
                        <a href="index.php" class="text-gray-600 hover:text-blue-600 font-medium">Home</a>
                        <a href="index.php#features" class="text-gray-600 hover:text-blue-600 font-medium">Features</a>
                        <a href="index.php#about" class="text-gray-600 hover:text-blue-600 font-medium">About</a>
                    </div>
                </div>

                <!-- Right navigation: Calculator, Inquiry, Auth -->
                <div class="hidden md:flex space-x-6 items-center">
                    <a href="index.php#budget" class="text-gray-600 hover:text-blue-600 font-medium">Calculator</a>
                    <a href="index.php#contact" class="text-gray-600 hover:text-blue-600 font-medium">Inquiry</a>
                    <a href="login.php" class="text-blue-600 hover:text-blue-800 font-medium transition duration-300">Login</a>
                    <a href="register.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition duration-300">Register</a>
                </div>
                
                <div class="md:hidden">
                    <button id="mobileMenuBtn" class="text-gray-600 hover:text-blue-600 focus:outline-none">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                </div>
            </div>
            
            <!-- Mobile Menu -->
            <div id="mobileMenu" class="md:hidden absolute top-full left-0 w-full bg-white shadow-lg border-t -translate-x-full transition-transform duration-300 ease-out">
                <div class="px-4 py-6 space-y-4">
                        <a href="index.php" class="block text-gray-600 hover:text-blue-600 font-medium py-2">Home</a>
                        <a href="index.php#features" class="block text-gray-600 hover:text-blue-600 font-medium py-2">Features</a>
                        <a href="index.php#about" class="block text-gray-600 hover:text-blue-600 font-medium py-2">About</a>
                        <a href="index.php#budget" class="block text-gray-600 hover:text-blue-600 font-medium py-2">Calculator</a>
                        <a href="index.php#contact" class="block text-gray-600 hover:text-blue-600 font-medium py-2">Inquiry</a>
                        <div class="pt-4 border-t border-gray-200 space-y-3">
                            <a href="login.php" class="block text-blue-600 hover:text-blue-800 font-medium py-2 text-center">Login</a>
                            <a href="register.php" class="block bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition duration-300 text-center">Register</a>
                        </div>
                </div>
            </div>
        </div>
    </nav>
    <?php endif; ?>

    <!-- Sidebar Navigation (for logged-in users) -->
    <?php if ($isLoggedIn && $userType): ?>
    <div class="flex flex-1">
        <!-- Sidebar -->
        <div id="sidebar" class="bg-gray-800 text-white w-64 min-h-screen fixed left-0 top-0 transform -translate-x-full transition-transform duration-300 ease-in-out z-40 lg:translate-x-0">
            <div class="p-4">
                <!-- User Info -->
                <div class="flex items-center space-x-3 mb-6 p-3 bg-gray-700 rounded-lg">
                    <div class="w-10 h-10 bg-blue-600 rounded-full flex items-center justify-center">
                        <i class="fas fa-user text-white"></i>
                    </div>
                    <div>
                        <p class="font-semibold"><?php echo htmlspecialchars($_SESSION['first_name'] ?? 'User'); ?></p>
                        <p class="text-sm text-gray-300 capitalize">
                            <?php 
                            if ($userType === 'employee' && isset($_SESSION['position'])) {
                                echo htmlspecialchars($_SESSION['position']);
                            } elseif ($userType === 'hr') {
                                echo 'HR Manager';
                            } else {
                                echo htmlspecialchars($userType);
                            }
                            ?>
                        </p>
                    </div>
                </div>

                <!-- Navigation Menu -->
                <nav class="space-y-2">
                    <?php if ($userType === 'admin'): ?>
                        <!-- Admin Navigation (Simplified - HR handles HR functions) -->
                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Administration</h3>
                            
                            <a href="manage_inquiries.php" class="<?php echo sidebar_link_classes('manage_inquiries.php'); ?>">
                                <i class="fas fa-inbox w-5"></i>
                                <span>Manage Inquiries</span>
                                <?php if (is_int($adminNewInquiriesCount) && $adminNewInquiriesCount > 0): ?>
                                    <span class="ml-auto inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-red-100 text-red-700 border border-red-200">
                                        <?php echo $adminNewInquiriesCount; ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                            <a href="admin/user-management/user-index.php" class="<?php echo sidebar_link_classes('admin/user-management'); ?>">
                                <i class="fas fa-users w-5"></i>
                                <span>User Management</span>
                            </a>
                            <a href="admin/settings/setting-index.php" class="<?php echo sidebar_link_classes('admin/settings'); ?>">
                                <i class="fas fa-cog w-5"></i>
                                <span>System Settings</span>
                            </a>
                        </div>

                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Project Management</h3>
                            <a href="admin/Projects/projects-index.php" class="<?php echo sidebar_link_classes('admin/Projects'); ?>">
                                <i class="fas fa-project-diagram w-5"></i>
                                <span>All Projects</span>
                            </a>
                            <a href="admin/Projects/assign-senior-architects.php" class="<?php echo sidebar_link_classes('admin/Projects/assign-senior-architects.php'); ?>">
                                <i class="fas fa-user-tie w-5"></i>
                                <span>Assign Senior Architects</span>
                            </a>
                            <a href="admin/Invoices/invoices-index.php" class="<?php echo sidebar_link_classes('admin/Invoices'); ?>">
                                <i class="fas fa-receipt w-5"></i>
                                <span>Invoices</span>
                            </a>
                            <a href="messages.php" class="<?php echo sidebar_link_classes('messages.php'); ?>">
                                <i class="fas fa-envelope w-5"></i>
                                <span>Messages</span>
                            </a>
                        </div>

                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Services</h3>
                            <a href="admin/Design-Service/design-services-index.php" class="<?php echo sidebar_link_classes('admin/Design-Service'); ?>">
                                <i class="fas fa-palette w-5"></i>
                                <span>Design Services</span>
                            </a>
                        </div>

                    <?php elseif ($userType === 'employee' && isset($_SESSION['position']) && strtolower(str_replace(' ', '_', trim($_SESSION['position']))) === 'architect'): ?>
                        <!-- Architect Navigation (Employee with position=architect) -->
                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">My Work</h3>
                            
                            <a href="employees/architects/projects.php" class="<?php echo sidebar_link_classes('employees/architects/projects.php'); ?>">
                                <i class="fas fa-project-diagram w-5"></i>
                                <span>My Projects</span>
                            </a>
                            <a href="employees/architects/tasks.php" class="<?php echo sidebar_link_classes('employees/architects/tasks.php'); ?>">
                                <i class="fas fa-tasks w-5"></i>
                                <span>Tasks</span>
                            </a>
                            <a href="employees/architects/project-materials.php" class="<?php echo sidebar_link_classes('employees/architects/project-materials.php'); ?>">
                                <i class="fas fa-tools w-5"></i>
                                <span>Project Materials</span>
                            </a>
                            <!-- Milestones removed for architects -->
                        </div>

                        <!-- Designs, Documents, Fee Estimates removed for architects -->

                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Communication</h3>
                            <a href="employees/architects/messages.php" class="<?php echo sidebar_link_classes('employees/architects/messages.php'); ?>">
                                <i class="fas fa-envelope w-5"></i>
                                <span>Messages</span>
                            </a>
                            <!-- Clients removed for architects -->
                        </div>

                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">HR Services</h3>
                            <a href="employees/attendance.php" class="<?php echo sidebar_link_classes('employees/attendance.php'); ?>">
                                <i class="fas fa-clock w-5"></i>
                                <span>Attendance</span>
                            </a>
                            <a href="employees/bank-details.php" class="<?php echo sidebar_link_classes('employees/bank-details.php'); ?>">
                                <i class="fas fa-university w-5"></i>
                                <span>Payment Details</span>
                            </a>
                            <a href="employees/leave-requests.php" class="<?php echo sidebar_link_classes('employees/leave-requests.php'); ?>">
                                <i class="fas fa-calendar-times w-5"></i>
                                <span>Leave Requests</span>
                            </a>
                        </div>

                    <?php elseif ($userType === 'employee' && isset($_SESSION['position']) && strtolower(str_replace(' ', '_', trim($_SESSION['position']))) === 'senior_architect'): ?>
                        <!-- Senior Architect Navigation -->
                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Design Oversight</h3>
                            
                            <a href="employees/senior_architects/projects.php" class="<?php echo sidebar_link_classes('employees/senior_architects/projects.php'); ?>">
                                <i class="fas fa-project-diagram"></i>
                                <span>Overseen Projects</span>
                            </a>
                            <a href="employees/senior_architects/reviews.php" class="<?php echo sidebar_link_classes('employees/senior_architects/reviews.php'); ?>">
                                <i class="fas fa-clipboard-check"></i>
                                <span>Reviews</span>
                            </a>
                            <a href="employees/senior_architects/pm_uploads.php" class="<?php echo sidebar_link_classes('employees/senior_architects/pm_uploads.php'); ?>">
                                <i class="fas fa-upload"></i>
                                <span>PM Uploads</span>
                            </a>
                            <a href="employees/senior_architects/inquiries.php" class="<?php echo sidebar_link_classes('employees/senior_architects/inquiries.php'); ?>">
                                <i class="fas fa-envelope-open-text"></i>
                                <span>Inquiries</span>
                            </a>
                            <a href="employees/senior_architects/create_project.php" class="<?php echo sidebar_link_classes('employees/senior_architects/create_project.php'); ?>">
                                <i class="fas fa-plus-circle"></i>
                                <span>Create Project</span>
                            </a>
                            <a href="employees/senior_architects/messages.php" class="<?php echo sidebar_link_classes('employees/senior_architects/messages.php'); ?>">
                                <i class="fas fa-envelope"></i>
                                <span>Messages</span>
                            </a>
                            <a href="employees/senior_architects/client_review_upload.php" class="<?php echo sidebar_link_classes('employees/senior_architects/client_review_upload.php'); ?>">
                                <i class="fas fa-file-upload"></i>
                                <span>Client Review Uploads</span>
                            </a>
                            <a href="employees/senior_architects/client_review_discuss.php" class="<?php echo sidebar_link_classes('employees/senior_architects/client_review_discuss.php'); ?>">
                                <i class="fas fa-comments"></i>
                                <span>Client Review Discuss</span>
                            </a>
                        </div>

                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">HR Services</h3>
                            <a href="employees/attendance.php" class="<?php echo sidebar_link_classes('employees/attendance.php'); ?>">
                                <i class="fas fa-clock"></i>
                                <span>Attendance</span>
                            </a>
                            <a href="employees/bank-details.php" class="<?php echo sidebar_link_classes('employees/bank-details.php'); ?>">
                                <i class="fas fa-university"></i>
                                <span>Payment Details</span>
                            </a>
                            <a href="employees/leave-requests.php" class="<?php echo sidebar_link_classes('employees/leave-requests.php'); ?>">
                                <i class="fas fa-calendar-times"></i>
                                <span>Leave Requests</span>
                            </a>
                        </div>

                    <?php elseif ($userType === 'employee' && isset($_SESSION['position']) && strtolower(str_replace(' ', '_', trim($_SESSION['position']))) === 'project_manager'): ?>
                        <!-- Project Manager Navigation -->
                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">My Work</h3>
                            
                            <a href="employees/project_manager/projects/projects.php" class="<?php echo sidebar_link_classes('employees/project_manager/projects'); ?>">
                                <i class="fas fa-project-diagram w-5"></i>
                                <span>Projects</span>
                            </a>
                            <a href="assign_architects.php" class="<?php echo sidebar_link_classes('assign_architects.php'); ?>">
                                <i class="fas fa-drafting-compass w-5"></i>
                                <span>Assign Architects</span>
                            </a>
                            <a href="employees/project_manager/pm_send_senior.php" class="<?php echo sidebar_link_classes('employees/project_manager/pm_send_senior.php'); ?>">
                                <i class="fas fa-upload w-5"></i>
                                <span>Send to Senior</span>
                            </a>
                            <a href="employees/project_manager/sa_responses.php" class="<?php echo sidebar_link_classes('employees/project_manager/sa_responses.php'); ?>">
                                <i class="fas fa-reply w-5"></i>
                                <span>Senior Responses</span>
                            </a>
                            <?php /* Removed Assign Site Inspector from PM navigation per request */ ?>
                            <a href="messages.php" class="<?php echo sidebar_link_classes('messages.php'); ?>">
                                <i class="fas fa-envelope w-5"></i>
                                <span>Messages</span>
                            </a>
                        </div>

                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">HR Services</h3>
                            <a href="employees/attendance.php" class="<?php echo sidebar_link_classes('employees/attendance.php'); ?>">
                                <i class="fas fa-clock w-5"></i>
                                <span>Attendance</span>
                            </a>
                            <a href="employees/bank-details.php" class="<?php echo sidebar_link_classes('employees/bank-details.php'); ?>">
                                <i class="fas fa-university w-5"></i>
                                <span>Payment Details</span>
                            </a>
                            <a href="employees/leave-requests.php" class="<?php echo sidebar_link_classes('employees/leave-requests.php'); ?>">
                                <i class="fas fa-calendar-times w-5"></i>
                                <span>Leave Requests</span>
                            </a>
                        </div>

                    <?php elseif ($userType === 'client'): ?>
                        <!-- Client Navigation -->
                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">My Projects</h3>
                            <a href="client/dashboard.php" class="<?php echo sidebar_link_classes('client/dashboard.php'); ?>">
                                <i class="fas fa-tachometer-alt w-5"></i>
                                <span>Dashboard</span>
                            </a>
                            <a href="client/projects-client.php" class="<?php echo sidebar_link_classes('client/projects-client.php'); ?>">
                                <i class="fas fa-home w-5"></i>
                                <span>My Projects</span>
                            </a>
                            <a href="client/progress.php" class="<?php echo sidebar_link_classes('client/progress.php'); ?>">
                                <i class="fas fa-chart-line w-5"></i>
                                <span>Progress Tracking</span>
                            </a>
                        </div>
                        <!-- Removed Design Gallery, Fee Estimates, Contracts, and Notifications per request -->
                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Communication</h3>
                            <a href="client/messages.php" class="<?php echo sidebar_link_classes('client/messages.php'); ?>">
                                <i class="fas fa-envelope w-5"></i>
                                <span>Messages</span>
                            </a>
                        </div>
                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Tools</h3>
                            <a href="client/fee-calculator.php" class="<?php echo sidebar_link_classes('client/fee-calculator.php'); ?>">
                                <i class="fas fa-calculator w-5"></i>
                                <span>Design Fee Calculator</span>
                            </a>
                            <a href="client/review_files.php" class="<?php echo sidebar_link_classes('client/review_files.php'); ?>">
                                <i class="fas fa-clipboard-check w-5"></i>
                                <span>Review Files</span>
                            </a>
                        </div>

                    <?php elseif ($userType === 'employee'): ?>
                        <!-- Employee Navigation (Architects & Project Managers) -->
                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">My Work</h3>
                            
                            <a href="employees/projects.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 transition duration-200">
                                <i class="fas fa-project-diagram w-5"></i>
                                <span>My Projects</span>
                            </a>
                            <a href="employees/tasks.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 transition duration-200">
                                <i class="fas fa-tasks w-5"></i>
                                <span>Tasks</span>
                            </a>
                            <a href="employees/milestones.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 transition duration-200">
                                <i class="fas fa-flag-checkered w-5"></i>
                                <span>Milestones</span>
                            </a>
                        </div>

                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Design & Documents</h3>
                            <a href="employees/designs.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 transition duration-200">
                                <i class="fas fa-drafting-compass w-5"></i>
                                <span>Designs</span>
                            </a>
                            <a href="employees/documents.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 transition duration-200">
                                <i class="fas fa-file-alt w-5"></i>
                                <span>Documents</span>
                            </a>
                            <a href="employees/estimates.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 transition duration-200">
                                <i class="fas fa-calculator w-5"></i>
                                <span>Fee Estimates</span>
                            </a>
                        </div>

                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Communication</h3>
                            <a href="messages.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 transition duration-200">
                                <i class="fas fa-envelope w-5"></i>
                                <span>Messages</span>
                            </a>
                            <a href="employees/clients.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 transition duration-200">
                                <i class="fas fa-handshake w-5"></i>
                                <span>Clients</span>
                            </a>
                        </div>

                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">HR Services</h3>
                            <a href="employees/attendance.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 transition duration-200">
                                <i class="fas fa-clock w-5"></i>
                                <span>Attendance</span>
                            </a>
                            <a href="employees/bank-details.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 transition duration-200">
                                <i class="fas fa-university w-5"></i>
                                <span>Payment Details</span>
                            </a>
                            <a href="employees/timesheet.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 transition duration-200">
                                <i class="fas fa-calendar-alt w-5"></i>
                                <span>Timesheet</span>
                            </a>
                            <a href="employees/leave-requests.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 transition duration-200">
                                <i class="fas fa-calendar-times w-5"></i>
                                <span>Leave Requests</span>
                            </a>
                            <a href="employees/payroll.php" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 transition duration-200">
                                <i class="fas fa-money-bill-wave w-5"></i>
                                <span>My Payroll</span>
                            </a>
                        </div>

                    <?php elseif ($userType === 'hr'): ?>
                        <!-- HR Navigation -->
                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">HR</h3>
                            
                            <a href="hr/employees.php" class="<?php echo sidebar_link_classes('hr/employees.php'); ?>">
                                <i class="fas fa-users w-5"></i>
                                <span>Employees</span>
                            </a>
                            <a href="hr/attendance.php" class="<?php echo sidebar_link_classes('hr/attendance.php'); ?>">
                                <i class="fas fa-calendar-check w-5"></i>
                                <span>Attendance</span>
                            </a>
                            <a href="hr/leave-requests.php" class="<?php echo sidebar_link_classes('hr/leave-requests.php'); ?>">
                                <i class="fas fa-file-medical w-5"></i>
                                <span>Leave Requests</span>
                            </a>
                            <a href="hr/bank-requests.php" class="<?php echo sidebar_link_classes('hr/bank-requests.php'); ?>">
                                <i class="fas fa-university w-5"></i>
                                <span>Bank Requests</span>
                            </a>
                            <a href="hr/payroll.php" class="<?php echo sidebar_link_classes('hr/payroll.php'); ?>">
                                <i class="fas fa-wallet w-5"></i>
                                <span>Payroll</span>
                            </a>
                            <a href="messages.php" class="<?php echo sidebar_link_classes('messages.php'); ?>">
                                <i class="fas fa-envelope w-5"></i>
                                <span>Messages</span>
                            </a>
                        </div>
                    <?php endif; ?>

                    <!-- Common Navigation -->
                    <div class="mb-4">
                        <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Account</h3>
                        <a href="profile.php" class="<?php echo sidebar_link_classes('profile.php'); ?>">
                            <i class="fas fa-user-cog w-5"></i>
                            <span>Profile Settings</span>
                        </a>
                        <a href="#" onclick="event.preventDefault(); logout();" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 transition duration-200">
                            <i class="fas fa-sign-out-alt w-5"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </nav>
            </div>
        </div>

        <!-- Sidebar Toggle Button (Mobile) -->
        <button id="sidebarToggle" class="lg:hidden fixed top-4 left-4 z-50 bg-gray-800 text-white p-2 rounded-lg shadow-lg">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Main Content Area -->
        <div id="mainContent" class="flex-1 lg:ml-64 transition-all duration-300">
    <?php else: ?>
        <!-- Main Content Area (for non-logged-in users) -->
        <div id="mainContent" class="flex-1">
    <?php endif; ?>
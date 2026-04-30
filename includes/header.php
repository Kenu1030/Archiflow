<?php
if (!isset($page_title)) { $page_title = 'ArchiFlow Dashboard'; }
$role = $_SESSION['role'] ?? null;
$full_name = $_SESSION['full_name'] ?? '';
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$current = basename($_SERVER['SCRIPT_NAME']);
if (!function_exists('isActive')) { function isActive($file, $current){ return $file === $current ? ' active' : ''; } }
// Safe badge count for admin: new public inquiries
$admin_new_inquiries_count = null;
if ($role === 'administrator' && isset($conn) && is_object($conn) && method_exists($conn, 'query')) {
  try {
    $checkTbl = @$conn->query("SHOW TABLES LIKE 'public_inquiries'");
    if ($checkTbl && $checkTbl->num_rows) {
      $res = @$conn->query("SELECT COUNT(*) AS c FROM public_inquiries WHERE status = 'new' OR status IS NULL");
      if ($res) {
        $row = $res->fetch_assoc();
        $admin_new_inquiries_count = (int)($row['c'] ?? 0);
      }
    }
  } catch (\Throwable $e) {
    // Silently ignore to avoid breaking header if DB unavailable here
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no">
<title><?php echo htmlspecialchars($page_title); ?> | ArchiFlow</title>

<!-- Tailwind CSS CDN -->
<script src="https://cdn.tailwindcss.com"></script>

<!-- FontAwesome Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- Google Fonts - Montserrat -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

<!-- Tailwind CSS Configuration -->
<script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: {
                    'montserrat': ['Montserrat', 'sans-serif'],
                },
                colors: {
                    'navy': {
                        50: '#f0f9ff',
                        100: '#e0f2fe',
                        200: '#bae6fd',
                        300: '#7dd3fc',
                        400: '#38bdf8',
                        500: '#0ea5e9',
                        600: '#0284c7',
                        700: '#0369a1',
                        800: '#075985',
                        900: '#0c4a6e',
                        950: '#082f49'
                    }
                },
                backgroundImage: {
                    'navy-gradient': 'linear-gradient(135deg, #0c1e3d 0%, #1e40af 25%, #3b82f6 75%, #60a5fa 100%)',
                    'navy-dark': 'linear-gradient(135deg, #0a1628 0%, #0c2340 50%, #1e40af 100%)',
                },
                animation: {
                    'pulse-slow': 'pulse 3s ease-in-out infinite',
                    'float': 'float 6s ease-in-out infinite',
                    'glow': 'glow 2s ease-in-out infinite alternate',
                },
                keyframes: {
                    float: {
                        '0%, 100%': { transform: 'translateY(0px)' },
                        '50%': { transform: 'translateY(-20px)' },
                    },
                    glow: {
                        '0%': { boxShadow: '0 0 20px rgba(59, 130, 246, 0.3)' },
                        '100%': { boxShadow: '0 0 30px rgba(147, 51, 234, 0.6)' },
                    }
                }
            }
        }
    }
</script>

<style>
    /* Enhanced Tailwind utilities and animations */
    .backdrop-blur-glass {
        backdrop-filter: blur(20px);
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }
    /* Dark variants for glass surfaces and neutral utilities */
    body.dark .backdrop-blur-glass { background: rgba(17, 24, 39, 0.6); border-color: rgba(255, 255, 255, 0.08); }
    body.dark .card-glass { background: rgba(17, 24, 39, 0.45); border-color: rgba(255, 255, 255, 0.08); box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4); }
    body.dark .bg-white\/80 { background-color: rgba(17, 24, 39, 0.6) !important; }
    body.dark .border-gray-200 { border-color: rgba(255, 255, 255, 0.12) !important; }
    body.dark .text-gray-700 { color: #e5e7eb !important; }
    body.dark .text-gray-900 { color: #f3f4f6 !important; }
    body.dark .text-gray-500 { color: #9ca3af !important; }
    body.dark .bg-gray-50 { background-color: rgba(31, 41, 55, 0.6) !important; }
    
    .text-shadow {
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    
    .glass-effect {
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }
    
    /* Animation delays for background elements */
    .animation-delay-2000 {
        animation-delay: 2s;
    }
    .animation-delay-4000 {
        animation-delay: 4s;
    }
    .animation-delay-6000 {
        animation-delay: 6s;
    }
    
    /* Enhanced background decorations */
    .bg-decoration {
        position: absolute;
        border-radius: 50%;
        mix-blend-mode: multiply;
        filter: blur(40px);
        animation: pulse 4s ease-in-out infinite;
    }
    
    /* Smooth transitions for all elements */
    * {
        transition: transform 0.3s ease, box-shadow 0.3s ease, background-color 0.3s ease;
    }
    
    /* Enhanced hover effects - now handled by Tailwind classes */
    
    /* Loading animations - now handled by Tailwind classes */
    
    /* Theme transitions */
    body {
        transition: background 0.5s ease;
    }
    
    /* Enhanced card styles */
    .card-glass {
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    }
    
    /* Sidebar always visible */
    .sidebar-hidden {
        transform: translateX(0);
    }

    .sidebar-visible {
        transform: translateX(0);
    }

    /* Sidebar positioning to avoid footer overlap */
    #sidebar {
        bottom: 120px !important;
        height: calc(100vh - 64px - 120px) !important;
        z-index: 40 !important;
        overflow-y: auto !important;
    }

    /* Ensure sidebar content scrolls properly */
    #sidebar .h-full {
        height: 100% !important;
        overflow-y: auto !important;
        display: flex !important;
        flex-direction: column !important;
    }

    /* Sidebar sections spacing */
    #sidebar .space-y-6 > * + * {
        margin-top: 1.5rem !important;
    }

    /* Content always shifts for sidebar on desktop */
    #content-wrapper { width: 100%; transition: margin-left 0.3s ease, width 0.3s ease; }
    @media (min-width: 1024px) { /* lg */
        #content-wrapper { margin-left: 18rem; width: calc(100% - 18rem); } /* w-72 */
        #sidebar { display: block !important; }
    }
    @media (max-width: 1023px) { /* below lg */
        #content-wrapper { margin-left: 0; width: 100%; }
        #sidebar { display: none !important; } /* Hide sidebar on mobile */
        body { padding-bottom: 120px; } /* Add bottom padding for footer on mobile */
    }

    /* Footer spacing adjustments */
    @media (min-width: 1024px) { /* lg and up */
        footer { margin-left: 0 !important; }
    }
    
    /* Mobile menu overlay */
    .mobile-overlay {
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px);
    }
    /* Sidebar overlays content and footer; footer remains full width */
</style>
</head>
<body class="font-montserrat bg-gradient-to-br from-blue-50 via-white to-purple-50 min-h-screen relative flex flex-col">
<!-- Background decoration matching other pages -->
<div class="fixed inset-0 opacity-10 pointer-events-none">
    <div class="absolute top-1/4 left-1/4 w-64 h-64 bg-blue-400 rounded-full mix-blend-multiply blur-xl animate-pulse"></div>
    <div class="absolute top-3/4 right-1/4 w-64 h-64 bg-purple-400 rounded-full mix-blend-multiply blur-xl animate-pulse animation-delay-2000"></div>
    <div class="absolute bottom-1/4 left-1/3 w-64 h-64 bg-pink-400 rounded-full mix-blend-multiply blur-xl animate-pulse animation-delay-4000"></div>
    <div class="absolute top-1/2 left-1/2 w-32 h-32 bg-yellow-400 rounded-full mix-blend-multiply blur-xl animate-pulse animation-delay-6000"></div>
</div>

<!-- Modern Page Loader -->
<div class="fixed inset-0 bg-navy-gradient flex items-center justify-center z-50 transition-opacity duration-600" id="page-loader">
    <div class="text-center text-white">
        <div class="w-16 h-16 border-4 border-white/20 border-l-white rounded-full animate-spin mx-auto mb-4"></div>
        <div class="font-medium text-lg opacity-80">Loading ArchiFlow...</div>
    </div>
</div>

<!-- Theme Toggle moved into navbar -->

<header class="w-full z-30">
  <div class="w-full">
    <div class="flex items-center justify-between h-16 bg-white/80 backdrop-blur-glass px-4 shadow-sm border border-white/40">
      <!-- Left: Brand -->
      <div class="flex items-center space-x-3">
        <a href="index.php" class="flex items-center space-x-2">
          <span class="inline-flex items-center justify-center bg-gradient-to-r from-blue-600 to-purple-600 w-9 h-9 rounded-lg text-white shadow-md">
            <i class="fas fa-building"></i>
          </span>
          <span class="hidden sm:block">
            <span class="block text-sm font-bold text-gray-900 leading-4">ArchiFlow</span>
            <span class="block text-[10px] text-gray-500">Project Suite</span>
          </span>
        </a>
      </div>

      <!-- Center: Quick role links -->
      <nav class="hidden md:block">
        <ul class="flex items-center gap-2">
          <?php if ($role === 'administrator'): ?>
            <?php if ($current_page !== 'admin_dashboard'): ?>
              <li>
                <a href="admin_dashboard.php" class="px-3 py-1.5 rounded-full text-sm font-medium text-gray-700 hover:text-white hover:bg-blue-600 transition-colors">Admin</a>
              </li>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($role === 'project_manager'): ?>
            <?php if ($current_page !== 'pm_dashboard'): ?>
              <li>
                <a href="pm_dashboard.php" class="px-3 py-1.5 rounded-full text-sm font-medium text-gray-700 hover:text-white hover:bg-blue-600 transition-colors">PM</a>
              </li>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($role === 'manager'): ?>
            <?php if ($current_page !== 'manager_dashboard'): ?>
              <li>
                <a href="manager_dashboard.php" class="px-3 py-1.5 rounded-full text-sm font-medium text-gray-700 hover:text-white hover:bg-blue-600 transition-colors">Manager</a>
              </li>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($role === 'hr'): ?>
            <?php if ($current_page !== 'hr_dashboard'): ?>
              <li>
                <a href="hr_dashboard.php" class="px-3 py-1.5 rounded-full text-sm font-medium text-gray-700 hover:text-white hover:bg-blue-600 transition-colors">HR</a>
              </li>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($role === 'architect'): ?>
            <?php if ($current_page !== 'architect_dashboard'): ?>
              <li>
                <a href="architect_dashboard.php" class="px-3 py-1.5 rounded-full text-sm font-medium text-gray-700 hover:text-white hover:bg-blue-600 transition-colors">Architect</a>
              </li>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($role === 'contractor'): ?>
            <?php if ($current_page !== 'contractor_dashboard'): ?>
              <li>
                <a href="contractor_dashboard.php" class="px-3 py-1.5 rounded-full text-sm font-medium text-gray-700 hover:text-white hover:bg-blue-600 transition-colors">Contractor</a>
              </li>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($role === 'client'): ?>
            <?php if ($current_page !== 'client_dashboard'): ?>
              <li>
                <a href="client_dashboard.php" class="px-3 py-1.5 rounded-full text-sm font-medium text-gray-700 hover:text-white hover:bg-blue-600 transition-colors">Client</a>
              </li>
            <?php endif; ?>
          <?php endif; ?>
        </ul>
      </nav>

      <!-- Right: Theme + User -->
      <div class="flex items-center space-x-3">
        <button id="themeToggle" class="inline-flex items-center justify-center w-10 h-10 rounded-lg text-gray-700 hover:text-blue-600 hover:bg-gray-100 transition-colors border border-gray-200 bg-white/60" title="Toggle theme">
        <i class="fas fa-palette text-lg" id="themeIcon"></i>
    </button>
        <div class="hidden sm:flex items-center space-x-2 px-3 py-1.5 rounded-full bg-gray-50 border border-gray-200">
          <span class="inline-flex w-6 h-6 items-center justify-center rounded-full bg-gradient-to-r from-blue-600 to-purple-600 text-white text-xs">
            <i class="fas fa-user"></i>
          </span>
          <span class="text-sm font-medium text-gray-700 truncate max-w-[140px]" title="<?php echo htmlspecialchars($full_name); ?>"><?php echo htmlspecialchars($full_name); ?></span>
        </div>
        <a class="inline-flex items-center px-3 py-2 rounded-lg text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 shadow-sm" href="logout.php">
          <i class="fas fa-sign-out-alt mr-2"></i>Logout
        </a>
      </div>
    </div>
  </div>
</header>
<aside id="sidebar" class="fixed top-16 left-0 w-72 max-w-full z-50 transition-transform duration-300 ease-out pointer-events-auto bottom-30" aria-label="Primary Navigation" aria-hidden="false" style="height: calc(100vh - 64px - 120px);">
  <div class="h-full bg-white/80 backdrop-blur-glass border-r border-white/40 pt-6 flex flex-col shadow-xl overflow-y-auto">
    <!-- Brand (matches nav) -->
    <div class="px-5 mb-4">
</div>

    <nav class="flex-1 overflow-y-auto px-3">
      <a href="profile.php" class="flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-white hover:bg-blue-600 transition-colors <?php echo isActive('profile.php',$current) ? 'bg-blue-50 text-blue-700' : ''; ?>">
        <span class="flex items-center space-x-2">
          <i class="fas fa-user-circle w-5 text-gray-400"></i>
          <span>Profile</span>
        </span>
        <?php if (isActive('profile.php',$current)): ?><i class="fas fa-circle text-[8px] text-blue-600"></i><?php endif; ?>
      </a>

      <?php if ($role === 'administrator'): ?>
        <div class="mt-6">
          <div class="px-3 text-[11px] uppercase tracking-wider text-gray-400 mb-2">Administration</div>
          <?php if ($current_page !== 'admin_dashboard'): ?>
          <a href="admin_dashboard.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('admin_dashboard.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-gauge-high w-5 mr-2 text-gray-400"></i>
            <span>Admin Dashboard</span>
          </a>
          <?php endif; ?>
          <a href="manage_inquiries.php" class="flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('manage_inquiries.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <span class="flex items-center">
              <i class="fas fa-inbox w-5 mr-2 text-gray-400"></i>
              <span>Manage Inquiries</span>
            </span>
            <?php if (is_int($admin_new_inquiries_count) && $admin_new_inquiries_count > 0): ?>
              <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-red-100 text-red-700 border border-red-200">
                <?php echo $admin_new_inquiries_count; ?>
              </span>
            <?php endif; ?>
          </a>
          <a href="system_settings.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('system_settings.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-sliders-h w-5 mr-2 text-gray-400"></i>
            <span>System Settings</span>
          </a>
        </div>
      <?php endif; ?>

      <?php if ($role === 'senior_architect'): ?>
        <div class="mt-6">
          <div class="px-3 text-[11px] uppercase tracking-wider text-gray-400 mb-2">Client Management</div>
          <?php if ($current_page !== 'senior_architect_dashboard'): ?>
          <a href="senior_architect_dashboard.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('senior_architect_dashboard.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-drafting-compass w-5 mr-2 text-gray-400"></i>
            <span>Project Creation</span>
          </a>
          <?php endif; ?>
          <a href="employees/senior_architects/inquiries.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('employees/senior_architects/inquiries.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-inbox w-5 mr-2 text-gray-400"></i>
            <span>Client Inquiries</span>
          </a>
        </div>
      <?php endif; ?>

      <?php if ($role === 'project_manager'): ?>
        <?php if ($current_page !== 'pm_dashboard'): ?>
        <div class="mt-6">
          <a href="pm_dashboard.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('pm_dashboard.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-project-diagram w-5 mr-2 text-gray-400"></i>
            <span>Project Manager</span>
          </a>
        </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($role === 'manager'): ?>
        <?php if ($current_page !== 'manager_dashboard'): ?>
        <div class="mt-6">
          <a href="manager_dashboard.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('manager_dashboard.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-user-tie w-5 mr-2 text-gray-400"></i>
            <span>Manager</span>
          </a>
        </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($role === 'hr'): ?>
        <div class="mt-6">
          <div class="px-3 text-[11px] uppercase tracking-wider text-gray-400 mb-2">Human Resources</div>
          <?php if ($current_page !== 'hr_dashboard'): ?>
          <a href="hr_dashboard.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('hr_dashboard.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-people-group w-5 mr-2 text-gray-400"></i>
            <span>HR Dashboard</span>
          </a>
          <?php endif; ?>
          <a href="hr_employees.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('hr_employees.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-id-badge w-5 mr-2 text-gray-400"></i>
            <span>Employees</span>
          </a>
          <a href="hr_pending.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('hr_pending.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-hourglass-half w-5 mr-2 text-gray-400"></i>
            <span>Pending</span>
          </a>
          <a href="hr_attendance.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('hr_attendance.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-calendar-check w-5 mr-2 text-gray-400"></i>
            <span>Attendance</span>
          </a>
          <a href="hr_payroll.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('hr_payroll.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-file-invoice-dollar w-5 mr-2 text-gray-400"></i>
            <span>Payroll</span>
          </a>
        </div>
      <?php endif; ?>

      <?php if ($role === 'architect'): ?>
        <div class="mt-6">
          <div class="px-3 text-[11px] uppercase tracking-wider text-gray-400 mb-2">Architecture</div>
          <?php if ($current_page !== 'architect_dashboard'): ?>
          <a href="architect_dashboard.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo (isActive('architect_dashboard.php',$current) || str_starts_with($current,'architect_')) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-gem w-5 mr-2 text-gray-400"></i>
            <span>Dashboard</span>
          </a>
          <?php endif; ?>
          <a href="architect_projects.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('architect_projects.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-cubes w-5 mr-2 text-gray-400"></i>
            <span>My Projects</span>
          </a>
          <a href="feedback.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('feedback.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-comments w-5 mr-2 text-gray-400"></i>
            <span>Collaborate</span>
          </a>
        </div>
      <?php endif; ?>

      <?php if ($role === 'contractor'): ?>
        <?php if ($current_page !== 'contractor_dashboard'): ?>
        <div class="mt-6">
          <a href="contractor_dashboard.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('contractor_dashboard.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-hard-hat w-5 mr-2 text-gray-400"></i>
            <span>Contractor</span>
          </a>
        </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($role === 'client'): ?>
        <?php if ($current_page !== 'client_dashboard'): ?>
        <div class="mt-6">
          <a href="client_dashboard.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo isActive('client_dashboard.php',$current) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
            <i class="fas fa-handshake w-5 mr-2 text-gray-400"></i>
            <span>Client Portal</span>
          </a>
        </div>
        <?php endif; ?>

        <!-- Client Quick Links (match sidebar link style) -->
        <div class="mt-6">
          <div class="px-3 text-[11px] uppercase tracking-wider text-gray-400 mb-2">Quick Links</div>
          <nav class="space-y-1 px-2">
            <a href="client_dashboard.php#projects" data-sb-pill="projects" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-white hover:bg-blue-600 transition-colors">
              <i class="fas fa-folder-open w-5 mr-2 text-gray-400"></i>
              <span>My Projects</span>
            </a>
            <a href="client_dashboard.php#documents" data-sb-pill="documents" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-white hover:bg-blue-600 transition-colors">
              <i class="fas fa-file-alt w-5 mr-2 text-gray-400"></i>
              <span>Documents</span>
            </a>
            <a href="client_dashboard.php#feedback" data-sb-pill="feedback" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-white hover:bg-blue-600 transition-colors">
              <i class="fas fa-comment-dots w-5 mr-2 text-gray-400"></i>
              <span>Feedback</span>
            </a>
            <a href="client_dashboard.php#inquiries" data-sb-pill="inquiries" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-white hover:bg-blue-600 transition-colors">
              <i class="fas fa-question-circle w-5 mr-2 text-gray-400"></i>
              <span>Inquiries</span>
            </a>
            <a href="profile.php" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium <?php echo $current_page==='profile' ? 'bg-green-50 text-green-700' : 'text-gray-700 hover:text-white hover:bg-blue-600'; ?> transition-colors">
              <i class="fas fa-user-circle w-5 mr-2 text-gray-400"></i>
              <span>Profile</span>
            </a>
          </nav>
        </div>
      <?php endif; ?>
    </nav>
  </div>
  </div>
</aside>

<div class="content-wrapper transition-all duration-300 relative z-10 flex-1" id="content-wrapper">

<!-- Enhanced Dashboard JavaScript (consistent with other pages) -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Theme toggle functionality (matching login.php and register.php)
    const themeToggle = document.getElementById("themeToggle");
    const themeIcon = document.getElementById("themeIcon");
    const body = document.body;

    function applyTheme(theme) {
        if (theme === "dark") {
            body.classList.add("dark");
            document.documentElement.classList.add("dark");
            themeIcon.className = "fas fa-moon text-lg";
            body.classList.add("bg-gradient-to-br", "from-gray-900", "via-slate-900", "to-slate-800");
            body.classList.remove("bg-gradient-to-br", "from-blue-50", "via-white", "to-purple-50");
        } else {
            body.classList.remove("dark");
            document.documentElement.classList.remove("dark");
            themeIcon.className = "fas fa-palette text-lg";
            body.classList.remove("bg-gradient-to-br", "from-gray-900", "via-slate-900", "to-slate-800");
            body.classList.add("bg-gradient-to-br", "from-blue-50", "via-white", "to-purple-50");
        }
    }

    // Load saved theme
    const savedTheme = localStorage.getItem("theme") || "light";
    applyTheme(savedTheme);

    // Theme toggle event
    if (themeToggle) {
        themeToggle.addEventListener("click", () => {
            const isDark = body.classList.contains("dark");
            const newTheme = isDark ? "light" : "dark";
            applyTheme(newTheme);
            localStorage.setItem("theme", newTheme);
        });
    }

    // Hide page loader with smooth transition
    const loader = document.getElementById('page-loader');
    if (loader) {
        setTimeout(() => {
            loader.classList.add('opacity-0');
            setTimeout(() => {
                if (loader.parentNode) {
                    loader.parentNode.removeChild(loader);
                }
            }, 600);
        }, 500);
    }

    // Initialize sidebar as always visible
    const sidebar = document.getElementById('sidebar');
    const contentWrapper = document.getElementById('content-wrapper');

    function updateSidebarHeight() {
        if (sidebar && window.innerWidth >= 1024) {
            const headerHeight = 64; // top-16 = 64px
            const footerHeight = 120; // estimated footer height
            const viewportHeight = window.innerHeight;
            const sidebarHeight = viewportHeight - headerHeight - footerHeight;

            sidebar.style.setProperty('height', sidebarHeight + 'px');
        }
    }

    if (sidebar) {
        // Ensure sidebar is always visible
        sidebar.classList.add('sidebar-visible');
        sidebar.classList.remove('sidebar-hidden');

        // Set proper accessibility attributes
        sidebar.setAttribute('aria-hidden', 'false');

        // On large screens, ensure content is properly shifted
        if (window.innerWidth >= 1024) {
            document.body.classList.add('sidebar-open');
            updateSidebarHeight();
        }
    }

    // Update sidebar height on window resize
    window.addEventListener('resize', updateSidebarHeight);

    // Enhanced button ripple effects (matching other pages)
    const buttons = document.querySelectorAll('button, .btn, a[class*="bg-"]');
    buttons.forEach(button => {
        button.addEventListener('click', function(e) {
            const ripple = document.createElement('span');
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;

            ripple.className = 'absolute rounded-full bg-white/60 animate-ping pointer-events-none';
            ripple.style.setProperty('width', size + 'px');
            ripple.style.setProperty('height', size + 'px');
            ripple.style.setProperty('left', x + 'px');
            ripple.style.setProperty('top', y + 'px');

            this.classList.add('relative', 'overflow-hidden');
            this.appendChild(ripple);

            setTimeout(() => ripple.remove(), 600);
        });
    });

    // Enhanced form interactions
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.classList.add('ring-2', 'ring-blue-500', 'border-blue-500', 'scale-105', 'shadow-xl', 'shadow-blue-500/20');
        });

        input.addEventListener('blur', function() {
            this.classList.remove('ring-2', 'ring-blue-500', 'border-blue-500', 'scale-105', 'shadow-xl', 'shadow-blue-500/20');
        });
    });

    // Enhanced card animations with Intersection Observer
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const cardObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('opacity-100', 'translate-y-0');
            }
        });
    }, observerOptions);
    
    // Observe all dashboard cards
    const cards = document.querySelectorAll('.bg-white\\/80, .bg-gradient-to-br, .card-glass');
    cards.forEach(card => {
        card.classList.add('opacity-0', 'translate-y-5', 'transition-all', 'duration-600', 'ease-out');
        cardObserver.observe(card);
    });

    // Enhanced table interactions
    const tableRows = document.querySelectorAll('tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.classList.add('bg-blue-50/50', 'scale-105', 'transition-all', 'duration-200');
        });

        row.addEventListener('mouseleave', function() {
            this.classList.remove('bg-blue-50/50', 'scale-105', 'transition-all', 'duration-200');
        });
    });

    // Smooth scroll for anchor links
    const anchorLinks = document.querySelectorAll('a[href^="#"]');
    anchorLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ 
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Progress bar animations
    const progressBars = document.querySelectorAll('[style*="width:"]');
    progressBars.forEach(bar => {
        const width = bar.style.width;
        bar.classList.add('w-0', 'transition-all', 'duration-1000', 'ease-out');
        setTimeout(() => {
            bar.style.setProperty('width', width);
        }, 500);
    });

    // Enhanced error handling
    window.addEventListener('error', (e) => {
        console.warn('Dashboard enhancement error:', e.error);
    });

    // Performance monitoring
    if (window.performance && window.performance.mark) {
        window.performance.mark('dashboard-enhanced');
    }

    // Sidebar pill auto-close on mobile
    const pillLinks = document.querySelectorAll('[data-sb-pill]');
    if (pillLinks.length) {
        pillLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    const toggleBtn = document.querySelector('.nav-toggle');
                    if (toggleBtn) toggleBtn.click();
                }
            });
        });
    }
});
</script>

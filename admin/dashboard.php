<?php
session_start();
require_once '../backend/auth.php';
require_once '../backend/connection/connect.php';

$auth = new Auth();
if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$user = $auth->getCurrentUser();

// Prepare dashboard data
$db = null;
$stats = [
    'total_users' => 0,
    'employees' => 0,
    'active_projects' => 0,
    'new_public_inquiries' => 0,
    'unread_notifications' => 0,
];
$recentPublic = [];
$recentClient = [];
$projectBreakdown = [];
$recentUsers = [];
$allUsers = [];
$recentLogs = [];
$dbError = '';
$debug = isset($_GET['debug']) && (strtolower((string)$_GET['debug']) === '1' || strtolower((string)$_GET['debug']) === 'true');
$debugInfo = [];

try {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Total users
    $stats['total_users'] = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();

    // Employees (best-effort)
    $stats['employees'] = (int)$db->query('SELECT COUNT(*) FROM employees')->fetchColumn();

    // Active projects (planning/design/construction)
    $stmt = $db->query("SELECT COUNT(*) FROM projects WHERE (status IN ('planning','design','construction')) AND (is_deleted=0 OR is_deleted IS NULL) AND (is_archived=0 OR is_archived IS NULL)");
    $stats['active_projects'] = (int)$stmt->fetchColumn();

    // New public inquiries
    $stats['new_public_inquiries'] = (int)$db->query("SELECT COUNT(*) FROM public_inquiries WHERE status='new' OR status IS NULL")->fetchColumn();

    // Unread notifications for this user
    $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$_SESSION['user_id']]);
    $stats['unread_notifications'] = (int)$stmt->fetchColumn();

    // Recent lists
    $recentPublic = $db->query("SELECT id, name, email, inquiry_type, status, created_at FROM public_inquiries ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $recentClient = $db->query("SELECT id, category, subject, status, created_at FROM client_inquiries ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Project status breakdown (top statuses)
    try {
        $stmt = $db->query("SELECT LOWER(TRIM(COALESCE(status,'unknown'))) AS s, COUNT(*) AS c
                             FROM projects
                             WHERE (is_deleted=0 OR is_deleted IS NULL) AND (is_archived=0 OR is_archived IS NULL)
                             GROUP BY s ORDER BY c DESC LIMIT 8");
        $projectBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { /* optional */ }

    // Recent users (best-effort: detect columns)
    try {
        $uCols = [];
        foreach ($db->query('SHOW COLUMNS FROM users') as $uc) { $uCols[$uc['Field']] = true; }
        $hasCreated = isset($uCols['created_at']);
        $hasRole = isset($uCols['role']);
        $hasPosition = isset($uCols['position']);
        $hasEmail = isset($uCols['email']);
        $roleExpr = ($hasRole || $hasPosition) ? 'COALESCE(role, position)' : "NULL";
        $createdExpr = $hasCreated ? 'created_at' : 'NULL';
        $emailExpr = $hasEmail ? 'email' : 'NULL';
        $orderCol = $hasCreated ? 'created_at' : 'user_id';
        $sql = "SELECT user_id, first_name, last_name, $emailExpr AS email, $roleExpr AS role, $createdExpr AS created_at FROM users ORDER BY $orderCol DESC LIMIT 5";
        $recentUsers = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Full user list (capped) for admin view
        $sqlAll = "SELECT user_id, first_name, last_name, $emailExpr AS email, $roleExpr AS role, $createdExpr AS created_at, user_type FROM users ORDER BY $orderCol DESC LIMIT 100";
        $allUsers = $db->query($sqlAll)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { /* optional */ }

    // Recent system activity (if system_logs exists; detect columns)
    try {
        $tbl = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='system_logs'")->fetchColumn();
        if ((int)$tbl > 0) {
            $lCols = [];
            foreach ($db->query('SHOW COLUMNS FROM system_logs') as $lc) { $lCols[$lc['Field']] = true; }
            $lvl = isset($lCols['level']) ? 'level' : (isset($lCols['severity']) ? 'severity' : "NULL");
            $msg = isset($lCols['message']) ? 'message' : (isset($lCols['action']) ? 'action' : (isset($lCols['details']) ? 'details' : 'id'));
            $ts = isset($lCols['created_at']) ? 'created_at' : (isset($lCols['timestamp']) ? 'timestamp' : 'id');
            $recentLogs = $db->query("SELECT $lvl AS level, $msg AS message, $ts AS created_at FROM system_logs ORDER BY $ts DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) { /* optional */ }

    // Extra insights (safe table checks)
    $insights = [
        'pending_approvals' => 0,
        'unassigned_inquiries' => 0,
        'pending_reviews' => 0,
        'overdue_tasks' => 0,
        'new_logs_24h' => 0,
    ];
    // Pending approvals
    $insights['pending_approvals'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetchColumn();
    // Unassigned public inquiries
    $tbl = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='public_inquiries'")->fetchColumn();
    if ((int)$tbl > 0) {
        $insights['unassigned_inquiries'] = (int)$db->query("SELECT COUNT(*) FROM public_inquiries WHERE (assigned_to IS NULL OR assigned_to=0) AND (status='new' OR status IS NULL)")->fetchColumn();
    }
    // Pending reviews
    $tbl = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='design_reviews'")->fetchColumn();
    if ((int)$tbl > 0) {
        $insights['pending_reviews'] = (int)$db->query("SELECT COUNT(*) FROM design_reviews WHERE status='pending'")->fetchColumn();
    }
    // Overdue tasks
    $tbl = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='tasks'")->fetchColumn();
    if ((int)$tbl > 0) {
        $insights['overdue_tasks'] = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status NOT IN ('Done','Completed') AND due_date IS NOT NULL AND due_date < CURDATE()")->fetchColumn();
    }
    // New logs 24h
    $tbl = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='system_logs'")->fetchColumn();
    if ((int)$tbl > 0) {
        $insights['new_logs_24h'] = (int)$db->query("SELECT COUNT(*) FROM system_logs WHERE created_at >= (NOW() - INTERVAL 1 DAY)")->fetchColumn();
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}
// Build lightweight debug info when requested
if ($debug) {
    $debugInfo['php_version'] = PHP_VERSION;
    $debugInfo['server_time'] = date('c');
    $debugInfo['user_id'] = (int)($_SESSION['user_id'] ?? 0);
    $debugInfo['user_type'] = (string)($_SESSION['user_type'] ?? '');
    $debugInfo['recent_counts'] = [
        'public' => is_array($recentPublic) ? count($recentPublic) : 0,
        'client' => is_array($recentClient) ? count($recentClient) : 0,
        'project_breakdown' => is_array($projectBreakdown) ? count($projectBreakdown) : 0,
        'recent_users' => is_array($recentUsers) ? count($recentUsers) : 0,
        'recent_logs' => is_array($recentLogs) ? count($recentLogs) : 0,
    ];
    $debugInfo['stats'] = $stats;
    try {
        if ($db) {
            $check = function($table) use ($db) {
                try {
                    $q = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
                    $q->execute([$table]);
                    return (int)$q->fetchColumn() > 0;
                } catch (Throwable $e) { return false; }
            };
            $debugInfo['tables'] = [
                'users' => $check('users'),
                'employees' => $check('employees'),
                'projects' => $check('projects'),
                'public_inquiries' => $check('public_inquiries'),
                'client_inquiries' => $check('client_inquiries'),
                'system_logs' => $check('system_logs'),
                'notifications' => $check('notifications'),
            ];
        }
    } catch (Throwable $e) { /* ignore debug */ }
}
?>

<?php
// Safe string truncation helper (avoids dependency on mbstring)
if (!function_exists('af_str_limit')) {
    function af_str_limit($value, $limit = 80) {
        $value = (string)$value;
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($value, 0, $limit, '…', 'UTF-8');
        }
        if (strlen($value) <= $limit) { return $value; }
        return substr($value, 0, max(0, $limit - 1)) . '…';
    }
}
?>

<?php include '../backend/core/header.php'; ?>

<main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-50 p-6">
    <div class="w-full max-w-full">
        <!-- Header -->
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold text-slate-900">Admin Dashboard</h1>
                <p class="text-slate-600 mt-2">Welcome back, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></p>
            </div>
            <div class="flex items-center gap-2">
                <a href="../manage_inquiries.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 transition">
                    <i class="fas fa-inbox"></i>
                    <span>Manage Inquiries</span>
                </a>
            </div>
        </div>

        <?php if ($debug): ?>
            <div class="mb-6 p-4 rounded-lg ring-1 ring-amber-200 bg-amber-50 text-amber-900 text-sm">
                <div class="font-semibold mb-2">Debug panel (visible because ?debug=1)</div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <div>PHP: <span class="font-mono"><?php echo htmlspecialchars((string)($debugInfo['php_version'] ?? '')); ?></span></div>
                        <div>Time: <span class="font-mono"><?php echo htmlspecialchars((string)($debugInfo['server_time'] ?? '')); ?></span></div>
                        <div>User: ID <span class="font-mono"><?php echo (int)($debugInfo['user_id'] ?? 0); ?></span>, type <span class="font-mono"><?php echo htmlspecialchars((string)($debugInfo['user_type'] ?? '')); ?></span></div>
                    </div>
                    <div>
                        <div class="font-semibold mb-1">Section counts</div>
                        <ul class="list-disc ml-5">
                            <li>Public inquiries: <?php echo (int)($debugInfo['recent_counts']['public'] ?? 0); ?></li>
                            <li>Client inquiries: <?php echo (int)($debugInfo['recent_counts']['client'] ?? 0); ?></li>
                            <li>Project breakdown: <?php echo (int)($debugInfo['recent_counts']['project_breakdown'] ?? 0); ?></li>
                            <li>Recent users: <?php echo (int)($debugInfo['recent_counts']['recent_users'] ?? 0); ?></li>
                            <li>Recent logs: <?php echo (int)($debugInfo['recent_counts']['recent_logs'] ?? 0); ?></li>
                        </ul>
                    </div>
                    <div>
                        <div class="font-semibold mb-1">Tables detected</div>
                        <?php if (!empty($debugInfo['tables'])): ?>
                            <ul class="list-disc ml-5">
                                <?php foreach ($debugInfo['tables'] as $tn => $ok): ?>
                                    <li><?php echo htmlspecialchars($tn); ?>: <span class="<?php echo $ok ? 'text-emerald-700' : 'text-rose-700'; ?>"><?php echo $ok ? 'yes' : 'no'; ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="text-slate-700">No table info</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($dbError)): ?>
            <div class="mb-6 p-4 rounded-lg ring-1 ring-red-200 bg-red-50 text-red-800">
                <strong>Database error:</strong> <?php echo htmlspecialchars($dbError); ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">Total Users</p><p class="text-3xl font-bold"><?php echo number_format((int)$stats['total_users']); ?></p></div><span class="p-3 rounded-xl bg-blue-50 text-blue-600"><i class="fas fa-users"></i></span></div></div>
            <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">Employees</p><p class="text-3xl font-bold"><?php echo number_format((int)$stats['employees']); ?></p></div><span class="p-3 rounded-xl bg-indigo-50 text-indigo-600"><i class="fas fa-id-badge"></i></span></div></div>
            <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">Active Projects</p><p class="text-3xl font-bold"><?php echo number_format((int)$stats['active_projects']); ?></p></div><span class="p-3 rounded-xl bg-green-50 text-green-600"><i class="fas fa-diagram-project"></i></span></div></div>
            <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">New Inquiries</p><p class="text-3xl font-bold"><?php echo number_format((int)$stats['new_public_inquiries']); ?></p></div><span class="p-3 rounded-xl bg-emerald-50 text-emerald-600"><i class="fas fa-envelope-open-text"></i></span></div></div>
            <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">Notifications</p><p class="text-3xl font-bold"><?php echo number_format((int)$stats['unread_notifications']); ?></p></div><span class="p-3 rounded-xl bg-rose-50 text-rose-600"><i class="fas fa-bell"></i></span></div></div>
        </div>

        <!-- Feature highlights -->
        <section class="mb-8">
            <h2 class="text-xl font-semibold text-slate-900 mb-3">Platform features</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <a href="Projects/projects-index.php" class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm hover:shadow transition">
                    <div class="flex items-start gap-3">
                        <span class="p-3 rounded-xl bg-indigo-50 text-indigo-600"><i class="fas fa-diagram-project"></i></span>
                        <div>
                            <div class="font-semibold text-slate-900">Projects & Phases</div>
                            <div class="text-sm text-slate-600">Create, track, and progress projects through phases.</div>
                        </div>
                    </div>
                </a>
                <a href="Projects/assign-senior-architects.php" class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm hover:shadow transition">
                    <div class="flex items-start gap-3">
                        <span class="p-3 rounded-xl bg-amber-50 text-amber-600"><i class="fas fa-user-tie"></i></span>
                        <div>
                            <div class="font-semibold text-slate-900">Assign Senior Architects</div>
                            <div class="text-sm text-slate-600">Map projects to responsible senior architects.</div>
                        </div>
                    </div>
                </a>
                <a href="user-management/user-index.php" class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm hover:shadow transition">
                    <div class="flex items-start gap-3">
                        <span class="p-3 rounded-xl bg-blue-50 text-blue-600"><i class="fas fa-users-cog"></i></span>
                        <div>
                            <div class="font-semibold text-slate-900">User Management</div>
                            <div class="text-sm text-slate-600">Roles, activation, and access control.</div>
                        </div>
                    </div>
                </a>
                <a href="../manage_inquiries.php" class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm hover:shadow transition">
                    <div class="flex items-start gap-3">
                        <span class="p-3 rounded-xl bg-emerald-50 text-emerald-600"><i class="fas fa-inbox"></i></span>
                        <div>
                            <div class="font-semibold text-slate-900">Inquiries</div>
                            <div class="text-sm text-slate-600">Triage public and client inquiries.</div>
                        </div>
                    </div>
                </a>
                <a href="Invoices/invoices-index.php" class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm hover:shadow transition">
                    <div class="flex items-start gap-3">
                        <span class="p-3 rounded-xl bg-purple-50 text-purple-600"><i class="fas fa-file-invoice-dollar"></i></span>
                        <div>
                            <div class="font-semibold text-slate-900">Billing & Invoices</div>
                            <div class="text-sm text-slate-600">Track invoices and payments.</div>
                        </div>
                    </div>
                </a>
                <a href="settings/setting-index.php" class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm hover:shadow transition">
                    <div class="flex items-start gap-3">
                        <span class="p-3 rounded-xl bg-slate-100 text-slate-700"><i class="fas fa-cog"></i></span>
                        <div>
                            <div class="font-semibold text-slate-900">System Settings</div>
                            <div class="text-sm text-slate-600">Configure organization and platform defaults.</div>
                        </div>
                    </div>
                </a>
            </div>
        </section>

        <!-- Insights Row -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">Pending Approvals</p><p class="text-3xl font-bold" data-counter="<?php echo (int)$insights['pending_approvals']; ?>">0</p></div><span class="p-3 rounded-xl bg-amber-50 text-amber-600"><i class="fas fa-user-clock"></i></span></div></div>
            <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">Unassigned Inquiries</p><p class="text-3xl font-bold" data-counter="<?php echo (int)$insights['unassigned_inquiries']; ?>">0</p></div><span class="p-3 rounded-xl bg-emerald-50 text-emerald-600"><i class="fas fa-inbox"></i></span></div></div>
            <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">Pending Reviews</p><p class="text-3xl font-bold" data-counter="<?php echo (int)$insights['pending_reviews']; ?>">0</p></div><span class="p-3 rounded-xl bg-indigo-50 text-indigo-600"><i class="fas fa-clipboard-check"></i></span></div></div>
            <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">Overdue Tasks</p><p class="text-3xl font-bold" data-counter="<?php echo (int)$insights['overdue_tasks']; ?>">0</p></div><span class="p-3 rounded-xl bg-rose-50 text-rose-600"><i class="fas fa-hourglass-end"></i></span></div></div>
            <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">New Logs (24h)</p><p class="text-3xl font-bold" data-counter="<?php echo (int)$insights['new_logs_24h']; ?>">0</p></div><span class="p-3 rounded-xl bg-sky-50 text-sky-600"><i class="fas fa-bell"></i></span></div></div>
        </div>

        <!-- System Status -->
        <div class="mb-8">
            <h2 class="text-xl font-semibold text-slate-900 mb-3">System Status</h2>
            <div class="rounded-xl ring-1 ring-green-200 bg-green-50 p-4">
                <div class="flex items-center gap-2 text-green-800"><i class="fas fa-check-circle"></i><span>Authentication and database are reachable.</span></div>
            </div>
        </div>

        <!-- Breakdown + Activity + New Users -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <!-- Project Status Breakdown -->
            <section class="rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-slate-900 mb-4">Project status breakdown</h3>
                <?php if (!$projectBreakdown): ?>
                    <div class="text-slate-500 text-sm">No project status data available.</div>
                <?php else: ?>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($projectBreakdown as $pb): $s = $pb['s'] ?? 'unknown'; $c=(int)($pb['c']??0); ?>
                            <span class="px-3 py-1 rounded-full text-xs bg-slate-100 text-slate-700 border border-slate-200 capitalize"><?php echo htmlspecialchars(str_replace('_',' ',$s)); ?>: <span class="font-semibold"><?php echo number_format($c); ?></span></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Recent Activity -->
            <section class="rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-slate-900 mb-4">Recent activity</h3>
                <?php if (!$recentLogs): ?>
                    <div class="text-slate-500 text-sm">No recent activity.</div>
                <?php else: ?>
                    <ul class="space-y-3 text-sm">
                        <?php foreach ($recentLogs as $lg): ?>
                            <li class="flex items-start gap-3">
                                <span class="mt-0.5 inline-flex items-center justify-center w-6 h-6 rounded-full bg-slate-100 text-slate-600"><i class="fas fa-stream text-[11px]"></i></span>
                                <div class="flex-1">
                                    <div class="text-slate-800"><?php echo htmlspecialchars(af_str_limit((string)($lg['message'] ?? ''), 80)); ?></div>
                                    <div class="text-[11px] text-slate-500">
                                        <?php if (!empty($lg['level'])): ?><span class="uppercase tracking-wide mr-2"><?php echo htmlspecialchars((string)$lg['level']); ?></span><?php endif; ?>
                                        <span><?php echo htmlspecialchars(date('M j, H:i', strtotime((string)($lg['created_at'] ?? 'now')))); ?></span>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <!-- New Users -->
            <section class="rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-slate-900 mb-4">New users</h3>
                <?php if (!$recentUsers): ?>
                    <div class="text-slate-500 text-sm">No recent users.</div>
                <?php else: ?>
                    <ul class="divide-y divide-slate-100 text-sm">
                        <?php foreach ($recentUsers as $u): $nm = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')); ?>
                            <li class="py-3 flex items-center justify-between">
                                <div class="min-w-0">
                                    <div class="font-medium text-slate-900 truncate max-w-[24ch]" title="<?php echo htmlspecialchars($nm ?: 'User #'.(int)($u['user_id'] ?? 0)); ?>"><?php echo htmlspecialchars($nm ?: ('User #'.(int)($u['user_id'] ?? 0))); ?></div>
                                    <div class="text-[11px] text-slate-500 truncate max-w-[28ch]">
                                        <?php if (!empty($u['role'])): ?><span class="capitalize"><?php echo htmlspecialchars(str_replace('_',' ', (string)$u['role'])); ?></span><?php endif; ?>
                                        <?php if (!empty($u['email'])): ?><span class="ml-2">• <?php echo htmlspecialchars((string)$u['email']); ?></span><?php endif; ?>
                                    </div>
                                </div>
                                <div class="text-[11px] text-slate-500 ml-3 whitespace-nowrap"><?php echo !empty($u['created_at']) ? htmlspecialchars(date('M j', strtotime((string)$u['created_at']))) : ''; ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>

        <!-- Bottom grid: inquiries + quick actions -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <section class="lg:col-span-2 rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-slate-900">Inquiries at a glance</h3>
                    <a href="../manage_inquiries.php" class="text-sm text-slate-700 hover:text-slate-900 underline">Open manage page</a>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Public inquiries -->
                    <div>
                        <h4 class="text-sm font-medium text-slate-700 mb-2">Latest Public Inquiries</h4>
                        <div class="rounded-xl ring-1 ring-slate-200 bg-slate-50">
                            <table class="min-w-full text-sm">
                                <thead class="text-slate-500">
                                    <tr><th class="py-2 px-3 text-left">Name</th><th class="py-2 px-3 text-left">Type</th><th class="py-2 px-3 text-right">Created</th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 bg-white">
                                <?php if (!$recentPublic): ?>
                                    <tr><td colspan="3" class="py-3 px-3 text-slate-500">No records.</td></tr>
                                <?php else: foreach ($recentPublic as $r): ?>
                                    <tr>
                                        <td class="py-2 px-3">
                                            <div class="font-medium text-slate-900"><?php echo htmlspecialchars($r['name'] ?: 'Anonymous'); ?></div>
                                            <div class="text-xs text-slate-500 break-words max-w-[28ch]"><?php echo htmlspecialchars($r['email']); ?></div>
                                        </td>
                                        <td class="py-2 px-3 capitalize text-slate-700"><?php echo htmlspecialchars(str_replace('_',' ',$r['inquiry_type'] ?: '')); ?></td>
                                        <td class="py-2 px-3 text-right text-slate-600"><?php echo htmlspecialchars(date('M j', strtotime($r['created_at']))); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Client inquiries -->
                    <div>
                        <h4 class="text-sm font-medium text-slate-700 mb-2">Latest Client Inquiries</h4>
                        <div class="rounded-xl ring-1 ring-slate-200 bg-slate-50">
                            <table class="min-w-full text-sm">
                                <thead class="text-slate-500">
                                    <tr><th class="py-2 px-3 text-left">Subject</th><th class="py-2 px-3 text-left">Category</th><th class="py-2 px-3 text-right">Created</th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 bg-white">
                                <?php if (!$recentClient): ?>
                                    <tr><td colspan="3" class="py-3 px-3 text-slate-500">No records.</td></tr>
                                <?php else: foreach ($recentClient as $r): ?>
                                    <tr>
                                        <td class="py-2 px-3"><div class="font-medium text-slate-900 break-words max-w-[34ch]"><?php echo htmlspecialchars($r['subject'] ?: '(No subject)'); ?></div></td>
                                        <td class="py-2 px-3 capitalize text-slate-700"><?php echo htmlspecialchars(str_replace('_',' ',$r['category'] ?: '')); ?></td>
                                        <td class="py-2 px-3 text-right text-slate-600"><?php echo htmlspecialchars(date('M j', strtotime($r['created_at']))); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Quick Actions -->
            <section class="rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-slate-900 mb-4">Quick Actions</h3>
                <div class="grid grid-cols-1 gap-3">
                    <a href="user-management/user-index.php" class="rounded-lg ring-1 ring-slate-200 bg-blue-50 hover:bg-blue-100 p-4 transition">
                        <div class="flex items-center"><i class="fas fa-users text-blue-600 text-xl mr-3"></i><div><div class="font-semibold text-slate-900">User Management</div><div class="text-xs text-slate-600">Manage system users</div></div></div>
                    </a>
                    <a href="Projects/projects-index.php" class="rounded-lg ring-1 ring-slate-200 bg-green-50 hover:bg-green-100 p-4 transition">
                        <div class="flex items-center"><i class="fas fa-diagram-project text-green-600 text-xl mr-3"></i><div><div class="font-semibold text-slate-900">Projects</div><div class="text-xs text-slate-600">Manage projects</div></div></div>
                    </a>
                    <a href="Invoices/invoices-index.php" class="rounded-lg ring-1 ring-slate-200 bg-purple-50 hover:bg-purple-100 p-4 transition">
                        <div class="flex items-center"><i class="fas fa-receipt text-purple-600 text-xl mr-3"></i><div><div class="font-semibold text-slate-900">Invoices</div><div class="text-xs text-slate-600">Manage billing</div></div></div>
                    </a>
                    <a href="Design-Service/design-services-index.php" class="rounded-lg ring-1 ring-slate-200 bg-orange-50 hover:bg-orange-100 p-4 transition">
                        <div class="flex items-center"><i class="fas fa-palette text-orange-600 text-xl mr-3"></i><div><div class="font-semibold text-slate-900">Design Services</div><div class="text-xs text-slate-600">Configure services</div></div></div>
                    </a>
                </div>
            </section>
        </div>

                <!-- Full Users (Admin View) -->
                <section class="mt-8 rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold text-slate-900">Current Users</h3>
                                <?php if ($allUsers): ?>
                                    <span class="text-xs px-2 py-1 rounded-full bg-slate-100 text-slate-600 border border-slate-200">Showing <?php echo count($allUsers); ?></span>
                                <?php endif; ?>
                        </div>
                        <?php if (!$allUsers): ?>
                            <div class="text-slate-500 text-sm">No users found.</div>
                        <?php else: ?>
                            <div class="overflow-auto max-h-[480px]">
                                <table class="min-w-full text-sm">
                                    <thead class="sticky top-0 bg-slate-50 text-slate-600 text-xs uppercase tracking-wide">
                                        <tr>
                                            <th class="text-left px-3 py-2">User</th>
                                            <th class="text-left px-3 py-2">Role</th>
                                            <th class="text-left px-3 py-2">Type</th>
                                            <th class="text-left px-3 py-2">Email</th>
                                            <th class="text-right px-3 py-2">Created</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-200 bg-white">
                                        <?php foreach ($allUsers as $u): $nm = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')); ?>
                                            <tr class="hover:bg-slate-50">
                                                <td class="px-3 py-2">
                                                    <div class="font-medium text-slate-900 truncate max-w-[20ch]" title="<?php echo htmlspecialchars($nm ?: 'User #'.(int)$u['user_id']); ?>"><?php echo htmlspecialchars($nm ?: ('User #'.(int)$u['user_id'])); ?></div>
                                                    <div class="text-[11px] text-slate-500 font-mono">#<?php echo (int)$u['user_id']; ?></div>
                                                </td>
                                                <td class="px-3 py-2 text-slate-700 capitalize truncate max-w-[16ch]"><?php echo htmlspecialchars(str_replace('_',' ', (string)($u['role'] ?? ''))); ?></td>
                                                <td class="px-3 py-2 text-slate-600 capitalize text-xs"><?php echo htmlspecialchars(str_replace('_',' ', (string)($u['user_type'] ?? ''))); ?></td>
                                                <td class="px-3 py-2 text-slate-600 truncate max-w-[26ch]" title="<?php echo htmlspecialchars((string)($u['email'] ?? '')); ?>"><?php echo htmlspecialchars((string)($u['email'] ?? '')); ?></td>
                                                <td class="px-3 py-2 text-right text-slate-600 text-xs"><?php echo !empty($u['created_at']) ? htmlspecialchars(date('Y-m-d', strtotime((string)$u['created_at']))) : ''; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="mt-2 text-[11px] text-slate-500">Limited to latest 100 users. For more, visit <a href="user-management/user-index.php" class="underline hover:text-slate-700">User Management</a>.</p>
                        <?php endif; ?>
                </section>
    </div>
</main>

<?php include '../backend/core/footer.php'; ?>

<script>
// Animate insight counters
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-counter]').forEach(el => {
        const target = parseInt(el.getAttribute('data-counter') || '0', 10);
        const duration = 900; const start = performance.now();
        const step = t => { const p = Math.min(1, (t - start) / duration); el.textContent = Math.floor(target * p).toLocaleString(); if (p < 1) requestAnimationFrame(step); };
        requestAnimationFrame(step);
    });
});
</script>

<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// DEBUG: Log session info
error_log('PM DASHBOARD DEBUG: Session data: ' . print_r($_SESSION, true));

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { 
    error_log('PM DASHBOARD DEBUG: Not logged in, redirecting to login');
    header('Location: ../../login.php'); 
    exit; 
}

$user_type = $_SESSION['user_type'] ?? '';
$position = strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? ''))));

error_log('PM DASHBOARD DEBUG: user_type=' . $user_type . ', position=' . $position . ' (original: ' . ($_SESSION['position'] ?? '') . ')');

if ($user_type !== 'employee' || $position !== 'project_manager') { 
    error_log('PM DASHBOARD DEBUG: Auth check failed - user_type=' . $user_type . ', position=' . $position . ', redirecting to index');
    header('Location: ../../index.php'); 
    exit; 
}

error_log('PM DASHBOARD DEBUG: Auth check passed, proceeding with dashboard');

require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Ensure projects.phase exists (best-effort)
try {
	$chk = $db->query("SHOW COLUMNS FROM projects LIKE 'phase'");
	if ($chk->rowCount() === 0) {
		$db->exec("ALTER TABLE projects ADD COLUMN phase VARCHAR(64) DEFAULT 'Pre-Design / Programming'");
	}
} catch (Throwable $e) { /* ignore */ }

$userId = (int)($_SESSION['user_id'] ?? 0);
$empStmt = $db->prepare('SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1');
$empStmt->execute([$userId]);
$empRow = $empStmt->fetch(PDO::FETCH_ASSOC);
$employeeId = $empRow ? (int)$empRow['employee_id'] : 0;

$err='';
$kpi = ['projects'=>0,'active'=>0,'completed'=>0,'tasks_pending'=>0,'milestones_upcoming'=>0];

// Dynamically detect how projects are assigned to this PM and build a robust WHERE clause
$assignParts = [];
$assignParams = [];
$debugInfo = ['assign_cols'=>[], 'assign_parts'=>[], 'used_user_id'=>(int)$userId, 'used_employee_id'=>(int)$employeeId];
try {
	// Detect projects columns
	$projCols = [];
	foreach ($db->query('SHOW COLUMNS FROM projects') as $pc) { $projCols[$pc['Field']] = true; }
	$debugInfo['assign_cols'] = array_keys($projCols);

	// Employee-ID based columns
	$empCols = [
		'project_manager_id','pm_id','project_manager_employee_id','manager_employee_id',
		'assigned_pm_id','assigned_manager_id','assigned_to_pm','assigned_to_manager'
	];
	// User-ID based columns
	$userCols = [
		'project_manager_user_id','manager_user_id','assigned_pm_user_id','assigned_manager_user_id','assigned_user_id'
	];

	foreach ($empCols as $col) {
		if (isset($projCols[$col])) { $assignParts[] = 'p.`'.$col.'` = ?'; $assignParams[] = $employeeId; }
	}
	foreach ($userCols as $col) {
		if (isset($projCols[$col])) { $assignParts[] = 'p.`'.$col.'` = ?'; $assignParams[] = $userId; }
	}

	// Fallback: membership table project_users(user_id, project_id [, role])
	$hasProjectUsers = false; $puCols = [];
	try {
		$tbl = $db->query("SHOW TABLES LIKE 'project_users'");
		$hasProjectUsers = (bool)($tbl && $tbl->rowCount());
		if ($hasProjectUsers) {
			foreach ($db->query('SHOW COLUMNS FROM project_users') as $cc) { $puCols[$cc['Field']] = true; }
			$roleCol = isset($puCols['role']) ? 'role' : (isset($puCols['user_role']) ? 'user_role' : null);
			$roleCheck = $roleCol ? " AND LOWER(TRIM(pu.`$roleCol`)) IN ('project_manager','pm','manager')" : '';
			$assignParts[] = 'EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id = p.project_id AND pu.user_id = ?' . $roleCheck . ')';
			$assignParams[] = $userId;
		}
	} catch (Throwable $e) { /* ignore */ }

	$pmWhere = '1=0';
	if (!empty($assignParts)) { $pmWhere = '(' . implode(' OR ', $assignParts) . ')'; }
	$debugInfo['assign_parts'] = $assignParts;

	// Project counts
	$sql1 = "SELECT 
			COUNT(*) AS projects,
			SUM(CASE WHEN p.status IN ('planning','design','construction') THEN 1 ELSE 0 END) AS active,
			SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) AS completed
		FROM projects p WHERE $pmWhere";
	$q1 = $db->prepare($sql1);
	$q1->execute($assignParams);
	$kpi = array_merge($kpi, (array)$q1->fetch(PDO::FETCH_ASSOC));

	// Tasks pending under PM projects
	$sql2 = "SELECT COUNT(*) FROM tasks t JOIN projects p ON p.project_id=t.project_id WHERE $pmWhere AND (t.status IS NULL OR t.status <> 'completed')";
	$q2 = $db->prepare($sql2);
	$q2->execute($assignParams);
	$kpi['tasks_pending'] = (int)$q2->fetchColumn();

	// Upcoming milestones
	$sql3 = "SELECT COUNT(*) FROM milestones m JOIN projects p ON p.project_id=m.project_id WHERE $pmWhere AND m.completion_date IS NULL AND (m.target_date IS NULL OR m.target_date >= CURDATE())";
	$q3 = $db->prepare($sql3);
	$q3->execute($assignParams);
	$kpi['milestones_upcoming'] = (int)$q3->fetchColumn();

	// Recent projects
	$sqlRP = "SELECT p.project_id, p.project_name, p.project_code, p.status, p.phase, p.created_at FROM projects p WHERE $pmWhere ORDER BY p.created_at DESC LIMIT 8";
	$rp = $db->prepare($sqlRP);
	$rp->execute($assignParams);
	$rows = $rp->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) { $err = $ex->getMessage(); $rows = []; }

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
	<div class="max-w-full">
		<h1 class="text-2xl font-bold mb-6">Project Manager Dashboard</h1>
		<?php if ($employeeId === 0): ?>
			<div class="mb-4 p-3 bg-amber-50 text-amber-800 ring-1 ring-amber-200 rounded text-sm">
				Your user account isn’t linked to an Employee profile. Some project assignments reference employees. Please contact HR to link your user to an Employee.
			</div>
		<?php elseif (empty($assignParts ?? [])): ?>
			<div class="mb-4 p-3 bg-amber-50 text-amber-800 ring-1 ring-amber-200 rounded text-sm">
				Heads up: I couldn’t detect any project manager assignment fields on the projects table (checked common variants like project_manager_id, pm_id, project_manager_user_id, etc.), and also checked project_users membership. Projects may not be mapped to you yet. Ask Admin to record you as the PM on relevant projects.
			</div>
		<?php endif; ?>
		<?php if ($err): ?><div class="mb-4 p-3 bg-red-50 text-red-700 ring-1 ring-red-200 rounded"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>

		<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
			<div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm">
				<div class="text-sm text-gray-500">Total Projects</div>
				<div class="text-2xl font-semibold text-gray-900"><?php echo (int)$kpi['projects']; ?></div>
			</div>
			<div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm">
				<div class="text-sm text-gray-500">Active</div>
				<div class="text-2xl font-semibold text-gray-900"><?php echo (int)$kpi['active']; ?></div>
			</div>
			<div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm">
				<div class="text-sm text-gray-500">Completed</div>
				<div class="text-2xl font-semibold text-gray-900"><?php echo (int)$kpi['completed']; ?></div>
			</div>
			<div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm">
				<div class="text-sm text-gray-500">Open Tasks</div>
				<div class="text-2xl font-semibold text-gray-900"><?php echo (int)$kpi['tasks_pending']; ?></div>
			</div>
			<div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm">
				<div class="text-sm text-gray-500">Upcoming Milestones</div>
				<div class="text-2xl font-semibold text-gray-900"><?php echo (int)$kpi['milestones_upcoming']; ?></div>
			</div>
		</div>

		<div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200">
			<div class="p-4 border-b border-gray-100">
				<div class="font-semibold">Recent Projects</div>
			</div>
			<ul class="divide-y divide-gray-100">
				<?php if (!$rows): ?>
					<li class="p-4 text-gray-500">No projects yet.</li>
				<?php else: foreach ($rows as $r): ?>
					<li class="p-4 flex items-center justify-between">
						<div>
							<div class="font-medium text-gray-900"><?php echo htmlspecialchars($r['project_name']); ?></div>
							<div class="text-xs text-gray-500 flex gap-2 items-center">
								<span><?php echo htmlspecialchars($r['project_code']); ?></span>
								<?php if (!empty($r['phase'])): ?>
									<span class="px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-100 text-[10px] tracking-wide">
										<?php echo htmlspecialchars($r['phase']); ?>
									</span>
								<?php endif; ?>
							</div>
						</div>
						<div class="flex flex-col items-end text-sm text-gray-700 gap-1">
							<span class="px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-700"><?php echo htmlspecialchars($r['status']); ?></span>
							<a href="/ArchiFlow/project_details.php?project_id=<?php echo (int)$r['project_id']; ?>" class="text-xs text-blue-600 hover:underline">View</a>
						</div>
					</li>
				<?php endforeach; endif; ?>
			</ul>
		</div>
	</div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

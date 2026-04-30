<?php
// Session and role/position guard before any output
if (session_status() === PHP_SESSION_NONE) {
		session_start();
}
// Compute app base (supports /ArchiFlow subfolder)
$APP_BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($APP_BASE === '/' || $APP_BASE === '.') { $APP_BASE = ''; }

// Compute root base for redirects (go up 2 levels from employees/architects/)
$ROOT_BASE = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($ROOT_BASE === '/' || $ROOT_BASE === '.') { $ROOT_BASE = ''; }

// Require employee architect
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
		header('Location: ' . $ROOT_BASE . '/login.php');
		exit;
}
if (($_SESSION['user_type'] ?? '') !== 'employee' || strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? '')))) !== 'architect') {
		// Send non-architects to their dashboards
		$userType = $_SESSION['user_type'] ?? '';
		switch ($userType) {
				case 'admin':
						header('Location: ' . $ROOT_BASE . '/admin/dashboard.php');
						break;
				case 'client':
						header('Location: ' . $ROOT_BASE . '/client/dashboard.php');
						break;
				case 'hr':
						header('Location: ' . $ROOT_BASE . '/hr/hr-dashboard.php');
						break;
				case 'employee':
						$position = strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? ''))));
						if ($position === 'senior_architect') {
							header('Location: ' . $ROOT_BASE . '/employees/senior_architects/senior_architects-dashboard.php');
						} elseif ($position === 'project_manager') {
							header('Location: ' . $ROOT_BASE . '/employees/project_manager/project_manager-dashboard.php');
						} else {
							// For unknown positions or architects, redirect to employees index
							header('Location: ' . $ROOT_BASE . '/employees/index.php');
						}
						break;
				default:
						header('Location: ' . $ROOT_BASE . '/index.php');
		}
		exit;
}

require_once __DIR__ . '/../../backend/connection/connect.php';

$db = null;
$employeeId = null;
$errorMsg = '';

// Data buckets
$stats = [
		'active_projects' => 0,
		'completed_projects' => 0,
		'pending_tasks' => 0,
		'upcoming_milestones' => 0,
		'unread_notifications' => 0,
		'attendance_today' => null,
];

$myProjects = [];
$myTasks = [];
$myMilestones = [];

try {
		$db = getDB();
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// Determine current employee_id
		$stmtEmp = $db->prepare('SELECT employee_id, status FROM employees WHERE user_id = ? LIMIT 1');
		$stmtEmp->execute([$_SESSION['user_id']]);
		$employee = $stmtEmp->fetch(PDO::FETCH_ASSOC);
		if (!$employee) {
			// Auto-provision an employee record for this architect
			$empCode = 'EMP-' . (int)$_SESSION['user_id'];
			$insert = $db->prepare("INSERT INTO employees (user_id, employee_code, position, department, hire_date, salary, status) VALUES (?, ?, 'architect', 'Architecture', CURDATE(), 0.00, 'active')");
			$insert->execute([$_SESSION['user_id'], $empCode]);
			// Re-fetch
			$stmtEmp = $db->prepare('SELECT employee_id, status FROM employees WHERE user_id = ? LIMIT 1');
			$stmtEmp->execute([$_SESSION['user_id']]);
			$employee = $stmtEmp->fetch(PDO::FETCH_ASSOC);
			if (!$employee) {
				throw new Exception('Employee record not found for current user');
			}
		}
		$employeeId = (int)$employee['employee_id'];

		// Stats queries
		// Active projects (pending, planning, design, construction)
		// Count projects where architect is assigned via architect_id or project_users.user_id
		$stmt = $db->prepare("SELECT COUNT(DISTINCT p.project_id)
			FROM projects p
			LEFT JOIN project_users pu ON pu.project_id = p.project_id
			WHERE (p.architect_id = ? OR pu.user_id = ?)
			AND p.status IN ('pending','planning','design','construction')
			AND (p.is_deleted=0 OR p.is_deleted IS NULL)
			AND (p.is_archived=0 OR p.is_archived IS NULL)");
		$stmt->execute([$employeeId, $_SESSION['user_id']]);
		$stats['active_projects'] = (int)$stmt->fetchColumn();

		// Completed projects
		$stmt = $db->prepare("SELECT COUNT(DISTINCT p.project_id) FROM projects p LEFT JOIN project_users pu ON pu.project_id = p.project_id AND pu.user_id = ? WHERE (p.architect_id = ? OR pu.user_id = ?) AND p.status = 'completed' AND (p.is_deleted=0 OR p.is_deleted IS NULL) AND (p.is_archived=0 OR p.is_archived IS NULL)");
		$stmt->execute([$_SESSION['user_id'], $employeeId, $_SESSION['user_id']]);
		$stats['completed_projects'] = (int)$stmt->fetchColumn();

	// Pending tasks (pending or in_progress)
	$stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE (assigned_to = ? OR assigned_to = ?) AND (status IN ('pending','in_progress') OR status IS NULL OR status = '')");
	$stmt->execute([$employeeId, $_SESSION['user_id']]);
	$stats['pending_tasks'] = (int)$stmt->fetchColumn();

		// Upcoming milestones (not completed, due >= today)
		$stmt = $db->prepare("SELECT COUNT(*) FROM milestones m JOIN projects p ON p.project_id = m.project_id WHERE p.architect_id = ? AND m.completion_date IS NULL AND (m.target_date IS NOT NULL AND m.target_date >= CURDATE())");
		$stmt->execute([$employeeId]);
		$stats['upcoming_milestones'] = (int)$stmt->fetchColumn();

		// Unread notifications (by user_id)
		$stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
		$stmt->execute([$_SESSION['user_id']]);
		$stats['unread_notifications'] = (int)$stmt->fetchColumn();

		// Attendance today
		$stmt = $db->prepare('SELECT work_date, time_in, time_out, status, hours_worked, overtime_hours FROM attendance WHERE employee_id = ? AND work_date = CURDATE() LIMIT 1');
		$stmt->execute([$employeeId]);
		$stats['attendance_today'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

	// Recent Projects (top 6)
	// Build recent projects query with conditional filters only if columns exist
	$projCols = [];
	try { foreach($db->query('SHOW COLUMNS FROM projects') as $c){ $projCols[$c['Field']] = true; } } catch(Throwable $e){}
	$filterParts = [];
	$filterParts[] = '(p.architect_id = ? OR pu.user_id = ?)';
	if (isset($projCols['is_deleted'])) { $filterParts[] = '(p.is_deleted=0 OR p.is_deleted IS NULL)'; }
	if (isset($projCols['is_archived'])) { $filterParts[] = '(p.is_archived=0 OR p.is_archived IS NULL)'; }
	$whereSql = implode(' AND ', $filterParts);
	$sqlRecent = "SELECT DISTINCT p.project_id, p.project_code, p.project_name, p.client_id, p.architect_id, p.project_manager_id, p.project_type, p.status, p.description, p.location, p.start_date, p.end_date, p.budget, p.project_image, p.created_at, p.created_by, p.size_sq_m, p.location_text, p.estimated_end_date, p.budget_amount, c.company_name, c.client_type
		     FROM projects p
		     LEFT JOIN clients c ON p.client_id = c.client_id
		     LEFT JOIN project_users pu ON pu.project_id = p.project_id
		     WHERE $whereSql
		     ORDER BY p.created_at DESC
		     LIMIT 6";
	$stmt = $db->prepare($sqlRecent);
	$stmt->execute([$employeeId, $_SESSION['user_id']]);
	$myProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
	// Defensive in-PHP filter in case column(s) were added after query build or data anomalies
	$myProjects = array_values(array_filter($myProjects, function($row){
		if (array_key_exists('is_deleted',$row) && (int)$row['is_deleted']===1) return false;
		if (array_key_exists('is_archived',$row) && (int)$row['is_archived']===1) return false;
		return true;
	}));

	// My Tasks (broader set; include completed and other lifecycle statuses). We keep ordering to surface active first.
	$stmt = $db->prepare("SELECT task_id, task_name, status, due_date, assigned_to
		FROM tasks
		WHERE (assigned_to = ? OR assigned_to = ?)
		ORDER BY
		CASE
			WHEN status IS NULL OR status = '' THEN 0
			WHEN LOWER(status) IN ('pending','to do','to_do','in progress','in_progress') THEN 1
			WHEN LOWER(status) IN ('revise','revision','revision requested','request revision') THEN 2
			WHEN LOWER(status) IN ('under review','under_review','review','for review') THEN 3
			WHEN LOWER(status) IN ('completed','done') THEN 4
			ELSE 5
		END,
		(due_date IS NULL), due_date ASC, task_id DESC
		LIMIT 10");
	$stmt->execute([$employeeId, $_SESSION['user_id']]);
	$myTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

		// Upcoming Milestones (top 6)
		$stmt = $db->prepare("SELECT m.milestone_id, m.milestone_name, m.target_date, p.project_name, p.project_code
													 FROM milestones m
													 JOIN projects p ON p.project_id = m.project_id
													 WHERE p.architect_id = ? AND m.completion_date IS NULL AND (m.target_date IS NOT NULL AND m.target_date >= CURDATE())
													 ORDER BY m.target_date ASC, m.milestone_id DESC
													 LIMIT 6");
		$stmt->execute([$employeeId]);
		$myMilestones = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
		$errorMsg = $e->getMessage();
}

// Helper: status badge classes
function status_badge_class($status) {
	$raw = (string)$status;
	$normalized = strtolower(trim($raw));
	// Normalize spaces/dashes to underscores for consistent map keys
	$normalized = str_replace([' ', '-'], '_', $normalized);
	$map = [
		// Project lifecycle statuses
		'planning' => 'bg-yellow-100 text-yellow-800',
		'design' => 'bg-blue-100 text-blue-800',
		'construction' => 'bg-purple-100 text-purple-800',
		'completed' => 'bg-green-100 text-green-800',
		'cancelled' => 'bg-red-100 text-red-800',
		// Task & shared statuses
		'pending' => 'bg-amber-100 text-amber-800',
		'in_progress' => 'bg-indigo-100 text-indigo-800',
		'to_do' => 'bg-slate-100 text-slate-800',
		'revise' => 'bg-orange-100 text-orange-800',
		'revision' => 'bg-orange-100 text-orange-800',
		'revision_requested' => 'bg-orange-100 text-orange-800',
		'under_review' => 'bg-amber-100 text-amber-800',
		'review' => 'bg-amber-100 text-amber-800',
		'done' => 'bg-green-100 text-green-800',
	];
	return $map[$normalized] ?? 'bg-gray-100 text-gray-800';
}

include __DIR__ . '/../../backend/core/header.php';
?>

<main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-50">
	<div class="max-w-full px-4 sm:px-6 lg:px-8 py-8">
		<!-- Header -->
		<div class="flex items-center justify-between mb-8">
			<div>
				<h1 class="text-2xl sm:text-3xl font-bold text-slate-900">Welcome back, <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?></h1>
				<p class="text-slate-500 mt-1">Architect • <?php echo date('l, F j, Y'); ?></p>
			</div>
			<div class="flex items-center gap-2">
				<!-- Design Services button removed as requested -->
			</div>
		</div>

		<?php if (!empty($errorMsg)): ?>
			<div class="mb-6 p-4 rounded-lg ring-1 ring-red-200 bg-red-50 text-red-800">
				<strong>Unable to load dashboard:</strong> <?php echo htmlspecialchars($errorMsg); ?>
			</div>
		<?php endif; ?>

		<!-- KPI Cards -->
			<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
				<div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm">
					<div class="flex items-center justify-between">
						<div>
							<p class="text-slate-500">Active Projects</p>
							<p class="text-3xl font-bold" data-counter="<?php echo (int)$stats['active_projects']; ?>">0</p>
						</div>
						<span class="p-3 rounded-xl bg-blue-50 text-blue-600"><i class="fas fa-diagram-project"></i></span>
					</div>
				</div>
				<div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm">
					<div class="flex items-center justify-between">
						<div>
							<p class="text-slate-500">Completed</p>
							<p class="text-3xl font-bold" data-counter="<?php echo (int)$stats['completed_projects']; ?>">0</p>
						</div>
						<span class="p-3 rounded-xl bg-green-50 text-green-600"><i class="fas fa-check-circle"></i></span>
					</div>
				</div>
				<div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm">
					<div class="flex items-center justify-between">
						<div>
							<p class="text-slate-500">My Tasks</p>
							<p class="text-3xl font-bold" data-counter="<?php echo (int)$stats['pending_tasks']; ?>">0</p>
						</div>
						<span class="p-3 rounded-xl bg-amber-50 text-amber-600"><i class="fas fa-list-check"></i></span>
					</div>
				</div>
			</div>


		<!-- Two-column: Projects and Tasks/Milestones -->
		<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
			<!-- My Projects -->
			<section class="lg:col-span-2 rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm">
				<div class="flex items-center justify-between mb-4">
					<h2 class="text-lg font-semibold text-slate-900">My Recent Projects</h2>
				</div>
				<div class="overflow-x-auto -mx-4 sm:mx-0">
					<table class="min-w-full divide-y divide-slate-200">
						<thead>
							<tr class="text-left text-xs font-medium uppercase tracking-wider text-slate-500">
								<th class="py-3 pr-3 pl-4">Project</th>
								<th class="py-3 px-3">Client</th>
								<th class="py-3 px-3">Type</th>
								<th class="py-3 px-3">Status</th>
								<th class="py-3 pl-3 pr-4 text-right">Created</th>
							</tr>
						</thead>
						<tbody class="divide-y divide-slate-100">
							<?php if (empty($myProjects)): ?>
								<tr><td colspan="5" class="py-6 text-center text-slate-500">No projects yet.</td></tr>
							<?php else: foreach ($myProjects as $p): ?>
								<tr class="hover:bg-slate-50">
									<td class="py-3 pr-3 pl-4">
										<div class="font-medium text-slate-900"><?php echo htmlspecialchars($p['project_name']); ?></div>
										<div class="text-xs text-slate-500"><?php echo htmlspecialchars($p['project_code']); ?></div>
									</td>
									<td class="py-3 px-3">
										<div class="text-slate-900"><?php echo htmlspecialchars($p['company_name'] ?? '—'); ?></div>
										<div class="text-xs text-slate-500"><?php echo htmlspecialchars($p['client_type'] ?? ''); ?></div>
									</td>
									<td class="py-3 px-3 capitalize text-slate-700"><?php echo htmlspecialchars($p['project_type']); ?></td>
									<td class="py-3 px-3">
										<span class="px-2 py-1 rounded-full text-xs font-medium <?php echo status_badge_class($p['status']); ?>"><?php echo htmlspecialchars($p['status']); ?></span>
									</td>
									<td class="py-3 pl-3 pr-4 text-right text-slate-600">
										<?php echo htmlspecialchars(date('M j, Y', strtotime($p['created_at']))); ?>
										<a class="ml-2 text-blue-600 hover:text-blue-800" href="/ArchiFlow/employees/architects/project-details.php?project_id=<?php echo (int)$p['project_id']; ?>">View</a>
									</td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
				<?php if (!empty($myProjects)): ?>
				<div class="mt-8">
					<h3 class="text-sm font-semibold text-slate-700 mb-2 flex items-center gap-2"><i class="fas fa-clock text-slate-400"></i><span>Recent Projects (ID / Created)</span></h3>
					<div class="overflow-x-auto rounded-lg ring-1 ring-slate-200 bg-slate-50/50">
						<table class="min-w-full text-[12px]">
							<thead class="bg-slate-100 text-slate-600 uppercase tracking-wide">
								<tr>
									<th class="px-3 py-2 text-left font-semibold">ID</th>
									<th class="px-3 py-2 text-left font-semibold whitespace-nowrap">Created</th>
								</tr>
							</thead>
							<tbody class="divide-y divide-slate-200 bg-white/50">
								<?php foreach ($myProjects as $p): ?>
								<tr class="hover:bg-slate-50">
									<td class="px-3 py-1.5 font-mono text-slate-700">#<?php echo (int)$p['project_id']; ?></td>
									<td class="px-3 py-1.5 text-slate-600 whitespace-nowrap text-[11px]"><?php echo htmlspecialchars($p['created_at']); ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
				<?php endif; ?>
			</section>

			<!-- Right column: Tasks & Milestones -->
				<div class="space-y-6">
				<!-- My Tasks -->
					<section class="rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm">
						<div class="flex items-center justify-between mb-4">
							<h3 class="text-lg font-semibold text-slate-900">My Tasks</h3>
						</div>
						<ul class="divide-y divide-slate-100">
							<?php if (empty($myTasks)): ?>
								<li class="py-4 text-slate-500">No open tasks. Enjoy your day!</li>
							<?php else: foreach ($myTasks as $t): ?>
								<li class="py-3 flex flex-col gap-2">
									<div class="flex items-center justify-between">
										<div>
											<div class="font-medium text-slate-900"><?php echo htmlspecialchars($t['task_name']); ?></div>
											<div class="text-xs text-slate-500">
												Due: <?php echo $t['due_date'] ? htmlspecialchars(date('M j, Y', strtotime($t['due_date']))) : 'No due date'; ?>
											</div>
										</div>
										<div class="flex items-center gap-2">
											<span class="px-2 py-1 rounded-full text-xs font-medium <?php echo status_badge_class($t['status']); ?>"><?php echo htmlspecialchars(str_replace('_',' ',$t['status'])); ?></span>
											<a class="ml-2 text-blue-600 hover:text-blue-800 text-xs font-semibold" href="/ArchiFlow/employees/architects/task-details.php?task_id=<?php echo (int)$t['task_id']; ?>">View</a>
										</div>
									</div>
									<?php // Messages removed from dashboard as requested ?>
								</li>
							<?php endforeach; endif; ?>
						</ul>
					</section>
				</div>
		</div>
	</div>
</main>

<script>
// Count-up animation for KPIs
document.addEventListener('DOMContentLoaded', () => {
	document.querySelectorAll('[data-counter]').forEach(el => {
		const target = parseInt(el.getAttribute('data-counter') || '0', 10);
		const duration = 800; // ms
		const start = performance.now();
		const step = (t) => {
			const p = Math.min(1, (t - start) / duration);
			el.textContent = Math.floor(target * p).toLocaleString();
			if (p < 1) requestAnimationFrame(step);
		};
		requestAnimationFrame(step);
	});
});
</script>

<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

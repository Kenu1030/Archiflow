<?php
// Project Manager - My Projects (employees area)
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
		header('Location: ../../login.php');
		exit;
}

$user_type = $_SESSION['user_type'] ?? '';
$position = strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? ''))));
if ($user_type !== 'employee' || $position !== 'project_manager') {
		header('Location: ../../index.php');
		exit;
}

require_once __DIR__ . '/../../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Ensure phase column exists (best-effort)
try {
	$chk = $db->query("SHOW COLUMNS FROM projects LIKE 'phase'");
	if ($chk->rowCount() === 0) {
		$db->exec("ALTER TABLE projects ADD COLUMN phase VARCHAR(64) DEFAULT 'Pre-Design / Programming'");
	}
} catch (Throwable $e) { /* ignore */ }

// Current user identifiers
$userId = (int)($_SESSION['user_id'] ?? 0);
$employeeId = 0;
// Try to map to employees.employee_id if table exists (graceful if missing)
try {
		$hasEmployees = $db->query("SHOW TABLES LIKE 'employees'")->rowCount() > 0;
		if ($hasEmployees) {
				$empStmt = $db->prepare('SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1');
				$empStmt->execute([$userId]);
				$empRow = $empStmt->fetch(PDO::FETCH_ASSOC);
				$employeeId = $empRow ? (int)$empRow['employee_id'] : 0;
		}
} catch (Throwable $e) { $employeeId = 0; }

// Detect projects PK
$projects_pk = 'id';
try {
		$chk = $db->query("SHOW COLUMNS FROM projects LIKE 'id'");
		if ($chk && $chk->rowCount() === 0) {
				$chk2 = $db->query("SHOW COLUMNS FROM projects LIKE 'project_id'");
				if ($chk2 && $chk2->rowCount() > 0) { $projects_pk = 'project_id'; }
		}
} catch (Throwable $e) { /* keep default */ }

// Discover available project columns
$cols = [];
try { foreach ($db->query('SHOW COLUMNS FROM projects') as $row) { $cols[$row['Field']] = true; } } catch (Throwable $e) {}
// Build archive/delete filter fragments if columns exist
$archiveFilter = '';
if (isset($cols['is_archived'])) { $archiveFilter .= ' AND is_archived = 0'; }
if (isset($cols['is_deleted'])) { $archiveFilter .= ' AND (is_deleted = 0 OR is_deleted IS NULL)'; }

$orderBy = isset($cols['created_at']) ? 'created_at DESC' : ($projects_pk . ' DESC');

// Build an assignment-aware filter that supports multiple schemas:
// - projects.manager_id stores PM as users.id (set by Senior Architect flow)
// - projects.project_manager_id may store PM as employees.employee_id or users.id
// - Fallback to project_users link table with role 'Project Manager'
$projects = [];
try {
		$conditions = [];
		$params = [];
		// Prefer manager_id when present (uses user_id)
		if (isset($cols['manager_id'])) {
				$conditions[] = 'manager_id = ?';
				$params[] = $userId;
		}
		// Also support project_manager_id if present (could be user or employee id)
		if (isset($cols['project_manager_id'])) {
				$conditions[] = '(project_manager_id = ? OR project_manager_id = ?)';
				$params[] = $userId;
				$params[] = $employeeId > 0 ? $employeeId : -1; // -1 won't match anything
		}
		if (!empty($conditions)) {
				$sql = 'SELECT * FROM projects WHERE (' . implode(' OR ', $conditions) . ")$archiveFilter ORDER BY $orderBy";
				$ps = $db->prepare($sql);
				$ps->execute($params);
				$projects = $ps->fetchAll(PDO::FETCH_ASSOC);
		}

		// Fallback: link table project_users (role contains 'Project Manager')
		if (empty($projects)) {
				$hasPU = $db->query("SHOW TABLES LIKE 'project_users'")->rowCount() > 0;
				if ($hasPU) {
						$sql = "SELECT p.* FROM projects p
								JOIN project_users pu ON pu.project_id = p.$projects_pk
								WHERE pu.user_id = ? AND (pu.role_in_project LIKE 'Project Manager' OR pu.role_in_project LIKE '%Manager%') $archiveFilter
								ORDER BY $orderBy";
						$ps = $db->prepare($sql);
						$ps->execute([$userId]);
						$projects = $ps->fetchAll(PDO::FETCH_ASSOC);
				}
		}

		// Absolute fallback: show nothing rather than all, to avoid confusion
} catch (Throwable $e) {
		$projects = [];
}

include __DIR__ . '/../../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
	<div class="max-w-full">
		<div class="mb-6">
			<h1 class="text-2xl font-bold text-gray-900">My Projects</h1>
			<p class="text-gray-500 text-sm">Project Manager view</p>
		</div>

		<?php if (empty($projects)): ?>
			<div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200 p-8">
				<p class="text-gray-600">No projects assigned to you yet.</p>
			</div>
		<?php else: ?>
			<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
				<?php foreach ($projects as $p): ?>
					<?php
						$pkVal = (int)($p[$projects_pk] ?? 0);
						$pname = isset($p['project_name']) ? (string)$p['project_name'] : (isset($p['name']) ? (string)$p['name'] : ('Project #' . $pkVal));
						$ptype = isset($p['project_type']) ? (string)$p['project_type'] : '';
						$pcode = isset($p['project_code']) ? (string)$p['project_code'] : '';
						$pstatus = isset($p['status']) ? (string)$p['status'] : '';
						$pphase = isset($p['phase']) ? (string)$p['phase'] : '';
						$start = isset($p['start_date']) ? (string)$p['start_date'] : '';
						$deadline = !empty($p['estimated_end_date']) ? (string)$p['estimated_end_date'] : (!empty($p['end_date']) ? (string)$p['end_date'] : '');
						$budget = isset($p['budget_amount']) ? (string)$p['budget_amount'] : (isset($p['budget']) ? (string)$p['budget'] : '');
					?>
					<div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200 p-5 flex flex-col">
						<div class="flex items-start justify-between">
							<div>
								<div class="text-sm text-gray-500">
									<?php if ($pcode !== ''): ?>
										<span class="font-mono"><?php echo htmlspecialchars($pcode); ?></span>
									<?php else: ?>
										<span class="opacity-60">#<?php echo $pkVal; ?></span>
									<?php endif; ?>
								</div>
								<h2 class="text-lg font-semibold text-gray-900 mt-1"><?php echo htmlspecialchars($pname); ?></h2>
							</div>
							<?php if ($pstatus !== ''): ?>
								<span class="px-2 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-100"><?php echo htmlspecialchars($pstatus); ?></span>
							<?php endif; ?>
						</div>
						<div class="mt-3 text-sm text-gray-600 space-y-1">
							<?php if ($ptype !== ''): ?><div><span class="text-gray-500">Type:</span> <?php echo htmlspecialchars($ptype); ?></div><?php endif; ?>
							<?php if ($pphase !== ''): ?><div><span class="text-gray-500">Phase:</span> <span class="inline-block px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-100 text-[11px] font-medium"><?php echo htmlspecialchars($pphase); ?></span></div><?php endif; ?>
							<?php if ($start !== ''): ?><div><span class="text-gray-500">Start:</span> <?php echo htmlspecialchars($start); ?></div><?php endif; ?>
							<?php if ($deadline !== ''): ?><div><span class="text-gray-500">Deadline:</span> <?php echo htmlspecialchars($deadline); ?></div><?php endif; ?>
							<?php if ($budget !== ''): ?><div><span class="text-gray-500">Budget:</span> <?php echo htmlspecialchars($budget); ?></div><?php endif; ?>
						</div>
						<div class="mt-4 pt-3 border-t border-gray-100">
							<a href="/ArchiFlow/project_details.php?project_id=<?php echo $pkVal; ?>" class="inline-flex items-center px-3 py-2 rounded-lg text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 shadow-sm">View</a>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</main>
<?php include __DIR__ . '/../../../backend/core/footer.php'; ?>

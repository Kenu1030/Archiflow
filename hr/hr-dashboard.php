<?php
// HR Dashboard - modern UI + data-driven widgets
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Guard: HR only
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['user_type'] ?? null) !== 'hr') {
	header('Location: ../login.php');
		exit;
}

require_once __DIR__ . '/../backend/connection/connect.php';
$pdo = getDB();
if (!$pdo) {
		http_response_code(500);
		echo 'Database connection failed.';
		exit;
}

// Helpers
$hasColumn = function (PDO $pdo, string $table, string $column): bool {
		$stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
		$stmt->execute([$table, $column]);
		return (int)$stmt->fetchColumn() > 0;
};

// Metrics
try {
		$totalEmployees = (int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();

		$today = (new DateTime('today'))->format('Y-m-d');
		$stmt = $pdo->prepare("SELECT status, COUNT(*) AS c FROM attendance WHERE work_date = ? GROUP BY status");
		$stmt->execute([$today]);
		$att = ['present' => 0, 'absent' => 0, 'late' => 0];
		foreach ($stmt as $row) { $att[$row['status']] = (int)$row['c']; }

		$monthlyLeaves = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE YEAR(applied_date)=YEAR(CURDATE()) AND MONTH(applied_date)=MONTH(CURDATE())")->fetchColumn();
		$monthlyPayroll = (int)$pdo->query("SELECT COUNT(*) FROM payroll WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetchColumn();

		// Recent employees: prefer names if available
		$hasUsers = $hasColumn($pdo, 'employees', 'user_id') && $hasColumn($pdo, 'users', 'user_id');
		$hasFirst = $hasUsers && $hasColumn($pdo, 'users', 'first_name');
		$hasLast  = $hasUsers && $hasColumn($pdo, 'users', 'last_name');
		if ($hasUsers && ($hasFirst || $hasLast)) {
				$nameExpr = trim(($hasFirst ? 'u.first_name' : "''") . ' , ' . ($hasLast ? 'u.last_name' : "''"));
				$sql = "SELECT e.employee_id, CONCAT_WS(' ', " . ($hasFirst ? 'u.first_name' : "NULL") . ", " . ($hasLast ? 'u.last_name' : "NULL") . ") AS full_name, e.created_at
								FROM employees e LEFT JOIN users u ON e.user_id = u.user_id
								ORDER BY e.created_at DESC, e.employee_id DESC LIMIT 6";
				$recentEmployees = $pdo->query($sql)->fetchAll();
		} else {
				$recentEmployees = $pdo->query("SELECT employee_id, created_at FROM employees ORDER BY created_at DESC, employee_id DESC LIMIT 6")->fetchAll();
				foreach ($recentEmployees as &$re) { $re['full_name'] = 'Employee #' . $re['employee_id']; }
		}

		// Today attendance snapshot
		$todayAttendance = $pdo->prepare("SELECT employee_id, status, time_in, time_out FROM attendance WHERE work_date = ? ORDER BY (time_in IS NULL), time_in LIMIT 10");
		$todayAttendance->execute([$today]);
		$todayAttendance = $todayAttendance->fetchAll();

		// Latest leave requests
		$latestLeaves = $pdo->query("SELECT leave_id, employee_id, applied_date FROM leave_requests ORDER BY applied_date DESC, leave_id DESC LIMIT 6")->fetchAll();

} catch (Throwable $e) {
		error_log('HR dashboard metrics error: ' . $e->getMessage());
		$totalEmployees = $totalEmployees ?? 0;
		$att = $att ?? ['present' => 0, 'absent' => 0, 'late' => 0];
		$monthlyLeaves = $monthlyLeaves ?? 0;
		$monthlyPayroll = $monthlyPayroll ?? 0;
		$recentEmployees = $recentEmployees ?? [];
		$todayAttendance = $todayAttendance ?? [];
		$latestLeaves = $latestLeaves ?? [];
}

// Suppress footer on this page
$HIDE_FOOTER = true;
include_once __DIR__ . '/../backend/core/header.php';
?>

<section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-10 shadow-lg rounded-xl mb-8 relative" style="transform:none;">
	<div class="max-w-full px-4">
		<div class="flex items-center space-x-4">
			<div class="w-12 h-12 bg-white/10 rounded-xl flex items-center justify-center"><i class="fas fa-user-tie text-white text-2xl"></i></div>
			<div>
				<h1 class="text-2xl md:text-3xl font-bold">HR Dashboard</h1>
				<p class="text-white/80">People operations overview and quick actions</p>
			</div>
		</div>
	</div>
	<div class="max-w-full px-4 mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
		<div class="bg-white/10 backdrop-blur rounded-xl p-5">
			<div class="flex items-center justify-between"><span class="text-white/70">Total Employees</span><i class="fas fa-users"></i></div>
			<div class="mt-2 text-3xl font-semibold" data-counter="<?php echo (int)$totalEmployees; ?>"><?php echo (int)$totalEmployees; ?></div>
		</div>
		<div class="bg-white/10 backdrop-blur rounded-xl p-5">
			<div class="flex items-center justify-between"><span class="text-white/70">Present Today</span><i class="fas fa-user-check"></i></div>
			<div class="mt-2 text-3xl font-semibold"><?php echo (int)$att['present']; ?></div>
		</div>
		<div class="bg-white/10 backdrop-blur rounded-xl p-5">
			<div class="flex items-center justify-between"><span class="text-white/70">Late Today</span><i class="fas fa-clock"></i></div>
			<div class="mt-2 text-3xl font-semibold"><?php echo (int)$att['late']; ?></div>
		</div>
		<div class="bg-white/10 backdrop-blur rounded-xl p-5">
			<div class="flex items-center justify-between"><span class="text-white/70">Leaves (This Month)</span><i class="fas fa-calendar-alt"></i></div>
			<div class="mt-2 text-3xl font-semibold"><?php echo (int)$monthlyLeaves; ?></div>
		</div>
	</div>
</section>

<main class="max-w-full px-4 mt-8">
	<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
		<div class="lg:col-span-2">
			<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
				<div class="flex items-center justify-between mb-4">
					<h2 class="text-lg font-semibold">Recent Employees</h2>
					<a href="hr/employees.php" class="text-sm text-blue-600 hover:underline">View all</a>
				</div>
				<div class="overflow-x-auto">
					<table class="min-w-full">
						<thead>
							<tr class="text-left text-sm text-gray-500">
								<th class="py-2">ID</th>
								<th class="py-2">Name</th>
								<th class="py-2">Joined</th>
							</tr>
						</thead>
						<tbody class="text-sm">
							<?php if (empty($recentEmployees)): ?>
								<tr><td colspan="3" class="py-4 text-center text-gray-500">No employees found.</td></tr>
							<?php else: foreach ($recentEmployees as $emp): ?>
								<tr class="border-t">
									<td class="py-2 font-medium">#<?php echo (int)$emp['employee_id']; ?></td>
									<td class="py-2"><?php echo htmlspecialchars($emp['full_name'] ?? ('Employee #' . (int)$emp['employee_id'])); ?></td>
									<td class="py-2 text-gray-500"><?php echo htmlspecialchars(date('M d, Y', strtotime($emp['created_at']))); ?></td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-6">
				<div class="flex items-center justify-between mb-4">
					<h2 class="text-lg font-semibold">Today’s Attendance</h2>
					<a href="hr/attendance.php" class="text-sm text-blue-600 hover:underline">Manage attendance</a>
				</div>
				<div class="overflow-x-auto">
					<table class="min-w-full">
						<thead>
							<tr class="text-left text-sm text-gray-500">
								<th class="py-2">Employee</th>
								<th class="py-2">Status</th>
								<th class="py-2">Time In</th>
								<th class="py-2">Time Out</th>
							</tr>
						</thead>
						<tbody class="text-sm">
							<?php if (empty($todayAttendance)): ?>
								<tr><td colspan="4" class="py-4 text-center text-gray-500">No records today.</td></tr>
							<?php else: foreach ($todayAttendance as $a): ?>
								<tr class="border-t">
									<td class="py-2">#<?php echo (int)$a['employee_id']; ?></td>
									<td class="py-2 capitalize">
										<?php if ($a['status'] === 'present'): ?><span class="px-2 py-0.5 rounded-full bg-green-100 text-green-700">Present</span>
										<?php elseif ($a['status'] === 'late'): ?><span class="px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700">Late</span>
										<?php else: ?><span class="px-2 py-0.5 rounded-full bg-red-100 text-red-700">Absent</span><?php endif; ?>
									</td>
									<td class="py-2 text-gray-500"><?php echo $a['time_in'] ? htmlspecialchars(substr($a['time_in'],0,5)) : '—'; ?></td>
									<td class="py-2 text-gray-500"><?php echo $a['time_out'] ? htmlspecialchars(substr($a['time_out'],0,5)) : '—'; ?></td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>

		<div class="space-y-6">
			<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
				<div class="flex items-center justify-between">
					<h2 class="text-lg font-semibold">This Month</h2>
				</div>
				<div class="grid grid-cols-2 gap-4 mt-4 text-center">
					<div class="rounded-lg bg-blue-50 p-4">
						<div class="text-2xl font-semibold text-blue-700"><?php echo (int)$monthlyLeaves; ?></div>
						<div class="text-sm text-blue-800/70">Leave Requests</div>
					</div>
					<div class="rounded-lg bg-indigo-50 p-4">
						<div class="text-2xl font-semibold text-indigo-700"><?php echo (int)$monthlyPayroll; ?></div>
						<div class="text-sm text-indigo-800/70">Payroll Batches</div>
					</div>
				</div>
			</div>

			<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
				<div class="flex items-center justify-between mb-3">
					<h2 class="text-lg font-semibold">Latest Leave Requests</h2>
					<a href="hr/leave-requests.php" class="text-sm text-blue-600 hover:underline">View all</a>
				</div>
				<ul class="divide-y">
					<?php if (empty($latestLeaves)): ?>
						<li class="py-3 text-sm text-gray-500">No leave requests.</li>
					<?php else: foreach ($latestLeaves as $lr): ?>
						<li class="py-3 text-sm flex items-center justify-between">
							<div>
								<div class="font-medium">Leave #<?php echo (int)$lr['leave_id']; ?></div>
								<div class="text-gray-500">Employee #<?php echo (int)$lr['employee_id']; ?></div>
							</div>
							<div class="text-gray-500"><?php echo htmlspecialchars(date('M d, Y', strtotime($lr['applied_date']))); ?></div>
						</li>
					<?php endforeach; endif; ?>
				</ul>
			</div>

			<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
				<h2 class="text-lg font-semibold mb-3">Quick Actions</h2>
				<div class="grid grid-cols-2 gap-3">
					<a href="hr/employees.php" class="flex items-center space-x-2 p-3 border rounded-lg hover:bg-gray-50">
						<i class="fas fa-user-plus text-blue-600"></i><span>Add Employee</span>
					</a>
					<a href="hr/attendance.php" class="flex items-center space-x-2 p-3 border rounded-lg hover:bg-gray-50">
						<i class="fas fa-calendar-check text-emerald-600"></i><span>Attendance</span>
					</a>
					<a href="hr/leave-requests.php" class="flex items-center space-x-2 p-3 border rounded-lg hover:bg-gray-50">
						<i class="fas fa-file-medical text-indigo-600"></i><span>Leave Requests</span>
					</a>
					<a href="hr/payroll.php" class="flex items-center space-x-2 p-3 border rounded-lg hover:bg-gray-50">
						<i class="fas fa-wallet text-purple-600"></i><span>Payroll</span>
					</a>
				</div>
			</div>
		</div>
	</div>
</main>

<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>


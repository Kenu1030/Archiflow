<?php
// Client Progress Tracking
// Suppress footer on this page
$HIDE_FOOTER = true;
require_once __DIR__ . '/_client_common.php';
include_once __DIR__ . '/../backend/core/header.php';

if (!$clientId) {
		echo '<main class="p-6"><div class="bg-yellow-50 text-yellow-800 p-4 rounded">Your account is not linked to a client record yet.</div></main>';
		include_once __DIR__ . '/../backend/core/footer.php';
		exit;
}

// Helpers
$hasCol = function(string $table, string $col) use ($hasColumn, $pdo) {
		return $hasColumn($pdo, $table, $col);
};

// Try to fetch client projects with best-effort columns
$projectCols = ['project_id'];
foreach (['project_name','project_code','status','start_date','end_date','created_at'] as $c) {
		if ($hasCol('projects',$c)) { $projectCols[] = $c; }
}
$projectSelect = implode(', ', $projectCols);

$projects = [];
try {
		$stmt = $pdo->prepare("SELECT $projectSelect FROM projects WHERE client_id = ? ORDER BY created_at DESC");
		$stmt->execute([$clientId]);
		$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
		$projects = [];
}

// Compute progress per project using milestones if available, else tasks
function project_progress(PDO $pdo, array $project, callable $hasCol): array {
		$pid = (int)$project['project_id'];
		$percent = 0; $total = 0; $done = 0; $upcoming = null;

		// Prefer milestones
		$milestonesTable = $hasCol('milestones','project_id');
		if ($milestonesTable) {
				try {
						$total = (int)$pdo->prepare('SELECT COUNT(*) FROM milestones WHERE project_id = ?')->execute([$pid]) ?
								(int)($pdo->query("SELECT COUNT(*) FROM milestones WHERE project_id = $pid")->fetchColumn()) : 0;
				} catch (Throwable $e) { $total = 0; }

				if ($hasCol('milestones','status')) {
						try {
								$st = $pdo->prepare("SELECT COUNT(*) FROM milestones WHERE project_id = ? AND status = 'completed'");
								$st->execute([$pid]);
								$done = (int)$st->fetchColumn();
						} catch (Throwable $e) {}
				} elseif ($hasCol('milestones','completion_date')) {
						try {
								$st = $pdo->prepare('SELECT COUNT(*) FROM milestones WHERE project_id = ? AND completion_date IS NOT NULL');
								$st->execute([$pid]);
								$done = (int)$st->fetchColumn();
						} catch (Throwable $e) {}
				}

				if ($total > 0) { $percent = (int)round(($done / $total) * 100); }

				// Upcoming milestone within 30 days
				if ($hasCol('milestones','target_date')) {
						try {
								if ($hasCol('milestones','status')) {
										$st = $pdo->prepare("SELECT MIN(target_date) FROM milestones WHERE project_id = ? AND (status = 'pending' OR status = 'in_progress' OR status IS NULL) AND target_date >= CURDATE()");
								} else {
										$st = $pdo->prepare('SELECT MIN(target_date) FROM milestones WHERE project_id = ? AND target_date >= CURDATE()');
								}
								$st->execute([$pid]);
								$upcoming = $st->fetchColumn() ?: null;
						} catch (Throwable $e) {}
				}
		}

		// Fallback to tasks if no milestones rows
		if ($total === 0 && $hasCol('tasks','project_id')) {
				try {
						$total = (int)$pdo->prepare('SELECT COUNT(*) FROM tasks WHERE project_id = ?')->execute([$pid]) ?
								(int)($pdo->query("SELECT COUNT(*) FROM tasks WHERE project_id = $pid")->fetchColumn()) : 0;
				} catch (Throwable $e) { $total = 0; }
				if ($hasCol('tasks','status')) {
						try {
								$st = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE project_id = ? AND status = 'completed'");
								$st->execute([$pid]);
								$done = (int)$st->fetchColumn();
						} catch (Throwable $e) {}
				}
				if ($total > 0) { $percent = (int)round(($done / $total) * 100); }
		}

		return [$percent, $total, $done, $upcoming];
}

?>

<section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-8">
	<div class="max-w-full px-4">
		<h1 class="text-2xl font-semibold">Progress Tracking</h1>
		<p class="text-white/70">Monitor the status of your projects, milestones, and tasks.</p>
		<div class="mt-4 flex items-center gap-3">
			<input type="text" id="projectSearch" placeholder="Search projects..." class="w-full max-w-md px-3 py-2 rounded bg-white/90 text-gray-900 placeholder-gray-500 focus:outline-none" />
		</div>
	</div>
	<script>
		// simple client-side filter
		document.addEventListener('DOMContentLoaded', function(){
			const input = document.getElementById('projectSearch');
			const cards = () => Array.from(document.querySelectorAll('[data-project-card]'));
			if (input) {
				input.addEventListener('input', function(){
					const k = this.value.toLowerCase();
					cards().forEach(c => {
						const t = (c.getAttribute('data-name')||'').toLowerCase();
						c.style.display = t.includes(k) ? '' : 'none';
					});
				});
			}
		});
	</script>
</section>

<main class="max-w-full px-4 -mt-6">
	<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
		<?php if (!$projects): ?>
			<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
				<p class="text-gray-600">No projects found.</p>
			</div>
		<?php else: ?>
			<?php foreach ($projects as $p): 
				[$pct,$total,$done,$upcoming] = project_progress($pdo, $p, $hasCol);
				$name = $p['project_name'] ?? ($p['project_code'] ?? ('Project #' . (int)$p['project_id']));
				$status = $p['status'] ?? null;
			?>
			<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6" data-project-card data-name="<?php echo htmlspecialchars($name); ?>">
				<div class="flex items-start justify-between">
					<div>
						<h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($name); ?></h3>
						<?php if ($status): ?><p class="text-sm text-gray-500 capitalize">Status: <?php echo htmlspecialchars($status); ?></p><?php endif; ?>
					</div>
					<span class="text-sm text-gray-500">#<?php echo (int)$p['project_id']; ?></span>
				</div>
				<!-- Overall Progress bar removed per request -->
				<div class="mt-4 grid grid-cols-2 gap-3 text-sm">
					<div class="p-3 rounded bg-gray-50">
						<div class="text-gray-500">Upcoming</div>
						<div class="font-medium text-gray-800"><?php echo $upcoming ? htmlspecialchars(date('M d, Y', strtotime($upcoming))) : '—'; ?></div>
					</div>
					<?php if (!empty($p['start_date']) || !empty($p['end_date'])): ?>
					<div class="p-3 rounded bg-gray-50">
						<div class="text-gray-500">Timeline</div>
						<div class="font-medium text-gray-800"><?php echo !empty($p['start_date']) ? htmlspecialchars(date('M d, Y', strtotime($p['start_date']))) : '—'; ?> → <?php echo !empty($p['end_date']) ? htmlspecialchars(date('M d, Y', strtotime($p['end_date']))) : '—'; ?></div>
					</div>
					<?php endif; ?>
				</div>
				<div class="mt-4 flex gap-2">
					<a href="client/projects-client.php" class="px-3 py-2 text-sm rounded bg-blue-600 text-white hover:bg-blue-700">View Projects</a>
					<a href="client/messages.php" class="px-3 py-2 text-sm rounded bg-gray-100 text-gray-800 hover:bg-gray-200">Message Us</a>
				</div>
			</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</main>

<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>


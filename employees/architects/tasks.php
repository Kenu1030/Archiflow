<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../../login.php'); exit; }
if (($_SESSION['user_type'] ?? '') !== 'employee' || strtolower((string)($_SESSION['position'] ?? '')) !== 'architect') { header('Location: ../../index.php'); exit; }
require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$emp = $db->prepare('SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1');
$emp->execute([$_SESSION['user_id']]);
$e = $emp->fetch(PDO::FETCH_ASSOC);
$employeeId = $e ? (int)$e['employee_id'] : 0;
$rows = [];$err='';
try {
  // Show tasks assigned directly or via project_users
  $stmt = $db->prepare("SELECT DISTINCT t.task_id, t.task_name, t.status, t.due_date, p.project_name, p.project_id
                         FROM tasks t
                         LEFT JOIN projects p ON p.project_id = t.project_id
                         LEFT JOIN project_users pu ON pu.project_id = t.project_id AND pu.user_id = ?
                         WHERE t.assigned_to = ? OR pu.user_id = ?
                         ORDER BY (t.due_date IS NULL), t.due_date ASC, t.task_id DESC");
  $stmt->execute([$_SESSION['user_id'], $employeeId, $_SESSION['user_id']]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) { $err = $ex->getMessage(); }
include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
  <div class="max-w-full">
    <h1 class="text-2xl font-bold mb-4">Tasks</h1>
  <?php if ($err): ?><div class="mb-4 p-3 bg-red-50 text-red-700 ring-1 ring-red-200 rounded"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200">
      <ul class="divide-y divide-gray-100">
        <?php if (!$rows): ?>
          <li class="p-4 text-gray-500">No tasks found.</li>
        <?php else: foreach ($rows as $t): ?>
          <li class="p-4 flex items-center justify-between">
            <div>
              <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($t['task_name']); ?></div>
              <div class="text-xs text-gray-500">Project: <?php echo htmlspecialchars($t['project_name'] ?? '—'); ?></div>
            </div>
            <div class="text-right flex items-center gap-2">
              <div class="text-sm text-gray-700"><?php echo $t['due_date'] ? htmlspecialchars(date('M j, Y', strtotime($t['due_date']))) : 'No due date'; ?></div>
              <span class="px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-700 inline-block mt-1"><?php echo htmlspecialchars(str_replace('_',' ', $t['status'])); ?></span>
              <a href="/ArchiFlow/employees/architects/task-details.php?task_id=<?php echo (int)$t['task_id']; ?>" class="ml-2 px-2 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 text-xs">View</a>
              <?php if (!empty($t['project_id'])): ?>
              <a href="/ArchiFlow/employees/architects/project-materials.php?project_id=<?php echo (int)$t['project_id']; ?>" class="px-2 py-1 bg-emerald-600 text-white rounded hover:bg-emerald-700 text-xs">Materials</a>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; endif; ?>
      </ul>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

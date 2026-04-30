<?php
// Auth & header
$allowed_roles = ['project_manager', 'admin', 'architect', 'senior_architect'];
include __DIR__.'/includes/auth_check.php';
include 'db.php';
$page_title = 'Task Details';
include __DIR__.'/includes/header.php';

// Get task_id from URL
$task_id = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
if ($task_id <= 0) { echo '<div class="text-red-600">Invalid task ID.</div>'; include __DIR__.'/includes/footer.php'; exit; }

// Fetch task details
$stmt = $conn->prepare("SELECT t.*, p.project_name FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.task_id = ? LIMIT 1");
$stmt->bind_param('i', $task_id);
$stmt->execute();
$res = $stmt->get_result();
$task = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$task) { echo '<div class="text-red-600">Task not found.</div>'; include __DIR__.'/includes/footer.php'; exit; }

// Fetch files uploaded for this task
$stmtF = $conn->prepare("SELECT tf.*, u.first_name, u.last_name FROM task_files tf LEFT JOIN users u ON tf.uploaded_by = u.user_id WHERE tf.task_id = ? ORDER BY tf.uploaded_at DESC");
$stmtF->bind_param('i', $task_id);
$stmtF->execute();
$resF = $stmtF->get_result();
$files = $resF ? $resF->fetch_all(MYSQLI_ASSOC) : [];
$stmtF->close();

// Fetch messages for this task
$stmtM = $conn->prepare("SELECT tm.*, u.first_name, u.last_name FROM task_messages tm LEFT JOIN users u ON tm.user_id = u.user_id WHERE tm.task_id = ? ORDER BY tm.created_at ASC");
$stmtM->bind_param('i', $task_id);
$stmtM->execute();
$resM = $stmtM->get_result();
$messages = $resM ? $resM->fetch_all(MYSQLI_ASSOC) : [];
$stmtM->close();
?>
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-purple-50 flex-1">
  <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-8">
      <h1 class="text-2xl font-bold text-gray-900 mb-2">Task Details</h1>
      <div class="text-sm text-gray-600 mb-2">Project: <?php echo htmlspecialchars($task['project_name'] ?? ''); ?></div>
      <div class="text-sm text-gray-600 mb-2">Task: <b><?php echo htmlspecialchars($task['task_name'] ?? $task['title'] ?? ''); ?></b></div>
      <div class="text-sm text-gray-600 mb-2">Status: <?php echo htmlspecialchars($task['status']); ?></div>
      <div class="text-sm text-gray-600 mb-2">Due: <?php echo htmlspecialchars($task['due_date']); ?></div>
      <div class="text-sm text-gray-600 mb-2">Phase: <?php echo htmlspecialchars($task['phase']); ?></div>
      <div class="text-sm text-gray-600 mb-2">Description: <?php echo nl2br(htmlspecialchars($task['description'])); ?></div>
    </div>
    <!-- Files Section -->
    <div class="mb-8">
      <h2 class="text-lg font-semibold text-slate-900 mb-2">Uploaded Files</h2>
      <?php if (empty($files)): ?>
        <div class="text-slate-500 text-sm">No files uploaded for this task.</div>
      <?php else: ?>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($files as $f): ?>
            <li class="py-2 flex items-center justify-between">
              <div>
                <a href="<?php echo htmlspecialchars($f['file_path']); ?>" target="_blank" class="text-blue-600 hover:underline font-medium">
                  <?php echo basename($f['file_path']); ?>
                </a>
                <span class="ml-2 text-xs text-slate-500">Uploaded by <?php echo htmlspecialchars(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? '')); ?> on <?php echo htmlspecialchars($f['uploaded_at']); ?></span>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <!-- Messages Section -->
    <div class="mb-8">
      <h2 class="text-lg font-semibold text-slate-900 mb-2">Task Messages</h2>
      <?php if (empty($messages)): ?>
        <div class="text-slate-500 text-sm">No messages for this task.</div>
      <?php else: ?>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($messages as $m): ?>
            <li class="py-2">
              <div class="font-medium text-slate-900"><?php echo htmlspecialchars(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')); ?></div>
              <div class="text-xs text-slate-500 mb-1"><?php echo htmlspecialchars($m['created_at']); ?></div>
              <div class="text-slate-700"><?php echo nl2br(htmlspecialchars($m['message'])); ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <a href="view_all_tasks.php?project_id=<?php echo (int)$task['project_id']; ?>" class="inline-flex items-center px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition"><i class="fas fa-arrow-left"></i> Back to All Tasks</a>
  </div>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>

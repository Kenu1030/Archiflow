<?php
// Usage: include this file in your PM's task details page
// Requires: $task_id (int) to be set before including
require_once __DIR__ . '/../db.php';

if (!isset($task_id) || !is_numeric($task_id)) {
    echo '<div class="text-red-600">Invalid task ID.</div>';
    return;
}

// Fetch files for this task
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

<!-- Files Section -->
<div class="mb-6">
  <h3 class="text-lg font-semibold text-slate-900 mb-2">Uploaded Files</h3>
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
<div class="mb-6">
  <h3 class="text-lg font-semibold text-slate-900 mb-2">Task Messages</h3>
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

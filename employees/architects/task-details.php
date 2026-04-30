// ...existing code...
<?php
// Session and role/position guard
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../../login.php'); exit; }
if (($_SESSION['user_type'] ?? '') !== 'employee' || strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? '')))) !== 'architect') { header('Location: ../../index.php'); exit; }
require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);


$taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
if ($taskId <= 0) { die('Invalid task ID.'); }

// Fetch task details with project status first for lock logic
$stmtProj = $db->prepare('SELECT t.*, p.status AS project_status FROM tasks t JOIN projects p ON p.project_id = t.project_id WHERE t.task_id = ? LIMIT 1');
$stmtProj->execute([$taskId]);
$task = $stmtProj->fetch(PDO::FETCH_ASSOC);
if (!$task) { die('Task not found.'); }
$projectCompleted = (strtolower($task['project_status'] ?? '') === 'completed');

// Handle file delete (blocked if project completed)
if (!$projectCompleted && isset($_GET['delete_file']) && is_numeric($_GET['delete_file'])) {
  $fileId = (int)$_GET['delete_file'];
  // Get file info
  $stmtF = $db->prepare("SELECT file_path, uploaded_by FROM task_files WHERE file_id = ? AND task_id = ? LIMIT 1");
  $stmtF->execute([$fileId, $taskId]);
  $file = $stmtF->fetch(PDO::FETCH_ASSOC);
  if ($file && $file['uploaded_by'] == $_SESSION['user_id']) {
    $filePath = __DIR__ . '/../../tasks/' . $file['file_path'];
    if (is_file($filePath)) { unlink($filePath); }
    $stmtDel = $db->prepare("DELETE FROM task_files WHERE file_id = ?");
    $stmtDel->execute([$fileId]);
    header('Location: task-details.php?task_id=' . $taskId . '&delete=success');
    exit;
  }
}

// Lock upload/delete if project completed or task status Under Review / Completed
$taskStatus = strtolower($task['status'] ?? '');
$isLocked = $projectCompleted || ($taskStatus === 'under review' || $taskStatus === 'completed');

// Handle file upload

$uploadMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['task_file'])) {
  if ($projectCompleted) {
    $uploadMsg = 'Project completed – uploads disabled.';
  } else {
  $file = $_FILES['task_file'];
  $upload_error = '';
  if ($file['error'] === UPLOAD_ERR_OK) {
    $allowed = ['jpg','jpeg','png','pdf','doc','docx','xls','xlsx','txt'];
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExt, $allowed)) {
      $upload_error = 'File type not allowed.';
    } else {
      $uploadDir = __DIR__ . '/../../tasks/';
      if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
      $newName = 'task_' . $taskId . '_' . time() . '_' . uniqid() . '.' . $fileExt;
      $dest = $uploadDir . $newName;
  if (move_uploaded_file($file['tmp_name'], $dest)) {
        // Save to DB
        $stmtIns = $db->prepare("INSERT INTO task_files (task_id, file_path, uploaded_at, uploaded_by) VALUES (?, ?, NOW(), ?)");
        $stmtIns->execute([$taskId, $newName, $_SESSION['user_id']]);
        // Redirect to avoid re-upload on refresh
        header('Location: task-details.php?task_id=' . $taskId . '&upload=success');
        exit;
      } else {
        $upload_error = 'Failed to move file.';
      }
    }
  } else {
    $upload_error = 'No file uploaded.';
  }
  if ($upload_error) {
    $uploadMsg = $upload_error;
  }
  }
}

// Handle new message

$msgMsg = '';
// Handle message delete
if (!$projectCompleted && isset($_GET['delete_message']) && is_numeric($_GET['delete_message'])) {
  $messageId = (int)$_GET['delete_message'];
  // Only allow delete if user is the author
  $stmtM = $db->prepare("SELECT user_id FROM task_messages WHERE id = ? AND task_id = ? LIMIT 1");
  $stmtM->execute([$messageId, $taskId]);
  $msg = $stmtM->fetch(PDO::FETCH_ASSOC);
  if ($msg && $msg['user_id'] == $_SESSION['user_id']) {
    $stmtDel = $db->prepare("DELETE FROM task_messages WHERE id = ?");
    $stmtDel->execute([$messageId]);
    header('Location: /ArchiFlow/employees/architects/task-details.php?task_id=' . $taskId . '&msgdelete=success');
    exit;
  }
}

if (!$projectCompleted && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_text'])) {
  $msgText = trim($_POST['message_text']);
  if ($msgText !== '') {
    // Save message to DB (requires task_messages table)
    $stmt = $db->prepare('INSERT INTO task_messages (task_id, user_id, message, created_at) VALUES (?, ?, ?, NOW())');
    $stmt->execute([$taskId, $_SESSION['user_id'], $msgText]);
    // Redirect to avoid re-post on refresh
    header('Location: /ArchiFlow/employees/architects/task-details.php?task_id=' . $taskId . '&msgpost=success');
    exit;
  }
}

// Fetch messages for this task
$messages = [];
try {
  $stmt = $db->prepare('SELECT tm.*, u.first_name, u.last_name FROM task_messages tm LEFT JOIN users u ON tm.user_id = u.user_id WHERE tm.task_id = ? ORDER BY tm.created_at ASC');
  $stmt->execute([$taskId]);
  $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {}

// List uploaded files for this task (if using uploads/tasks/)
$uploadedFiles = [];
$uploadedFiles = [];
try {
  $stmtF = $db->prepare("SELECT file_id, file_path, uploaded_at, uploaded_by FROM task_files WHERE task_id = ? ORDER BY uploaded_at DESC");
  $stmtF->execute([$taskId]);
  $uploadedFiles = $stmtF->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
  <div class="max-w-2xl mx-auto">
    <h1 class="text-2xl font-bold mb-4">Task Details</h1>
    <div class="mb-6 p-4 bg-white rounded-xl ring-1 ring-slate-200 shadow-sm">
      <div class="mb-2 text-slate-500">Task Name</div>
      <div class="font-medium text-slate-900 text-lg mb-2"><?php echo htmlspecialchars($task['task_name']); ?></div>
      <div class="mb-2 text-slate-500">Status</div>
      <div class="font-medium text-slate-900 mb-2"><?php echo htmlspecialchars($task['status']); ?></div>
      <div class="mb-2 text-slate-500">Due Date</div>
      <div class="font-medium text-slate-900 mb-2"><?php echo $task['due_date'] ? htmlspecialchars(date('M j, Y', strtotime($task['due_date']))) : 'No due date'; ?></div>
      <?php if (!empty($task['description'])): ?>
        <div class="mb-2 text-slate-500">Task Description</div>
        <div class="font-medium text-slate-900 mb-2"><?php echo nl2br(htmlspecialchars($task['description'])); ?></div>
      <?php endif; ?>
    </div>

    <!-- File Upload -->
    <section class="mb-6 p-4 bg-white rounded-xl ring-1 ring-slate-200 shadow-sm">
      <h2 class="text-lg font-semibold mb-2">Upload File</h2>
      <?php if ($uploadMsg): ?><div class="mb-2 text-green-700"><?php echo htmlspecialchars($uploadMsg); ?></div><?php endif; ?>
  <?php if (!$isLocked): ?>
        <form method="post" enctype="multipart/form-data">
          <label class="block mb-2" for="task_file_input">
            <span class="px-4 py-2 bg-blue-100 text-blue-700 rounded cursor-pointer inline-block hover:bg-blue-200" style="border: 1px solid #3b82f6; font-weight: 500;">Choose File
              <input type="file" id="task_file_input" name="task_file" class="sr-only">
            </span>
            <span id="file-name-display" class="ml-2 text-slate-600">No file chosen</span>
          </label>
          <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Upload</button>
        </form>
        <script>
          document.addEventListener('DOMContentLoaded', function() {
            var fileInput = document.getElementById('task_file_input');
            var fileNameDisplay = document.getElementById('file-name-display');
            if (fileInput && fileNameDisplay) {
              fileInput.addEventListener('change', function() {
                fileNameDisplay.textContent = fileInput.files.length ? fileInput.files[0].name : 'No file chosen';
              });
            }
          });
        </script>
      <?php endif; ?>
      <?php if ($uploadedFiles): ?>
        <div class="mt-4">
          <h3 class="text-sm font-semibold mb-2">Uploaded Files</h3>
          <ul class="list-disc pl-5">
            <?php foreach ($uploadedFiles as $uf): ?>
              <li>
                <a class="text-blue-600 hover:underline" href="/ArchiFlow/tasks/<?php echo urlencode($uf['file_path']); ?>" target="_blank"><?php echo htmlspecialchars($uf['file_path']); ?></a>
                <span class="text-slate-400"><?php echo date('M j, Y H:i', strtotime($uf['uploaded_at'])); ?></span>
                <?php if (!$isLocked && $_SESSION['user_id'] == ($uf['uploaded_by'] ?? null)): ?>
                  <form method="get" action="/ArchiFlow/employees/architects/task-details.php" style="display:inline">
                    <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
                    <input type="hidden" name="delete_file" value="<?php echo (int)$uf['file_id']; ?>">
                    <button type="submit" class="ml-2 text-red-600 hover:underline bg-transparent border-none cursor-pointer" onclick="return confirm('Delete this file?')">Delete</button>
                  </form>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </section>

    <!-- Messages -->
    <section class="mb-6 p-4 bg-white rounded-xl ring-1 ring-slate-200 shadow-sm">
      <h2 class="text-lg font-semibold mb-2">Messages</h2>
      <?php if ($msgMsg): ?><div class="mb-2 text-green-700"><?php echo htmlspecialchars($msgMsg); ?></div><?php endif; ?>
      <?php if (!$isLocked): ?>
        <form method="post">
          <textarea name="message_text" rows="2" class="w-full mb-2 p-2 border rounded" placeholder="Type your message..."></textarea>
          <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded">Send</button>
        </form>
      <?php elseif ($projectCompleted): ?>
        <div class="text-xs text-slate-500 mb-2">Project completed • messaging disabled</div>
      <?php endif; ?>
      <div class="mt-4">
        <?php if ($messages): foreach ($messages as $m): ?>
          <div class="mb-3 p-3 bg-slate-50 rounded flex justify-between items-center">
            <div>
              <div class="text-xs text-slate-500 mb-1"><?php echo htmlspecialchars(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')); ?> • <?php echo htmlspecialchars(date('M j, Y H:i', strtotime($m['created_at']))); ?></div>
              <div class="text-slate-900"><?php echo nl2br(htmlspecialchars($m['message'])); ?></div>
            </div>
            <?php if (!$isLocked && $_SESSION['user_id'] == ($m['user_id'] ?? null) && isset($m['id'])): ?>
              <form method="get" action="/ArchiFlow/employees/architects/task-details.php" style="display:inline">
                <input type="hidden" name="task_id" value="<?php echo htmlspecialchars($taskId); ?>">
                <input type="hidden" name="delete_message" value="<?php echo htmlspecialchars($m['id']); ?>">
                <button type="submit" class="ml-2 text-red-600 hover:underline bg-transparent border-none cursor-pointer" onclick="return confirm('Delete this message?')">Delete</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; else: ?>
          <div class="text-slate-500">No messages yet.</div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

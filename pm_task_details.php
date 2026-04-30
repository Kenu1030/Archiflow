<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
if (empty($_SESSION['role'])) { $_SESSION['role'] = 'project_manager'; }
$role = $_SESSION['role'];
if ($role !== 'project_manager' && $role !== 'administrator') {
  echo '<main class="min-h-screen flex items-center justify-center"><div class="p-8 bg-white rounded-xl shadow ring-1 ring-slate-200 text-center"><h2 class="text-xl font-bold mb-2">Access Denied</h2><p class="text-slate-600">You do not have permission to view this page.</p></div></main>';
  exit();
}
include __DIR__ . '/db.php';
$task_param = 'id';
if (!isset($_GET[$task_param]) || !is_numeric($_GET[$task_param])) { echo 'Invalid task.'; exit(); }
$task_id = (int)$_GET[$task_param];
$stmt = $conn->prepare("SELECT t.*, p.project_name, p.description as project_description, p.status as project_status FROM tasks t JOIN projects p ON t.project_id = p.project_id WHERE t.task_id = ? LIMIT 1");
$stmt->bind_param('i', $task_id);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$task) { echo 'Task not found.'; exit(); }

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Status update
  if (isset($_POST['update_status'], $_POST['task_status'])) {
    $newStatus = $_POST['task_status'];
    // Best-effort: ensure Revise exists in enum if chosen
    if ($newStatus === 'Revise') {
      @ $conn->query("ALTER TABLE tasks MODIFY status ENUM('Pending','In Progress','Under Review','Completed','Revise') DEFAULT 'Pending'");
    }
    $stmt = $conn->prepare("UPDATE tasks SET status = ? WHERE task_id = ?");
    $stmt->bind_param('si', $newStatus, $task_id);
    $stmt->execute();
    $stmt->close();
    header('Location: /ArchiFlow/pm_task_details.php?id=' . $task_id . '&statusupdate=success');
    exit;
  }
  // New message
  if (isset($_POST['new_message']) && trim($_POST['new_message']) !== '') {
    $msgText = trim($_POST['new_message']);
    // Ensure task_messages table structure compatibility
    $colCheck = $conn->query("SHOW COLUMNS FROM task_messages LIKE 'message'");
    $messageCol = 'message';
    if (!$colCheck || $colCheck->num_rows === 0) { // maybe schema uses message_text
      $colCheck2 = $conn->query("SHOW COLUMNS FROM task_messages LIKE 'message_text'");
      if ($colCheck2 && $colCheck2->num_rows > 0) { $messageCol = 'message_text'; }
      $colCheck2 && $colCheck2->free();
    }
    $colCheck && $colCheck->free();
    // Insert
    $sqlInsert = "INSERT INTO task_messages (task_id, user_id, $messageCol, created_at) VALUES (?,?,?,NOW())";
    if ($stmtMsg = $conn->prepare($sqlInsert)) {
      $uid = (int)$_SESSION['user_id'];
      $stmtMsg->bind_param('iis', $task_id, $uid, $msgText);
      $stmtMsg->execute();
      $stmtMsg->close();
    }
    header('Location: /ArchiFlow/pm_task_details.php?id=' . $task_id . '&msg=added');
    exit;
  }
}

$pname   = (string)($task['project_name'] ?? '');
$pdesc   = (string)($task['project_description'] ?? '');
$pcode   = (string)($task['project_code'] ?? '');
$ptype   = (string)($task['project_type'] ?? '');
$pstatus = (string)($task['status'] ?? '');
$start   = (string)($task['start_date'] ?? '');
$end     = (string)($task['estimated_end_date'] ?? '');
$budget  = (string)($task['budget_amount'] ?? '');
$created = (string)($task['created_at'] ?? '');

$isEmployee = (($_SESSION['user_type'] ?? '') === 'employee');
$files = [];
$stmtF = $conn->prepare("SELECT * FROM task_files WHERE task_id = ?");
$stmtF->bind_param('i', $task_id);
$stmtF->execute();
$resF = $stmtF->get_result();
while ($rowF = $resF->fetch_assoc()) { $files[] = $rowF; }
$stmtF->close();

$employeeHeader = __DIR__ . '/backend/core/header.php';
$employeeFooter = __DIR__ . '/backend/core/footer.php';
$sharedHeader   = __DIR__ . '/includes/header.php';
$sharedFooter   = __DIR__ . '/includes/footer.php';


$messages = [];

$stmtM = $conn->prepare("SELECT tm.*, u.username, u.first_name, u.last_name FROM task_messages tm LEFT JOIN users u ON tm.user_id = u.user_id WHERE tm.task_id = ? ORDER BY tm.created_at ASC");
$stmtM->bind_param('i', $task_id);
$stmtM->execute();
$resM = $stmtM->get_result();
while ($rowM = $resM->fetch_assoc()) { $messages[] = $rowM; }
$stmtM->close();

// Handle message delete
$msgMsg = '';
if (isset($_GET['delete_message']) && is_numeric($_GET['delete_message'])) {
  $messageId = (int)$_GET['delete_message'];
  // Only allow delete if user is the author
  $stmtM = $conn->prepare("SELECT user_id FROM task_messages WHERE id = ? AND task_id = ? LIMIT 1");
  $stmtM->bind_param('ii', $messageId, $task_id);
  $stmtM->execute();
  $res = $stmtM->get_result();
  $msg = $res->fetch_assoc();
  $stmtM->close();
  if ($msg && $msg['user_id'] == $_SESSION['user_id']) {
    $stmtDel = $conn->prepare("DELETE FROM task_messages WHERE id = ?");
    $stmtDel->bind_param('i', $messageId);
    $stmtDel->execute();
    $stmtDel->close();
    header('Location: /ArchiFlow/pm_task_details.php?id=' . $task_id . '&msgdelete=success');
    exit;
  }
}

if ($isEmployee && file_exists($employeeHeader)) {
  include $employeeHeader;
} else {
  include $sharedHeader;
}
?>
<main id="content-wrapper" class="flex-1 p-6">
  <div class="max-w-5xl mx-auto">
    <div class="mb-6">
      <a href="project_details.php?project_id=<?php echo (int)$task['project_id']; ?>" class="text-blue-600 hover:underline">&larr; Back to Project Details</a>
      <h1 class="text-2xl font-bold text-gray-900 mt-2">Task: <?php echo htmlspecialchars($task['task_name'] ?? $task['title'] ?? ''); ?></h1>
      <!-- Task Status Dropdown -->
      <div class="mt-4 mb-4">
        <form method="post" action="">
          <label for="task_status" class="text-gray-500 text-sm mr-2">Task Status:</label>
          <select name="task_status" id="task_status" class="border rounded px-2 py-1">
            <?php
              $statusOptions = ['Pending','In Progress','Under Review','Completed','Revise'];
              $currentStatus = $task['status'];
              foreach ($statusOptions as $opt) {
                $sel = ($currentStatus === $opt) ? 'selected' : '';
                echo '<option value="'.htmlspecialchars($opt).'" '.$sel.'>'.htmlspecialchars($opt).'</option>';
              }
            ?>
          </select>
          <button type="submit" name="update_status" class="ml-2 px-3 py-1 bg-indigo-600 text-white rounded hover:bg-indigo-700">Update</button>
        </form>
      </div>
      <?php if ($pstatus !== ''): ?>
        <span class="inline-block mt-2 px-2 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-100"><?php echo htmlspecialchars($pstatus); ?></span>
      <?php endif; ?>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
        <?php if ($pcode !== ''): ?>
          <div>
            <div class="text-gray-500 text-sm">Project Code</div>
            <div class="font-medium"><?php echo htmlspecialchars($pcode); ?></div>
          </div>
        <?php endif; ?>
        <?php if ($ptype !== ''): ?>
          <div>
            <div class="text-gray-500 text-sm">Type</div>
            <div class="font-medium"><?php echo htmlspecialchars($ptype); ?></div>
          </div>
        <?php endif; ?>
        <?php if ($start !== ''): ?>
          <div>
            <div class="text-gray-500 text-sm">Start Date</div>
            <div class="font-medium"><?php echo htmlspecialchars($start); ?></div>
          </div>
        <?php endif; ?>
        <?php if ($end !== ''): ?>
          <div>
            <div class="text-gray-500 text-sm">Deadline</div>
            <div class="font-medium"><?php echo htmlspecialchars($end); ?></div>
          </div>
        <?php endif; ?>
        <?php if ($budget !== ''): ?>
          <div>
            <div class="text-gray-500 text-sm">Budget</div>
            <div class="font-medium"><?php echo htmlspecialchars($budget); ?></div>
          </div>
        <?php endif; ?>
        <?php if ($created !== ''): ?>
          <div>
            <div class="text-gray-500 text-sm">Created At</div>
            <div class="font-medium"><?php echo htmlspecialchars($created); ?></div>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($pdesc !== ''): ?>
        <div class="mt-6">
          <div class="text-gray-500 text-sm">Project Description</div>
          <div class="mt-1 whitespace-pre-line"><?php echo nl2br(htmlspecialchars($pdesc)); ?></div>
        </div>
      <?php endif; ?>
    </div>
    <section class="mb-8" id="task-files">
      <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
          <div class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-file-arrow-up text-purple-600"></i>
          </div>
          Task Files
        </h3>
        <?php if (count($files) > 0): ?>
          <ul class="space-y-2">
            <?php foreach ($files as $file): ?>
                <li class="flex items-center gap-3 p-2 bg-gray-50 rounded-lg">
                  <i class="fas fa-file text-purple-500"></i>
                  <a href="/ArchiFlow/tasks/<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank" class="text-blue-700 hover:underline font-medium">
                    <?php echo htmlspecialchars(basename($file['file_path'])); ?>
                  </a>
                  <a href="/ArchiFlow/tasks/<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank" class="px-2 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 ml-2">View</a>
                  <a href="/ArchiFlow/tasks/<?php echo htmlspecialchars($file['file_path']); ?>" download class="px-2 py-1 bg-green-600 text-white rounded hover:bg-green-700 ml-2">Download</a>
                  <span class="text-xs text-gray-500 ml-auto">Uploaded: <?php echo date('M d, Y H:i', strtotime($file['uploaded_at'])); ?></span>
                </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="text-gray-500">No files uploaded for this task.</div>
        <?php endif; ?>
      </div>
    </section>
    <section class="mb-8" id="task-messages">
      <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
          <div class="w-8 h-8 bg-indigo-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-comments text-indigo-600"></i>
          </div>
          Task Messages
        </h3>
        <?php if (count($messages) > 0): ?>
          <ul class="space-y-2">
            <?php foreach ($messages as $msg): ?>
                <li class="p-2 bg-gray-50 rounded-lg flex justify-between items-center">
                  <div>
                    <?php
                      $msgBody = $msg['message'] ?? ($msg['message_text'] ?? '');
                      $displayName = $msg['username'] ?? trim(($msg['first_name'] ?? '').' '.($msg['last_name'] ?? ''));
                      if ($displayName === '') $displayName = 'User #'.(int)($msg['user_id']);
                    ?>
                    <div class="text-gray-900 font-medium"><?php echo nl2br(htmlspecialchars($msgBody)); ?></div>
                    <div class="text-xs text-gray-500">Sent by <?php echo htmlspecialchars($displayName); ?>: <?php echo date('M d, Y H:i', strtotime($msg['created_at'])); ?></div>
                  </div>
                  <?php if (isset($msg['id'], $msg['user_id']) && $_SESSION['user_id'] == $msg['user_id']): ?>
                    <form method="get" action="/ArchiFlow/pm_task_details.php" style="display:inline">
                      <input type="hidden" name="id" value="<?php echo htmlspecialchars($task_id); ?>">
                      <input type="hidden" name="delete_message" value="<?php echo htmlspecialchars($msg['id']); ?>">
                      <button type="submit" class="ml-2 text-red-600 hover:underline bg-transparent border-none cursor-pointer" onclick="return confirm('Delete this message?')">Delete</button>
                    </form>
                  <?php endif; ?>
                </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="text-gray-500">No messages for this task.</div>
        <?php endif; ?>
        <div class="mt-4 pt-4 border-t border-gray-200">
          <form method="post" class="space-y-2">
            <label class="block text-sm text-gray-600 font-medium">Add Message / Reply</label>
            <textarea name="new_message" rows="3" class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Type your message..."></textarea>
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 text-sm">Post Message</button>
          </form>
        </div>
      </div>

    </section>
  </div>
</main>
<?php
if ($isEmployee && file_exists($employeeFooter)) {
    include $employeeFooter;
} else {
    include $sharedFooter;
}
?>

<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['user_type'] ?? null) !== 'hr') {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../backend/connection/connect.php';
$pdo = getDB();
if (!$pdo) { http_response_code(500); echo 'DB error'; exit; }

// Ensure table exists to avoid errors on fresh systems
try { $pdo->query("SELECT 1 FROM leave_requests LIMIT 1"); }
catch (Throwable $e) {
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS leave_requests (
      leave_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      employee_id INT(10) UNSIGNED NOT NULL,
      leave_type VARCHAR(50) DEFAULT NULL,
      start_date DATE NOT NULL,
      end_date DATE NOT NULL,
      reason TEXT DEFAULT NULL,
      attachment_path VARCHAR(255) DEFAULT NULL,
      status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      applied_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (leave_id),
      KEY idx_emp (employee_id),
      KEY idx_status (status),
      KEY idx_dates (start_date, end_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Throwable $e2) {
    // If creation fails, show a helpful message
    http_response_code(500);
    exit('Missing table leave_requests and failed to create it. Please run the SQL in database/add_leave_requests_table.sql');
  }
}

// CSRF helpers
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_token'];

// Handle approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(400);
    exit('Invalid CSRF token');
  }
  $leaveId = (int)($_POST['leave_id'] ?? 0);
  $action = $_POST['action'] ?? '';
  $notes = trim($_POST['notes'] ?? '');
  if ($leaveId > 0 && in_array($action, ['approve','reject'], true)) {
    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    $stmt = $pdo->prepare("UPDATE leave_requests SET status = ? WHERE leave_id = ? AND status IN ('pending')");
    $stmt->execute([$newStatus, $leaveId]);
    // Audit via notifications to the employee's user, if mapped
    $who = $_SESSION['user_id'] ?? null;
    $owner = $pdo->prepare("SELECT e.user_id FROM leave_requests lr JOIN employees e ON lr.employee_id=e.employee_id WHERE lr.leave_id=?");
    $owner->execute([$leaveId]);
    $userId = (int)$owner->fetchColumn();
    if ($userId) {
      // Ensure notifications table and expected columns exist (handles legacy schemas)
      try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          title VARCHAR(255) NULL,
          message TEXT NULL,
          type VARCHAR(50) DEFAULT 'general',
          is_read TINYINT(1) DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX(user_id), INDEX(is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      } catch (Throwable $e) { /* ignore */ }
      try {
        $cols = [];
        $rc = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'");
        $rc->execute();
        foreach ($rc->fetchAll(PDO::FETCH_COLUMN) as $c) { $cols[$c] = true; }
        $alter = [];
        if (!isset($cols['title'])) { $alter[] = 'ADD COLUMN title VARCHAR(255) NULL'; }
        if (!isset($cols['message'])) { $alter[] = 'ADD COLUMN message TEXT NULL'; }
        if (!isset($cols['type'])) { $alter[] = "ADD COLUMN type VARCHAR(50) DEFAULT 'general'"; }
        if (!isset($cols['is_read'])) { $alter[] = 'ADD COLUMN is_read TINYINT(1) DEFAULT 0'; }
        if ($alter) { $pdo->exec('ALTER TABLE notifications ' . implode(', ', $alter)); }
      } catch (Throwable $e) { /* ignore */ }
      $title = 'Leave ' . ucfirst($newStatus);
      $message = 'Leave #' . $leaveId . ' was ' . $newStatus . ($notes !== '' ? (". Notes: " . $notes) : '');
      $ins = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'general')");
      $ins->execute([$userId, $title, $message]);
    }
  }
  header('Location: leave-requests.php');
  exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per = 10;
$offset = ($page-1) * $per;
$total = (int)$pdo->query('SELECT COUNT(*) FROM leave_requests')->fetchColumn();
$colCheck = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests'");
$colCheck->execute();
$cols = array_column($colCheck->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
$selectCols = ['leave_id','employee_id','applied_date'];
foreach (['leave_type','start_date','end_date','status'] as $c) { if (in_array($c, $cols, true)) { $selectCols[] = $c; } }
$sql = 'SELECT ' . implode(',', $selectCols) . ' FROM leave_requests ORDER BY applied_date DESC, leave_id DESC LIMIT ? OFFSET ?';
$stmt = $pdo->prepare($sql);
$stmt->bindValue(1, $per, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
// Suppress footer on this page
$HIDE_FOOTER = true;
include_once __DIR__ . '/../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-8">
  <div class="max-w-full px-4">
    <div class="flex items-center space-x-3">
      <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center"><i class="fas fa-file-medical"></i></div>
      <div>
        <h1 class="text-2xl font-semibold">Leave Requests</h1>
        <p class="text-white/70">Review and manage employee leaves</p>
      </div>
    </div>
  </div>
</section>
<main class="max-w-full px-4 -mt-6">
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <div class="flex justify-end mb-3 space-x-2">
      <a class="text-sm bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700" href="hr/export.php?type=leaves&csrf_token=<?php echo htmlspecialchars($CSRF); ?>">Export CSV</a>
      <a class="text-sm bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700" target="_blank" href="hr/print.php?type=leaves">Print</a>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full">
        <thead>
          <tr class="text-left text-sm text-gray-500">
            <th class="py-2">ID</th>
            <th class="py-2">Employee</th>
            <th class="py-2">Type</th>
            <th class="py-2">Start</th>
            <th class="py-2">End</th>
            <th class="py-2">Status</th>
            <th class="py-2">Applied</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="text-sm">
          <?php if (empty($rows)): ?>
            <tr><td colspan="8" class="py-4 text-center text-gray-500">No leave requests.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr class="border-t">
              <td class="py-2">#<?php echo (int)$r['leave_id']; ?></td>
              <td class="py-2">#<?php echo (int)$r['employee_id']; ?></td>
              <td class="py-2"><?php echo htmlspecialchars($r['leave_type'] ?? '—'); ?></td>
              <td class="py-2 text-gray-500"><?php echo isset($r['start_date']) ? htmlspecialchars($r['start_date']) : '—'; ?></td>
              <td class="py-2 text-gray-500"><?php echo isset($r['end_date']) ? htmlspecialchars($r['end_date']) : '—'; ?></td>
              <td class="py-2">
                <?php $st = strtolower($r['status'] ?? 'pending');
                $badge = ['approved' => 'bg-green-100 text-green-700','rejected' => 'bg-red-100 text-red-700','pending' => 'bg-yellow-100 text-yellow-700'][$st] ?? 'bg-gray-100 text-gray-700'; ?>
                <span class="px-2 py-0.5 rounded-full <?php echo $badge; ?>"><?php echo ucfirst($st); ?></span>
              </td>
              <td class="py-2 text-gray-500"><?php echo htmlspecialchars(date('M d, Y', strtotime($r['applied_date']))); ?></td>
              <td class="py-2 text-right">
                <?php if (($r['status'] ?? 'pending') === 'pending'): ?>
                    <form method="post" class="inline">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
                      <input type="hidden" name="leave_id" value="<?php echo (int)$r['leave_id']; ?>">
                      <input type="text" name="notes" placeholder="Notes (optional)" class="border rounded px-2 py-1 text-xs" />
                      <button name="action" value="approve" class="px-2 py-1 text-xs rounded bg-green-600 text-white hover:bg-green-700">Approve</button>
                    </form>
                    <form method="post" class="inline ml-1">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
                      <input type="hidden" name="leave_id" value="<?php echo (int)$r['leave_id']; ?>">
                      <input type="text" name="notes" placeholder="Notes (optional)" class="border rounded px-2 py-1 text-xs" />
                      <button name="action" value="reject" class="px-2 py-1 text-xs rounded bg-red-600 text-white hover:bg-red-700">Reject</button>
                    </form>
                <?php else: ?>
                    <span class="text-gray-400 text-xs">No actions</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php $pages = (int)ceil($total / $per); if ($pages > 1): ?>
    <div class="flex justify-between items-center mt-4 text-sm">
      <div>Page <?php echo $page; ?> of <?php echo $pages; ?></div>
      <div class="space-x-2">
        <?php if ($page > 1): ?><a class="px-3 py-1 border rounded" href="?page=<?php echo $page-1; ?>">Prev</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="px-3 py-1 border rounded" href="?page=<?php echo $page+1; ?>">Next</a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</main>
<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>

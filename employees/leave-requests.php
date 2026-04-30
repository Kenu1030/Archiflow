<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['user_type'] ?? null) !== 'employee') {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../backend/connection/connect.php';
$pdo = getDB();
if (!$pdo) { http_response_code(500); echo 'DB error'; exit; }

// CSRF token
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_token'];

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { header('Location: ../login.php'); exit; }

// Resolve employee
$empStmt = $pdo->prepare("SELECT e.*, u.first_name, u.last_name FROM employees e LEFT JOIN users u ON e.user_id = u.user_id WHERE e.user_id = ? LIMIT 1");
$empStmt->execute([$userId]);
$emp = $empStmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) { http_response_code(403); echo 'Employee record not found.'; exit; }
$employeeId = (int)$emp['employee_id'];

// Ensure leave_requests table exists and has required columns (handles legacy tables)
try { $pdo->query("SELECT 1 FROM leave_requests LIMIT 1"); }
catch (Throwable $e) {
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
}
// Backfill missing columns if table pre-existed without them
try {
  $c = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests'");
  $c->execute();
  $have = array_flip($c->fetchAll(PDO::FETCH_COLUMN));
  $alters = [];
  if (!isset($have['attachment_path'])) { $alters[] = 'ADD COLUMN attachment_path VARCHAR(255) DEFAULT NULL'; }
  if (!isset($have['status'])) { $alters[] = "ADD COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'"; }
  if (!isset($have['applied_date'])) { $alters[] = 'ADD COLUMN applied_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'; }
  if ($alters) {
    $pdo->exec('ALTER TABLE leave_requests ' . implode(', ', $alters));
  }
} catch (Throwable $e) { /* ignore to keep page operational */ }

// Flash (PRG)
$flash = null;
if (!empty($_SESSION['flash_message'])) { $flash = $_SESSION['flash_message']; unset($_SESSION['flash_message']); }

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) { http_response_code(400); exit('Invalid CSRF token'); }

  $leaveType = trim((string)($_POST['leave_type'] ?? ''));
  $startDate = trim((string)($_POST['start_date'] ?? ''));
  $endDate   = trim((string)($_POST['end_date'] ?? ''));
  $reason    = trim((string)($_POST['reason'] ?? ''));

  // Basic validation
  $errors = [];
  if ($startDate === '' || $endDate === '') { $errors[] = 'Start and end dates are required.'; }
  elseif (strtotime($startDate) === false || strtotime($endDate) === false) { $errors[] = 'Invalid date format.'; }
  elseif (strtotime($endDate) < strtotime($startDate)) { $errors[] = 'End date cannot be before start date.'; }

  $attachPath = null;
  if (!empty($_FILES['attachment']['name']) && is_uploaded_file($_FILES['attachment']['tmp_name'])) {
    $dir = __DIR__ . '/../uploads/leave_attachments';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $orig = basename($_FILES['attachment']['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['pdf','jpg','jpeg','png'];
    if (!in_array($ext, $allowed, true)) { $errors[] = 'Attachment must be PDF or image.'; }
    if (($_FILES['attachment']['size'] ?? 0) > 5 * 1024 * 1024) { $errors[] = 'Attachment size must be <= 5MB.'; }
    if (!$errors) {
      $fname = 'leave_' . $employeeId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
      $dest = $dir . '/' . $fname;
      if (@move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
        $attachPath = 'uploads/leave_attachments/' . $fname;
      }
    }
  }

  if (empty($errors)) {
    $ins = $pdo->prepare("INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, reason, attachment_path) VALUES (?,?,?,?,?,?)");
    $ins->execute([$employeeId, $leaveType ?: null, $startDate, $endDate, $reason ?: null, $attachPath]);

    // Optional: notify HR users if a notifications or roles table is present (skipped due to unknown schema)

    $_SESSION['flash_message'] = 'Your leave request has been submitted and is pending HR review.';
    header('Location: leave-requests.php');
    exit;
  } else {
    $_SESSION['flash_message'] = implode(' ', $errors);
    header('Location: leave-requests.php');
    exit;
  }
}

// Fetch recent leave requests
$stmt = $pdo->prepare("SELECT leave_id, leave_type, start_date, end_date, status, applied_date, reason, attachment_path FROM leave_requests WHERE employee_id=? ORDER BY applied_date DESC LIMIT 20");
$stmt->execute([$employeeId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$HIDE_FOOTER = true;
include_once __DIR__ . '/../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-8">
  <div class="max-w-full px-4">
    <div class="flex items-center justify-between">
      <div class="flex items-center space-x-3">
        <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center"><i class="fas fa-file-medical text-white"></i></div>
        <div>
          <h1 class="text-2xl font-semibold">Leave Requests</h1>
          <p class="text-white/70">Submit and track your leave applications</p>
        </div>
      </div>
    </div>
  </div>
</section>
<main class="max-w-full px-4 -mt-6">
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <?php if ($flash): ?><div class="mb-4 p-3 rounded bg-blue-50 text-blue-700 text-sm"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>
    <h2 class="font-semibold mb-3">Apply for Leave</h2>
    <form method="post" enctype="multipart/form-data" id="leaveForm" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
      <div>
        <label class="block text-sm text-gray-600 mb-1">Leave Type</label>
        <select name="leave_type" class="w-full border rounded p-2">
          <option value="">Select type</option>
          <option value="sick">Sick Leave</option>
          <option value="vacation">Vacation Leave</option>
          <option value="emergency">Emergency Leave</option>
          <option value="unpaid">Unpaid Leave</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div>
        <label class="block text-sm text-gray-600 mb-1">Start Date</label>
        <input type="date" name="start_date" class="w-full border rounded p-2" required />
      </div>
      <div>
        <label class="block text-sm text-gray-600 mb-1">End Date</label>
        <input type="date" name="end_date" class="w-full border rounded p-2" required />
      </div>
      <div class="md:col-span-2">
        <label class="block text-sm text-gray-600 mb-1">Reason</label>
        <textarea name="reason" class="w-full border rounded p-2" rows="3" placeholder="Optional"></textarea>
      </div>
      <div class="md:col-span-2">
        <label class="block text-sm text-gray-600 mb-1">Attachment (optional)</label>
        <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png" />
        <div class="text-xs text-gray-500 mt-1">Max 5MB; PDF or image.</div>
      </div>
      <div class="md:col-span-2 flex justify-end">
        <button id="submitBtn" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Submit Request</button>
      </div>
    </form>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-6">
    <h2 class="font-semibold mb-3">Recent Requests</h2>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500">
            <th class="py-2">Applied</th>
            <th class="py-2">Type</hth>
            <th class="py-2">Start</th>
            <th class="py-2">End</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="6" class="py-3 text-center text-gray-500">No leave requests yet.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr class="border-t">
              <td class="py-2 text-gray-500"><?php echo htmlspecialchars(date('Y-m-d', strtotime($r['applied_date']))); ?></td>
              <td class="py-2"><?php echo htmlspecialchars($r['leave_type'] ?? '—'); ?></td>
              <td class="py-2 text-gray-500"><?php echo htmlspecialchars($r['start_date']); ?></td>
              <td class="py-2 text-gray-500"><?php echo htmlspecialchars($r['end_date']); ?></td>
              <td class="py-2">
                <?php $st = strtolower($r['status'] ?? 'pending');
                $badge = ['approved' => 'bg-green-100 text-green-700','rejected' => 'bg-red-100 text-red-700','pending' => 'bg-yellow-100 text-yellow-700'][$st] ?? 'bg-gray-100 text-gray-700'; ?>
                <span class="px-2 py-0.5 rounded-full <?php echo $badge; ?>"><?php echo ucfirst($st); ?></span>
              </td>
              <td class="py-2 text-right">
                <button class="view-leave px-3 py-1 text-xs rounded bg-gray-800 text-white hover:bg-gray-700"
                  data-id="<?php echo (int)$r['leave_id']; ?>"
                  data-type="<?php echo htmlspecialchars($r['leave_type'] ?? ''); ?>"
                  data-start="<?php echo htmlspecialchars($r['start_date']); ?>"
                  data-end="<?php echo htmlspecialchars($r['end_date']); ?>"
                  data-status="<?php echo htmlspecialchars($r['status']); ?>"
                  data-reason="<?php echo htmlspecialchars($r['reason'] ?? ''); ?>"
                  data-attachment="<?php echo htmlspecialchars($r['attachment_path'] ?? ''); ?>"
                >View</button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- Modal -->
<div id="leaveModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
  <div class="bg-white rounded-xl shadow-lg w-full max-w-md p-5 relative">
    <button id="leaveModalClose" class="absolute top-3 right-3 text-gray-500 hover:text-gray-700" aria-label="Close">
      <i class="fas fa-times"></i>
    </button>
    <h3 class="text-lg font-semibold mb-3">Leave Details</h3>
    <div class="space-y-2 text-sm">
      <div><span class="text-gray-500">Type:</span> <span id="mType">—</span></div>
      <div><span class="text-gray-500">From:</span> <span id="mStart">—</span></div>
      <div><span class="text-gray-500">To:</span> <span id="mEnd">—</span></div>
      <div><span class="text-gray-500">Status:</span> <span id="mStatus" class="capitalize">—</span></div>
      <div><span class="text-gray-500">Reason:</span>
        <div id="mReason" class="mt-1 whitespace-pre-wrap">—</div>
      </div>
      <div><span class="text-gray-500">Attachment:</span> <a id="mAttachment" href="#" target="_blank" class="text-blue-600 hover:underline">—</a></div>
    </div>
    <div class="mt-4 flex justify-end">
      <button id="leaveModalClose2" class="px-4 py-2 rounded bg-gray-800 text-white hover:bg-gray-700">Close</button>
    </div>
  </div>
  <button id="leaveModalBackdrop" class="absolute inset-0 w-full h-full" aria-hidden="true"></button>
</div>

<script>
  (function(){
    var form = document.getElementById('leaveForm');
    var btn = document.getElementById('submitBtn');
    if (form) {
      form.addEventListener('submit', function(){ if (btn) { btn.disabled = true; btn.textContent = 'Submitting...'; } });
    }

    function openModal(){ modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function closeModal(){ modal.classList.add('hidden'); modal.classList.remove('flex'); }

    var modal = document.getElementById('leaveModal');
    var close1 = document.getElementById('leaveModalClose');
    var close2 = document.getElementById('leaveModalClose2');
    var backdrop = document.getElementById('leaveModalBackdrop');
    [close1, close2, backdrop].forEach(function(el){ if (el) el.addEventListener('click', closeModal); });

    document.querySelectorAll('.view-leave').forEach(function(btn){
      btn.addEventListener('click', function(){
        document.getElementById('mType').textContent = btn.getAttribute('data-type') || '—';
        document.getElementById('mStart').textContent = btn.getAttribute('data-start') || '—';
        document.getElementById('mEnd').textContent = btn.getAttribute('data-end') || '—';
        document.getElementById('mStatus').textContent = btn.getAttribute('data-status') || '—';
        var rsn = btn.getAttribute('data-reason') || '';
        document.getElementById('mReason').textContent = rsn || '—';
        var att = btn.getAttribute('data-attachment') || '';
        var a = document.getElementById('mAttachment');
        if (att) { a.textContent = 'Open'; a.href = att; a.classList.remove('pointer-events-none','text-gray-400'); }
        else { a.textContent = '—'; a.href = '#'; a.classList.add('pointer-events-none','text-gray-400'); }
        openModal();
      });
    });
  })();
</script>

<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>

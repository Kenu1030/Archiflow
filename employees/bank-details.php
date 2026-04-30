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

// Detect bank columns
$hasCol = function(string $col) use ($pdo): bool {
  $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = ?");
  $q->execute([$col]);
  return ((int)$q->fetchColumn()) > 0;
};
$hasBankName = $hasCol('bank_name');
$hasAcctName = $hasCol('account_name');
$hasAcctNum  = $hasCol('account_number');

// Ensure change request table exists
try {
  $pdo->query("SELECT 1 FROM employee_bank_change_requests LIMIT 1");
} catch (Throwable $e) {
  $pdo->exec("CREATE TABLE IF NOT EXISTS employee_bank_change_requests (
    request_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT(10) UNSIGNED NOT NULL,
    requested_bank_name VARCHAR(100) DEFAULT NULL,
    requested_account_name VARCHAR(150) DEFAULT NULL,
    requested_account_number VARCHAR(50) DEFAULT NULL,
    attachment_path VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id),
    KEY idx_emp (employee_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

// Handle submission
$flash = null;
// Read flash from session (Post/Redirect/Get)
if (!empty($_SESSION['flash_message'])) {
  $flash = $_SESSION['flash_message'];
  unset($_SESSION['flash_message']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) { http_response_code(400); exit('Invalid CSRF token'); }
  $reqBank = trim((string)($_POST['requested_bank_name'] ?? ''));
  $reqAcctName = trim((string)($_POST['requested_account_name'] ?? ''));
  $reqAcctNum = trim((string)($_POST['requested_account_number'] ?? ''));
  $attachPath = null;
  // Optional file upload
  if (!empty($_FILES['attachment']['name']) && is_uploaded_file($_FILES['attachment']['tmp_name'])) {
    $dir = __DIR__ . '/../uploads/bank_changes';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $orig = basename($_FILES['attachment']['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['pdf','jpg','jpeg','png'];
    if (in_array($ext, $allowed, true) && $_FILES['attachment']['size'] <= 5 * 1024 * 1024) {
      $fname = 'req_' . $employeeId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
      $dest = $dir . '/' . $fname;
      if (@move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
        $attachPath = 'uploads/bank_changes/' . $fname;
      }
    }
  }
  $ins = $pdo->prepare("INSERT INTO employee_bank_change_requests (employee_id, requested_bank_name, requested_account_name, requested_account_number, attachment_path) VALUES (?,?,?,?,?)");
  $ins->execute([$employeeId, $reqBank ?: null, $reqAcctName ?: null, $reqAcctNum ?: null, $attachPath]);
  // Set flash and redirect to prevent duplicate submissions on refresh
  $_SESSION['flash_message'] = 'Your change request has been submitted. HR will review it shortly.';
  header('Location: bank-details.php');
  exit;
}

// Fetch recent requests
$reqs = $pdo->prepare("SELECT request_id, requested_bank_name, requested_account_name, requested_account_number, attachment_path, status, submitted_at FROM employee_bank_change_requests WHERE employee_id=? ORDER BY submitted_at DESC LIMIT 10");
$reqs->execute([$employeeId]);
$recent = $reqs->fetchAll(PDO::FETCH_ASSOC);

function mask_account(?string $acct): string {
  if (!$acct) return '—';
  $len = strlen($acct);
  if ($len <= 4) return str_repeat('*', max(0,$len-1)) . substr($acct,-1);
  return str_repeat('*', $len - 4) . substr($acct, -4);
}

$HIDE_FOOTER = true;
include_once __DIR__ . '/../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-8">
  <div class="max-w-full px-4">
    <div class="flex items-center justify-between">
      <div class="flex items-center space-x-3">
        <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center"><i class="fas fa-university text-white"></i></div>
        <div>
          <h1 class="text-2xl font-semibold">Payment Details</h1>
          <p class="text-white/70">View your registered bank info and request changes</p>
        </div>
      </div>
    </div>
  </div>
</section>
<main class="max-w-full px-4 -mt-6">
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <?php if ($flash): ?><div class="mb-4 p-3 rounded bg-green-50 text-green-700 text-sm"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <div>
        <h2 class="font-semibold mb-2">Current (as recorded by HR)</h2>
        <ul class="text-sm space-y-1">
          <li><span class="text-gray-500">Bank Name:</span> <?php echo $hasBankName ? htmlspecialchars($emp['bank_name'] ?? '—') : '—'; ?></li>
          <li><span class="text-gray-500">Account Name:</span> <?php echo $hasAcctName ? htmlspecialchars($emp['account_name'] ?? trim(($emp['first_name']??'') . ' ' . ($emp['last_name']??''))) : '—'; ?></li>
          <li><span class="text-gray-500">Account Number:</span> <?php echo $hasAcctNum ? htmlspecialchars(mask_account($emp['account_number'] ?? '')) : '—'; ?></li>
        </ul>
        <?php if (!$hasBankName && !$hasAcctName && !$hasAcctNum): ?>
          <div class="text-xs text-gray-500 mt-2">Note: Bank fields are not yet enabled in the system. HR needs to add them first.</div>
        <?php endif; ?>
      </div>
      <div>
        <h2 class="font-semibold mb-2">Request a Change</h2>
        <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 gap-3" id="bankChangeForm">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
          <div>
            <label class="block text-sm text-gray-600 mb-1">Bank Name</label>
            <input name="requested_bank_name" class="w-full border rounded p-2" placeholder="e.g., BPI" />
          </div>
          <div>
            <label class="block text-sm text-gray-600 mb-1">Account Name</label>
            <input name="requested_account_name" class="w-full border rounded p-2" placeholder="Your full name as on bank" />
          </div>
          <div>
            <label class="block text-sm text-gray-600 mb-1">Account Number</label>
            <input name="requested_account_number" class="w-full border rounded p-2" placeholder="Digits only" />
          </div>
          <div>
            <label class="block text-sm text-gray-600 mb-1">Attachment (optional)</label>
            <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png" />
            <div class="text-xs text-gray-500 mt-1">Bank certificate or ID; max 5MB.</div>
          </div>
          <div class="flex justify-end">
            <button id="submitBtn" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Submit Request</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-6">
    <h2 class="font-semibold mb-3">Recent Requests</h2>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500">
            <th class="py-2">Submitted</th>
            <th class="py-2">Bank Name</th>
            <th class="py-2">Account Name</th>
            <th class="py-2">Account Number</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recent)): ?>
            <tr><td colspan="6" class="py-3 text-center text-gray-500">No requests yet.</td></tr>
          <?php else: foreach ($recent as $r): ?>
            <tr class="border-t">
              <td class="py-2 text-gray-500"><?php echo htmlspecialchars($r['submitted_at']); ?></td>
              <td class="py-2"><?php echo htmlspecialchars($r['requested_bank_name'] ?? '—'); ?></td>
              <td class="py-2"><?php echo htmlspecialchars($r['requested_account_name'] ?? '—'); ?></td>
              <td class="py-2"><?php echo htmlspecialchars(mask_account($r['requested_account_number'] ?? '')); ?></td>
              <td class="py-2 capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
              <td class="py-2 text-right">
                <button
                  class="view-req px-3 py-1 text-xs rounded bg-gray-800 text-white hover:bg-gray-700"
                  data-id="<?php echo (int)$r['request_id']; ?>"
                  data-submitted="<?php echo htmlspecialchars($r['submitted_at']); ?>"
                  data-bank="<?php echo htmlspecialchars($r['requested_bank_name'] ?? ''); ?>"
                  data-acct-name="<?php echo htmlspecialchars($r['requested_account_name'] ?? ''); ?>"
                  data-acct-num="<?php echo htmlspecialchars($r['requested_account_number'] ?? ''); ?>"
                  data-status="<?php echo htmlspecialchars($r['status']); ?>"
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
<div id="reqModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
  <div class="bg-white rounded-xl shadow-lg w-full max-w-md p-5 relative">
    <button id="reqModalClose" class="absolute top-3 right-3 text-gray-500 hover:text-gray-700" aria-label="Close">
      <i class="fas fa-times"></i>
    </button>
    <h3 class="text-lg font-semibold mb-3">Request Details</h3>
    <div class="space-y-2 text-sm">
      <div><span class="text-gray-500">Submitted:</span> <span id="mSubmitted">—</span></div>
      <div><span class="text-gray-500">Status:</span> <span id="mStatus" class="capitalize">—</span></div>
      <div><span class="text-gray-500">Bank Name:</span> <span id="mBank">—</span></div>
      <div><span class="text-gray-500">Account Name:</span> <span id="mAcctName">—</span></div>
      <div><span class="text-gray-500">Account Number:</span> <span id="mAcctNum">—</span></div>
      <div><span class="text-gray-500">Attachment:</span> <a id="mAttachment" href="#" target="_blank" class="text-blue-600 hover:underline">—</a></div>
    </div>
    <div class="mt-4 flex justify-end">
      <button id="reqModalClose2" class="px-4 py-2 rounded bg-gray-800 text-white hover:bg-gray-700">Close</button>
    </div>
  </div>
  <button id="reqModalBackdrop" class="absolute inset-0 w-full h-full" aria-hidden="true"></button>
  
</div>

<script>
  (function(){
    function maskAcct(acct){
      if (!acct) return '—';
      var s = (acct+'');
      if (s.length <= 4) return '*'.repeat(Math.max(0, s.length-1)) + s.slice(-1);
      return '*'.repeat(s.length - 4) + s.slice(-4);
    }
    var modal = document.getElementById('reqModal');
    var close1 = document.getElementById('reqModalClose');
    var close2 = document.getElementById('reqModalClose2');
    var backdrop = document.getElementById('reqModalBackdrop');
    function openModal(){ modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function closeModal(){ modal.classList.add('hidden'); modal.classList.remove('flex'); }
    [close1, close2, backdrop].forEach(function(el){ if (el) el.addEventListener('click', closeModal); });
    document.querySelectorAll('.view-req').forEach(function(btn){
      btn.addEventListener('click', function(){
        document.getElementById('mSubmitted').textContent = btn.getAttribute('data-submitted') || '—';
        document.getElementById('mStatus').textContent = btn.getAttribute('data-status') || '—';
        document.getElementById('mBank').textContent = btn.getAttribute('data-bank') || '—';
        document.getElementById('mAcctName').textContent = btn.getAttribute('data-acct-name') || '—';
        document.getElementById('mAcctNum').textContent = maskAcct(btn.getAttribute('data-acct-num') || '');
        var att = btn.getAttribute('data-attachment') || '';
        var a = document.getElementById('mAttachment');
        if (att) { a.textContent = 'Open'; a.href = att; a.classList.remove('pointer-events-none','text-gray-400'); }
        else { a.textContent = '—'; a.href = '#'; a.classList.add('pointer-events-none','text-gray-400'); }
        openModal();
      });
    });
  })();
</script>

<script>
  // Prevent double submissions by disabling the submit button
  (function(){
    var form = document.getElementById('bankChangeForm');
    if (!form) return;
    var btn = document.getElementById('submitBtn');
    form.addEventListener('submit', function(){
      if (btn) {
        btn.disabled = true;
        btn.textContent = 'Submitting...';
      }
    });
  })();
</script>

<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>

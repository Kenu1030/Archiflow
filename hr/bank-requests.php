<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['user_type'] ?? null) !== 'hr') {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../backend/connection/connect.php';
$pdo = getDB();
if (!$pdo) { http_response_code(500); echo 'DB error'; exit; }

// CSRF token
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_token'];

// Ensure table exists (if employee page was never hit)
try { $pdo->query("SELECT 1 FROM employee_bank_change_requests LIMIT 1"); }
catch (Throwable $e) {
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

// Utility
$hasCol = function(string $col) use ($pdo): bool {
  $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = ?");
  $q->execute([$col]);
  return ((int)$q->fetchColumn()) > 0;
};
$hasBankName = $hasCol('bank_name');
$hasAcctName = $hasCol('account_name');
$hasAcctNum  = $hasCol('account_number');

// Flash
$flash = $_SESSION['flash_message'] ?? null; unset($_SESSION['flash_message']);

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) { http_response_code(400); exit('Invalid CSRF token'); }
  $rid = (int)($_POST['request_id'] ?? 0);
  $action = $_POST['action'] ?? '';
  // Load request
  $rstmt = $pdo->prepare("SELECT * FROM employee_bank_change_requests WHERE request_id=?");
  $rstmt->execute([$rid]);
  $req = $rstmt->fetch(PDO::FETCH_ASSOC);
  if ($req) {
    if ($action === 'approve') {
      // Require bank columns to apply
      if ($hasBankName || $hasAcctName || $hasAcctNum) {
        $sets = []; $vals = [];
        if ($hasBankName && !empty($req['requested_bank_name'])) { $sets[] = 'bank_name=?'; $vals[] = $req['requested_bank_name']; }
        if ($hasAcctName && !empty($req['requested_account_name'])) { $sets[] = 'account_name=?'; $vals[] = $req['requested_account_name']; }
        if ($hasAcctNum && !empty($req['requested_account_number'])) { $sets[] = 'account_number=?'; $vals[] = $req['requested_account_number']; }
        if (!empty($sets)) {
          $vals[] = (int)$req['employee_id'];
          $upd = $pdo->prepare('UPDATE employees SET ' . implode(',', $sets) . ' WHERE employee_id=?');
          $upd->execute($vals);
        }
        $pdo->prepare("UPDATE employee_bank_change_requests SET status='approved' WHERE request_id=?")->execute([$rid]);
        $_SESSION['flash_message'] = 'Request approved and bank details applied.';
      } else {
        $_SESSION['flash_message'] = 'Employees table has no bank fields. Add them first from HR → Employee Profile.';
      }
    } elseif ($action === 'reject') {
      $pdo->prepare("UPDATE employee_bank_change_requests SET status='rejected' WHERE request_id=?")->execute([$rid]);
      $_SESSION['flash_message'] = 'Request rejected.';
    }
  }
  header('Location: bank-requests.php');
  exit;
}

// Filters
$status = $_GET['status'] ?? 'pending';
$valid = ['pending','approved','rejected','all'];
if (!in_array($status, $valid, true)) { $status = 'pending'; }
$params = [];
$sql = "SELECT r.*, e.employee_code, e.employee_id, u.first_name, u.last_name
        FROM employee_bank_change_requests r
        JOIN employees e ON r.employee_id = e.employee_id
        LEFT JOIN users u ON e.user_id = u.user_id";
if ($status !== 'all') { $sql .= " WHERE r.status = ?"; $params[] = $status; }
$sql .= " ORDER BY r.submitted_at DESC, r.request_id DESC LIMIT 200";
$rows = $pdo->prepare($sql); $rows->execute($params); $list = $rows->fetchAll(PDO::FETCH_ASSOC);

$HIDE_FOOTER = true;
include_once __DIR__ . '/../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-8">
  <div class="max-w-full px-4">
    <div class="flex items-center space-x-3">
      <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center"><i class="fas fa-university"></i></div>
      <div>
        <h1 class="text-2xl font-semibold">Bank Change Requests</h1>
        <p class="text-white/70">Review and approve employee banking updates</p>
      </div>
    </div>
  </div>
</section>
<main class="max-w-full px-4 -mt-6">
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <?php if ($flash): ?><div class="mb-4 p-3 rounded bg-green-50 text-green-700 text-sm"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>
    <div class="flex items-center justify-between mb-3">
      <div class="space-x-2 text-sm">
        <?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All'] as $key=>$label): ?>
          <a class="px-3 py-1 rounded <?php echo $status===$key?'bg-gray-900 text-white':'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>" href="?status=<?php echo $key; ?>"><?php echo $label; ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500">
            <th class="py-2">Employee</th>
            <th class="py-2">Submitted</th>
            <th class="py-2">Bank</th>
            <th class="py-2">Account Name</th>
            <th class="py-2">Account Number</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($list)): ?>
            <tr><td colspan="7" class="py-4 text-center text-gray-500">No requests.</td></tr>
          <?php else: foreach ($list as $r): $name = trim(($r['first_name'].' '.$r['last_name'])); if ($name==='') $name='Employee #'.(int)$r['employee_id']; ?>
            <tr class="border-t">
              <td class="py-2"><?php echo htmlspecialchars($name . (!empty($r['employee_code'])? ' ('.$r['employee_code'].')':'')); ?></td>
              <td class="py-2 text-gray-500"><?php echo htmlspecialchars($r['submitted_at']); ?></td>
              <td class="py-2"><?php echo htmlspecialchars($r['requested_bank_name'] ?? ''); ?></td>
              <td class="py-2"><?php echo htmlspecialchars($r['requested_account_name'] ?? ''); ?></td>
              <td class="py-2"><?php echo htmlspecialchars($r['requested_account_number'] ?? ''); ?></td>
              <td class="py-2 capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
              <td class="py-2 text-right space-x-2">
                <button class="view-btn px-2 py-1 rounded bg-gray-800 text-white text-xs" data-json='<?php echo json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>'>View</button>
                <?php if ($r['status'] === 'pending'): ?>
                  <form method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
                    <input type="hidden" name="request_id" value="<?php echo (int)$r['request_id']; ?>">
                    <button name="action" value="approve" class="px-2 py-1 rounded bg-green-600 text-white text-xs hover:bg-green-700">Approve</button>
                  </form>
                  <form method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
                    <input type="hidden" name="request_id" value="<?php echo (int)$r['request_id']; ?>">
                    <button name="action" value="reject" class="px-2 py-1 rounded bg-red-600 text-white text-xs hover:bg-red-700">Reject</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- Modal -->
<div id="viewModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
  <div class="bg-white rounded-xl shadow-lg w-full max-w-md p-5 relative">
    <button id="modalClose" class="absolute top-3 right-3 text-gray-500 hover:text-gray-700" aria-label="Close">
      <i class="fas fa-times"></i>
    </button>
    <h3 class="text-lg font-semibold mb-3">Request Details</h3>
    <div class="space-y-2 text-sm">
      <div><span class="text-gray-500">Employee:</span> <span id="mEmp">—</span></div>
      <div><span class="text-gray-500">Submitted:</span> <span id="mSubmitted">—</span></div>
      <div><span class="text-gray-500">Status:</span> <span id="mStatus" class="capitalize">—</span></div>
      <div><span class="text-gray-500">Bank:</span> <span id="mBank">—</span></div>
      <div><span class="text-gray-500">Account Name:</span> <span id="mAcctName">—</span></div>
      <div><span class="text-gray-500">Account Number:</span> <span id="mAcctNum">—</span></div>
      <div><span class="text-gray-500">Attachment:</span> <a id="mAttachment" href="#" target="_blank" class="text-blue-600 hover:underline">—</a></div>
    </div>
    <div class="mt-4 flex justify-end">
      <button id="modalClose2" class="px-4 py-2 rounded bg-gray-800 text-white hover:bg-gray-700">Close</button>
    </div>
  </div>
  <button id="modalBackdrop" class="absolute inset-0 w-full h-full" aria-hidden="true"></button>
</div>

<script>
(function(){
  var modal = document.getElementById('viewModal');
  function openModal(){ modal.classList.remove('hidden'); modal.classList.add('flex'); }
  function closeModal(){ modal.classList.add('hidden'); modal.classList.remove('flex'); }
  ['modalClose','modalClose2','modalBackdrop'].forEach(function(id){ var el=document.getElementById(id); if(el) el.addEventListener('click', closeModal); });
  document.querySelectorAll('.view-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var r = JSON.parse(btn.getAttribute('data-json'));
      var emp = (r.first_name ? r.first_name : '') + ' ' + (r.last_name ? r.last_name : '');
      if (emp.trim() === '') emp = 'Employee #' + (r.employee_id || '');
      document.getElementById('mEmp').textContent = emp.trim();
      document.getElementById('mSubmitted').textContent = r.submitted_at || '—';
      document.getElementById('mStatus').textContent = r.status || '—';
      document.getElementById('mBank').textContent = r.requested_bank_name || '—';
      document.getElementById('mAcctName').textContent = r.requested_account_name || '—';
      document.getElementById('mAcctNum').textContent = r.requested_account_number || '—';
      var a = document.getElementById('mAttachment');
      if (r.attachment_path) { a.textContent='Open'; a.href=r.attachment_path; a.classList.remove('pointer-events-none','text-gray-400'); }
      else { a.textContent='—'; a.href='#'; a.classList.add('pointer-events-none','text-gray-400'); }
      openModal();
    });
  });
})();
</script>

<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>

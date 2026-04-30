<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['user_type'] ?? null) !== 'hr') {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../backend/connection/connect.php';
$pdo = getDB();
if (!$pdo) { http_response_code(500); echo 'DB error'; exit; }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: employees.php'); exit; }

$CSRF = $_SESSION['csrf_token'] ?? null; if (!$CSRF) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); $CSRF = $_SESSION['csrf_token']; }

// Detect bank/payment columns
$hasCol = function(string $col) use ($pdo): bool {
  $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = ?");
  $q->execute([$col]);
  return ((int)$q->fetchColumn()) > 0;
};
$hasBankName = $hasCol('bank_name');
$hasAcctName = $hasCol('account_name');
$hasAcctNum  = $hasCol('account_number');
// Statutory fields
$hasSSSNo = $hasCol('sss_no');
$hasPHNo = $hasCol('philhealth_no');
$hasHDMFNo = $hasCol('hdmf_no');
$hasExSSS = $hasCol('exclude_sss');
$hasExPH = $hasCol('exclude_philhealth');
$hasExHDMF = $hasCol('exclude_pagibig');

// Update payment details
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) { http_response_code(400); exit('Invalid CSRF token'); }
  $section = $_POST['section'] ?? '';
  // One-click migration to add bank fields if missing
  if (isset($_POST['add_bank_fields'])) {
    try {
      if (!$hasBankName) { $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS bank_name VARCHAR(100) DEFAULT NULL"); }
      if (!$hasAcctName) { $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS account_name VARCHAR(150) DEFAULT NULL"); }
      if (!$hasAcctNum)  { $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS account_number VARCHAR(50) DEFAULT NULL"); }
    } catch (Throwable $e) {
      // Fallback for servers without IF NOT EXISTS support: add only if still missing
      try {
        if (!$hasBankName && !$hasCol('bank_name')) { $pdo->exec("ALTER TABLE employees ADD COLUMN bank_name VARCHAR(100) DEFAULT NULL"); }
        if (!$hasAcctName && !$hasCol('account_name')) { $pdo->exec("ALTER TABLE employees ADD COLUMN account_name VARCHAR(150) DEFAULT NULL"); }
        if (!$hasAcctNum  && !$hasCol('account_number')) { $pdo->exec("ALTER TABLE employees ADD COLUMN account_number VARCHAR(50) DEFAULT NULL"); }
      } catch (Throwable $e2) {
        http_response_code(500); exit('Failed to add bank fields. Please use the SQL in database/add_employee_bank_fields.sql');
      }
    }
    header('Location: employee-profile.php?id=' . $id . '&saved=1&msg=' . urlencode('Bank fields added'));
    exit;
  }
  // One-click migration to add statutory fields if missing
  if (isset($_POST['add_statutory_fields'])) {
    try {
      if (!$hasSSSNo) { $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS sss_no VARCHAR(50) DEFAULT NULL"); }
      if (!$hasPHNo) { $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS philhealth_no VARCHAR(50) DEFAULT NULL"); }
      if (!$hasHDMFNo) { $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS hdmf_no VARCHAR(50) DEFAULT NULL"); }
      if (!$hasExSSS) { $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS exclude_sss TINYINT(1) NOT NULL DEFAULT 0"); }
      if (!$hasExPH) { $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS exclude_philhealth TINYINT(1) NOT NULL DEFAULT 0"); }
      if (!$hasExHDMF) { $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS exclude_pagibig TINYINT(1) NOT NULL DEFAULT 0"); }
    } catch (Throwable $e) {
      // Fallback for servers without IF NOT EXISTS support
      try {
        if (!$hasSSSNo && !$hasCol('sss_no')) { $pdo->exec("ALTER TABLE employees ADD COLUMN sss_no VARCHAR(50) DEFAULT NULL"); }
        if (!$hasPHNo && !$hasCol('philhealth_no')) { $pdo->exec("ALTER TABLE employees ADD COLUMN philhealth_no VARCHAR(50) DEFAULT NULL"); }
        if (!$hasHDMFNo && !$hasCol('hdmf_no')) { $pdo->exec("ALTER TABLE employees ADD COLUMN hdmf_no VARCHAR(50) DEFAULT NULL"); }
        if (!$hasExSSS && !$hasCol('exclude_sss')) { $pdo->exec("ALTER TABLE employees ADD COLUMN exclude_sss TINYINT(1) NOT NULL DEFAULT 0"); }
        if (!$hasExPH && !$hasCol('exclude_philhealth')) { $pdo->exec("ALTER TABLE employees ADD COLUMN exclude_philhealth TINYINT(1) NOT NULL DEFAULT 0"); }
        if (!$hasExHDMF && !$hasCol('exclude_pagibig')) { $pdo->exec("ALTER TABLE employees ADD COLUMN exclude_pagibig TINYINT(1) NOT NULL DEFAULT 0"); }
      } catch (Throwable $e2) {
        http_response_code(500); exit('Failed to add statutory fields.');
      }
    }
    header('Location: employee-profile.php?id=' . $id . '&saved=1&msg=' . urlencode('Statutory fields added'));
    exit;
  }
  $sets = [];
  $vals = [];
  // Only update fields for the submitted section to avoid wiping other values
  if ($section === 'bank' || $section === 'all') {
    if ($hasBankName && array_key_exists('bank_name', $_POST)) { $sets[] = 'bank_name = ?'; $vals[] = trim((string)$_POST['bank_name']); }
    if ($hasAcctName && array_key_exists('account_name', $_POST)) { $sets[] = 'account_name = ?'; $vals[] = trim((string)$_POST['account_name']); }
    if ($hasAcctNum && array_key_exists('account_number', $_POST)) { $sets[] = 'account_number = ?'; $vals[] = trim((string)$_POST['account_number']); }
  }
  if ($section === 'statutory' || $section === 'all') {
    if ($hasSSSNo && array_key_exists('sss_no', $_POST)) { $sets[] = 'sss_no = ?'; $vals[] = trim((string)$_POST['sss_no']); }
    if ($hasPHNo && array_key_exists('philhealth_no', $_POST)) { $sets[] = 'philhealth_no = ?'; $vals[] = trim((string)$_POST['philhealth_no']); }
    if ($hasHDMFNo && array_key_exists('hdmf_no', $_POST)) { $sets[] = 'hdmf_no = ?'; $vals[] = trim((string)$_POST['hdmf_no']); }
    // Checkboxes: absent means 0 only for this section
    if ($hasExSSS) { $sets[] = 'exclude_sss = ?'; $vals[] = !empty($_POST['exclude_sss']) ? 1 : 0; }
    if ($hasExPH) { $sets[] = 'exclude_philhealth = ?'; $vals[] = !empty($_POST['exclude_philhealth']) ? 1 : 0; }
    if ($hasExHDMF) { $sets[] = 'exclude_pagibig = ?'; $vals[] = !empty($_POST['exclude_pagibig']) ? 1 : 0; }
  }
  if (!empty($sets)) {
    $vals[] = $id;
    $sqlUpd = 'UPDATE employees SET ' . implode(', ', $sets) . ' WHERE employee_id = ?';
    $upd = $pdo->prepare($sqlUpd);
    $upd->execute($vals);
  }
  header('Location: employee-profile.php?id=' . $id . '&saved=1&section=' . urlencode($section));
  exit;
}

$sql = "SELECT e.*, u.first_name, u.last_name, u.email, u.phone, u.address
        FROM employees e LEFT JOIN users u ON e.user_id = u.user_id WHERE e.employee_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$emp = $stmt->fetch();
if (!$emp) { header('Location: employees.php'); exit; }
// Suppress footer on this page
$HIDE_FOOTER = true;
include_once __DIR__ . '/../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-8">
  <div class="max-w-5xl mx-auto px-4">
    <div class="flex items-center space-x-3">
      <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center"><i class="fas fa-id-badge"></i></div>
      <div>
        <h1 class="text-2xl font-semibold"><?php echo htmlspecialchars(trim(($emp['first_name']??'') . ' ' . ($emp['last_name']??'')) ?: 'Employee #'.$emp['employee_id']); ?></h1>
        <p class="text-white/70">Employee Profile</p>
      </div>
    </div>
  </div>
</section>
<main class="max-w-5xl mx-auto px-4 -mt-6">
  <?php if (!empty($_GET['saved'])): ?>
    <?php
      $sec = $_GET['section'] ?? '';
      $msg = $_GET['msg'] ?? '';
      $text = 'Changes saved.';
      if ($msg !== '') { $text = $msg; }
      elseif ($sec === 'bank') { $text = 'Bank details saved.'; }
      elseif ($sec === 'statutory') { $text = 'Government IDs & contributions saved.'; }
      elseif ($sec === 'all') { $text = 'All details saved.'; }
    ?>
    <div id="saveToast" class="mb-4 bg-green-50 border border-green-200 text-green-800 rounded-lg p-3 flex items-center shadow-sm">
      <i class="fas fa-check-circle mr-2"></i>
      <span class="text-sm"><?php echo htmlspecialchars($text); ?></span>
    </div>
    <script>
      setTimeout(function(){
        var t = document.getElementById('saveToast');
        if (t) { t.style.transition = 'opacity 300ms'; t.style.opacity = '0'; setTimeout(function(){ t.remove(); }, 350); }
      }, 2500);
    </script>
  <?php endif; ?>
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <div>
        <h2 class="font-semibold mb-2">Details</h2>
        <ul class="text-sm space-y-1">
          <li><span class="text-gray-500">Employee Code:</span> <?php echo htmlspecialchars($emp['employee_code']); ?></li>
          <li><span class="text-gray-500">Position:</span> <?php echo htmlspecialchars(str_replace('_',' ', $emp['position'])); ?></li>
          <li><span class="text-gray-500">Department:</span> <?php echo htmlspecialchars($emp['department']); ?></li>
          <li><span class="text-gray-500">Hire Date:</span> <?php echo htmlspecialchars($emp['hire_date']); ?></li>
          <li><span class="text-gray-500">Status:</span> <?php echo htmlspecialchars(ucfirst($emp['status'])); ?></li>
        </ul>
      </div>
      <div>
        <h2 class="font-semibold mb-2">Contact</h2>
        <ul class="text-sm space-y-1">
          <li><span class="text-gray-500">Email:</span> <?php echo htmlspecialchars($emp['email'] ?? '—'); ?></li>
          <li><span class="text-gray-500">Phone:</span> <?php echo htmlspecialchars($emp['phone'] ?? '—'); ?></li>
          <li><span class="text-gray-500">Address:</span> <?php echo htmlspecialchars($emp['address'] ?? '—'); ?></li>
        </ul>
      </div>
    </div>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-6">
    <h2 class="font-semibold mb-4">Payment Details</h2>
    <?php if (!$hasBankName && !$hasAcctName && !$hasAcctNum): ?>
      <div class="text-sm text-gray-600 mb-3">Bank fields are not present on the employees table. You can still export payout CSV and fill bank details manually, or add these columns for full automation.</div>
      <form method="post" class="mb-4">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
        <button name="add_bank_fields" value="1" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">Add Bank Fields to Employees</button>
        <span class="text-xs text-gray-500 ml-2">One-time migration: adds bank_name, account_name, account_number</span>
      </form>
    <?php else: ?>
      <form id="bankForm" method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
        <input type="hidden" name="section" value="bank">
        <div>
          <label class="block text-sm text-gray-600 mb-1">Bank Name</label>
          <input id="bank_name" name="bank_name" value="<?php echo htmlspecialchars($emp['bank_name'] ?? ''); ?>" class="w-full border rounded p-2" <?php echo $hasBankName? '' : 'disabled'; ?> />
        </div>
        <div>
          <label class="block text-sm text-gray-600 mb-1">Account Name</label>
          <input id="account_name" name="account_name" value="<?php echo htmlspecialchars($emp['account_name'] ?? (($emp['first_name']??'') . ' ' . ($emp['last_name']??''))); ?>" class="w-full border rounded p-2" <?php echo $hasAcctName? '' : 'disabled'; ?> />
        </div>
        <div>
          <label class="block text-sm text-gray-600 mb-1">Account Number</label>
          <input id="account_number" name="account_number" value="<?php echo htmlspecialchars($emp['account_number'] ?? ''); ?>" class="w-full border rounded p-2" <?php echo $hasAcctNum? '' : 'disabled'; ?> />
        </div>
        <div class="md:col-span-3 flex justify-end">
          <button class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Save</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-6">
    <h2 class="font-semibold mb-4">Government IDs & Contributions</h2>
    <?php if (!$hasSSSNo && !$hasPHNo && !$hasHDMFNo && !$hasExSSS && !$hasExPH && !$hasExHDMF): ?>
      <div class="text-sm text-gray-600 mb-3">Statutory fields are not present on the employees table. Add them to track SSS, PhilHealth, and Pag‑IBIG IDs and per-employee inclusion flags.</div>
      <form method="post" class="mb-4">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
        <button name="add_statutory_fields" value="1" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">Add Statutory Fields</button>
        <span class="text-xs text-gray-500 ml-2">Adds sss_no, philhealth_no, hdmf_no, exclude_* flags</span>
      </form>
    <?php else: ?>
      <?php if ($hasBankName || $hasAcctName || $hasAcctNum): ?>
        <?php
          $acct = $hasAcctNum ? ($emp['account_number'] ?? '') : '';
          $acctDigits = preg_replace('/\s+/', '', (string)$acct);
          $acctMask = '';
          if ($acctDigits !== '') {
            $len = strlen($acctDigits);
            $acctMask = $len <= 4 ? substr($acctDigits, -4) : ('•••• ' . substr($acctDigits, -4));
          }
        ?>
        <div class="mb-4 bg-gray-50 border border-gray-200 rounded-lg p-4">
          <div class="text-sm font-medium text-gray-700 mb-2">Bank Details (read-only)</div>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm text-gray-700">
            <div><span class="text-gray-500">Bank:</span> <?php echo $hasBankName ? htmlspecialchars($emp['bank_name'] ?? '—') : '—'; ?></div>
            <div><span class="text-gray-500">Account Name:</span> <?php echo $hasAcctName ? htmlspecialchars($emp['account_name'] ?? '—') : '—'; ?></div>
            <div><span class="text-gray-500">Account No:</span> <?php echo $acctMask !== '' ? htmlspecialchars($acctMask) : '—'; ?></div>
          </div>
          <div class="text-xs text-gray-500 mt-2">Edit bank details above in the Payment Details section.</div>
        </div>
      <?php endif; ?>
      <form id="statForm" method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
        <input type="hidden" name="section" value="statutory">
        <div>
          <label class="block text-sm text-gray-600 mb-1">SSS Number</label>
          <input id="sss_no" name="sss_no" value="<?php echo htmlspecialchars($emp['sss_no'] ?? ''); ?>" class="w-full border rounded p-2" <?php echo $hasSSSNo? '' : 'disabled'; ?> />
        </div>
        <div>
          <label class="block text-sm text-gray-600 mb-1">PhilHealth Number</label>
          <input id="philhealth_no" name="philhealth_no" value="<?php echo htmlspecialchars($emp['philhealth_no'] ?? ''); ?>" class="w-full border rounded p-2" <?php echo $hasPHNo? '' : 'disabled'; ?> />
        </div>
        <div>
          <label class="block text-sm text-gray-600 mb-1">Pag‑IBIG (HDMF) Number</label>
          <input id="hdmf_no" name="hdmf_no" value="<?php echo htmlspecialchars($emp['hdmf_no'] ?? ''); ?>" class="w-full border rounded p-2" <?php echo $hasHDMFNo? '' : 'disabled'; ?> />
        </div>
        <div class="md:col-span-3 grid grid-cols-1 md:grid-cols-3 gap-4">
          <label class="inline-flex items-center space-x-2"><input id="exclude_sss" type="checkbox" name="exclude_sss" <?php echo !empty($emp['exclude_sss'])?'checked':''; ?> <?php echo $hasExSSS? '' : 'disabled'; ?>><span>Exclude from SSS</span></label>
          <label class="inline-flex items-center space-x-2"><input id="exclude_philhealth" type="checkbox" name="exclude_philhealth" <?php echo !empty($emp['exclude_philhealth'])?'checked':''; ?> <?php echo $hasExPH? '' : 'disabled'; ?>><span>Exclude from PhilHealth</span></label>
          <label class="inline-flex items-center space-x-2"><input id="exclude_pagibig" type="checkbox" name="exclude_pagibig" <?php echo !empty($emp['exclude_pagibig'])?'checked':''; ?> <?php echo $hasExHDMF? '' : 'disabled'; ?>><span>Exclude from Pag‑IBIG</span></label>
        </div>
        <div class="md:col-span-3 flex justify-between items-center gap-2">
          <button type="button" onclick="saveAll()" class="bg-purple-600 text-white px-4 py-2 rounded hover:bg-purple-700">Save All</button>
          <button class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Save</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <!-- Hidden form for Save All -->
  <form id="saveAllForm" method="post" class="hidden">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
    <input type="hidden" name="section" value="all">
    <input type="hidden" name="bank_name">
    <input type="hidden" name="account_name">
    <input type="hidden" name="account_number">
    <input type="hidden" name="sss_no">
    <input type="hidden" name="philhealth_no">
    <input type="hidden" name="hdmf_no">
    <input type="hidden" name="exclude_sss">
    <input type="hidden" name="exclude_philhealth">
    <input type="hidden" name="exclude_pagibig">
  </form>
</main>
<script>
function saveAll() {
  const f = document.getElementById('saveAllForm');
  // Copy bank fields (may be disabled or absent)
  const bankName = document.getElementById('bank_name');
  const accName = document.getElementById('account_name');
  const accNum = document.getElementById('account_number');
  if (bankName) f.elements['bank_name'].value = bankName.value || '';
  if (accName) f.elements['account_name'].value = accName.value || '';
  if (accNum) f.elements['account_number'].value = accNum.value || '';
  // Statutory fields
  const sss = document.getElementById('sss_no');
  const ph = document.getElementById('philhealth_no');
  const hdmf = document.getElementById('hdmf_no');
  if (sss) f.elements['sss_no'].value = sss.value || '';
  if (ph) f.elements['philhealth_no'].value = ph.value || '';
  if (hdmf) f.elements['hdmf_no'].value = hdmf.value || '';
  // Checkboxes
  const exSSS = document.getElementById('exclude_sss');
  const exPH = document.getElementById('exclude_philhealth');
  const exPI = document.getElementById('exclude_pagibig');
  f.elements['exclude_sss'].value = exSSS && exSSS.checked ? '1' : '';
  f.elements['exclude_philhealth'].value = exPH && exPH.checked ? '1' : '';
  f.elements['exclude_pagibig'].value = exPI && exPI.checked ? '1' : '';
  f.submit();
}
</script>
<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>

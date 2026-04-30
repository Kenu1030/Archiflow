<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['user_type'] ?? null) !== 'hr') {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../backend/connection/connect.php';
$pdo = getDB();
if (!$pdo) { http_response_code(500); echo 'DB error'; exit; }

$CSRF = $_SESSION['csrf_token'] ?? null; if (!$CSRF) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); $CSRF = $_SESSION['csrf_token']; }

// Helper: recompute a single payroll row by id
function recompute_payroll(PDO $pdo, int $pid): void {
  $row = $pdo->prepare("SELECT payroll_id, employee_id, month_year FROM payroll WHERE payroll_id=?");
  $row->execute([$pid]);
  $pr = $row->fetch();
  if (!$pr) { return; }
  $eid = (int)$pr['employee_id']; $month = $pr['month_year'];
  // Settings (payroll computation + statutory deductions)
  $settings = $pdo->query(
    "SELECT setting_name, setting_value FROM settings WHERE setting_name IN (
      'working_hours_per_day','overtime_rate',
      'philhealth_enabled','philhealth_rate','philhealth_floor','philhealth_ceiling',
      'pagibig_enabled','pagibig_rate_low','pagibig_rate_high','pagibig_threshold','pagibig_base_cap',
      'sss_enabled','sss_rate_employee','sss_ceiling'
    )"
  )->fetchAll(PDO::FETCH_KEY_PAIR);
  $perDay = (float)($settings['working_hours_per_day'] ?? 8);
  $otRate = (float)($settings['overtime_rate'] ?? 1.25);
  // Salary if present
  $chkSalary = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME='salary'");
  $chkSalary->execute();
  $salary = 0.0;
  if ((int)$chkSalary->fetchColumn() > 0) {
    $sal = $pdo->prepare("SELECT salary FROM employees WHERE employee_id=?");
    $sal->execute([$eid]);
    $salary = (float)$sal->fetchColumn();
  }
  // Employee-level exclude flags (optional columns)
  $excludePH = false; $excludePI = false; $excludeSSS = false;
  try {
    $chk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME=?");
    $colsToCheck = ['exclude_philhealth','exclude_pagibig','exclude_sss'];
    $colExists = [];
    foreach ($colsToCheck as $c) { $chk->execute([$c]); $colExists[$c] = ((int)$chk->fetchColumn() > 0); }
    if ($colExists['exclude_philhealth']) {
      $q = $pdo->prepare("SELECT exclude_philhealth FROM employees WHERE employee_id=?");
      $q->execute([$eid]); $excludePH = ((int)$q->fetchColumn() === 1);
    }
    if ($colExists['exclude_pagibig']) {
      $q = $pdo->prepare("SELECT exclude_pagibig FROM employees WHERE employee_id=?");
      $q->execute([$eid]); $excludePI = ((int)$q->fetchColumn() === 1);
    }
    if ($colExists['exclude_sss']) {
      $q = $pdo->prepare("SELECT exclude_sss FROM employees WHERE employee_id=?");
      $q->execute([$eid]); $excludeSSS = ((int)$q->fetchColumn() === 1);
    }
  } catch (Throwable $e) {
    // ignore
  }
  // Aggregate attendance within month
  $stmt = $pdo->prepare("SELECT COALESCE(SUM(hours_worked),0) h, COALESCE(SUM(overtime_hours),0) o FROM attendance WHERE employee_id=? AND DATE_FORMAT(work_date, '%Y-%m') = ?");
  $stmt->execute([$eid, $month]);
  $agg = $stmt->fetch();
  $hours = (float)($agg['h'] ?? 0); $overtime = (float)($agg['o'] ?? 0);
  // Hourly from salary assuming 22 working days/month
  $baseHours = max(1.0, ($perDay > 0 ? ($perDay * 22.0) : 176.0));
  $hourly = $salary > 0 ? ($salary / $baseHours) : 0.0;
  $gross = ($hours * $hourly) + ($overtime * $hourly * $otRate);

  // Statutory deductions (simple configurable mode)
  $toBool = static function ($v): bool {
    if ($v === null) return false;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','on','enabled'], true);
  };
  $clamp = static function (float $x, float $lo, float $hi): float { return max($lo, min($hi, $x)); };

  // Use monthly base as salary if available; otherwise fall back to gross
  $monthlyBase = $salary > 0 ? $salary : max(0.0, $gross);

  // PhilHealth (employee share = 50% of rate * clamp(base, floor, ceiling))
  $phEnabled = $toBool($settings['philhealth_enabled'] ?? null);
  $phRate = (float)($settings['philhealth_rate'] ?? 0.0); // e.g., 0.05 for 5%
  $phFloor = (float)($settings['philhealth_floor'] ?? 0.0); // e.g., 10000
  $phCeil  = (float)($settings['philhealth_ceiling'] ?? 0.0); // e.g., 90000
  $philhealthEmp = 0.0;
  if ($phEnabled && !$excludePH && $phRate > 0) {
    $base = $monthlyBase;
    if ($phFloor > 0 || $phCeil > 0) {
      $lo = $phFloor > 0 ? $phFloor : 0.0;
      $hi = $phCeil > 0 ? $phCeil : $monthlyBase; // if no ceiling set, use current base
      $base = $clamp($monthlyBase, $lo, $hi);
    }
    $philhealthEmp = 0.5 * $phRate * $base;
  }

  // Pag-IBIG (HDMF) employee share
  $piEnabled = $toBool($settings['pagibig_enabled'] ?? null);
  $piRateLow = (float)($settings['pagibig_rate_low'] ?? 0.0);   // e.g., 0.01
  $piRateHigh = (float)($settings['pagibig_rate_high'] ?? 0.0); // e.g., 0.02
  $piThreshold = (float)($settings['pagibig_threshold'] ?? 0.0); // e.g., 1500
  $piCap = (float)($settings['pagibig_base_cap'] ?? 0.0); // e.g., 5000
  $pagibigEmp = 0.0;
  if ($piEnabled && !$excludePI) {
    $rate = $monthlyBase < $piThreshold || $piThreshold <= 0 ? $piRateLow : $piRateHigh;
    $base = $monthlyBase;
    if ($piCap > 0) { $base = min($base, $piCap); }
    $pagibigEmp = $rate > 0 ? ($base * $rate) : 0.0;
  }

  // SSS (simple mode): employee share = min(base, ceiling) * rate
  $sssEnabled = $toBool($settings['sss_enabled'] ?? null);
  $sssRateEmp = (float)($settings['sss_rate_employee'] ?? 0.0); // e.g., 0.045
  $sssCeil = (float)($settings['sss_ceiling'] ?? 0.0); // e.g., 32500 or set to 0 to skip cap
  $sssEmp = 0.0;
  if ($sssEnabled && !$excludeSSS && $sssRateEmp > 0) {
    $base = $monthlyBase;
    if ($sssCeil > 0) { $base = min($base, $sssCeil); }
    $sssEmp = $base * $sssRateEmp;
  }

  // Total deductions = statutory EE shares (you may add other deductions here)
  $deductions = round($philhealthEmp + $pagibigEmp + $sssEmp, 2);
  $net = max(0, $gross - $deductions);
  $upd = $pdo->prepare("UPDATE payroll SET regular_hours=?, overtime_hours=?, gross_pay=?, deductions=?, net_pay=? WHERE payroll_id=?");
  $upd->execute([$hours, $overtime, $gross, $deductions, $net, $pid]);
}

// Create payroll batch rows and compute from attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) { http_response_code(400); exit('Invalid CSRF token'); }
  if (isset($_POST['create_batch'])) {
    $month = $_POST['month_year'] ?? '';
    if (preg_match('/^\d{4}-\d{2}$/', $month)) {
      $emps = $pdo->query("SELECT employee_id FROM employees WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN);
      $insert = $pdo->prepare("INSERT INTO payroll (employee_id, month_year, status) VALUES (?, ?, 'pending')");
      foreach ($emps as $eid) {
        $exists = $pdo->prepare("SELECT payroll_id FROM payroll WHERE employee_id=? AND month_year=? LIMIT 1");
        $exists->execute([$eid, $month]);
        $existingPid = $exists->fetchColumn();
        if (!$existingPid) {
          $insert->execute([$eid, $month]);
          $pid = (int)$pdo->lastInsertId();
          if ($pid > 0) { recompute_payroll($pdo, $pid); }
        } else {
          // Recompute existing row to reflect latest attendance
          $pid = (int)$existingPid;
          if ($pid > 0) { recompute_payroll($pdo, $pid); }
        }
      }
    }
    header('Location: payroll.php');
    exit;
  }
  if (isset($_POST['recompute'])) {
    $pid = (int)($_POST['payroll_id'] ?? 0);
    if ($pid > 0) { recompute_payroll($pdo, $pid); }
    header('Location: payroll.php');
    exit;
  }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per = 10;
$offset = ($page-1) * $per;
$total = (int)$pdo->query('SELECT COUNT(*) FROM payroll')->fetchColumn();
$colCheck = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll'");
$colCheck->execute();
$cols = array_column($colCheck->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
$selectCols = ['payroll_id','employee_id','created_at'];
if (in_array('month_year', $cols, true)) { $selectCols[] = 'month_year'; }
foreach (['period_start','period_end','gross_pay','net_pay'] as $c) { if (in_array($c, $cols, true)) { $selectCols[] = $c; } }
$sql = 'SELECT ' . implode(',', $selectCols) . ' FROM payroll ORDER BY created_at DESC, payroll_id DESC LIMIT ? OFFSET ?';
$stmt = $pdo->prepare($sql);
$stmt->bindValue(1, $per, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
// Build employee name and metadata lookup for display
$empNames = [];
$empMeta = [];
try {
  $en = $pdo->query("SELECT e.employee_id, e.employee_code, e.position, e.department, e.hire_date, e.status,
                            COALESCE(u.first_name,'') AS fn, COALESCE(u.last_name,'') AS ln, u.email, u.phone, u.address
                     FROM employees e LEFT JOIN users u ON e.user_id = u.user_id");
  foreach ($en as $erow) {
    $label = trim(($erow['fn'] . ' ' . $erow['ln']));
    if ($label === '') { $label = 'Employee #' . (int)$erow['employee_id']; }
    if (!empty($erow['employee_code'])) { $label .= ' (' . $erow['employee_code'] . ')'; }
    $eidMap = (int)$erow['employee_id'];
    $empNames[$eidMap] = $label;
    $empMeta[$eidMap] = [
      'employee_code' => $erow['employee_code'] ?? null,
      'position' => $erow['position'] ?? null,
      'department' => $erow['department'] ?? null,
      'hire_date' => $erow['hire_date'] ?? null,
      'status' => $erow['status'] ?? null,
      'email' => $erow['email'] ?? null,
      'phone' => $erow['phone'] ?? null,
      'address' => $erow['address'] ?? null,
    ];
  }
} catch (Throwable $e) {
  // Fallback silently; will show #id below
}
// Build employee bank details lookup (if columns exist)
$empBank = [];
try {
  $colChk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME=?");
  $need = ['bank_name','account_name','account_number'];
  $exists = [];
  foreach ($need as $c) { $colChk->execute([$c]); $exists[$c] = ((int)$colChk->fetchColumn() > 0); }
  if ($exists['bank_name'] || $exists['account_name'] || $exists['account_number']) {
    $cols = ['employee_id'];
    if ($exists['bank_name']) $cols[] = 'bank_name';
    if ($exists['account_name']) $cols[] = 'account_name';
    if ($exists['account_number']) $cols[] = 'account_number';
    $sql = 'SELECT ' . implode(',', $cols) . ' FROM employees';
    foreach ($pdo->query($sql) as $rowB) {
      $eidB = (int)$rowB['employee_id'];
      $empBank[$eidB] = [
        'bank_name' => $rowB['bank_name'] ?? null,
        'account_name' => $rowB['account_name'] ?? null,
        'account_number' => $rowB['account_number'] ?? null,
      ];
    }
  }
} catch (Throwable $e) {
  // ignore bank details if any error
}
// Helper to mask account number (show last 4)
$maskAcct = function (?string $num): string {
  if (!$num) return '';
  $s = preg_replace('/\s+/', '', (string)$num);
  $len = strlen($s);
  if ($len <= 4) return str_repeat('•', max(0, $len)) . $s;
  $last4 = substr($s, -4);
  return '•••• ' . $last4;
};
// Suppress footer on this page
$HIDE_FOOTER = true;
include_once __DIR__ . '/../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-8">
  <div class="max-w-full px-4">
    <div class="flex items-center space-x-3">
      <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center"><i class="fas fa-wallet"></i></div>
      <div>
        <h1 class="text-2xl font-semibold">Payroll</h1>
        <p class="text-white/70">Salary batches and payouts</p>
      </div>
    </div>
  </div>
</section>
<main class="max-w-full px-4 -mt-6">
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
  <form method="post" class="flex flex-col sm:flex-row sm:items-end gap-2">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
      <div>
        <label class="text-sm text-gray-600">Month (YYYY-MM)</label>
        <input name="month_year" type="month" class="border rounded p-2" required />
      </div>
      <div>
    <button name="create_batch" value="1" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Create Payroll Batch</button>
      </div>
    </form>
    <form method="get" action="hr/export.php" class="mt-3 flex flex-col sm:flex-row sm:items-end gap-2">
      <input type="hidden" name="type" value="payroll_payout">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
      <div>
        <label class="text-sm text-gray-600">Payout Month (YYYY-MM)</label>
        <input name="month" type="month" class="border rounded p-2" required />
      </div>
      <div>
        <button class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">Export Payout CSV</button>
      </div>
      <div class="text-xs text-gray-500 sm:ml-2">Uses net pay for the selected month. Ensure employee bank details are recorded.</div>
    </form>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <div class="flex justify-end mb-3 space-x-2">
      <a class="text-sm bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700" href="hr/export.php?type=payroll&csrf_token=<?php echo htmlspecialchars($CSRF); ?>">Export CSV</a>
      <a class="text-sm bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700" target="_blank" href="hr/print.php?type=payroll">Print</a>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full">
    <thead>
          <tr class="text-left text-sm text-gray-500">
    <th class="py-2">Employee</th>
            <th class="py-2">Bank</th>
            <th class="py-2">Period</th>
            <th class="py-2">Gross</th>
            <th class="py-2">Net</th>
            <th class="py-2">Created</th>
      <th class="py-2 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="text-sm">
          <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="py-4 text-center text-gray-500">No payroll records.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr class="border-t">
              <td class="py-2">
                <?php $eidRow = (int)$r['employee_id']; ?>
                <div class="leading-tight">
                  <div><?php echo htmlspecialchars($empNames[$eidRow] ?? ('Employee #' . $eidRow)); ?></div>
                  <?php $m = $empMeta[$eidRow] ?? null; if ($m): ?>
                    <div class="text-xs text-gray-500">
                      <?php
                        $bits = [];
                        if (!empty($m['employee_code'])) { $bits[] = htmlspecialchars((string)$m['employee_code']); }
                        if (!empty($m['position'])) { $bits[] = htmlspecialchars(ucwords(str_replace('_',' ', (string)$m['position']))); }
                        if (!empty($m['department'])) { $bits[] = htmlspecialchars((string)$m['department']); }
                        echo !empty($bits) ? implode(' • ', $bits) : '';
                      ?>
                    </div>
                  <?php endif; ?>
                </div>
              </td>
              <td class="py-2 text-gray-500">
                <?php
                  $eidRow = (int)$r['employee_id'];
                  $b = $empBank[$eidRow] ?? null;
                  if ($b) {
                    $parts = [];
                    if (!empty($b['bank_name'])) { $parts[] = htmlspecialchars((string)$b['bank_name']); }
                    $accMasked = $maskAcct($b['account_number'] ?? null);
                    if ($accMasked !== '') { $parts[] = 'Acct ' . htmlspecialchars($accMasked); }
                    echo !empty($parts) ? implode(' • ', $parts) : '—';
                  } else {
                    echo '—';
                  }
                ?>
              </td>
              <td class="py-2 text-gray-500">
                <?php if (isset($r['month_year']) && $r['month_year']): ?>
                  <?php echo htmlspecialchars($r['month_year']); ?>
                <?php else: ?>
                  <?php echo (isset($r['period_start']) ? htmlspecialchars($r['period_start']) : '—') . ' - ' . (isset($r['period_end']) ? htmlspecialchars($r['period_end']) : '—'); ?>
                <?php endif; ?>
              </td>
              <td class="py-2 text-gray-700"><?php echo isset($r['gross_pay']) ? number_format((float)$r['gross_pay'], 2) : '—'; ?></td>
              <td class="py-2 text-gray-700"><?php echo isset($r['net_pay']) ? number_format((float)$r['net_pay'], 2) : '—'; ?></td>
              <td class="py-2 text-gray-500"><?php echo htmlspecialchars(date('M d, Y', strtotime($r['created_at']))); ?></td>
              <td class="py-2 text-right">
                <form method="post" class="inline">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
                  <input type="hidden" name="payroll_id" value="<?php echo (int)$r['payroll_id']; ?>">
                  <button name="recompute" value="1" class="px-2 py-1 text-xs rounded bg-purple-600 text-white hover:bg-purple-700">Recompute</button>
                </form>
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

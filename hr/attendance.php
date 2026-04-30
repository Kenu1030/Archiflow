<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['user_type'] ?? null) !== 'hr') {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../backend/connection/connect.php';
$pdo = getDB();
if (!$pdo) { http_response_code(500); echo 'DB error'; exit; }

// Helper: format decimal hours (e.g., 6.27) to HH:MM (e.g., 06:16)
function af_format_hours_hm($hours) {
  if ($hours === null || $hours === '' || !is_numeric($hours)) return '—';
  $mins = (int)round(((float)$hours) * 60);
  $h = (int)floor($mins / 60);
  $m = $mins % 60;
  return sprintf('%02d:%02d', $h, $m);
}

// Resolve current HR's employee_id (if any) to block self-edits
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$selfEmpId = 0;
if ($currentUserId > 0) {
  try {
    $qSelf = $pdo->prepare("SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1");
    $qSelf->execute([$currentUserId]);
    $selfEmpId = (int)($qSelf->fetchColumn() ?: 0);
  } catch (Throwable $e) { $selfEmpId = 0; }
}

$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : (new DateTime('today'))->format('Y-m-d');

// CSRF
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_token'];

// Ensure audit_logs and attendance_corrections tables exist (self-healing)
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id INT NULL,
    before_data TEXT NULL,
    after_data TEXT NULL,
    reason TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(actor_user_id), INDEX(entity_type), INDEX(created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {}
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_corrections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    work_date DATE NOT NULL,
    new_time_in TIME NULL,
    new_time_out TIME NULL,
    reason TEXT NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    reviewer_id INT NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(employee_id), INDEX(status), INDEX(work_date)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {}

// Handle approval/rejection of correction requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['correction_action'])) {
  $token = $_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) { http_response_code(400); exit('Invalid CSRF token'); }
  $action = $_POST['correction_action'];
  $cid = (int)($_POST['correction_id'] ?? 0);
  if ($cid > 0 && in_array($action, ['approve','reject'], true)) {
    $st = $pdo->prepare("SELECT * FROM attendance_corrections WHERE id=? LIMIT 1");
    $st->execute([$cid]);
    $corr = $st->fetch(PDO::FETCH_ASSOC);
    if ($corr && $corr['status'] === 'pending') {
      // Block self-approval
      if ($selfEmpId > 0 && (int)$corr['employee_id'] === $selfEmpId) {
        header('Location: attendance.php?date='.urlencode($date).'&err=self_correction');
        exit;
      }
      if ($action === 'approve') {
        // Apply to attendance table (upsert for the given date)
        $before = null; $after = null; $attId = null;
        $sel = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND work_date=? LIMIT 1");
        $sel->execute([(int)$corr['employee_id'], $corr['work_date']]);
        $ex = $sel->fetch(PDO::FETCH_ASSOC);
        if ($ex) {
          $before = $ex;
          $upd = $pdo->prepare("UPDATE attendance SET time_in=COALESCE(?, time_in), time_out=COALESCE(?, time_out) WHERE attendance_id=?");
          $upd->execute([$corr['new_time_in'] ?: null, $corr['new_time_out'] ?: null, (int)$ex['attendance_id']]);
          $attId = (int)$ex['attendance_id'];
          $sel->execute([(int)$corr['employee_id'], $corr['work_date']]);
          $after = $sel->fetch(PDO::FETCH_ASSOC);
        } else {
          $ins = $pdo->prepare("INSERT INTO attendance (employee_id, work_date, time_in, time_out, status) VALUES (?,?,?,?, 'present')");
          $ins->execute([(int)$corr['employee_id'], $corr['work_date'], $corr['new_time_in'] ?: null, $corr['new_time_out'] ?: null]);
          $attId = (int)$pdo->lastInsertId();
          $sel->execute([(int)$corr['employee_id'], $corr['work_date']]);
          $after = $sel->fetch(PDO::FETCH_ASSOC);
        }
        // Mark correction approved
        $pdo->prepare("UPDATE attendance_corrections SET status='approved', reviewer_id=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$currentUserId ?: null, $cid]);
        // Audit log
        $pdo->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data, reason, ip_address) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$currentUserId, 'approve_correction', 'attendance', $attId, json_encode($before), json_encode($after), (string)($corr['reason'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? null]);
      } else { // reject
        $pdo->prepare("UPDATE attendance_corrections SET status='rejected', reviewer_id=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$currentUserId ?: null, $cid]);
        $pdo->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data, reason, ip_address) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$currentUserId, 'reject_correction', 'attendance_correction', $cid, null, null, (string)($corr['reason'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? null]);
      }
    }
  }
  header('Location: attendance.php?date=' . urlencode($date));
  exit;
}

// Record attendance (insert or update by unique (employee_id, work_date))
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['correction_action'])) {
  $token = $_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) { http_response_code(400); exit('Invalid CSRF token'); }
  $empId = (int)($_POST['employee_id'] ?? 0);
  $status = $_POST['status'] ?? 'present';
  $timeIn = $_POST['time_in'] !== '' ? $_POST['time_in'] : null;
  $timeOut = $_POST['time_out'] !== '' ? $_POST['time_out'] : null;
  $hours = isset($_POST['hours_worked']) && $_POST['hours_worked'] !== '' ? (float)$_POST['hours_worked'] : null;
  $ot = isset($_POST['overtime_hours']) && $_POST['overtime_hours'] !== '' ? (float)$_POST['overtime_hours'] : null;
  $reason = trim($_POST['reason'] ?? '');
  // Block self-edit for HR
  if ($selfEmpId > 0 && $empId === $selfEmpId) {
    header('Location: attendance.php?date=' . urlencode($date) . '&err=self_edit');
    exit;
  }
  // Validate employee exists and is active
  $ok = false;
  if ($empId > 0 && in_array($status, ['present','absent','late'], true)) {
    $chk = $pdo->prepare("SELECT 1 FROM employees WHERE employee_id=? AND status='active' LIMIT 1");
    $chk->execute([$empId]);
    $ok = (bool)$chk->fetchColumn();
  }
  if ($ok) {
    // Upsert and audit
    $exists = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND work_date=? LIMIT 1");
    $exists->execute([$empId, $date]);
    $prev = $exists->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($prev) {
      $upd = $pdo->prepare("UPDATE attendance SET status=?, time_in=?, time_out=?, hours_worked=?, overtime_hours=? WHERE attendance_id=?");
      $upd->execute([$status, $timeIn, $timeOut, $hours, $ot, (int)$prev['attendance_id']]);
      $exists->execute([$empId, $date]);
      $after = $exists->fetch(PDO::FETCH_ASSOC) ?: null;
      $pdo->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data, reason, ip_address) VALUES (?,?,?,?,?,?,?,?)")
          ->execute([$currentUserId, 'attendance_update', 'attendance', (int)$prev['attendance_id'], json_encode($prev), json_encode($after), $reason !== '' ? $reason : null, $_SERVER['REMOTE_ADDR'] ?? null]);
    } else {
      $ins = $pdo->prepare("INSERT INTO attendance (employee_id, work_date, status, time_in, time_out, hours_worked, overtime_hours) VALUES (?,?,?,?,?,?,?)");
      $ins->execute([$empId, $date, $status, $timeIn, $timeOut, $hours, $ot]);
      $attId = (int)$pdo->lastInsertId();
      $exists->execute([$empId, $date]);
      $after = $exists->fetch(PDO::FETCH_ASSOC) ?: null;
      $pdo->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data, reason, ip_address) VALUES (?,?,?,?,?,?,?,?)")
          ->execute([$currentUserId, 'attendance_create', 'attendance', $attId, null, json_encode($after), $reason !== '' ? $reason : null, $_SERVER['REMOTE_ADDR'] ?? null]);
    }
  }
  header('Location: attendance.php?date=' . urlencode($date));
  exit;
}

// Stats by status
$att = ['present' => 0, 'absent' => 0, 'late' => 0];
$stmt = $pdo->prepare('SELECT status, COUNT(*) c FROM attendance WHERE work_date = ? GROUP BY status');
$stmt->execute([$date]);
foreach ($stmt as $row) { $att[$row['status']] = (int)$row['c']; }

// Rows
$listStmt = $pdo->prepare('SELECT employee_id, status, time_in, time_out, hours_worked, overtime_hours FROM attendance WHERE work_date = ? ORDER BY (time_in IS NULL), time_in, employee_id');
$listStmt->execute([$date]);
$rows = $listStmt->fetchAll();

// Active employees for dropdown (name fallback to code/id)
$empStmt = $pdo->query("SELECT e.employee_id, e.employee_code, COALESCE(u.first_name,'') AS fn, COALESCE(u.last_name,'') AS ln 
                        FROM employees e LEFT JOIN users u ON e.user_id = u.user_id 
                        WHERE e.status='active' ORDER BY u.first_name, u.last_name, e.employee_code, e.employee_id LIMIT 500");
$emps = $empStmt->fetchAll();
// Suppress footer on this page
$HIDE_FOOTER = true;
include_once __DIR__ . '/../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-8">
  <div class="max-w-full px-4">
    <div class="flex items-center justify-between">
      <div class="flex items-center space-x-3">
        <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center"><i class="fas fa-calendar-check text-white"></i></div>
        <div>
          <h1 class="text-2xl font-semibold">Attendance</h1>
          <p class="text-white/70">Records for <?php echo htmlspecialchars($date); ?></p>
        </div>
      </div>
      <form method="get" class="flex items-center space-x-2 bg-white/10 p-2 rounded-lg">
        <input type="date" name="date" value="<?php echo htmlspecialchars($date); ?>" class="bg-transparent text-white placeholder-white/70 outline-none">
        <button class="bg-white text-blue-700 px-3 py-1 rounded-lg">Go</button>
      </form>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-4">
      <div class="bg-white/10 p-4 rounded-lg"><div class="text-white/70">Present</div><div class="text-2xl font-semibold"><?php echo (int)$att['present']; ?></div></div>
      <div class="bg-white/10 p-4 rounded-lg"><div class="text-white/70">Late</div><div class="text-2xl font-semibold"><?php echo (int)$att['late']; ?></div></div>
      <div class="bg-white/10 p-4 rounded-lg"><div class="text-white/70">Absent</div><div class="text-2xl font-semibold"><?php echo (int)$att['absent']; ?></div></div>
    </div>
    <div class="mt-3 space-x-2">
      <a class="inline-block bg-white text-blue-700 px-3 py-1 rounded" href="hr/export.php?type=attendance&date=<?php echo urlencode($date); ?>&csrf_token=<?php echo htmlspecialchars($CSRF); ?>">Export CSV</a>
      <a class="inline-block bg-white text-blue-700 px-3 py-1 rounded" target="_blank" href="hr/print.php?type=attendance&date=<?php echo urlencode($date); ?>">Print</a>
    </div>
    <div class="mt-4 bg-white/10 p-4 rounded-lg">
      <h3 class="font-semibold mb-2">Record Attendance</h3>
      <?php if (isset($_GET['err']) && $_GET['err']==='self_edit'): ?>
        <div class="mb-2 p-2 rounded bg-red-50 text-red-700 text-sm">You cannot edit your own attendance here. Please submit a correction request from your Attendance page.</div>
      <?php elseif (isset($_GET['err']) && $_GET['err']==='self_correction'): ?>
        <div class="mb-2 p-2 rounded bg-red-50 text-red-700 text-sm">You cannot approve your own attendance correction.</div>
      <?php endif; ?>
      <form method="post" class="grid grid-cols-1 md:grid-cols-6 gap-2 items-end">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
        <div class="md:col-span-2">
          <label class="text-sm text-white/80">Employee</label>
          <select name="employee_id" required class="w-full bg-white text-gray-900 p-2 rounded">
            <option value="">Select…</option>
            <?php foreach ($emps as $e): $label = trim(($e['fn'].' '.$e['ln'])); $label = $label !== '' ? $label : ('Employee #'.$e['employee_id']); $empIdOpt=(int)$e['employee_id']; $isSelf = ($selfEmpId>0 && $empIdOpt===$selfEmpId); ?>
              <option value="<?php echo $empIdOpt; ?>" <?php echo $isSelf ? 'disabled' : ''; ?>><?php echo htmlspecialchars($label . ($e['employee_code'] ? ' ('.$e['employee_code'].')' : '') . ($isSelf ? ' — (self, use Correction Request)' : '')); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="text-sm text-white/80">Status</label>
          <select name="status" class="w-full bg-white text-gray-900 p-2 rounded">
            <option value="present">Present</option>
            <option value="late">Late</option>
            <option value="absent">Absent</option>
          </select>
        </div>
        <div>
          <label class="text-sm text-white/80">Time In</label>
          <input name="time_in" type="time" class="w-full bg-white text-gray-900 p-2 rounded" />
        </div>
        <div>
          <label class="text-sm text-white/80">Time Out</label>
          <input name="time_out" type="time" class="w-full bg-white text-gray-900 p-2 rounded" />
        </div>
        <div class="md:col-span-6 grid grid-cols-2 md:grid-cols-6 gap-2">
          <div>
            <label class="text-sm text-white/80">Hours</label>
            <input name="hours_worked" type="number" step="0.25" min="0" class="w-full bg-white text-gray-900 p-2 rounded" />
          </div>
          <div>
            <label class="text-sm text-white/80">Overtime</label>
            <input name="overtime_hours" type="number" step="0.25" min="0" class="w-full bg-white text-gray-900 p-2 rounded" />
          </div>
          <div class="md:col-span-3">
            <label class="text-sm text-white/80">Reason (optional, required for adjustments)</label>
            <input name="reason" type="text" maxlength="255" class="w-full bg-white text-gray-900 p-2 rounded" placeholder="e.g., Corrected time-out per manager" />
          </div>
          <div class="md:col-span-4 flex items-end justify-end">
            <button class="bg-white text-blue-700 px-4 py-2 rounded">Save</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</section>
<main class="max-w-full px-4 -mt-6">
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <div class="overflow-x-auto">
      <table class="min-w-full">
        <thead>
          <tr class="text-left text-sm text-gray-500">
            <th class="py-2">Employee</th>
            <th class="py-2">Status</th>
            <th class="py-2">Time In</th>
            <th class="py-2">Time Out</th>
            <th class="py-2">Hours</th>
            <th class="py-2">Overtime</th>
          </tr>
        </thead>
        <tbody class="text-sm">
          <?php if (empty($rows)): ?>
            <tr><td colspan="6" class="py-4 text-center text-gray-500">No attendance records.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr class="border-t">
              <td class="py-2">#<?php echo (int)$r['employee_id']; ?></td>
              <td class="py-2 capitalize">
                <?php if ($r['status'] === 'present'): ?><span class="px-2 py-0.5 rounded-full bg-green-100 text-green-700">Present</span>
                <?php elseif ($r['status'] === 'late'): ?><span class="px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700">Late</span>
                <?php else: ?><span class="px-2 py-0.5 rounded-full bg-red-100 text-red-700">Absent</span><?php endif; ?>
              </td>
              <td class="py-2 text-gray-500"><?php echo $r['time_in'] ? htmlspecialchars(substr($r['time_in'],0,5)) : '—'; ?></td>
              <td class="py-2 text-gray-500"><?php echo $r['time_out'] ? htmlspecialchars(substr($r['time_out'],0,5)) : '—'; ?></td>
              <td class="py-2 text-gray-500" title="<?php echo isset($r['hours_worked']) && $r['hours_worked']!==null && $r['hours_worked']!=='' ? htmlspecialchars($r['hours_worked']) . ' hrs' : ''; ?>">
                <?php echo af_format_hours_hm($r['hours_worked'] ?? null); ?>
              </td>
              <td class="py-2 text-gray-500" title="<?php echo isset($r['overtime_hours']) && $r['overtime_hours']!==null && $r['overtime_hours']!=='' ? htmlspecialchars($r['overtime_hours']) . ' hrs' : ''; ?>">
                <?php echo af_format_hours_hm($r['overtime_hours'] ?? null); ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-6" id="corrections">
    <div class="flex items-center justify-between mb-3">
      <h3 class="font-semibold">Pending Attendance Corrections</h3>
      <span class="text-xs text-gray-500">Newest first</span>
    </div>
    <?php
      // Fetch pending corrections (limit 50)
      $pcStmt = $pdo->query("SELECT id, employee_id, work_date, new_time_in, new_time_out, reason, created_at FROM attendance_corrections WHERE status='pending' ORDER BY created_at DESC LIMIT 50");
      $pending = $pcStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <?php if (!$pending): ?>
      <div class="text-sm text-gray-500">No pending corrections.</div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="min-w-full">
          <thead>
            <tr class="text-left text-sm text-gray-500">
              <th class="py-2">Employee</th>
              <th class="py-2">Date</th>
              <th class="py-2">New In</th>
              <th class="py-2">New Out</th>
              <th class="py-2">Reason</th>
              <th class="py-2">Actions</th>
            </tr>
          </thead>
          <tbody class="text-sm">
            <?php foreach ($pending as $p): $isSelfCorr = ($selfEmpId>0 && (int)$p['employee_id']===$selfEmpId); ?>
            <tr class="border-t">
              <td class="py-2">#<?php echo (int)$p['employee_id']; ?><?php echo $isSelfCorr ? ' (self)' : ''; ?></td>
              <td class="py-2"><?php echo htmlspecialchars($p['work_date']); ?></td>
              <td class="py-2"><?php echo $p['new_time_in'] ? htmlspecialchars(substr($p['new_time_in'],0,5)) : '—'; ?></td>
              <td class="py-2"><?php echo $p['new_time_out'] ? htmlspecialchars(substr($p['new_time_out'],0,5)) : '—'; ?></td>
              <td class="py-2 max-w-md pr-4"><div class="truncate" title="<?php echo htmlspecialchars($p['reason']); ?>"><?php echo htmlspecialchars($p['reason']); ?></div></td>
              <td class="py-2">
                <form method="post" class="inline">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>" />
                  <input type="hidden" name="correction_id" value="<?php echo (int)$p['id']; ?>" />
                  <input type="hidden" name="correction_action" value="approve" />
                  <button class="px-3 py-1 rounded bg-green-600 text-white disabled:opacity-50" <?php echo $isSelfCorr ? 'disabled title="Cannot approve own"' : ''; ?>>Approve</button>
                </form>
                <form method="post" class="inline ml-1">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>" />
                  <input type="hidden" name="correction_id" value="<?php echo (int)$p['id']; ?>" />
                  <input type="hidden" name="correction_action" value="reject" />
                  <button class="px-3 py-1 rounded bg-red-600 text-white">Reject</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>

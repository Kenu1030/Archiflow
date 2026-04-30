  <?php
  if (session_status() === PHP_SESSION_NONE) { session_start(); }
  // Only employees can access (covers architects, PMs, senior architects via position)
  if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['user_type'] ?? null) !== 'employee') {
      header('Location: ../login.php');
      exit;
  }

  // Set explicit timezone for consistent local times (adjust if company uses a different primary timezone)
  date_default_timezone_set('Asia/Manila'); // TODO: move to a central config if multi-timezone support is required

  require_once __DIR__ . '/../backend/connection/connect.php';
  $pdo = getDB();
  if (!$pdo) { http_response_code(500); echo 'Database connection error'; exit; }

  // Load anti-cheat config if present
  $attendanceConfig = [
    'enforce_office_network' => false,
    'allowed_ip_prefixes' => [],
    'enforce_geofence' => false,
    'office_locations' => [],
    // New: enforce client clock sanity (prevent desktop clock cheating)
    'enforce_client_clock' => false,
    // Max allowed skew between client clock and server clock (minutes)
    'max_client_skew_minutes' => 10,
  ];
  $confPath = __DIR__ . '/../config/attendance.php';
  if (file_exists($confPath)) {
    $cfg = include $confPath;
    if (is_array($cfg)) { $attendanceConfig = array_merge($attendanceConfig, $cfg); }
  }

  // CSRF token
  if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
  $CSRF = $_SESSION['csrf_token'];

  $userId = (int)($_SESSION['user_id'] ?? 0);
  if ($userId <= 0) { header('Location: ../login.php'); exit; }

  // Resolve employee_id from logged-in user
  $empStmt = $pdo->prepare("SELECT employee_id, position, status FROM employees WHERE user_id = ? ORDER BY employee_id LIMIT 1");
  $empStmt->execute([$userId]);
  $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
  if (!$employee || ($employee['status'] ?? '') !== 'active') {
      http_response_code(403);
      echo 'Your employee profile is not active or not found. Please contact HR.';
      exit;
  }
  $employeeId = (int)$employee['employee_id'];

  // Date handling (employees record only for today)
  $today = (new DateTime('today'))->format('Y-m-d');

  // Optional: ensure attendance table exists (safe-guard in dev)
  try {
      $pdo->query("SELECT 1 FROM attendance LIMIT 1");
  } catch (Throwable $e) {
      // Create minimal table per archiflow_db.sql if missing
      $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
          attendance_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
          employee_id INT(10) UNSIGNED NOT NULL,
          work_date DATE NOT NULL,
          time_in TIME DEFAULT NULL,
          time_out TIME DEFAULT NULL,
          hours_worked DECIMAL(4,2) DEFAULT 0.00,
          overtime_hours DECIMAL(4,2) DEFAULT 0.00,
          status ENUM('present','absent','late') DEFAULT 'present',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (attendance_id),
          KEY idx_emp_date (employee_id, work_date)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  }

  // Ensure attendance_logs audit table exists (for metadata and enforcement results)
  try {
    $pdo->query("SELECT 1 FROM attendance_logs LIMIT 1");
  } catch (Throwable $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_logs (
      log_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      employee_id INT(10) UNSIGNED NOT NULL,
      attendance_id INT(10) UNSIGNED DEFAULT NULL,
      work_date DATE NOT NULL,
      action ENUM('clock_in','clock_out') NOT NULL,
      logged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      ip_address VARCHAR(45) DEFAULT NULL,
      user_agent VARCHAR(255) DEFAULT NULL,
      latitude DECIMAL(9,6) DEFAULT NULL,
      longitude DECIMAL(9,6) DEFAULT NULL,
      network_allowed TINYINT(1) DEFAULT NULL,
      geofence_ok TINYINT(1) DEFAULT NULL,
      -- New anti-cheat metadata
      client_time_utc DATETIME NULL,
      client_tz_offset_min INT NULL,
      time_skew_min INT NULL,
      clock_skew_ok TINYINT(1) NULL,
      PRIMARY KEY (log_id),
      KEY idx_emp_date (employee_id, work_date),
      KEY idx_attendance (attendance_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  }
  // Ensure new anti-cheat columns exist (safe to run repeatedly)
  try {
    $cols = [];
    $rs = $pdo->query("SHOW COLUMNS FROM attendance_logs");
    while ($r = $rs->fetch(PDO::FETCH_ASSOC)) { $cols[$r['Field']] = true; }
    if (!isset($cols['client_time_utc'])) {
      @$pdo->exec("ALTER TABLE attendance_logs ADD COLUMN client_time_utc DATETIME NULL AFTER geofence_ok");
    }
    if (!isset($cols['client_tz_offset_min'])) {
      @$pdo->exec("ALTER TABLE attendance_logs ADD COLUMN client_tz_offset_min INT NULL AFTER client_time_utc");
    }
    if (!isset($cols['time_skew_min'])) {
      @$pdo->exec("ALTER TABLE attendance_logs ADD COLUMN time_skew_min INT NULL AFTER client_tz_offset_min");
    }
    if (!isset($cols['clock_skew_ok'])) {
      @$pdo->exec("ALTER TABLE attendance_logs ADD COLUMN clock_skew_ok TINYINT(1) NULL AFTER time_skew_min");
    }
  } catch (Throwable $e) { /* ignore */ }

  // Ensure attendance_corrections table exists (employees submit requests)
  try {
    $pdo->query("SELECT 1 FROM attendance_corrections LIMIT 1");
  } catch (Throwable $e) {
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
  }

  // Helpers
  function getTodayRecord(PDO $pdo, int $empId, string $date) {
      $stmt = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? AND work_date = ? LIMIT 1');
      $stmt->execute([$empId, $date]);
      return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  }

  // Default shift start used to compute late and overtime (can be moved to system settings later)
  $SHIFT_START = '09:00:00';
  $SHIFT_HOURS = 8.0;

  // Handle actions: clock_in / clock_out / request_correction
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $token = $_POST['csrf_token'] ?? '';
      if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) { http_response_code(400); exit('Invalid CSRF token'); }
      $action = $_POST['action'] ?? '';
      // Capture client clock info for anti-cheat
      $clientTimeIso = isset($_POST['client_time_iso']) ? trim($_POST['client_time_iso']) : null;
      $clientTzOffsetMin = isset($_POST['client_tz_offset_min']) && is_numeric($_POST['client_tz_offset_min']) ? (int)$_POST['client_tz_offset_min'] : null;
      $serverNowUtc = new DateTime('now', new DateTimeZone('UTC'));
      $clientUtc = null; $timeSkewMin = null; $clockSkewOk = null;
      if ($clientTimeIso) {
        try { $clientUtc = new DateTime($clientTimeIso, new DateTimeZone('UTC')); } catch (Throwable $e) { $clientUtc = null; }
      }
      if ($clientUtc instanceof DateTime) {
        $timeSkewMin = (int)round(abs($serverNowUtc->getTimestamp() - $clientUtc->getTimestamp())/60);
        $clockSkewOk = ($timeSkewMin <= (int)($attendanceConfig['max_client_skew_minutes'] ?? 10)) ? 1 : 0;
      }
      // Early handling: attendance correction requests should NOT trigger anti-cheat checks
      if ($action === 'request_correction') {
        $reqDate = $_POST['corr_date'] ?? '';
        $newIn = $_POST['corr_time_in'] !== '' ? $_POST['corr_time_in'] : null;
        $newOut = $_POST['corr_time_out'] !== '' ? $_POST['corr_time_out'] : null;
        $reason = trim($_POST['corr_reason'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqDate)) { header('Location: attendance.php?msg=bad_date'); exit; }
        if ($reason === '' || strlen($reason) < 4) { header('Location: attendance.php?msg=need_reason'); exit; }
        $st = $pdo->prepare('INSERT INTO attendance_corrections (employee_id, work_date, new_time_in, new_time_out, reason, status) VALUES (?,?,?,?,?,"pending")');
        $st->execute([$employeeId, $reqDate, $newIn, $newOut, $reason]);
        header('Location: attendance.php?msg=corr_ok'); exit;
      }
      // Enforce client clock skew only for actual clock actions
      if (in_array($action, ['clock_in','clock_out'], true) && !empty($attendanceConfig['enforce_client_clock'])) {
        if (!$clientUtc || $clockSkewOk !== 1) {
          // Log and block
          $pdo->prepare('INSERT INTO attendance_logs (employee_id, attendance_id, work_date, action, ip_address, user_agent, latitude, longitude, network_allowed, geofence_ok, client_time_utc, client_tz_offset_min, time_skew_min, clock_skew_ok) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$employeeId, null, $today, ($action === 'clock_out' ? 'clock_out' : 'clock_in'), $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null, null, null, null, null, $clientUtc ? $clientUtc->format('Y-m-d H:i:s') : null, $clientTzOffsetMin, $timeSkewMin, 0]);
          http_response_code(403);
          exit('Clocking blocked: Please correct your system time and enable JavaScript.');
        }
      }
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $lat = isset($_POST['geo_lat']) && $_POST['geo_lat'] !== '' ? (float)$_POST['geo_lat'] : null;
    $lng = isset($_POST['geo_lng']) && $_POST['geo_lng'] !== '' ? (float)$_POST['geo_lng'] : null;

    // Anti-cheat checks (only for clock actions)
    $networkAllowed = null; $geoOk = null;
    if (in_array($action,['clock_in','clock_out'], true) && !empty($attendanceConfig['enforce_office_network']) && !empty($attendanceConfig['allowed_ip_prefixes'])) {
      $networkAllowed = 0;
      foreach ($attendanceConfig['allowed_ip_prefixes'] as $prefix) {
        if ($prefix !== '' && $clientIp && str_starts_with($clientIp, $prefix)) { $networkAllowed = 1; break; }
      }
      if ($networkAllowed !== 1) {
        // Log attempt and block
        $pdo->prepare('INSERT INTO attendance_logs (employee_id, attendance_id, work_date, action, ip_address, user_agent, latitude, longitude, network_allowed, geofence_ok, client_time_utc, client_tz_offset_min, time_skew_min, clock_skew_ok) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$employeeId, null, $today, $action === 'clock_out' ? 'clock_out' : 'clock_in', $clientIp, $userAgent, $lat, $lng, 0, null, $clientUtc ? $clientUtc->format('Y-m-d H:i:s') : null, $clientTzOffsetMin, $timeSkewMin, $clockSkewOk]);
        http_response_code(403);
        exit('Clocking is restricted to office network.');
      }
    }
    if (in_array($action,['clock_in','clock_out'], true) && !empty($attendanceConfig['enforce_geofence']) && !empty($attendanceConfig['office_locations'])) {
      // Require posted coords
      if ($lat === null || $lng === null) {
        // Log attempt and block
        $pdo->prepare('INSERT INTO attendance_logs (employee_id, attendance_id, work_date, action, ip_address, user_agent, latitude, longitude, network_allowed, geofence_ok, client_time_utc, client_tz_offset_min, time_skew_min, clock_skew_ok) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$employeeId, null, $today, $action === 'clock_out' ? 'clock_out' : 'clock_in', $clientIp, $userAgent, $lat, $lng, $networkAllowed, 0, $clientUtc ? $clientUtc->format('Y-m-d H:i:s') : null, $clientTzOffsetMin, $timeSkewMin, $clockSkewOk]);
        http_response_code(403);
        exit('Location permission required to clock.');
      }
      // Haversine distance check
      $geoOk = 0;
      foreach ($attendanceConfig['office_locations'] as $loc) {
        if (!isset($loc['lat'], $loc['lng'], $loc['radius_m'])) { continue; }
        $earthR = 6371000; // meters
        $dLat = deg2rad($loc['lat'] - $lat);
        $dLng = deg2rad($loc['lng'] - $lng);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat)) * cos(deg2rad($loc['lat'])) * sin($dLng/2)**2;
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $dist = $earthR * $c;
        if ($dist <= (float)$loc['radius_m']) { $geoOk = 1; break; }
      }
      if ($geoOk !== 1) {
        $pdo->prepare('INSERT INTO attendance_logs (employee_id, attendance_id, work_date, action, ip_address, user_agent, latitude, longitude, network_allowed, geofence_ok) VALUES (?,?,?,?,?,?,?,?,?,?)')
          ->execute([$employeeId, null, $today, $action === 'clock_out' ? 'clock_out' : 'clock_in', $clientIp, $userAgent, $lat, $lng, $networkAllowed, 0]);
        http_response_code(403);
        exit('You are outside the permitted location to clock.');
      }
    }

      // Fetch or create today's record
      $rec = getTodayRecord($pdo, $employeeId, $today);

    if ($action === 'clock_in') {
          // If already clocked in, ignore
          if ($rec && !empty($rec['time_in'])) {
              header('Location: attendance.php'); exit;
          }
          // Use server time in configured timezone; if anti-cheat client time skew check passed and within skew, optionally trust server only
          $now = (new DateTime('now'))->format('H:i:s');
          // Determine status based on shift start with 15-min grace
          $status = 'present';
          try {
              $inTime = new DateTime($now);
              $shiftStart = new DateTime($SHIFT_START);
              $grace = (clone $shiftStart)->modify('+15 minutes');
              if ($inTime > $grace) { $status = 'late'; }
          } catch (Throwable $e) {
              $status = 'present';
          }
      if ($rec) {
              $stmt = $pdo->prepare('UPDATE attendance SET time_in = ?, status = ? WHERE attendance_id = ?');
              $stmt->execute([$now, $status, (int)$rec['attendance_id']]);
          } else {
              $stmt = $pdo->prepare('INSERT INTO attendance (employee_id, work_date, time_in, status) VALUES (?,?,?,?)');
              $stmt->execute([$employeeId, $today, $now, $status]);
          }
      // Reload to get attendance_id
      $rec = getTodayRecord($pdo, $employeeId, $today);
      // Log
      $pdo->prepare('INSERT INTO attendance_logs (employee_id, attendance_id, work_date, action, ip_address, user_agent, latitude, longitude, network_allowed, geofence_ok, client_time_utc, client_tz_offset_min, time_skew_min, clock_skew_ok) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$employeeId, (int)($rec['attendance_id'] ?? 0) ?: null, $today, 'clock_in', $clientIp, $userAgent, $lat, $lng, $networkAllowed, $geoOk, $clientUtc ? $clientUtc->format('Y-m-d H:i:s') : null, $clientTzOffsetMin, $timeSkewMin, $clockSkewOk]);
          header('Location: attendance.php'); exit;
      }

      if ($action === 'clock_out') {
          if (!$rec || empty($rec['time_in']) || !empty($rec['time_out'])) {
              header('Location: attendance.php'); exit; // No clock-in yet or already clocked out
          }
          $now = (new DateTime('now'))->format('H:i:s');
          // Compute hours_worked and overtime
          $hoursWorked = null; $overtime = 0.0;
          try {
              $in = new DateTime($rec['time_in']);
              $out = new DateTime($now);
              if ($out < $in) { // Crossed midnight? assume +1 day
                  $out->modify('+1 day');
              }
              $diffSeconds = max(0, $out->getTimestamp() - $in->getTimestamp());
              $hoursWorked = round($diffSeconds / 3600, 2);
              if ($hoursWorked > $SHIFT_HOURS) {
                  $overtime = round($hoursWorked - $SHIFT_HOURS, 2);
              }
          } catch (Throwable $e) {
              $hoursWorked = null; $overtime = 0.0;
          }
      $stmt = $pdo->prepare('UPDATE attendance SET time_out = ?, hours_worked = ?, overtime_hours = ? WHERE attendance_id = ?');
      $stmt->execute([$now, $hoursWorked, $overtime, (int)$rec['attendance_id']]);
      // Log
      $pdo->prepare('INSERT INTO attendance_logs (employee_id, attendance_id, work_date, action, ip_address, user_agent, latitude, longitude, network_allowed, geofence_ok, client_time_utc, client_tz_offset_min, time_skew_min, clock_skew_ok) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$employeeId, (int)$rec['attendance_id'], $today, 'clock_out', $clientIp, $userAgent, $lat, $lng, $networkAllowed, $geoOk, $clientUtc ? $clientUtc->format('Y-m-d H:i:s') : null, $clientTzOffsetMin, $timeSkewMin, $clockSkewOk]);
          header('Location: attendance.php'); exit;
      }

      // Unknown action
      header('Location: attendance.php'); exit;
  }

  // Query current state for rendering
  $todayRec = getTodayRecord($pdo, $employeeId, $today);

  // Weekly history (last 7 days including today)
  $weekStart = (new DateTime('today'))->modify('-6 days')->format('Y-m-d');
  $histStmt = $pdo->prepare('SELECT work_date, status, time_in, time_out, hours_worked, overtime_hours FROM attendance WHERE employee_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date DESC');
  $histStmt->execute([$employeeId, $weekStart, $today]);
  $history = $histStmt->fetchAll(PDO::FETCH_ASSOC);

  $HIDE_FOOTER = true;
  include_once __DIR__ . '/../backend/core/header.php';
  ?>
  <section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-8">
    <div class="max-w-full px-4">
      <div class="flex items-center justify-between">
        <div class="flex items-center space-x-3">
          <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center"><i class="fas fa-clock text-white"></i></div>
          <div>
            <h1 class="text-2xl font-semibold">My Attendance</h1>
            <p class="text-white/70">Today: <?php echo htmlspecialchars($today); ?></p>
          </div>
        </div>
        <div class="text-sm text-white/70">
          Shift start: <?php echo htmlspecialchars(substr($SHIFT_START,0,5)); ?> • Standard hours: <?php echo htmlspecialchars(number_format($SHIFT_HOURS, 2)); ?>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
        <div class="bg-white/10 p-5 rounded-xl md:col-span-2">
          <h2 class="font-semibold mb-3">Today</h2>
          <div class="grid grid-cols-2 gap-3">
            <div class="bg-white/5 rounded-lg p-4">
              <div class="text-white/70 text-sm">Time In</div>
              <div class="text-2xl font-semibold mt-1"><?php echo $todayRec && $todayRec['time_in'] ? htmlspecialchars(substr($todayRec['time_in'],0,5)) : '—'; ?></div>
            </div>
            <div class="bg-white/5 rounded-lg p-4">
              <div class="text-white/70 text-sm">Time Out</div>
              <div class="text-2xl font-semibold mt-1"><?php echo $todayRec && $todayRec['time_out'] ? htmlspecialchars(substr($todayRec['time_out'],0,5)) : '—'; ?></div>
            </div>
            <div class="bg-white/5 rounded-lg p-4">
              <div class="text-white/70 text-sm">Status</div>
              <div class="mt-1">
                <?php $st = $todayRec['status'] ?? null; if ($st === 'late'): ?>
                  <span class="px-2 py-1 rounded-full bg-yellow-100 text-yellow-800 text-sm">Late</span>
                <?php elseif ($st === 'present'): ?>
                  <span class="px-2 py-1 rounded-full bg-green-100 text-green-800 text-sm">Present</span>
                <?php elseif ($st === 'absent'): ?>
                  <span class="px-2 py-1 rounded-full bg-red-100 text-red-800 text-sm">Absent</span>
                <?php else: ?>
                  <span class="px-2 py-1 rounded-full bg-gray-100 text-gray-800 text-sm">—</span>
                <?php endif; ?>
              </div>
            </div>
            <div class="bg-white/5 rounded-lg p-4">
              <div class="text-white/70 text-sm">Hours</div>
              <div class="text-2xl font-semibold mt-1"><?php echo $todayRec && $todayRec['hours_worked'] !== null ? htmlspecialchars($todayRec['hours_worked']) : '—'; ?></div>
            </div>
          </div>
          <div class="mt-4 flex space-x-3">
            <form method="post" id="clockInForm">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>" />
              <input type="hidden" name="action" value="clock_in" />
              <input type="hidden" name="geo_lat" id="clockInLat" />
              <input type="hidden" name="geo_lng" id="clockInLng" />
              <input type="hidden" name="client_time_iso" id="clientTimeIsoIn" />
              <input type="hidden" name="client_tz_offset_min" id="clientTzOffsetIn" />
              <button class="px-5 py-2 rounded-lg bg-white text-blue-700 font-semibold disabled:opacity-50" <?php echo ($todayRec && !empty($todayRec['time_in'])) ? 'disabled' : ''; ?>>Clock In</button>
            </form>
            <form method="post" id="clockOutForm">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>" />
              <input type="hidden" name="action" value="clock_out" />
              <input type="hidden" name="geo_lat" id="clockOutLat" />
              <input type="hidden" name="geo_lng" id="clockOutLng" />
              <input type="hidden" name="client_time_iso" id="clientTimeIsoOut" />
              <input type="hidden" name="client_tz_offset_min" id="clientTzOffsetOut" />
              <button class="px-5 py-2 rounded-lg bg-white text-blue-700 font-semibold disabled:opacity-50" <?php echo (!$todayRec || empty($todayRec['time_in']) || !empty($todayRec['time_out'])) ? 'disabled' : ''; ?>>Clock Out</button>
            </form>
          </div>
          <p class="text-white/60 text-xs mt-2">Note: If you made a mistake, request a correction below. HR will review it.</p>
          <div class="mt-4 bg-white/5 rounded-lg p-4" id="correction">
            <h3 class="font-semibold mb-2">Request Correction</h3>
            <?php if (isset($_GET['msg']) && $_GET['msg']==='corr_ok'): ?>
              <div class="mb-2 p-2 rounded bg-green-50 text-green-700 text-sm">Correction request sent to HR.</div>
            <?php elseif (isset($_GET['msg']) && $_GET['msg']==='need_reason'): ?>
              <div class="mb-2 p-2 rounded bg-red-50 text-red-700 text-sm">Please provide a short reason.</div>
            <?php elseif (isset($_GET['msg']) && $_GET['msg']==='bad_date'): ?>
              <div class="mb-2 p-2 rounded bg-red-50 text-red-700 text-sm">Invalid date.</div>
            <?php endif; ?>
            <form method="post" class="grid grid-cols-1 md:grid-cols-5 gap-2 items-end">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>" />
              <input type="hidden" name="action" value="request_correction" />
              <div>
                <label class="text-sm text-white/80">Date</label>
                <input type="date" name="corr_date" value="<?php echo htmlspecialchars($today); ?>" class="w-full bg-white text-gray-900 p-2 rounded" required />
              </div>
              <div>
                <label class="text-sm text-white/80">New Time In</label>
                <input type="time" name="corr_time_in" class="w-full bg-white text-gray-900 p-2 rounded" />
              </div>
              <div>
                <label class="text-sm text-white/80">New Time Out</label>
                <input type="time" name="corr_time_out" class="w-full bg-white text-gray-900 p-2 rounded" />
              </div>
              <div class="md:col-span-2">
                <label class="text-sm text-white/80">Reason</label>
                <input type="text" name="corr_reason" maxlength="255" class="w-full bg-white text-gray-900 p-2 rounded" placeholder="Brief explanation" required />
              </div>
              <div class="md:col-span-5 flex justify-end">
                <button class="bg-white text-blue-700 px-4 py-2 rounded">Submit Request</button>
              </div>
            </form>
          </div>
        </div>
        <div class="bg-white/10 p-5 rounded-xl">
          <h2 class="font-semibold mb-3">This Week</h2>
          <div class="space-y-2 text-sm">
            <?php if (empty($history)): ?>
              <div class="text-white/70">No records yet.</div>
            <?php else: foreach ($history as $h): ?>
              <div class="bg-white/5 rounded p-3 flex items-center justify-between">
                <div>
                  <div class="font-medium"><?php echo htmlspecialchars($h['work_date']); ?></div>
                  <div class="text-white/70 text-xs">In: <?php echo $h['time_in'] ? htmlspecialchars(substr($h['time_in'],0,5)) : '—'; ?> • Out: <?php echo $h['time_out'] ? htmlspecialchars(substr($h['time_out'],0,5)) : '—'; ?></div>
                </div>
                <div class="text-right">
                  <div class="text-white/90">Hrs: <?php echo $h['hours_worked'] !== null ? htmlspecialchars($h['hours_worked']) : '—'; ?></div>
                  <div class="text-white/70 text-xs capitalize"><?php echo htmlspecialchars($h['status'] ?? ''); ?></div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
    </div>
  </section>
  <main class="max-w-full px-4 -mt-6">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
      <h3 class="font-semibold mb-3">FAQ</h3>
      <ul class="list-disc ml-5 text-sm text-gray-600 space-y-1">
        <li>Clock in once at the start of your shift and clock out when you finish.</li>
        <li>Arrivals more than 15 minutes after shift start are marked Late.</li>
        <li>Hours and overtime are calculated automatically when you clock out.</li>
        <li>Use the Request Correction form for past record fixes; HR will review.</li>
      </ul>
    </div>
  </main>

  <?php include_once __DIR__ . '/../backend/core/footer.php'; ?>
  <script>
    (function(){
      function requestGeo(cb){
        if (!navigator.geolocation) { cb(null); return; }
        navigator.geolocation.getCurrentPosition(function(pos){
          cb({lat: pos.coords.latitude, lng: pos.coords.longitude});
        }, function(){ cb(null); }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 });
      }
      function stampClientClock(prefix){
        try {
          var now = new Date();
          var iso = now.toISOString();
          var tz = now.getTimezoneOffset(); // minutes west of UTC (e.g., Manila UTC+8 => -480)
          var isoEl = document.getElementById('clientTimeIso' + prefix);
          var tzEl = document.getElementById('clientTzOffset' + prefix);
          if (isoEl) isoEl.value = iso;
          if (tzEl) tzEl.value = tz;
        } catch (e) { /* ignore */ }
      }
      var inForm = document.getElementById('clockInForm');
      var outForm = document.getElementById('clockOutForm');
      if (inForm) {
        inForm.addEventListener('submit', function(e){
          // If fields already set, proceed
          var latEl = document.getElementById('clockInLat');
          var lngEl = document.getElementById('clockInLng');
          stampClientClock('In');
          if (latEl.value && lngEl.value) return;
          e.preventDefault();
          requestGeo(function(loc){
            if (loc){ latEl.value = loc.lat; lngEl.value = loc.lng; }
            inForm.submit();
          });
        });
      }
      if (outForm) {
        outForm.addEventListener('submit', function(e){
          var latEl = document.getElementById('clockOutLat');
          var lngEl = document.getElementById('clockOutLng');
          stampClientClock('Out');
          if (latEl.value && lngEl.value) return;
          e.preventDefault();
          requestGeo(function(loc){
            if (loc){ latEl.value = loc.lat; lngEl.value = loc.lng; }
            outForm.submit();
          });
        });
      }
    })();
  </script>

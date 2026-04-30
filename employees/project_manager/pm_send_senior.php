<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Basic auth: must be logged in and project_manager employee
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../../login.php'); exit; }
$position = strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? ''))));
if (($_SESSION['user_type'] ?? '') !== 'employee' || $position !== 'project_manager') { header('Location: ../../index.php'); exit; }

require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Ensure userId variable is initialized before queries
$userId = (int)($_SESSION['user_id'] ?? 0);
// Ensure pm_senior_files table exists for uploads
try {
  $db->exec("CREATE TABLE IF NOT EXISTS pm_senior_files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT NOT NULL,
    uploaded_by_employee_id INT NULL,
    design_phase VARCHAR(150) NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    relative_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NULL,
    size INT NULL,
    note TEXT NULL,
    status VARCHAR(50) DEFAULT 'pending',
    senior_comment TEXT NULL,
    reviewed_by_employee_id INT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_project (project_id),
    INDEX idx_uploader (uploaded_by_employee_id),
    INDEX idx_status (status),
    INDEX idx_uploaded_at (uploaded_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (Throwable $e) { /* ignore, later operations degrade gracefully */ }
// Determine PM employee id
try {
  $empStmt = $db->prepare('SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1');
  $empStmt->execute([$userId]);
  $row = $empStmt->fetch(PDO::FETCH_ASSOC);
  $employeeId = $row ? (int)$row['employee_id'] : 0;
} catch (Throwable $e) { $employeeId = 0; }

// Allow quick creation of minimal employee record if missing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_pm_employee_minimal' && $employeeId === 0) {
  try {
    $ins = $db->prepare("INSERT INTO employees (user_id, position, created_at) VALUES (?, 'Project Manager', NOW())");
    $ins->execute([$userId]);
    $employeeId = (int)$db->lastInsertId();
    header('Location: pm_send_senior.php?emp_created=1');
    exit;
  } catch (Throwable $ex) {
    // silent; could log error_log($ex->getMessage())
  }
}

// Fetch projects managed by PM for selection
$projects = [];
try {
  $pstmt = $db->prepare('SELECT project_id, project_name FROM projects WHERE project_manager_id = ? ORDER BY project_name ASC');
  $pstmt->execute([$employeeId]);
  $projects = $pstmt->fetchAll(PDO::FETCH_ASSOC);
  // Fallback: some schemas store user_id instead of employee_id in project_manager_id
  if (!$projects && $employeeId !== $userId && $userId > 0) {
    $pstmt2 = $db->prepare('SELECT project_id, project_name FROM projects WHERE project_manager_id = ? ORDER BY project_name ASC');
    $pstmt2->execute([$userId]);
    $projects = $pstmt2->fetchAll(PDO::FETCH_ASSOC);
  }
  // If employeeId is 0 (not found in employees table) but userId has projects mapped directly, treat those as owned
  if (!$projects && $employeeId === 0 && $userId > 0) {
    $pstmt3 = $db->prepare('SELECT project_id, project_name FROM projects WHERE project_manager_id = ? ORDER BY project_name ASC');
    $pstmt3->execute([$userId]);
    $projects = $pstmt3->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {}

  // Inline debug hint (commented out):
  // if (!$projects) { error_log('[pm_send_senior] No projects found for employee_id=' . $employeeId . ' user_id=' . $userId); }

// Static design phases (can be moved to a table later)
$designPhases = [
  'Pre-Design / Programming',
  'Schematic Design',
  'Design Development',
  'Final Design'
];

// One-time token to prevent double submission (CSRF + duplicate clicks)
if (empty($_SESSION['pm_upload_token'])) {
  try { $_SESSION['pm_upload_token'] = bin2hex(random_bytes(16)); } catch (Throwable $e) { $_SESSION['pm_upload_token'] = sha1(uniqid('', true)); }
}

$uploadMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pm_file'])) {
  $projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
  $phase = trim($_POST['design_phase'] ?? '');
  if ($phase !== '' && !in_array($phase, $designPhases)) { $phase = ''; }
  $note = trim($_POST['note'] ?? '');
  $file = $_FILES['pm_file'];
  $postedToken = $_POST['upload_token'] ?? '';

  if ($postedToken !== ($_SESSION['pm_upload_token'] ?? '')) {
    $uploadMsg = 'Invalid or expired submission token.';
  } else {
    $sig = hash('sha256', $projectId . '|' . $phase . '|' . ($file['name'] ?? '') . '|' . ($file['size'] ?? 0));
    $now = time();
    $recentDuplicate = isset($_SESSION['pm_last_upload_sig']) && $_SESSION['pm_last_upload_sig'] === $sig && isset($_SESSION['pm_last_upload_sig_time']) && ($now - $_SESSION['pm_last_upload_sig_time']) < 4;
    if ($recentDuplicate) {
      // Treat as already processed; just redirect to success without re-saving file
      header('Location: pm_send_senior.php?upload=success');
      exit;
    } else {
      if ($file['error'] === UPLOAD_ERR_OK) {
        $allowed = ['pdf','doc','docx','xls','xlsx','ppt','pptx','png','jpg','jpeg','zip'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
          $uploadMsg = 'File type not allowed.';
        } else {
          $owns = false; foreach ($projects as $p) { if ((int)$p['project_id'] === $projectId) { $owns = true; break; } }
          if (!$owns) {
            $uploadMsg = 'Invalid project.';
          } else {
            // Use unified directory /PMuploads per spec
            $dir = __DIR__ . '/../../PMuploads/';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $safeBase = preg_replace('/[^A-Za-z0-9._-]/','_', pathinfo($file['name'], PATHINFO_FILENAME));
            if ($safeBase === '') { $safeBase = 'file'; }
            $newName = 'pmproj_' . $projectId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '_' . $safeBase . '.' . $ext;
            $dest = $dir . $newName;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
              // Attempt DB insert into pm_senior_files (falls back silently if table missing)
              try {
                $relPath = 'PMuploads/' . $newName; // stored relative path for web serving
                $mime = $file['type'] ?? null;
                $size = $file['size'] ?? null;
                // status column may or may not exist yet; use dynamic column detection if needed
                // Simple insert with optional status if column exists
                $haveStatus = false;
                try {
                  $colChk = $db->query("SHOW COLUMNS FROM pm_senior_files LIKE 'status'");
                  $haveStatus = (bool)$colChk->fetch();
                } catch (Throwable $ignore) {}
                if ($haveStatus) {
                  $ins = $db->prepare("INSERT INTO pm_senior_files (project_id, uploaded_by_employee_id, design_phase, original_name, stored_name, relative_path, mime_type, size, note, status) VALUES (?,?,?,?,?,?,?,?,?,?)");
                  $ins->execute([$projectId, $employeeId, ($phase!==''?$phase:null), $file['name'], $newName, $relPath, $mime, $size, ($note!==''?$note:null), 'pending']);
                } else {
                  $ins = $db->prepare("INSERT INTO pm_senior_files (project_id, uploaded_by_employee_id, design_phase, original_name, stored_name, relative_path, mime_type, size, note) VALUES (?,?,?,?,?,?,?,?,?,?)");
                  $ins->execute([$projectId, $employeeId, ($phase!==''?$phase:null), $file['name'], $newName, $relPath, $mime, $size, ($note!==''?$note:null)]);
                }
              } catch (Throwable $dbEx) {
                // If table absent, still proceed (meta JSON optional legacy)
              }
              $_SESSION['pm_last_upload_sig'] = $sig;
              $_SESSION['pm_last_upload_sig_time'] = $now;
              $_SESSION['pm_upload_token'] = bin2hex(random_bytes(16));
              header('Location: pm_send_senior.php?upload=success');
              exit;
            } else {
              $uploadMsg = 'Failed to move uploaded file.';
            }
          }
        }
      } else {
        $uploadMsg = 'Upload error.';
      }
    }
  }
}

// Forward a revision request (status: revisions_requested -> forwarded)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forward_file_id'])) {
  $fwdId = (int)$_POST['forward_file_id'];
  try {
    // Confirm table + status column exists
    $hasStatusCol = false; $colChk = $db->query("SHOW COLUMNS FROM pm_senior_files LIKE 'status'");
    $hasStatusCol = (bool)$colChk->fetch();
    if ($hasStatusCol) {
      $chk = $db->prepare('SELECT id, uploaded_by_employee_id, status, project_id FROM pm_senior_files WHERE id=? LIMIT 1');
      $chk->execute([$fwdId]);
      $row = $chk->fetch(PDO::FETCH_ASSOC);
      if ($row && (int)$row['uploaded_by_employee_id'] === (int)$employeeId && ($row['status'] === 'revisions_requested')) {
        // Verify project has architect assigned (optional gate)
        $archId = null;
        try {
          $pstmtArch = $db->prepare('SELECT architect_id FROM projects WHERE project_id=? LIMIT 1');
          $pstmtArch->execute([(int)$row['project_id']]);
          $prow = $pstmtArch->fetch(PDO::FETCH_ASSOC);
          $archId = $prow ? (int)$prow['architect_id'] : null;
        } catch (Throwable $ign) {}
        // Only allow forward if architect assigned
        if (!empty($archId)) {
          $upd = $db->prepare("UPDATE pm_senior_files SET status='forwarded' WHERE id=?");
          $upd->execute([$fwdId]);
          header('Location: pm_send_senior.php?forward=1');
          exit;
        } else {
          $uploadMsg = 'Cannot forward: no architect assigned to project.';
        }
      } else {
        $uploadMsg = 'Invalid forward request.';
      }
    }
  } catch (Throwable $e) {
    $uploadMsg = 'Forward failed.';
  }
}

// List recent uploads (prefer DB, fallback to filesystem)
$recent = [];
try {
  // Discover optional columns for richer display
  $cols = [];
  try {
    $cRes = $db->query("SHOW COLUMNS FROM pm_senior_files");
    while ($c = $cRes->fetch(PDO::FETCH_ASSOC)) { $cols[$c['Field']] = true; }
  } catch (Throwable $ign) {}
  $select = [
    'id','project_id','design_phase','original_name','stored_name','relative_path','mime_type','size','note','uploaded_at'
  ];
  $hasStatus = isset($cols['status']);
  $hasComment = isset($cols['senior_comment']);
  $hasReviewedAt = isset($cols['reviewed_at']);
  $hasReviewer = isset($cols['reviewed_by_employee_id']);
  if ($hasStatus) { $select[] = "status"; }
  if ($hasComment) { $select[] = 'senior_comment'; }
  if ($hasReviewedAt) { $select[] = 'reviewed_at'; }
  if ($hasReviewer) { $select[] = 'reviewed_by_employee_id'; }
  $sql = 'SELECT ' . implode(',', $select) . ' FROM pm_senior_files WHERE uploaded_by_employee_id = ? ORDER BY uploaded_at DESC LIMIT 30';
  $list = $db->prepare($sql);
  $list->execute([$employeeId]);
  $recent = $list->fetchAll(PDO::FETCH_ASSOC);
  // Map project architects for quick forward button enablement
  $projectArchitectMap = [];
  if ($recent) {
    $pids = [];
    foreach ($recent as $r) { if (!empty($r['project_id'])) { $pids[(int)$r['project_id']] = true; } }
    if ($pids) {
      $in = implode(',', array_map('intval', array_keys($pids)));
      try {
        $pa = $db->query("SELECT project_id, architect_id FROM projects WHERE project_id IN ($in)");
        while ($pr = $pa->fetch(PDO::FETCH_ASSOC)) { $projectArchitectMap[(int)$pr['project_id']] = (int)$pr['architect_id']; }
      } catch (Throwable $ign) {}
    }
  }
  // If DB table is present but query returned zero rows, attempt legacy directories to show older uploads
  if (!$recent) {
    $legacyDirs = [__DIR__ . '/../../PMuploads/', __DIR__ . '/../../pm_senior_uploads/'];
    foreach ($legacyDirs as $legacy) {
      if (!is_dir($legacy)) continue;
      $files = array_diff(@scandir($legacy, SCANDIR_SORT_DESCENDING) ?: [], ['.','..']);
      $count = 0;
      foreach ($files as $f) {
        $path = $legacy . $f;
        if (!is_file($path)) continue;
        $metaPath = $path . '.meta.json';
        $meta = [];
        if (is_file($metaPath)) {
          $json = @file_get_contents($metaPath);
          $meta = json_decode($json, true) ?: [];
        }
        $recent[] = [
          'original_name' => $meta['original_name'] ?? $f,
          'stored_name' => $f,
          'design_phase' => $meta['design_phase'] ?? '',
          'size' => @filesize($path) ?: 0,
          'note' => $meta['note'] ?? '',
          'uploaded_at' => date('Y-m-d H:i:s', @filemtime($path) ?: time()),
          'status' => null,
          'relative_path' => (strpos($legacy,'pm_senior_uploads')!==false ? 'pm_senior_uploads/' : 'PMuploads/') . $f
        ];
        if (++$count >= 30) break;
      }
      if ($recent) break; // stop after first non-empty legacy dir
    }
  }
  if ($hasReviewer) {
    $ids = [];
    foreach ($recent as $r) { if (!empty($r['reviewed_by_employee_id'])) $ids[(int)$r['reviewed_by_employee_id']] = true; }
    if ($ids) {
      $in = implode(',', array_map('intval', array_keys($ids)));
      try {
        $revMap = [];
        $revQ = $db->query("SELECT e.employee_id, CONCAT(u.first_name,' ',u.last_name) AS full_name FROM employees e JOIN users u ON u.user_id=e.user_id WHERE e.employee_id IN ($in)");
        while ($row = $revQ->fetch(PDO::FETCH_ASSOC)) { $revMap[(int)$row['employee_id']] = $row['full_name']; }
        foreach ($recent as &$rr) {
          if (!empty($rr['reviewed_by_employee_id']) && isset($revMap[(int)$rr['reviewed_by_employee_id']])) {
            $rr['_reviewer_name'] = $revMap[(int)$rr['reviewed_by_employee_id']];
          }
        }
        unset($rr);
      } catch (Throwable $ign) {}
    }
  }
} catch (Throwable $e) {
  // Fallback legacy scan if table not present
  $dirScan = __DIR__ . '/../../PMuploads/';
  if (is_dir($dirScan)) {
    $files = array_diff(@scandir($dirScan, SCANDIR_SORT_DESCENDING) ?: [], ['.','..']);
    $count = 0;
    foreach ($files as $f) {
      $path = $dirScan . $f;
      if (is_file($path)) {
        $metaPath = $path . '.meta.json';
        $meta = [];
        if (is_file($metaPath)) {
          $json = @file_get_contents($metaPath);
            $meta = json_decode($json, true) ?: [];
        }
        $recent[] = [
          'original_name' => $meta['original_name'] ?? $f,
          'stored_name' => $f,
          'design_phase' => $meta['design_phase'] ?? '',
          'size' => filesize($path),
          'note' => $meta['note'] ?? '',
          'uploaded_at' => date('Y-m-d H:i:s', filemtime($path)),
          'status' => null,
          'relative_path' => 'PMuploads/' . $f
        ];
        if (++$count >= 30) break;
      }
    }
  }
}

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
  <div class="max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold mb-6">Send File to Senior Architect</h1>
    <?php if (isset($_GET['upload']) && $_GET['upload']==='success'): ?>
      <div class="mb-4 p-3 bg-green-50 text-green-700 ring-1 ring-green-200 rounded">Upload successful.</div>
    <?php endif; ?>
    <?php if ($uploadMsg): ?><div class="mb-4 p-3 bg-red-50 text-red-700 ring-1 ring-red-200 rounded"><?php echo htmlspecialchars($uploadMsg); ?></div><?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200 mb-8">
      <div class="p-5 border-b border-gray-100 font-semibold">New Upload</div>
      <form method="post" enctype="multipart/form-data" class="p-5 space-y-4" id="pmSendForm">
        <input type="hidden" name="upload_token" value="<?php echo htmlspecialchars($_SESSION['pm_upload_token'] ?? ''); ?>">
        <input type="hidden" name="confirm_upload" id="confirmUploadFlag" value="0">
        <div>
          <label class="block text-sm text-gray-600 mb-1">Project</label>
          <select name="project_id" class="w-full border rounded px-3 py-2" required>
            <option value="">-- Select Project --</option>
            <?php foreach ($projects as $p): ?>
              <option value="<?php echo (int)$p['project_id']; ?>"><?php echo htmlspecialchars($p['project_name']); ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$projects): ?>
            <div class="mt-2 rounded border border-amber-300 bg-amber-50 p-3 text-[11px] leading-snug text-amber-900">
              <div class="font-semibold mb-1">No projects detected for this account.</div>
              <ul class="list-disc ml-4 space-y-0.5">
                <li>Current user_id: <span class="font-mono"><?php echo (int)$userId; ?></span></li>
                <li>Resolved employee_id: <span class="font-mono"><?php echo (int)$employeeId; ?></span></li>
                <li>System expects projects.project_manager_id to match employee_id (preferred) or user_id (fallback).</li>
              </ul>
              <div class="mt-2 text-gray-700">
                Fix options:
                <ol class="list-decimal ml-4 mt-1 space-y-0.5">
                  <li>Create / verify employees row: <code class="font-mono">SELECT * FROM employees WHERE user_id=<?php echo (int)$userId; ?>;</code></li>
                  <li>If missing: insert then get employee_id.</li>
                  <li>Assign projects: <code class="font-mono">UPDATE projects SET project_manager_id=&lt;employee_id&gt; WHERE project_manager_id IN (0,<?php echo (int)$userId; ?>);</code></li>
                </ol>
              </div>
              <?php if ($employeeId === 0): ?>
                <form method="post" class="mt-2" onsubmit="return confirm('Create employee record and reload?');">
                  <input type="hidden" name="action" value="create_pm_employee_minimal">
                  <button class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded text-xs">Create Employee Record For Me</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <div id="designPhaseWrap" class="hidden">
          <label class="block text-sm text-gray-600 mb-1">Design Phase</label>
          <select name="design_phase" class="w-full border rounded px-3 py-2">
            <option value="">-- (Optional) Select Phase --</option>
            <?php foreach ($designPhases as $ph): ?>
              <option value="<?php echo htmlspecialchars($ph); ?>"><?php echo htmlspecialchars($ph); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-600 mb-1">File</label>
          <input id="pmFileInput" type="file" name="pm_file" required class="hidden" />
          <label for="pmFileInput" class="inline-block cursor-pointer px-4 py-2 bg-indigo-600 text-white rounded text-sm hover:bg-indigo-700">Choose File</label>
          <span id="fileNameDisplay" class="ml-2 text-xs text-gray-600 align-middle"></span>
          <p class="text-xs text-gray-500 mt-1">Allowed: pdf, doc/x, xls/x, ppt/x, png, jpg, jpeg, zip</p>
        </div>
        <div>
          <label class="block text-sm text-gray-600 mb-1">Note (optional)</label>
          <textarea name="note" rows="2" class="w-full border rounded px-3 py-2 text-sm" placeholder="Optional context for Senior Architect..."></textarea>
        </div>
        <div class="pt-2">
          <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Upload & Send</button>
        </div>
      </form>
    </div>

    <div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200">
      <div class="p-5 border-b border-gray-100 font-semibold">Recent Uploads (Latest 30)</div>
      <?php if (!$recent): ?>
        <div class="p-5 text-gray-500">No files uploaded yet.</div>
      <?php else: ?>
        <ul class="divide-y divide-gray-100">
          <?php foreach ($recent as $r): ?>
            <?php
              $phaseDisp = $r['design_phase'] ?? ($r['phase'] ?? '');
              $status = $r['status'] ?? null;
              $statusColor = 'bg-gray-200 text-gray-700';
              if ($status === 'pending') $statusColor = 'bg-amber-100 text-amber-700';
              elseif ($status === 'forwarded') $statusColor = 'bg-sky-100 text-sky-700';
              elseif ($status === 'reviewed') $statusColor = 'bg-green-100 text-green-700';
              elseif ($status === 'revisions_requested') $statusColor = 'bg-red-100 text-red-700';
              $fileUrl = '/ArchiFlow/' . ($r['relative_path'] ?? ('PMuploads/' . $r['stored_name']));
              $hasSeniorComment = !empty($r['senior_comment']);
              $reviewedAtFmt = !empty($r['reviewed_at']) ? date('M j, Y H:i', strtotime($r['reviewed_at'])) : '';
            ?>
            <li class="p-4 flex flex-col gap-3 md:flex-row md:items-center md:gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <div class="font-medium text-gray-900 truncate"><?php echo htmlspecialchars($r['original_name'] ?? $r['stored_name']); ?></div>
                  <?php if ($status): ?><span class="text-[10px] px-2 py-0.5 rounded-full <?php echo $statusColor; ?> capitalize"><?php echo htmlspecialchars(str_replace('_',' ',$status)); ?></span><?php endif; ?>
                </div>
                <div class="text-xs text-gray-500 mt-0.5">
                  <?php echo date('M j, Y H:i', strtotime($r['uploaded_at'] ?? (date('Y-m-d H:i:s')))); ?> • <?php echo isset($r['size'])?number_format(((int)$r['size'])/1024,1):'0'; ?> KB
                  <?php if (!empty($phaseDisp)): ?> • Phase: <span class="text-indigo-600"><?php echo htmlspecialchars($phaseDisp); ?></span><?php endif; ?>
                  <?php if (!empty($r['note'])): ?> • Note: <?php echo htmlspecialchars($r['note']); ?><?php endif; ?>
                </div>
                <?php if ($hasSeniorComment || $reviewedAtFmt): ?>
                  <div class="mt-1 text-xs bg-gray-50 border border-gray-200 rounded p-2 text-gray-700 leading-snug">
                    <?php if ($reviewedAtFmt): ?>
                      <div><span class="font-medium text-gray-600">Reviewed:</span> <?php echo htmlspecialchars($reviewedAtFmt); ?><?php if (!empty($r['_reviewer_name'])): ?> by <?php echo htmlspecialchars($r['_reviewer_name']); ?><?php endif; ?></div>
                    <?php endif; ?>
                    <?php if ($hasSeniorComment): ?>
                      <div><span class="font-medium text-gray-600">Senior Comment:</span> <?php echo nl2br(htmlspecialchars($r['senior_comment'])); ?></div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <a href="<?php echo htmlspecialchars($fileUrl); ?>" target="_blank" class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">View</a>
                <a href="<?php echo htmlspecialchars($fileUrl); ?>" download class="px-2 py-1 bg-green-600 text-white rounded text-xs hover:bg-green-700">Download</a>
                <?php if (!empty($status) && $status === 'revisions_requested' && !empty($r['senior_comment']) && !empty($r['project_id']) && !empty($projectArchitectMap[(int)$r['project_id']])): ?>
                  <form method="post" onsubmit="return confirm('Forward this revision request to the assigned architect?');" class="inline">
                    <input type="hidden" name="forward_file_id" value="<?php echo (int)$r['id']; ?>" />
                    <button type="submit" class="px-2 py-1 bg-amber-600 text-white rounded text-xs hover:bg-amber-700">Forward to Architect</button>
                  </form>
                <?php elseif (!empty($status) && $status === 'revisions_requested' && empty($projectArchitectMap[(int)($r['project_id'] ?? 0)])): ?>
                  <span class="text-[10px] text-amber-700 bg-amber-100 px-2 py-0.5 rounded">No architect assigned</span>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</main>
<script>
  // Show design phase once a project is chosen
  const projSelect = document.querySelector('select[name="project_id"]');
  const phaseWrap = document.getElementById('designPhaseWrap');
  if (projSelect && phaseWrap) {
    projSelect.addEventListener('change', () => {
      if (projSelect.value) phaseWrap.classList.remove('hidden');
      else phaseWrap.classList.add('hidden');
    });
  }
  // File input display
  const fileInput = document.getElementById('pmFileInput');
  const fileNameDisplay = document.getElementById('fileNameDisplay');
  if (fileInput) {
    fileInput.addEventListener('change', () => {
      if (fileInput.files && fileInput.files[0]) {
        fileNameDisplay.textContent = fileInput.files[0].name;
      } else {
        fileNameDisplay.textContent = '';
      }
    });
  }
  // Disable submit button on first submit to prevent double uploads
  const form = document.getElementById('pmSendForm');
  if (form) {
    form.addEventListener('submit', (e) => {
      const confirmFlag = document.getElementById('confirmUploadFlag');
      if (confirmFlag && confirmFlag.value !== '1') {
        e.preventDefault();
        // Build review summary
        const projSel = form.querySelector('select[name="project_id"]');
        const phaseSel = form.querySelector('select[name="design_phase"]');
        const noteTextarea = form.querySelector('textarea[name="note"]');
        const fileEl = document.getElementById('pmFileInput');
        if (!fileEl || !fileEl.files || !fileEl.files[0]) {
          alert('Please choose a file first.');
          return;
        }
        const f = fileEl.files[0];
        const projName = projSel && projSel.selectedIndex > -1 ? projSel.options[projSel.selectedIndex].text : '(none)';
        const phaseName = phaseSel && phaseSel.value ? phaseSel.options[phaseSel.selectedIndex].text : '—';
        const noteVal = (noteTextarea && noteTextarea.value.trim()) ? noteTextarea.value.trim() : '—';
        showReviewModal({
          project: projName,
            phase: phaseName,
          filename: f.name,
          size: (f.size/1024).toFixed(1) + ' KB',
          type: f.type || 'n/a',
          note: noteVal
        });
        return;
      }
      // Final submit: lock button
      const btn = form.querySelector('button[type="submit"]');
      if (btn && !btn.disabled) {
        btn.disabled = true;
        btn.classList.add('opacity-70','cursor-not-allowed');
        btn.textContent = 'Uploading...';
      }
    });
  }

  // Modal creation (lightweight, injected once)
  function ensureModal() {
    let modal = document.getElementById('uploadReviewModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'uploadReviewModal';
      modal.className = 'fixed inset-0 z-50 hidden';
      modal.innerHTML = `
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm"></div>
        <div class="relative max-w-lg mx-auto mt-24 bg-white rounded-xl shadow-lg ring-1 ring-slate-200 overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h2 class="font-semibold text-gray-800 text-lg">Review Upload</h2>
            <button type="button" id="reviewCloseBtn" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
          </div>
          <div class="p-5 space-y-4 text-sm" id="reviewContent"></div>
          <div class="px-5 py-4 bg-gray-50 flex items-center justify-end gap-3">
            <button type="button" id="reviewCancelBtn" class="px-4 py-2 rounded bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm">Cancel</button>
            <button type="button" id="reviewConfirmBtn" class="px-4 py-2 rounded bg-blue-600 hover:bg-blue-700 text-white text-sm">Confirm & Upload</button>
          </div>
        </div>`;
      document.body.appendChild(modal);
      // Wire buttons
      modal.querySelector('#reviewCloseBtn').addEventListener('click', hideReviewModal);
      modal.querySelector('#reviewCancelBtn').addEventListener('click', hideReviewModal);
      modal.querySelector('#reviewConfirmBtn').addEventListener('click', () => {
        const flag = document.getElementById('confirmUploadFlag');
        if (flag) flag.value = '1';
        hideReviewModal();
        // submit form now
        form.requestSubmit();
      });
    }
    return modal;
  }
  function showReviewModal(data) {
    const modal = ensureModal();
    const content = modal.querySelector('#reviewContent');
    content.innerHTML = `
      <div class="grid grid-cols-1 gap-3">
        <div><span class="font-medium text-gray-600">Project:</span> <span class="text-gray-900">${escapeHTML(data.project)}</span></div>
        <div><span class="font-medium text-gray-600">Design Phase:</span> <span class="text-gray-900">${escapeHTML(data.phase)}</span></div>
        <div><span class="font-medium text-gray-600">File Name:</span> <span class="text-gray-900">${escapeHTML(data.filename)}</span></div>
        <div><span class="font-medium text-gray-600">Size:</span> <span class="text-gray-900">${escapeHTML(data.size)}</span></div>
        <div><span class="font-medium text-gray-600">Type:</span> <span class="text-gray-900">${escapeHTML(data.type)}</span></div>
        <div><span class="font-medium text-gray-600">Note:</span> <span class="text-gray-900 whitespace-pre-line">${escapeHTML(data.note)}</span></div>
        ${previewBlock()}
      </div>`;
    // If image, show preview
    const fileEl = document.getElementById('pmFileInput');
    if (fileEl && fileEl.files && fileEl.files[0] && fileEl.files[0].type.startsWith('image/')) {
      const imgTag = modal.querySelector('#uploadImgPreview');
      if (imgTag) {
        imgTag.src = URL.createObjectURL(fileEl.files[0]);
      }
    }
    modal.classList.remove('hidden');
  }
  function hideReviewModal() {
    const modal = document.getElementById('uploadReviewModal');
    if (modal) modal.classList.add('hidden');
  }
  function escapeHTML(str) {
    return (str || '').toString().replace(/[&<>"] /g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',' ':' '}[s]));
  }
  function previewBlock() {
    const fileEl = document.getElementById('pmFileInput');
    if (fileEl && fileEl.files && fileEl.files[0] && fileEl.files[0].type.startsWith('image/')) {
      return '<div><span class="font-medium text-gray-600">Preview:</span><br><img id="uploadImgPreview" class="mt-1 max-h-48 rounded border" alt="Image preview" /></div>';
    }
    return '';
  }
</script>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

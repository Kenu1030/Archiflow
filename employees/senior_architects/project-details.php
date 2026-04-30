<?php
// Senior Architect Project Detail (view + edit + delete)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$APP_BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($APP_BASE === '/' || $APP_BASE === '.') { $APP_BASE = ''; }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ' . $APP_BASE . '/login.php'); exit; }
if (($_SESSION['user_type'] ?? '') !== 'employee' || strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? '')))) !== 'senior_architect') { header('Location: ' . $APP_BASE . '/index.php'); exit; }

require_once __DIR__ . '/../../backend/connection/connect.php';

$ALLOWED_PHASES = [
  'Pre-Design / Programming',
  'Schematic Design (SD)',
  'Design Development (DD)',
  'Final Design'
];
$ALLOWED_STATUSES = ['planning','design','construction','completed','cancelled'];
$statusMsg = '';
$errorMsg = '';
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

try {
  $db = getDB();
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  // Ensure phase column
  $c = $db->query("SHOW COLUMNS FROM projects LIKE 'phase'");
  if ($c->rowCount() === 0) { $db->exec("ALTER TABLE projects ADD COLUMN phase VARCHAR(64) DEFAULT 'Pre-Design / Programming'"); }
} catch (Throwable $e) { $errorMsg = 'DB init failed: ' . $e->getMessage(); }

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project']) && $projectId) {
  try {
    $db->beginTransaction();
    // naive cascade (same as list page logic)
    $tables = [];
    foreach ($db->query('SHOW TABLES') as $r) { $tables[] = array_values($r)[0]; }
    $has = function($n) use ($tables) { return in_array($n, $tables, true); };
    if ($has('tasks')) {
      if ($has('task_files')) { $db->prepare("DELETE tf FROM task_files tf JOIN tasks t ON t.task_id=tf.task_id WHERE t.project_id=?")->execute([$projectId]); }
      if ($has('task_messages')) { $db->prepare("DELETE tm FROM task_messages tm JOIN tasks t ON t.task_id=tm.task_id WHERE t.project_id=?")->execute([$projectId]); }
      if ($has('task_progress')) { $db->prepare("DELETE tp FROM task_progress tp JOIN tasks t ON t.task_id=tp.task_id WHERE t.project_id=?")->execute([$projectId]); }
      $db->prepare('DELETE FROM tasks WHERE project_id=?')->execute([$projectId]);
    }
    foreach (['project_files','milestones','project_users','project_contractors','contractor_updates','pm_senior_files','project_senior_architects'] as $tbl) {
      if ($has($tbl)) { $db->prepare("DELETE FROM $tbl WHERE project_id=?")->execute([$projectId]); }
    }
    $db->prepare('DELETE FROM projects WHERE project_id=? LIMIT 1')->execute([$projectId]);
    $db->commit();
    header('Location: projects.php?deleted=1');
    exit;
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $statusMsg = 'Delete failed: ' . htmlspecialchars($e->getMessage());
  }
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project']) && $projectId) {
  $name = trim($_POST['project_name'] ?? '');
  $desc = trim($_POST['description'] ?? '');
  $ptype = trim($_POST['project_type'] ?? '');
  if ($ptype === 'fit in' || $ptype === 'fit_in') { $ptype = 'fit_out'; }
  if ($ptype && !in_array($ptype, ['design_only','fit_out'], true)) { $ptype = 'design_only'; }
  $phase = trim($_POST['phase'] ?? '');
  $pstatus = trim($_POST['status'] ?? '');
  if ($name === '') { $statusMsg = 'Project name required'; }
  elseif ($phase && !in_array($phase, $ALLOWED_PHASES, true)) { $statusMsg = 'Invalid phase'; }
  elseif ($pstatus && !in_array($pstatus, $ALLOWED_STATUSES, true)) { $statusMsg = 'Invalid status'; }
  else {
    try {
      $stmtU = $db->prepare('UPDATE projects SET project_name=?, description=?, project_type=?, phase=?, status=? WHERE project_id=? LIMIT 1');
      $stmtU->execute([$name, $desc, $ptype, $phase, $pstatus, $projectId]);
      $statusMsg = 'Project updated successfully';
    } catch (Throwable $e) { $statusMsg = 'Update failed: ' . htmlspecialchars($e->getMessage()); }
  }
}

// Load project
$project = null;
if (!$errorMsg) {
  try {
    $stmt = $db->prepare("SELECT * FROM projects WHERE project_id=? LIMIT 1");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$project) { $errorMsg = 'Project not found'; }
  } catch (Throwable $e) { $errorMsg = $e->getMessage(); }
}

// Resolve Project Manager display info (name/email) from projects or project_users
$pmName = null; $pmEmail = null;
if (!$errorMsg && $project) {
  try {
    // Detect users PK and name expression
    $userCols = [];
    foreach ($db->query('SHOW COLUMNS FROM users') as $c) { $userCols[$c['Field']] = true; }
    $USERS_PK = isset($userCols['id']) ? 'id' : (isset($userCols['user_id']) ? 'user_id' : 'id');
    if (isset($userCols['full_name'])) { $NAME_EXPR = 'full_name'; }
    elseif (isset($userCols['first_name']) && isset($userCols['last_name'])) { $NAME_EXPR = "CONCAT(first_name,' ',last_name)"; }
    elseif (isset($userCols['username'])) { $NAME_EXPR = 'username'; }
    elseif (isset($userCols['email'])) { $NAME_EXPR = 'email'; }
    else { $NAME_EXPR = "''"; }
    $EMAIL_EXPR = isset($userCols['email']) ? 'email' : "NULL";

    // Determine PM id from projects table (support multiple possible columns)
    $pmId = null;
    foreach (['project_manager_id','manager_id','pm_id','pm_user_id','project_manager_user_id'] as $candCol) {
      if (array_key_exists($candCol, $project) && (int)$project[$candCol] > 0) { $pmId = (int)$project[$candCol]; break; }
    }

    if ($pmId) {
      $st = $db->prepare("SELECT $NAME_EXPR AS name, $EMAIL_EXPR AS email FROM users WHERE $USERS_PK = ? LIMIT 1");
      $st->execute([$pmId]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      $pmName = $row['name'] ?? null;
      $pmEmail = $row['email'] ?? null;
    }

    // Fallback: look in project_users for a project_manager role
    if (!$pmName) {
      // Ensure project_users exists
      try { $db->exec("CREATE TABLE IF NOT EXISTS project_users (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, user_id INT NOT NULL, role_in_project VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(project_id), INDEX(user_id)) ENGINE=InnoDB"); } catch (Throwable $ie) {}
      $sqlPU = "SELECT u.$USERS_PK AS id, $NAME_EXPR AS name, $EMAIL_EXPR AS email
                FROM project_users pu
                JOIN users u ON u.$USERS_PK = pu.user_id
                WHERE pu.project_id = ? AND (
                  LOWER(pu.role_in_project) IN ('project_manager','pm','manager') OR
                  LOWER(pu.role_in_project) LIKE '%manager%'
                )
                ORDER BY pu.id ASC LIMIT 1";
      $qpu = $db->prepare($sqlPU);
      $qpu->execute([$projectId]);
      $row = $qpu->fetch(PDO::FETCH_ASSOC);
      if ($row) {
        $pmName = (!empty($row['name']) ? $row['name'] : null);
        $pmEmail = $row['email'] ?? $pmEmail;
      }
    }
  } catch (Throwable $e) { /* ignore pm resolution errors */ }
}

function badge_class($status) {
  $map = [
    'planning' => 'bg-yellow-100 text-yellow-800',
    'design' => 'bg-blue-100 text-blue-800',
    'construction' => 'bg-purple-100 text-purple-800',
    'completed' => 'bg-green-100 text-green-800',
    'cancelled' => 'bg-red-100 text-red-800'
  ];
  return $map[strtolower($status)] ?? 'bg-gray-100 text-gray-800';
}

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-50">
  <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex items-center justify-between mb-8">
      <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-slate-900">Project Details (Senior Architect)</h1>
        <p class="text-slate-500 mt-1"><?php echo date('l, F j, Y'); ?></p>
      </div>
      <div class="flex items-center gap-2">
        <a href="projects.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 transition">
          <i class="fas fa-arrow-left"></i><span>Back</span>
        </a>
        <?php if ($project): ?>
        <form method="post" onsubmit="return confirm('Delete this project? This cannot be undone.');">
          <input type="hidden" name="delete_project" value="1">
          <button class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-red-600 text-white hover:bg-red-700 transition" type="submit">
            <i class="fas fa-trash"></i><span>Delete</span>
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($errorMsg) { ?>
      <div class="mb-6 p-4 rounded-lg ring-1 ring-red-200 bg-red-50 text-red-700 text-sm"><?php echo htmlspecialchars($errorMsg); ?></div>
    <?php } elseif ($project) { ?>
      <?php if ($statusMsg) { ?>
        <div class="mb-6 p-4 rounded-lg ring-1 <?php echo str_starts_with($statusMsg,'Project updated') ? 'ring-green-200 bg-green-50 text-green-700' : 'ring-slate-200 bg-slate-50 text-slate-700'; ?> text-sm"><?php echo htmlspecialchars($statusMsg); ?></div>
      <?php } ?>

      <form method="post" class="space-y-8 bg-white rounded-2xl ring-1 ring-slate-200 shadow-sm p-6">
        <input type="hidden" name="update_project" value="1">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Project Name</label>
            <input type="text" name="project_name" value="<?php echo htmlspecialchars($project['project_name'] ?? ''); ?>" class="w-full rounded border-slate-300 focus:ring-indigo-500 focus:border-indigo-500" required>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Project Type</label>
            <?php $pt = strtolower((string)($project['project_type'] ?? '')); ?>
            <select name="project_type" class="w-full rounded border-slate-300 focus:ring-indigo-500 focus:border-indigo-500">
              <option value="design_only" <?php echo ($pt==='design_only')?'selected':''; ?>>Design Only</option>
              <option value="fit_out" <?php echo ($pt==='fit_out' || $pt==='fit in' || $pt==='fit_in')?'selected':''; ?>>Fit In</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Phase</label>
            <select name="phase" class="w-full rounded border-slate-300 focus:ring-indigo-500 focus:border-indigo-500">
              <?php foreach ($ALLOWED_PHASES as $ph): ?>
                <option value="<?php echo htmlspecialchars($ph); ?>" <?php echo ($ph === ($project['phase'] ?? 'Pre-Design / Programming'))?'selected':''; ?>><?php echo htmlspecialchars($ph); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Status</label>
            <select name="status" class="w-full rounded border-slate-300 focus:ring-indigo-500 focus:border-indigo-500">
              <?php foreach ($ALLOWED_STATUSES as $st): ?>
                <option value="<?php echo htmlspecialchars($st); ?>" <?php echo ($st === ($project['status'] ?? ''))?'selected':''; ?>><?php echo htmlspecialchars(ucfirst($st)); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs font-medium text-slate-500 mb-1">Description</label>
            <textarea name="description" rows="4" class="w-full rounded border-slate-300 focus:ring-indigo-500 focus:border-indigo-500"><?php echo htmlspecialchars($project['description'] ?? ''); ?></textarea>
          </div>
        </div>
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <button type="submit" class="px-4 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-700">Save Changes</button>
        </div>
      </form>

      <section class="mt-10 bg-white rounded-2xl ring-1 ring-slate-200 shadow-sm p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-4">Summary</h2>
        <div class="flex flex-wrap gap-2 text-xs">
          <span class="px-2 py-1 rounded-full <?php echo badge_class($project['status']); ?>"><?php echo htmlspecialchars($project['status']); ?></span>
          <span class="px-2 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-100"><?php echo htmlspecialchars($project['phase'] ?? 'Pre-Design / Programming'); ?></span>
        </div>
        <div class="mt-4 text-sm text-slate-700">
          <div class="flex items-center gap-2">
            <i class="fas fa-user-tie text-slate-500"></i>
            <span class="text-slate-600">Project Manager:</span>
            <span class="font-medium"><?php echo htmlspecialchars($pmName ?: ($pmEmail ?: 'Unassigned')); ?></span>
            <?php if ($pmEmail): ?>
              <span class="text-xs text-slate-500">(<?php echo htmlspecialchars($pmEmail); ?>)</span>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <?php
      // Project extended metadata (schema tolerant) with optional debug instrumentation
      $debug = isset($_GET['debug']);
      $debugInfo = ['project'=>$project,'notes'=>[],'fallback'=>null,'matched_request'=>null];
      $meta = [];
      $taskSummary = ['total'=>0,'completed'=>0,'in_progress'=>0,'pending'=>0,'recent'=>[]];
      if ($project) {
        // Collect common columns from projects
        $possibleCols = [
          'project_id','id','location','location_text','address','site_location',
          'budget','budget_amount','estimated_budget','project_budget','total_budget','design_budget','design_fee','fee_estimate',
          'start_date','created_at','created_on'
        ];
        foreach ($possibleCols as $col) { if (array_key_exists($col, $project)) { $meta[$col] = $project[$col]; } }

        // Normalize budget numeric formatting
        $budgetVal = null; $budgetKey = null;
        foreach (['budget_amount','budget','estimated_budget','project_budget','total_budget','design_budget','design_fee','fee_estimate'] as $bcol) {
          if (isset($meta[$bcol]) && $meta[$bcol] !== '' && $meta[$bcol] !== null) { $budgetVal = $meta[$bcol]; $budgetKey = $bcol; break; }
        }
        if ($budgetVal !== null) {
          $budgetValClean = preg_replace('/[^0-9.]/','',$budgetVal);
          if ($budgetValClean === '' && is_numeric($budgetVal)) { $budgetValClean = (string)$budgetVal; }
          $meta['__budget_clean'] = $budgetValClean;
          $meta['__budget_key'] = $budgetKey;
        } else {
          $meta['__budget_clean'] = null;
          $meta['__budget_key'] = null;
        }

        // Fallback: derive budget/location/start from project_requests as needed
        try {
          $needsBudget = ($meta['__budget_clean'] === null);
          $needsLocation = (empty($meta['location']) && empty($meta['location_text']) && empty($meta['address']) && empty($meta['site_location']));
          $invalidDates = ["0000-00-00","0000-00-00 00:00:00","1970-01-01","0001-01-01"];
          $needsStart = (empty($meta['start_date']) || in_array($meta['start_date'], $invalidDates, true));
          if ($needsBudget || $needsLocation || $needsStart) {
            $chkPR = $db->query("SHOW TABLES LIKE 'project_requests'");
            if ($chkPR && $chkPR->rowCount() > 0) {
              // Detect client id from possible columns
              $clientId = 0;
              foreach (['client_id','clientid','client_user_id','customer_id','user_id'] as $cidCol) {
                if (isset($project[$cidCol]) && (int)$project[$cidCol] > 0) { $clientId = (int)$project[$cidCol]; break; }
              }
              if ($clientId > 0) {
                $prCols = [];
                foreach ($db->query('SHOW COLUMNS FROM project_requests') as $c) { $prCols[$c['Field']] = true; }
                $budgetCols = array_filter(['budget','estimated_budget','budget_amount','project_budget','total_budget','design_budget','design_fee','fee_estimate','quotation_amount','project_cost'], fn($n)=> isset($prCols[$n]));
                $locCols    = array_filter(['location','site','site_location'], fn($n)=> isset($prCols[$n]));
                $startCols  = array_filter(['preferred_start_date','start_date','preferred_date','proposed_start','target_start'], fn($n)=> isset($prCols[$n]));
                $selCols = implode(',', array_unique(array_merge($budgetCols, $locCols, $startCols, ['id','project_name'])));
                // Fetch several recent requests to choose best per field
                $sqlMulti = "SELECT $selCols FROM project_requests WHERE client_id = ? ORDER BY created_at DESC LIMIT 10";
                $stmMulti = $db->prepare($sqlMulti); $stmMulti->execute([$clientId]);
                $rowsPR = $stmMulti->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if ($debug) { $debugInfo['fallback'] = ['mode'=>'multi-scan','rows_found'=>count($rowsPR),'budgetCols'=>$budgetCols,'locCols'=>$locCols,'startCols'=>$startCols]; }
                $chosen = ['budget'=>null,'budget_key'=>null,'location'=>null,'start'=>null,'budget_request_id'=>null,'location_request_id'=>null,'start_request_id'=>null];
                foreach ($rowsPR as $r) {
                  // Budget pick
                  if ($needsBudget && $chosen['budget'] === null) {
                    foreach ($budgetCols as $bc) {
                      if (isset($r[$bc]) && $r[$bc] !== '' && $r[$bc] !== null) {
                        $bv = preg_replace('/[^0-9.]/','', (string)$r[$bc]);
                        if ($bv !== '') { $chosen['budget'] = $bv; $chosen['budget_key'] = $bc; $chosen['budget_request_id'] = $r['id'] ?? null; break; }
                      }
                    }
                  }
                  // Location pick
                  if ($needsLocation && $chosen['location'] === null) {
                    foreach ($locCols as $lc) { if (!empty($r[$lc])) { $chosen['location'] = $r[$lc]; $chosen['location_request_id'] = $r['id'] ?? null; break; } }
                  }
                  // Start date pick
                  if ($needsStart && $chosen['start'] === null) {
                    foreach ($startCols as $sc) { if (!empty($r[$sc]) && !in_array($r[$sc],["0000-00-00","0000-00-00 00:00:00","1970-01-01","0001-01-01"], true)) { $chosen['start'] = $r[$sc]; $chosen['start_request_id'] = $r['id'] ?? null; break; } }
                  }
                  // Exit early if all chosen
                  if ((!$needsBudget || $chosen['budget'] !== null) && (!$needsLocation || $chosen['location'] !== null) && (!$needsStart || $chosen['start'] !== null)) { break; }
                }
                if ($needsBudget && $chosen['budget'] !== null) { $meta['__budget_clean'] = $chosen['budget']; $meta['__budget_key'] = $chosen['budget_key'] . ' (request #' . $chosen['budget_request_id'] . ')'; }
                if ($needsLocation && $chosen['location'] !== null) { $meta['__location_fallback'] = $chosen['location']; }
                if ($needsStart && $chosen['start'] !== null) { $meta['__start_date_fallback'] = $chosen['start']; }
                if ($debug) {
                  if ($needsBudget && $chosen['budget'] === null) { $debugInfo['notes'][] = 'Multi-scan found no usable budget.'; }
                  if ($needsLocation && $chosen['location'] === null) { $debugInfo['notes'][] = 'Multi-scan found no usable location.'; }
                  if ($needsStart && $chosen['start'] === null) { $debugInfo['notes'][] = 'Multi-scan found no usable start date.'; }
                  $debugInfo['matched_request'] = ['budget_request_id'=>$chosen['budget_request_id'],'location_request_id'=>$chosen['location_request_id'],'start_request_id'=>$chosen['start_request_id']];
                }
              } else { if ($debug) { $debugInfo['notes'][] = 'Client_id missing for fallback.'; } }
            } else { if ($debug) { $debugInfo['notes'][] = 'project_requests table not found.'; } }
          }
        } catch (Throwable $eBF) { if ($debug) { $debugInfo['notes'][] = 'Fallback exception: '.$eBF->getMessage(); } }

        // Tasks summary (schema tolerant)
        try {
          $colsT = [];
          foreach ($db->query('SHOW COLUMNS FROM tasks') as $c) { $colsT[$c['Field']] = true; }
          $TASK_PID_COL = isset($colsT['project_id']) ? 'project_id' : (isset($colsT['proj_id']) ? 'proj_id' : null);
          if ($TASK_PID_COL) {
            $TASK_STATUS_COL = isset($colsT['status']) ? 'status' : (isset($colsT['task_status']) ? 'task_status' : null);
            $TASK_TITLE_COL = isset($colsT['title']) ? 'title' : (isset($colsT['task_title']) ? 'task_title' : null);
            $TASK_ID_COL = isset($colsT['id']) ? 'id' : (isset($colsT['task_id']) ? 'task_id' : 'id');
            $TASK_TS_COL = isset($colsT['created_at']) ? 'created_at' : (isset($colsT['created_on']) ? 'created_on' : (isset($colsT['timestamp']) ? 'timestamp' : null));
            if ($TASK_STATUS_COL) {
              $countSql = "SELECT COUNT(*) AS total,\n                    SUM(CASE WHEN LOWER($TASK_STATUS_COL) IN ('completed','done') THEN 1 ELSE 0 END) AS completed,\n                    SUM(CASE WHEN LOWER($TASK_STATUS_COL) IN ('in-progress','in_progress','progress') THEN 1 ELSE 0 END) AS in_progress,\n                    SUM(CASE WHEN LOWER($TASK_STATUS_COL) IN ('pending','new','open') THEN 1 ELSE 0 END) AS pending\n                    FROM tasks WHERE $TASK_PID_COL = ?";
              $ct = $db->prepare($countSql); $ct->execute([$projectId]); $rowT = $ct->fetch(PDO::FETCH_ASSOC) ?: [];
              $taskSummary['total'] = (int)($rowT['total'] ?? 0);
              $taskSummary['completed'] = (int)($rowT['completed'] ?? 0);
              $taskSummary['in_progress'] = (int)($rowT['in_progress'] ?? 0);
              $taskSummary['pending'] = (int)($rowT['pending'] ?? 0);
            } else {
              $ct = $db->prepare("SELECT COUNT(*) AS total FROM tasks WHERE $TASK_PID_COL = ?");
              $ct->execute([$projectId]); $rowT = $ct->fetch(PDO::FETCH_ASSOC) ?: [];
              $taskSummary['total'] = (int)($rowT['total'] ?? 0);
            }
            $titleExpr = $TASK_TITLE_COL ? $TASK_TITLE_COL : "CONCAT('Task #',$TASK_ID_COL)";
            $statusExpr = $TASK_STATUS_COL ? $TASK_STATUS_COL : "''";
            $tsExpr = $TASK_TS_COL ? $TASK_TS_COL : "NOW()";
            $recentSql = "SELECT $TASK_ID_COL AS task_id, $titleExpr AS title, $statusExpr AS status, $tsExpr AS ts FROM tasks WHERE $TASK_PID_COL = ? ORDER BY $tsExpr DESC LIMIT 5";
            $rt = $db->prepare($recentSql); $rt->execute([$projectId]);
            $taskSummary['recent'] = $rt->fetchAll(PDO::FETCH_ASSOC);
          }
        } catch (Throwable $eTasks) { /* ignore */ }
      }

      // Build participants list (schema tolerant) to avoid undefined variable
      $participants = [];
      if ($project) {
        try {
          $colsU = [];
          foreach ($db->query('SHOW COLUMNS FROM users') as $c) { $colsU[$c['Field']] = true; }
          $USERS_PK2 = isset($colsU['id']) ? 'id' : (isset($colsU['user_id']) ? 'user_id' : 'id');
          if (isset($colsU['full_name'])) { $NAME_EXPR2 = 'full_name'; }
          elseif (isset($colsU['first_name']) && isset($colsU['last_name'])) { $NAME_EXPR2 = "CONCAT(first_name,' ',last_name)"; }
          elseif (isset($colsU['username'])) { $NAME_EXPR2 = 'username'; }
          elseif (isset($colsU['email'])) { $NAME_EXPR2 = 'email'; }
          else { $NAME_EXPR2 = "CONCAT('User #', $USERS_PK2)"; }
          $EMAIL_EXPR2 = isset($colsU['email']) ? 'email' : "NULL";

          // Ensure project_users table exists
          try { $db->exec("CREATE TABLE IF NOT EXISTS project_users (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, user_id INT NOT NULL, role_in_project VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(project_id), INDEX(user_id)) ENGINE=InnoDB"); } catch (Throwable $ie) {}

          $sqlP = "SELECT pu.user_id, $NAME_EXPR2 AS name, $EMAIL_EXPR2 AS email, pu.role_in_project
                   FROM project_users pu
                   JOIN users u ON u.$USERS_PK2 = pu.user_id
                   WHERE pu.project_id = ?
                   ORDER BY pu.id ASC";
          $pp = $db->prepare($sqlP); $pp->execute([$projectId]);
          $participants = $pp->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $ePart) { $participants = []; }
      }
      ?>

      <section class="mt-10 bg-white rounded-2xl ring-1 ring-slate-200 shadow-sm p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-4">Project Info</h2>
        <?php if ($debug): ?>
          <div class="mb-4 p-3 rounded bg-yellow-50 ring-1 ring-yellow-200 text-xs text-yellow-800 space-y-2">
            <div class="font-semibold">Debug Inspector</div>
            <div><strong>Budget Key:</strong> <?php echo htmlspecialchars($meta['__budget_key'] ?? 'none'); ?> | <strong>Budget Clean:</strong> <?php echo htmlspecialchars($meta['__budget_clean'] ?? 'null'); ?></div>
            <div><strong>Location Fallback:</strong> <?php echo htmlspecialchars($meta['__location_fallback'] ?? 'none'); ?> | <strong>Start Fallback:</strong> <?php echo htmlspecialchars($meta['__start_date_fallback'] ?? 'none'); ?></div>
            <?php if (!empty($debugInfo['fallback'])): ?>
              <details class="p-2 bg-yellow-100/50 rounded"><summary class="cursor-pointer">Fallback Query Details</summary>
                <pre class="whitespace-pre-wrap max-h-64 overflow-auto"><?php echo htmlspecialchars(print_r($debugInfo['fallback'], true)); ?></pre>
              </details>
            <?php endif; ?>
            <?php if (!empty($debugInfo['matched_request'])): ?>
              <details class="p-2 bg-yellow-100/50 rounded"><summary class="cursor-pointer">Matched Request Row</summary>
                <pre class="whitespace-pre-wrap max-h-64 overflow-auto"><?php echo htmlspecialchars(print_r($debugInfo['matched_request'], true)); ?></pre>
              </details>
            <?php endif; ?>
            <?php if (!empty($debugInfo['notes'])): ?>
              <ul class="list-disc ml-5">
                <?php foreach ($debugInfo['notes'] as $n): ?><li><?php echo htmlspecialchars($n); ?></li><?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="grid md:grid-cols-2 gap-4 text-sm">
          <div>
            <div class="text-slate-500">Project ID</div>
            <div class="font-medium text-slate-900"><?php echo htmlspecialchars($project['project_id'] ?? ($project['id'] ?? '—')); ?></div>
          </div>
          <div>
            <div class="text-slate-500">Location</div>
            <div class="font-medium text-slate-900"><?php
              $locPrimary = $meta['location'] ?? ($meta['location_text'] ?? ($meta['address'] ?? ($meta['site_location'] ?? '')));
              $locFinal = $locPrimary !== '' ? $locPrimary : ($meta['__location_fallback'] ?? '—');
              echo htmlspecialchars($locFinal !== '' ? $locFinal : '—');
            ?></div>
          </div>
          <div>
            <div class="text-slate-500">Budget</div>
            <div class="font-medium text-slate-900"><?php echo ($meta['__budget_clean'] !== null && $meta['__budget_clean'] !== '') ? '₱' . number_format((float)$meta['__budget_clean'],2) : '—'; ?></div>
          </div>
          <div>
            <div class="text-slate-500">Start Date</div>
            <div class="font-medium text-slate-900"><?php
              $rawStart = $meta['start_date'] ?? '';
              $startInvalidSet = ['0000-00-00','0000-00-00 00:00:00','1970-01-01','0001-01-01'];
              if ($rawStart === '' || in_array($rawStart, $startInvalidSet, true)) {
                $sd = $meta['__start_date_fallback'] ?? '';
              } else { $sd = $rawStart; }
              if ($sd && !in_array($sd,$startInvalidSet, true)) {
                $ts = strtotime($sd);
                echo $ts ? htmlspecialchars(date('M j, Y',$ts)) : '—';
              } else { echo '—'; }
            ?></div>
          </div>
          <div>
            <div class="text-slate-500">Created</div>
            <div class="font-medium text-slate-900"><?php
              $cd = $meta['created_at'] ?? ($meta['created_on'] ?? '');
              if ($cd && !in_array($cd,['0000-00-00','0000-00-00 00:00:00','1970-01-01','0001-01-01'], true)) {
                $cts = strtotime($cd);
                echo $cts ? htmlspecialchars(date('M j, Y',$cts)) : '—';
              } else { echo '—'; }
            ?></div>
          </div>
        </div>
      </section>

      <?php if ($participants): ?>
      <section class="mt-10 bg-white rounded-2xl ring-1 ring-slate-200 shadow-sm p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-4">Participants</h2>
        <ul class="divide-y divide-slate-200 text-sm">
          <?php foreach ($participants as $p): ?>
            <li class="py-3 flex items-center justify-between">
              <div class="flex flex-col">
                <span class="font-medium text-slate-800"><?php echo htmlspecialchars($p['name'] ?: ('User #' . (int)$p['user_id'])); ?></span>
                <?php if (!empty($p['email'])): ?><span class="text-xs text-slate-500"><?php echo htmlspecialchars($p['email']); ?></span><?php endif; ?>
              </div>
              <span class="text-xs px-2 py-1 rounded-full bg-slate-100 text-slate-700 border border-slate-200"><?php echo htmlspecialchars($p['role_in_project'] ?: 'member'); ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <section class="mt-10 bg-white rounded-2xl ring-1 ring-slate-200 shadow-sm p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-4">Tasks Overview</h2>
        <?php if ($taskSummary['total'] === 0): ?>
          <p class="text-sm text-slate-500">No tasks found for this project.</p>
        <?php else: ?>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6 text-center">
            <div class="p-4 rounded-lg bg-slate-50 ring-1 ring-slate-200">
              <div class="text-xs text-slate-500">Total</div>
              <div class="text-xl font-semibold text-slate-800"><?php echo (int)$taskSummary['total']; ?></div>
            </div>
            <div class="p-4 rounded-lg bg-green-50 ring-1 ring-green-200">
              <div class="text-xs text-green-700">Completed</div>
              <div class="text-xl font-semibold text-green-800"><?php echo (int)$taskSummary['completed']; ?></div>
            </div>
            <div class="p-4 rounded-lg bg-blue-50 ring-1 ring-blue-200">
              <div class="text-xs text-blue-700">In Progress</div>
              <div class="text-xl font-semibold text-blue-800"><?php echo (int)$taskSummary['in_progress']; ?></div>
            </div>
            <div class="p-4 rounded-lg bg-yellow-50 ring-1 ring-yellow-200">
              <div class="text-xs text-yellow-700">Pending</div>
              <div class="text-xl font-semibold text-yellow-800"><?php echo (int)$taskSummary['pending']; ?></div>
            </div>
          </div>
          <h3 class="text-sm font-semibold text-slate-700 mb-2">Recent Tasks</h3>
          <ul class="divide-y divide-slate-200 text-sm">
            <?php foreach ($taskSummary['recent'] as $t): ?>
              <li class="py-3 flex items-center justify-between">
                <div class="flex flex-col">
                  <span class="font-medium text-slate-800"><?php echo htmlspecialchars($t['title'] ?? ('Task #' . (int)($t['task_id'] ?? 0))); ?></span>
                  <?php $tsVal = isset($t['ts']) && $t['ts'] ? strtotime($t['ts']) : null; ?>
                  <span class="text-xs text-slate-500"><?php echo $tsVal ? htmlspecialchars(date('M j, Y', $tsVal)) : '—'; ?></span>
                </div>
                <?php $tsClass = 'bg-slate-100 text-slate-700';
                  $ts = strtolower((string)($t['status'] ?? ''));
                  if (in_array($ts,['done','completed'])) $tsClass='bg-green-100 text-green-800';
                  elseif (in_array($ts,['in_progress','in-progress','ongoing'])) $tsClass='bg-blue-100 text-blue-800';
                  elseif (in_array($ts,['pending','new','open'])) $tsClass='bg-yellow-100 text-yellow-800';
                ?>
                <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $tsClass; ?>"><?php echo htmlspecialchars(str_replace('_',' ', $t['status'] ?? '')); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    <?php } ?>
  </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

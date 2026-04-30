<?php
// View All Tasks for a Project (styled like project_details.php)
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
include __DIR__ . '/db.php';

// Detect projects primary key (id vs project_id)
$projects_pk = 'id';
try {
    if ($res = $conn->query("SHOW COLUMNS FROM projects LIKE 'id'")) {
        if ($res->num_rows === 0) {
            if ($res2 = $conn->query("SHOW COLUMNS FROM projects LIKE 'project_id'")) {
                if ($res2->num_rows > 0) { $projects_pk = 'project_id'; }
                $res2->free();
            }
        }
        $res->free();
    }
} catch (Throwable $e) { /* keep default */ }

// Validate & get project id
if (!isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) { echo 'Invalid project.'; exit(); }
$project_id = (int)$_GET['project_id'];

// Pull the row
$stmt = $conn->prepare("SELECT * FROM projects WHERE $projects_pk = ? LIMIT 1");
$stmt->bind_param('i', $project_id);
$stmt->execute();
$res = $stmt->get_result();
$project = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$project) { echo 'Project not found.'; exit(); }

$pname   = (string)($project['project_name'] ?? ($project['name'] ?? ('Project #'.$project_id)));
$pdesc   = (string)($project['description'] ?? '');
$pcode   = (string)($project['project_code'] ?? '');
$ptype   = (string)($project['project_type'] ?? '');
$pstatus = (string)($project['status'] ?? '');
$start   = (string)($project['start_date'] ?? '');
$end     = (string)($project['estimated_end_date'] ?? ($project['end_date'] ?? ''));
$budget  = (string)($project['budget_amount'] ?? ($project['budget'] ?? ''));
$created = (string)($project['created_at'] ?? '');

// Header/footer logic
$page_title = 'All Tasks for ' . htmlspecialchars($pname);
$isEmployee = (($_SESSION['user_type'] ?? '') === 'employee');
$positionRaw = (string)($_SESSION['position'] ?? '');
$position = strtolower($positionRaw);
$positionNormalized = strtolower(str_replace(' ', '_', trim($positionRaw)));
$isPM = ($position === 'project_manager') || ($positionNormalized === 'project_manager') || (($_SESSION['role'] ?? '') === 'project_manager');
$employeeHeader = __DIR__ . '/backend/core/header.php';
$employeeFooter = __DIR__ . '/backend/core/footer.php';
$sharedHeader   = __DIR__ . '/includes/header.php';
$sharedFooter   = __DIR__ . '/includes/footer.php';

// DEBUG: Show raw tasks query result
$debugTasksRaw = [];
try {
  if ($chk = $conn->query("SHOW TABLES LIKE 'tasks'")) {
    if ($chk->num_rows > 0) {
      $sqlDebug = "SELECT * FROM tasks WHERE project_id = ?";
      if ($stDebug = $conn->prepare($sqlDebug)) {
        $stDebug->bind_param('i', $project_id);
        if ($stDebug->execute()) {
          $rtDebug = $stDebug->get_result();
          if ($rtDebug) {
            $debugTasksRaw = $rtDebug->fetch_all(MYSQLI_ASSOC);
          }
        }
        $stDebug->close();
      }
    }
    $chk->free();
  }
} catch (Throwable $e) { $debugTasksRaw = ['error' => $e->getMessage()]; }

if ($isEmployee && file_exists($employeeHeader)) {
  include $employeeHeader;
} else {
  include $sharedHeader;
}
?>
<main id="content-wrapper" class="flex-1 p-6">
  <div class="max-w-5xl mx-auto">
    <div class="mb-6">
      <a href="project_details.php?project_id=<?php echo (int)$project_id; ?>" class="text-blue-600 hover:underline">&larr; Back to Project Details</a>
      <h1 class="text-2xl font-bold text-gray-900 mt-2"><?php echo htmlspecialchars($pname); ?></h1>
      <?php if ($pstatus !== ''): ?>
        <span class="inline-block mt-2 px-2 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-100"><?php echo htmlspecialchars($pstatus); ?></span>
      <?php endif; ?>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
        <?php if ($pcode !== ''): ?>
          <div>
            <div class="text-gray-500 text-sm">Project Code</div>
            <div class="font-medium"><?php echo htmlspecialchars($pcode); ?></div>
          </div>
        <?php endif; ?>
        <?php if ($ptype !== ''): ?>
          <div>
            <div class="text-gray-500 text-sm">Type</div>
            <div class="font-medium"><?php echo htmlspecialchars($ptype); ?></div>
          </div>
        <?php endif; ?>
        <?php if ($start !== ''): ?>
          <div>
            <div class="text-gray-500 text-sm">Start Date</div>
            <div class="font-medium"><?php echo htmlspecialchars($start); ?></div>
          </div>
        <?php endif; ?>
        <?php if ($end !== ''): ?>
          <div>
            <div class="text-gray-500 text-sm">Deadline</div>
            <div class="font-medium"><?php echo htmlspecialchars($end); ?></div>
          </div>
        <?php endif; ?>
        <?php if ($budget !== ''): ?>
          <div>
            <div class="text-gray-500 text-sm">Budget</div>
            <div class="font-medium"><?php echo htmlspecialchars($budget); ?></div>
          </div>
        <?php endif; ?>
        <?php if ($created !== ''): ?>
          <div>
            <div class="text-gray-500 text-sm">Created At</div>
            <div class="font-medium"><?php echo htmlspecialchars($created); ?></div>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($pdesc !== ''): ?>
        <div class="mt-6">
          <div class="text-gray-500 text-sm">Description</div>
          <div class="mt-1 whitespace-pre-line"><?php echo nl2br(htmlspecialchars($pdesc)); ?></div>
        </div>
      <?php endif; ?>
    </div>
    <div class="bg-white/80 backdrop-blur-glass border border-white/40 rounded-xl p-6 shadow-sm">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">All Tasks</h2>
      <?php
      // Handle PM revision request (set status to Revise + store note)
      if ($isPM && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['rev_task_id']) && ctype_digit((string)$_POST['rev_task_id'])) {
        $revTaskId = (int)$_POST['rev_task_id'];
        $revNote = trim($_POST['rev_note'] ?? '');
        try {
          if ($chkTask = $conn->prepare('SELECT id,title,status FROM tasks WHERE (id=? OR id=?) AND project_id=? LIMIT 1')) { // id used both places
            $chkTask->bind_param('iii',$revTaskId,$revTaskId,$project_id);
            if ($chkTask->execute()) {
              $resT = $chkTask->get_result();
              $trow = $resT?$resT->fetch_assoc():null;
            }
            $chkTask->close();
            if (!empty($trow)) {
              // Add Revise to enum if needed
              if ($colInfo = $conn->query("SHOW COLUMNS FROM tasks LIKE 'status'")) {
                if ($colMeta = $colInfo->fetch_assoc()) {
                  if (strpos($colMeta['Type'],'Revise') === false) {
                    @ $conn->query("ALTER TABLE tasks MODIFY status ENUM('To Do','In Progress','Done','Revise') DEFAULT 'To Do'");
                  }
                }
                $colInfo->free();
              }
              @ $conn->query("UPDATE tasks SET status='Revise', updated_at=NOW() WHERE id=".$revTaskId);
              // Ensure task_revisions table
              $revTableExists = false; $chkRev = $conn->query("SHOW TABLES LIKE 'task_revisions'");
              if ($chkRev && $chkRev->num_rows>0) { $revTableExists=true; $chkRev->free(); }
              if (!$revTableExists) {
                @ $conn->query("CREATE TABLE task_revisions (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, note TEXT NULL, requested_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
              }
              if ($revNote !== '') {
                if ($insRev = $conn->prepare('INSERT INTO task_revisions (task_id,note,requested_by) VALUES (?,?,?)')) {
                  $reqBy = (int)($_SESSION['user_id'] ?? 0);
                  $insRev->bind_param('isi',$revTaskId,$revNote,$reqBy);
                  $insRev->execute(); $insRev->close();
                }
              }
            }
          }
        } catch (Throwable $e) { /* swallow */ }
        header('Location: view_all_tasks.php?project_id='.$project_id.'&rev=1');
        exit;
      }

      // Get tasks grouped by phase (dynamic column detection for schema variants)
      $tasksByPhase = [];
      $totalTasks = 0;
      $TASK_ID_COL = 'id';
      $TASK_NAME_COL = 'title';
      $HAS_PHASE = true; $HAS_DUE = true; $HAS_STATUS = true; $HAS_ASSIGNED = true;
      try {
        $taskCols = [];
        if ($rsCols = $conn->query("SHOW COLUMNS FROM tasks")) {
          while ($rc = $rsCols->fetch_assoc()) { $taskCols[$rc['Field']] = $rc; }
          $rsCols->free();
        }
        if (isset($taskCols['task_id'])) $TASK_ID_COL = 'task_id';
        if (isset($taskCols['task_name'])) $TASK_NAME_COL = 'task_name';
        elseif (!isset($taskCols['title']) && isset($taskCols['name'])) $TASK_NAME_COL = 'name';
        $HAS_PHASE = isset($taskCols['phase']);
        $HAS_DUE = isset($taskCols['due_date']);
        $HAS_STATUS = isset($taskCols['status']);
        $HAS_ASSIGNED = isset($taskCols['assigned_to']);

        $phaseExpr = $HAS_PHASE ? "COALESCE(phase,'Pre-Design / Programming')" : "'Pre-Design / Programming'";
        $selectCols = [$TASK_ID_COL . ' AS task_id', $TASK_NAME_COL . ' AS task_name'];
        if ($HAS_STATUS) $selectCols[] = 'status'; else $selectCols[] = "'To Do' AS status";
        if ($HAS_DUE) $selectCols[] = 'due_date'; else $selectCols[] = "NULL AS due_date";
        if ($HAS_ASSIGNED) $selectCols[] = 'assigned_to'; else $selectCols[] = 'NULL AS assigned_to';
        $selectCols[] = $phaseExpr . ' AS phase';
        $sqlT = 'SELECT ' . implode(',', $selectCols) . ' FROM tasks WHERE project_id = ? ORDER BY FIELD(phase,\'Pre-Design / Programming\',\'Schematic Design (SD)\',\'Design Development (DD)\',\'Final Design\'), due_date, ' . $TASK_ID_COL;
        if ($stT = $conn->prepare($sqlT)) {
          $stT->bind_param('i', $project_id);
          if ($stT->execute()) {
            $rt = $stT->get_result();
            if ($rt) {
              while ($row = $rt->fetch_assoc()) {
                $phKey = $row['phase'];
                if (!isset($tasksByPhase[$phKey])) $tasksByPhase[$phKey] = [];
                $tasksByPhase[$phKey][] = $row; $totalTasks++;
              }
            }
          }
          $stT->close();
        }
      } catch (Throwable $e) { /* optional */ }
      $phasesList = ['Pre-Design / Programming','Schematic Design (SD)','Design Development (DD)','Final Design'];
      // Resolve user names for assigned architects
      $USERS_PK = 'id';
      $USERS_NAME_EXPR = 'full_name';
      $userNames = [];
      try {
        if ($rs = $conn->query("SHOW COLUMNS FROM users")) {
          $uCols = [];
          while ($row = $rs->fetch_assoc()) { $uCols[$row['Field']] = true; }
          $rs->free();
          if (!isset($uCols['id']) && isset($uCols['user_id'])) { $USERS_PK = 'user_id'; }
          if (isset($uCols['full_name'])) { $USERS_NAME_EXPR = 'full_name'; }
          elseif (isset($uCols['username'])) { $USERS_NAME_EXPR = 'username'; }
          else { $USERS_NAME_EXPR = 'email'; }
        }
      } catch (Throwable $e) { /* keep defaults */ }
      // Collect all assigned_to ids
      $assignedIds = [];
      foreach ($tasksByPhase as $phase => $tasks) {
        foreach ($tasks as $t) {
          if (!empty($t['assigned_to'])) $assignedIds[(int)$t['assigned_to']] = true;
        }
      }
      if (!empty($assignedIds)) {
        $ids = array_keys($assignedIds);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sqlU = "SELECT $USERS_PK as id, $USERS_NAME_EXPR as name FROM users WHERE $USERS_PK IN ($in)";
        if ($stmtU = $conn->prepare($sqlU)) {
          $stmtU->bind_param($types, ...$ids);
          if ($stmtU->execute()) {
            $ru = $stmtU->get_result();
            if ($ru) {
              while ($row = $ru->fetch_assoc()) {
                $userNames[(int)$row['id']] = $row['name'];
              }
            }
          }
          $stmtU->close();
        }
      }
      ?>
      <?php if ($totalTasks === 0): ?>
        <div class="text-gray-500 text-sm">No tasks found for this project.</div>
      <?php else: ?>
        <?php foreach ($phasesList as $ph): ?>
          <div class="mb-6">
            <div class="flex items-center justify-between mb-2">
              <h3 class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($ph); ?></h3>
              <span class="text-xs text-gray-500"><?php echo isset($tasksByPhase[$ph]) ? count($tasksByPhase[$ph]) : 0; ?> tasks</span>
            </div>
            <ul class="mt-2 divide-y divide-gray-100">
              <?php if (!empty($tasksByPhase[$ph])): ?>
                <?php foreach ($tasksByPhase[$ph] as $t): ?>
                  <li class="py-2 flex flex-col md:flex-row md:items-center justify-between">
                    <div>
                      <b><?php echo htmlspecialchars($t['task_name']); ?></b>
                      <?php if (!empty($t['due_date'])): ?><span class="ml-2 text-xs text-gray-500">Due: <?php echo htmlspecialchars($t['due_date']); ?></span><?php endif; ?>
                      <span class="ml-2 text-xs text-gray-500">Status: <?php echo htmlspecialchars(!empty($t['status']) ? $t['status'] : 'To Do'); ?></span>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-gray-700 mt-1 md:mt-0">
                      Assigned To: <?php 
                        $aid = (int)($t['assigned_to'] ?? 0);
                        $archName = '';
                        if ($aid) {
                          $stmtEmp = $conn->prepare("SELECT user_id FROM employees WHERE employee_id = ? LIMIT 1");
                          $stmtEmp->bind_param('i', $aid);
                          if ($stmtEmp->execute()) {
                            $rEmp = $stmtEmp->get_result();
                            if ($rEmp && ($rowEmp = $rEmp->fetch_assoc())) {
                              $userId = (int)($rowEmp['user_id'] ?? 0);
                              if ($userId) {
                                $nameCol = 'full_name';
                                $nameCols = ['full_name','username','first_name','last_name','email'];
                                $rsCols = $conn->query("SHOW COLUMNS FROM users");
                                $foundCols = [];
                                while ($rsCols && ($rowC = $rsCols->fetch_assoc())) { $foundCols[$rowC['Field']] = true; }
                                $rsCols && $rsCols->free();
                                foreach ($nameCols as $nc) { if (isset($foundCols[$nc])) { $nameCol = $nc; break; } }
                                $sqlArch = "SELECT $nameCol FROM users WHERE user_id = ? LIMIT 1";
                                $stmtArch = $conn->prepare($sqlArch);
                                $stmtArch->bind_param('i', $userId);
                                if ($stmtArch->execute()) {
                                  $rArch = $stmtArch->get_result();
                                  if ($rArch && ($rowArch = $rArch->fetch_assoc())) {
                                    $archName = $rowArch[$nameCol] ?? '';
                                  }
                                }
                                $stmtArch->close();
                              }
                            }
                          }
                          $stmtEmp->close();
                        }
                        echo htmlspecialchars($archName !== '' ? $archName : 'Unassigned');
                      ?>
                      <a href="pm_task_details.php?id=<?php echo (int)$t['task_id']; ?>" class="ml-4 inline-flex items-center px-3 py-1 rounded bg-blue-600 text-white hover:bg-blue-700 text-xs font-semibold">View</a>
                    </div>
                  </li>
                <?php endforeach; ?>
              <?php else: ?>
                <li class="py-2 text-xs text-gray-400">No tasks in this phase.</li>
              <?php endif; ?>
            </ul>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</main>
<?php
if ($isEmployee && file_exists($employeeFooter)) {
  include $employeeFooter;
} else {
  include $sharedFooter;
}
?>

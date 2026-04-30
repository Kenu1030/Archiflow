<?php
// Minimal, schema-aware project details page (no tasks UI)
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

// Compute display fields with graceful fallbacks
$pname   = (string)($project['project_name'] ?? ($project['name'] ?? ('Project #'.$project_id)));
$pdesc   = (string)($project['description'] ?? '');
$pcode   = (string)($project['project_code'] ?? '');
$ptype   = (string)($project['project_type'] ?? '');
$pstatus = (string)($project['status'] ?? '');
$start   = (string)($project['start_date'] ?? '');
$end     = (string)($project['estimated_end_date'] ?? ($project['end_date'] ?? ''));
$budget  = (string)($project['budget_amount'] ?? ($project['budget'] ?? ''));
$created = (string)($project['created_at'] ?? '');

// Compute days left/overdue if we have a deadline
$days_left = null;
if ($end !== '') {
  try {
    $d1 = new DateTime('today');
    $d2 = new DateTime($end);
    $days_left = (int)$d1->diff($d2)->format('%r%a'); // negative if past
  } catch (Throwable $e) { $days_left = null; }
}

// Resolve names: users schema-awareness
$USERS_PK = 'id';
$USERS_NAME_EXPR = 'full_name';
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

$createdByName = '';
if (!empty($project['created_by'])) {
  $cid = (int)$project['created_by'];
  if ($stmtU = $conn->prepare("SELECT $USERS_NAME_EXPR AS name FROM users WHERE $USERS_PK = ? LIMIT 1")) {
    $stmtU->bind_param('i', $cid);
    if ($stmtU->execute()) { $r = $stmtU->get_result(); if ($r) { $row = $r->fetch_assoc(); $createdByName = (string)($row['name'] ?? ''); } }
    $stmtU->close();
  }
}

$managerName = '';
if (array_key_exists('manager_id', $project) && !empty($project['manager_id'])) {
  $mid = (int)$project['manager_id'];
  if ($stmtM = $conn->prepare("SELECT $USERS_NAME_EXPR AS name FROM users WHERE $USERS_PK = ? LIMIT 1")) {
    $stmtM->bind_param('i', $mid);
    if ($stmtM->execute()) { $r = $stmtM->get_result(); if ($r) { $row = $r->fetch_assoc(); $managerName = (string)($row['name'] ?? ''); } }
    $stmtM->close();
  }
}

// Recent files (if table exists)
$recentFiles = [];
try {
  if ($chk = $conn->query("SHOW TABLES LIKE 'project_files'")) {
    if ($chk->num_rows > 0) {
      if ($stf = $conn->prepare("SELECT file_name, file_path, uploaded_at FROM project_files WHERE project_id = ? ORDER BY uploaded_at DESC LIMIT 3")) {
        $stf->bind_param('i', $project_id);
        if ($stf->execute()) {
          $rfres = $stf->get_result();
          if ($rfres) { $recentFiles = $rfres->fetch_all(MYSQLI_ASSOC); }
        }
        $stf->close();
      }
    }
    $chk->free();
  }
} catch (Throwable $e) { /* optional */ }

// Assigned Architects: via project_users (role like Architect) and fallback projects.architect_id
$architects = [];
try {
  if ($chk = $conn->query("SHOW TABLES LIKE 'project_users'")) {
    if ($chk->num_rows > 0) {
      $sqlA = "SELECT u.$USERS_PK as id, u.$USERS_NAME_EXPR as name, u.position AS u_position, pu.role_in_project as role
                     FROM project_users pu JOIN users u ON u.$USERS_PK = pu.user_id
                     WHERE pu.project_id = ? AND (pu.role_in_project LIKE 'Architect' OR pu.role_in_project LIKE '%Architect%')
                     ORDER BY pu.id ASC";
      if ($stA = $conn->prepare($sqlA)) {
        $stA->bind_param('i', $project_id);
        if ($stA->execute()) {
          $ra = $stA->get_result();
          if ($ra) { $architects = $ra->fetch_all(MYSQLI_ASSOC); }
        }
        $stA->close();
      }
    }
    $chk->free();
  }
} catch (Throwable $e) { /* ignore */ }

// Fallback if empty and projects.architect_id exists
if (empty($architects) && array_key_exists('architect_id', $project) && !empty($project['architect_id'])) {
  $aid = (int)$project['architect_id'];
  if ($stF = $conn->prepare("SELECT $USERS_NAME_EXPR AS name, position AS u_position FROM users WHERE $USERS_PK = ? LIMIT 1")) {
    $stF->bind_param('i', $aid);
    if ($stF->execute()) { $rf = $stF->get_result(); if ($rf) { $row = $rf->fetch_assoc(); if ($row) { $architects[] = ['id'=>$aid,'name'=>$row['name'] ?? ('#'.$aid),'role'=>'Architect','u_position'=>$row['u_position'] ?? null]; } } }
    $stF->close();
  }
}

// Decide which header/footer to use
$page_title = 'Project Details';
$isEmployee = (($_SESSION['user_type'] ?? '') === 'employee');
$positionRaw = (string)($_SESSION['position'] ?? '');
$position = strtolower($positionRaw);
$positionNormalized = strtolower(str_replace(' ', '_', trim($positionRaw)));
$isPM = ($position === 'project_manager') || ($positionNormalized === 'project_manager') || (($_SESSION['role'] ?? '') === 'project_manager');
$employeeHeader = __DIR__ . '/backend/core/header.php';
$employeeFooter = __DIR__ . '/backend/core/footer.php';
$sharedHeader   = __DIR__ . '/includes/header.php';
$sharedFooter   = __DIR__ . '/includes/footer.php';

// Include appropriate header
if ($isEmployee && file_exists($employeeHeader)) {
  include $employeeHeader;
} else {
  include $sharedHeader;
}
?>

<main id="content-wrapper" class="flex-1 p-6">
  <?php
  // Ensure phasesList is defined before use
  $phasesList = ['Pre-Design / Programming','Schematic Design (SD)','Design Development (DD)','Final Design'];
  ?>
  <?php
  // Handle PM revision request on a task
  if ($isPM && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['rev_task_id']) && ctype_digit((string)$_POST['rev_task_id'])) {
    $revTaskId = (int)$_POST['rev_task_id'];
    $revNote = trim($_POST['rev_note'] ?? '');
    // Basic mysqli reuse of $conn (already open earlier in file)
    try {
      // Verify task belongs to this project
      if ($stmt = $conn->prepare('SELECT id,status FROM tasks WHERE id=? AND project_id=? LIMIT 1')) {
        $stmt->bind_param('ii',$revTaskId,$project_id); $stmt->execute(); $res=$stmt->get_result(); $taskRow=$res?$res->fetch_assoc():null; $stmt->close();
        if ($taskRow) {
          // Attempt to add 'Revise' to enum if not present (safe best-effort)
          $enumHasRevise = false; $colRes = $conn->query("SHOW COLUMNS FROM tasks LIKE 'status'");
          if ($colRes && $col = $colRes->fetch_assoc()) { if (strpos($col['Type'],'Revise')!==false) $enumHasRevise=true; }
          if (!$enumHasRevise) { @ $conn->query("ALTER TABLE tasks MODIFY status ENUM('To Do','In Progress','Done','Revise') DEFAULT 'To Do'"); }
          // Update status to Revise
          if ($upd = $conn->prepare("UPDATE tasks SET status='Revise', updated_at=NOW() WHERE id=?")) { $upd->bind_param('i',$revTaskId); $upd->execute(); $upd->close(); }
          // Insert revision note into task_revisions table if exists; else create quick table then insert
          $hasRevTable = false; $chkRev = $conn->query("SHOW TABLES LIKE 'task_revisions'");
          if ($chkRev && $chkRev->num_rows>0) { $hasRevTable=true; $chkRev->free(); }
          if (!$hasRevTable) {
            @ $conn->query("CREATE TABLE task_revisions (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, note TEXT NULL, requested_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
          }
          if ($revNote !== '') {
            if ($insRev = $conn->prepare('INSERT INTO task_revisions (task_id, note, requested_by) VALUES (?,?,?)')) {
              $reqBy = (int)($_SESSION['user_id'] ?? 0);
              $insRev->bind_param('isi', $revTaskId, $revNote, $reqBy);
              $insRev->execute(); $insRev->close();
            }
          }
          header('Location: project_details.php?project_id='.$project_id.'&rev=1');
          exit;
        }
      }
    } catch (Throwable $e) { /* silent */ }
  }
  ?>
  <div class="max-w-5xl mx-auto">
    <div class="mb-6">
      <?php
      // Progress bar for completed phases
      $completedPhases = 0;
      $totalPhases = count($phasesList);
      foreach ($phasesList as $ph) {
        if (!empty($tasksByPhase[$ph])) {
          $allDone = true;
          foreach ($tasksByPhase[$ph] as $t) {
            if (strtolower($t['status']) !== 'completed' && strtolower($t['status']) !== 'done') {
              $allDone = false;
              break;
            }
          }
          if ($allDone) { $completedPhases++; }
        }
      }
      $progressPercent = $totalPhases > 0 ? round(($completedPhases / $totalPhases) * 100) : 0;
      ?>
      <div class="mt-2 mb-4">
        <div class="text-sm text-gray-600 mb-1">Project Progress</div>
        <div class="w-full bg-gray-200 rounded-full h-4">
          <div class="bg-blue-500 h-4 rounded-full transition-all duration-300" style="width: <?php echo $progressPercent; ?>%;"></div>
        </div>
        <div class="text-xs text-gray-500 mt-1"><?php echo $completedPhases; ?> / <?php echo $totalPhases; ?> phases completed (<?php echo $progressPercent; ?>%)</div>
      </div>
      <?php
        // Build an absolute URL when in employee PM context to avoid relative path issues
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = $scheme . '://' . $host;
        $backUrl = 'projects.php';
        if ($isEmployee && $isPM) {
            $backUrl = rtrim($base,'/') . '/ArchiFlow/employees/project_manager/projects/projects.php';
        }
      ?>
      <a href="<?php echo htmlspecialchars($backUrl); ?>" class="text-blue-600 hover:underline">&larr; Back to Projects</a>
      <h1 class="text-2xl font-bold text-gray-900 mt-2"><?php echo htmlspecialchars($pname); ?></h1>
      <?php if ($pstatus !== ''): ?>
        <span class="inline-block mt-2 px-2 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-100"><?php echo htmlspecialchars($pstatus); ?></span>
      <?php endif; ?>
    </div>

    <div class="bg-white/80 backdrop-blur-glass border border-white/40 rounded-xl p-6 shadow-sm">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
            <div class="font-medium flex items-center gap-2">
              <?php echo htmlspecialchars($end); ?>
              <?php if ($days_left !== null): ?>
                <?php
                  $badgeClass = $days_left < 0 ? 'bg-red-50 text-red-700 border-red-100' : ($days_left <= 7 ? 'bg-amber-50 text-amber-700 border-amber-100' : 'bg-emerald-50 text-emerald-700 border-emerald-100');
                  $label = $days_left < 0 ? (abs($days_left) . ' days overdue') : ($days_left . ' days left');
                ?>
                <span class="px-2 py-0.5 rounded-full text-xs font-semibold border <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($label); ?></span>
              <?php endif; ?>
            </div>
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
  </div>

  <!-- People panel -->
  <div class="max-w-5xl mx-auto mt-6">
    <div class="bg-white/80 backdrop-blur-glass border border-white/40 rounded-xl p-6 shadow-sm">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">People</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <div class="text-gray-500 text-sm">Created By</div>
          <div class="font-medium"><?php echo htmlspecialchars($createdByName !== '' ? $createdByName : (string)($project['created_by'] ?? 'Unknown')); ?></div>
        </div>
        <?php if ($managerName !== '' || !empty($project['manager_id'])): ?>
        <div>
          <div class="text-gray-500 text-sm">Project Manager</div>
          <div class="font-medium"><?php echo htmlspecialchars($managerName !== '' ? $managerName : (string)$project['manager_id']); ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($architects)): ?>
        <div class="md:col-span-2">
          <div class="text-gray-500 text-sm mb-1">Architects</div>
          <div class="flex flex-wrap gap-2">
            <?php foreach ($architects as $a): ?>
              <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium border bg-white text-gray-800">
                <i class="fas fa-user-tie mr-1 text-gray-500"></i>
                <?php echo htmlspecialchars($a['name'] ?? ('#'.(string)($a['id'] ?? ''))); ?>
                <?php 
                  $role_l = strtolower((string)($a['role'] ?? ''));
                  $pos_l  = strtolower((string)($a['u_position'] ?? ''));
                  $is_senior = ((strpos($role_l,'senior')!==false && strpos($role_l,'architect')!==false) || (strpos($pos_l,'senior')!==false && strpos($pos_l,'architect')!==false));
                  $is_arch = ($is_senior || strpos($role_l,'architect')!==false || strpos($pos_l,'architect')!==false);
                  $display_role = $is_senior ? 'Senior Architect' : ($is_arch ? 'Architect' : (isset($a['role']) ? ucwords((string)$a['role']) : ''));
                ?>
                <?php if ($display_role !== ''): ?>
                  <span class="ml-2 px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-100 text-[11px]">
                    <?php echo htmlspecialchars($display_role); ?>
                  </span>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent files -->
  <?php if (!empty($recentFiles)): ?>
  <div class="max-w-5xl mx-auto mt-6">
    <div class="bg-white/80 backdrop-blur-glass border border-white/40 rounded-xl p-6 shadow-sm">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-900">Recent Files</h2>
        <a href="project_details.php?project_id=<?php echo (int)$project_id; ?>#files" class="text-sm text-blue-600 hover:underline">View all</a>
      </div>
      <ul class="divide-y divide-gray-100">
        <?php foreach ($recentFiles as $f): ?>
          <li class="py-3 flex items-center justify-between">
            <a class="text-gray-800 hover:text-blue-700 truncate" href="<?php echo htmlspecialchars($f['file_path']); ?>" target="_blank" rel="noopener">
              <i class="fa-regular fa-file mr-2 text-gray-400"></i>
              <?php echo htmlspecialchars($f['file_name']); ?>
            </a>
            <span class="text-xs text-gray-500"><?php echo htmlspecialchars((string)$f['uploaded_at']); ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

  <?php
  // Tasks panel: grouped by phase if tasks table exists
  $tasksByPhase = [];
  $totalTasks = 0;
  try {
    if ($chk = $conn->query("SHOW TABLES LIKE 'tasks'")) {
      if ($chk->num_rows > 0) {
        $sqlT = "SELECT id, title, status, due_date, COALESCE(phase,'Pre-Design / Programming') AS phase
                 FROM tasks WHERE project_id = ?
                 ORDER BY FIELD(phase,'Pre-Design / Programming','Schematic Design (SD)','Design Development (DD)','Final Design'), due_date, id";
        if ($stT = $conn->prepare($sqlT)) {
          $stT->bind_param('i', $project_id);
          if ($stT->execute()) {
            $rt = $stT->get_result();
            if ($rt) {
              while ($row = $rt->fetch_assoc()) {
                $phase = $row['phase'];
                if (!isset($tasksByPhase[$phase])) { $tasksByPhase[$phase] = []; }
                $tasksByPhase[$phase][] = $row; $totalTasks++;
              }
            }
          }
          $stT->close();
        }
      }
      $chk->free();
    }
  } catch (Throwable $e) { /* optional */ }

  $phasesList = ['Pre-Design / Programming','Schematic Design (SD)','Design Development (DD)','Final Design'];
  ?>

  <div class="max-w-5xl mx-auto mt-6">
    <div class="bg-white/80 backdrop-blur-glass border border-white/40 rounded-xl p-6 shadow-sm">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-900">Tasks</h2>
        <div class="flex gap-2">
          <?php if ($isPM): ?>
            <a href="assign_tasks.php?project_id=<?php echo (int)$project_id; ?>" class="text-sm text-blue-600 hover:underline">Assign Task</a>
          <?php endif; ?>
          <a href="view_all_tasks.php?project_id=<?php echo (int)$project_id; ?>" class="text-sm text-blue-600 hover:underline">View All Tasks</a>
        </div>
      </div>
      <?php if ($totalTasks === 0): ?>
  <!-- No tasks message removed as requested -->
      <?php else: ?>
          <!-- Removed individual View Task button -->
        <div class="space-y-4">
            <div class="mb-4">
              <a href="assign_tasks.php?project_id=<?php echo (int)$project_id; ?>" class="px-4 py-2 text-sm rounded bg-blue-600 text-white hover:bg-blue-700">View All Tasks</a>
            </div>
          <?php foreach ($phasesList as $ph): ?>
            <div>
              <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($ph); ?></h3>
                <span class="text-xs text-gray-500"><?php echo isset($tasksByPhase[$ph]) ? count($tasksByPhase[$ph]) : 0; ?></span>
              </div>
              <ul class="mt-2 divide-y divide-gray-100">
                <?php if (!empty($tasksByPhase[$ph])): ?>
                  <?php foreach ($tasksByPhase[$ph] as $t): ?>
                  <li class="py-2 border-b last:border-b-0">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                      <div class="text-sm text-gray-800 font-medium flex items-center gap-2">
                        <span><?php echo htmlspecialchars($t['title'] ?? $t['task_name'] ?? 'Task #'.($t['id']??$t['task_id'])); ?></span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] bg-gray-100 text-gray-600">Status: <?php echo htmlspecialchars($t['status']); ?></span>
                        <?php if (!empty($t['due_date'])): ?><span class="text-[11px] text-gray-500">Due: <?php echo htmlspecialchars($t['due_date']); ?></span><?php endif; ?>
                      </div>
                      <?php if ($isPM): ?>
                      <div class="flex flex-wrap items-center gap-2">
                        <form method="post" class="flex items-center gap-2" onsubmit="return confirm('Mark this task for revision?');">
                          <input type="hidden" name="rev_task_id" value="<?php echo (int)($t['id'] ?? $t['task_id']); ?>" />
                          <input type="text" name="rev_note" class="border rounded px-2 py-1 text-xs" placeholder="Revision note (optional)" />
                          <button type="submit" class="px-3 py-1 text-xs rounded bg-amber-600 text-white hover:bg-amber-700">Set Revise</button>
                        </form>
                      </div>
                      <?php endif; ?>
                    </div>
                  </li>
                  <?php endforeach; ?>
                <?php else: ?>
                  <li class="py-2 text-xs text-gray-400">No tasks in this phase.</li>
                <?php endif; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php
// My Tasks: tasks assigned by current user
$myTasks = [];
$user_id = $_SESSION['user_id'];
try {
  if ($chk = $conn->query("SHOW TABLES LIKE 'tasks'")) {
    if ($chk->num_rows > 0) {
      $sqlMy = "SELECT task_id, task_name, status, due_date, COALESCE(phase,'Pre-Design / Programming') AS phase
                FROM tasks WHERE project_id = ? AND created_by = ?
                ORDER BY FIELD(phase,'Pre-Design / Programming','Schematic Design (SD)','Design Development (DD)','Final Design'), due_date, task_id";
      if ($stMy = $conn->prepare($sqlMy)) {
        $stMy->bind_param('ii', $project_id, $user_id);
        if ($stMy->execute()) {
          $rtMy = $stMy->get_result();
          if ($rtMy) {
            while ($row = $rtMy->fetch_assoc()) {
              $myTasks[] = $row;
            }
          }
        }
        $stMy->close();
      }
    }
    $chk->free();
  }
} catch (Throwable $e) { /* optional */ }
?>

<div class="max-w-5xl mx-auto mt-8">
  <div class="bg-white/80 backdrop-blur-glass border border-blue-100 rounded-xl p-6 shadow-sm">
    <h2 class="text-lg font-semibold text-blue-900 mb-4">My Tasks (Assigned By You)</h2>
    <?php if (empty($myTasks)): ?>
      <div class="text-gray-500 text-sm">You have not assigned any tasks for this project.</div>
    <?php else: ?>
      <ul class="divide-y divide-gray-100">
        <?php foreach ($myTasks as $t): ?>
          <li class="py-2 flex items-center justify-between">
            <span>
              <b><?php echo htmlspecialchars($t['task_name']); ?></b>
              <span class="ml-2 text-xs text-gray-500">Phase: <?php echo htmlspecialchars($t['phase']); ?></span>
              <span class="ml-2 text-xs text-gray-500">Due: <?php echo htmlspecialchars($t['due_date']); ?></span>
              <span class="ml-2 text-xs text-gray-500">Status: <?php echo htmlspecialchars($t['status']); ?></span>
            </span>
            <!-- Removed individual View Task button -->
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

  <?php
// Show architect uploads for a specific task if requested
if (isset($_GET['view_task']) && is_numeric($_GET['view_task'])) {
  $task_id = (int)$_GET['view_task'];
  // Get architect assigned to this task
  $stmtA = $conn->prepare("SELECT assigned_to FROM tasks WHERE id = ? AND project_id = ? LIMIT 1");
  $stmtA->bind_param('ii', $task_id, $project_id);
  $stmtA->execute();
  $resA = $stmtA->get_result();
  $assigned_to = null;
  if ($resA && ($rowA = $resA->fetch_assoc())) { $assigned_to = (int)$rowA['assigned_to']; }
  $stmtA->close();
  // Query project_files for uploads by this architect for this project
  $uploads = [];
  if ($assigned_to) {
    $stmtF = $conn->prepare("SELECT file_name, file_path, uploaded_at FROM project_files WHERE project_id = ? AND uploaded_by = ? ORDER BY uploaded_at DESC");
    $stmtF->bind_param('ii', $project_id, $assigned_to);
    $stmtF->execute();
    $resF = $stmtF->get_result();
    if ($resF) { $uploads = $resF->fetch_all(MYSQLI_ASSOC); }
    $stmtF->close();
  }
  ?>
  <div class="max-w-3xl mx-auto mt-8 mb-8">
    <div class="bg-white/90 border border-blue-100 rounded-xl p-6 shadow">
      <h3 class="text-lg font-semibold text-blue-900 mb-4">Architect Uploads for Task #<?php echo (int)$task_id; ?></h3>
      <?php if (empty($uploads)): ?>
        <div class="text-gray-500 text-sm">No uploads found for this task.</div>
      <?php else: ?>
        <ul class="divide-y divide-gray-100">
          <?php foreach ($uploads as $f): ?>
            <li class="py-2 flex items-center justify-between">
              <a class="text-gray-800 hover:text-blue-700 truncate" href="<?php echo htmlspecialchars($f['file_path']); ?>" target="_blank" rel="noopener">
                <i class="fa-regular fa-file mr-2 text-gray-400"></i>
                <?php echo htmlspecialchars($f['file_name']); ?>
              </a>
              <span class="text-xs text-gray-500">Uploaded: <?php echo htmlspecialchars((string)$f['uploaded_at']); ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <div class="mt-4"><a href="project_details.php?project_id=<?php echo (int)$project_id; ?>" class="inline-flex items-center px-4 py-2 rounded-lg bg-slate-200 text-slate-800 hover:bg-slate-300 transition">Close</a></div>
    </div>
  </div>
  <?php
}
?>
<?php
// Include appropriate footer
if ($isEmployee && file_exists($employeeFooter)) {
  include $employeeFooter;
} else {
  include $sharedFooter;
}
?>

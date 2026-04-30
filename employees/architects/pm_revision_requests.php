<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../../login.php'); exit; }
if (($_SESSION['user_type'] ?? '') !== 'employee' || strtolower(str_replace(' ','_',trim((string)($_SESSION['position'] ?? '')))) !== 'architect') { header('Location: ../../index.php'); exit; }
require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = (int)($_SESSION['user_id'] ?? 0);
$employeeId = 0;
try { $es = $db->prepare('SELECT employee_id FROM employees WHERE user_id=? LIMIT 1'); $es->execute([$userId]); $r=$es->fetch(PDO::FETCH_ASSOC); $employeeId = $r?(int)$r['employee_id']:0; } catch (Throwable $e) {}

$cols=[]; $colNames=[]; try { $c=$db->query("SHOW COLUMNS FROM pm_senior_files"); while($cl=$c->fetch(PDO::FETCH_ASSOC)){ $cols[$cl['Field']]=true; $colNames[]=$cl['Field']; } } catch(Throwable $e){}
// Detect status column (case-insensitive fallback)
$statusCol = null; foreach (['status','file_status','review_status','pm_status'] as $cand) { foreach ($colNames as $fn) { if (strtolower($fn) === $cand) { $statusCol = $fn; break 2; } } }
$hasStatus = $statusCol !== null;
$hasComment=isset($cols['senior_comment']); $hasReviewedAt=isset($cols['reviewed_at']); $hasReviewer=isset($cols['reviewed_by_employee_id']);
// Inspect projects table for architect assignment column
$projCols=[]; $archAssignCol=null; try { $cp=$db->query("SHOW COLUMNS FROM projects"); while($cl=$cp->fetch(PDO::FETCH_ASSOC)){ $projCols[$cl['Field']]=true; } } catch(Throwable $e){}
foreach (['architect_id','assigned_architect_id','lead_architect_id','architect_employee_id'] as $cand) { if (isset($projCols[$cand])) { $archAssignCol=$cand; break; } }

$select=[ 'f.id','f.project_id','f.original_name','f.stored_name','f.relative_path','f.design_phase','f.uploaded_at','f.note' ];
if($hasStatus) $select[]='f.`'.$statusCol.'` AS status';
if($hasComment) $select[]='f.senior_comment';
if($hasReviewedAt) $select[]='f.reviewed_at';
if($hasReviewer) $select[]='f.reviewed_by_employee_id';
$select[]='p.project_name';

$where=[]; $params=[];
// Assignment filtering: project-level or file-level fallback
if ($employeeId > 0) {
  $assignParts = [];
  if ($archAssignCol) { $assignParts[] = 'p.' . $archAssignCol . ' = ?'; $params[] = $employeeId; }
  // File-level candidate columns
  foreach (['architect_id','assigned_architect_id','assigned_to','assignee_id','assignee_employee_id','uploaded_by_employee_id','owner_employee_id'] as $fc) {
    if (isset($cols[$fc])) { $assignParts[] = 'f.' . $fc . ' = ?'; $params[] = $employeeId; }
  }
  if (!empty($assignParts)) { $where[] = '(' . implode(' OR ', $assignParts) . ')'; }
}
// Status filter
$filter = isset($_GET['filter']) ? strtolower(trim((string)$_GET['filter'])) : 'revisions';
$q = isset($_GET['q']) ? strtolower(trim((string)$_GET['q'])) : '';
$showAllStatuses = isset($_GET['all']) && (($_GET['all'] === '1') || ($_GET['all'] === 'true'));
if ($hasStatus) {
  if ($filter === 'revisions') {
    // Include common aliases used by PM/Senior for revision status (case/space-insensitive)
    $where[] = 'LOWER(TRIM(f.`'.$statusCol.'`)) IN (\'revisions_requested\',\'revise\',\'revision\',\'needs_revision\')';
  } elseif ($filter === 'pending') {
    $where[] = 'LOWER(TRIM(f.`'.$statusCol.'`)) IN (\'forwarded\',\'pending\',\'for_review\')';
  } else {
    // all: no additional status constraint
    $filter = 'all';
  }
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$sql = 'SELECT '.implode(',', $select).' FROM pm_senior_files f JOIN projects p ON p.project_id=f.project_id ' . $whereSql . ' ORDER BY f.uploaded_at DESC LIMIT 100';

$rows=[]; try { $st=$db->prepare($sql); $st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC);} catch(Throwable $e){}

// Also include Task-level revision requests assigned to this architect/user
$taskRows = [];
$debug = isset($_GET['debug']) && ($_GET['debug'] === '1' || $_GET['debug'] === 'true');
$taskDebug = [ 'tCols' => [], 'TASK_ID_COL' => null, 'TASK_NAME_COL' => null, 'HAS_STATUS' => null, 'HAS_DUE' => null, 'HAS_ASSIGNED' => null, 'sql' => null, 'params' => [], 'error' => null, 'fallback_count' => 0, 'sample_testing' => null ];
try {
  // Detect tasks schema variants
  $tCols = [];
  if ($rc = $db->query('SHOW COLUMNS FROM tasks')) { while($r=$rc->fetch(PDO::FETCH_ASSOC)){ $tCols[$r['Field']]=$r; } }
  $TASK_ID_COL = isset($tCols['task_id']) ? 'task_id' : (isset($tCols['id']) ? 'id' : 'task_id');
  $TASK_NAME_COL = isset($tCols['task_name']) ? 'task_name' : (isset($tCols['title']) ? 'title' : (isset($tCols['name']) ? 'name' : 'task_name'));
  $HAS_STATUS = isset($tCols['status']);
  $HAS_DUE = isset($tCols['due_date']);
  $HAS_ASSIGNED = isset($tCols['assigned_to']);
  if ($debug) { $taskDebug['tCols'] = array_keys($tCols); $taskDebug['TASK_ID_COL']=$TASK_ID_COL; $taskDebug['TASK_NAME_COL']=$TASK_NAME_COL; $taskDebug['HAS_STATUS']=$HAS_STATUS; $taskDebug['HAS_DUE']=$HAS_DUE; $taskDebug['HAS_ASSIGNED']=$HAS_ASSIGNED; }
  $DESC_COL_EXPR = "COALESCE(t.description, t.task_description, t.details, t.note, '') AS task_description";

  // Build select
  $selT = [
    't.`'.$TASK_ID_COL.'` AS task_id',
    't.`'.$TASK_NAME_COL.'` AS task_name',
    ($HAS_STATUS ? 't.status' : "'To Do' AS status"),
    ($HAS_DUE ? 't.due_date' : 'NULL AS due_date'),
    $DESC_COL_EXPR,
    'p.project_name'
  ];

  // Build where for assignment + status filter
  $wT = [];
  $pT = [];
  // Assignment: tasks.assigned_to may reference users.id OR employees.employee_id; also include project_users membership
  if ($HAS_ASSIGNED) {
    // If debug=1 and no rows found later, we can temporarily relax this filter to inspect
    $wT[] = '(t.assigned_to = ? OR t.assigned_to = ? OR EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id = t.project_id AND pu.user_id = ?))';
    $pT[] = (int)($_SESSION['user_id'] ?? 0);
    $pT[] = (int)$employeeId;
    $pT[] = (int)($_SESSION['user_id'] ?? 0);
  }
  // Status filter mapping for tasks (unless overridden by 'all' or search query)
  // Detect optional task_revisions table and role columns to specifically capture PM-initiated revision requests
  $hasTaskRevisions = false; $trCols = [];
  try {
    $chkTR = $db->query("SHOW TABLES LIKE 'task_revisions'");
    $hasTaskRevisions = (bool)($chkTR && $chkTR->rowCount());
    if ($hasTaskRevisions) {
      foreach($db->query('SHOW COLUMNS FROM task_revisions') as $cc){ $trCols[$cc['Field']] = true; }
    }
  } catch (Throwable $e) {}

  if ($HAS_STATUS && !$showAllStatuses && $q === '') {
    if ($filter === 'revisions') {
      // Accept common spelling/spacing variants
      $revCond = "LOWER(TRIM(t.status)) IN ('revise','needs_revision','needs revision','revisions_requested','revision requested','request revision','for_revision','for revision','revision','changes_requested','changes requested')";

      // If we have a task_revisions table, include tasks that have a PM-initiated revision signal
      if ($hasTaskRevisions) {
        // Try to detect a role column to check if the requester is the PM
        $roleCol = null; foreach (['requested_by_role','requester_role','author_role','role'] as $cand) { if (isset($trCols[$cand])) { $roleCol = $cand; break; } }
        $userCol = null; foreach (['requested_by_user_id','created_by_user_id','user_id','author_user_id'] as $cand) { if (isset($trCols[$cand])) { $userCol = $cand; break; } }
        $empCol  = null; foreach (['requested_by_employee_id','created_by_employee_id','employee_id','author_employee_id'] as $cand) { if (isset($trCols[$cand])) { $empCol = $cand; break; } }

        // Build EXISTS clause prioritizing explicit role, else infer via employees/users tables
        $pmExists = 'EXISTS (SELECT 1 FROM task_revisions tr WHERE tr.task_id = t.`'.$TASK_ID_COL.'`';
        if ($roleCol) {
          $pmExists .= " AND LOWER(TRIM(tr.`$roleCol`)) IN ('project_manager','project manager','pm','manager')";
        } elseif ($empCol) {
          // infer via employees.position
          $pmExists .= " AND EXISTS (SELECT 1 FROM employees e WHERE e.employee_id = tr.`$empCol` AND LOWER(TRIM(e.position)) IN ('project_manager','project manager','pm'))";
        } elseif ($userCol) {
          // infer via users.role or users.position
          // Detect users table columns
          $usersCols = [];
          try { foreach($db->query('SHOW COLUMNS FROM users') as $uc){ $usersCols[$uc['Field']]=true; } } catch (Throwable $e) {}
          $userRoleCol = isset($usersCols['role']) ? 'role' : (isset($usersCols['position']) ? 'position' : null);
          if ($userRoleCol) {
            $pmExists .= " AND EXISTS (SELECT 1 FROM users u WHERE u.user_id = tr.`$userCol` AND LOWER(TRIM(u.`$userRoleCol`)) IN ('project_manager','project manager','pm'))";
          }
        }
        $pmExists .= ')';
        $revCond = '(' . $revCond . ' OR ' . $pmExists . ')';
      }
      $wT[] = $revCond;
    } elseif ($filter === 'pending') {
      // show common working states
      $wT[] = "LOWER(TRIM(t.status)) IN ('to do','to_do','in progress','in_progress','pending')";
    } else {
      // all: no extra constraint
    }
  }

  // Search by task name/title if q provided
  if ($q !== '') {
    $wT[] = '(LOWER(TRIM(t.`'.$TASK_NAME_COL.'`)) LIKE ?' . (isset($tCols['title']) ? ' OR LOWER(TRIM(t.title)) LIKE ?' : '') . ')';
    $pT[] = '%'.$q.'%';
    if (isset($tCols['title'])) { $pT[] = '%'.$q.'%'; }
  }
  $wTsql = $wT ? ('WHERE '.implode(' AND ',$wT)) : '';
  $sqlT = 'SELECT '.implode(',', $selT).' FROM tasks t LEFT JOIN projects p ON p.project_id = t.project_id '.$wTsql.' ORDER BY (t.due_date IS NULL), t.due_date ASC, t.`'.$TASK_ID_COL.'` DESC LIMIT 200';
  if ($debug) { $taskDebug['sql'] = $sqlT; $taskDebug['params'] = $pT; }
  $stT = $db->prepare($sqlT);
  $stT->execute($pT);
  $taskRows = $stT->fetchAll(PDO::FETCH_ASSOC);

  // If nothing found and debug enabled, run fallback scans to help diagnose
  if ($debug && !$taskRows) {
    // Count all tasks with a revise-like status regardless of assignment
    if ($HAS_STATUS) {
      $q = $db->query("SELECT COUNT(*) c FROM tasks WHERE LOWER(TRIM(status)) IN ('revise','needs_revision','needs revision','revisions_requested','revision requested','request revision','for_revision','for revision','revision')");
      $taskDebug['fallback_count'] = (int)$q->fetchColumn();
      if ($hasTaskRevisions) {
        try { $qr = $db->query('SELECT COUNT(DISTINCT tr.task_id) c FROM task_revisions tr'); $taskDebug['fallback_task_revisions'] = (int)$qr->fetchColumn(); } catch (Throwable $e) {}
      }
    }
    // Probe a specific sample by title/name = 'TESTING' (case-insensitive)
    $probeSql = 'SELECT t.' . $TASK_ID_COL . ' AS task_id, t.' . $TASK_NAME_COL . ' AS task_name, t.status, t.assigned_to, t.project_id, t.due_date FROM tasks t WHERE LOWER(TRIM(t.' . $TASK_NAME_COL . ')) LIKE ? OR (CASE WHEN EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=\'tasks\' AND COLUMN_NAME=\'title\') THEN LOWER(TRIM(t.title)) ELSE NULL END) LIKE ? LIMIT 1';
    $ps = $db->prepare($probeSql);
    $like = '%'.strtolower('TESTING').'%';
    $ps->execute([$like,$like]);
    $taskDebug['sample_testing'] = $ps->fetch(PDO::FETCH_ASSOC) ?: null;

    // Also show a small distribution of statuses for assigned tasks (ignoring status filter)
    $diagSql = 'SELECT LOWER(TRIM(COALESCE(t.status,\'\'))) s, COUNT(*) c FROM tasks t WHERE ' . ($HAS_ASSIGNED ? '(t.assigned_to = '.(int)($_SESSION['user_id'] ?? 0).' OR t.assigned_to = '.(int)$employeeId.' OR EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id = t.project_id AND pu.user_id = '.(int)($_SESSION['user_id'] ?? 0).'))' : '1=1') . ' GROUP BY s ORDER BY c DESC LIMIT 10';
    try { $taskDebug['assigned_status_dist'] = $db->query($diagSql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
  }
} catch (Throwable $e) { /* tasks optional */ }

// Map reviewer names
$reviewerMap=[]; if($hasReviewer){ $ids=[]; foreach($rows as $r){ if(!empty($r['reviewed_by_employee_id'])) $ids[(int)$r['reviewed_by_employee_id']]=true; } if($ids){ $in=implode(',', array_map('intval', array_keys($ids))); try{ $q=$db->query("SELECT e.employee_id, CONCAT(u.first_name,' ',u.last_name) AS nm FROM employees e JOIN users u ON u.user_id=e.user_id WHERE e.employee_id IN ($in)"); while($rr=$q->fetch(PDO::FETCH_ASSOC)){ $reviewerMap[(int)$rr['employee_id']]=$rr['nm']; } }catch(Throwable $e){} } }

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
  <div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">Revision Requests</h1>
      <form method="get" class="flex items-center gap-2 text-sm">
        <label class="text-slate-600">Show</label>
        <select name="filter" class="border rounded px-2 py-1">
          <option value="revisions" <?php echo $filter==='revisions'?'selected':''; ?>>Needs Revisions</option>
          <option value="pending" <?php echo $filter==='pending'?'selected':''; ?>>Pending Review</option>
          <option value="all" <?php echo $filter==='all'?'selected':''; ?>>All Statuses</option>
        </select>
        <button class="bg-slate-800 text-white px-3 py-1 rounded">Apply</button>
      </form>
    </div>
    <?php if ($employeeId === 0): ?>
      <div class="mb-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2">
        Your account isn’t linked to an employee profile. Please contact HR to link your user to an Employee record.
      </div>
    <?php elseif (!$archAssignCol): ?>
      <div class="mb-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2">
        Heads up: I couldn’t detect an architect assignment column on projects (looked for architect_id, assigned_architect_id, lead_architect_id, architect_employee_id). Showing nothing may be due to that mapping. Ask Admin/PM to ensure projects record which architect is assigned.
      </div>
    <?php endif; ?>
    <div class="overflow-x-auto bg-white rounded-xl shadow-sm ring-1 ring-slate-200 mb-6">
      <div class="px-3 pt-3 text-slate-700 font-semibold">Files needing action</div>
      <table class="min-w-full text-sm">
        <thead class="bg-slate-100 text-slate-600 uppercase text-[11px] tracking-wide">
          <tr>
            <th class="py-2 px-3 text-left">File</th>
            <th class="py-2 px-3 text-left">Project</th>
            <th class="py-2 px-3 text-left">Phase</th>
            <th class="py-2 px-3 text-left">Uploaded</th>
            <?php if($hasStatus): ?><th class="py-2 px-3 text-left">Status</th><?php endif; ?>
            <th class="py-2 px-3 text-left">PM Note</th>
            <?php if($hasComment): ?><th class="py-2 px-3 text-left">Senior Note</th><?php endif; ?>
            <th class="py-2 px-3 text-left">Reviewed</th>
            <th class="py-2 px-3 text-left">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if(!$rows): ?>
            <tr>
              <td colspan="9" class="p-4 text-slate-600">
                <div class="space-y-1">
                  <?php if ($employeeId === 0): ?>
                    <div>Your account isn’t linked to an employee profile. Please contact HR to link your user to an Employee record.</div>
                  <?php else: ?>
                    <div>No files found for this filter.</div>
                    <ul class="list-disc ml-6 text-slate-500 text-xs">
                      <li>Only projects assigned to you (projects.architect_id = your employee ID) are shown.</li>
                      <?php if ($hasStatus): ?>
                        <li>Try changing the filter to “All Statuses”.</li>
                        <li>PM/Senior must set status to “revisions_requested” for actionable items.</li>
                      <?php endif; ?>
                    </ul>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php else: foreach($rows as $r): ?>
            <?php
              $status = $r['status'] ?? null;
              $statusColor='bg-slate-200 text-slate-700';
              $sL = strtolower(trim((string)$status));
              if(in_array($sL, ['revisions_requested','revise','revision','needs_revision'], true)) $statusColor='bg-red-100 text-red-700';
              elseif(in_array($sL, ['forwarded','pending','for_review'], true)) $statusColor='bg-amber-100 text-amber-700';
              $fileUrl = '/ArchiFlow/' . ($r['relative_path'] ?? ('PMuploads/'.$r['stored_name']));
            ?>
            <tr class="hover:bg-slate-50">
              <td class="py-2 px-3"><div class="font-medium text-slate-900 max-w-[200px] truncate" title="<?php echo htmlspecialchars($r['original_name']); ?>"><?php echo htmlspecialchars($r['original_name']); ?></div><div class="text-[10px] text-slate-500 font-mono">#<?php echo (int)$r['id']; ?></div></td>
              <td class="py-2 px-3 text-slate-700 max-w-[160px] truncate" title="<?php echo htmlspecialchars($r['project_name']); ?>"><?php echo htmlspecialchars($r['project_name']); ?></td>
              <td class="py-2 px-3 text-slate-700"><?php echo htmlspecialchars($r['design_phase'] ?? ''); ?></td>
              <td class="py-2 px-3 text-slate-600"><?php echo htmlspecialchars(date('M j, Y H:i', strtotime($r['uploaded_at']))); ?></td>
              <?php if($hasStatus): ?><td class="py-2 px-3"><span class="px-2 py-0.5 rounded-full text-[10px] capitalize <?php echo $statusColor; ?>"><?php echo htmlspecialchars(str_replace('_',' ',$status)); ?></span></td><?php endif; ?>
              <td class="py-2 px-3 text-slate-600 max-w-[220px] truncate" title="<?php echo htmlspecialchars($r['note'] ?? ''); ?>"><?php echo htmlspecialchars($r['note'] ?? ''); ?></td>
              <?php if($hasComment): ?><td class="py-2 px-3 text-slate-600 max-w-[240px] truncate" title="<?php echo htmlspecialchars($r['senior_comment'] ?? ''); ?>"><?php echo htmlspecialchars($r['senior_comment'] ?? ''); ?></td><?php endif; ?>
              <td class="py-2 px-3 text-slate-600 text-xs">
                <?php if(!empty($r['reviewed_at'])): ?>
                  <div><?php echo htmlspecialchars(date('M j, Y H:i', strtotime($r['reviewed_at']))); ?></div>
                  <?php if(!empty($r['reviewed_by_employee_id']) && isset($reviewerMap[(int)$r['reviewed_by_employee_id']])): ?>
                    <div class="text-[10px] text-slate-500">by <?php echo htmlspecialchars($reviewerMap[(int)$r['reviewed_by_employee_id']]); ?></div>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-slate-400 text-[11px]">—</span>
                <?php endif; ?>
              </td>
              <td class="py-2 px-3">
                <div class="flex items-center gap-2">
                  <a href="<?php echo htmlspecialchars($fileUrl); ?>" target="_blank" class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">View</a>
                  <a href="<?php echo htmlspecialchars($fileUrl); ?>" download class="px-2 py-1 bg-green-600 text-white rounded text-xs hover:bg-green-700">Download</a>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="overflow-x-auto bg-white rounded-xl shadow-sm ring-1 ring-slate-200">
        <div class="px-3 pt-3 text-slate-700 font-semibold flex items-center justify-between">
          <span class="font-semibold">Tasks needing action</span>
        <form method="get" class="flex items-center gap-2 text-xs pr-3">
          <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>" />
          <input type="text" name="q" value="<?php echo htmlspecialchars((string)($_GET['q'] ?? '')); ?>" placeholder="Search task name" class="border rounded px-2 py-1" />
          <label class="inline-flex items-center gap-1 text-slate-600"><input type="checkbox" name="all" value="1" <?php echo $showAllStatuses?'checked':''; ?> /> <span>All statuses</span></label>
          <button class="bg-slate-800 text-white px-3 py-1 rounded">Apply</button>
        </form>
      </div>
        <div class="px-3 pb-2 text-[11px] text-slate-500">Shows tasks the PM marked for revisions (status like "revise"/"needs revision"/"revisions requested" or a PM-initiated revision record), limited to tasks assigned to you or your project membership.</div>
      <table class="min-w-full text-sm">
        <thead class="bg-slate-100 text-slate-600 uppercase text-[11px] tracking-wide">
          <tr>
            <th class="py-2 px-3 text-left">Task</th>
            <th class="py-2 px-3 text-left">Project</th>
            <th class="py-2 px-3 text-left">Due Date</th>
            <th class="py-2 px-3 text-left">Status</th>
            <th class="py-2 px-3 text-left">Description</th>
            <th class="py-2 px-3 text-left">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if(!$taskRows): ?>
            <tr><td colspan="6" class="p-4 text-slate-600">No tasks found for this filter.</td></tr>
          <?php else: foreach($taskRows as $t): ?>
            <?php
              $tStatus = strtolower(trim((string)($t['status'] ?? '')));
              $tColor = 'bg-slate-200 text-slate-700';
              if (in_array($tStatus, ['revise','needs_revision','revisions_requested','for_revision'], true)) $tColor='bg-red-100 text-red-700';
              elseif (in_array($tStatus, ['pending','to do','to_do','in progress','in_progress'], true)) $tColor='bg-amber-100 text-amber-700';
              $due = !empty($t['due_date']) ? date('M j, Y', strtotime($t['due_date'])) : '—';
              $taskUrl = '/ArchiFlow/employees/architects/task-details.php?task_id='.(int)$t['task_id'];
            ?>
            <tr class="hover:bg-slate-50">
              <td class="py-2 px-3"><div class="font-medium text-slate-900 max-w-[240px] truncate" title="<?php echo htmlspecialchars($t['task_name']); ?>"><?php echo htmlspecialchars($t['task_name']); ?></div><div class="text-[10px] text-slate-500 font-mono">#<?php echo (int)$t['task_id']; ?></div></td>
              <td class="py-2 px-3 text-slate-700 max-w-[180px] truncate" title="<?php echo htmlspecialchars($t['project_name'] ?? ''); ?>"><?php echo htmlspecialchars($t['project_name'] ?? ''); ?></td>
              <td class="py-2 px-3 text-slate-700"><?php echo htmlspecialchars($due); ?></td>
              <td class="py-2 px-3"><span class="px-2 py-0.5 rounded-full text-[10px] capitalize <?php echo $tColor; ?>"><?php echo htmlspecialchars(str_replace('_',' ', (string)$t['status'])); ?></span></td>
              <td class="py-2 px-3 text-slate-600 max-w-[280px] truncate" title="<?php echo htmlspecialchars($t['task_description'] ?? ''); ?>"><?php echo htmlspecialchars($t['task_description'] ?? ''); ?></td>
              <td class="py-2 px-3">
                <a href="<?php echo htmlspecialchars($taskUrl); ?>" class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">View</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php if ($debug): ?>
    <div class="max-w-6xl mx-auto mt-6">
      <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-amber-800 text-sm">
        <div class="font-semibold mb-2">Debug: Tasks data</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <div><span class="font-semibold">Employee ID:</span> <?php echo (int)$employeeId; ?></div>
            <div><span class="font-semibold">User ID:</span> <?php echo (int)$userId; ?></div>
            <div><span class="font-semibold">Filter:</span> <?php echo htmlspecialchars($filter); ?></div>
            <div><span class="font-semibold">Detected task columns:</span> <span class="font-mono text-[11px]"><?php echo htmlspecialchars(implode(', ',$taskDebug['tCols'] ?? [])); ?></span></div>
            <div><span class="font-semibold">TASK_ID_COL:</span> <?php echo htmlspecialchars((string)$taskDebug['TASK_ID_COL']); ?>, <span class="font-semibold">TASK_NAME_COL:</span> <?php echo htmlspecialchars((string)$taskDebug['TASK_NAME_COL']); ?></div>
            <div><span class="font-semibold">HAS_STATUS:</span> <?php echo $taskDebug['HAS_STATUS']?'yes':'no'; ?>, <span class="font-semibold">HAS_DUE:</span> <?php echo $taskDebug['HAS_DUE']?'yes':'no'; ?>, <span class="font-semibold">HAS_ASSIGNED:</span> <?php echo $taskDebug['HAS_ASSIGNED']?'yes':'no'; ?></div>
          </div>
          <div>
            <div class="font-semibold">SQL</div>
            <pre class="text-[11px] whitespace-pre-wrap overflow-auto bg-white/70 border border-amber-200 rounded p-2"><?php echo htmlspecialchars((string)($taskDebug['sql'] ?? '')); ?></pre>
            <div class="mt-1 font-semibold">Params</div>
            <pre class="text-[11px] whitespace-pre-wrap overflow-auto bg-white/70 border border-amber-200 rounded p-2"><?php echo htmlspecialchars(json_encode($taskDebug['params'] ?? [], JSON_PRETTY_PRINT)); ?></pre>
          </div>
        </div>
        <?php if (!empty($taskDebug['error'])): ?>
          <div class="mt-2 text-red-700">Error: <?php echo htmlspecialchars((string)$taskDebug['error']); ?></div>
        <?php endif; ?>
        <div class="mt-3">
          <div><span class="font-semibold">Found task rows:</span> <?php echo is_array($taskRows)?count($taskRows):0; ?></div>
          <div><span class="font-semibold">Fallback revise-like tasks (ignoring assignment):</span> <?php echo (int)($taskDebug['fallback_count'] ?? 0); ?></div>
          <?php if (isset($taskDebug['fallback_task_revisions'])): ?>
            <div><span class="font-semibold">Tasks with any revision record (task_revisions):</span> <?php echo (int)$taskDebug['fallback_task_revisions']; ?></div>
          <?php endif; ?>
          <?php if (!empty($taskDebug['sample_testing'])): $s=$taskDebug['sample_testing']; ?>
            <div class="mt-1">
              <div class="font-semibold">Sample "TESTING" task</div>
              <div class="text-[12px]">task_id: <?php echo (int)($s['task_id'] ?? 0); ?>, name: <?php echo htmlspecialchars((string)($s['task_name'] ?? '')); ?>, status: <?php echo htmlspecialchars((string)($s['status'] ?? '')); ?>, assigned_to: <?php echo htmlspecialchars((string)($s['assigned_to'] ?? '')); ?>, project_id: <?php echo htmlspecialchars((string)($s['project_id'] ?? '')); ?></div>
            </div>
          <?php else: ?>
            <div class="mt-1 text-[12px]">No exact title match found for "TESTING".</div>
          <?php endif; ?>
          <?php if (!empty($taskDebug['assigned_status_dist'])): ?>
            <div class="mt-3">
              <div class="font-semibold">Assigned tasks status distribution (top 10):</div>
              <ul class="list-disc ml-5 text-[12px] text-amber-900">
                <?php foreach ($taskDebug['assigned_status_dist'] as $d): ?>
                  <li><?php echo htmlspecialchars((string)($d['s'] ?? '(empty)')); ?>: <?php echo (int)($d['c'] ?? 0); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          <div class="mt-2 text-[12px] text-amber-700">Tip: If assigned_to stores users.id (not employees.employee_id), ensure your user_id is set there; project membership via project_users also counts.</div>
        </div>
      </div>
    </div>
  <?php endif; ?>
  </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

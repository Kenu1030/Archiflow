<?php
// Senior Architect Overseen Projects
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$APP_BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($APP_BASE === '/' || $APP_BASE === '.') { $APP_BASE = ''; }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ' . $APP_BASE . '/login.php'); exit; }
if (($_SESSION['user_type'] ?? '') !== 'employee' || strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? '')))) !== 'senior_architect') { header('Location: ' . $APP_BASE . '/index.php'); exit; }
require_once __DIR__ . '/../../backend/connection/connect.php';

// Define allowed project phases
$ALLOWED_PHASES = [
  'Pre-Design / Programming',
  'Schematic Design (SD)',
  'Design Development (DD)',
  'Final Design'
];

$statusMsg = '';

// Ensure phase column exists (best-effort, ignore errors)
try {
  $dbCheck = getDB();
  $dbCheck->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $c = $dbCheck->query("SHOW COLUMNS FROM projects LIKE 'phase'");
  if ($c->rowCount() === 0) {
    $dbCheck->exec("ALTER TABLE projects ADD COLUMN phase VARCHAR(64) DEFAULT 'Pre-Design / Programming'");
  }
} catch (Throwable $e) { /* ignore */ }

// Handle phase update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_phase'], $_POST['project_id'], $_POST['phase'])) {
  $projId = (int)$_POST['project_id'];
  $newPhase = trim($_POST['phase']);
  if (in_array($newPhase, $ALLOWED_PHASES, true)) {
    try {
      $dbUpd = getDB();
      $dbUpd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $stmtU = $dbUpd->prepare("UPDATE projects SET phase = ? WHERE project_id = ? LIMIT 1");
      $stmtU->execute([$newPhase, $projId]);
      // Notify PM if project_manager_id / manager_id present & notifications table exists
      $pmUser = null;
      try {
        $stmtPM = $dbUpd->prepare("SELECT project_manager_id, manager_id FROM projects WHERE project_id = ? LIMIT 1");
        $stmtPM->execute([$projId]);
        $rowPM = $stmtPM->fetch(PDO::FETCH_ASSOC);
        if ($rowPM) {
          if (!empty($rowPM['project_manager_id'])) { $pmUser = (int)$rowPM['project_manager_id']; }
          elseif (!empty($rowPM['manager_id'])) { $pmUser = (int)$rowPM['manager_id']; }
        }
      } catch (Throwable $e) {}
      if ($pmUser) {
        try {
          $dbUpd->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())")
                ->execute([$pmUser, 'Project phase updated to ' . $newPhase]);
        } catch (Throwable $e) { /* ignore */ }
      }
      $statusMsg = 'Phase updated to ' . htmlspecialchars($newPhase);
    } catch (Throwable $e) {
      $statusMsg = 'Failed to update phase: ' . htmlspecialchars($e->getMessage());
    }
  } else {
    $statusMsg = 'Invalid phase selected';
  }
}

$projects = [];
$errorMsg = '';
try {
  $db = getDB();
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  // Ensure archive/delete columns exist (best-effort)
  try { $cA=$db->query("SHOW COLUMNS FROM projects LIKE 'is_archived'"); if($cA->rowCount()===0){ $db->exec("ALTER TABLE projects ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0"); } } catch(Throwable $e){}
  try { $cD=$db->query("SHOW COLUMNS FROM projects LIKE 'is_deleted'"); if($cD->rowCount()===0){ $db->exec("ALTER TABLE projects ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0"); } } catch(Throwable $e){}

  // resolve employee_id
  $stmt = $db->prepare('SELECT employee_id FROM employees WHERE user_id=? LIMIT 1');
  $stmt->execute([$_SESSION['user_id']]);
  $emp = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$emp) { throw new Exception('Employee not found'); }
  $employeeId = (int)$emp['employee_id'];

  // Handle archive/unarchive/delete actions
  if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['project_id'])) {
    $actId = (int)$_POST['project_id'];
    if (isset($_POST['archive_project'])) {
      try { $db->prepare('UPDATE projects SET is_archived=1 WHERE project_id=? LIMIT 1')->execute([$actId]); $statusMsg='Project archived.'; } catch(Throwable $e){ $statusMsg='Archive failed: '.htmlspecialchars($e->getMessage()); }
    } elseif (isset($_POST['unarchive_project'])) {
      try { $db->prepare('UPDATE projects SET is_archived=0 WHERE project_id=? LIMIT 1')->execute([$actId]); $statusMsg='Project unarchived.'; } catch(Throwable $e){ $statusMsg='Unarchive failed: '.htmlspecialchars($e->getMessage()); }
    } elseif (isset($_POST['delete_project'])) {
      // Guard: prevent delete if has client review history
      $hasReviews=false; try { $cr=$db->prepare('SELECT 1 FROM project_client_review_files WHERE project_id=? LIMIT 1'); $cr->execute([$actId]); $hasReviews=(bool)$cr->fetchColumn(); } catch(Throwable $e){}
      if ($hasReviews) {
        $statusMsg='Cannot delete: has client review files. Archive instead.';
      } else {
        try { $db->prepare('UPDATE projects SET is_deleted=1 WHERE project_id=? LIMIT 1')->execute([$actId]); $statusMsg='Project deleted (soft).'; } catch(Throwable $e){ $statusMsg='Delete failed: '.htmlspecialchars($e->getMessage()); }
      }
    }
  }

  $showArchived = (isset($_GET['archived']) && $_GET['archived']=='1');
  $filter = $showArchived ? 'p.is_archived=1' : 'p.is_archived=0';
  $filter .= ' AND (p.is_deleted=0 OR p.is_deleted IS NULL)';

  $sql = "SELECT DISTINCT p.project_id, p.project_code, p.project_name, p.project_type, p.status, p.phase, p.project_manager_id, p.created_at, p.is_archived, p.is_deleted
          FROM project_senior_architects psa
          JOIN projects p ON p.project_id = psa.project_id
          WHERE psa.employee_id = ? AND $filter
          ORDER BY p.created_at DESC";
  $stmt = $db->prepare($sql);
  $stmt->execute([$employeeId]);
  $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $errorMsg = $e->getMessage(); }

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-50">
  <div class="max-w-full px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-slate-900">Overseen Projects <?php if(isset($showArchived) && $showArchived) echo '(Archived)'; ?></h1>
        <p class="text-slate-500 mt-1">Projects you're assigned to as Senior Architect. <?php echo $showArchived? 'Viewing archived projects.' : 'Viewing active projects.'; ?></p>
        <?php if ($statusMsg): ?>
          <div class="mt-3 p-3 rounded-md text-sm <?php echo (preg_match('/^(Phase updated|Project archived|Project unarchived|Project deleted)/',$statusMsg) ? 'bg-green-50 text-green-700 ring-1 ring-green-200' : 'bg-red-50 text-red-700 ring-1 ring-red-200'); ?>"><?php echo $statusMsg; ?></div>
        <?php endif; ?>
      </div>
      <div class="flex items-center gap-3">
        <?php if(!$showArchived): ?>
          <a href="?archived=1" class="text-xs px-3 py-1.5 rounded bg-slate-100 text-slate-700 hover:bg-slate-200">View Archived</a>
        <?php else: ?>
          <a href="?" class="text-xs px-3 py-1.5 rounded bg-slate-100 text-slate-700 hover:bg-slate-200">View Active</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($errorMsg): ?>
      <div class="mb-6 p-4 rounded-lg ring-1 ring-red-200 bg-red-50 text-red-800">Error: <?php echo htmlspecialchars($errorMsg); ?></div>
    <?php endif; ?>

    <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm">
      <div class="overflow-x-auto -mx-4 sm:mx-0">
        <table class="min-w-full divide-y divide-slate-200">
          <thead>
            <tr class="text-left text-xs font-medium uppercase tracking-wider text-slate-500">
              <th class="py-3 pr-3 pl-4">Project</th>
              <th class="py-3 px-3">Type</th>
              <th class="py-3 px-3">Status</th>
              <th class="py-3 px-3">Phase</th>
              <th class="py-3 pl-3 pr-4 text-right">Created</th>
              <th class="py-3 pl-3 pr-4 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php if (empty($projects)): ?>
              <tr><td colspan="4" class="py-6 text-center text-slate-500">No overseen projects yet.</td></tr>
            <?php else: foreach ($projects as $p): ?>
              <tr class="hover:bg-slate-50">
                <td class="py-3 pr-3 pl-4">
                  <div class="font-medium text-slate-900"><?php echo htmlspecialchars($p['project_name']); ?><?php if(!empty($p['is_archived'])): ?><span class="ml-2 inline-block px-1.5 py-0.5 text-[10px] rounded bg-amber-100 text-amber-700">Archived</span><?php endif; ?></div>
                  <div class="text-xs text-slate-500"><?php echo htmlspecialchars($p['project_code']); ?></div>
                </td>
                <td class="py-3 px-3 text-slate-700"><?php
                  $ptype = strtolower((string)($p['project_type'] ?? ''));
                  $ptypeLabel = $ptype === 'fit_out' ? 'Fit In' : ($ptype === 'design_only' ? 'Design Only' : ucwords(str_replace('_',' ', $ptype)));
                  echo htmlspecialchars($ptypeLabel);
                ?></td>
                <td class="py-3 px-3">
                  <?php
                    $st = $p['status'];
                    $cls = 'bg-gray-100 text-gray-800';
                    if ($st === 'planning') $cls = 'bg-yellow-100 text-yellow-800';
                    elseif ($st === 'design') $cls = 'bg-blue-100 text-blue-800';
                    elseif ($st === 'construction') $cls = 'bg-purple-100 text-purple-800';
                    elseif ($st === 'completed') $cls = 'bg-green-100 text-green-800';
                  ?>
                  <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo htmlspecialchars($cls); ?>"><?php echo htmlspecialchars($st); ?></span>
                </td>
                <td class="py-3 px-3">
                  <?php $phaseVal = $p['phase'] ?? 'Pre-Design / Programming'; ?>
                  <form method="post" class="flex items-center gap-2">
                    <input type="hidden" name="project_id" value="<?php echo (int)$p['project_id']; ?>">
                    <select name="phase" class="text-xs rounded border-slate-300 focus:ring-indigo-500 focus:border-indigo-500 py-1 px-2">
                      <?php foreach ($ALLOWED_PHASES as $ph): ?>
                        <option value="<?php echo htmlspecialchars($ph); ?>" <?php echo ($ph === $phaseVal ? 'selected' : ''); ?>><?php echo htmlspecialchars($ph); ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" name="update_phase" value="1" class="px-2 py-1 text-xs rounded bg-indigo-600 text-white hover:bg-indigo-700">Save</button>
                  </form>
                </td>
                <td class="py-3 pl-3 pr-4 text-right text-slate-600"><?php echo htmlspecialchars(date('M j, Y', strtotime($p['created_at']))); ?></td>
                <td class="py-3 pl-3 pr-4 text-right flex items-center justify-end gap-2">
                  <a href="/ArchiFlow/employees/senior_architects/project-details.php?project_id=<?php echo (int)$p['project_id']; ?>" class="px-2 py-1 text-xs rounded bg-blue-600 text-white hover:bg-blue-700">View</a>
                  <a href="/ArchiFlow/employees/senior_architects/project-edit.php?project_id=<?php echo (int)$p['project_id']; ?>" class="px-2 py-1 text-xs rounded bg-emerald-600 text-white hover:bg-emerald-700">Edit</a>
                  <?php if (empty($p['is_archived'])): ?>
                    <form method="post" class="inline" onsubmit="return confirm('Archive this project? You can unarchive later.');">
                      <input type="hidden" name="project_id" value="<?php echo (int)$p['project_id']; ?>">
                      <button type="submit" name="archive_project" value="1" class="px-2 py-1 text-xs rounded bg-slate-600 text-white hover:bg-slate-700">Archive</button>
                    </form>
                  <?php else: ?>
                    <form method="post" class="inline" onsubmit="return confirm('Unarchive this project?');">
                      <input type="hidden" name="project_id" value="<?php echo (int)$p['project_id']; ?>">
                      <button type="submit" name="unarchive_project" value="1" class="px-2 py-1 text-xs rounded bg-amber-600 text-white hover:bg-amber-700">Unarchive</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" onsubmit="return confirm('Delete this project? This will soft-delete if allowed (no review history). Continue?');" class="inline">
                    <input type="hidden" name="project_id" value="<?php echo (int)$p['project_id']; ?>">
                    <button type="submit" name="delete_project" value="1" class="px-2 py-1 text-xs rounded bg-red-600 text-white hover:bg-red-700">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>


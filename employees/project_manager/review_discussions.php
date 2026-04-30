<?php
// Project Manager read-only view of Senior Architect & Client review file discussions
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in']!==true) { header('Location: /login.php'); exit; }
$pos = strtolower(str_replace(' ','_', $_SESSION['position'] ?? ''));
if (($_SESSION['user_type'] ?? '')!=='employee' || $pos!=='project_manager') { header('Location: /index.php'); exit; }

require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ensure base tables exist (soft fail on missing review tables)
try { $db->query('SELECT 1 FROM project_client_review_files LIMIT 1'); } catch(Throwable $e){ die('Review files table missing.'); }
try { $db->query('SELECT 1 FROM project_client_review_file_messages LIMIT 1'); } catch(Throwable $e){ /* messages may not yet exist */ }

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

// Map to employee_id if possible
$employeeId = 0;
try {
  $hasEmployees = $db->query("SHOW TABLES LIKE 'employees'")->rowCount() > 0;
  if ($hasEmployees) {
    $emp = $db->prepare('SELECT employee_id FROM employees WHERE user_id=? LIMIT 1');
    $emp->execute([$currentUserId]);
    $row = $emp->fetch(PDO::FETCH_ASSOC);
    $employeeId = $row ? (int)$row['employee_id'] : 0;
  }
} catch(Throwable $e) {}

// Determine projects PK
$projects_pk='id';
try {
  $c=$db->query("SHOW COLUMNS FROM projects LIKE 'id'");
  if ($c && $c->rowCount()===0) {
    $c2=$db->query("SHOW COLUMNS FROM projects LIKE 'project_id'");
    if ($c2 && $c2->rowCount()>0) $projects_pk='project_id';
  }
} catch(Throwable $e){}

// Column discovery for archive/delete
$projCols=[]; try { foreach($db->query('SHOW COLUMNS FROM projects') as $r) { $projCols[$r['Field']]=true; } } catch(Throwable $e){}

// Build list of projects where PM is assigned (reuse logic philosophy from projects/projects.php)
$projects=[]; $allowedProjectIds=[];
try {
  $conditions=[]; $params=[];
  if (isset($projCols['manager_id'])) { $conditions[]='manager_id=?'; $params[]=$currentUserId; }
  if (isset($projCols['project_manager_id'])) { $conditions[]='(project_manager_id=? OR project_manager_id=?)'; $params[]=$currentUserId; $params[]=$employeeId>0?$employeeId:-1; }
  $archFilter='';
  if (isset($projCols['is_archived'])) $archFilter.=' AND is_archived=0';
  if (isset($projCols['is_deleted'])) $archFilter.=' AND (is_deleted=0 OR is_deleted IS NULL)';
  $orderBy = isset($projCols['created_at']) ? 'created_at DESC' : ($projects_pk.' DESC');
  if ($conditions) {
    $sql='SELECT * FROM projects WHERE (' . implode(' OR ', $conditions) . ')'+$archFilter+' ORDER BY '+$orderBy; // placeholder concatenation fix next
  }
} catch(Throwable $e){}
// Rebuild SQL string properly (PHP + operator used mistakenly above) – patching inline
$projects=[];
try {
  $conditions=[]; $params=[];
  if (isset($projCols['manager_id'])) { $conditions[]='manager_id=?'; $params[]=$currentUserId; }
  if (isset($projCols['project_manager_id'])) { $conditions[]='(project_manager_id=? OR project_manager_id=?)'; $params[]=$currentUserId; $params[]=$employeeId>0?$employeeId:-1; }
  $archFilter=''; if (isset($projCols['is_archived'])) $archFilter.=' AND is_archived=0'; if (isset($projCols['is_deleted'])) $archFilter.=' AND (is_deleted=0 OR is_deleted IS NULL)';
  $orderBy = isset($projCols['created_at']) ? 'created_at DESC' : ($projects_pk.' DESC');
  if ($conditions) {
    $sql='SELECT * FROM projects WHERE (' . implode(' OR ', $conditions) . ")$archFilter ORDER BY $orderBy";
    $ps=$db->prepare($sql); $ps->execute($params); $projects=$ps->fetchAll(PDO::FETCH_ASSOC);
  }
  if (!$projects) {
    // fallback to project_users table
    $hasPU = $db->query("SHOW TABLES LIKE 'project_users'")->rowCount()>0;
    if ($hasPU) {
      $archFilter2=''; if (isset($projCols['is_archived'])) $archFilter2.=' AND p.is_archived=0'; if (isset($projCols['is_deleted'])) $archFilter2.=' AND (p.is_deleted=0 OR p.is_deleted IS NULL)';
      $sql = "SELECT p.* FROM projects p JOIN project_users pu ON pu.project_id=p.$projects_pk WHERE pu.user_id=? AND (pu.role_in_project LIKE 'Project Manager' OR pu.role_in_project LIKE '%Manager%')$archFilter2 ORDER BY $orderBy";
      $ps=$db->prepare($sql); $ps->execute([$currentUserId]); $projects=$ps->fetchAll(PDO::FETCH_ASSOC);
    }
  }
  foreach($projects as $p){ $allowedProjectIds[]=(int)$p[$projects_pk]; }
} catch(Throwable $e){}

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$reviewId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errorMsg=''; $infoMsg='';
if ($projectId && !in_array($projectId,$allowedProjectIds,true)) { $errorMsg='Unauthorized project selection.'; }

$files=[]; $currentFile=null; $messages=[];
if ($projectId && !$errorMsg) {
  $st = $db->prepare('SELECT id, original_name, version, review_status, created_at, file_path FROM project_client_review_files WHERE project_id=? ORDER BY original_name, version DESC');
  $st->execute([$projectId]);
  $files=$st->fetchAll(PDO::FETCH_ASSOC);
  if ($reviewId) {
    $sf=$db->prepare('SELECT * FROM project_client_review_files WHERE id=? AND project_id=? LIMIT 1');
    $sf->execute([$reviewId,$projectId]);
    $currentFile=$sf->fetch(PDO::FETCH_ASSOC);
    if ($currentFile) {
      try {
        $ms=$db->prepare('SELECT m.*, u.full_name FROM project_client_review_file_messages m LEFT JOIN users u ON u.id=m.author_user_id WHERE m.review_file_id=? ORDER BY m.created_at ASC');
        $ms->execute([$reviewId]);
        $messages=$ms->fetchAll(PDO::FETCH_ASSOC);
      } catch(Throwable $e){}
    }
  }
}

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 pb-14">
  <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-10">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6 mb-10">
      <div class="space-y-2">
        <div class="flex items-center gap-3">
          <span class="w-11 h-11 rounded-xl bg-indigo-600/10 text-indigo-600 flex items-center justify-center text-xl"><i class="fas fa-comments"></i></span>
          <div>
            <h1 class="text-3xl font-bold tracking-tight text-gray-900">Design Review Discussions</h1>
            <p class="text-sm text-gray-500">Read the conversation between the Senior Architect and Client.</p>
          </div>
        </div>
        <div class="flex flex-wrap gap-2 text-[11px] font-medium text-gray-500">
          <span class="px-2 py-0.5 rounded bg-white shadow-sm ring-1 ring-gray-200">Read-Only</span>
          <span class="px-2 py-0.5 rounded bg-white shadow-sm ring-1 ring-gray-200">Versioned Files</span>
          <span class="px-2 py-0.5 rounded bg-white shadow-sm ring-1 ring-gray-200">Senior Architect Replies Highlighted</span>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <a href="/Archiflow/employees/project_manager/projects/projects.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 shadow-sm"><i class="fas fa-arrow-left"></i><span>Back to Projects</span></a>
      </div>
    </div>

    <div class="mb-10 flex flex-col sm:flex-row sm:items-end gap-6">
      <form method="get" class="w-full max-w-sm space-y-2">
        <label class="block text-[11px] font-semibold tracking-wide text-gray-600 uppercase">Project</label>
        <div class="flex gap-3">
          <select name="project_id" class="flex-1 rounded-lg border border-gray-300 bg-white text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 shadow-sm" onchange="this.form.submit()">
            <option value="">-- Select Project --</option>
            <?php foreach($projects as $p): $pid=(int)$p[$projects_pk]; $pname = $p['project_name'] ?? ($p['name'] ?? ('Project #'.$pid)); ?>
              <option value="<?php echo $pid; ?>" <?php if($projectId===$pid) echo 'selected'; ?>><?php echo htmlspecialchars($pname); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
      <?php if($projectId): ?>
        <div class="text-sm text-gray-500 flex items-center gap-2"><i class="fas fa-folder text-gray-400"></i><span>Project ID: <?php echo $projectId; ?></span></div>
      <?php endif; ?>
    </div>

    <?php if($errorMsg): ?>
      <div class="mb-6 p-4 rounded bg-red-50 text-red-700 text-sm"><?php echo $errorMsg; ?></div>
    <?php endif; ?>

    <div class="grid lg:grid-cols-3 gap-8">
      <div class="lg:col-span-1 order-2 lg:order-1">
        <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm overflow-hidden flex flex-col h-[600px]">
          <div class="px-5 py-4 border-b bg-gray-50/60 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-700 tracking-wide flex items-center gap-2"><i class="fas fa-layer-group text-indigo-600"></i><span>Review Files</span></h2>
            <?php if($files): ?><span class="text-[10px] px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-600 font-medium"><?php echo count($files); ?> total</span><?php endif; ?>
          </div>
          <ul class="flex-1 overflow-auto divide-y">
            <?php if(!$files): ?>
              <li class="p-5 text-[11px] text-gray-500">No review files yet.</li>
            <?php else: foreach($files as $f): $active = $reviewId===(int)$f['id']; $badge = match($f['review_status']) { 'approved'=>'bg-green-100 text-green-700 ring-green-200', 'changes_requested'=>'bg-red-100 text-red-700 ring-red-200', default=>'bg-amber-100 text-amber-700 ring-amber-200'}; ?>
              <li class="<?php echo $active ? 'bg-indigo-50/70 ring-inset ring-1 ring-indigo-200' : 'hover:bg-gray-50'; ?> transition">
                <a class="block px-4 py-3" href="review_discussions.php?project_id=<?php echo (int)$projectId; ?>&id=<?php echo (int)$f['id']; ?>">
                  <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                      <div class="text-[12px] font-semibold text-gray-800 truncate" title="<?php echo htmlspecialchars($f['original_name']); ?>">
                        <?php echo htmlspecialchars($f['original_name']); ?>
                      </div>
                      <div class="mt-1 flex items-center gap-2 text-[10px] text-gray-500">
                        <span class="font-mono">v<?php echo (int)$f['version']; ?></span>
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[9px] font-medium ring-1 <?php echo $badge; ?>">
                          <?php echo htmlspecialchars(str_replace('_',' ', $f['review_status'])); ?>
                        </span>
                      </div>
                    </div>
                    <i class="fas fa-chevron-right text-[10px] text-gray-400"></i>
                  </div>
                </a>
              </li>
            <?php endforeach; endif; ?>
          </ul>
        </div>
      </div>

      <div class="lg:col-span-2 order-1 lg:order-2">
        <?php if($currentFile): ?>
          <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-7 mb-8 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-50/30 via-transparent to-transparent pointer-events-none"></div>
            <div class="relative flex flex-col md:flex-row md:items-start md:justify-between gap-6">
              <div class="space-y-2">
                <h2 class="text-xl font-semibold text-gray-900 flex flex-wrap items-center gap-2">
                  <span><?php echo htmlspecialchars($currentFile['original_name']); ?></span>
                  <span class="text-sm font-medium text-gray-500">v<?php echo (int)$currentFile['version']; ?></span>
                </h2>
                <?php $statusBadge = match($currentFile['review_status']) { 'approved'=>'bg-green-100 text-green-700 ring-green-200','changes_requested'=>'bg-red-100 text-red-700 ring-red-200', default=>'bg-amber-100 text-amber-700 ring-amber-200'}; ?>
                <div class="flex items-center gap-3 text-[12px]">
                  <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full font-semibold ring-1 <?php echo $statusBadge; ?>">
                    <i class="fas fa-circle text-[7px]"></i>
                    <?php echo htmlspecialchars(str_replace('_',' ', $currentFile['review_status'])); ?>
                  </span>
                  <span class="text-gray-400">•</span>
                  <span class="text-gray-500">Uploaded <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($currentFile['created_at']))); ?></span>
                </div>
              </div>
              <div class="relative">
                <?php $pubFile = htmlspecialchars($currentFile['file_path']); $fs = __DIR__.'/../../'.str_replace(['../','..\\'],'',$currentFile['file_path']); $ok = is_file($fs); ?>
                <div class="flex flex-wrap items-center gap-3">
                  <a href="<?php echo $pubFile; ?>" target="_blank" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-indigo-600 text-white text-sm font-medium shadow hover:bg-indigo-700"><i class="fas fa-eye"></i><span>View</span></a>
                  <a href="<?php echo $pubFile; ?>" target="_blank" download class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white ring-1 ring-gray-200 text-gray-700 text-sm font-medium shadow-sm hover:bg-gray-50"><i class="fas fa-download"></i><span>Download</span></a>
                  <?php if(isset($_GET['debug']) && !$ok): ?><span class="text-[10px] text-red-500 font-medium">(missing)</span><?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6 mb-8">
            <h3 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-comments text-indigo-600"></i><span>Discussion</span></h3>
            <div class="space-y-5 max-h-80 overflow-auto pr-2" id="messagesBox">
              <?php if(!$messages): ?>
                <div class="text-[12px] text-gray-500">No messages yet.</div>
              <?php else: foreach($messages as $m): $role=strtolower($m['author_role'] ?? ''); $isSA=$role==='senior_architect'; $isClient=$role==='client'; ?>
                <div class="text-[12px] group">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold <?php echo $isSA? 'text-indigo-700':'text-gray-700'; ?>"><?php echo htmlspecialchars($m['full_name'] ?? ('User #'.$m['author_user_id'])); ?></span>
                    <span class="text-[10px] inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-medium ring-1 <?php echo $isSA? 'bg-indigo-50 text-indigo-700 ring-indigo-200' : ($isClient? 'bg-blue-50 text-blue-700 ring-blue-200' : 'bg-gray-100 text-gray-500 ring-gray-200'); ?>">
                      <i class="fas fa-user-tag"></i><?php echo htmlspecialchars($m['author_role']); ?> • <?php echo htmlspecialchars($m['action']); ?>
                    </span>
                    <span class="text-[10px] text-gray-400"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($m['created_at']))); ?></span>
                  </div>
                  <div class="mt-1 rounded-md p-3 leading-snug border <?php echo $isSA? 'bg-indigo-50 border-indigo-100 group-hover:bg-indigo-100' : ($isClient? 'bg-blue-50 border-blue-100 group-hover:bg-blue-100' : 'bg-gray-50 border-gray-100 group-hover:bg-gray-100'); ?> transition text-gray-700">
                    <?php echo nl2br(htmlspecialchars($m['message'])); ?>
                  </div>
                </div>
              <?php endforeach; endif; ?>
            </div>
            <div class="mt-4 text-[11px] text-gray-400 italic">Project Manager view is read-only.</div>
          </div>
        <?php elseif($projectId): ?>
          <div class="p-10 rounded-xl border-2 border-dashed border-gray-300 bg-white text-center text-sm text-gray-500 flex flex-col items-center gap-3">
            <i class="fas fa-hand-pointer text-2xl text-gray-400"></i>
            <span>Select a file from the list to view its discussion.</span>
          </div>
        <?php else: ?>
          <div class="p-10 rounded-xl border-2 border-dashed border-gray-300 bg-white text-center text-sm text-gray-500 flex flex-col items-center gap-3">
            <i class="fas fa-folder-open text-2xl text-gray-400"></i>
            <span>Choose a project above to view design review discussions.</span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

<?php
// Senior Architect discussion & status update interface for client review files
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in']!==true) { header('Location: /login.php'); exit; }
$pos = strtolower(str_replace(' ','_', $_SESSION['position'] ?? ''));
if (($_SESSION['user_type'] ?? '')!=='employee' || $pos!=='senior_architect') { header('Location: /index.php'); exit; }
require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB(); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try { $db->query('SELECT 1 FROM project_client_review_files LIMIT 1'); } catch(Throwable $e){ die('Review table missing.'); }
try { $db->query('SELECT 1 FROM project_client_review_file_messages LIMIT 1'); } catch(Throwable $e){ /* may not exist */ }

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$reviewId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$statusMsg=''; $errorMsg='';

// Validate oversight
$isOverseer=false;
if ($projectId) {
  $chk = $db->prepare('SELECT 1 FROM project_senior_architects psa JOIN employees e ON e.employee_id=psa.employee_id WHERE psa.project_id=? AND e.user_id=? LIMIT 1');
  $chk->execute([$projectId,$currentUserId]);
  $isOverseer = (bool)$chk->fetchColumn();
  if(!$isOverseer) { $errorMsg='You are not assigned to this project.'; }
}

// Handle post (comment or status change)
if(!$errorMsg && $_SERVER['REQUEST_METHOD']==='POST' && $reviewId){
  $action = $_POST['action_type'] ?? 'comment';
  $msg = trim($_POST['message'] ?? '');
  if($msg==='') { $errorMsg='Message required.'; }
  else {
    $db->beginTransaction();
    try {
      $st = $db->prepare('SELECT project_id, review_status FROM project_client_review_files WHERE id=? LIMIT 1');
      $st->execute([$reviewId]);
      $file = $st->fetch(PDO::FETCH_ASSOC);
      if(!$file || (int)$file['project_id']!==$projectId) throw new Exception('File not found');
      $role='senior_architect';
      if(!in_array($action,['comment','request_changes','approve'],true)) $action='comment';
      $ins = $db->prepare('INSERT INTO project_client_review_file_messages (review_file_id, project_id, author_user_id, author_role, action, message) VALUES (?,?,?,?,?,?)');
      $ins->execute([$reviewId,$projectId,$currentUserId,$role,$action,$msg]);
      if ($action==='approve') {
        $db->prepare("UPDATE project_client_review_files SET review_status='approved' WHERE id=? LIMIT 1")->execute([$reviewId]);
      } elseif ($action==='request_changes') {
        $db->prepare("UPDATE project_client_review_files SET review_status='changes_requested' WHERE id=? LIMIT 1")->execute([$reviewId]);
      } elseif ($action==='comment' && isset($_POST['set_pending'])) {
        $db->prepare("UPDATE project_client_review_files SET review_status='pending' WHERE id=? LIMIT 1")->execute([$reviewId]);
      }
      $db->commit();
      header('Location: client_review_discuss.php?project_id='.$projectId.'&id='.$reviewId.'&done=1');
      exit;
    } catch(Throwable $e){ if($db->inTransaction()) $db->rollBack(); $errorMsg='Action failed: '.htmlspecialchars($e->getMessage()); }
  }
}

// Fetch overseen projects list
$projects=[];
if(!$errorMsg){
  $ps = $db->prepare('SELECT p.project_id, p.project_name FROM projects p JOIN project_senior_architects psa ON psa.project_id=p.project_id JOIN employees e ON e.employee_id=psa.employee_id WHERE e.user_id=? ORDER BY p.project_name');
  $ps->execute([$currentUserId]);
  $projects=$ps->fetchAll(PDO::FETCH_ASSOC);
}

$files=[];$currentFile=null;$messages=[];
if($projectId && !$errorMsg){
  $stmt = $db->prepare('SELECT id, original_name, stored_name, version, review_status, created_at, file_path FROM project_client_review_files WHERE project_id=? ORDER BY original_name, version DESC');
  $stmt->execute([$projectId]);
  $files=$stmt->fetchAll(PDO::FETCH_ASSOC);
  if($reviewId){
    $stf=$db->prepare('SELECT * FROM project_client_review_files WHERE id=? AND project_id=? LIMIT 1');
    $stf->execute([$reviewId,$projectId]);
    $currentFile=$stf->fetch(PDO::FETCH_ASSOC);
    if($currentFile){
      try {
        // Correct join: users.user_id (not id) to resolve author names
        $msgSt=$db->prepare('SELECT m.*, u.full_name, CONCAT(u.first_name, " ", u.last_name) AS name_parts FROM project_client_review_file_messages m LEFT JOIN users u ON u.user_id=m.author_user_id WHERE m.review_file_id=? ORDER BY m.created_at ASC');
        $msgSt->execute([$reviewId]);
        $messages=$msgSt->fetchAll(PDO::FETCH_ASSOC);
      } catch(Throwable $e) { /* ignore */ }
      // Fetch latest messages from both client and senior architect for focused feedback panel
      $feedbackMessages=[]; $latestClientAction=null; $clientActionCounts=['comment'=>0,'request_changes'=>0,'approve'=>0];
      try {
        $sqlA = 'SELECT m.*, COALESCE(u.full_name, CONCAT(u.first_name, " ", u.last_name)) AS author_name
                 FROM project_client_review_file_messages m
                 LEFT JOIN users u ON u.user_id=m.author_user_id
                 WHERE m.review_file_id=? AND m.author_role IN ("client","senior_architect") ORDER BY m.created_at DESC';
        $crSt=$db->prepare($sqlA);
        $crSt->execute([$reviewId]);
        $feedbackMessages=$crSt->fetchAll(PDO::FETCH_ASSOC);
      } catch(Throwable $eA) {
        try {
          $sqlB = 'SELECT m.*, COALESCE(u.full_name, CONCAT(u.first_name, " ", u.last_name)) AS author_name
                   FROM project_client_review_file_messages m
                   LEFT JOIN users u ON u.id=m.author_user_id
                   WHERE m.review_file_id=? AND m.author_role IN ("client","senior_architect") ORDER BY m.created_at DESC';
          $crSt=$db->prepare($sqlB);
          $crSt->execute([$reviewId]);
          $feedbackMessages=$crSt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Throwable $eB) {
          try {
            $sqlC = 'SELECT m.* FROM project_client_review_file_messages m WHERE m.review_file_id=? AND m.author_role IN ("client","senior_architect") ORDER BY m.created_at DESC';
            $crSt=$db->prepare($sqlC);
            $crSt->execute([$reviewId]);
            $feedbackMessages=$crSt->fetchAll(PDO::FETCH_ASSOC);
          } catch(Throwable $eC) { $feedbackMessages=[]; }
        }
      }
      foreach($feedbackMessages as $cr){
        if(($cr['author_role'] ?? '') === 'client'){
          $act=strtolower($cr['action'] ?? 'comment');
          if(isset($clientActionCounts[$act])) $clientActionCounts[$act]++;
        }
      }
      // latest client action for badge
      foreach($feedbackMessages as $cr){ if(($cr['author_role'] ?? '')==='client'){ $latestClientAction=$cr; break; } }
    }
  }
}

include __DIR__ . '/../../backend/core/header.php';
?>
<?php
// Helper to resolve a user's display name with caching (supports both schemas)
if (!function_exists('resolve_display_name_sa')) {
  function resolve_display_name_sa(PDO $db, int $userId): string {
    static $cache = [];
    if ($userId <= 0) return 'User #0';
    if (isset($cache[$userId])) return $cache[$userId];
    $name = '';
    // Prefer username if available
    try { $st = $db->prepare("SELECT username FROM users WHERE user_id=? LIMIT 1"); $st->execute([$userId]); $name = trim((string)$st->fetchColumn()); } catch(Throwable $e) {}
    if ($name === '') { try { $st = $db->prepare("SELECT username FROM users WHERE id=? LIMIT 1"); $st->execute([$userId]); $name = trim((string)$st->fetchColumn()); } catch(Throwable $e) {} }
    try {
      if ($name === '') { $st = $db->prepare("SELECT COALESCE(full_name, CONCAT(first_name, ' ', last_name)) AS name FROM users WHERE user_id=? LIMIT 1"); $st->execute([$userId]); $name = trim((string)$st->fetchColumn()); }
    } catch(Throwable $e) { /* ignore */ }
    if ($name === '') {
      try { $st = $db->prepare("SELECT COALESCE(full_name, CONCAT(first_name, ' ', last_name)) AS name FROM users WHERE id=? LIMIT 1"); $st->execute([$userId]); $name = trim((string)$st->fetchColumn()); } catch(Throwable $e) {}
    }
    if ($name === '') {
      try { $st = $db->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name FROM employees WHERE user_id=? LIMIT 1"); $st->execute([$userId]); $name = trim((string)$st->fetchColumn()); } catch(Throwable $e) {}
    }
    if ($name === '') {
      try { $st = $db->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name FROM clients WHERE user_id=? LIMIT 1"); $st->execute([$userId]); $name = trim((string)$st->fetchColumn()); } catch(Throwable $e) {}
    }
    // Legacy fix: if provided id is clients.client_id, resolve via clients -> users
    if ($name === '') {
      try { $st = $db->prepare("SELECT u.username FROM clients c JOIN users u ON u.user_id=c.user_id WHERE c.client_id=? LIMIT 1"); $st->execute([$userId]); $name = trim((string)$st->fetchColumn()); } catch(Throwable $e) {}
    }
    if ($name === '') { $name = 'User #'.$userId; }
    return $cache[$userId] = $name;
  }
}
?>
<?php
// Build "needs attention" list (files with changes requested) across overseen projects
$needsAttention=[]; $needsErr=null;
try {
  $attStmt=$db->prepare("SELECT f.id, f.project_id, f.original_name, f.version, f.review_status, f.updated_at, p.project_name
      FROM project_client_review_files f
      JOIN projects p ON p.project_id=f.project_id
      WHERE f.review_status='changes_requested' AND (
          EXISTS(
            SELECT 1 FROM project_senior_architects psa
            JOIN employees e ON e.employee_id = psa.employee_id
            WHERE psa.project_id = f.project_id AND e.user_id = ?
          )
          OR EXISTS(
            SELECT 1 FROM project_users pu
            WHERE pu.project_id = f.project_id
              AND pu.user_id = ?
              AND LOWER(pu.role_in_project) LIKE '%senior%'
          )
      )
      ORDER BY f.updated_at DESC LIMIT 50");
  $attStmt->execute([$currentUserId,$currentUserId]);
  $needsAttention=$attStmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){ $needsErr=$e->getMessage(); }
?>
<main class="max-w-6xl mx-auto px-4 py-8">
  <div class="mb-8 bg-amber-50 border border-amber-200 rounded-lg p-4">
    <div class="flex items-start gap-3">
      <div class="mt-0.5 text-amber-600"><i class="fas fa-exclamation-circle"></i></div>
      <div class="flex-1">
        <div class="font-semibold text-amber-800 text-sm mb-2">Client Change Requests (<?php echo count($needsAttention); ?>)</div>
        <?php if(!$needsAttention): ?>
          <div class="text-xs text-amber-700">No pending client change requests. When a client selects "Request Changes" the items will appear here.</div>
          <?php if(isset($_GET['debug'])): ?>
            <?php
              // Extra diagnostics: count global change requests & those overseen
              $globalCount = 0; $overseenCount = 0; $assignInfo = [];
              try {
                $gc = $db->query("SELECT COUNT(*) FROM project_client_review_files WHERE review_status='changes_requested'");
                $globalCount = (int)$gc->fetchColumn();
                $ocSt = $db->prepare("SELECT f.project_id, COUNT(*) AS cnt FROM project_client_review_files f WHERE f.review_status='changes_requested' AND EXISTS (SELECT 1 FROM project_senior_architects psa JOIN employees e ON e.employee_id=psa.employee_id WHERE psa.project_id=f.project_id AND e.user_id=?) GROUP BY f.project_id");
                $ocSt->execute([$currentUserId]);
                $rows = $ocSt->fetchAll(PDO::FETCH_ASSOC);
                foreach($rows as $r){ $overseenCount += (int)$r['cnt']; $assignInfo[] = $r['project_id'].':'.$r['cnt']; }
              } catch(Throwable $dx) { $assignInfo[] = 'err'; }
            ?>
            <div class="mt-2 space-y-1">
              <div class="text-[10px] text-amber-600">DEBUG: Query executed successfully, 0 rows for this senior architect.</div>
              <div class="text-[10px] text-amber-600">DEBUG: Global change requests count: <?php echo $globalCount; ?>; Overseen change requests: <?php echo $overseenCount; ?>.</div>
              <div class="text-[10px] text-amber-600">DEBUG: Overseen project breakdown: <?php echo htmlspecialchars(implode(', ', $assignInfo)); ?>.</div>
              <div class="text-[10px] text-amber-600">TIP: If global &gt; 0 but overseen is 0, ensure this senior architect is assigned in project_senior_architects table.</div>
            </div>
          <?php endif; ?>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-xs">
            <thead class="text-amber-700">
              <tr class="text-left">
                <th class="pr-3 py-1 font-medium">Project</th>
                <th class="pr-3 py-1 font-medium">File</th>
                <th class="pr-3 py-1 font-medium">v</th>
                <th class="pr-3 py-1 font-medium">Updated</th>
                <th class="py-1 font-medium">Open</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-amber-100">
              <?php foreach($needsAttention as $na): ?>
              <tr class="hover:bg-amber-100/40">
                <td class="pr-3 py-1 whitespace-nowrap text-amber-800"><?php echo htmlspecialchars($na['project_name'] ?: ('Project #'.$na['project_id'])); ?></td>
                <td class="pr-3 py-1 text-amber-900 max-w-[220px] truncate" title="<?php echo htmlspecialchars($na['original_name']); ?>"><?php echo htmlspecialchars($na['original_name']); ?></td>
                <td class="pr-3 py-1 text-amber-700"><?php echo (int)$na['version']; ?></td>
                <td class="pr-3 py-1 text-amber-600 text-[11px]"><?php echo htmlspecialchars(substr($na['updated_at'],0,16)); ?></td>
                <td class="py-1"><a class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-amber-600 text-white hover:bg-amber-700" href="client_review_discuss.php?project_id=<?php echo (int)$na['project_id']; ?>&id=<?php echo (int)$na['id']; ?>">View</a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="mb-6 flex flex-wrap gap-4 items-end">
    <form method="get" class="space-y-1">
      <label class="block text-xs font-medium text-slate-500">Project</label>
      <select name="project_id" class="rounded border-slate-300" onchange="this.form.submit()">
        <option value="">-- Select --</option>
        <?php foreach($projects as $p): ?>
          <option value="<?php echo (int)$p['project_id']; ?>" <?php if($projectId===(int)$p['project_id']) echo 'selected'; ?>><?php echo htmlspecialchars($p['project_name'] ?: ('Project #'.$p['project_id'])); ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if($projectId): ?><div class="text-sm text-slate-500">Review files for project #<?php echo (int)$projectId; ?></div><?php endif; ?>
  <!-- Removed Upload Page link per request -->
  </div>
  <?php if($errorMsg): ?><div class="mb-6 p-4 rounded bg-red-50 text-red-700 text-sm"><?php echo $errorMsg; ?></div><?php endif; ?>
  <?php if(isset($_GET['done'])): ?><div class="mb-6 p-4 rounded bg-green-50 text-green-700 text-sm">Action saved.</div><?php endif; ?>
  <div class="grid md:grid-cols-3 gap-6">
    <div class="md:col-span-1">
      <div class="bg-white rounded-lg border border-slate-200 shadow-sm">
        <div class="px-4 py-3 border-b text-sm font-semibold">Client Review Files</div>
        <ul class="max-h-[520px] overflow-auto divide-y">
          <?php if(!$files): ?><li class="p-4 text-xs text-slate-500">None yet.</li><?php else: foreach($files as $f): $active=$reviewId===(int)$f['id']; ?>
            <li class="p-3 <?php echo $active?'bg-indigo-50':'hover:bg-slate-50'; ?>">
              <a class="block" href="client_review_discuss.php?project_id=<?php echo (int)$projectId; ?>&id=<?php echo (int)$f['id']; ?>">
                <div class="text-xs font-medium text-slate-700 truncate" title="<?php echo htmlspecialchars($f['original_name']); ?>"><?php echo htmlspecialchars($f['original_name']); ?> (v<?php echo (int)$f['version']; ?>)</div>
                <div class="mt-0.5 text-[11px] uppercase tracking-wide <?php echo match($f['review_status']) { 'approved'=>'text-green-600','changes_requested'=>'text-red-600', default=>'text-amber-600'}; ?>"><?php echo htmlspecialchars(str_replace('_',' ', $f['review_status'])); ?></div>
              </a>
            </li>
          <?php endforeach; endif; ?>
        </ul>
      </div>
    </div>
    <div class="md:col-span-2">
      <?php if($currentFile): ?>
        <div class="bg-white rounded-lg border border-slate-200 shadow-sm p-5 mb-6">
          <div class="flex items-start justify-between gap-4">
            <div>
              <h2 class="text-lg font-semibold text-slate-900"><?php echo htmlspecialchars($currentFile['original_name']); ?> <span class="text-sm text-slate-500">(v<?php echo (int)$currentFile['version']; ?>)</span></h2>
              <div class="mt-1 text-xs">Status: <span class="font-medium <?php echo match($currentFile['review_status']) { 'approved'=>'text-green-600','changes_requested'=>'text-red-600', default=>'text-amber-600'}; ?>"><?php echo htmlspecialchars(str_replace('_',' ', $currentFile['review_status'])); ?></span></div>
              <div class="mt-1 text-xs text-slate-500">Uploaded: <?php echo htmlspecialchars($currentFile['created_at']); ?></div>
            </div>
            <div>
              <?php $filePublic = htmlspecialchars($currentFile['file_path']); $fileFs = __DIR__.'/../../'.str_replace(['../','..\\'],'',$currentFile['file_path']); $fileOk = is_file($fileFs); ?>
              <div class="flex items-center gap-2">
                <a href="<?php echo $filePublic; ?>" target="_blank" class="inline-flex items-center px-3 py-1.5 text-sm rounded bg-blue-600 text-white hover:bg-blue-700"><i class="fas fa-eye mr-2"></i>View</a>
                <a href="<?php echo $filePublic; ?>" target="_blank" download class="inline-flex items-center px-3 py-1.5 text-sm rounded bg-indigo-600 text-white hover:bg-indigo-700"><i class="fas fa-download mr-2"></i>Download</a>
                <?php if(isset($_GET['debug']) && !$fileOk): ?>
                  <span class="ml-1 text-xs text-red-500">(file missing)</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <!-- Client Feedback Summary Panel -->
        <div class="bg-white rounded-lg border border-slate-200 shadow-sm p-5 mb-6">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2"><i class="fas fa-user-check text-indigo-600"></i><span>Client Feedback</span></h3>
            <?php if($latestClientAction): ?>
              <?php $actBadge = match(strtolower($latestClientAction['action'] ?? 'comment')) { 'approve'=>'bg-green-100 text-green-700','request_changes'=>'bg-red-100 text-red-700', default=>'bg-slate-100 text-slate-600'}; ?>
              <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-[11px] font-medium <?php echo $actBadge; ?>">
                <i class="fas fa-clock"></i>
                Latest: <?php echo htmlspecialchars(str_replace('_',' ', strtolower($latestClientAction['action'] ?? 'comment'))); ?>
              </span>
            <?php endif; ?>
          </div>
          <?php if(!$feedbackMessages): ?>
            <div class="text-xs text-slate-500">No client or senior responses yet.</div>
          <?php else: ?>
            <div class="grid md:grid-cols-4 gap-3 mb-4 text-[11px]">
              <div class="p-2 rounded bg-slate-50 border border-slate-200 flex flex-col"><span class="text-slate-500">Comments</span><span class="font-semibold text-slate-700 text-sm"><?php echo (int)$clientActionCounts['comment']; ?></span></div>
              <div class="p-2 rounded bg-red-50 border border-red-200 flex flex-col"><span class="text-red-600">Change Requests</span><span class="font-semibold text-red-700 text-sm"><?php echo (int)$clientActionCounts['request_changes']; ?></span></div>
              <div class="p-2 rounded bg-green-50 border border-green-200 flex flex-col"><span class="text-green-600">Approvals</span><span class="font-semibold text-green-700 text-sm"><?php echo (int)$clientActionCounts['approve']; ?></span></div>
              <div class="p-2 rounded bg-amber-50 border border-amber-200 flex flex-col"><span class="text-amber-600">Status</span><span class="font-semibold text-amber-700 text-sm"><?php echo htmlspecialchars(str_replace('_',' ', $currentFile['review_status'])); ?></span></div>
            </div>
            <div class="space-y-3 max-h-48 overflow-auto pr-1">
              <?php foreach(array_slice($feedbackMessages,0,10) as $cr): $isYouFB = ((int)($cr['author_user_id'] ?? 0) === (int)$currentUserId); $authorName = $isYouFB ? 'You' : resolve_display_name_sa($db, (int)($cr['author_user_id'] ?? 0)); ?>
                <?php $crBadge = match(strtolower($cr['action'] ?? 'comment')) { 'approve'=>'bg-green-100 text-green-700','request_changes'=>'bg-red-100 text-red-700', default=>'bg-slate-100 text-slate-600'}; ?>
                <div class="border border-slate-200 rounded-md p-2 bg-slate-50">
                  <div class="flex items-center justify-between gap-2 mb-1">
                    <span class="text-[11px] font-medium text-slate-700 truncate"><?php echo htmlspecialchars($authorName); ?></span>
                    <div class="flex items-center gap-1">
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold <?php echo $crBadge; ?>"><?php echo htmlspecialchars(str_replace('_',' ', strtolower($cr['action'] ?? 'comment'))); ?></span>
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-slate-100 text-slate-600"><?php echo htmlspecialchars($cr['author_role'] ?? ''); ?></span>
                    </div>
                  </div>
                  <div class="text-[11px] leading-snug text-slate-600"><?php echo nl2br(htmlspecialchars($cr['message'])); ?></div>
                  <div class="mt-1 text-[10px] text-slate-400 flex items-center gap-1"><i class="fas fa-clock"></i><?php echo htmlspecialchars($cr['created_at']); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
            <!-- Quick Reply inline form -->
            <div class="mt-4">
              <form method="post" class="space-y-2">
                <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                <input type="hidden" name="review_id" value="<?php echo (int)$reviewId; ?>">
                <div class="grid sm:grid-cols-5 gap-2 items-stretch">
                  <div class="sm:col-span-2">
                    <select name="action_type" class="w-full rounded border-slate-300 text-sm">
                      <option value="comment">Comment</option>
                      <option value="request_changes">Request Changes</option>
                      <option value="approve">Approve</option>
                    </select>
                  </div>
                  <div class="sm:col-span-3">
                    <input type="text" name="message" class="w-full rounded border-slate-300 text-sm" placeholder="Write a quick reply..." required />
                  </div>
                </div>
                <div class="flex items-center justify-between gap-3">
                  <label class="inline-flex items-center gap-1 text-[11px] text-slate-500"><input type="checkbox" name="set_pending" value="1"> Reset to Pending (comment only)</label>
                  <button class="inline-flex items-center gap-2 px-3 py-1.5 rounded bg-indigo-600 text-white text-xs hover:bg-indigo-700"><i class="fas fa-reply"></i><span>Reply</span></button>
                </div>
              </form>
            </div>
          <?php endif; ?>
        </div>
        <div class="bg-white rounded-lg border border-slate-200 shadow-sm p-5 mb-6">
          <h3 class="text-sm font-semibold text-slate-800 mb-3">Conversation</h3>
          <div class="space-y-4 max-h-72 overflow-auto pr-1">
            <?php if(!$messages): ?><div class="text-xs text-slate-500">No messages yet.</div><?php else: foreach($messages as $m): $isYou = ((int)($m['author_user_id'] ?? 0) === (int)$currentUserId); $mName = resolve_display_name_sa($db, (int)($m['author_user_id'] ?? 0)); ?>
              <div class="text-xs">
                <div class="flex items-center gap-2">
                  <span class="font-medium text-slate-700"><?php echo $isYou ? 'You' : htmlspecialchars($mName); ?></span>
                  <span class="text-[10px] text-slate-400">(<?php echo htmlspecialchars($m['author_role']); ?> • <?php echo htmlspecialchars($m['action']); ?>)</span>
                  <span class="text-[10px] text-slate-400"><?php echo htmlspecialchars($m['created_at']); ?></span>
                </div>
                <div class="mt-1 rounded p-2 leading-snug <?php echo $isYou ? 'bg-indigo-50 text-slate-800 border border-indigo-100' : 'bg-slate-50 text-slate-700'; ?>"><?php echo nl2br(htmlspecialchars($m['message'])); ?></div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
        <div class="bg-white rounded-lg border border-slate-200 shadow-sm p-5">
          <h3 class="text-sm font-semibold text-slate-800 mb-3">Respond / Update Status</h3>
          <form method="post" class="space-y-3">
            <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
            <input type="hidden" name="review_id" value="<?php echo (int)$reviewId; ?>">
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Action</label>
              <select name="action_type" class="rounded border-slate-300 text-sm">
                <option value="comment">Comment</option>
                <option value="request_changes">Request Changes</option>
                <option value="approve">Approve</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Message</label>
              <textarea name="message" rows="4" class="w-full rounded border-slate-300 focus:ring-indigo-500 focus:border-indigo-500 text-sm" placeholder="Clarify approval or requested changes" required></textarea>
            </div>
            <div class="flex items-center gap-3">
              <label class="inline-flex items-center gap-1 text-[11px] text-slate-500"><input type="checkbox" name="set_pending" value="1"> Reset to Pending (comment only)</label>
            </div>
            <div class="flex gap-3">
              <button class="inline-flex items-center gap-2 px-4 py-2 rounded bg-indigo-600 text-white text-sm hover:bg-indigo-700"><i class="fas fa-reply"></i><span>Submit</span></button>
              <a href="client_review_discuss.php?project_id=<?php echo (int)$projectId; ?>&id=<?php echo (int)$reviewId; ?>" class="inline-flex items-center px-3 py-2 rounded border text-sm text-slate-600 hover:bg-slate-50">Reset</a>
            </div>
          </form>
        </div>
      <?php elseif($projectId): ?>
        <div class="p-6 rounded border border-dashed text-center text-sm text-slate-500">Select a file to view messages & respond.</div>
      <?php else: ?>
        <div class="p-6 rounded border border-dashed text-center text-sm text-slate-500">Choose a project to view client review files.</div>
      <?php endif; ?>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

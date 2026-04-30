<?php
// Client view of files sent for review + ability to approve/request changes/comment
require_once __DIR__ . '/_client_common.php';

// Ensure table exists (soft)
try { $pdo->query('SELECT 1 FROM project_client_review_files LIMIT 1'); } catch(Throwable $e) { die('Review table missing.'); }
try { 
  $pdo->query('SELECT 1 FROM project_client_review_file_messages LIMIT 1');
} catch(Throwable $e) { 
  // Attempt to auto-create the messages table if it does not exist
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_client_review_file_messages (\n      id INT AUTO_INCREMENT PRIMARY KEY,\n      review_file_id INT NOT NULL,\n      project_id INT NOT NULL,\n      author_user_id INT NOT NULL,\n      author_role VARCHAR(40) NOT NULL,\n      action ENUM('comment','request_changes','approve') NOT NULL DEFAULT 'comment',\n      message TEXT NOT NULL,\n      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n      INDEX idx_review_file (review_file_id),\n      INDEX idx_project (project_id),\n      INDEX idx_author (author_user_id),\n      INDEX idx_action (action)\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch(Throwable $ce) { /* suppress; will error on use */ }
}

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$reviewId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
// Allow POST fallback when form submission drops query parameters
$postProjectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$postReviewId  = isset($_POST['review_id']) ? (int)$_POST['review_id'] : 0;
$effectiveProjectId = $projectId ?: $postProjectId;
$effectiveReviewId  = $reviewId ?: $postReviewId;
$statusMsg = '';$errorMsg='';

// Authorization: ensure client owns or is participant in project
$owns = false;
if ($effectiveProjectId) {
  $stmt = $pdo->prepare("SELECT 1 FROM projects WHERE project_id=? AND client_id=? LIMIT 1");
  $stmt->execute([$effectiveProjectId, $clientId]);
  $owns = (bool)$stmt->fetchColumn();
  if (!$owns) { $errorMsg = 'Unauthorized project access.'; }
}

// Handle post actions
if (!$errorMsg && $_SERVER['REQUEST_METHOD']==='POST' && $effectiveReviewId) {
  $action = $_POST['action_type'] ?? 'comment';
  $msg = trim($_POST['message'] ?? '');
  if ($msg === '') { $errorMsg = 'Message required.'; }
  else {
    $pdo->beginTransaction();
    try {
      // Re-validate file belongs to project & client
      $st = $pdo->prepare("SELECT project_id, review_status FROM project_client_review_files WHERE id=? LIMIT 1");
      $st->execute([$effectiveReviewId]);
      $file = $st->fetch(PDO::FETCH_ASSOC);
      if (!$file || (int)$file['project_id'] !== $effectiveProjectId) { throw new Exception('File not found or mismatched project'); }
      $ins = $pdo->prepare("INSERT INTO project_client_review_file_messages (review_file_id, project_id, author_user_id, author_role, action, message) VALUES (?,?,?,?,?,?)");
      $role = 'client';
      if (!in_array($action,['comment','request_changes','approve'],true)) { $action='comment'; }
  // Store the actual logged-in user's id as author_user_id
  $ins->execute([$effectiveReviewId,$effectiveProjectId,$userId,$role,$action,$msg]);
      // If approve or request_changes, update review_status
      if ($action==='approve') {
        $pdo->prepare("UPDATE project_client_review_files SET review_status='approved' WHERE id=? LIMIT 1")->execute([$effectiveReviewId]);
      } elseif ($action==='request_changes') {
        $pdo->prepare("UPDATE project_client_review_files SET review_status='changes_requested' WHERE id=? LIMIT 1")->execute([$effectiveReviewId]);
      }
      $pdo->commit();
      $statusMsg = 'Action recorded.';
      header('Location: review_files.php?project_id='.$effectiveProjectId.'&id='.$effectiveReviewId.'&done=1');
      exit;
    } catch(Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errorMsg = 'Action failed: '.htmlspecialchars($e->getMessage());
    }
  }
}

// Fetch projects list for this client (quick select)
$projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE client_id=".(int)$clientId." ORDER BY project_name")->fetchAll();

$files = [];$currentFile=null;$messages=[];
$seniorArchitects = [];
if ($effectiveProjectId && !$errorMsg) {
  $stmt = $pdo->prepare("SELECT id, original_name, stored_name, version, review_status, created_at, file_path FROM project_client_review_files WHERE project_id=? ORDER BY original_name, version DESC");
  $stmt->execute([$effectiveProjectId]);
  $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
  // Auto-select first file if none explicitly chosen so preview shows immediately
  if (!$effectiveReviewId && $files) { $effectiveReviewId = (int)$files[0]['id']; }
  if ($effectiveReviewId) {
    $st2 = $pdo->prepare("SELECT * FROM project_client_review_files WHERE id=? AND project_id=? LIMIT 1");
    $st2->execute([$effectiveReviewId,$effectiveProjectId]);
    $currentFile = $st2->fetch(PDO::FETCH_ASSOC);
    if ($currentFile) {
      // Robust messages fetch: try users.user_id, then users.id, then no join
      try {
        $sql1 = "SELECT m.*, COALESCE(u.full_name, CONCAT(u.first_name, ' ', u.last_name)) AS full_name
                 FROM project_client_review_file_messages m
                 LEFT JOIN users u ON u.user_id = m.author_user_id
                 WHERE m.review_file_id=? ORDER BY m.created_at ASC";
        $messagesStmt = $pdo->prepare($sql1);
        $messagesStmt->execute([$effectiveReviewId]);
        $messages = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);
      } catch (Throwable $e1) {
        try {
          $sql2 = "SELECT m.*, COALESCE(u.full_name, CONCAT(u.first_name, ' ', u.last_name)) AS full_name
                   FROM project_client_review_file_messages m
                   LEFT JOIN users u ON u.id = m.author_user_id
                   WHERE m.review_file_id=? ORDER BY m.created_at ASC";
          $messagesStmt = $pdo->prepare($sql2);
          $messagesStmt->execute([$effectiveReviewId]);
          $messages = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) {
          try {
            $sql3 = "SELECT m.* FROM project_client_review_file_messages m WHERE m.review_file_id=? ORDER BY m.created_at ASC";
            $messagesStmt = $pdo->prepare($sql3);
            $messagesStmt->execute([$effectiveReviewId]);
            $messages = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);
          } catch (Throwable $e3) {
            $messages = [];
          }
        }
      }
    }
  }
  // Load senior architects (show in sidebar) using users table for names
  try {
    $hasPSA = $pdo->query('SELECT 1 FROM project_senior_architects LIMIT 1');
    $hasEmp = $pdo->query('SELECT 1 FROM employees LIMIT 1');
    $hasUsers = $pdo->query('SELECT 1 FROM users LIMIT 1');
    if($hasPSA && $hasEmp){
      $sqlSA = 'SELECT psa.role, psa.assigned_at, e.employee_id, ' .
        ($hasUsers ? 'CONCAT(u.first_name, " ", u.last_name)' : 'e.employee_code') . ' AS full_name '
        . 'FROM project_senior_architects psa '
        . 'JOIN employees e ON e.employee_id = psa.employee_id '
        . ($hasUsers ? 'LEFT JOIN users u ON u.user_id = e.user_id ' : '')
        . 'WHERE psa.project_id = ? ORDER BY psa.assigned_at';
      $stSA = $pdo->prepare($sqlSA);
      $stSA->execute([$effectiveProjectId]);
      $seniorArchitects = $stSA->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  } catch(Throwable $e){ /* suppress */ }
}

include_once __DIR__ . '/../backend/core/header.php';
?>
<?php
// Helper: resolve display name for a user id using multiple sources with caching
if (!function_exists('resolve_display_name_client')) {
  function resolve_display_name_client(PDO $pdo, int $userId): string {
    static $cache = [];
    if ($userId <= 0) return 'User #0';
    if (isset($cache[$userId])) return $cache[$userId];
    $name = '';
    // Prefer username from users
    try {
      $st = $pdo->prepare("SELECT username FROM users WHERE user_id=? LIMIT 1");
      $st->execute([$userId]);
      $name = trim((string)$st->fetchColumn());
    } catch (Throwable $e) { /* ignore */ }
    if ($name === '') {
      try { $st = $pdo->prepare("SELECT username FROM users WHERE id=? LIMIT 1"); $st->execute([$userId]); $name = trim((string)$st->fetchColumn()); } catch (Throwable $e) {}
    }
    try {
      if ($name === '') {
        $st = $pdo->prepare("SELECT COALESCE(full_name, CONCAT(first_name, ' ', last_name)) AS name FROM users WHERE user_id=? LIMIT 1");
        $st->execute([$userId]);
        $name = trim((string)$st->fetchColumn());
      }
    } catch (Throwable $e) { /* ignore */ }
    if ($name === '') {
      try {
        $st = $pdo->prepare("SELECT COALESCE(full_name, CONCAT(first_name, ' ', last_name)) AS name FROM users WHERE id=? LIMIT 1");
        $st->execute([$userId]);
        $name = trim((string)$st->fetchColumn());
      } catch (Throwable $e) { /* ignore */ }
    }
    // If still not found, this id may actually be a clients.client_id; map to users via clients.user_id
    if ($name === '') {
      try {
        $st = $pdo->prepare("SELECT u.username FROM clients c JOIN users u ON u.user_id = c.user_id WHERE c.client_id = ? LIMIT 1");
        $st->execute([$userId]);
        $name = trim((string)$st->fetchColumn());
      } catch (Throwable $e) { /* ignore */ }
    }
    if ($name === '') {
      try {
        $st = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name FROM employees WHERE user_id=? LIMIT 1");
        $st->execute([$userId]);
        $name = trim((string)$st->fetchColumn());
      } catch (Throwable $e) { /* ignore */ }
    }
    if ($name === '') {
      try {
        $st = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name FROM clients WHERE user_id=? LIMIT 1");
        $st->execute([$userId]);
        $name = trim((string)$st->fetchColumn());
      } catch (Throwable $e) { /* ignore */ }
    }
    if ($name === '') { $name = 'User #'.$userId; }
    return $cache[$userId] = $name;
  }
}
?>
<main class="min-h-screen bg-gray-100 pb-14">
  <!-- Page Header -->
  <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-10">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6 mb-10">
      <div class="space-y-2">
        <div class="flex items-center gap-3">
          <span class="w-11 h-11 rounded-xl bg-blue-600/10 text-blue-600 flex items-center justify-center text-xl"><i class="fas fa-clipboard-check"></i></span>
          <div>
            <h1 class="text-3xl font-bold tracking-tight text-gray-900">Design Review Files</h1>
            <p class="text-sm text-gray-500">View design deliverables, approve or request changes, and discuss with the team.</p>
          </div>
        </div>
        <div class="flex flex-wrap gap-2 text-[11px] font-medium text-gray-500">
          <span class="px-2 py-0.5 rounded bg-white shadow-sm ring-1 ring-gray-200">Versioned Files</span>
          <span class="px-2 py-0.5 rounded bg-white shadow-sm ring-1 ring-gray-200">Approval Workflow</span>
          <span class="px-2 py-0.5 rounded bg-white shadow-sm ring-1 ring-gray-200">Central Discussion</span>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <?php if($effectiveProjectId): ?>
          <a href="review_files.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 shadow-sm"><i class="fas fa-rotate-left text-blue-600"></i><span>Clear Selection</span></a>
        <?php endif; ?>
  <a href="client/projects-client.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-blue-600 text-white hover:bg-blue-700 shadow" title="Back to Projects"><i class="fas fa-arrow-left"></i><span>Back to Projects</span></a>
      </div>
    </div>

    <!-- Project Selector -->
    <div class="mb-10 flex flex-col sm:flex-row sm:items-end gap-6">
      <form method="get" class="w-full max-w-sm space-y-2">
        <label class="block text-[11px] font-semibold tracking-wide text-gray-600 uppercase">Project</label>
        <div class="flex gap-3">
          <select name="project_id" class="flex-1 rounded-lg border border-gray-300 bg-white text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 shadow-sm" onchange="this.form.submit()">
            <option value="">-- Select Project --</option>
            <?php foreach($projects as $p): ?>
              <option value="<?php echo (int)$p['project_id']; ?>" <?php if($projectId===(int)$p['project_id']) echo 'selected'; ?>><?php echo htmlspecialchars($p['project_name'] ?: ('Project #'.$p['project_id'])); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
      <?php /* Removed Project ID display per request */ ?>
    </div>
  </div>

  <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

  <?php if($errorMsg): ?>
    <div class="mb-6 p-4 rounded bg-red-50 text-red-700 text-sm"><?php echo $errorMsg; ?></div>
  <?php endif; ?>
  <?php if(isset($_GET['done'])): ?>
    <div class="mb-6 p-4 rounded bg-green-50 text-green-700 text-sm">Action saved.</div>
  <?php endif; ?>

  <div class="grid lg:grid-cols-3 gap-8">
    <div class="lg:col-span-1 order-2 lg:order-1">
      <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm overflow-hidden flex flex-col h-[600px]">
        <div class="px-5 py-4 border-b bg-gray-50/60 flex items-center justify-between">
          <h2 class="text-sm font-semibold text-gray-700 tracking-wide flex items-center gap-2"><i class="fas fa-layer-group text-blue-600"></i><span>Review Files</span></h2>
          <?php if($files): ?><span class="text-[10px] px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 font-medium"><?php echo count($files); ?> total</span><?php endif; ?>
        </div>
        <ul class="flex-1 overflow-auto divide-y">
          <?php if(!$files): ?>
            <li class="p-5 text-[11px] text-gray-500">No review files yet.</li>
          <?php else: foreach($files as $f): $active = $effectiveReviewId===(int)$f['id']; $badge = match($f['review_status']) { 'approved'=>'bg-green-100 text-green-700 ring-green-200', 'changes_requested'=>'bg-red-100 text-red-700 ring-red-200', default=>'bg-amber-100 text-amber-700 ring-amber-200'}; $ext=strtolower(pathinfo($f['file_path']??'',PATHINFO_EXTENSION)); $thumbable=in_array($ext,['png','jpg','jpeg','gif','webp']); ?>
            <li class="<?php echo $active ? 'bg-blue-50/70 ring-inset ring-1 ring-blue-200' : 'hover:bg-gray-50'; ?> transition">
              <a class="block px-4 py-3" href="review_files.php?project_id=<?php echo (int)$effectiveProjectId; ?>&id=<?php echo (int)$f['id']; ?>">
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
                  <?php if($thumbable): ?>
                    <img src="<?php echo htmlspecialchars($f['file_path']); ?>" alt="thumb" class="w-10 h-10 object-cover rounded-md ring-1 ring-gray-200" loading="lazy" />
                  <?php else: ?>
                    <i class="fas fa-chevron-right text-[10px] text-gray-400"></i>
                  <?php endif; ?>
                </div>
              </a>
            </li>
          <?php endforeach; endif; ?>
        </ul>
        <div class="border-t bg-white">
          <div class="px-5 py-3 border-b bg-gray-50/60 flex items-center justify-between">
            <h3 class="text-xs font-semibold text-gray-700 tracking-wide flex items-center gap-1"><i class="fas fa-user-tie text-blue-600"></i><span>Senior Architects</span></h3>
            <?php if($seniorArchitects): ?><span class="text-[10px] px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 font-medium"><?php echo count($seniorArchitects); ?></span><?php endif; ?>
          </div>
          <?php if(!$seniorArchitects): ?>
            <div class="p-4 text-[11px] text-gray-500">None assigned.</div>
          <?php else: ?>
            <ul class="max-h-40 overflow-auto divide-y">
              <?php foreach($seniorArchitects as $sa): ?>
                <li class="px-4 py-2 flex items-center justify-between gap-3 text-[11px]">
                  <div class="min-w-0">
                    <div class="font-medium text-gray-800 truncate" title="<?php echo htmlspecialchars($sa['full_name']); ?>"><?php echo htmlspecialchars($sa['full_name']); ?></div>
                    <div class="text-[10px] text-gray-400"><?php echo htmlspecialchars($sa['role']); ?> • <?php echo htmlspecialchars(date('Y-m-d', strtotime($sa['assigned_at']))); ?></div>
                  </div>
                  <span class="px-2 py-0.5 rounded-full text-[9px] font-medium bg-blue-100 text-blue-700">Lead</span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <div class="px-4 py-3 text-center">
            <?php if($effectiveProjectId): ?>
              <a href="project_details_client.php?project_id=<?php echo (int)$effectiveProjectId; ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded bg-white ring-1 ring-gray-200 text-[11px] font-medium text-gray-600 hover:bg-gray-50"><i class="fas fa-folder-open text-blue-600"></i><span>Project Details</span></a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="lg:col-span-2 order-1 lg:order-2">
  <?php if($currentFile): ?>
        <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-7 mb-8 relative overflow-hidden">
          <div class="absolute inset-0 bg-gradient-to-br from-blue-50/30 via-transparent to-transparent pointer-events-none"></div>
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
            <div class="relative space-y-5">
              <?php
                // Support both new root-level path (SeniortoClientUploads/..) and legacy uploads/SeniortoClientUploads/.. paths
                $rawPath = $currentFile['file_path'];
                $sanitized = str_replace(['../','..\\'],'',$rawPath);
                $fsBase = __DIR__ . '/../'; // project root relative
                $candidatePaths = [];
                // If path already includes SeniortoClientUploads assume correct
                $candidatePaths[] = $sanitized;
                // Legacy to new rewrite: if starts with 'uploads/' remove it for fallback
                if (strpos($sanitized,'uploads/SeniortoClientUploads/') === 0) {
                  $candidatePaths[] = substr($sanitized, strlen('uploads/'));
                }
                // New to legacy fallback (if DB updated but files still old location)
                if (strpos($sanitized,'SeniortoClientUploads/') === 0) {
                  $candidatePaths[] = 'uploads/' . $sanitized;
                }
                $resolvedPub = null; $resolvedFs = null;
                foreach(array_unique($candidatePaths) as $rel) {
                  $tryFs = $fsBase . $rel;
                  if (is_file($tryFs)) { $resolvedFs = $tryFs; $resolvedPub = $rel; break; }
                }
                if (!$resolvedPub) { $resolvedPub = $sanitized; }
                $pubFile = htmlspecialchars($resolvedPub);
                $ok = is_file($resolvedFs ?: ($fsBase.$sanitized));
              ?>
              <div class="flex flex-wrap items-center gap-3">
                <?php
                  $ext = strtolower(pathinfo($resolvedPub ?? $sanitized, PATHINFO_EXTENSION));
                  $isImg = in_array($ext,['png','jpg','jpeg','gif','webp','bmp']);
                  $isPdf = $ext==='pdf';
                  $isText = in_array($ext,['txt','md','csv','log']);
                  if($ok && $isImg): ?>
                    <div class="rounded-lg ring-1 ring-gray-200 overflow-hidden bg-white shadow-sm">
                      <img src="<?php echo $pubFile; ?>" alt="Preview" class="block w-full h-auto max-h-[400px] object-contain" loading="lazy" />
                    </div>
                  <?php elseif($ok && $isPdf): ?>
                    <div class="rounded-lg ring-1 ring-gray-200 overflow-hidden bg-gray-50 shadow-sm h-[400px]">
                      <iframe src="<?php echo $pubFile; ?>" class="w-full h-full" title="PDF Preview" loading="lazy"></iframe>
                    </div>
                  <?php elseif($ok && $isText): ?>
                    <div class="rounded-lg ring-1 ring-gray-200 overflow-hidden bg-gray-50 shadow-sm">
                      <pre class="p-4 text-[12px] leading-snug whitespace-pre-wrap max-h-[300px] overflow-auto"><?php echo htmlspecialchars(substr(@file_get_contents($resolvedFs),0,2000)); ?><?php if(filesize($resolvedFs)>2000) echo "\n… (truncated)"; ?></pre>
                    </div>
                  <?php else: ?>
                    <div class="flex items-center gap-2 text-[12px] text-gray-600"><i class="fas fa-file"></i><span><?php echo htmlspecialchars(basename($resolvedPub ?? $sanitized)); ?></span></div>
                  <?php endif; ?>
                <div class="flex flex-wrap items-center gap-3 pt-1">
                  <a href="<?php echo $pubFile; ?>" target="_blank" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-medium shadow hover:bg-blue-700"><i class="fas fa-eye"></i><span>View</span></a>
                  <a href="<?php echo $pubFile; ?>" target="_blank" download class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white ring-1 ring-gray-200 text-gray-700 text-sm font-medium shadow-sm hover:bg-gray-50"><i class="fas fa-download"></i><span>Download</span></a>
                </div>
                <?php if(isset($_GET['debug']) && !$ok): ?><span class="text-[10px] text-red-500 font-medium">(missing)</span><?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6 mb-8">
          <h3 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-comments text-blue-600"></i><span>Discussion</span></h3>
          <div class="space-y-5 max-h-80 overflow-auto pr-2" id="messagesBox">
            <?php if(!$messages): ?>
              <div class="text-[12px] text-gray-500">No messages yet.</div>
            <?php else: foreach($messages as $m):
              // Consider legacy messages where author_user_id stored clients.client_id; treat as self as well
              $authorId = (int)($m['author_user_id'] ?? 0);
              $isYou = ($authorId === (int)$userId) || ($authorId === (int)$clientId);
              $dispName = resolve_display_name_client($pdo, $authorId);
            ?>
              <div class="text-[12px] group">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-semibold text-gray-700"><?php echo $isYou ? 'You' : htmlspecialchars($dispName); ?></span>
                  <span class="text-[10px] inline-flex items-center gap-1 px-2 py-0.5 rounded-full <?php echo $isYou ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-500'; ?> font-medium">
                    <i class="fas fa-user-tag"></i><?php echo htmlspecialchars($m['author_role']); ?> • <?php echo htmlspecialchars($m['action']); ?>
                  </span>
                  <span class="text-[10px] text-gray-400"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($m['created_at']))); ?></span>
                </div>
                <div class="mt-1 <?php echo $isYou ? 'bg-blue-50 group-hover:bg-blue-100 border-blue-100' : 'bg-gray-50 group-hover:bg-gray-100 border-gray-100'; ?> transition rounded-md p-3 text-gray-700 leading-snug border">
                  <?php echo nl2br(htmlspecialchars($m['message'])); ?>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6">
          <h3 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-pen-to-square text-blue-600"></i><span>Add Feedback</span></h3>
          <form method="post" class="space-y-5">
            <input type="hidden" name="project_id" value="<?php echo (int)$effectiveProjectId; ?>">
            <input type="hidden" name="review_id" value="<?php echo (int)$effectiveReviewId; ?>">
            <div class="grid md:grid-cols-2 gap-5">
              <div>
                <label class="block text-[11px] font-medium text-gray-600 mb-1 uppercase tracking-wide">Action</label>
                <select name="action_type" class="w-full rounded-lg border border-gray-300 bg-white text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                  <option value="comment">Comment</option>
                  <option value="request_changes">Request Changes</option>
                  <option value="approve">Approve</option>
                </select>
              </div>
              <div class="text-[11px] text-gray-500 flex items-end">Select an action then provide your message or approval note.</div>
            </div>
            <div>
              <label class="block text-[11px] font-medium text-gray-600 mb-1 uppercase tracking-wide">Message</label>
              <textarea name="message" rows="5" class="w-full rounded-lg border border-gray-300 bg-white text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Describe requested changes or approval comments" required></textarea>
            </div>
            <div class="flex flex-wrap gap-4">
              <button class="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-semibold shadow hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"><i class="fas fa-paper-plane"></i><span>Submit Feedback</span></button>
              <a href="review_files.php?project_id=<?php echo (int)$effectiveProjectId; ?>&id=<?php echo (int)$effectiveReviewId; ?>" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white ring-1 ring-gray-200 text-gray-700 text-sm font-medium hover:bg-gray-50"><i class="fas fa-rotate-right"></i><span>Reset</span></a>
            </div>
          </form>
        </div>
      <?php elseif($projectId): ?>
        <div class="p-10 rounded-xl border-2 border-dashed border-gray-300 bg-white text-center text-sm text-gray-500 flex flex-col items-center gap-3">
          <i class="fas fa-hand-pointer text-2xl text-gray-400"></i>
          <span>Select a file from the list to view details & feedback tools.</span>
        </div>
      <?php else: ?>
        <div class="p-10 rounded-xl border-2 border-dashed border-gray-300 bg-white text-center text-sm text-gray-500 flex flex-col items-center gap-3">
          <i class="fas fa-folder-open text-2xl text-gray-400"></i>
          <span>Choose a project above to view its design review files.</span>
        </div>
      <?php endif; ?>
    </div>
  </div>
  </div>
</main>
<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>

<?php
// Senior Architect view of PM uploads
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../../login.php'); exit; }
$position = strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? ''))));
if (($_SESSION['user_type'] ?? '') !== 'employee' || $position !== 'senior_architect') { header('Location: ../../index.php'); exit; }
require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Ensure table exists so querying doesn't silently fail
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
} catch (Throwable $e) { /* ignore */ }

// Ensure comments table for PM<->Senior discussion exists
try {
  $db->exec("CREATE TABLE IF NOT EXISTS pm_senior_file_comments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_id INT UNSIGNED NOT NULL,
    author_employee_id INT NULL,
    author_role VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_file (file_id),
    INDEX idx_created (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (Throwable $e) { /* ignore */ }

// Helper: authorization for a given file_id (current senior must be assigned to the file's project)
function af_sa_authorized_for_file(PDO $db, int $fileId, int $employeeId): bool {
  try {
    // Fetch file row first (include uploader)
    $fp = $db->prepare('SELECT project_id, uploaded_by_employee_id FROM pm_senior_files WHERE id=? LIMIT 1');
    $fp->execute([$fileId]);
    $fr = $fp->fetch(PDO::FETCH_ASSOC);
    if (!$fr) return false;
    $projId = (int)$fr['project_id'];
    $uploaderEmp = (int)($fr['uploaded_by_employee_id'] ?? 0);
    // Uploader can always view/discuss
    if ($uploaderEmp && $uploaderEmp === $employeeId) return true;

    // Detect projects PK
    $projCols = [];
    try { foreach($db->query('SHOW COLUMNS FROM projects') as $c){ $projCols[$c['Field']] = true; } } catch (Throwable $e) {}
    $PROJECTS_PK = isset($projCols['project_id']) ? 'project_id' : (isset($projCols['id']) ? 'id' : 'project_id');

    // Mapping table project_senior_architects
    $hasPSA = false;
    try { $db->query('SELECT 1 FROM project_senior_architects LIMIT 1'); $hasPSA = true; } catch (Throwable $e) { $hasPSA = false; }
    if ($hasPSA) {
      $chk = $db->prepare('SELECT 1 FROM project_senior_architects WHERE project_id=? AND employee_id=? LIMIT 1');
      $chk->execute([$projId, $employeeId]);
      if ($chk->fetchColumn()) return true;
    }

    // Fallback senior architect columns
    $pcols = [];
    try { foreach($db->query('SHOW COLUMNS FROM projects') as $c){ $pcols[$c['Field']] = true; } } catch (Throwable $e) {}
    $candidateCols = ['senior_architect_id','sa_id','assigned_to','senior_architect_employee_id'];
    foreach ($candidateCols as $cName) {
      if (isset($pcols[$cName])) {
        $chk = $db->prepare('SELECT 1 FROM projects WHERE ' . $PROJECTS_PK . ' = ? AND ' . $cName . ' = ? LIMIT 1');
        $chk->execute([$projId, $employeeId]);
        if ($chk->fetchColumn()) return true;
      }
    }

    // project_users membership (role-based) via user_id mapping
    $userId = 0; // derive user_id from employees table
    try {
      $es = $db->prepare('SELECT user_id FROM employees WHERE employee_id=? LIMIT 1');
      $es->execute([$employeeId]);
      $er = $es->fetch(PDO::FETCH_ASSOC); $userId = $er ? (int)$er['user_id'] : 0;
    } catch (Throwable $e) {}
    if ($userId) {
      // Ensure table exists
      try {
        $db->query("SELECT 1 FROM project_users LIMIT 1");
        $pu = $db->prepare("SELECT 1 FROM project_users WHERE project_id=? AND user_id=? AND (role_in_project LIKE '%Architect%' OR role_in_project LIKE '%Senior%' OR role_in_project LIKE '%Manager%') LIMIT 1");
        $pu->execute([$projId, $userId]);
        if ($pu->fetchColumn()) return true;
      } catch (Throwable $e) { /* ignore */ }
    }
  } catch (Throwable $e) { return false; }
  return false;
}

// Resolve current employee early for AJAX handlers and page use
$userId = (int)($_SESSION['user_id'] ?? 0);
$employeeId = 0;
try {
  $es = $db->prepare('SELECT employee_id FROM employees WHERE user_id=? LIMIT 1');
  $es->execute([$userId]);
  $er = $es->fetch(PDO::FETCH_ASSOC);
  $employeeId = $er ? (int)$er['employee_id'] : 0;
} catch (Throwable $e) {}

// Comments feature removed: no AJAX endpoints


$filterStatus = $_GET['status'] ?? '';
$filterPhase = $_GET['phase'] ?? '';
$validStatuses = ['pending','forwarded','reviewed','revisions_requested'];
if ($filterStatus && !in_array($filterStatus,$validStatuses)) { $filterStatus=''; }

// Build select columns dynamically
$cols = [];
try { $cr = $db->query("SHOW COLUMNS FROM pm_senior_files"); while ($c=$cr->fetch(PDO::FETCH_ASSOC)) { $cols[$c['Field']] = true; } } catch (Throwable $e) {}
$hasStatus = isset($cols['status']);
$hasComment = isset($cols['senior_comment']);
$hasReviewedAt = isset($cols['reviewed_at']);
$hasReviewer = isset($cols['reviewed_by_employee_id']);

$select = ['f.id','f.project_id','f.design_phase','f.original_name','f.stored_name','f.relative_path','f.mime_type','f.size','f.note','f.uploaded_at'];
if ($hasStatus) $select[] = 'f.status';
if ($hasComment) $select[] = 'f.senior_comment';
if ($hasReviewedAt) $select[] = 'f.reviewed_at';
if ($hasReviewer) $select[] = 'f.reviewed_by_employee_id';
// Detect projects PK and name column dynamically
$projCols = [];
try { foreach($db->query('SHOW COLUMNS FROM projects') as $c){ $projCols[$c['Field']] = true; } } catch (Throwable $e) {}
$PROJECTS_PK = isset($projCols['project_id']) ? 'project_id' : (isset($projCols['id']) ? 'id' : 'project_id');
$PROJECT_NAME_COL = isset($projCols['project_name']) ? 'project_name' : (isset($projCols['name']) ? 'name' : (isset($projCols['title']) ? 'title' : null));
if ($PROJECT_NAME_COL) { $select[] = 'p.' . $PROJECT_NAME_COL . ' AS project_name'; }

$where = [];
$params = [];
// Senior architect sees all projects or (optionally filter by association if you later add that relation)
if ($filterStatus && $hasStatus) { $where[] = 'f.status = ?'; $params[] = $filterStatus; }
if ($filterPhase) { $where[] = 'f.design_phase = ?'; $params[] = $filterPhase; }

// Limit visibility: only show uploads for projects assigned to this senior architect
// Detect project_senior_architects mapping table first; fallback to projects.senior_architect_id/assigned_to/sa_id if present
$hasPSA = false; $projSaCol = null;
try { $db->query('SELECT 1 FROM project_senior_architects LIMIT 1'); $hasPSA = true; } catch (Throwable $e) { $hasPSA = false; }
if (!$hasPSA) {
  // check projects columns for a direct senior architect assignment column
  try {
    $pcols = [];
    foreach($db->query('SHOW COLUMNS FROM projects') as $c){ $pcols[$c['Field']] = true; }
    if (isset($pcols['senior_architect_id'])) $projSaCol='senior_architect_id';
    elseif (isset($pcols['sa_id'])) $projSaCol='sa_id';
    elseif (isset($pcols['assigned_to'])) $projSaCol='assigned_to';
  } catch (Throwable $e) {}
}

$sql = 'SELECT '.implode(',', $select).' FROM pm_senior_files f JOIN projects p ON p.' . $PROJECTS_PK . ' = f.project_id';
if ($hasPSA) {
  $sql .= ' JOIN project_senior_architects psa ON psa.project_id = f.project_id';
  $where[] = 'psa.employee_id = ?';
  $params[] = $employeeId;
} elseif ($projSaCol) {
  $where[] = 'p.' . $projSaCol . ' = ?';
  $params[] = $employeeId;
} else {
  // No mapping table or column found; to honor "only assigned", show none by forcing false predicate
  $where[] = '1=0';
}
// Always exclude archived / deleted projects if those columns exist
try {
  $projCols=[]; foreach($db->query('SHOW COLUMNS FROM projects') as $c){ $projCols[$c['Field']]=true; }
  $autoFilters=[];
  if (isset($projCols['is_archived'])) { $autoFilters[]='p.is_archived=0'; }
  if (isset($projCols['is_deleted'])) { $autoFilters[]='(p.is_deleted=0 OR p.is_deleted IS NULL)'; }
  if ($autoFilters) { $where[] = implode(' AND ', $autoFilters); }
} catch(Throwable $e){}
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' ORDER BY f.uploaded_at DESC LIMIT 200';

$rows = [];
try { $st = $db->prepare($sql); $st->execute($params); $rows = $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

// Handle review action (approve / request revisions)
$actionMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_action'], $_POST['file_id']) && $hasStatus) {
  $fid = (int)$_POST['file_id'];
  $act = $_POST['review_action'];
  $comment = trim($_POST['senior_comment'] ?? '');
  if (!in_array($act,['reviewed','revisions_requested'])) { $actionMsg='Invalid action'; }
  else {
    // Require comments when requesting revisions
    if ($act === 'revisions_requested' && $comment === '') {
      $actionMsg = 'Revision comments are required when requesting revisions.';
    } else {
      try {
      // Authorization guard: ensure this senior architect is assigned to the file's project
      $fp = $db->prepare('SELECT project_id FROM pm_senior_files WHERE id=? LIMIT 1');
      $fp->execute([$fid]);
      $fr = $fp->fetch(PDO::FETCH_ASSOC);
      if (!$fr) { throw new Exception('File not found'); }
      $projId = (int)$fr['project_id'];

      $authorized = false;
      if ($hasPSA) {
        $chk = $db->prepare('SELECT 1 FROM project_senior_architects WHERE project_id=? AND employee_id=? LIMIT 1');
        $chk->execute([$projId, $employeeId]);
        $authorized = (bool)$chk->fetchColumn();
      } elseif ($projSaCol ?? null) {
        $chk = $db->prepare('SELECT 1 FROM projects WHERE ' . $PROJECTS_PK . ' = ? AND ' . $projSaCol . ' = ? LIMIT 1');
        $chk->execute([$projId, $employeeId]);
        $authorized = (bool)$chk->fetchColumn();
      }

      if (!$authorized) {
        $actionMsg = 'Not authorized to review this file (not assigned to the project).';
      } else {
        $upd = $db->prepare('UPDATE pm_senior_files SET status=?, senior_comment=?, reviewed_by_employee_id=?, reviewed_at=NOW() WHERE id=?');
        $upd->execute([$act, ($comment!==''?$comment:null), $employeeId, $fid]);
        header('Location: pm_uploads.php?updated=1');
        exit;
      }
      } catch (Throwable $e) { $actionMsg = $e->getMessage(); }
    }
  }
}

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
  <div class="max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold mb-6">PM Uploads</h1>
    <?php if (isset($_GET['updated'])): ?><div class="mb-4 p-3 bg-green-50 text-green-700 ring-1 ring-green-200 rounded">Status updated.</div><?php endif; ?>
    <?php if ($actionMsg): ?><div class="mb-4 p-3 bg-red-50 text-red-700 ring-1 ring-red-200 rounded"><?php echo htmlspecialchars($actionMsg); ?></div><?php endif; ?>

    <form method="get" class="mb-5 flex flex-wrap gap-3 items-end text-sm">
      <div>
        <label class="block text-xs font-medium mb-1">Status</label>
        <select name="status" class="border rounded px-2 py-1">
          <option value="">All</option>
          <?php foreach ($validStatuses as $vs): ?>
            <option value="<?php echo htmlspecialchars($vs); ?>" <?php if ($filterStatus===$vs) echo 'selected'; ?>><?php echo htmlspecialchars(str_replace('_',' ',$vs)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium mb-1">Phase</label>
        <input type="text" name="phase" value="<?php echo htmlspecialchars($filterPhase); ?>" class="border rounded px-2 py-1" placeholder="Exact phase text">
      </div>
      <div>
        <button class="px-4 py-2 bg-slate-700 hover:bg-slate-800 text-white rounded">Filter</button>
      </div>
    </form>

  <div class="overflow-x-auto bg-white rounded-xl shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-100 text-slate-600 uppercase text-[11px] tracking-wide">
          <tr>
            <th class="py-2 px-3 text-left">File</th>
            <th class="py-2 px-3 text-left">Project</th>
            <th class="py-2 px-3 text-left">Phase</th>
            <th class="py-2 px-3 text-left">Size</th>
            <th class="py-2 px-3 text-left">Uploaded</th>
            <?php if ($hasStatus): ?><th class="py-2 px-3 text-left">Status</th><?php endif; ?>
            <th class="py-2 px-3 text-left">Note</th>
            <?php if ($hasComment): ?><th class="py-2 px-3 text-left">Sr Comment</th><?php endif; ?>
            <th class="py-2 px-3 text-left">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="p-4 text-slate-500">No uploads found.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <?php
            $status = $r['status'] ?? null;
            $statusColor = 'bg-slate-200 text-slate-700';
            if ($status === 'pending') $statusColor='bg-amber-100 text-amber-700';
            elseif ($status==='forwarded') $statusColor='bg-sky-100 text-sky-700';
            elseif ($status==='reviewed') $statusColor='bg-green-100 text-green-700';
            elseif ($status==='revisions_requested') $statusColor='bg-red-100 text-red-700';
            $fileUrl = '/ArchiFlow/' . ($r['relative_path'] ?? ('PMuploads/' . $r['stored_name']));
          ?>
          <tr class="hover:bg-slate-50">
            <td class="py-2 px-3">
              <div class="font-medium text-slate-900 max-w-[180px] truncate" title="<?php echo htmlspecialchars($r['original_name']); ?>"><?php echo htmlspecialchars($r['original_name']); ?></div>
              <div class="text-[10px] text-slate-500 font-mono">#<?php echo (int)$r['id']; ?></div>
            </td>
            <td class="py-2 px-3 text-slate-700 max-w-[150px] truncate" title="<?php echo htmlspecialchars($r['project_name']); ?>"><?php echo htmlspecialchars($r['project_name']); ?></td>
            <td class="py-2 px-3 text-slate-700"><?php echo htmlspecialchars($r['design_phase'] ?? ''); ?></td>
            <td class="py-2 px-3 text-slate-600"><?php echo isset($r['size'])?number_format(((int)$r['size'])/1024,1):'0'; ?> KB</td>
            <td class="py-2 px-3 text-slate-600"><?php echo htmlspecialchars(date('M j, Y H:i', strtotime($r['uploaded_at']))); ?></td>
            <?php if ($hasStatus): ?><td class="py-2 px-3"><span class="px-2 py-0.5 rounded-full text-[10px] <?php echo $statusColor; ?> capitalize"><?php echo htmlspecialchars(str_replace('_',' ',$status)); ?></span></td><?php endif; ?>
            <td class="py-2 px-3 text-slate-600 max-w-[220px] truncate" title="<?php echo htmlspecialchars($r['note'] ?? ''); ?>"><?php echo htmlspecialchars($r['note'] ?? ''); ?></td>
            <?php if ($hasComment): ?><td class="py-2 px-3 text-slate-600 max-w-[220px] truncate" title="<?php echo htmlspecialchars($r['senior_comment'] ?? ''); ?>"><?php echo htmlspecialchars($r['senior_comment'] ?? ''); ?></td><?php endif; ?>
            <td class="py-2 px-3">
              <div class="flex items-center gap-2">
                <button type="button"
                        class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700"
                        onclick="openViewModal(this)"
                        data-file-url="<?php echo htmlspecialchars($fileUrl); ?>"
                        data-file-name="<?php echo htmlspecialchars($r['original_name']); ?>"
                        data-project-name="<?php echo htmlspecialchars($r['project_name'] ?? ''); ?>"
                        data-phase="<?php echo htmlspecialchars($r['design_phase'] ?? ''); ?>"
                        data-size-bytes="<?php echo isset($r['size']) ? (int)$r['size'] : 0; ?>"
                        data-size-human="<?php echo isset($r['size'])?number_format(((int)$r['size'])/1024,1):'0'; ?> KB"
                        data-uploaded="<?php echo htmlspecialchars(date('M j, Y H:i', strtotime($r['uploaded_at']))); ?>"
                        data-status="<?php echo htmlspecialchars(str_replace('_',' ', (string)$status)); ?>"
                        data-note="<?php echo htmlspecialchars($r['note'] ?? ''); ?>"
                        data-senior-comment="<?php echo htmlspecialchars($r['senior_comment'] ?? ''); ?>"
                        data-mime="<?php echo htmlspecialchars($r['mime_type'] ?? ''); ?>"
                        data-id="<?php echo (int)$r['id']; ?>">
                  View
                </button>
                <a href="<?php echo htmlspecialchars($fileUrl); ?>" download class="px-2 py-1 bg-green-600 text-white rounded text-xs hover:bg-green-700">Download</a>
                <?php if ($hasStatus && in_array(($r['status'] ?? ''), ['pending','forwarded','revisions_requested'])): ?>
                  <button type="button" class="px-2 py-1 bg-green-600 text-white rounded text-xs hover:bg-green-700" onclick="srReview(<?php echo (int)$r['id']; ?>,'reviewed')">Approve</button>
                  <button type="button" class="px-2 py-1 bg-amber-600 text-white rounded text-xs hover:bg-amber-700" onclick="openRevisionModal(<?php echo (int)$r['id']; ?>)">Request Revisions</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
<!-- Revision Modal -->
<div id="revisionModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
  <div class="absolute inset-0 bg-black/40" onclick="closeRevisionModal()"></div>
  <div class="relative bg-white w-full max-w-md rounded-lg shadow-lg ring-1 ring-slate-200 p-5 animate-fadeIn">
    <h2 class="text-lg font-semibold mb-2">Request Revisions</h2>
    <p class="text-sm text-slate-600 mb-4">Add an optional note to help the Project Manager understand required changes.</p>
    <form id="revisionForm" method="POST" class="space-y-4">
      <input type="hidden" name="file_id" id="rev_file_id">
      <input type="hidden" name="review_action" value="revisions_requested">
      <div>
        <label for="rev_comment" class="block text-xs font-medium text-slate-600 mb-1">Revision Comments</label>
        <textarea id="rev_comment" name="senior_comment" rows="4" class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500" placeholder="Describe the requested changes (required)" required minlength="3"></textarea>
      </div>
      <div class="flex justify-end gap-2 pt-2">
        <button type="button" onclick="closeRevisionModal()" class="px-3 py-2 text-sm rounded border border-slate-300 hover:bg-slate-100">Cancel</button>
        <button type="submit" class="px-4 py-2 text-sm rounded bg-amber-600 text-white hover:bg-amber-700">Send Request</button>
      </div>
    </form>
  </div>
</div>
<style>
@keyframes fadeIn { from { opacity:0; transform:translateY(4px);} to { opacity:1; transform:translateY(0);} }
.animate-fadeIn { animation: fadeIn .18s ease-out; }
</style>
<script>
// View modal logic
function openViewModal(btn) {
  try {
    var d = btn.dataset;
    var modal = document.getElementById('viewModal');
    var nameEl = document.getElementById('vmName');
    var projEl = document.getElementById('vmProject');
    var phaseEl = document.getElementById('vmPhase');
    var sizeEl = document.getElementById('vmSize');
    var upEl = document.getElementById('vmUploaded');
    var statusEl = document.getElementById('vmStatus');
    var noteEl = document.getElementById('vmNote');
    var scomEl = document.getElementById('vmSeniorComment');
    var imgWrap = document.getElementById('vmPreviewWrap');
    var imgEl = document.getElementById('vmPreview');
    var fallbackEl = document.getElementById('vmNoPreview');
    var openBtn = document.getElementById('vmOpen');
    var dlBtn = document.getElementById('vmDownload');

    nameEl.textContent = d.fileName || '';
    projEl.textContent = d.projectName || '—';
    phaseEl.textContent = d.phase || '—';
    sizeEl.textContent = d.sizeHuman || '—';
    upEl.textContent = d.uploaded || '—';
    statusEl.textContent = d.status || '—';
    noteEl.textContent = d.note || '—';
    scomEl.textContent = d.seniorComment || '—';

    var url = d.fileUrl || '#';
    openBtn.href = url;
    dlBtn.href = url;
    dlBtn.download = d.fileName || '';

    // comments removed

    // Determine if previewable (image)
    var mime = (d.mime || '').toLowerCase();
    var isImg = mime.indexOf('image/') === 0;
    if (!isImg) {
      // fallback: check extension
      var ext = (d.fileName || '').split('.').pop().toLowerCase();
      if (['png','jpg','jpeg','gif','webp','bmp','svg'].indexOf(ext) !== -1) isImg = true;
    }
    if (isImg) {
      imgEl.src = url;
      imgWrap.classList.remove('hidden');
      fallbackEl.classList.add('hidden');
    } else {
      imgEl.src = '';
      imgWrap.classList.add('hidden');
      fallbackEl.classList.remove('hidden');
    }
    modal.classList.remove('hidden');
  } catch (e) {
    console.error(e);
  }
}
function closeViewModal() {
  document.getElementById('viewModal').classList.add('hidden');
}
// comments removed
function srReview(id, action) {
  // Approve path (no modal)
  if (action === 'reviewed') {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="file_id" value="'+id+'">'+
                     '<input type="hidden" name="review_action" value="reviewed">';
    document.body.appendChild(form);
    form.submit();
  }
}
function openRevisionModal(id) {
  document.getElementById('rev_file_id').value = id;
  document.getElementById('rev_comment').value = '';
  document.getElementById('revisionModal').classList.remove('hidden');
  document.getElementById('rev_comment').focus();
}
function closeRevisionModal() {
  document.getElementById('revisionModal').classList.add('hidden');
}
// Enforce non-empty revision comments on submit
(function(){
  var f = document.getElementById('revisionForm');
  if (f) {
    f.addEventListener('submit', function(e){
      var t = document.getElementById('rev_comment');
      if (t && !t.value.replace(/\s+/g,' ').trim()) {
        e.preventDefault();
        try {
          t.focus();
          t.classList.add('ring-2','ring-red-500');
          setTimeout(function(){ t.classList.remove('ring-2','ring-red-500'); }, 1200);
        } catch(_) {}
        alert('Please enter revision comments before sending the request.');
      }
    });
  }
})();
</script>
<!-- View Details Modal -->
<div id="viewModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
  <div class="absolute inset-0 bg-black/40" onclick="closeViewModal()"></div>
  <div class="relative bg-white w-full max-w-5xl rounded-lg shadow-lg ring-1 ring-slate-200 p-5 animate-fadeIn">
    <div class="flex items-start justify-between mb-3">
      <h2 class="text-lg font-semibold">File Details</h2>
      <button class="text-slate-500 hover:text-slate-700" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
    </div>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div id="vmPreviewWrap" class="border rounded-lg overflow-hidden bg-slate-50 flex items-center justify-center min-h-[280px]">
        <img id="vmPreview" alt="Preview" class="max-h-[480px] w-auto object-contain" />
        <div id="vmNoPreview" class="text-slate-500 text-sm hidden p-6">
          <div class="flex flex-col items-center">
            <i class="fas fa-file text-4xl mb-2"></i>
            <div>No inline preview available for this file type.</div>
          </div>
        </div>
      </div>
      <div class="space-y-2 text-sm">
        <div><span class="text-slate-500">Name:</span> <span class="font-medium" id="vmName"></span></div>
        <div><span class="text-slate-500">Project:</span> <span class="font-medium" id="vmProject"></span></div>
        <div><span class="text-slate-500">Phase:</span> <span class="font-medium" id="vmPhase"></span></div>
        <div><span class="text-slate-500">Size:</span> <span class="font-medium" id="vmSize"></span></div>
        <div><span class="text-slate-500">Uploaded:</span> <span class="font-medium" id="vmUploaded"></span></div>
        <div><span class="text-slate-500">Status:</span> <span class="font-medium" id="vmStatus"></span></div>
        <div>
          <div class="text-slate-500">PM Note:</div>
          <div class="font-medium whitespace-pre-wrap break-words" id="vmNote"></div>
        </div>
        <div>
          <div class="text-slate-500">Senior Comment:</div>
          <div class="font-medium whitespace-pre-wrap break-words" id="vmSeniorComment"></div>
        </div>
      </div>
    </div>
    <!-- Comments Thread removed -->
    <div class="flex items-center justify-end gap-2 mt-4">
      <a id="vmOpen" href="#" target="_blank" class="px-3 py-1.5 bg-slate-600 hover:bg-slate-700 text-white rounded text-sm">Open in new tab</a>
      <a id="vmDownload" href="#" download class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded text-sm">Download</a>
      <button class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-800 rounded text-sm" onclick="closeViewModal()">Close</button>
    </div>
  </div>
  
</div>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

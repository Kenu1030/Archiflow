<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../../login.php'); exit; }
if (($_SESSION['user_type'] ?? '') !== 'employee' || strtolower((string)($_SESSION['position'] ?? '')) !== 'architect') { header('Location: ../../index.php'); exit; }
require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = (int)($_SESSION['user_id'] ?? 0);
// Resolve architect's employee_id
$empStmt = $db->prepare('SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1');
$empStmt->execute([$userId]);
$empRow = $empStmt->fetch(PDO::FETCH_ASSOC);
$employeeId = $empRow ? (int)$empRow['employee_id'] : 0;

$err = '';$ok='';
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

// Handle delete
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { $err = 'Invalid form token.'; }
  else {
  $docId = (int)($_POST['document_id'] ?? 0);
  try {
    // Ensure architect owns the project's document or uploaded it
    $chk = $db->prepare('SELECT d.document_id FROM documents d LEFT JOIN projects p ON p.project_id=d.project_id WHERE d.document_id=? AND (p.architect_id = ? OR d.uploaded_by = ?)');
    $chk->execute([$docId, $employeeId, $userId]);
    if ($chk->fetch()) {
      $del = $db->prepare('DELETE FROM documents WHERE document_id = ?');
      $del->execute([$docId]);
      $ok = 'Document deleted.';
    } else {
      $err = 'You do not have permission to delete this document.';
    }
  } catch (Throwable $ex) { $err = $ex->getMessage(); }
  }
}

// Handle upload
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'upload') {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { $err = 'Invalid form token.'; }
  else {
  $projectId = (int)($_POST['project_id'] ?? 0);
  $docType = $_POST['document_type'] ?? 'other';
  $docName = trim((string)($_POST['document_name'] ?? ''));
  try {
    // Check project belongs to architect
    $chk = $db->prepare('SELECT project_id FROM projects WHERE project_id = ? AND architect_id = ?');
    $chk->execute([$projectId, $employeeId]);
    if (!$chk->fetch()) { throw new RuntimeException('Invalid project.'); }
    if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      throw new RuntimeException('No file uploaded.');
    }
    $tmp = $_FILES['file']['tmp_name'];
    $origName = $_FILES['file']['name'] ?? 'document';
    $mime = $_FILES['file']['type'] ?? 'application/octet-stream';
    $size = (int)($_FILES['file']['size'] ?? 0);
    if ($size <= 0) { throw new RuntimeException('Empty file.'); }
    if ($size > 15 * 1024 * 1024) { throw new RuntimeException('File too large (max 15MB).'); }
    // Simple allowlist for safety
    $allowed = ['application/pdf','image/png','image/jpeg','image/jpg','image/gif','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/msword'];
    if (!in_array($mime, $allowed, true)) { throw new RuntimeException('Unsupported file type.'); }
    $data = file_get_contents($tmp);
    if ($data === false) { throw new RuntimeException('Failed to read uploaded file.'); }
    $nameToSave = $docName !== '' ? $docName : $origName;
    $ins = $db->prepare('INSERT INTO documents (project_id, document_name, document_type, document_data, file_type, uploaded_by) VALUES (?,?,?,?,?,?)');
    $ins->bindParam(1, $projectId, PDO::PARAM_INT);
    $ins->bindParam(2, $nameToSave, PDO::PARAM_STR);
    $ins->bindParam(3, $docType, PDO::PARAM_STR);
    $ins->bindParam(4, $data, PDO::PARAM_LOB);
    $ins->bindParam(5, $mime, PDO::PARAM_STR);
    $ins->bindParam(6, $userId, PDO::PARAM_INT);
    $ins->execute();
    $ok = 'Document uploaded successfully.';
  } catch (Throwable $ex) { $err = $ex->getMessage(); }
  }
}

// Load projects for dropdown (owned by this architect)
$projects = [];
try {
  $ps = $db->prepare('SELECT project_id, project_name, project_code FROM projects WHERE architect_id = ? ORDER BY created_at DESC');
  $ps->execute([$employeeId]);
  $projects = $ps->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) { $err = $err ?: $ex->getMessage(); }

// Load documents for this architect (their projects or uploaded_by)
$docs = [];
try {
  $ds = $db->prepare("SELECT d.document_id, d.document_name, d.document_type, d.file_type, d.upload_date, d.uploaded_by,
                             p.project_name, p.project_code,
                             u.first_name, u.last_name
                      FROM documents d
                      LEFT JOIN projects p ON p.project_id=d.project_id
                      LEFT JOIN users u ON u.user_id=d.uploaded_by
                      WHERE (p.architect_id = ? OR d.uploaded_by = ?) 
                      ORDER BY d.upload_date DESC, d.document_id DESC");
  $ds->execute([$employeeId, $userId]);
  $docs = $ds->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) { $err = $err ?: $ex->getMessage(); }

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
  <div class="max-w-full">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">Documents</h1>
    </div>
    <?php if ($err): ?><div class="mb-4 p-3 bg-red-50 text-red-700 ring-1 ring-red-200 rounded"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="mb-4 p-3 bg-green-50 text-green-700 ring-1 ring-green-200 rounded"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>

    <!-- Upload form -->
    <div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200 p-4 mb-6">
      <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
        <input type="hidden" name="action" value="upload" />
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" />
        <div>
          <label class="block text-sm text-gray-700 mb-1">Project</label>
          <select name="project_id" required class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500">
            <option value="">Select project</option>
            <?php foreach ($projects as $p): ?>
              <option value="<?php echo (int)$p['project_id']; ?>"><?php echo htmlspecialchars($p['project_name'] . ' (' . $p['project_code'] . ')'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-700 mb-1">Type</label>
          <select name="document_type" class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500">
            <option value="blueprint">Blueprint</option>
            <option value="contract">Contract</option>
            <option value="invoice">Invoice</option>
            <option value="design">Design</option>
            <option value="other" selected>Other</option>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-700 mb-1">Document name (optional)</label>
          <input name="document_name" type="text" placeholder="e.g., Floor Plan v2" class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 placeholder-slate-400 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm text-gray-700 mb-1">File</label>
          <input name="file" type="file" accept=".pdf,.png,.jpg,.jpeg,.gif,.doc,.docx" required class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <button type="submit" class="inline-flex items-center px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm">Upload</button>
        </div>
      </form>
    </div>

    <!-- Documents table -->
    <div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200 overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
          <tr>
            <th class="px-4 py-3 text-left">Name</th>
            <th class="px-4 py-3 text-left">Project</th>
            <th class="px-4 py-3 text-left">Type</th>
            <th class="px-4 py-3 text-left">Uploaded By</th>
            <th class="px-4 py-3 text-right">Date</th>
            <th class="px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (!$docs): ?>
            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No documents yet.</td></tr>
          <?php else: foreach ($docs as $d): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-3">
                <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($d['document_name']); ?></div>
              </td>
              <td class="px-4 py-3">
                <div class="text-sm text-gray-800"><?php echo htmlspecialchars($d['project_name'] ?? '—'); ?></div>
                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($d['project_code'] ?? ''); ?></div>
              </td>
              <td class="px-4 py-3 capitalize"><?php echo htmlspecialchars($d['document_type']); ?></td>
              <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars(trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? '')) ?: '—'); ?></td>
              <td class="px-4 py-3 text-right text-gray-600"><?php echo htmlspecialchars(date('M j, Y', strtotime($d['upload_date']))); ?></td>
              <td class="px-4 py-3 text-right space-x-2">
                <a href="/ArchiFlow/backend/file.php?doc_id=<?php echo (int)$d['document_id']; ?>" class="text-indigo-600 hover:text-indigo-800">Download</a>
                <?php if ((int)$d['uploaded_by'] === $userId): ?>
                  <form method="post" class="inline" onsubmit="return confirm('Delete this document?');">
                    <input type="hidden" name="action" value="delete" />
                    <input type="hidden" name="document_id" value="<?php echo (int)$d['document_id']; ?>" />
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" />
                    <button type="submit" class="text-red-600 hover:text-red-800">Delete</button>
                  </form>
                <?php endif; ?>
                <?php if (!empty($d['project_name'])): ?>
                  <select class="rounded-lg border-slate-300" data-reviewers-for-doc="<?php echo (int)$d['project_id']; ?>"></select>
                  <button class="px-3 py-1 rounded-lg bg-slate-900 text-white hover:bg-slate-800" onclick="requestDocReview(<?php echo (int)$d['project_id']; ?>, <?php echo (int)$d['document_id']; ?>, this)">Request Review</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
<script>
// load reviewers for each project row (documents)
document.addEventListener('DOMContentLoaded', async () => {
  const selects = Array.from(document.querySelectorAll('select[data-reviewers-for-doc]'));
  const grouped = {};
  selects.forEach(s => { const pid = s.getAttribute('data-reviewers-for-doc'); grouped[pid] = s; });
  for (const pid of Object.keys(grouped)) {
    const form = new FormData(); form.append('action','listProjectAssignments'); form.append('project_id', pid);
    const res = await fetch('backend/projects.php', { method:'POST', body: form });
    const data = await res.json().catch(()=>({}));
    const sel = grouped[pid]; sel.innerHTML = '';
    const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent='Select Senior Architect'; sel.appendChild(opt0);
    if (data.success && Array.isArray(data.data)) {
      data.data.forEach(a => {
        const o = document.createElement('option'); o.value = a.employee_id; o.textContent = `${a.first_name} ${a.last_name} (${a.role})`; sel.appendChild(o);
      });
    }
  }
});

async function requestDocReview(projectId, documentId, btn){
  const select = btn.previousElementSibling;
  const reviewerId = select && select.value ? parseInt(select.value,10) : 0;
  if (!reviewerId) { alert('Select a Senior Architect first.'); return; }
  const form = new FormData();
  form.append('action','createReview');
  form.append('project_id', String(projectId));
  form.append('document_id', String(documentId));
  form.append('reviewer_id', String(reviewerId));
  const res = await fetch('backend/reviews.php', { method:'POST', body: form });
  const data = await res.json().catch(()=>({}));
  if (data.success) { alert('Review requested'); } else { alert(data.message || 'Failed'); }
}
</script>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

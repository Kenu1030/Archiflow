<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../../login.php'); exit; }
if (($_SESSION['user_type'] ?? '') !== 'employee' || strtolower((string)($_SESSION['position'] ?? '')) !== 'architect') { header('Location: ../../index.php'); exit; }
require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = (int)($_SESSION['user_id'] ?? 0);
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
    $chk = $db->prepare("SELECT d.document_id FROM documents d LEFT JOIN projects p ON p.project_id=d.project_id WHERE d.document_id=? AND d.document_type='design' AND (p.architect_id = ? OR d.uploaded_by = ?)");
    $chk->execute([$docId, $employeeId, $userId]);
    if ($chk->fetch()) {
      $del = $db->prepare('DELETE FROM documents WHERE document_id = ?');
      $del->execute([$docId]);
      $ok = 'Design deleted.';
    } else { $err = 'You do not have permission to delete this design.'; }
  } catch (Throwable $ex) { $err = $ex->getMessage(); }
  }
}

// Handle upload (forced type=design)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'upload') {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { $err = 'Invalid form token.'; }
  else {
  $projectId = (int)($_POST['project_id'] ?? 0);
  $docName = trim((string)($_POST['document_name'] ?? ''));
  try {
    $chk = $db->prepare('SELECT project_id FROM projects WHERE project_id = ? AND architect_id = ?');
    $chk->execute([$projectId, $employeeId]);
    if (!$chk->fetch()) { throw new RuntimeException('Invalid project.'); }
    if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      throw new RuntimeException('No file uploaded.');
    }
    $tmp = $_FILES['file']['tmp_name'];
    $origName = $_FILES['file']['name'] ?? 'design';
    $mime = $_FILES['file']['type'] ?? 'application/octet-stream';
    $size = (int)($_FILES['file']['size'] ?? 0);
    if ($size <= 0) { throw new RuntimeException('Empty file.'); }
    if ($size > 25 * 1024 * 1024) { throw new RuntimeException('File too large (max 25MB).'); }
    $allowed = ['application/pdf','image/png','image/jpeg','image/jpg','image/gif'];
    if (!in_array($mime, $allowed, true)) { throw new RuntimeException('Unsupported file type.'); }
    $data = file_get_contents($tmp);
    if ($data === false) { throw new RuntimeException('Failed to read uploaded file.'); }
    $nameToSave = $docName !== '' ? $docName : $origName;
    $ins = $db->prepare("INSERT INTO documents (project_id, document_name, document_type, document_data, file_type, uploaded_by) VALUES (?,?,'design',?,?,?)");
    $ins->execute([$projectId, $nameToSave, $data, $mime, $userId]);
    $ok = 'Design uploaded successfully.';
  } catch (Throwable $ex) { $err = $ex->getMessage(); }
  }
}

// Load projects for dropdown
$projects = [];
try {
  $ps = $db->prepare('SELECT project_id, project_name, project_code FROM projects WHERE architect_id = ? ORDER BY created_at DESC');
  $ps->execute([$employeeId]);
  $projects = $ps->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) { $err = $err ?: $ex->getMessage(); }

// Load designs only
$docs = [];
try {
  $ds = $db->prepare("SELECT d.document_id, d.document_name, d.file_type, d.upload_date, d.uploaded_by,
                             p.project_name, p.project_code,
                             u.first_name, u.last_name
                      FROM documents d
                      LEFT JOIN projects p ON p.project_id=d.project_id
                      LEFT JOIN users u ON u.user_id=d.uploaded_by
                      WHERE d.document_type='design' AND (p.architect_id = ? OR d.uploaded_by = ?) 
                      ORDER BY d.upload_date DESC, d.document_id DESC");
  $ds->execute([$employeeId, $userId]);
  $docs = $ds->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) { $err = $err ?: $ex->getMessage(); }

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
  <div class="max-w-full">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">Designs</h1>
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
        <div class="md:col-span-2">
          <label class="block text-sm text-gray-700 mb-1">Design name (optional)</label>
          <input name="document_name" type="text" placeholder="e.g., Concept Board" class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 placeholder-slate-400 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm text-gray-700 mb-1">File</label>
          <input name="file" type="file" accept=".pdf,.png,.jpg,.jpeg,.gif" required class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <button type="submit" class="inline-flex items-center px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm">Upload</button>
        </div>
      </form>
    </div>

    <!-- Designs table -->
    <div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200 overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
          <tr>
            <th class="px-4 py-3 text-left">Name</th>
            <th class="px-4 py-3 text-left">Project</th>
            <th class="px-4 py-3 text-left">Uploaded By</th>
            <th class="px-4 py-3 text-right">Date</th>
            <th class="px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (!$docs): ?>
            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">No designs yet.</td></tr>
          <?php else: foreach ($docs as $d): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-3">
                <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($d['document_name']); ?></div>
              </td>
              <td class="px-4 py-3">
                <div class="text-sm text-gray-800"><?php echo htmlspecialchars($d['project_name'] ?? '—'); ?></div>
                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($d['project_code'] ?? ''); ?></div>
              </td>
              <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars(trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? '')) ?: '—'); ?></td>
              <td class="px-4 py-3 text-right text-gray-600"><?php echo htmlspecialchars(date('M j, Y', strtotime($d['upload_date']))); ?></td>
              <td class="px-4 py-3 text-right space-x-2">
                <a href="/ArchiFlow/backend/file.php?doc_id=<?php echo (int)$d['document_id']; ?>" class="text-indigo-600 hover:text-indigo-800">Download</a>
                <?php if ((int)$d['uploaded_by'] === $userId): ?>
                  <form method="post" class="inline" onsubmit="return confirm('Delete this design?');">
                    <input type="hidden" name="action" value="delete" />
                    <input type="hidden" name="document_id" value="<?php echo (int)$d['document_id']; ?>" />
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" />
                    <button type="submit" class="text-red-600 hover:text-red-800">Delete</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

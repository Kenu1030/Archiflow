<?php
// Senior Architect: Upload a client-review file to a project
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: /login.php'); exit; }
$pos = strtolower(str_replace(' ', '_', (string)($_SESSION['position'] ?? '')));
if (($_SESSION['user_type'] ?? '') !== 'employee' || $pos !== 'senior_architect') { header('Location: /index.php'); exit; }

require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$statusMsg = '';
$errorMsg = '';

// Base directory (relative folder) for senior architect -> client review uploads
// CHANGED: store directly under project root: /SeniortoClientUploads instead of /uploads/SeniortoClientUploads
// Existing records using old path remain valid (we don't migrate them; they still point to uploads/SeniortoClientUploads/...)
$CLIENT_REVIEW_DIR_NAME = 'SeniortoClientUploads';
$CLIENT_REVIEW_BASE_FS = __DIR__ . '/../../' . $CLIENT_REVIEW_DIR_NAME; // filesystem path
$CLIENT_REVIEW_BASE_PUBLIC = $CLIENT_REVIEW_DIR_NAME; // relative public path
if (!is_dir($CLIENT_REVIEW_BASE_FS)) { @mkdir($CLIENT_REVIEW_BASE_FS, 0775, true); }

// Confirm dedicated client review table exists; if missing, attempt auto-create (one-time convenience)
try {
  $db->query("SELECT 1 FROM project_client_review_files LIMIT 1");
} catch (Throwable $e) {
  try {
    $createSql = <<<SQL
CREATE TABLE IF NOT EXISTS project_client_review_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  version INT NOT NULL DEFAULT 1,
  review_status ENUM('pending','approved','changes_requested') NOT NULL DEFAULT 'pending',
  uploaded_by INT NOT NULL,
  reviewer_user_id INT NULL,
  client_feedback TEXT NULL,
  internal_notes TEXT NULL,
  hash CHAR(40) NULL,
  group_token CHAR(16) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pcrf_project (project_id),
  INDEX idx_pcrf_project_status (project_id, review_status),
  INDEX idx_pcrf_group (project_id, group_token),
  INDEX idx_pcrf_project_version (project_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL;
    $db->exec($createSql);
    // Re-test
    $db->query("SELECT 1 FROM project_client_review_files LIMIT 1");
  } catch (Throwable $ce) {
    $errorMsg = 'Client review table missing and auto-create failed. Run migration 20251002_create_project_client_review_files.sql manually. Error: ' . htmlspecialchars($ce->getMessage());
  }
}

// Fetch overseen projects (limit to active for choice)
$projects = [];
if (!$errorMsg) {
  try {
    $stmt = $db->prepare("SELECT p.project_id, p.project_name, p.status FROM projects p JOIN project_senior_architects psa ON psa.project_id=p.project_id WHERE psa.employee_id = (SELECT employee_id FROM employees WHERE user_id=? LIMIT 1) ORDER BY p.project_name");
    $stmt->execute([$currentUserId]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $errorMsg = 'Load projects failed: ' . htmlspecialchars($e->getMessage()); }
}

$selectedProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$selectedProject = null;
if ($selectedProjectId) {
  try {
    $st = $db->prepare("SELECT * FROM projects WHERE project_id=? LIMIT 1");
    $st->execute([$selectedProjectId]);
    $selectedProject = $st->fetch(PDO::FETCH_ASSOC);
    if (!$selectedProject) { $errorMsg = 'Project not found'; }
  } catch (Throwable $e) { $errorMsg = 'Project lookup failed: ' . htmlspecialchars($e->getMessage()); }
}

// Handle upload
if (!$errorMsg && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_client_file'])) {
  $projId = (int)($_POST['project_id'] ?? 0);
  if ($projId <= 0) {
    $errorMsg = 'Select a project.';
  } else {
    // Validate project belongs to senior architect
    $chk = $db->prepare("SELECT 1 FROM project_senior_architects psa WHERE psa.project_id=? AND psa.employee_id=(SELECT employee_id FROM employees WHERE user_id=? LIMIT 1) LIMIT 1");
    $chk->execute([$projId, $currentUserId]);
    if (!$chk->fetchColumn()) {
      $errorMsg = 'You are not assigned to this project.';
    } else {
      // Prevent upload if project completed
      $pstatus = $db->prepare('SELECT status FROM projects WHERE project_id=?');
      $pstatus->execute([$projId]);
      $ps = strtolower((string)$pstatus->fetchColumn());
      if ($ps === 'completed') {
        $errorMsg = 'Project is completed; uploads disabled.';
      }
    }
  }
  if (!$errorMsg) {
    if (!isset($_FILES['client_file']) || ($_FILES['client_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $errorMsg = 'No file uploaded or upload error.';
    } else {
      $file = $_FILES['client_file'];
      $allowed = ['pdf','doc','docx','xls','xlsx','png','jpg','jpeg','zip'];
      $origName = $file['name'];
      $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
      if (!in_array($ext, $allowed, true)) {
        $errorMsg = 'File type not allowed.';
      } elseif ($file['size'] > 20 * 1024 * 1024) {
        $errorMsg = 'File exceeds 20MB limit.';
      } else {
  // Ensure project subfolder inside dedicated SeniortoClientUploads directory
  $baseDir = $CLIENT_REVIEW_BASE_FS . '/' . $projId;
  if (!is_dir($baseDir)) { @mkdir($baseDir, 0775, true); }
        $token = bin2hex(random_bytes(6));
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
        $storedName = $token . '_' . $safeName;
        $dest = $baseDir . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
          $errorMsg = 'Failed to move uploaded file.';
        } else {
          // Determine next version based on new table (grouped by original_name)
          $verStmt = $db->prepare("SELECT MAX(version) FROM project_client_review_files WHERE project_id=? AND original_name=?");
          $verStmt->execute([$projId, $origName]);
          $nextVersion = ((int)$verStmt->fetchColumn()) + 1;
          $relPath = $CLIENT_REVIEW_BASE_PUBLIC . '/' . $projId . '/' . $storedName;
          $groupToken = substr(sha1($projId . '|' . $origName), 0, 16);
          $hash = sha1($projId . '|' . $relPath);
          $ins = $db->prepare("INSERT INTO project_client_review_files (project_id, original_name, stored_name, file_path, version, review_status, uploaded_by, group_token, hash) VALUES (?,?,?,?,?,'pending',?,?,?)");
          $ins->execute([$projId, $origName, $storedName, $relPath, $nextVersion, $currentUserId, $groupToken, $hash]);
          $statusMsg = 'File uploaded for client review (v' . $nextVersion . ').';
          // Future: create notification entry for client(s)
          header('Location: client_review_upload.php?project_id=' . $projId . '&uploaded=1');
          exit;
        }
      }
    }
  }
}

// Fetch existing client-review files for the selected project
$clientFiles = [];
if ($selectedProjectId && !$errorMsg) {
  try {
    $stmtF = $db->prepare("SELECT id, original_name, stored_name, file_path, created_at, updated_at, review_status, version, uploaded_by FROM project_client_review_files WHERE project_id=? ORDER BY created_at DESC");
    $stmtF->execute([$selectedProjectId]);
    $clientFiles = $stmtF->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $errorMsg = 'Could not load files: ' . htmlspecialchars($e->getMessage()); }
}

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-100 pb-12">
  <!-- Page Heading -->
  <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6 mb-10">
      <div class="space-y-2">
        <div class="flex items-center gap-3">
          <span class="w-11 h-11 rounded-xl bg-blue-600/10 text-blue-600 flex items-center justify-center text-xl"><i class="fas fa-file-upload"></i></span>
          <div>
            <h1 class="text-3xl font-bold tracking-tight text-gray-900">Client Review Uploads</h1>
            <p class="text-sm text-gray-500">Share deliverables with the client, track versions & review status.</p>
          </div>
        </div>
        <div class="flex flex-wrap gap-2 text-[11px] font-medium text-gray-500">
          <span class="px-2 py-0.5 rounded bg-white shadow-sm ring-1 ring-gray-200">Versioned</span>
          <span class="px-2 py-0.5 rounded bg-white shadow-sm ring-1 ring-gray-200">Pending → Approved / Changes</span>
          <span class="px-2 py-0.5 rounded bg-white shadow-sm ring-1 ring-gray-200">Secure Folder</span>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <a href="client_review_discuss.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 shadow-sm"><i class="fas fa-comments text-blue-600"></i><span>Discussion</span></a>
        <a href="projects.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-blue-600 text-white hover:bg-blue-700 shadow"><i class="fas fa-arrow-left"></i><span>Back to Projects</span></a>
      </div>
    </div>

    <?php if ($errorMsg): ?>
      <div class="mb-6 p-4 rounded-lg ring-1 ring-red-200 bg-red-50 text-red-700 text-sm"><?php echo $errorMsg; ?></div>
    <?php elseif (isset($_GET['uploaded'])): ?>
      <div class="mb-6 p-4 rounded-lg ring-1 ring-green-200 bg-green-50 text-green-700 text-sm">File uploaded successfully and set to Pending.</div>
    <?php elseif ($statusMsg): ?>
      <div class="mb-6 p-4 rounded-lg ring-1 ring-slate-200 bg-slate-50 text-slate-700 text-sm"><?php echo htmlspecialchars($statusMsg); ?></div>
    <?php endif; ?>

    <form method="get" class="mb-8">
      <label class="block text-xs font-semibold tracking-wide text-gray-600 mb-2 uppercase">Select Project</label>
      <div class="flex gap-4 items-stretch flex-col sm:flex-row">
        <select name="project_id" class="w-full rounded-lg border border-gray-300 bg-white text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 shadow-sm">
          <option value="">-- choose project --</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?php echo (int)$p['project_id']; ?>" <?php echo $selectedProjectId===(int)$p['project_id']?'selected':''; ?>><?php echo htmlspecialchars(($p['project_name'] ?? 'Project #'.$p['project_id']) . ' [' . ($p['status'] ?? '') . ']'); ?></option>
          <?php endforeach; ?>
        </select>
        <button class="inline-flex justify-center items-center gap-2 px-6 py-3 rounded-lg bg-blue-600 text-white text-sm font-semibold tracking-wide shadow hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
          <i class="fas fa-arrow-right"></i>
          <span>Load</span>
        </button>
      </div>
    </form>

    <?php if ($selectedProject && !$errorMsg): ?>
      <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6 mb-10 relative overflow-hidden">
        <div class="absolute inset-0 pointer-events-none bg-gradient-to-br from-blue-50/40 via-transparent to-transparent"></div>
        <h2 class="relative text-lg font-semibold text-gray-900 flex items-center gap-2 mb-4"><i class="fas fa-cloud-upload-alt text-blue-600"></i><span>Upload New File</span></h2>
        <?php if (strtolower($selectedProject['status'] ?? '') === 'completed'): ?>
          <div class="p-3 rounded-lg bg-amber-100 text-amber-800 text-sm font-medium flex items-center gap-2"><i class="fas fa-lock"></i><span>Project completed. Uploads are locked.</span></div>
        <?php else: ?>
          <form method="post" enctype="multipart/form-data" class="relative space-y-4" id="clientUploadForm">
            <input type="hidden" name="project_id" value="<?php echo (int)$selectedProjectId; ?>">
            <input type="hidden" name="upload_client_file" value="1">
            <input type="file" name="client_file" id="client_file_input" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.zip" class="hidden" required>
            <div class="flex flex-wrap items-center gap-4">
              <button type="button" id="triggerClientUpload" class="inline-flex items-center gap-2 px-7 py-3 rounded-lg bg-blue-600 text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 shadow text-sm font-semibold tracking-wide transition">
                <i class="fas fa-file-arrow-up"></i>
                <span>Select & Upload</span>
              </button>
              <div id="selectedFileInfo" class="text-[11px] leading-relaxed text-gray-500 max-w-md">Allowed: <span class="font-medium">PDF, DOC, DOCX, XLS, XLSX, PNG, JPG, ZIP</span> up to <span class="font-medium">20MB</span>. Upload starts instantly after choosing a file.</div>
            </div>
            <noscript>
              <div class="text-xs text-red-600">JavaScript is required for auto upload. Enable it and reload.</div>
            </noscript>
          </form>
          <script>
            (function(){
              const btn = document.getElementById('triggerClientUpload');
              const input = document.getElementById('client_file_input');
              const info = document.getElementById('selectedFileInfo');
              if(btn && input){
                btn.addEventListener('click', ()=> input.click());
                input.addEventListener('change', ()=>{
                  if(input.files && input.files.length){
                    const f = input.files[0];
                    info.textContent = 'Uploading ' + f.name + ' ...';
                    // Submit the form
                    input.form.submit();
                  }
                });
              }
            })();
          </script>
        <?php endif; ?>
      </div>

      <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2 mb-5"><i class="fas fa-folder-open text-blue-600"></i><span>Files Sent to Client</span></h2>
        <?php if (!$clientFiles): ?>
          <div class="text-sm text-gray-500 flex items-center gap-2"><i class="fas fa-circle-info text-gray-400"></i><span>No client review files yet.</span></div>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm align-middle">
              <thead class="bg-gray-50">
                <tr class="text-left text-[11px] uppercase tracking-wider text-gray-600">
                  <th class="py-3 pr-3 font-semibold">Original Name</th>
                  <th class="py-3 px-3 font-semibold">Version</th>
                  <th class="py-3 px-3 font-semibold">Status</th>
                  <th class="py-3 px-3 font-semibold">Uploaded</th>
                  <th class="py-3 px-3 font-semibold">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <?php foreach ($clientFiles as $f): $cls = match($f['review_status']) { 'approved' => 'bg-green-100 text-green-700 ring-green-200', 'changes_requested' => 'bg-red-100 text-red-700 ring-red-200', default => 'bg-yellow-100 text-yellow-700 ring-yellow-200'}; ?>
                  <tr class="hover:bg-gray-50/60">
                    <td class="py-3 pr-3 text-gray-900 font-medium"><?php echo htmlspecialchars($f['original_name']); ?></td>
                    <td class="py-3 px-3 font-mono text-[12px] text-gray-600">v<?php echo (int)$f['version']; ?></td>
                    <td class="py-3 px-3"><span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold ring-1 <?php echo $cls; ?>"><?php echo htmlspecialchars(ucwords(str_replace('_',' ', $f['review_status']))); ?></span></td>
                    <td class="py-3 px-3 text-gray-500 text-xs">
                      <div class="flex flex-col leading-tight">
                        <span><?php echo htmlspecialchars(date('Y-m-d', strtotime($f['created_at']))); ?></span>
                        <span class="text-[10px] text-gray-400"><?php echo htmlspecialchars(date('H:i', strtotime($f['created_at']))); ?></span>
                      </div>
                    </td>
                    <td class="py-3 px-3">
                      <?php
                        $pub = htmlspecialchars($f['file_path']);
                        $fsCheck = __DIR__ . '/../../' . str_replace(['../','..\\'], '', $f['file_path']);
                        $exists = is_file($fsCheck);
                      ?>
                      <div class="flex items-center gap-2 flex-wrap">
                        <a class="inline-flex items-center gap-1 px-3 py-1.5 rounded-md bg-blue-50 text-blue-600 hover:bg-blue-100 text-[11px] font-medium" href="<?php echo $pub; ?>" target="_blank"><i class="fas fa-eye"></i><span>View</span></a>
                        <a class="inline-flex items-center gap-1 px-3 py-1.5 rounded-md bg-white ring-1 ring-gray-200 hover:bg-gray-50 text-gray-700 text-[11px] font-medium" href="<?php echo $pub; ?>" target="_blank" download><i class="fas fa-download"></i><span>Download</span></a>
                        <?php if(isset($_GET['debug']) && !$exists): ?><span class="text-[10px] text-red-500 font-medium">(missing)</span><?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

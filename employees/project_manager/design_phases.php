<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Role guard: must be logged in project manager (employee)
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../../login.php'); exit; }
$position = strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? ''))));
if (($_SESSION['user_type'] ?? '') !== 'employee' || $position !== 'project_manager') { header('Location: ../../index.php'); exit; }

require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = (int)($_SESSION['user_id'] ?? 0);
// Resolve employee_id for this PM
$employeeId = 0;
try {
  $empStmt = $db->prepare('SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1');
  $empStmt->execute([$userId]);
  $row = $empStmt->fetch(PDO::FETCH_ASSOC);
  $employeeId = $row ? (int)$row['employee_id'] : 0;
} catch (Throwable $e) {}

// Fetch projects managed by this PM
$projects = [];
try {
  $pstmt = $db->prepare('SELECT project_id, project_name, status FROM projects WHERE project_manager_id = ? ORDER BY created_at DESC');
  $pstmt->execute([$employeeId]);
  $projects = $pstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Design phases definition (adjust as needed)
$designPhases = [
  'concept' => 'Concept Design',
  'schematic' => 'Schematic Design',
  'development' => 'Design Development',
  'construction_docs' => 'Construction Documents',
  'final_review' => 'Final Review'
];

$uploadMsg = '';
// Handle file upload for a design phase destined for Senior Architect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['project_id'], $_POST['design_phase']) && isset($_FILES['phase_file'])) {
  $projId = (int)$_POST['project_id'];
  $phaseKey = $_POST['design_phase'];
  if (!isset($designPhases[$phaseKey])) {
    $uploadMsg = 'Invalid design phase.';
  } else {
    // Verify project belongs to this PM
    $own = false;
    foreach ($projects as $p) { if ((int)$p['project_id'] === $projId) { $own = true; break; } }
    if (!$own) {
      $uploadMsg = 'You cannot upload to this project.';
    } else {
      $file = $_FILES['phase_file'];
      if ($file['error'] === UPLOAD_ERR_OK) {
        $allowed = ['pdf','doc','docx','xls','xlsx','png','jpg','jpeg','zip','ppt','pptx'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
          $uploadMsg = 'File type not allowed.';
        } else {
          $baseDir = __DIR__ . '/../../design_phase_uploads/';
          if (!is_dir($baseDir)) { @mkdir($baseDir, 0777, true); }
          $newName = 'proj_' . $projId . '_phase_' . $phaseKey . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
          $dest = $baseDir . $newName;
          if (move_uploaded_file($file['tmp_name'], $dest)) {
            // Insert record (expects a table design_phase_files)
            /* Suggested table:
            CREATE TABLE design_phase_files (
              id INT AUTO_INCREMENT PRIMARY KEY,
              project_id INT NOT NULL,
              phase VARCHAR(50) NOT NULL,
              file_name VARCHAR(255) NOT NULL,
              original_name VARCHAR(255) NOT NULL,
              uploaded_by INT NOT NULL,
              uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX(project_id), INDEX(phase)
            ); */
            try {
              $ins = $db->prepare('INSERT INTO design_phase_files (project_id, phase, file_name, original_name, uploaded_by, uploaded_at) VALUES (?,?,?,?,?,NOW())');
              $ins->execute([$projId, $phaseKey, $newName, $file['name'], $userId]);
              header('Location: design_phases.php?upload=success');
              exit;
            } catch (Throwable $e) {
              $uploadMsg = 'DB insert failed (check table).';
            }
          } else {
            $uploadMsg = 'Failed to move uploaded file.';
          }
        }
      } else {
        $uploadMsg = 'Upload error.';
      }
    }
  }
}

// Fetch existing uploads grouped by project
$existing = [];
try {
  $q = $db->prepare('SELECT d.*, p.project_name FROM design_phase_files d JOIN projects p ON p.project_id = d.project_id WHERE p.project_manager_id = ? ORDER BY d.uploaded_at DESC LIMIT 200');
  $q->execute([$employeeId]);
  $existing = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
  <div class="max-w-6xl mx-auto">
    <h1 class="text-2xl font-bold mb-6">Design Phase Files (Senior Architect)</h1>

    <?php if (isset($_GET['upload']) && $_GET['upload']==='success'): ?>
      <div class="mb-4 p-3 bg-green-50 text-green-700 ring-1 ring-green-200 rounded">File uploaded successfully.</div>
    <?php endif; ?>
    <?php if ($uploadMsg): ?><div class="mb-4 p-3 bg-red-50 text-red-700 ring-1 ring-red-200 rounded"><?php echo htmlspecialchars($uploadMsg); ?></div><?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200 mb-8">
      <div class="p-5 border-b border-gray-100 font-semibold">Upload New Phase File</div>
      <form method="post" enctype="multipart/form-data" class="p-5 space-y-4">
        <div>
          <label class="block text-sm text-gray-600 mb-1">Project</label>
          <select name="project_id" class="w-full border rounded px-3 py-2" required>
            <option value="">-- Select Project --</option>
            <?php foreach ($projects as $p): ?>
              <option value="<?php echo (int)$p['project_id']; ?>">[<?php echo htmlspecialchars($p['status']); ?>] <?php echo htmlspecialchars($p['project_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-600 mb-1">Design Phase</label>
          <select name="design_phase" class="w-full border rounded px-3 py-2" required>
            <option value="">-- Select Phase --</option>
            <?php foreach ($designPhases as $k=>$label): ?>
              <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-600 mb-1">File</label>
          <input type="file" name="phase_file" required class="block w-full text-sm" />
          <p class="text-xs text-gray-500 mt-1">Allowed: pdf, doc/x, xls/x, png, jpg, jpeg, zip, ppt/x</p>
        </div>
        <div class="pt-2">
          <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Upload</button>
        </div>
      </form>
    </div>

    <div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200">
      <div class="p-5 border-b border-gray-100 font-semibold">Recent Uploads</div>
      <?php if (!$existing): ?>
        <div class="p-5 text-gray-500">No phase files uploaded yet.</div>
      <?php else: ?>
        <ul class="divide-y divide-gray-100">
          <?php foreach ($existing as $row): ?>
            <li class="p-4 flex items-center gap-4">
              <div class="flex-1 min-w-0">
                <div class="font-medium text-gray-900 truncate"><?php echo htmlspecialchars($row['project_name']); ?> • <?php echo htmlspecialchars($designPhases[$row['phase']] ?? $row['phase']); ?></div>
                <div class="text-xs text-gray-500 mt-0.5">Uploaded <?php echo htmlspecialchars(date('M j, Y H:i', strtotime($row['uploaded_at']))); ?> • Original: <?php echo htmlspecialchars($row['original_name']); ?></div>
              </div>
              <div class="flex items-center gap-2">
                <a href="/ArchiFlow/design_phase_uploads/<?php echo rawurlencode($row['file_name']); ?>" target="_blank" class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">View</a>
                <a href="/ArchiFlow/design_phase_uploads/<?php echo rawurlencode($row['file_name']); ?>" download class="px-2 py-1 bg-green-600 text-white rounded text-xs hover:bg-green-700">Download</a>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

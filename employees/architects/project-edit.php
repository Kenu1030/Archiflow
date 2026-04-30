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

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$err=''; $ok='';
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
// flash for PRG
$flash = $_SESSION['flash'] ?? null; if ($flash) { unset($_SESSION['flash']); }

// Load project (ensure belongs to architect)
$proj = null;
try {
  $ps = $db->prepare('SELECT p.*, c.company_name FROM projects p LEFT JOIN clients c ON c.client_id=p.client_id WHERE p.project_id = ? AND p.architect_id = ?');
  $ps->execute([$projectId, $employeeId]);
  $proj = $ps->fetch(PDO::FETCH_ASSOC);
  if (!$proj) { throw new RuntimeException('Project not found or not allowed.'); }
} catch (Throwable $ex) { $err = $ex->getMessage(); }

// Handle update
if (!$err && (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') && (($_POST['action'] ?? '') === 'update')) {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { $err = 'Invalid form token.'; } else {
  $name = trim((string)($_POST['project_name'] ?? ''));
  $type = $_POST['project_type'] ?? $proj['project_type'];
  $status = $_POST['status'] ?? $proj['status'];
  $location = trim((string)($_POST['location'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));
  $startDate = $_POST['start_date'] ?? '';
  $endDate = $_POST['end_date'] ?? '';
  $budget = $_POST['budget'] !== '' ? (float)$_POST['budget'] : null;
  try {
    if ($name === '') { throw new RuntimeException('Project name is required.'); }
    $validTypes = ['residential','commercial','industrial'];
    if (!in_array($type, $validTypes, true)) { throw new RuntimeException('Invalid project type.'); }
    $validStatus = ['planning','design','construction','completed','cancelled'];
    if (!in_array($status, $validStatus, true)) { throw new RuntimeException('Invalid status.'); }
    foreach (['startDate'=>$startDate,'endDate'=>$endDate] as $k=>$v) {
      if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { throw new RuntimeException('Invalid date format.'); }
    }
    $up = $db->prepare('UPDATE projects SET project_name=?, project_type=?, status=?, description=?, location=?, start_date=?, end_date=?, budget=? WHERE project_id=? AND architect_id=?');
    $up->execute([$name, $type, $status, $description, $location, ($startDate!==''?$startDate:null), ($endDate!==''?$endDate:null), $budget, $projectId, $employeeId]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Project updated successfully.'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    header('Location: /ArchiFlow/employees/architects/project-edit.php?project_id='.(int)$projectId);
    exit;
  } catch (Throwable $ex) { $err = $ex->getMessage(); }
  }
}

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
  <div class="max-w-3xl mx-auto">
    <a href="/ArchiFlow/employees/architects/projects.php" class="text-sm text-indigo-600 hover:text-indigo-800">← Back to Projects</a>
    <h1 class="text-2xl font-bold mt-2 mb-4">Edit Project</h1>
  <?php if ($err): ?><div class="mb-4 p-3 bg-red-50 text-red-700 ring-1 ring-red-200 rounded"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <?php if ($flash && ($flash['type'] ?? '') === 'success'): ?><div class="mb-4 p-3 bg-green-50 text-green-700 ring-1 ring-green-200 rounded"><?php echo htmlspecialchars($flash['msg'] ?? ''); ?></div><?php endif; ?>
    <?php if ($proj): ?>
    <div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200 p-4">
      <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <input type="hidden" name="action" value="update" />
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" />
        <div class="md:col-span-2">
          <label class="block text-sm text-gray-700 mb-1">Project Name</label>
          <input type="text" name="project_name" value="<?php echo htmlspecialchars($proj['project_name']); ?>" required class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 placeholder-slate-400 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <label class="block text-sm text-gray-700 mb-1">Type</label>
          <select name="project_type" class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500">
            <?php foreach (['residential','commercial','industrial'] as $t): ?>
              <option value="<?php echo $t; ?>" <?php echo $proj['project_type']===$t?'selected':''; ?>><?php echo ucfirst($t); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-700 mb-1">Status</label>
          <select name="status" class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500">
            <?php foreach (['planning','design','construction','completed','cancelled'] as $s): ?>
              <option value="<?php echo $s; ?>" <?php echo $proj['status']===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-700 mb-1">Start Date</label>
          <input type="date" name="start_date" value="<?php echo htmlspecialchars($proj['start_date'] ?? ''); ?>" class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <label class="block text-sm text-gray-700 mb-1">End Date</label>
          <input type="date" name="end_date" value="<?php echo htmlspecialchars($proj['end_date'] ?? ''); ?>" class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <label class="block text-sm text-gray-700 mb-1">Budget</label>
          <input type="number" step="0.01" min="0" name="budget" value="<?php echo htmlspecialchars((string)$proj['budget']); ?>" class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm text-gray-700 mb-1">Location</label>
          <input type="text" name="location" value="<?php echo htmlspecialchars($proj['location'] ?? ''); ?>" class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 placeholder-slate-400 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm text-gray-700 mb-1">Description</label>
          <textarea name="description" rows="3" class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 placeholder-slate-400 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500"><?php echo htmlspecialchars($proj['description'] ?? ''); ?></textarea>
        </div>
        <div class="md:col-span-2">
          <button type="submit" class="inline-flex items-center px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm">Save Changes</button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

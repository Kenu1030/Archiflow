<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../../login.php'); exit; }
if (($_SESSION['user_type'] ?? '') !== 'employee' || strtolower((string)($_SESSION['position'] ?? '')) !== 'architect') { header('Location: ../../index.php'); exit; }
require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// find employee id
$emp = $db->prepare('SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1');
$emp->execute([$_SESSION['user_id']]);
$e = $emp->fetch(PDO::FETCH_ASSOC);
$employeeId = $e ? (int)$e['employee_id'] : 0;

// Auto-provision employee row if missing (to avoid blocking project creation)
if ($employeeId === 0) {
  try {
    $code = 'EMP-' . (int)$_SESSION['user_id'] . '-' . date('ymdHis');
    $pos = 'architect';
    $ins = $db->prepare("INSERT INTO employees (user_id, employee_code, position, department, hire_date, salary, status) VALUES (?,?,?,?,CURDATE(),?, 'active')");
    $sal = 0.00;
    $ins->execute([$_SESSION['user_id'], $code, $pos, 'Architecture', $sal]);
    $employeeId = (int)$db->lastInsertId();
  } catch (Throwable $ex) {
    // ignore, will surface as empty list and form will error if used
  }
}

$rows = [];$err = '';$ok='';

// Load clients for dropdown
$clients = [];
try {
  $cs = $db->query('SELECT client_id, company_name, client_type FROM clients ORDER BY company_name ASC');
  $clients = $cs->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) { $err = $ex->getMessage(); }

// Handle create project
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'create') {
  $name = trim((string)($_POST['project_name'] ?? ''));
  $clientId = (int)($_POST['client_id'] ?? 0);
  $type = $_POST['project_type'] ?? '';
  $location = trim((string)($_POST['location'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));
  $startDate = $_POST['start_date'] ?? '';
  $budget = $_POST['budget'] !== '' ? (float)$_POST['budget'] : null;
  try {
    if ($employeeId === 0) { throw new RuntimeException('Employee profile not initialized. Try reloading your dashboard first.'); }
    if ($name === '') { throw new RuntimeException('Project name is required.'); }
    if ($clientId <= 0) { throw new RuntimeException('Choose a client.'); }
    $validTypes = ['residential','commercial','industrial'];
    if (!in_array($type, $validTypes, true)) { throw new RuntimeException('Invalid project type.'); }
    if ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) { throw new RuntimeException('Invalid start date.'); }
    // Generate unique project code
    $tries = 0; $projectCode = '';
    do {
      $tries++;
      $projectCode = 'PRJ-' . date('Ymd') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
      $chk = $db->prepare('SELECT 1 FROM projects WHERE project_code = ?');
      $chk->execute([$projectCode]);
    } while ($chk->fetch() && $tries < 5);
    if ($chk->fetch()) { throw new RuntimeException('Could not generate project code. Try again.'); }
    $ins = $db->prepare('INSERT INTO projects (project_code, project_name, client_id, architect_id, project_type, status, description, location, start_date, budget) VALUES (?,?,?,?,? ,\'planning\',?,?,?,?)');
    $ins->execute([$projectCode, $name, $clientId, $employeeId, $type, $description, $location, ($startDate!==''?$startDate:null), $budget]);
    $ok = 'Project created successfully.';
  } catch (Throwable $ex) { $err = $ex->getMessage(); }
}

try {
  // Detect archive / delete columns
  $hasArch = false; $hasDel = false;
  try {
    $colInfo = $db->query('SHOW COLUMNS FROM projects');
    foreach ($colInfo as $ci) {
      if ($ci['Field'] === 'is_archived') { $hasArch = true; }
      if ($ci['Field'] === 'is_deleted') { $hasDel = true; }
    }
  } catch (Throwable $ie) { /* ignore */ }
  $filter = [];
  if ($hasArch) { $filter[] = 'p.is_archived = 0'; }
  if ($hasDel) { $filter[] = '(p.is_deleted = 0 OR p.is_deleted IS NULL)'; }
  $filterSql = $filter ? (' AND ' . implode(' AND ', $filter)) : '';
  $sql = "SELECT DISTINCT p.project_id, p.project_code, p.project_name, p.project_type, p.status, p.created_at, c.company_name
          FROM projects p
          LEFT JOIN clients c ON p.client_id = c.client_id
          LEFT JOIN project_users pu ON pu.project_id = p.project_id
          WHERE (p.architect_id = ? OR pu.user_id = ?)" . $filterSql . "
          ORDER BY p.created_at DESC";
  $stmt = $db->prepare($sql);
  $stmt->execute([$employeeId, $_SESSION['user_id']]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) { $err = $ex->getMessage(); }
include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
  <div class="max-w-full">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">My Projects</h1>
    </div>
  <?php if ($err): ?><div class="mb-4 p-3 bg-red-50 text-red-700 ring-1 ring-red-200 rounded"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="mb-4 p-3 bg-green-50 text-green-700 ring-1 ring-green-200 rounded"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>
  <div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200 overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
          <tr>
            <th class="px-4 py-3 text-left">Project</th>
            <th class="px-4 py-3 text-left">Client</th>
            <th class="px-4 py-3 text-left">Type</th>
            <th class="px-4 py-3 text-left">Status</th>
            <th class="px-4 py-3 text-right">Created</th>
            <th class="px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (!$rows): ?>
            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">No projects found.</td></tr>
          <?php else: foreach ($rows as $p): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-3">
                <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($p['project_name']); ?></div>
              </td>
              <td class="px-4 py-3"><?php echo htmlspecialchars($p['company_name'] ?? '—'); ?></td>
              <td class="px-4 py-3 capitalize"><?php echo htmlspecialchars($p['project_type']); ?></td>
              <td class="px-4 py-3">
                <span class="px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-700"><?php echo htmlspecialchars($p['status']); ?></span>
              </td>
              <td class="px-4 py-3 text-right text-gray-600"><?php echo htmlspecialchars(date('M j, Y', strtotime($p['created_at']))); ?></td>
              <td class="px-4 py-3 text-right">
                <a class="text-blue-600 hover:text-blue-800 mr-3" href="/ArchiFlow/employees/architects/project-details.php?project_id=<?php echo (int)$p['project_id']; ?>">View</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

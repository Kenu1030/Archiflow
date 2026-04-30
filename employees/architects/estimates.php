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

$err=''; $ok='';
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

// Fetch architect projects for dropdown
$projects = [];
try {
  $ps = $db->prepare('SELECT project_id, project_name, project_code FROM projects WHERE architect_id = ? ORDER BY created_at DESC');
  $ps->execute([$employeeId]);
  $projects = $ps->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) { $err = $ex->getMessage(); }

// Fetch design services
$services = [];
try {
  $ss = $db->query('SELECT service_id, service_name, service_type, base_price, price_per_sqm FROM design_services ORDER BY service_name ASC');
  $services = $ss->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) { $err = $err ?: $ex->getMessage(); }

// Handle add line item
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add') {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { $err = 'Invalid form token.'; }
  else {
  $projectId = (int)($_POST['project_id'] ?? 0);
  $serviceId = (int)($_POST['service_id'] ?? 0);
  $qty = (float)($_POST['quantity'] ?? 1);
  $unitPrice = isset($_POST['unit_price']) && $_POST['unit_price'] !== '' ? (float)$_POST['unit_price'] : null;
  try {
    $chk = $db->prepare('SELECT project_id FROM projects WHERE project_id = ? AND architect_id = ?');
    $chk->execute([$projectId, $employeeId]);
    if (!$chk->fetch()) { throw new RuntimeException('Invalid project.'); }
    if ($serviceId <= 0) { throw new RuntimeException('Choose a service.'); }
    // default unit price from service base_price when not provided
    if ($unitPrice === null) {
      $sp = $db->prepare('SELECT base_price FROM design_services WHERE service_id = ?');
      $sp->execute([$serviceId]);
      $unitPrice = (float)($sp->fetchColumn() ?: 0);
    }
    $total = $unitPrice * max(0.0, $qty);
    $ins = $db->prepare('INSERT INTO project_estimates (project_id, service_id, quantity, unit_price, total_amount) VALUES (?,?,?,?,?)');
    $ins->execute([$projectId, $serviceId, $qty, $unitPrice, $total]);
    $ok = 'Estimate line added.';
  } catch (Throwable $ex) { $err = $ex->getMessage(); }
  }
}

// Handle delete line
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { $err = 'Invalid form token.'; }
  else {
  $estimateId = (int)($_POST['estimate_id'] ?? 0);
  try {
    $chk = $db->prepare('SELECT pe.estimate_id FROM project_estimates pe JOIN projects p ON p.project_id=pe.project_id WHERE pe.estimate_id = ? AND p.architect_id = ?');
    $chk->execute([$estimateId, $employeeId]);
    if ($chk->fetch()) {
      $del = $db->prepare('DELETE FROM project_estimates WHERE estimate_id = ?');
      $del->execute([$estimateId]);
      $ok = 'Estimate line removed.';
    } else { throw new RuntimeException('Not allowed.'); }
  } catch (Throwable $ex) { $err = $ex->getMessage(); }
  }
}

// Load estimates for this architect
$estimates = [];
try {
  $es = $db->prepare("SELECT pe.estimate_id, pe.quantity, pe.unit_price, pe.total_amount,
                             p.project_id, p.project_name, p.project_code,
                             ds.service_name
                      FROM project_estimates pe
                      JOIN projects p ON p.project_id=pe.project_id
                      JOIN design_services ds ON ds.service_id=pe.service_id
                      WHERE p.architect_id = ?
                      ORDER BY pe.created_at DESC, pe.estimate_id DESC");
  $es->execute([$employeeId]);
  $estimates = $es->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) { $err = $err ?: $ex->getMessage(); }

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
  <div class="max-w-full">
    <h1 class="text-2xl font-bold mb-4">Fee Estimates</h1>
    <?php if ($err): ?><div class="mb-4 p-3 bg-red-50 text-red-700 ring-1 ring-red-200 rounded"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="mb-4 p-3 bg-green-50 text-green-700 ring-1 ring-green-200 rounded"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200 p-4 mb-6">
      <form method="post" class="grid grid-cols-1 md:grid-cols-6 gap-4 items-end">
        <input type="hidden" name="action" value="add" />
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" />
        <div class="md:col-span-2">
          <label class="block text-sm text-gray-700 mb-1">Project</label>
          <select name="project_id" required class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500">
            <option value="">Select project</option>
            <?php foreach ($projects as $p): ?>
              <option value="<?php echo (int)$p['project_id']; ?>"><?php echo htmlspecialchars($p['project_name'] . ' (' . $p['project_code'] . ')'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm text-gray-700 mb-1">Service</label>
          <select name="service_id" required class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500">
            <option value="">Select service</option>
            <?php foreach ($services as $s): ?>
              <option value="<?php echo (int)$s['service_id']; ?>"><?php echo htmlspecialchars($s['service_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-700 mb-1">Quantity</label>
          <input type="number" name="quantity" step="0.01" min="0" value="1" class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <label class="block text-sm text-gray-700 mb-1">Unit Price (optional)</label>
          <input type="number" name="unit_price" step="0.01" min="0" class="w-full rounded-lg border border-slate-300 bg-white text-gray-900 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <button type="submit" class="inline-flex items-center px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm">Add</button>
        </div>
      </form>
    </div>

    <div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200 overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
          <tr>
            <th class="px-4 py-3 text-left">Project</th>
            <th class="px-4 py-3 text-left">Service</th>
            <th class="px-4 py-3 text-right">Qty</th>
            <th class="px-4 py-3 text-right">Unit Price</th>
            <th class="px-4 py-3 text-right">Total</th>
            <th class="px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (!$estimates): ?>
            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No estimate lines yet.</td></tr>
          <?php else: foreach ($estimates as $e): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-3">
                <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($e['project_name']); ?></div>
                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($e['project_code']); ?></div>
              </td>
              <td class="px-4 py-3"><?php echo htmlspecialchars($e['service_name']); ?></td>
              <td class="px-4 py-3 text-right"><?php echo number_format((float)$e['quantity'], 2); ?></td>
              <td class="px-4 py-3 text-right"><?php echo number_format((float)$e['unit_price'], 2); ?></td>
              <td class="px-4 py-3 text-right font-semibold"><?php echo number_format((float)$e['total_amount'], 2); ?></td>
              <td class="px-4 py-3 text-right">
                <form method="post" class="inline" onsubmit="return confirm('Remove this line?');">
                  <input type="hidden" name="action" value="delete" />
                  <input type="hidden" name="estimate_id" value="<?php echo (int)$e['estimate_id']; ?>" />
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" />
                  <button type="submit" class="text-red-600 hover:text-red-800">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

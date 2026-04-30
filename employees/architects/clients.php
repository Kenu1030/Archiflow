<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../../login.php'); exit; }
if (($_SESSION['user_type'] ?? '') !== 'employee' || strtolower((string)($_SESSION['position'] ?? '')) !== 'architect') { header('Location: ../../index.php'); exit; }
require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$emp = $db->prepare('SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1');
$emp->execute([$_SESSION['user_id']]);
$e = $emp->fetch(PDO::FETCH_ASSOC);
$employeeId = $e ? (int)$e['employee_id'] : 0; // Not used directly yet
$rows = [];$err='';
try {
  $stmt = $db->prepare("SELECT DISTINCT c.client_id, c.company_name, c.client_type
                         FROM projects p JOIN clients c ON c.client_id=p.client_id
                         WHERE p.architect_id = ? ORDER BY c.company_name ASC");
  $stmt->execute([$employeeId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) { $err = $ex->getMessage(); }
include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
  <div class="max-w-full">
    <h1 class="text-2xl font-bold mb-4">Clients</h1>
  <?php if ($err): ?><div class="mb-4 p-3 bg-red-50 text-red-700 ring-1 ring-red-200 rounded"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200 overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
          <tr>
            <th class="px-4 py-3 text-left">Client</th>
            <th class="px-4 py-3 text-left">Type</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (!$rows): ?>
            <tr><td colspan="2" class="px-4 py-6 text-center text-gray-500">No clients found.</td></tr>
          <?php else: foreach ($rows as $c): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-3"><?php echo htmlspecialchars($c['company_name'] ?? '—'); ?></td>
              <td class="px-4 py-3 capitalize"><?php echo htmlspecialchars($c['client_type'] ?? ''); ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

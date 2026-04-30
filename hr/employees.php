<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['user_type'] ?? null) !== 'hr') {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../backend/connection/connect.php';
$pdo = getDB();
if (!$pdo) { http_response_code(500); echo 'DB error'; exit; }

$q = trim($_GET['q'] ?? '');
$pos = trim($_GET['position'] ?? '');
$status = trim($_GET['status'] ?? '');
$params = [];
$sql = "SELECT e.employee_id, e.employee_code, e.position, e.department, e.hire_date, e.status, e.created_at,
               u.first_name, u.last_name
        FROM employees e LEFT JOIN users u ON e.user_id = u.user_id WHERE 1=1";
if ($q !== '') {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR e.employee_code LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
if ($pos !== '') { $sql .= " AND e.position = ?"; $params[] = $pos; }
if ($status !== '') { $sql .= " AND e.status = ?"; $params[] = $status; }
$sql .= " ORDER BY e.created_at DESC, e.employee_id DESC LIMIT 50";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
// Suppress footer on this page
$HIDE_FOOTER = true;
include_once __DIR__ . '/../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-8">
  <div class="max-w-full px-4">
    <div class="flex items-center space-x-3">
      <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center"><i class="fas fa-users"></i></div>
      <div>
        <h1 class="text-2xl font-semibold">Employees</h1>
        <p class="text-white/70">Directory and quick filters</p>
      </div>
    </div>
  </div>
</section>
<main class="max-w-full px-4 -mt-6">
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-3">
      <div>
        <label class="text-sm text-gray-600">Search</label>
        <input name="q" value="<?php echo htmlspecialchars($q); ?>" class="w-full border rounded p-2" placeholder="Name or code" />
      </div>
      <div>
        <label class="text-sm text-gray-600">Position</label>
        <select name="position" class="w-full border rounded p-2">
          <option value="">All</option>
          <option value="architect" <?php echo $pos==='architect'?'selected':''; ?>>Architect</option>
          <option value="senior_architect" <?php echo $pos==='senior_architect'?'selected':''; ?>>Senior Architect</option>
          <option value="project_manager" <?php echo $pos==='project_manager'?'selected':''; ?>>Project Manager</option>
        </select>
      </div>
      <div>
        <label class="text-sm text-gray-600">Status</label>
        <select name="status" class="w-full border rounded p-2">
          <option value="">All</option>
          <option value="active" <?php echo $status==='active'?'selected':''; ?>>Active</option>
          <option value="inactive" <?php echo $status==='inactive'?'selected':''; ?>>Inactive</option>
        </select>
      </div>
      <div class="flex items-end">
        <button class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Filter</button>
      </div>
    </form>

    <div class="overflow-x-auto mt-4">
      <table class="min-w-full">
        <thead>
          <tr class="text-left text-sm text-gray-500">
            <th class="py-2">ID</th>
            <th class="py-2">Code</th>
            <th class="py-2">Name</th>
            <th class="py-2">Position</th>
            <th class="py-2">Department</th>
            <th class="py-2">Hire Date</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Profile</th>
          </tr>
        </thead>
        <tbody class="text-sm">
          <?php if (empty($rows)): ?>
            <tr><td colspan="8" class="py-4 text-center text-gray-500">No employees.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr class="border-t">
              <td class="py-2">#<?php echo (int)$r['employee_id']; ?></td>
              <td class="py-2"><?php echo htmlspecialchars($r['employee_code']); ?></td>
              <td class="py-2"><?php echo htmlspecialchars(trim(($r['first_name']??'') . ' ' . ($r['last_name']??'')) ?: '—'); ?></td>
              <td class="py-2 capitalize"><?php echo htmlspecialchars(str_replace('_',' ', $r['position'])); ?></td>
              <td class="py-2"><?php echo htmlspecialchars($r['department']); ?></td>
              <td class="py-2 text-gray-500"><?php echo htmlspecialchars($r['hire_date']); ?></td>
              <td class="py-2">
                <?php $st = $r['status']; $badge = $st==='active'?'bg-green-100 text-green-700':'bg-gray-100 text-gray-700'; ?>
                <span class="px-2 py-0.5 rounded-full <?php echo $badge; ?>"><?php echo ucfirst($st); ?></span>
              </td>
              <td class="py-2 text-right">
                <a class="text-blue-600 hover:underline" href="hr/employee-profile.php?id=<?php echo (int)$r['employee_id']; ?>">View</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>

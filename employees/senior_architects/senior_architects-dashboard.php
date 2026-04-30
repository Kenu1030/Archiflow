<?php
// Session and role/position guard before any output
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Compute app base (supports /ArchiFlow subfolder)
$APP_BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($APP_BASE === '/' || $APP_BASE === '.') { $APP_BASE = ''; }

// Compute root base for redirects (go up 2 levels from employees/senior_architects/)
$ROOT_BASE = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($ROOT_BASE === '/' || $ROOT_BASE === '.') { $ROOT_BASE = ''; }

// Require employee senior_architect
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ' . $ROOT_BASE . '/login.php');
    exit;
}
if (($_SESSION['user_type'] ?? '') !== 'employee' || strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? '')))) !== 'senior_architect') {
    // Send others to their dashboards
    $userType = $_SESSION['user_type'] ?? '';
    switch ($userType) {
        case 'admin': header('Location: ' . $ROOT_BASE . '/admin/dashboard.php'); break;
        case 'client': header('Location: ' . $ROOT_BASE . '/client/dashboard.php'); break;
        case 'hr': header('Location: ' . $ROOT_BASE . '/hr/hr-dashboard.php'); break;
        case 'employee': 
            $position = strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? ''))));
            if ($position === 'architect') {
                header('Location: ' . $ROOT_BASE . '/employees/architects/architects-dashboard.php');
            } elseif ($position === 'project_manager') {
                header('Location: ' . $ROOT_BASE . '/employees/project_manager/project_manager-dashboard.php');
            } else {
                // For unknown positions or senior_architects, redirect to employees index
                header('Location: ' . $ROOT_BASE . '/employees/index.php');
            }
            break;
        default: header('Location: ' . $ROOT_BASE . '/index.php');
    }
    exit;
}

require_once __DIR__ . '/../../backend/connection/connect.php';

$db = null;
$employeeId = null;
$errorMsg = '';

// Data buckets
$stats = [
  'overseeing_active' => 0,
  'overseeing_total' => 0,
  'pending_reviews' => 0,
  'approved_last7' => 0,
  'unread_notifications' => 0,
];

$overseenProjects = [];
$pendingReviews = [];

try {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Determine current employee_id for the senior architect
    $stmtEmp = $db->prepare('SELECT employee_id, status FROM employees WHERE user_id = ? LIMIT 1');
    $stmtEmp->execute([$_SESSION['user_id']]);
    $employee = $stmtEmp->fetch(PDO::FETCH_ASSOC);
    if (!$employee) {
        // Auto-provision a record
        $empCode = 'EMP-' . (int)$_SESSION['user_id'];
        $insert = $db->prepare("INSERT INTO employees (user_id, employee_code, position, department, hire_date, salary, status) VALUES (?, ?, 'senior_architect', 'Architecture', CURDATE(), 0.00, 'active')");
        $insert->execute([$_SESSION['user_id'], $empCode]);
        $stmtEmp->execute([$_SESSION['user_id']]);
        $employee = $stmtEmp->fetch(PDO::FETCH_ASSOC);
        if (!$employee) { throw new Exception('Employee record not found'); }
    }
    $employeeId = (int)$employee['employee_id'];

  // Overseeing totals via assignment table (project_senior_architects)
  $stmt = $db->prepare("SELECT COUNT(DISTINCT p.project_id) FROM project_senior_architects psa JOIN projects p ON p.project_id=psa.project_id WHERE psa.employee_id=? AND (p.is_deleted=0 OR p.is_deleted IS NULL) AND (p.is_archived=0 OR p.is_archived IS NULL)");
  $stmt->execute([$employeeId]);
  $stats['overseeing_total'] = (int)$stmt->fetchColumn();

  // Overseeing active projects (in active phases)
  $stmt = $db->prepare("SELECT COUNT(DISTINCT p.project_id) FROM project_senior_architects psa JOIN projects p ON p.project_id=psa.project_id WHERE psa.employee_id=? AND p.status IN ('planning','design','construction') AND (p.is_deleted=0 OR p.is_deleted IS NULL) AND (p.is_archived=0 OR p.is_archived IS NULL)");
  $stmt->execute([$employeeId]);
  $stats['overseeing_active'] = (int)$stmt->fetchColumn();

  // Pending reviews assigned to this SA
  $stmt = $db->prepare("SELECT COUNT(*) FROM design_reviews WHERE reviewer_id=? AND status='pending'");
  $stmt->execute([$employeeId]);
  $stats['pending_reviews'] = (int)$stmt->fetchColumn();

  // Approved in last 7 days
  $stmt = $db->prepare("SELECT COUNT(*) FROM design_reviews WHERE reviewer_id=? AND status='approved' AND reviewed_at >= (NOW() - INTERVAL 7 DAY)");
  $stmt->execute([$employeeId]);
  $stats['approved_last7'] = (int)$stmt->fetchColumn();

    // Unread notifications (by user_id)
    $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$_SESSION['user_id']]);
    $stats['unread_notifications'] = (int)$stmt->fetchColumn();

  // Overseen projects (recent)
  $stmt = $db->prepare("SELECT DISTINCT p.project_id, p.project_code, p.project_name, p.project_type, p.status, p.created_at, p.is_archived, p.is_deleted
               FROM project_senior_architects psa
               JOIN projects p ON p.project_id=psa.project_id
               WHERE psa.employee_id=? AND (p.is_deleted=0 OR p.is_deleted IS NULL) AND (p.is_archived=0 OR p.is_archived IS NULL)
               ORDER BY p.created_at DESC LIMIT 8");
  $stmt->execute([$employeeId]);
  $overseenProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Pending reviews (soonest) assigned to this SA
  $stmt = $db->prepare("SELECT dr.review_id, dr.project_id, dr.milestone_id, dr.document_id, dr.status, dr.created_at, p.project_name, p.project_code,
                 m.milestone_name, m.target_date
              FROM design_reviews dr
              JOIN projects p ON p.project_id=dr.project_id
              LEFT JOIN milestones m ON m.milestone_id=dr.milestone_id
              WHERE dr.reviewer_id=? AND dr.status='pending'
              ORDER BY (m.target_date IS NULL), m.target_date ASC, dr.created_at ASC
              LIMIT 8");
  $stmt->execute([$employeeId]);
  $pendingReviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $errorMsg = $e->getMessage();
}

function status_badge_class($status) {
    $map = [
        'planning' => 'bg-yellow-100 text-yellow-800',
        'design' => 'bg-blue-100 text-blue-800',
        'construction' => 'bg-purple-100 text-purple-800',
        'completed' => 'bg-green-100 text-green-800',
        'cancelled' => 'bg-red-100 text-red-800',
        'pending' => 'bg-amber-100 text-amber-800',
        'in_progress' => 'bg-indigo-100 text-indigo-800',
    ];
    return $map[strtolower((string)$status)] ?? 'bg-gray-100 text-gray-800';
}

include __DIR__ . '/../../backend/core/header.php';
?>

<main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-50">
  <div class="max-w-full px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex items-center justify-between mb-8">
      <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-slate-900">Welcome back, <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?></h1>
        <p class="text-slate-500 mt-1">Senior Architect • <?php echo date('l, F j, Y'); ?></p>
      </div>
      <div class="flex items-center gap-2">
        <a href="<?php echo $APP_BASE; ?>/employees/senior_architects/reviews.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 transition">
          <i class="fas fa-clipboard-check"></i>
          <span>My Reviews</span>
        </a>
      </div>
    </div>

    <?php if (!empty($errorMsg)): ?>
      <div class="mb-6 p-4 rounded-lg ring-1 ring-red-200 bg-red-50 text-red-800">
        <strong>Unable to load dashboard:</strong> <?php echo htmlspecialchars($errorMsg); ?>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
      <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-slate-500">Overseen Active</p>
            <p class="text-3xl font-bold" data-counter="<?php echo (int)$stats['overseeing_active']; ?>">0</p>
          </div>
          <span class="p-3 rounded-xl bg-blue-50 text-blue-600"><i class="fas fa-diagram-project"></i></span>
        </div>
      </div>
      <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-slate-500">Overseen Total</p>
            <p class="text-3xl font-bold" data-counter="<?php echo (int)$stats['overseeing_total']; ?>">0</p>
          </div>
          <span class="p-3 rounded-xl bg-indigo-50 text-indigo-600"><i class="fas fa-sitemap"></i></span>
        </div>
      </div>
      <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-slate-500">Pending Reviews</p>
            <p class="text-3xl font-bold" data-counter="<?php echo (int)$stats['pending_reviews']; ?>">0</p>
          </div>
          <span class="p-3 rounded-xl bg-amber-50 text-amber-600"><i class="fas fa-clipboard-check"></i></span>
        </div>
      </div>
      <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-slate-500">Approved (7d)</p>
            <p class="text-3xl font-bold" data-counter="<?php echo (int)$stats['approved_last7']; ?>">0</p>
          </div>
          <span class="p-3 rounded-xl bg-green-50 text-green-600"><i class="fas fa-check-circle"></i></span>
        </div>
      </div>
      <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-slate-500">Notifications</p>
            <p class="text-3xl font-bold" data-counter="<?php echo (int)$stats['unread_notifications']; ?>">0</p>
          </div>
          <span class="p-3 rounded-xl bg-rose-50 text-rose-600"><i class="fas fa-bell"></i></span>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <section class="lg:col-span-2 rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-lg font-semibold text-slate-900">My Overseen Projects</h2>
        </div>
        <div class="overflow-x-auto -mx-4 sm:mx-0">
          <table class="min-w-full divide-y divide-slate-200">
            <thead>
              <tr class="text-left text-xs font-medium uppercase tracking-wider text-slate-500">
                <th class="py-3 pr-3 pl-4">Project</th>
                <th class="py-3 px-3">Type</th>
                <th class="py-3 px-3">Status</th>
                <th class="py-3 pl-3 pr-4 text-right">Created</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php if (empty($overseenProjects)): ?>
                <tr><td colspan="4" class="py-6 text-center text-slate-500">No overseen projects yet.</td></tr>
              <?php else: foreach ($overseenProjects as $p): ?>
                <tr class="hover:bg-slate-50">
                  <td class="py-3 pr-3 pl-4">
                    <div class="font-medium text-slate-900"><?php echo htmlspecialchars($p['project_name']); ?></div>
                    <div class="text-xs text-slate-500"><?php echo htmlspecialchars($p['project_code']); ?></div>
                  </td>
                  <td class="py-3 px-3 capitalize text-slate-700"><?php echo htmlspecialchars($p['project_type']); ?></td>
                  <td class="py-3 px-3">
                    <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo status_badge_class($p['status']); ?>"><?php echo htmlspecialchars($p['status']); ?></span>
                  </td>
                  <td class="py-3 pl-3 pr-4 text-right text-slate-600"><?php echo htmlspecialchars(date('M j, Y', strtotime($p['created_at']))); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold text-slate-900">Pending Reviews</h3>
        </div>
        <ul class="divide-y divide-slate-100">
          <?php if (empty($pendingReviews)): ?>
            <li class="py-4 text-slate-500">No pending items.</li>
          <?php else: foreach ($pendingReviews as $r): ?>
            <li class="py-3">
              <div class="flex items-center justify-between">
                <div>
                  <div class="font-medium text-slate-900"><?php echo htmlspecialchars($r['milestone_name'] ?? 'Design Review'); ?></div>
                  <div class="text-xs text-slate-500">Project: <?php echo htmlspecialchars($r['project_name']); ?> (<?php echo htmlspecialchars($r['project_code']); ?>)</div>
                </div>
                <div class="text-sm text-slate-700"><?php echo $r['target_date'] ? htmlspecialchars(date('M j, Y', strtotime($r['target_date']))) : '—'; ?></div>
              </div>
            </li>
          <?php endforeach; endif; ?>
        </ul>
      </section>
    </div>
  </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-counter]').forEach(el => {
    const target = parseInt(el.getAttribute('data-counter') || '0', 10);
    const duration = 800;
    const start = performance.now();
    const step = (t) => {
      const p = Math.min(1, (t - start) / duration);
      el.textContent = Math.floor(target * p).toLocaleString();
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  });
});
</script>

<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

<?php
// Senior Architect Reviews
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$APP_BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($APP_BASE === '/' || $APP_BASE === '.') { $APP_BASE = ''; }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ' . $APP_BASE . '/login.php'); exit; }
if (($_SESSION['user_type'] ?? '') !== 'employee' || strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? '')))) !== 'senior_architect') { header('Location: ' . $APP_BASE . '/index.php'); exit; }
require_once __DIR__ . '/../../backend/connection/connect.php';

$pending = $completed = [];
$errorMsg = '';
try {
  $db = getDB();
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $stmt = $db->prepare('SELECT employee_id FROM employees WHERE user_id=? LIMIT 1');
  $stmt->execute([$_SESSION['user_id']]);
  $emp = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$emp) throw new Exception('Employee not found');
  $employeeId = (int)$emp['employee_id'];

  $sqlBase = "SELECT dr.review_id, dr.status, dr.created_at, dr.reviewed_at, dr.comments,
                     p.project_id, p.project_name, p.project_code,
                     m.milestone_id, m.milestone_name, m.target_date
              FROM design_reviews dr
              JOIN projects p ON p.project_id = dr.project_id
              LEFT JOIN milestones m ON m.milestone_id = dr.milestone_id
              WHERE dr.reviewer_id = ?";

  $stmt = $db->prepare($sqlBase . " AND dr.status='pending' ORDER BY (m.target_date IS NULL), m.target_date ASC, dr.created_at ASC");
  $stmt->execute([$employeeId]);
  $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $stmt = $db->prepare($sqlBase . " AND dr.status IN ('approved','changes_requested','rejected') ORDER BY dr.reviewed_at DESC, dr.created_at DESC LIMIT 20");
  $stmt->execute([$employeeId]);
  $completed = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $errorMsg = $e->getMessage(); }

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-50">
  <div class="max-w-full px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-slate-900">Design Reviews</h1>
        <p class="text-slate-500 mt-1">Approve, Request Changes, or Reject submissions</p>
      </div>
    </div>

    <?php if ($errorMsg): ?>
      <div class="mb-6 p-4 rounded-lg ring-1 ring-red-200 bg-red-50 text-red-800">Error: <?php echo htmlspecialchars($errorMsg); ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <section class="rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold text-slate-900 mb-4">Pending</h2>
        <ul class="divide-y divide-slate-100" id="pendingList">
          <?php if (empty($pending)): ?>
            <li class="py-4 text-slate-500">No pending reviews.</li>
          <?php else: foreach ($pending as $r): ?>
            <li class="py-4">
              <div class="flex items-start justify-between gap-4">
                <div>
                  <div class="font-medium text-slate-900"><?php echo htmlspecialchars($r['milestone_name'] ?? 'Design Review'); ?></div>
                  <div class="text-xs text-slate-500">Project: <?php echo htmlspecialchars($r['project_name']); ?> (<?php echo htmlspecialchars($r['project_code']); ?>)</div>
                  <div class="text-xs text-slate-500">Submitted: <?php echo htmlspecialchars(date('M j, Y', strtotime($r['created_at']))); ?><?php if ($r['target_date']): ?> • Target: <?php echo htmlspecialchars(date('M j, Y', strtotime($r['target_date']))); ?><?php endif; ?></div>
                </div>
                <div class="flex items-center gap-2">
                  <button class="px-3 py-1 rounded-lg bg-green-600 text-white hover:bg-green-700" onclick="updateReview(<?php echo (int)$r['review_id']; ?>,'approved')">Approve</button>
                  <button class="px-3 py-1 rounded-lg bg-amber-600 text-white hover:bg-amber-700" onclick="updateReview(<?php echo (int)$r['review_id']; ?>,'changes_requested')">Request Changes</button>
                  <button class="px-3 py-1 rounded-lg bg-rose-600 text-white hover:bg-rose-700" onclick="updateReview(<?php echo (int)$r['review_id']; ?>,'rejected')">Reject</button>
                </div>
              </div>
            </li>
          <?php endforeach; endif; ?>
        </ul>
      </section>

      <section class="rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold text-slate-900 mb-4">Recently Reviewed</h2>
        <ul class="divide-y divide-slate-100">
          <?php if (empty($completed)): ?>
            <li class="py-4 text-slate-500">No recent reviews.</li>
          <?php else: foreach ($completed as $r): ?>
            <li class="py-3">
              <div class="flex items-center justify-between">
                <div>
                  <div class="font-medium text-slate-900"><?php echo htmlspecialchars($r['milestone_name'] ?? 'Design Review'); ?></div>
                  <div class="text-xs text-slate-500">Project: <?php echo htmlspecialchars($r['project_name']); ?> (<?php echo htmlspecialchars($r['project_code']); ?>)</div>
                </div>
                <div class="text-sm">
                  <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo htmlspecialchars(($r['status']==='approved')?'bg-green-100 text-green-800':(($r['status']==='changes_requested')?'bg-amber-100 text-amber-800':'bg-rose-100 text-rose-800')); ?>"><?php echo htmlspecialchars(str_replace('_',' ',$r['status'])); ?></span>
                </div>
              </div>
            </li>
          <?php endforeach; endif; ?>
        </ul>
      </section>
    </div>
  </div>
</main>
<script>
async function updateReview(reviewId, status) {
  const comments = (status === 'changes_requested' || status === 'rejected') ? prompt('Add comments (optional):') : '';
  const form = new FormData();
  form.append('action','updateReviewStatus');
  form.append('review_id', String(reviewId));
  form.append('status', status);
  form.append('comments', comments || '');
  const res = await fetch('backend/reviews.php', { method: 'POST', body: form });
  const data = await res.json().catch(() => ({}));
  if (data.success) { location.reload(); } else { alert(data.message || 'Failed to update'); }
}
</script>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

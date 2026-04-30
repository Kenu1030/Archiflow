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
$employeeId = $e ? (int)$e['employee_id'] : 0;
$rows = [];$err='';
try {
  $stmt = $db->prepare("SELECT m.milestone_id, m.project_id, m.milestone_name, m.target_date, p.project_name, p.project_code
                           FROM milestones m JOIN projects p ON p.project_id=m.project_id
                           WHERE p.architect_id = ? AND m.completion_date IS NULL ORDER BY (m.target_date IS NULL), m.target_date ASC, m.milestone_id DESC");
  $stmt->execute([$employeeId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) { $err = $ex->getMessage(); }
include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
  <div class="max-w-full">
    <h1 class="text-2xl font-bold mb-4">Milestones</h1>
  <?php if ($err): ?><div class="mb-4 p-3 bg-red-50 text-red-700 ring-1 ring-red-200 rounded"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <div class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200">
      <ul class="divide-y divide-gray-100">
        <?php if (!$rows): ?>
          <li class="p-4 text-gray-500">No upcoming milestones.</li>
        <?php else: foreach ($rows as $m): ?>
          <li class="p-4">
            <div class="flex items-center justify-between">
              <div>
                <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($m['milestone_name']); ?></div>
                <div class="text-xs text-gray-500">Project: <?php echo htmlspecialchars($m['project_name']); ?> (<?php echo htmlspecialchars($m['project_code']); ?>)</div>
              </div>
              <div class="text-gray-700"><?php echo $m['target_date'] ? htmlspecialchars(date('M j, Y', strtotime($m['target_date']))) : '—'; ?></div>
            </div>
            <div class="mt-3 flex items-center gap-2">
              <select class="rounded-lg border-slate-300" data-reviewers-for="<?php echo (int)$m['project_id']; ?>"></select>
              <button class="px-3 py-1 rounded-lg bg-slate-900 text-white hover:bg-slate-800" onclick="requestReview(<?php echo (int)$m['project_id']; ?>, <?php echo (int)$m['milestone_id']; ?>, this)">Request Review</button>
            </div>
          </li>
        <?php endforeach; endif; ?>
      </ul>
    </div>
  </div>
</main>
<script>
// load reviewers for each project row
document.addEventListener('DOMContentLoaded', async () => {
  const selects = Array.from(document.querySelectorAll('select[data-reviewers-for]'));
  const grouped = {};
  selects.forEach(s => { const pid = s.getAttribute('data-reviewers-for'); grouped[pid] = s; });
  for (const pid of Object.keys(grouped)) {
    const form = new FormData(); form.append('action','listProjectAssignments'); form.append('project_id', pid);
  const res = await fetch('backend/projects.php', { method:'POST', body: form });
    const data = await res.json().catch(()=>({}));
    const sel = grouped[pid]; sel.innerHTML = '';
    const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent='Select Senior Architect'; sel.appendChild(opt0);
    if (data.success && Array.isArray(data.data)) {
      data.data.forEach(a => {
        const o = document.createElement('option'); o.value = a.employee_id; o.textContent = `${a.first_name} ${a.last_name} (${a.role})`; sel.appendChild(o);
      });
    }
  }
});

async function requestReview(projectId, milestoneId, btn){
  const select = btn.previousElementSibling;
  const reviewerId = select && select.value ? parseInt(select.value,10) : 0;
  if (!reviewerId) { alert('Select a Senior Architect first.'); return; }
  const form = new FormData();
  form.append('action','createReview');
  form.append('project_id', String(projectId));
  form.append('milestone_id', String(milestoneId));
  form.append('reviewer_id', String(reviewerId));
  const res = await fetch('backend/reviews.php', { method:'POST', body: form });
  const data = await res.json().catch(()=>({}));
  if (data.success) { alert('Review requested'); } else { alert(data.message || 'Failed'); }
}
</script>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

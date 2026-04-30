<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../backend/connection/connect.php';

// Guard: admin or project manager
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../../login.php'); exit; }
$allowed = ($_SESSION['user_type'] ?? '') === 'admin' || (($_SESSION['user_type'] ?? '') === 'employee' && strtolower((string)($_SESSION['position'] ?? '')) === 'project_manager');
if (!$allowed) { header('Location: ../../index.php'); exit; }

$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$project = null;
$assignments = [];
$architects = [];
$errorMsg = '';

try {
  if ($projectId) {
    $stmt = $db->prepare('SELECT project_id, project_name, project_code FROM projects WHERE project_id=?');
    $stmt->execute([$projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
  }
  // list senior architects
  $stmt = $db->query("SELECT e.employee_id, u.first_name, u.last_name FROM employees e JOIN users u ON u.user_id=e.user_id WHERE e.position='senior_architect' AND (e.status IS NULL OR e.status='active') ORDER BY u.first_name, u.last_name");
  $architects = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if ($projectId) {
    $stmt = $db->prepare("SELECT psa.employee_id, psa.role, u.first_name, u.last_name, psa.assigned_at
                          FROM project_senior_architects psa
                          JOIN employees e ON e.employee_id=psa.employee_id
                          JOIN users u ON u.user_id=e.user_id
                          WHERE psa.project_id=?");
    $stmt->execute([$projectId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) { $errorMsg = $e->getMessage(); }

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-50">
  <div class="max-w-full px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-6">
      <h1 class="text-2xl sm:text-3xl font-bold text-slate-900">Assign Senior Architects</h1>
      <p class="text-slate-500 mt-1">Select project and assign Senior Architects as Lead, Reviewer, or Advisor.</p>
    </div>

    <?php if ($errorMsg): ?><div class="mb-6 p-4 rounded-lg ring-1 ring-red-200 bg-red-50 text-red-800"><?php echo htmlspecialchars($errorMsg); ?></div><?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <section class="rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm lg:col-span-1">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Choose Project</h2>
        <form class="space-y-3" method="get">
          <input type="hidden" name="" value="">
          <label class="block text-sm text-slate-600">Project ID</label>
          <input type="number" class="w-full rounded-lg border-slate-300" name="project_id" value="<?php echo $projectId ?: ''; ?>" placeholder="Enter project ID">
          <button class="px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800">Load</button>
        </form>
        <?php if ($project): ?>
          <div class="mt-4 text-sm text-slate-700">
            <div class="font-medium"><?php echo htmlspecialchars($project['project_name']); ?></div>
            <div class="text-slate-500"><?php echo htmlspecialchars($project['project_code']); ?></div>
          </div>
        <?php endif; ?>
      </section>

      <section class="rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Assignments</h2>
        <?php if (!$projectId): ?>
          <p class="text-slate-500">Load a project to manage assignments.</p>
        <?php else: ?>
          <div class="flex items-end gap-3 mb-4">
            <div class="flex-1">
              <label class="block text-sm text-slate-600">Senior Architect</label>
              <select id="saEmployee" class="w-full rounded-lg border-slate-300">
                <option value="">Select...</option>
                <?php foreach ($architects as $a): ?>
                  <option value="<?php echo (int)$a['employee_id']; ?>"><?php echo htmlspecialchars($a['first_name'].' '.$a['last_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-sm text-slate-600">Role</label>
              <select id="saRole" class="rounded-lg border-slate-300">
                <option value="lead">Lead</option>
                <option value="reviewer">Reviewer</option>
                <option value="advisor">Advisor</option>
              </select>
            </div>
            <div>
              <button onclick="assignSA()" class="px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">Assign</button>
            </div>
          </div>

          <div class="overflow-x-auto -mx-4 sm:mx-0">
            <table class="min-w-full divide-y divide-slate-200">
              <thead>
                <tr class="text-left text-xs font-medium uppercase tracking-wider text-slate-500">
                  <th class="py-3 pr-3 pl-4">Senior Architect</th>
                  <th class="py-3 px-3">Role</th>
                  <th class="py-3 px-3">Assigned</th>
                  <th class="py-3 pl-3 pr-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100" id="assignmentsBody">
                <?php if (empty($assignments)): ?>
                  <tr><td colspan="4" class="py-6 text-center text-slate-500">No assignments yet.</td></tr>
                <?php else: foreach ($assignments as $as): ?>
                  <tr>
                    <td class="py-3 pr-3 pl-4"><?php echo htmlspecialchars($as['first_name'].' '.$as['last_name']); ?></td>
                    <td class="py-3 px-3 capitalize"><?php echo htmlspecialchars($as['role']); ?></td>
                    <td class="py-3 px-3"><?php echo htmlspecialchars(date('M j, Y', strtotime($as['assigned_at']))); ?></td>
                    <td class="py-3 pl-3 pr-4 text-right">
                      <button class="px-3 py-1 rounded-lg bg-rose-600 text-white hover:bg-rose-700" onclick="removeSA(<?php echo (int)$projectId; ?>, <?php echo (int)$as['employee_id']; ?>)">Remove</button>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </div>
</main>
<script>
async function assignSA(){
  const employeeId = (document.getElementById('saEmployee') || {}).value;
  const role = (document.getElementById('saRole') || {}).value;
  if(!employeeId){ alert('Select a Senior Architect'); return; }
  const form = new FormData();
  form.append('action','assignSeniorArchitect');
  form.append('project_id','<?php echo (int)$projectId; ?>');
  form.append('employee_id', employeeId);
  form.append('role', role);
  const res = await fetch('backend/projects.php', { method:'POST', body: form });
  const data = await res.json().catch(()=>({}));
  if(data.success){ location.reload(); } else { alert(data.message||'Failed'); }
}
async function removeSA(projectId, employeeId){
  if(!confirm('Remove assignment?')) return;
  const form = new FormData();
  form.append('action','removeSeniorArchitect');
  form.append('project_id', String(projectId));
  form.append('employee_id', String(employeeId));
  const res = await fetch('backend/projects.php', { method:'POST', body: form });
  const data = await res.json().catch(()=>({}));
  if(data.success){ location.reload(); } else { alert(data.message||'Failed'); }
}
</script>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

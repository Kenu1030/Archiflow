<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$allowed_roles = ['senior_architect'];
include __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function col_exists(PDO $db, string $table, string $col): bool {
  $q = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $q->execute([$table, $col]);
  return (int)$q->fetchColumn() > 0;
}

function sa_oversees_project(PDO $db, int $userId, int $projectId): bool {
  try {
    $stmt = $db->prepare('SELECT e.employee_id FROM employees e WHERE e.user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC); if(!$emp) return false;
    $empId = (int)$emp['employee_id'];
    $chk = $db->prepare('SELECT 1 FROM project_senior_architects WHERE project_id=? AND employee_id=? LIMIT 1');
    $chk->execute([$projectId, $empId]);
    return (bool)$chk->fetchColumn();
  } catch (Throwable $e) { return false; }
}

// Fetch PM candidates (users who appear to be project managers)
function fetch_pm_candidates(PDO $db): array {
  $cols = [];
  try { foreach($db->query('SHOW COLUMNS FROM users') as $c){ $cols[$c['Field']] = true; } } catch (Throwable $e) {}
  $idCol = isset($cols['id']) ? 'id' : (isset($cols['user_id']) ? 'user_id' : 'id');
  $nameExpr = isset($cols['full_name']) ? 'full_name' : (isset($cols['username']) ? 'username' : (isset($cols['email']) ? 'email' : $idCol));
  $conds = [];
  if (isset($cols['position'])) {
    $conds[] = "LOWER(REPLACE(REPLACE(position,'_',' '),'-',' ')) LIKE '%project manager%'";
    $conds[] = "LOWER(position) IN ('project_manager','project manager','pm')";
  }
  if (isset($cols['role'])) { $conds[] = "LOWER(role) IN ('project_manager','pm','project manager')"; }
  if (isset($cols['user_type']) && isset($cols['position'])) {
    $conds[] = "(LOWER(user_type)='employee' AND LOWER(REPLACE(REPLACE(position,'_',' '),'-',' ')) LIKE '%project manager%')";
  } elseif (isset($cols['user_type'])) { $conds[] = "LOWER(user_type) IN ('project_manager','pm')"; }
  $where = $conds ? ('(' . implode(' OR ', $conds) . ')') : '1=1';
  $sql = "SELECT $idCol AS id, $nameExpr AS full_name FROM users WHERE $where ORDER BY full_name LIMIT 500";
  try { return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
}

$pid = (int)($_GET['project_id'] ?? 0);
if ($pid <= 0) { header('Location: projects.php'); exit; }
if (!sa_oversees_project($db, (int)$_SESSION['user_id'], $pid)) { header('Location: projects.php'); exit; }

// Load project
$stmt = $db->prepare('SELECT * FROM projects WHERE project_id = ? LIMIT 1');
$stmt->execute([$pid]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$project){ header('Location: projects.php'); exit; }

$errors = [];$saved=false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_project'])) {
  $updCols = [];$vals=[];
  $fields = [
    'project_name' => FILTER_SANITIZE_STRING,
    'project_type' => FILTER_SANITIZE_STRING,
    'description' => FILTER_UNSAFE_RAW,
    'location' => FILTER_SANITIZE_STRING,
    'location_text' => FILTER_SANITIZE_STRING,
    'status' => FILTER_SANITIZE_STRING,
    'start_date' => FILTER_SANITIZE_STRING,
    'estimated_end_date' => FILTER_SANITIZE_STRING,
    // Keep legacy fields present if posted by older forms (ignored if absent)
    'budget' => FILTER_SANITIZE_NUMBER_FLOAT,
    'budget_amount' => FILTER_SANITIZE_NUMBER_FLOAT,
    // Unified budget input from this editor
    'budget_combined' => FILTER_UNSAFE_RAW,
    'size_sq_m' => FILTER_SANITIZE_NUMBER_FLOAT,
    'project_manager_id' => FILTER_SANITIZE_NUMBER_INT
  ];
  $in = filter_input_array(INPUT_POST, $fields, true) ?: [];
  // Normalize numbers
  if (isset($in['budget'])) { $in['budget'] = $in['budget'] !== '' ? preg_replace('/[^0-9.\-]/','',$in['budget']) : null; }
  if (isset($in['budget_amount'])) { $in['budget_amount'] = $in['budget_amount'] !== '' ? preg_replace('/[^0-9.\-]/','',$in['budget_amount']) : null; }
  if (isset($in['size_sq_m'])) { $in['size_sq_m'] = $in['size_sq_m'] !== '' ? preg_replace('/[^0-9.\-]/','',$in['size_sq_m']) : null; }
  if (isset($in['budget_combined'])) { $in['budget_combined'] = $in['budget_combined'] !== '' ? preg_replace('/[^0-9.\-]/','',$in['budget_combined']) : null; }

  // Allowed statuses and project types
  $allowedStatuses = ['planned','planning','design','construction','active','on_hold','completed','cancelled'];
  $allowedTypes = ['design_only','fit_out'];

  // Handle updates
  // Unified budget mapping: if budget_combined is provided, set to whichever columns exist
  if (array_key_exists('budget_combined', $in) && $in['budget_combined'] !== null && $in['budget_combined'] !== '') {
    if (col_exists($db,'projects','budget')) { $updCols[] = "budget = ?"; $vals[] = $in['budget_combined']; }
    if (col_exists($db,'projects','budget_amount')) { $updCols[] = "budget_amount = ?"; $vals[] = $in['budget_combined']; }
  }
  foreach ($in as $k=>$v) {
    // Special case for project manager mapping
    if ($k === 'project_manager_id') {
      $v = (int)$v;
      if ($v <= 0) { continue; }
      $pmCol = null;
      if (col_exists($db,'projects','project_manager_id')) { $pmCol = 'project_manager_id'; }
      elseif (col_exists($db,'projects','manager_id')) { $pmCol = 'manager_id'; }
      if ($pmCol) { $updCols[] = "$pmCol = ?"; $vals[] = $v; }
      continue;
    }

    if ($v === '' || $v === null) continue;
    if ($k === 'budget_combined') { continue; } // already handled above
    if ($k === 'status' && !in_array($v, $allowedStatuses, true)) continue;
    if ($k === 'project_type') {
      $v = strtolower(trim($v));
      // map friendly 'fit in' to 'fit_out'
      if ($v === 'fit in' || $v === 'fit_in') { $v = 'fit_out'; }
      if (!in_array($v, $allowedTypes, true)) continue;
    }
    if (col_exists($db,'projects',$k)) { $updCols[] = "$k = ?"; $vals[] = $v; }
  }

  if ($updCols) {
    $vals[] = $pid;
    $sql = 'UPDATE projects SET ' . implode(', ',$updCols) . ' WHERE project_id = ?';
    try {
      $db->prepare($sql)->execute($vals);
      $saved=true;

      // Link PM in project_users if provided
      $pmIdPosted = (int)($in['project_manager_id'] ?? 0);
      if ($pmIdPosted > 0) {
        try {
          $db->exec("CREATE TABLE IF NOT EXISTS project_users (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, user_id INT NOT NULL, role_in_project VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(project_id), INDEX(user_id)) ENGINE=InnoDB");
          $chk = $db->prepare('SELECT COUNT(*) FROM project_users WHERE project_id=? AND user_id=? AND role_in_project = "project_manager"');
          $chk->execute([$pid, $pmIdPosted]);
          if ((int)$chk->fetchColumn() === 0) {
            $ins = $db->prepare('INSERT INTO project_users (project_id, user_id, role_in_project) VALUES (?,?, "project_manager")');
            $ins->execute([$pid, $pmIdPosted]);
          }
        } catch (Throwable $e) {
          // ignore linking errors
        }
      }
    } catch (Throwable $e) {
      $errors[] = $e->getMessage();
    }
  } else {
    $errors[] = 'No updatable fields provided.';
  }

  // Reload project after save
  $stmt = $db->prepare('SELECT * FROM projects WHERE project_id = ? LIMIT 1');
  $stmt->execute([$pid]);
  $project = $stmt->fetch(PDO::FETCH_ASSOC) ?: $project;
}

// Prepare PM dropdown data
$pmCandidates = fetch_pm_candidates($db);
$pmCurrent = isset($project['project_manager_id']) ? (int)$project['project_manager_id'] : (isset($project['manager_id']) ? (int)$project['manager_id'] : 0);

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-50 p-6">
  <div class="max-w-3xl mx-auto">
    <div class="mb-6">
      <h1 class="text-2xl font-bold">Edit Project</h1>
      <p class="text-slate-600">Project ID: <?php echo (int)$pid; ?></p>
      <?php if($saved): ?>
        <div class="mt-3 p-3 rounded-md bg-green-50 text-green-700 ring-1 ring-green-200">Project saved.</div>
      <?php endif; ?>
      <?php if($errors): ?>
        <div class="mt-3 p-3 rounded-md bg-red-50 text-red-700 ring-1 ring-red-200"><?php echo htmlspecialchars(implode("\n",$errors)); ?></div>
      <?php endif; ?>
    </div>
    <form method="post" class="bg-white p-6 rounded-xl ring-1 ring-slate-200 shadow-sm space-y-4">
      <div class="grid md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-slate-500 mb-1">Project Name</label>
          <input name="project_name" class="w-full px-3 py-2 border rounded-lg" value="<?php echo htmlspecialchars($project['project_name'] ?? ''); ?>">
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-500 mb-1">Type</label>
          <select name="project_type" class="w-full px-3 py-2 border rounded-lg">
            <?php $ptype = strtolower((string)($project['project_type'] ?? '')); ?>
            <option value="design_only" <?php echo ($ptype==='design_only')?'selected':'';?>>Design Only</option>
            <option value="fit_out" <?php echo ($ptype==='fit_out' || $ptype==='fit in' || $ptype==='fit_in')?'selected':'';?>>Fit In</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-500 mb-1">Status</label>
          <input name="status" class="w-full px-3 py-2 border rounded-lg" value="<?php echo htmlspecialchars($project['status'] ?? ''); ?>">
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-500 mb-1">Start Date</label>
          <input type="date" name="start_date" class="w-full px-3 py-2 border rounded-lg" value="<?php echo htmlspecialchars($project['start_date'] ?? ''); ?>">
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-500 mb-1">Estimated End Date</label>
          <input type="date" name="estimated_end_date" class="w-full px-3 py-2 border rounded-lg" value="<?php echo htmlspecialchars($project['estimated_end_date'] ?? ''); ?>">
        </div>
        <?php if (col_exists($db,'projects','size_sq_m')): ?>
        <div>
          <label class="block text-xs font-semibold text-slate-500 mb-1">Size (sq m)</label>
          <input name="size_sq_m" class="w-full px-3 py-2 border rounded-lg" value="<?php echo htmlspecialchars($project['size_sq_m'] ?? ''); ?>">
        </div>
        <?php endif; ?>
        <?php if (col_exists($db,'projects','budget') || col_exists($db,'projects','budget_amount')): ?>
        <?php 
          $budgetVal = '';
          if (col_exists($db,'projects','budget') && isset($project['budget']) && $project['budget'] !== '') { $budgetVal = (string)$project['budget']; }
          elseif (col_exists($db,'projects','budget_amount') && isset($project['budget_amount']) && $project['budget_amount'] !== '') { $budgetVal = (string)$project['budget_amount']; }
        ?>
        <div>
          <label class="block text-xs font-semibold text-slate-500 mb-1">Budget</label>
          <input name="budget_combined" class="w-full px-3 py-2 border rounded-lg" value="<?php echo htmlspecialchars($budgetVal); ?>" placeholder="e.g., 500000">
        </div>
        <?php endif; ?>
        <?php if (col_exists($db,'projects','location')): ?>
        <div>
          <label class="block text-xs font-semibold text-slate-500 mb-1">Location</label>
          <input name="location" class="w-full px-3 py-2 border rounded-lg" value="<?php echo htmlspecialchars($project['location'] ?? ''); ?>">
        </div>
        <?php endif; ?>
        <?php if (col_exists($db,'projects','location_text')): ?>
        <div>
          <label class="block text-xs font-semibold text-slate-500 mb-1">Location (Text)</label>
          <input name="location_text" class="w-full px-3 py-2 border rounded-lg" value="<?php echo htmlspecialchars($project['location_text'] ?? ''); ?>">
        </div>
        <?php endif; ?>
      </div>
      <?php if (col_exists($db,'projects','project_manager_id') || col_exists($db,'projects','manager_id')): ?>
      <div>
        <label class="block text-xs font-semibold text-slate-500 mb-1">Project Manager</label>
        <select name="project_manager_id" class="w-full px-3 py-2 border rounded-lg">
          <option value="">-- Select Project Manager --</option>
          <?php foreach($pmCandidates as $pm): ?>
            <option value="<?php echo (int)$pm['id']; ?>" <?php echo ((int)$pm['id'] === $pmCurrent) ? 'selected' : ''; ?>><?php echo htmlspecialchars($pm['full_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div>
        <label class="block text-xs font-semibold text-slate-500 mb-1">Description</label>
        <textarea name="description" rows="6" class="w-full px-3 py-2 border rounded-lg"><?php echo htmlspecialchars($project['description'] ?? ''); ?></textarea>
      </div>
      <div class="flex justify-end gap-2">
        <a href="projects.php" class="px-4 py-2 border rounded-lg">Cancel</a>
        <button name="save_project" class="px-4 py-2 bg-indigo-600 text-white rounded-lg">Save Changes</button>
      </div>
    </form>
  </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

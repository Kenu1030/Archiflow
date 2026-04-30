<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$allowed_roles = ['senior_architect'];
include __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Lightweight JSON endpoint to show PM workload count
if (isset($_GET['action']) && $_GET['action'] === 'pm_load') {
  header('Content-Type: application/json');
  $uid = (int)($_GET['user_id'] ?? 0);
  try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM projects WHERE project_manager_id = ?");
    $stmt->execute([$uid]);
    $count = (int)$stmt->fetchColumn();
    echo json_encode(['count' => $count]);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => 'Failed to load workload']);
  }
  exit;
}

// Ensure projects table (add columns if missing) & extend schema
try {
  $db->exec("CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_name VARCHAR(255) NOT NULL,
    project_type ENUM('design_only','fit_out') DEFAULT 'design_only',
    description TEXT NULL,
    client_id INT NULL,
    start_date DATE NULL,
    status ENUM('planned','active','on_hold','completed','cancelled') DEFAULT 'planned',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  size_sq_m DECIMAL(10,2) NULL,
  location_text VARCHAR(255) NULL,
  estimated_end_date DATE NULL,
  budget_amount DECIMAL(14,2) NULL,
    INDEX (client_id),
    INDEX (created_by)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  // Conditionally add columns (for existing installs that lack them)
  // Add columns without relying on AFTER positioning to avoid missing-anchor errors
  $needed = [
    'project_manager_id' => "ALTER TABLE projects ADD COLUMN project_manager_id INT NULL",
    'created_by' => "ALTER TABLE projects ADD COLUMN created_by INT NULL",
    'size_sq_m' => "ALTER TABLE projects ADD COLUMN size_sq_m DECIMAL(10,2) NULL",
    'location_text' => "ALTER TABLE projects ADD COLUMN location_text VARCHAR(255) NULL",
    'estimated_end_date' => "ALTER TABLE projects ADD COLUMN estimated_end_date DATE NULL",
    'budget_amount' => "ALTER TABLE projects ADD COLUMN budget_amount DECIMAL(14,2) NULL"
  ];
  foreach ($needed as $col => $ddl) {
    // Use information_schema to check column existence; SHOW with placeholders isn't supported in prepared statements
    $chk = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = ?");
    $chk->execute([$col]);
    $exists = (int)$chk->fetchColumn();
    if ($exists === 0) {
      $db->exec($ddl);
    }
  }
} catch (Throwable $e) { $schema_error = $e->getMessage(); }

$errors = [];$success=false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['project_name'] ?? '');
  $type = $_POST['project_type'] ?? 'design_only';
  $client_id = (int)($_POST['client_id'] ?? 0);
  $pm_id = (int)($_POST['project_manager_id'] ?? 0);
  $start_date = $_POST['start_date'] ?? null;
  $description = trim($_POST['description'] ?? '');
  $size_sq_m = trim($_POST['size_sq_m'] ?? '');
  $location_text = trim($_POST['location_text'] ?? '');
  $estimated_end_date = trim($_POST['estimated_end_date'] ?? '');
  $budget_amount = trim($_POST['budget_amount'] ?? '');
  // phase removed per request
  if ($name === '') { $errors[] = 'Project name is required'; }
  if (!in_array($type, ['design_only','fit_out'], true)) { $errors[] = 'Invalid project type'; }
  if ($start_date && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$start_date)) { $errors[] = 'Invalid date format'; }
  if ($size_sq_m !== '' && !is_numeric($size_sq_m)) { $errors[] = 'Project size must be a number.'; }
  if ($budget_amount !== '' && !is_numeric($budget_amount)) { $errors[] = 'Budget must be numeric.'; }
  if ($estimated_end_date && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$estimated_end_date)) { $errors[] = 'Invalid estimated end date format'; }
  if ($client_id <= 0) { $errors[] = 'Please select a Client.'; }
  if ($pm_id <= 0) { $errors[] = 'Please select a Project Manager.'; }
  // phase validation removed

  if (!$errors) {
    // Validate client exists when clients table is present to avoid FK violations
    try {
      if (af_table_exists($db, 'clients')) {
        $chk = $db->prepare("SELECT COUNT(*) FROM clients WHERE client_id = ?");
        $chk->execute([$client_id]);
        if ((int)$chk->fetchColumn() === 0) {
          $errors[] = 'Selected client not found. Please choose a valid client.';
        }
      }
    } catch (Throwable $e) {
      // Continue; DB will enforce if FK exists
    }
  }

  if (!$errors) {
    try {
      $stmt = $db->prepare("INSERT INTO projects (project_name, project_type, description, client_id, project_manager_id, start_date, created_by, size_sq_m, location_text, estimated_end_date, budget_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
      $stmt->execute([
        $name,
        $type,
        $description ?: null,
        $client_id ?: null,
        $pm_id ?: null,
        $start_date ?: null,
        $_SESSION['user_id'],
        $size_sq_m !== '' ? $size_sq_m : null,
        $location_text !== '' ? $location_text : null,
        $estimated_end_date !== '' ? $estimated_end_date : null,
        $budget_amount !== '' ? $budget_amount : null
      ]);
      $project_id = $db->lastInsertId();
      // Optionally auto-link senior architect as architect in project_users
      $db->exec("CREATE TABLE IF NOT EXISTS project_users (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, user_id INT NOT NULL, role_in_project VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(project_id), INDEX(user_id)) ENGINE=InnoDB");
      $link = $db->prepare("INSERT INTO project_users (project_id, user_id, role_in_project) VALUES (?,?,?)");
      $link->execute([$project_id, $_SESSION['user_id'], 'architect']);
      if ($pm_id) {
        $link->execute([$project_id, $pm_id, 'project_manager']);
      }
      // Also ensure overseen assignment for Senior Architect (project_senior_architects)
      try {
        // Create PSA table if missing
        $db->exec("CREATE TABLE IF NOT EXISTS project_senior_architects (
          psa_id INT AUTO_INCREMENT PRIMARY KEY,
          project_id INT NOT NULL,
          employee_id INT NOT NULL,
          role ENUM('lead','reviewer','advisor') NOT NULL DEFAULT 'advisor',
          assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX(project_id), INDEX(employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Resolve employee_id for current user
        $empId = null;
        if (af_table_exists($db, 'employees')) {
          $stmtEmp = $db->prepare('SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1');
          $stmtEmp->execute([$_SESSION['user_id']]);
          $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);
          if ($emp && isset($emp['employee_id'])) {
            $empId = (int)$emp['employee_id'];
          } else {
            // Attempt to auto-provision minimal employee record (best-effort)
            try {
              $empCode = 'EMP-' . (int)$_SESSION['user_id'];
              $ins = $db->prepare("INSERT INTO employees (user_id, employee_code, position, department, hire_date, salary, status) VALUES (?, ?, 'senior_architect', 'Architecture', CURDATE(), 0.00, 'active')");
              $ins->execute([$_SESSION['user_id'], $empCode]);
              $stmtEmp->execute([$_SESSION['user_id']]);
              $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);
              if ($emp && isset($emp['employee_id'])) { $empId = (int)$emp['employee_id']; }
            } catch (Throwable $ie) { /* ignore */ }
          }
        }

        if ($empId) {
          // Avoid duplicate assignment
          $chk = $db->prepare('SELECT COUNT(*) FROM project_senior_architects WHERE project_id = ? AND employee_id = ?');
          $chk->execute([$project_id, $empId]);
          if ((int)$chk->fetchColumn() === 0) {
            $psaIns = $db->prepare('INSERT INTO project_senior_architects (project_id, employee_id, role) VALUES (?,?,\'lead\')');
            $psaIns->execute([$project_id, $empId]);
          }
        }
      } catch (Throwable $ie) { /* overseen assignment is best-effort; ignore on failure */ }
      $success = true;
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
  }
}

// Helpers to adapt to varying users schema
function af_column_exists(PDO $db, string $table, string $column): bool {
  $q = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $q->execute([$table, $column]);
  return (int)$q->fetchColumn() > 0;
}
function af_table_exists(PDO $db, string $table): bool {
  $q = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  $q->execute([$table]);
  return (int)$q->fetchColumn() > 0;
}
function af_detect_user_id_col(PDO $db): string { return af_column_exists($db,'users','id') ? 'id' : (af_column_exists($db,'users','user_id') ? 'user_id' : 'id'); }
function af_detect_user_name_expr(PDO $db): string {
  if (af_column_exists($db,'users','full_name')) return 'full_name';
  $hasFirst = af_column_exists($db,'users','first_name');
  $hasLast  = af_column_exists($db,'users','last_name');
  if ($hasFirst && $hasLast) return "CONCAT(first_name, ' ', last_name)";
  if (af_column_exists($db,'users','username')) return 'username';
  if (af_column_exists($db,'users','email')) return 'email';
  return "''"; // fallback empty
}
/** Ensure every user with user_type=client has a corresponding row in clients. Safe no-op if table/columns absent. */
function af_autoprovision_clients(PDO $db): void {
  if (!af_table_exists($db, 'clients')) return;
  // Require clients.user_id column to link
  if (!af_column_exists($db, 'clients', 'user_id')) return;
  $userIdCol = af_column_exists($db,'users','user_id') ? 'user_id' : (af_column_exists($db,'users','id') ? 'id' : null);
  if ($userIdCol === null) return;
  try {
    // Insert minimal client rows for any client users missing in clients table
    $sql = "INSERT INTO clients (user_id)
            SELECT u.$userIdCol FROM users u
            WHERE LOWER(u.user_type) = 'client'
              AND NOT EXISTS (SELECT 1 FROM clients c WHERE c.user_id = u.$userIdCol)";
    $db->exec($sql);
  } catch (Throwable $e) {
    // best-effort only
  }
}
function af_fetch_pms(PDO $db): array {
  $idCol = af_detect_user_id_col($db);
  $nameExpr = af_detect_user_name_expr($db);
  $conds = [];
  if (af_column_exists($db,'users','position')) {
    // Normalize underscores/dashes to spaces for tolerant matching
    $conds[] = "LOWER(REPLACE(REPLACE(position, '_', ' '), '-', ' ')) LIKE '%project manager%'";
    // Also allow direct equality for common encodings
    $conds[] = "LOWER(position) IN ('project_manager','project manager','pm')";
  }
  if (af_column_exists($db,'users','role')) {
    $conds[] = "LOWER(role) IN ('project_manager','pm','project manager')";
  }
  if (af_column_exists($db,'users','user_type') && af_column_exists($db,'users','position')) {
    // Typical schema: user_type='employee' and position='project_manager'
    $conds[] = "(LOWER(user_type) = 'employee' AND LOWER(REPLACE(REPLACE(position, '_',' '),'-',' ')) LIKE '%project manager%')";
  } elseif (af_column_exists($db,'users','user_type')) {
    $conds[] = "LOWER(user_type) IN ('project_manager','pm')";
  }
  $where = $conds ? implode(' OR ', $conds) : '1=1';
  $sql = "SELECT $idCol AS id, $nameExpr AS full_name FROM users WHERE $where ORDER BY full_name LIMIT 500";
  return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function af_fetch_clients(PDO $db): array {
  $idCol = af_detect_user_id_col($db);
  $nameExpr = af_detect_user_name_expr($db);
  // Prefer a dedicated clients table if it exists
  if (af_table_exists($db, 'clients')) {
    // Be robust to environments where clients.user_id may reference users.user_id or users.id.
    $hasUserId = af_column_exists($db, 'users', 'user_id');
    $hasId = af_column_exists($db, 'users', 'id');
    // Build a JOIN condition that tolerates either mapping when possible.
    if ($hasUserId && $hasId) {
      $joinCond = "(c.user_id = u.user_id OR c.user_id = u.id)";
    } elseif ($hasUserId) {
      $joinCond = "c.user_id = u.user_id";
    } else {
      $joinCond = "c.user_id = u.id";
    }
    // IMPORTANT: use clients.client_id as the identifier to satisfy FK
    $sql = "SELECT c.client_id AS id, $nameExpr AS full_name FROM clients c JOIN users u ON $joinCond ORDER BY full_name LIMIT 500";
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  }
  $where = '1=1';
  if (af_column_exists($db,'users','role')) {
    $where = "LOWER(role) IN ('client','customer')";
  } elseif (af_column_exists($db,'users','user_type')) {
    $where = "LOWER(user_type) IN ('client','customer')";
  } elseif (af_column_exists($db,'users','position')) {
    $where = "LOWER(position) LIKE '%client%'";
  }
  $sql = "SELECT $idCol AS id, $nameExpr AS full_name FROM users WHERE $where ORDER BY full_name LIMIT 500";
  return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch lists for dropdowns
$project_managers = [];
$clients = [];
try { af_autoprovision_clients($db); } catch (Throwable $e) {}
try { $project_managers = af_fetch_pms($db); } catch (Throwable $e) {}
try { $clients = af_fetch_clients($db); } catch (Throwable $e) {}

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-blue-50 p-6">
  <div class="max-w-3xl mx-auto">
    <h1 class="text-3xl font-bold mb-4">Create Project</h1>
    <p class="text-gray-600 mb-6">Register a new client project.</p>
    <?php if(isset($_GET['debug'])): ?>
      <div class="mb-4 p-4 bg-yellow-50 text-yellow-800 border border-yellow-200 rounded text-sm">
        <?php
          $dbgHasUserId = af_column_exists($db, 'users', 'user_id');
          $dbgHasId = af_column_exists($db, 'users', 'id');
          if ($dbgHasUserId && $dbgHasId) {
            $dbgJoin = 'clients.user_id = users.user_id OR clients.user_id = users.id';
          } elseif ($dbgHasUserId) {
            $dbgJoin = 'clients.user_id = users.user_id';
          } else {
            $dbgJoin = 'clients.user_id = users.id';
          }
        ?>
        <div><strong>Debug:</strong> Clients join mode: <?php echo htmlspecialchars($dbgJoin); ?></div>
        <div>Project Managers loaded: <?php echo (int)count($project_managers); ?>, Clients loaded: <?php echo (int)count($clients); ?></div>
      </div>
    <?php endif; ?>
    <?php if(!empty($schema_error)): ?>
      <div class="mb-4 p-4 bg-red-50 text-red-700 border border-red-200 rounded">Schema Error: <?php echo htmlspecialchars($schema_error); ?></div>
    <?php endif; ?>
    <?php if($success): ?>
      <div class="mb-4 p-4 bg-green-50 text-green-700 border border-green-200 rounded">Project created successfully.</div>
    <?php endif; ?>
    <?php if($errors): ?>
      <div class="mb-4 p-4 bg-red-50 text-red-700 border border-red-200 rounded">
        <ul class="list-disc list-inside text-sm">
          <?php foreach($errors as $er): ?><li><?php echo htmlspecialchars($er); ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <form method="post" class="space-y-5 bg-white rounded-xl shadow-sm ring-1 ring-slate-200 p-6">
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">Project Name<span class="text-red-500">*</span></label>
        <input name="project_name" value="<?php echo htmlspecialchars($_POST['project_name'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg" required>
      </div>
      <div class="grid md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Project Type</label>
          <select name="project_type" class="w-full px-3 py-2 border rounded-lg">
            <option value="design_only" <?php if(($_POST['project_type'] ?? '')==='design_only') echo 'selected';?>>Design Only</option>
            <option value="fit_out" <?php if(($_POST['project_type'] ?? '')==='fit_out') echo 'selected';?>>Fit Out</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Start Date</label>
          <input type="date" name="start_date" value="<?php echo htmlspecialchars($_POST['start_date'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg">
        </div>
      </div>
      <div class="grid md:grid-cols-3 gap-4">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Size (sq. m)</label>
          <input name="size_sq_m" value="<?php echo htmlspecialchars($_POST['size_sq_m'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg" placeholder="e.g. 250">
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Estimated End Date</label>
          <input type="date" name="estimated_end_date" value="<?php echo htmlspecialchars($_POST['estimated_end_date'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg">
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Budget (<?php echo htmlspecialchars('$'); ?>)</label>
          <input name="budget_amount" value="<?php echo htmlspecialchars($_POST['budget_amount'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg" placeholder="e.g. 150000">
        </div>
      </div>
      <div class="grid md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Project Manager<span class="text-red-500">*</span></label>
          <select name="project_manager_id" class="w-full px-3 py-2 border rounded-lg" required>
            <option value="">-- Select Project Manager --</option>
            <?php foreach($project_managers as $pm): ?>
              <option value="<?php echo (int)$pm['id']; ?>" <?php if(($_POST['project_manager_id'] ?? '') == $pm['id']) echo 'selected';?>><?php echo htmlspecialchars($pm['full_name']); ?></option>
            <?php endforeach; ?>
          </select>
          <p id="pm-load-indicator" class="mt-1 text-xs text-gray-500"></p>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Client<span class="text-red-500">*</span></label>
          <select name="client_id" class="w-full px-3 py-2 border rounded-lg" required>
            <option value="">-- Select Client --</option>
            <?php foreach($clients as $cl): ?>
              <option value="<?php echo (int)$cl['id']; ?>" <?php if(($_POST['client_id'] ?? '') == $cl['id']) echo 'selected';?>><?php echo htmlspecialchars($cl['full_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">Location</label>
        <input name="location_text" value="<?php echo htmlspecialchars($_POST['location_text'] ?? ''); ?>" class="w-full px-3 py-2 border rounded-lg" placeholder="City / Site">
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">Description</label>
        <textarea name="description" rows="4" class="w-full px-3 py-2 border rounded-lg"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
      </div>
      <div class="flex justify-end gap-3 pt-2">
        <a href="senior_architect_dashboard.php" class="px-4 py-2 border rounded-lg">Cancel</a>
        <button class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium">Create Project</button>
      </div>
    </form>

  </div>
</main>
<script>
  (function(){
    const pmSelect = document.querySelector('select[name="project_manager_id"]');
    const indicator = document.getElementById('pm-load-indicator');
    async function updatePmCount(){
      if(!pmSelect || !indicator) return;
      const val = pmSelect.value;
      if(!val){ indicator.textContent = ''; return; }
      indicator.textContent = 'Checking workload...';
      try {
        const res = await fetch('?action=pm_load&user_id=' + encodeURIComponent(val), { headers: { 'Accept': 'application/json' }});
        if(!res.ok) { indicator.textContent = ''; return; }
        const data = await res.json();
        const n = (data && typeof data.count !== 'undefined') ? Number(data.count) : 0;
        indicator.textContent = `Currently managing ${n} project${n===1?'':'s'}`;
      } catch(err){ indicator.textContent = ''; }
    }
    pmSelect && pmSelect.addEventListener('change', updatePmCount);
    document.addEventListener('DOMContentLoaded', updatePmCount);
  })();
</script>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

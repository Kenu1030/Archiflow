<?php
// Suppress footer on this page
$HIDE_FOOTER = true;
require_once __DIR__ . '/_client_common.php';
// Start output buffering so we can safely redirect after includes
if (!headers_sent()) { ob_start(); }
include_once __DIR__ . '/../backend/core/header.php';

if (!$clientId) {
    echo '<main class="p-6"><div class="bg-yellow-50 text-yellow-800 p-4 rounded">Your account is not linked to a client record yet.</div></main>';
    include_once __DIR__ . '/../backend/core/footer.php';
    exit;
}

// Metrics (safer prepared statements + richer insights)
$metrics = [
  'projects_total'      => 0,
  'projects_active'     => 0,
  'projects_completed'  => 0,
  'contracts_value'     => 0.0,
  'invoices_total'      => 0,
  'invoices_unpaid'     => 0,
  'invoices_outstanding'=> 0.0,
  'messages_total'      => 0,
  'messages_unread'     => 0,
  'notifications_unread'=> 0,
  'milestones_upcoming' => 0
];

// Discover project columns once (avoid repeating INFORMATION_SCHEMA calls)
$projCols = [];
try { foreach($pdo->query('SHOW COLUMNS FROM projects') as $c){ $projCols[$c['Field']] = true; } } catch(Throwable $e){}
$hasArchived = isset($projCols['is_archived']) || isset($projCols['archived']);
$hasDeleted  = isset($projCols['is_deleted'])  || isset($projCols['deleted']);
$hasStatus   = isset($projCols['status']);
// Common column name variants
$CLIENT_COL  = isset($projCols['client_id']) ? 'client_id' : (isset($projCols['clientId']) ? 'clientId' : (isset($projCols['client']) ? 'client' : null));
$CREATED_COL = isset($projCols['created_at']) ? 'created_at' : (isset($projCols['createdAt']) ? 'createdAt' : (isset($projCols['created_date']) ? 'created_date' : (isset($projCols['date_created']) ? 'date_created' : null)));
// Toggle: show extended (financial & communication) metrics blocks
$SHOW_EXTENDED_CLIENT_METRICS = false; // set true to restore Contracts / Invoices / Messages KPI grids
// Build common visibility filter for client-facing queries
$visibilityFilter = '';
if ($hasArchived) { $visibilityFilter .= isset($projCols['is_archived']) ? ' AND (is_archived = 0)' : ' AND (archived = 0)'; }
if ($hasDeleted)  { $visibilityFilter .= isset($projCols['is_deleted']) ? ' AND (is_deleted = 0 OR is_deleted IS NULL)' : ' AND (deleted = 0 OR deleted IS NULL)'; }

try {
  $clientCol = $CLIENT_COL ?: 'client_id';
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE ' . $clientCol . ' = ?' . $visibilityFilter);
  $stmt->execute([$clientId]);
  $metrics['projects_total'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
  $clientCol = $CLIENT_COL ?: 'client_id';
  $activeStatuses = "('planning','design','construction')"; // keep consistent
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE ' . $clientCol . ' = ? AND status IN ' . $activeStatuses . $visibilityFilter);
  $stmt->execute([$clientId]);
  $metrics['projects_active'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
  $clientCol = $CLIENT_COL ?: 'client_id';
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE " . $clientCol . " = ? AND status = 'completed'" . $visibilityFilter);
  $stmt->execute([$clientId]);
  $metrics['projects_completed'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
  $stmt = $pdo->prepare('SELECT COALESCE(SUM(contract_value),0) FROM contracts WHERE client_id = ?');
  $stmt->execute([$clientId]);
  $metrics['contracts_value'] = (float)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE client_id = ?');
  $stmt->execute([$clientId]);
  $metrics['invoices_total'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE client_id = ? AND (status <> 'paid' OR total_amount > paid_amount)");
  $stmt->execute([$clientId]);
  $metrics['invoices_unpaid'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
  $stmt = $pdo->prepare('SELECT COALESCE(SUM(total_amount - paid_amount),0) FROM invoices WHERE client_id = ? AND (total_amount - paid_amount) > 0');
  $stmt->execute([$clientId]);
  $metrics['invoices_outstanding'] = (float)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
  $uid = (int)($_SESSION['user_id'] ?? 0);
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ?');
  $stmt->execute([$uid]);
  $metrics['messages_total'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
  $uid = (int)($_SESSION['user_id'] ?? 0);
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0');
  $stmt->execute([$uid]);
  $metrics['messages_unread'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
  $uid = (int)($_SESSION['user_id'] ?? 0);
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
  $stmt->execute([$uid]);
  $metrics['notifications_unread'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM milestones m JOIN projects p ON p.project_id = m.project_id WHERE p.client_id = ? AND m.completion_date IS NULL AND (m.target_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))");
  $stmt->execute([$clientId]);
  $metrics['milestones_upcoming'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}
?>
<?php
// Determine the correct project request table name (handles singular/plural variants)
$PR_TABLE = 'project_requests';
try {
  $hasSingular = false; $hasPlural = false;
  try { $rs = $pdo->query("SHOW TABLES LIKE 'project_request'"); $hasSingular = $rs && $rs->rowCount() > 0; } catch (Throwable $e) {}
  try { $rs2 = $pdo->query("SHOW TABLES LIKE 'project_requests'"); $hasPlural = $rs2 && $rs2->rowCount() > 0; } catch (Throwable $e) {}
  if ($hasSingular && $hasPlural) {
    $c1 = 0; $c2 = 0;
    try { $c1 = (int)$pdo->query("SELECT COUNT(*) FROM project_request")->fetchColumn(); } catch (Throwable $e) {}
    try { $c2 = (int)$pdo->query("SELECT COUNT(*) FROM project_requests")->fetchColumn(); } catch (Throwable $e) {}
    $PR_TABLE = ($c1 >= $c2) ? 'project_request' : 'project_requests';
  } elseif ($hasSingular) {
    $PR_TABLE = 'project_request';
  } elseif ($hasPlural) {
    $PR_TABLE = 'project_requests';
  } else {
    // Neither exists yet; align with common schema in this repo: prefer singular per environment hint
    $PR_TABLE = 'project_request';
  }
} catch (Throwable $e) {}

// Discover users schema and prepare Senior Architects list for availability display
$usersCols = [];
try { foreach($pdo->query('SHOW COLUMNS FROM users') as $c){ $usersCols[$c['Field']] = true; } } catch(Throwable $e){}
$USERS_PK = isset($usersCols['id']) ? 'id' : (isset($usersCols['user_id']) ? 'user_id' : 'id');
$USERS_NAME_EXPR = isset($usersCols['full_name']) ? 'full_name' : ((isset($usersCols['first_name']) && isset($usersCols['last_name'])) ? "CONCAT(first_name,' ',last_name)" : (isset($usersCols['username']) ? 'username' : (isset($usersCols['email']) ? 'email' : "''")));
// Build a safe SQL snippet to select the user's display name with alias prefixing
if (isset($usersCols['full_name'])) {
  $USERS_NAME_SQL = 'u.full_name';
} elseif (isset($usersCols['first_name']) && isset($usersCols['last_name'])) {
  $USERS_NAME_SQL = "CONCAT(u.first_name,' ',u.last_name)";
} elseif (isset($usersCols['username'])) {
  $USERS_NAME_SQL = 'u.username';
} elseif (isset($usersCols['email'])) {
  $USERS_NAME_SQL = 'u.email';
} else {
  $USERS_NAME_SQL = "''";
}
$hasCreatedBy = isset($projCols['created_by']);
$PROJECTS_PK = isset($projCols['project_id']) ? 'project_id' : (isset($projCols['id']) ? 'id' : 'project_id');

$seniorArchitects = [];
try {
  $conds = [];
  if (isset($usersCols['position'])) { $conds[] = "LOWER(position) REGEXP '(^|[^a-z])senior[ _-]?architect([^a-z]|$)'"; }
  if (isset($usersCols['role'])) { $conds[] = "LOWER(role) IN ('senior_architect','senior architect','senior-architect')"; }
  $where = $conds ? '(' . implode(' OR ', $conds) . ')' : '0';
  if (isset($usersCols['is_active'])) { $where .= ' AND is_active = 1'; }
  $sql = "SELECT $USERS_PK AS id, $USERS_NAME_EXPR AS name" . (isset($usersCols['position'])? ', position':'') . " FROM users WHERE $where ORDER BY name";
  foreach($pdo->query($sql) as $row){ $seniorArchitects[] = $row; }
} catch (Throwable $e) {}
// Handle project creation request from modal
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['submit_project_request'])) {
  $uid = (int)($_SESSION['user_id'] ?? 0);
  $req_sa_id    = 0; // clients cannot choose; will auto-assign least loaded
  $req_name     = trim((string)($_POST['req_project_name'] ?? ''));
  $req_type     = trim((string)($_POST['req_project_type'] ?? ''));
  $req_start    = trim((string)($_POST['req_start_date'] ?? '')) ?: null;
  $req_location = trim((string)($_POST['req_location'] ?? '')) ?: null;
  $req_budget   = trim((string)($_POST['req_budget'] ?? ''));
  $req_budget   = ($req_budget === '' ? null : $req_budget);
  $req_details  = trim((string)($_POST['req_details'] ?? '')) ?: null;

  // Always auto-pick Senior Architect with the least inquiries (client + public)
  if ($seniorArchitects) {
    $minLoad = PHP_INT_MAX; $chosen = 0;
    // Prepare reusable statements
    $stCli = null; $stPub = null;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS client_inquiries (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, project_id INT NULL, recipient_id INT NULL, category ENUM('general','project_request') DEFAULT 'general', subject VARCHAR(255), message TEXT, status ENUM('open','in_progress','resolved') DEFAULT 'open', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"); } catch (Throwable $ie) {}
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS public_inquiries (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150), email VARCHAR(150), phone VARCHAR(50) NULL, inquiry_type VARCHAR(100) NULL, project_type VARCHAR(150) NULL, budget_range VARCHAR(100) NULL, message TEXT, location VARCHAR(255) NULL, status VARCHAR(50) DEFAULT 'new', assigned_to INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"); } catch (Throwable $ie2) {}
    try { $stCli = $pdo->prepare("SELECT COUNT(*) FROM client_inquiries WHERE recipient_id = ? AND status IN ('open','in_progress')"); } catch (Throwable $pe1) { $stCli = null; }
    try { $stPub = $pdo->prepare("SELECT COUNT(*) FROM public_inquiries WHERE assigned_to = ? AND status IN ('new','in_review')"); } catch (Throwable $pe2) { $stPub = null; }
    foreach ($seniorArchitects as $sa) {
      $sid = (int)$sa['id'];
      $load = 0;
      try { if ($stCli) { $stCli->execute([$sid]); $load += (int)$stCli->fetchColumn(); } } catch (Throwable $e1) {}
      try { if ($stPub) { $stPub->execute([$sid]); $load += (int)$stPub->fetchColumn(); } } catch (Throwable $e2) {}
      if ($load < $minLoad || ($load === $minLoad && $sid < $chosen)) { $minLoad = $load; $chosen = $sid; }
    }
    $req_sa_id = $chosen;
  }

  // Proceed even if no SA found; admin can assign later
  if ($req_name !== '' && $req_type !== '') {
    // project_requests table
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS {$PR_TABLE} (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        senior_architect_id INT NULL,
        project_name VARCHAR(255) NOT NULL,
        project_type VARCHAR(100) NOT NULL,
        preferred_start_date DATE NULL,
        location VARCHAR(255) NULL,
        budget DECIMAL(15,2) NULL,
        details TEXT NULL,
        status ENUM('pending','review','approved','declined') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(client_id), INDEX(senior_architect_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      // Ensure nullable SA column if table already existed
      try { $pdo->exec("ALTER TABLE {$PR_TABLE} MODIFY COLUMN senior_architect_id INT NULL"); } catch (Throwable $e2) {}
    } catch (Throwable $e) {}

    $newPrId = 0;
    try {
      // Discover existing columns to build a resilient INSERT
      $prInsCols = [];
      try {
        $colsStmt = $pdo->query('SHOW COLUMNS FROM ' . $PR_TABLE);
        foreach ($colsStmt as $c) {
          $prInsCols[$c['Field']] = true;
        }
      } catch (Throwable $ePRIns) {
      }
      $insClientId = (int)$clientId ?: $uid;
      $insSaId = ($req_sa_id > 0 ? $req_sa_id : null);
      $cols = [];$params = [];
      // Client column
      if (isset($prInsCols['client_id'])) { $cols[] = 'client_id'; $params[] = $insClientId; }
      elseif (isset($prInsCols['clientId'])) { $cols[] = 'clientId'; $params[] = $insClientId; }
      elseif (isset($prInsCols['user_id'])) { $cols[] = 'user_id'; $params[] = $insClientId; }
      // SA assignment column
      if ($insSaId !== null) {
        if (isset($prInsCols['senior_architect_id'])) { $cols[] = 'senior_architect_id'; $params[] = $insSaId; }
        elseif (isset($prInsCols['assigned_to'])) { $cols[] = 'assigned_to'; $params[] = $insSaId; }
        elseif (isset($prInsCols['sa_id'])) { $cols[] = 'sa_id'; $params[] = $insSaId; }
      }
      // Name/type/start
      if (isset($prInsCols['project_name'])) { $cols[] = 'project_name'; $params[] = $req_name; }
      elseif (isset($prInsCols['name'])) { $cols[] = 'name'; $params[] = $req_name; }
      elseif (isset($prInsCols['title'])) { $cols[] = 'title'; $params[] = $req_name; }
      elseif (isset($prInsCols['subject'])) { $cols[] = 'subject'; $params[] = $req_name; }

      if (isset($prInsCols['project_type'])) { $cols[] = 'project_type'; $params[] = $req_type; }
      elseif (isset($prInsCols['type'])) { $cols[] = 'type'; $params[] = $req_type; }

      if ($req_start !== null) {
        if (isset($prInsCols['preferred_start_date'])) { $cols[] = 'preferred_start_date'; $params[] = $req_start; }
        elseif (isset($prInsCols['start_date'])) { $cols[] = 'start_date'; $params[] = $req_start; }
        elseif (isset($prInsCols['preferred_date'])) { $cols[] = 'preferred_date'; $params[] = $req_start; }
      }
      // Location/budget/details
      if ($req_location !== null) {
        if (isset($prInsCols['location'])) { $cols[] = 'location'; $params[] = $req_location; }
        elseif (isset($prInsCols['site'])) { $cols[] = 'site'; $params[] = $req_location; }
      }
      if ($req_budget !== null) {
        if (isset($prInsCols['budget'])) { $cols[] = 'budget'; $params[] = $req_budget; }
        elseif (isset($prInsCols['estimated_budget'])) { $cols[] = 'estimated_budget'; $params[] = $req_budget; }
      }
      if ($req_details !== null) {
        if (isset($prInsCols['details'])) { $cols[] = 'details'; $params[] = $req_details; }
        elseif (isset($prInsCols['description'])) { $cols[] = 'description'; $params[] = $req_details; }
        elseif (isset($prInsCols['message'])) { $cols[] = 'message'; $params[] = $req_details; }
      }
      // Default status if column exists
      if (isset($prInsCols['status'])) { $cols[] = 'status'; $params[] = 'pending'; }

      if ($cols) {
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sqlIns = 'INSERT INTO ' . $PR_TABLE . ' (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')';
        $st = $pdo->prepare($sqlIns);
        $st->execute($params);
        try { $newPrId = (int)$pdo->lastInsertId(); } catch (Throwable $ie) { $newPrId = 0; }
        if ($newPrId > 0) { $_SESSION['last_pr_rid'] = $newPrId; }
      }
    } catch (Throwable $e) {}

    // Mirror to client_inquiries for SA visibility
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS client_inquiries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT,
        project_id INT NULL,
        request_id INT NULL,
        recipient_id INT NULL,
        category ENUM('general','project_request') DEFAULT 'general',
        subject VARCHAR(255),
        message TEXT,
        status ENUM('open','in_progress','resolved') DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(client_id), INDEX(project_id), INDEX(recipient_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      // Discover existing columns to build a resilient INSERT for client_inquiries
      $ciCols = [];
      try {
        $ciStmt = $pdo->query('SHOW COLUMNS FROM client_inquiries');
        foreach ($ciStmt as $c) {
          $ciCols[$c['Field']] = true;
        }
      } catch (Throwable $eCI) {
      }
      // Ensure request_id column exists in legacy tables
      if (!isset($ciCols['request_id'])) {
        try { $pdo->exec("ALTER TABLE client_inquiries ADD COLUMN request_id INT NULL"); $ciCols['request_id'] = true; } catch (Throwable $eAltr) {}
      }
      $subject = 'Project Request: ' . $req_name;
      $message = $req_details ?: ('Client requested new project: ' . $req_name);
      $ciInsertCols = [];$ciParams = [];
      if (isset($ciCols['client_id'])) { $ciInsertCols[] = 'client_id'; $ciParams[] = $insClientId; }
      elseif (isset($ciCols['user_id'])) { $ciInsertCols[] = 'user_id'; $ciParams[] = $insClientId; }
      if (isset($ciCols['project_id'])) { $ciInsertCols[] = 'project_id'; $ciParams[] = null; }
      if (isset($ciCols['request_id'])) { $ciInsertCols[] = 'request_id'; $ciParams[] = ($newPrId > 0 ? $newPrId : null); }
      // Recipient/assignment
      $recId = ($req_sa_id > 0 ? $req_sa_id : null);
      if (isset($ciCols['recipient_id'])) { $ciInsertCols[] = 'recipient_id'; $ciParams[] = $recId; }
      elseif (isset($ciCols['assigned_to'])) { $ciInsertCols[] = 'assigned_to'; $ciParams[] = $recId; }
      // Category/subject/message/status
      if (isset($ciCols['category'])) { $ciInsertCols[] = 'category'; $ciParams[] = 'project_request'; }
      if (isset($ciCols['subject'])) { $ciInsertCols[] = 'subject'; $ciParams[] = $subject; }
      elseif (isset($ciCols['title'])) { $ciInsertCols[] = 'title'; $ciParams[] = $subject; }
      if (isset($ciCols['message'])) { $ciInsertCols[] = 'message'; $ciParams[] = $message; }
      elseif (isset($ciCols['details'])) { $ciInsertCols[] = 'details'; $ciParams[] = $message; }
      if (isset($ciCols['status'])) { $ciInsertCols[] = 'status'; $ciParams[] = 'open'; }
      if ($ciInsertCols) {
        $ciPH = implode(',', array_fill(0, count($ciInsertCols), '?'));
        $ciSQL = 'INSERT INTO client_inquiries (' . implode(',', $ciInsertCols) . ') VALUES (' . $ciPH . ')';
        $stI = $pdo->prepare($ciSQL);
        $stI->execute($ciParams);
      }
    } catch (Throwable $e) {}

    // Notify assigned SA
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NULL,
        type VARCHAR(50) DEFAULT 'project',
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id), INDEX(is_read)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      if ($req_sa_id > 0) {
        $title = 'New Project Request Assigned';
        $msg = 'A new project request (' . $req_name . ') has been assigned to you.';
        $stN = $pdo->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)');
        $stN->execute([$req_sa_id, $title, $msg, 'project']);
      }
    } catch (Throwable $e) {}

    // Redirect to avoid form resubmission and show updated list, including the new request id for highlighting
    if (!headers_sent()) {
      $loc = 'dashboard.php?req=sent' . ($newPrId > 0 ? ('&rid=' . $newPrId) : '');
      header('Location: ' . $loc);
      exit;
    } else {
      $ridJs = $newPrId > 0 ? ('&rid=' . (int)$newPrId) : '';
      echo '<script>location.href="dashboard.php?req=sent' . $ridJs . '";</script>';
      exit;
    }
  }
}
?>

<!-- Removed gradient hero section -->

<!-- Request Project Modal -->
<div id="requestProjectModal" class="hidden fixed inset-0 z-50">
  <div class="modal-overlay absolute inset-0 bg-black/40 flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-3xl rounded-xl shadow-xl overflow-hidden">
      <div class="flex items-center justify-between px-5 py-4 border-b">
        <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2"><i class="fas fa-folder-plus text-green-600"></i> Request a New Project</h3>
        <button type="button" data-close class="text-gray-500 hover:text-gray-700">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div class="p-5 grid gap-6">
        <div>
          <h4 class="text-sm font-semibold text-gray-900 mb-3">Fill in request details</h4>
          <form method="post" class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Project Name</label>
              <input type="text" name="req_project_name" required class="w-full px-3 py-2 border rounded-lg" placeholder="e.g., Residential Villa" />
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Project Type</label>
                <select name="req_project_type" required class="w-full px-3 py-2 border rounded-lg">
                  <option value="design_only">Design Only</option>
                  <option value="fit_out">Fit Out</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Preferred Start Date</label>
                <input type="date" name="req_start_date" class="w-full px-3 py-2 border rounded-lg" />
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                <input type="text" name="req_location" class="w-full px-3 py-2 border rounded-lg" placeholder="City / Site" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Estimated Budget</label>
                <input type="number" step="0.01" name="req_budget" class="w-full px-3 py-2 border rounded-lg" placeholder="e.g., 250000" />
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Details</label>
              <textarea name="req_details" rows="4" class="w-full px-3 py-2 border rounded-lg" placeholder="Describe your project requirements..."></textarea>
            </div>
            <div class="flex items-center justify-end gap-2 pt-2">
              <button type="button" data-close class="px-4 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">Cancel</button>
              <button type="submit" name="submit_project_request" class="px-4 py-2 rounded-lg bg-green-600 text-white hover:bg-green-700">Submit Request</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<main class="p-6 space-y-6">
  <div class="flex items-center justify-between">
    <h1 class="text-xl font-semibold text-gray-900">Client Dashboard</h1>
    <button id="openRequestModal" type="button" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-green-600 text-white hover:bg-green-700">
      <i class="fas fa-folder-plus"></i>
      Request Project
    </button>
  </div>
  <?php
    // Your Project Requests (list the client's submitted requests)
  $requests = [];
  $sessUid = (int)($_SESSION['user_id'] ?? 0);
  $rid = (int)($_GET['rid'] ?? 0);
  if ($rid <= 0 && !empty($_SESSION['last_pr_rid'])) { $rid = (int)$_SESSION['last_pr_rid']; unset($_SESSION['last_pr_rid']); }
    try {
      // Discover project_requests schema for resilience (PK/client/status/created/name/type/sa columns)
      $prCols = [];
      try { foreach($pdo->query('SHOW COLUMNS FROM ' . $PR_TABLE) as $c){ $prCols[$c['Field']] = true; } } catch(Throwable $ePR){}
      $PR_PK         = isset($prCols['id']) ? 'id' : (isset($prCols['request_id']) ? 'request_id' : (isset($prCols['pr_id']) ? 'pr_id' : 'id'));
      $PR_CLIENT_COL = isset($prCols['client_id']) ? 'client_id' : (isset($prCols['clientId']) ? 'clientId' : (isset($prCols['user_id']) ? 'user_id' : null));
      $PR_NAME_COL   = isset($prCols['project_name']) ? 'project_name' : (isset($prCols['name']) ? 'name' : (isset($prCols['title']) ? 'title' : (isset($prCols['subject']) ? 'subject' : null)));
      $PR_TYPE_COL   = isset($prCols['project_type']) ? 'project_type' : (isset($prCols['type']) ? 'type' : null);
      $PR_START_COL  = isset($prCols['preferred_start_date']) ? 'preferred_start_date' : (isset($prCols['start_date']) ? 'start_date' : (isset($prCols['preferred_date']) ? 'preferred_date' : null));
      $PR_SA_COL     = isset($prCols['senior_architect_id']) ? 'senior_architect_id' : (isset($prCols['assigned_to']) ? 'assigned_to' : (isset($prCols['sa_id']) ? 'sa_id' : null));
      $PR_STATUS_COL = isset($prCols['status']) ? 'status' : null;
      $PR_CREATED_COL= isset($prCols['created_at']) ? 'created_at' : (isset($prCols['createdAt']) ? 'createdAt' : (isset($prCols['date_created']) ? 'date_created' : (isset($prCols['created_date']) ? 'created_date' : null)));
      $PR_LOC_COL    = isset($prCols['location']) ? 'location' : (isset($prCols['site']) ? 'site' : null);
      $PR_BUDGET_COL = isset($prCols['budget']) ? 'budget' : (isset($prCols['estimated_budget']) ? 'estimated_budget' : null);
      $PR_DETAILS_COL= isset($prCols['details']) ? 'details' : (isset($prCols['description']) ? 'description' : (isset($prCols['message']) ? 'message' : null));
      // Discover users schema for joining SA name
      $USERS_PK = isset($usersCols['id']) ? 'id' : (isset($usersCols['user_id']) ? 'user_id' : 'id');
  $USERS_NAME_EXPR = isset($usersCols['full_name']) ? 'full_name' : ((isset($usersCols['first_name']) && isset($usersCols['last_name'])) ? "CONCAT(first_name,' ',last_name)" : (isset($usersCols['username']) ? 'username' : (isset($usersCols['email']) ? 'email' : "''")));
  if (isset($usersCols['full_name'])) { $USERS_NAME_SQL = 'u.full_name'; }
  elseif (isset($usersCols['first_name']) && isset($usersCols['last_name'])) { $USERS_NAME_SQL = "CONCAT(u.first_name,' ',u.last_name)"; }
  elseif (isset($usersCols['username'])) { $USERS_NAME_SQL = 'u.username'; }
  elseif (isset($usersCols['email'])) { $USERS_NAME_SQL = 'u.email'; }
  else { $USERS_NAME_SQL = "''"; }

      // Build robust candidate sets: client_ids and user_ids
      $clientCandidates = [];
      $userCandidates = [];
      if (!empty($clientId)) { $clientCandidates[] = (int)$clientId; }
      if (!empty($sessUid))  { $userCandidates[] = (int)$sessUid; }
      // Try mapped users.user_id if session holds legacy users.id
      try {
        if ($sessUid) {
          $mapped = 0;
          if (isset($usersCols['id']) && isset($usersCols['user_id'])) {
            // Try session as legacy users.id
            try { $stMU = $pdo->prepare('SELECT user_id FROM users WHERE id = ? LIMIT 1'); $stMU->execute([$sessUid]); $mapped = (int)$stMU->fetchColumn(); } catch (Throwable $e1) { $mapped = 0; }
            // Or session already equals users.user_id
            if ($mapped <= 0) { try { $stMU2 = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? LIMIT 1'); $stMU2->execute([$sessUid]); $mapped = (int)$stMU2->fetchColumn(); } catch (Throwable $e2) { $mapped = 0; } }
          } elseif (isset($usersCols['user_id'])) {
            // Table only has user_id; session likely stores user_id
            $mapped = (int)$sessUid;
          } elseif (isset($usersCols['id'])) {
            // Table only has id; attempt to fetch user_id if it exists, else fallback
            try { $stMU = $pdo->prepare('SELECT user_id FROM users WHERE id = ? LIMIT 1'); $stMU->execute([$sessUid]); $mapped = (int)$stMU->fetchColumn(); } catch (Throwable $e3) { $mapped = 0; }
          }
          if ($mapped > 0) { $userCandidates[] = $mapped; }
          // Any client_id tied to session or mapped user
          $idsQ = [];$idsP = [];
          foreach (array_unique(array_filter([$sessUid, $mapped])) as $i) { $idsQ[] = '?'; $idsP[] = $i; }
          if ($idsQ) {
            $stC = $pdo->prepare('SELECT client_id FROM clients WHERE user_id IN (' . implode(',', $idsQ) . ')');
            $stC->execute($idsP);
            foreach ($stC->fetchAll(PDO::FETCH_COLUMN, 0) as $cid) { $clientCandidates[] = (int)$cid; }
          }
        }
      } catch (Throwable $e0) {}

      $clientCandidates = array_values(array_unique(array_filter($clientCandidates, function($v){ return (int)$v > 0; })));
      $userCandidates   = array_values(array_unique(array_filter($userCandidates, function($v){ return (int)$v > 0; })));

  $pr_count = 0; $ci_count = 0;
  if ($clientCandidates || $userCandidates || $rid > 0) {
        // Build named placeholders for client ids and user ids
  $phC = [];$phU = [];$phU2 = [];$params = [];$i=0;$j=0;
        foreach ($clientCandidates as $val) { $key = ':c'.$i++; $phC[] = $key; $params[$key] = $val; }
  foreach ($userCandidates as $val)   { $key = ':u'.$j; $phU[] = $key; $params[$key] = $val; $phU2[] = $key.'b'; $params[$key.'b'] = $val; $j++; }
        $clauses = [];
        if ($phC && $PR_CLIENT_COL) { $clauses[] = 'pr.' . $PR_CLIENT_COL . ' IN (' . implode(',', $phC) . ')'; }
        if ($phU) {
          // Legacy rows may have stored user_id directly in client_id
          if ($PR_CLIENT_COL) {
            $clauses[] = 'pr.' . $PR_CLIENT_COL . ' IN (' . implode(',', $phU) . ')';
            $clauses[] = 'pr.' . $PR_CLIENT_COL . ' IN (SELECT client_id FROM clients WHERE user_id IN (' . implode(',', $phU2) . '))';
          }
        }
        if ($rid > 0) { $clauses[] = 'pr.' . $PR_PK . ' = :rid'; $params[':rid'] = $rid; }
        $where = $clauses ? ('WHERE ' . implode(' OR ', $clauses)) : '';

        // Build resilient SELECT parts with aliases
        $selectParts = [];
        $selectParts[] = 'pr.' . $PR_PK . ' AS id';
        $selectParts[] = $PR_NAME_COL   ? ('pr.' . $PR_NAME_COL   . ' AS project_name')       : ("NULL AS project_name");
        $selectParts[] = $PR_TYPE_COL   ? ('pr.' . $PR_TYPE_COL   . ' AS project_type')       : ("NULL AS project_type");
        $selectParts[] = $PR_START_COL  ? ('pr.' . $PR_START_COL  . ' AS preferred_start_date'): ("NULL AS preferred_start_date");
        $selectParts[] = $PR_STATUS_COL ? ('pr.' . $PR_STATUS_COL . ' AS status')             : ("'pending' AS status");
        $selectParts[] = $PR_CREATED_COL? ('pr.' . $PR_CREATED_COL. ' AS created_at')         : ("CURRENT_TIMESTAMP AS created_at");
        $selectParts[] = $PR_SA_COL     ? ('pr.' . $PR_SA_COL     . ' AS senior_architect_id'): ("NULL AS senior_architect_id");
        $selectParts[] = $PR_LOC_COL    ? ('pr.' . $PR_LOC_COL    . ' AS location')           : ("NULL AS location");
        $selectParts[] = $PR_BUDGET_COL ? ('pr.' . $PR_BUDGET_COL . ' AS budget')             : ("NULL AS budget");
        $selectParts[] = $PR_DETAILS_COL? ('pr.' . $PR_DETAILS_COL. ' AS details')            : ("NULL AS details");
        // Optionally join latest inquiry status linked to this request
  $inqStatusSel = "NULL AS inquiry_status, NULL AS inquiry_subject, NULL AS inquiry_message, NULL AS inquiry_created_at";
        $joinInquiry = '';
        try {
          $hasCI = false; $hasCIReq = false; $hasCIStatus = false;
          try { $rsCI = $pdo->query("SHOW TABLES LIKE 'client_inquiries'"); $hasCI = $rsCI && $rsCI->rowCount() > 0; } catch (Throwable $eCI) {}
          if ($hasCI) {
            $ciCols = [];
            try { foreach($pdo->query('SHOW COLUMNS FROM client_inquiries') as $c){ $ciCols[$c['Field']] = true; } } catch (Throwable $eCIC) {}
            $hasCIReq = isset($ciCols['request_id']);
            $hasCIStatus = isset($ciCols['status']);
          }
          if ($hasCI && $hasCIReq && $hasCIStatus) {
            // Subquery to get latest inquiry per request_id using max(id) as proxy for latest, including subject/message/created_at
            $joinInquiry = "LEFT JOIN (SELECT ci1.request_id, ci1.status, ci1.subject, ci1.message, ci1.created_at FROM client_inquiries ci1 JOIN (SELECT request_id, MAX(id) AS max_id FROM client_inquiries WHERE request_id IS NOT NULL GROUP BY request_id) ci2 ON ci1.request_id = ci2.request_id AND ci1.id = ci2.max_id) cil ON cil.request_id = pr." . $PR_PK;
            $inqStatusSel = 'cil.status AS inquiry_status, cil.subject AS inquiry_subject, cil.message AS inquiry_message, cil.created_at AS inquiry_created_at';
          }
        } catch (Throwable $eJI) {}

        $joinUsers = $PR_SA_COL ? ('LEFT JOIN users u ON u.' . $USERS_PK . ' = pr.' . $PR_SA_COL) : '';
        $saNameSel = $PR_SA_COL ? ($USERS_NAME_SQL . ' AS sa_name') : ("NULL AS sa_name");
        $selectSql = 'SELECT ' . implode(', ', $selectParts) . ', ' . $saNameSel . ', ' . $inqStatusSel . ' FROM ' . $PR_TABLE . ' pr ' . $joinUsers . ' ' . $joinInquiry . ' ' . $where;
        // Order by created date if known, else by PK desc
        $orderBy = $PR_CREATED_COL ? (' ORDER BY pr.' . $PR_CREATED_COL . ' DESC') : (' ORDER BY pr.' . $PR_PK . ' DESC');
  $sqlPR = $selectSql . $orderBy . ' LIMIT 10';
  
  $stPR = $pdo->prepare($sqlPR);
  $stPR->execute($params);
  $requests = $stPR->fetchAll(PDO::FETCH_ASSOC);
  $pr_count = is_array($requests) ? count($requests) : 0;

        // Fallback: if no rows in project_requests, pull from client_inquiries (category project_request)
        if (!$requests) {
          try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS client_inquiries (
              id INT AUTO_INCREMENT PRIMARY KEY,
              client_id INT,
              project_id INT NULL,
              recipient_id INT NULL,
              category ENUM('general','project_request') DEFAULT 'general',
              subject VARCHAR(255),
              message TEXT,
              status ENUM('open','in_progress','resolved') DEFAULT 'open',
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX(client_id), INDEX(project_id), INDEX(recipient_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
          } catch (Throwable $ie) {}
          $clausesCI = [];
          if ($phC) { $clausesCI[] = 'ci.client_id IN (' . implode(',', $phC) . ')'; }
          if ($phU) {
            // Legacy rows may have stored user_id directly in client_id
            $clausesCI[] = 'ci.client_id IN (' . implode(',', $phU) . ')';
            $clausesCI[] = 'ci.client_id IN (SELECT client_id FROM clients WHERE user_id IN (' . implode(',', $phU) . '))';
          }
          // Relaxed filter: include explicit project_request category OR subjects starting with 'Project Request'
          $whereCI = 'WHERE (' . implode(' OR ', $clausesCI) . ") AND (ci.category = 'project_request' OR ci.subject LIKE 'Project Request:%' OR ci.subject LIKE 'Project Request%')";
     $sqlCI = "SELECT ci.id,
            ci.subject AS project_name,
            NULL AS project_type,
            NULL AS preferred_start_date,
            ci.status,
            ci.created_at,
            ci.recipient_id AS senior_architect_id,
            NULL AS location,
            NULL AS budget,
            ci.message AS details,
            u.$USERS_NAME_EXPR AS sa_name,
            ci.status AS inquiry_status,
            ci.subject AS inquiry_subject,
            ci.message AS inquiry_message,
            ci.created_at AS inquiry_created_at
                    FROM client_inquiries ci
                    LEFT JOIN users u ON u.$USERS_PK = ci.recipient_id
                    $whereCI
                    ORDER BY ci.created_at DESC
                    LIMIT 10";
          $stCI = $pdo->prepare($sqlCI);
          $stCI->execute($params);
          $requests = $stCI->fetchAll(PDO::FETCH_ASSOC);
          $ci_count = is_array($requests) ? count($requests) : 0;
        }
      }
    } catch (Throwable $e) {
      // If table doesn't exist or any error occurs, keep $requests empty
      $requests = [];
    }
  ?>

  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
    <h2 class="text-lg font-semibold mb-3">Your Project Requests</h2>
    <?php if (isset($_GET['req']) && $_GET['req'] === 'sent'): ?>
  <div id="req-sent-alert" class="mb-4 rounded-lg border border-green-200 bg-green-50 text-green-800 px-4 py-3 flex items-start gap-3">
        <i class="fas fa-check-circle mt-0.5"></i>
        <div>
          <p class="font-semibold">Request submitted</p>
          <p class="text-sm">Your project request has been sent. We'll get back to you shortly.</p>
        </div>
        <button type="button" aria-label="Close" class="ml-auto text-green-800/70 hover:text-green-900" onclick="this.closest('#req-sent-alert')?.remove()">&times;</button>
      </div>
      <script>
        setTimeout(function(){ try{ document.getElementById('req-sent-alert')?.remove(); }catch(e){} }, 4500);
      </script>
    <?php endif; ?>
    <div class="overflow-x-auto max-h-96 overflow-y-auto">
      <table class="min-w-full">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
            <th class="py-2 pr-3">Project</th>
            <th class="py-2 pr-3">Type</th>
            <th class="py-2 pr-3">Preferred Start</th>
            <th class="py-2 pr-3">Senior Architect</th>
            <th class="py-2 pr-3">Status</th>
            <th class="py-2">Created</th>
          </tr>
        </thead>
        <tbody class="text-sm">
          <?php if (!$requests): ?>
            <tr>
              <td colspan="6" class="py-6 text-center text-gray-500">No project requests yet.</td>
            </tr>
          <?php else: foreach ($requests as $rq): ?>
            <?php $isNew = isset($_GET['rid']) && ((int)$_GET['rid'] === (int)($rq['id'] ?? 0)); ?>
            <tr class="border-t hover:bg-gray-50 <?php echo $isNew ? 'bg-green-50' : ''; ?>">
              <td class="py-2 pr-3 text-gray-800 truncate" title="<?php echo htmlspecialchars($rq['project_name'] ?: 'Request'); ?>">
                <div class="truncate">
                  <?php echo htmlspecialchars($rq['project_name'] ?: 'Request'); ?>
                  <?php if ($isNew): ?><span class="ml-2 inline-block align-middle px-1.5 py-0.5 text-[10px] rounded-full bg-green-600 text-white">New</span><?php endif; ?>
                </div>
                <?php
                  $loc = trim((string)($rq['location'] ?? ''));
                  $bud = $rq['budget'] !== null && $rq['budget'] !== '' ? (float)$rq['budget'] : null;
                  $det = trim((string)($rq['details'] ?? ''));
                  $bits = [];
                  if ($loc !== '') { $bits[] = 'Location: ' . $loc; }
                  if ($bud !== null) { $bits[] = 'Budget: ' . number_format($bud, 2); }
                  if ($det !== '') {
                    $preview = mb_substr($det, 0, 80);
                    if (mb_strlen($det) > 80) { $preview .= '…'; }
                    $bits[] = 'Details: ' . $preview;
                  }
                ?>
                <?php if (!empty($bits)): ?>
                  <div class="mt-0.5 text-[12px] text-gray-600 truncate" title="<?php echo htmlspecialchars($det); ?>">
                    <?php echo htmlspecialchars(implode(' • ', $bits)); ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="py-2 pr-3 text-gray-600 text-[13px]"><?php echo htmlspecialchars($rq['project_type'] ?? ''); ?></td>
              <td class="py-2 pr-3 text-gray-600 text-[13px]">
                <?php echo !empty($rq['preferred_start_date']) ? htmlspecialchars(date('Y-m-d', strtotime($rq['preferred_start_date']))) : '<span class="text-gray-400">—</span>'; ?>
              </td>
              <td class="py-2 pr-3 text-gray-700">
                <?php
                  $saName = trim((string)($rq['sa_name'] ?? ''));
                  echo $saName !== '' ? htmlspecialchars($saName) : '<span class="text-gray-400">Auto-assign pending</span>';
                ?>
              </td>
              <td class="py-2 pr-3">
                <?php
                  // Prefer inquiry status if available, else fall back to request status
                  $statusLabel = !empty($rq['inquiry_status']) ? (string)$rq['inquiry_status'] : ((string)($rq['status'] ?? 'pending'));
                  $st = strtolower($statusLabel);
                  $cls = 'bg-gray-100 text-gray-700';
                  if ($st === 'pending' || $st === 'new' || $st === 'open') { $cls = 'bg-yellow-100 text-yellow-800'; }
                  elseif ($st === 'review' || $st === 'in_progress' || $st === 'in_review') { $cls = 'bg-blue-100 text-blue-800'; }
                  elseif ($st === 'approved' || $st === 'resolved') { $cls = 'bg-green-100 text-green-800'; }
                  elseif ($st === 'declined' || $st === 'dismissed' || $st === 'cancelled') { $cls = 'bg-red-100 text-red-800'; }
                ?>
                <span class="px-2 py-0.5 rounded-full text-[11px] font-medium <?php echo $cls; ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
              </td>
              <td class="py-2 text-gray-500 whitespace-nowrap text-[13px]">
                <div><?php echo htmlspecialchars(date('Y-m-d', strtotime($rq['created_at']))); ?></div>
                <div class="text-[10px] text-gray-400"><?php echo htmlspecialchars(date('H:i', strtotime($rq['created_at']))); ?></div>
              </td>
              <td class="py-2 pr-2">
                <?php
                  // Prepare data attributes for modal
                  $data = [
                    'id' => (int)($rq['id'] ?? 0),
                    'name' => (string)($rq['project_name'] ?? ''),
                    'type' => (string)($rq['project_type'] ?? ''),
                    'start' => (string)($rq['preferred_start_date'] ?? ''),
                    'sa' => (string)($rq['sa_name'] ?? ''),
                    'loc' => (string)($rq['location'] ?? ''),
                    'budget' => isset($rq['budget']) && $rq['budget'] !== '' ? (string)$rq['budget'] : '',
                    'status' => (string)(!empty($rq['inquiry_status']) ? $rq['inquiry_status'] : ($rq['status'] ?? 'pending')),
                    'created' => (string)($rq['created_at'] ?? ''),
                    'details' => (string)($rq['details'] ?? ''),
                    'iq_status' => (string)($rq['inquiry_status'] ?? ''),
                    'iq_subject' => (string)($rq['inquiry_subject'] ?? ''),
                    'iq_message' => (string)($rq['inquiry_message'] ?? ''),
                    'iq_created' => (string)($rq['inquiry_created_at'] ?? ''),
                  ];
                ?>
                <button type="button"
                        class="px-2.5 py-1 text-xs rounded-md bg-blue-600 text-white hover:bg-blue-700 view-request"
                        <?php foreach ($data as $k=>$v) { echo ' data-'.$k.'="'.htmlspecialchars($v, ENT_QUOTES).'"'; } ?>>
                  View
                </button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <h2 class="text-lg font-semibold mb-3">Recent Projects</h2>
    <div class="overflow-x-auto">
      <?php
        // Recent visible projects (with name & status if present), robust to schema variants
        $recent = [];
        try {
          $clientCol = $CLIENT_COL ?: 'client_id';
          $pkCol = $PROJECTS_PK; // determined earlier as project_id or id
          $createdCol = $CREATED_COL; // may be null
          // Build select parts
          $selectCreated = $createdCol ? ('p.' . $createdCol . ' AS created_at') : ('NULL AS created_at');
          $selectStatus  = $hasStatus ? ', p.status' : '';
          $sql = 'SELECT p.' . $pkCol . ' AS project_id, p.project_name, ' . $selectCreated . $selectStatus . ' FROM projects p WHERE p.' . $clientCol . ' = ?' . $visibilityFilter;
          // Order by created if available; else by PK desc
          $sql .= $createdCol ? ' ORDER BY p.' . $createdCol . ' DESC' : ' ORDER BY p.' . $pkCol . ' DESC';
          $sql .= ' LIMIT 10';
          $stR = $pdo->prepare($sql);
          $stR->execute([$clientId]);
          $recent = $stR->fetchAll(PDO::FETCH_ASSOC);
        } catch(Throwable $e) { $recent = []; }
      ?>
      <table class="min-w-full">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
            <th class="py-2 pr-3">ID</th>
            <th class="py-2 pr-3">Project</th>
            <?php if ($hasStatus): ?><th class="py-2 pr-3">Status</th><?php endif; ?>
            <th class="py-2">Created</th>
          </tr>
        </thead>
        <tbody class="text-sm">
          <?php if (!$recent): ?>
            <?php if (!empty($requests)): ?>
              <tr>
                <td colspan="<?php echo $hasStatus? '4':'3'; ?>" class="py-6 text-center text-gray-500">No projects yet. Showing your recent requests below.</td>
              </tr>
              <?php foreach (array_slice($requests, 0, 3) as $rq): ?>
                <tr class="border-t border-gray-100">
                  <td class="py-2 pr-3">REQ #<?php echo (int)$rq['id']; ?></td>
                  <td class="py-2 pr-3 text-gray-800 truncate" title="<?php echo htmlspecialchars(($rq['project_name'] ?? '') !== '' ? $rq['project_name'] : ('Request #'.(int)$rq['id'])); ?>">
                    <?php echo htmlspecialchars(($rq['project_name'] ?? '') !== '' ? $rq['project_name'] : ('Request #'.(int)$rq['id'])); ?>
                  </td>
                  <?php if ($hasStatus): ?>
                  <td class="py-2 pr-3">
                    <?php
                      $st = strtolower((string)($rq['status'] ?? 'pending'));
                      $cls = 'bg-gray-100 text-gray-700';
                      if ($st === 'pending') { $cls = 'bg-yellow-100 text-yellow-800'; }
                      elseif ($st === 'review') { $cls = 'bg-blue-100 text-blue-800'; }
                      elseif ($st === 'approved') { $cls = 'bg-green-100 text-green-800'; }
                      elseif ($st === 'declined') { $cls = 'bg-red-100 text-red-800'; }
                    ?>
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-medium <?php echo $cls; ?>"><?php echo htmlspecialchars($rq['status'] ?? 'pending'); ?></span>
                  </td>
                  <?php endif; ?>
                  <td class="py-2 text-gray-500 whitespace-nowrap text-[13px]">
                    <?php if (!empty($rq['created_at'])): ?>
                      <div><?php echo htmlspecialchars(date('Y-m-d', strtotime($rq['created_at']))); ?></div>
                      <div class="text-[10px] text-gray-400"><?php echo htmlspecialchars(date('H:i', strtotime($rq['created_at']))); ?></div>
                    <?php else: ?>
                      <span class="text-gray-400">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="<?php echo $hasStatus? '4':'3'; ?>" class="py-6 text-center text-gray-500">No visible projects.</td>
              </tr>
            <?php endif; ?>
          <?php else: foreach ($recent as $rp): ?>
            <tr class="border-t border-gray-100">
              <td class="py-2 pr-3">#<?php echo (int)$rp['project_id']; ?></td>
              <td class="py-2 pr-3 text-gray-800 truncate" title="<?php echo htmlspecialchars(($rp['project_name'] ?? '') !== '' ? $rp['project_name'] : ('Project #'.(int)$rp['project_id'])); ?>">
                <?php echo htmlspecialchars(($rp['project_name'] ?? '') !== '' ? $rp['project_name'] : ('Project #'.(int)$rp['project_id'])); ?>
              </td>
              <?php if ($hasStatus): ?>
              <td class="py-2 pr-3">
                <?php
                  $st = strtolower((string)($rp['status'] ?? ''));
                  $cls = 'bg-gray-100 text-gray-700';
                  if ($st === 'pending') { $cls = 'bg-yellow-100 text-yellow-800'; }
                  elseif ($st === 'active') { $cls = 'bg-blue-100 text-blue-800'; }
                  elseif ($st === 'completed') { $cls = 'bg-green-100 text-green-800'; }
                  elseif ($st === 'cancelled') { $cls = 'bg-red-100 text-red-800'; }
                ?>
                <span class="px-2 py-0.5 rounded-full text-[11px] font-medium <?php echo $cls; ?>"><?php echo htmlspecialchars($rp['status'] ?? ''); ?></span>
              </td>
              <?php endif; ?>
              <td class="py-2 text-gray-500 whitespace-nowrap text-[13px]">
                <?php if (!empty($rp['created_at'])): ?>
                  <div><?php echo htmlspecialchars(date('Y-m-d', strtotime($rp['created_at']))); ?></div>
                  <div class="text-[10px] text-gray-400"><?php echo htmlspecialchars(date('H:i', strtotime($rp['created_at']))); ?></div>
                <?php else: ?>
                  <span class="text-gray-400">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const openBtn = document.getElementById('openRequestModal');
    const modal = document.getElementById('requestProjectModal');
    const overlay = modal ? modal.querySelector('.modal-overlay') : null;
    const open = () => { if(modal) modal.classList.remove('hidden'); };
    const close = () => { if(modal) modal.classList.add('hidden'); };
    if(openBtn) openBtn.addEventListener('click', open);
    if(overlay) overlay.addEventListener('click', (e)=>{ if(e.target===overlay) close(); });
    if(modal) modal.querySelectorAll('[data-close]')?.forEach(btn=>btn.addEventListener('click', close));

    // Prefill project request from design fee calculator (sessionStorage 'dfc_prefill')
    function prefillFromCalculator() {
      let raw = null; try { raw = sessionStorage.getItem('dfc_prefill'); } catch(e) { raw = null; }
      if (!raw) return;
      let data = null; try { data = JSON.parse(raw); } catch(e) { data = null; }
      if (!data || !modal) return;
      const nameField = modal.querySelector('input[name="req_project_name"]');
      const budgetField = modal.querySelector('input[name="req_budget"]');
      const detailsField = modal.querySelector('textarea[name="req_details"]');
      const label = data.label || 'Design Fee';
      const area = data.area || '0';
      const unit = data.unit || 'sqm';
      const fee = data.fee || '₱0';
      const projectCost = data.project || '₱0';
      if (nameField && !nameField.value) {
        nameField.value = `Project Request (${label})`;
      }
      if (budgetField && (!budgetField.value || budgetField.value === '')) {
        // Extract numeric value from fee string (remove currency symbol and commas)
        const numericFee = fee.replace(/[^0-9.]/g,'');
        budgetField.value = numericFee;
      }
      if (detailsField && !detailsField.value) {
        detailsField.value = `Preliminary design fee estimate\nArea: ${area} ${unit}\nProject Cost: ${projectCost}\nDesign Fee (${label}): ${fee}\nPlease refine scope, inclusions, and assumptions.`;
      }
      // Open modal automatically
      open();
      // Clear prefill so it doesn't repeat
      try { sessionStorage.removeItem('dfc_prefill'); } catch(e) {}
    }
    // Auto-prefill if anchor hash indicates request or if storage present
    if (window.location.hash === '#prefill-request') {
      prefillFromCalculator();
    } else {
      // Also allow prefill even without hash if data exists
      prefillFromCalculator();
    }

    // Request Details Modal logic
    const detailsModal = document.getElementById('requestDetailsModal');
    const detailsOverlay = detailsModal ? detailsModal.querySelector('.modal-overlay') : null;
    const fields = detailsModal ? {
      name: detailsModal.querySelector('[data-field="name"]'),
      type: detailsModal.querySelector('[data-field="type"]'),
      start: detailsModal.querySelector('[data-field="start"]'),
      sa: detailsModal.querySelector('[data-field="sa"]'),
      loc: detailsModal.querySelector('[data-field="loc"]'),
      budget: detailsModal.querySelector('[data-field="budget"]'),
      status: detailsModal.querySelector('[data-field="status"]'),
      created: detailsModal.querySelector('[data-field="created"]'),
      details: detailsModal.querySelector('[data-field="details"]'),
      iq_status: detailsModal.querySelector('[data-field="iq_status"]'),
      iq_subject: detailsModal.querySelector('[data-field="iq_subject"]'),
      iq_message: detailsModal.querySelector('[data-field="iq_message"]'),
      iq_created: detailsModal.querySelector('[data-field="iq_created"]'),
    } : {};
    const openDetails = (btn) => {
      if (!detailsModal) return;
      const d = Object.fromEntries(Array.from(btn.attributes).filter(a=>a.name.startsWith('data-')).map(a=>[a.name.replace('data-',''), a.value]));
      const fmtDate = (s) => { if(!s) return '—'; try { const dt = new Date(s.replace(' ', 'T')); return isNaN(dt) ? s : dt.toISOString().slice(0,16).replace('T',' ');} catch(e){ return s || '—'; } };
      fields.name.textContent = d.name || 'Request';
      fields.type.textContent = d.type || '';
      fields.start.textContent = d.start ? d.start.slice(0,10) : '—';
      fields.sa.textContent = d.sa || 'Auto-assign pending';
      fields.loc.textContent = d.loc || '—';
      fields.budget.textContent = d.budget ? (Number(d.budget).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})) : '—';
      fields.status.textContent = d.status || 'pending';
      fields.created.textContent = fmtDate(d.created);
      fields.details.textContent = d.details || 'No additional details provided.';
      // Inquiry block
      const iqBlock = detailsModal.querySelector('[data-block="inquiry"]');
      if (d.iq_status || d.iq_subject || d.iq_message || d.iq_created) {
        iqBlock.classList.remove('hidden');
        fields.iq_status.textContent = d.iq_status || '';
        fields.iq_subject.textContent = d.iq_subject || '';
        fields.iq_message.textContent = d.iq_message || '';
        fields.iq_created.textContent = fmtDate(d.iq_created);
      } else {
        iqBlock.classList.add('hidden');
      }
      detailsModal.classList.remove('hidden');
    };
    document.querySelectorAll('.view-request').forEach(btn => btn.addEventListener('click', () => openDetails(btn)));
    if(detailsOverlay) detailsOverlay.addEventListener('click', (e)=>{ if(e.target===detailsOverlay) detailsModal.classList.add('hidden'); });
    detailsModal?.querySelectorAll('[data-close]')?.forEach(btn=>btn.addEventListener('click', ()=>detailsModal.classList.add('hidden')));
  });
</script>

<!-- Removed inline design fee calculator script; now a dedicated page at client/fee-calculator.php -->

<?php include_once __DIR__ . '/../backend/core/footer.php'; if (function_exists('ob_get_level') && ob_get_level() > 0) { ob_end_flush(); } ?>

<!-- Request Details Modal -->
<div id="requestDetailsModal" class="hidden fixed inset-0 z-50">
  <div class="modal-overlay absolute inset-0 bg-black/40 flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-2xl rounded-xl shadow-xl overflow-hidden">
      <div class="flex items-center justify-between px-5 py-4 border-b">
        <h3 class="text-lg font-bold text-gray-900">Request Details</h3>
        <button type="button" data-close class="text-gray-500 hover:text-gray-700">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div class="p-5 grid gap-4">
        <div class="grid md:grid-cols-2 gap-4">
          <div>
            <div class="text-xs uppercase text-gray-500">Project</div>
            <div class="text-base font-medium text-gray-900" data-field="name"></div>
          </div>
          <div>
            <div class="text-xs uppercase text-gray-500">Type</div>
            <div class="text-base text-gray-800" data-field="type"></div>
          </div>
          <div>
            <div class="text-xs uppercase text-gray-500">Preferred Start</div>
            <div class="text-base text-gray-800" data-field="start"></div>
          </div>
          <div>
            <div class="text-xs uppercase text-gray-500">Senior Architect</div>
            <div class="text-base text-gray-800" data-field="sa"></div>
          </div>
          <div>
            <div class="text-xs uppercase text-gray-500">Location</div>
            <div class="text-base text-gray-800" data-field="loc"></div>
          </div>
          <div>
            <div class="text-xs uppercase text-gray-500">Budget</div>
            <div class="text-base text-gray-800" data-field="budget"></div>
          </div>
          <div>
            <div class="text-xs uppercase text-gray-500">Status</div>
            <div class="text-base text-gray-800" data-field="status"></div>
          </div>
          <div>
            <div class="text-xs uppercase text-gray-500">Created</div>
            <div class="text-base text-gray-800" data-field="created"></div>
          </div>
        </div>
        <div>
          <div class="text-xs uppercase text-gray-500 mb-1">Details</div>
          <div class="text-sm text-gray-800 whitespace-pre-wrap" data-field="details"></div>
        </div>
        <div class="mt-2 p-4 rounded-lg border border-gray-200 bg-gray-50 hidden" data-block="inquiry">
          <div class="flex items-center justify-between mb-2">
            <div class="text-sm font-semibold text-gray-900">Latest Inquiry Update</div>
            <span class="px-2 py-0.5 rounded-full text-[11px] font-medium bg-gray-100 text-gray-700" data-field="iq_status"></span>
          </div>
          <div class="text-sm text-gray-700"><span class="font-medium">Subject:</span> <span data-field="iq_subject"></span></div>
          <div class="mt-2 text-sm text-gray-700 whitespace-pre-wrap" data-field="iq_message"></div>
          <div class="mt-2 text-xs text-gray-500">Updated: <span data-field="iq_created"></span></div>
        </div>
      </div>
    </div>
  </div>
</div>

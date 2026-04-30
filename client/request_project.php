<?php
// Client: Request Project Creation
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$allowed_roles = ['client'];
include __DIR__ . '/../includes/auth_check.php';
$user_id = (int)($_SESSION['user_id'] ?? 0);
include __DIR__ . '/../db.php'; // mysqli $conn

// Helpers
function af_col_exists($conn, $table, $col) {
  $table = $conn->real_escape_string($table);
  $col = $conn->real_escape_string($col);
  $res = $conn->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = '$col'");
  return $res && ($row = $res->fetch_assoc()) && (int)$row['c'] > 0;
}
function af_table_exists($conn, $table) {
  $table = $conn->real_escape_string($table);
  $res = $conn->query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'");
  return $res && ($row = $res->fetch_assoc()) && (int)$row['c'] > 0;
}
$USERS_PK = af_col_exists($conn, 'users', 'id') ? 'id' : (af_col_exists($conn, 'users', 'user_id') ? 'user_id' : 'id');
$PROJECTS_PK = af_col_exists($conn, 'projects', 'id') ? 'id' : (af_col_exists($conn, 'projects', 'project_id') ? 'project_id' : 'id');
$USERS_NAME_EXPR = af_col_exists($conn, 'users', 'full_name') ? 'full_name' : (af_col_exists($conn, 'users', 'first_name') && af_col_exists($conn, 'users', 'last_name') ? "CONCAT(first_name,' ',last_name)" : (af_col_exists($conn, 'users', 'username') ? 'username' : (af_col_exists($conn, 'users', 'email') ? 'email' : "''")));
$HAS_CREATED_BY = af_col_exists($conn, 'projects', 'created_by');

// Load Senior Architects
$senior_architects = [];
{
  $conds = [];
  if (af_col_exists($conn, 'users', 'position')) { $conds[] = "LOWER(position) REGEXP '(^|[^a-z])senior[ _-]?architect([^a-z]|$)'"; }
  if (af_col_exists($conn, 'users', 'role')) { $conds[] = "LOWER(role) IN ('senior_architect','senior architect','senior-architect')"; }
  $where = $conds ? '(' . implode(' OR ', $conds) . ')' : '0';
  if (af_col_exists($conn, 'users', 'is_active')) { $where .= ' AND is_active = 1'; }
  $qry = "SELECT $USERS_PK AS id, $USERS_NAME_EXPR AS name, email, position FROM users WHERE $where ORDER BY name";
  if ($res = $conn->query($qry)) { while ($row = $res->fetch_assoc()) { $senior_architects[] = $row; } }
}

$success_msg = $error_msg = null;

// Handle project creation request
if (isset($_POST['submit_project_request'])) {
  $req_sa_id = isset($_POST['sa_id']) ? (int)$_POST['sa_id'] : 0;
  $req_name = trim($_POST['req_project_name'] ?? '');
  $req_type = trim($_POST['req_project_type'] ?? '');
  // Normalize type to match SA project creation: 'fit in' => 'fit_out'
  $rt = strtolower($req_type);
  if ($rt === 'fit in' || $rt === 'fit_in') { $req_type = 'fit_out'; }
  $req_start = trim($_POST['req_start_date'] ?? '');
  $req_location = trim($_POST['req_location'] ?? '');
  $req_budget = trim($_POST['req_budget'] ?? '');
  $req_details = trim($_POST['req_details'] ?? '');
  if ($req_name === '' || $req_type === '') { $error_msg = 'Project name and type are required.'; }
  if (!$error_msg) {
    // Inquiry-load calculator (public + client inquiries considered active)
    $computeInquiryLoad = function(mysqli $conn, int $sa_id): int {
      $activeSet = "('new','in_review','open','in_progress','contacted','pending','review','active')";
      $total = 0;
      // public_inquiries assigned_to
      $tbl1 = $conn->query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='public_inquiries'");
      $hasTbl1 = $tbl1 && ($r=$tbl1->fetch_assoc()) && (int)$r['c']>0;
      if ($hasTbl1) {
        $q1 = $conn->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='public_inquiries' AND COLUMN_NAME='assigned_to'");
        $hasCol1 = $q1 && ($rc=$q1->fetch_assoc()) && (int)$rc['c']>0;
        if ($hasCol1) {
          $sql = "SELECT COUNT(*) AS c FROM public_inquiries WHERE assigned_to=".(int)$sa_id." AND (status IS NULL OR status IN $activeSet)";
          if ($res = $conn->query($sql)) { $row = $res->fetch_assoc(); $total += (int)($row['c'] ?? 0); }
        }
      }
      // client_inquiries recipient_id
      $tbl2 = $conn->query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='client_inquiries'");
      $hasTbl2 = $tbl2 && ($r2=$tbl2->fetch_assoc()) && (int)$r2['c']>0;
      if ($hasTbl2) {
        $q2 = $conn->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='client_inquiries' AND COLUMN_NAME='recipient_id'");
        $hasCol2 = $q2 && ($rc2=$q2->fetch_assoc()) && (int)$rc2['c']>0;
        if ($hasCol2) {
          $sql2 = "SELECT COUNT(*) AS c FROM client_inquiries WHERE recipient_id=".(int)$sa_id." AND (status IS NULL OR status IN $activeSet)";
          if ($res2 = $conn->query($sql2)) { $row2 = $res2->fetch_assoc(); $total += (int)($row2['c'] ?? 0); }
        }
      }
      return $total;
    };
    // Auto-pick SA if not provided
    $assign_sa_id = $req_sa_id;
    if ($assign_sa_id <= 0) {
      $minLoad = PHP_INT_MAX; $chosen = 0;
      foreach ($senior_architects as $sa) {
        $sid = (int)$sa['id'];
        $load = $computeInquiryLoad($conn, $sid);
        if ($load < $minLoad || ($load === $minLoad && $sid < $chosen)) { $minLoad = $load; $chosen = $sid; }
      }
      $assign_sa_id = $chosen;
    }
    if ($assign_sa_id <= 0) { $error_msg = 'No Senior Architect available for assignment.'; }
  }
  if (!$error_msg) {
    // Create table and insert request
    $conn->query("CREATE TABLE IF NOT EXISTS project_requests (
      id INT AUTO_INCREMENT PRIMARY KEY,
      client_id INT NOT NULL,
      senior_architect_id INT NOT NULL,
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

    $stmt = $conn->prepare("INSERT INTO project_requests (client_id, senior_architect_id, project_name, project_type, preferred_start_date, location, budget, details) VALUES (?,?,?,?,?,?,?,?)");
    $bval = ($req_budget === '' ? null : $req_budget);
    $stmt->bind_param("isssssss", $user_id, $assign_sa_id, $req_name, $req_type, $req_start, $req_location, $bval, $req_details);
    $stmt->execute();
    $stmt->close();

    // Mirror into client_inquiries for visibility
    if (!af_table_exists($conn, 'client_inquiries')) {
      $conn->query("CREATE TABLE IF NOT EXISTS client_inquiries (
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
    }
    if (!af_col_exists($conn, 'client_inquiries', 'recipient_id')) { $conn->query("ALTER TABLE client_inquiries ADD COLUMN recipient_id INT NULL"); }
    if (!af_col_exists($conn, 'client_inquiries', 'category')) { $conn->query("ALTER TABLE client_inquiries ADD COLUMN category ENUM('general','project_request') DEFAULT 'general'"); }
    $subject = 'Project Request: ' . $req_name;
    $message = $req_details !== '' ? $req_details : ('Client requested new project: ' . $req_name);
    $stmt2 = $conn->prepare("INSERT INTO client_inquiries (client_id, project_id, recipient_id, category, subject, message, status) VALUES (NULLIF(?,0), NULL, ?, 'project_request', ?, ?, 'open')");
    $stmt2->bind_param("iiss", $user_id, $assign_sa_id, $subject, $message);
    $stmt2->execute();
    $stmt2->close();

    // Notify SA
    $conn->query("CREATE TABLE IF NOT EXISTS notifications (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      title VARCHAR(255) NOT NULL,
      message TEXT NULL,
      type VARCHAR(50) DEFAULT 'project',
      is_read TINYINT(1) DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(user_id), INDEX(is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $noteTitle = 'New Project Request Assigned';
    $noteMsg = 'A new project request ('. $req_name .') has been assigned to you.';
    $stmtN = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'project')");
    $stmtN->bind_param("iss", $assign_sa_id, $noteTitle, $noteMsg);
    $stmtN->execute();
    $stmtN->close();

    // Success
    $saName = null; foreach ($senior_architects as $sa) { if ((int)$sa['id'] === (int)$assign_sa_id) { $saName = $sa['name']; break; } }
    $success_msg = 'Request sent and assigned to Senior Architect' . ($saName ? (': ' . htmlspecialchars($saName)) : '.');
  }
}

$page_title = 'Request a New Project';
include __DIR__ . '/../includes/header.php';
?>
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-green-50">
  <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="mb-6 flex items-center justify-between">
      <h1 class="text-2xl md:text-3xl font-black text-gray-900 flex items-center gap-2"><i class="fas fa-folder-plus text-green-600"></i><span>Request a New Project</span></h1>
      <a href="dashboard.php" class="inline-flex items-center px-3 py-2 bg-white border border-gray-200 rounded-lg text-gray-700 hover:bg-gray-50"><i class="fas fa-arrow-left mr-2"></i>Back</a>
    </div>

    <?php if ($success_msg): ?>
      <div class="mb-4 bg-green-50 text-green-800 border border-green-200 rounded-lg px-4 py-3"><i class="fas fa-check-circle mr-2"></i><?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
      <div class="mb-4 bg-red-50 text-red-800 border border-red-200 rounded-lg px-4 py-3"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="grid gap-6 lg:grid-cols-2">
      <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2"><i class="fas fa-user-tie text-indigo-600"></i> Available Senior Architects</h3>
        <?php if (count($senior_architects) > 0): ?>
          <div class="space-y-3">
            <?php foreach ($senior_architects as $sa): ?>
              <?php
                $sa_id = (int)$sa['id'];
                $status_filter = "(p.status IS NULL OR p.status NOT IN ('completed','cancelled'))";
                $created_by_cond = $HAS_CREATED_BY ? "p.created_by = $sa_id" : "0";
                $work_sql = "SELECT COUNT(DISTINCT p.$PROJECTS_PK) AS c FROM projects p LEFT JOIN project_users pu ON pu.project_id = p.$PROJECTS_PK AND pu.user_id = $sa_id WHERE ($created_by_cond OR pu.user_id IS NOT NULL) AND $status_filter";
                $work_res = $conn->query($work_sql);
                $work_cnt = $work_res ? (int)$work_res->fetch_assoc()['c'] : 0;
              ?>
              <div class="flex items-center justify-between p-3 bg-gray-50/80 rounded-lg">
                <div>
                  <div class="font-medium text-gray-900"><?php echo htmlspecialchars($sa['name']); ?></div>
                  <div class="text-xs text-gray-500"><?php echo htmlspecialchars($sa['position'] ?? 'Senior Architect'); ?></div>
                </div>
                <div class="text-sm text-gray-700"><i class="fas fa-briefcase mr-1 text-indigo-600"></i><?php echo $work_cnt; ?> active</div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="text-sm text-gray-600">No Senior Architects found.</p>
        <?php endif; ?>
      </div>

      <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2"><i class="fas fa-folder-plus text-green-600"></i> Request a New Project</h3>
        <form method="post" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Senior Architect <span class="text-gray-400 text-xs font-normal">(leave blank to auto-assign least loaded)</span></label>
            <select name="sa_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
              <option value="">Auto-assign</option>
              <?php foreach ($senior_architects as $sa): ?>
                <option value="<?php echo (int)$sa['id']; ?>"><?php echo htmlspecialchars($sa['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="grid md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Project Name</label>
              <input type="text" name="req_project_name" required class="w-full px-3 py-2 border rounded-lg" placeholder="e.g., Residential Villa" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Project Type</label>
              <select name="req_project_type" required class="w-full px-3 py-2 border rounded-lg">
                <option value="design_only">Design Only</option>
                <option value="fit_out">Fit Out</option>
              </select>
            </div>
          </div>
          <div class="grid md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Preferred Start Date</label>
              <input type="date" name="req_start_date" class="w-full px-3 py-2 border rounded-lg" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Location</label>
              <input type="text" name="req_location" class="w-full px-3 py-2 border rounded-lg" placeholder="City / Site" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Estimated Budget</label>
            <input type="number" step="0.01" name="req_budget" class="w-full px-3 py-2 border rounded-lg" placeholder="e.g., 250000" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Details</label>
            <textarea name="req_details" rows="4" class="w-full px-3 py-2 border rounded-lg" placeholder="Describe your project requirements..."></textarea>
          </div>
          <button type="submit" name="submit_project_request" class="w-full bg-green-600 text-white py-2 px-4 rounded-lg hover:bg-green-700 transition-colors font-medium">
            <i class="fas fa-paper-plane mr-2"></i>Send Request
          </button>
        </form>
      </div>
    </div>

    <div class="mt-8 bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
      <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2"><i class="fas fa-clock text-indigo-600"></i> Your Recent Requests</h3>
      <?php
      $reqs = [];
      $check = $conn->query("SHOW TABLES LIKE 'project_requests'");
      if ($check && $check->num_rows > 0) {
        $rs = $conn->prepare("SELECT pr.*, u.$USERS_NAME_EXPR AS sa_name FROM project_requests pr LEFT JOIN users u ON u.$USERS_PK = pr.senior_architect_id WHERE pr.client_id = ? ORDER BY pr.created_at DESC LIMIT 10");
        $rs->bind_param('i', $user_id);
        $rs->execute();
        $reqs = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
        $rs->close();
      }
      if (empty($reqs)): ?>
        <div class="text-sm text-gray-600">No requests yet.</div>
      <?php else: ?>
        <div class="space-y-3">
          <?php foreach ($reqs as $r): ?>
            <?php
              $status_colors = [
                'pending' => 'bg-amber-100 text-amber-800',
                'review' => 'bg-blue-100 text-blue-800',
                'approved' => 'bg-green-100 text-green-800',
                'declined' => 'bg-red-100 text-red-800'
              ];
              $cls = $status_colors[strtolower($r['status'])] ?? 'bg-gray-100 text-gray-800';
            ?>
            <div class="p-3 bg-gray-50/70 rounded-lg flex items-center justify-between">
              <div class="min-w-0">
                <div class="font-medium text-gray-900 truncate"><?php echo htmlspecialchars($r['project_name']); ?></div>
                <div class="text-xs text-gray-500 mt-0.5">SA: <?php echo htmlspecialchars($r['sa_name'] ?? ('#'.$r['senior_architect_id'])); ?> • Type: <?php echo htmlspecialchars($r['project_type']); ?> • <?php echo date('M d, Y', strtotime($r['created_at'])); ?></div>
              </div>
              <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $cls; ?> capitalize"><?php echo htmlspecialchars(str_replace('_',' ', $r['status'])); ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>

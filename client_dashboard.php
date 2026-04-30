<?php
$allowed_roles = ['client'];
include __DIR__ . '/includes/auth_check.php';
$full_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'User';
$user_id = $_SESSION['user_id'];
include 'db.php';

// Helpers: schema detection for users/projects PKs and name fields
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

// Senior Architects list for messaging/requesting
$senior_architects = [];
{
  $conds = [];
  if (af_col_exists($conn, 'users', 'position')) { $conds[] = "LOWER(position) REGEXP '(^|[^a-z])senior[ _-]?architect([^a-z]|$)'"; }
  if (af_col_exists($conn, 'users', 'role')) { $conds[] = "LOWER(role) IN ('senior_architect','senior architect','senior-architect')"; }
  // Strictly Senior Architects only
  $where = $conds ? '(' . implode(' OR ', $conds) . ')' : '0';
  if (af_col_exists($conn, 'users', 'is_active')) { $where .= ' AND is_active = 1'; }
  $qry = "SELECT $USERS_PK AS id, $USERS_NAME_EXPR AS name, email, position FROM users WHERE $where ORDER BY name";
  if ($res = $conn->query($qry)) { while ($row = $res->fetch_assoc()) { $senior_architects[] = $row; } }
}

// Handle feedback submission
if (isset($_POST['submit_feedback'])) {
  $project_id = intval($_POST['project_id']);
  $feedback_text = trim($_POST['feedback_text']);
  $rating = intval($_POST['rating']);

  if ($feedback_text && $project_id > 0) {
    // Create client_feedback table if it doesn't exist
  // Create client_feedback table if it doesn't exist (FKs adapted to detected PKs)
  $conn->query("CREATE TABLE IF NOT EXISTS client_feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT,
            project_id INT,
            feedback TEXT,
            rating INT DEFAULT 5,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX (client_id), INDEX (project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $conn->prepare("INSERT INTO client_feedback (client_id, project_id, feedback, rating) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iisi", $user_id, $project_id, $feedback_text, $rating);
    $stmt->execute();
    $stmt->close();
    $success_msg = "Feedback submitted successfully!";
  }
}

// Handle project inquiry
if (isset($_POST['submit_inquiry'])) {
  $inquiry_subject = trim($_POST['inquiry_subject']);
  $inquiry_message = trim($_POST['inquiry_message']);
  $project_id = isset($_POST['inquiry_project_id']) && !empty($_POST['inquiry_project_id']) ? intval($_POST['inquiry_project_id']) : null;
  $recipient_id = isset($_POST['recipient_id']) && !empty($_POST['recipient_id']) ? intval($_POST['recipient_id']) : null;

  if ($inquiry_subject && $inquiry_message) {
    // Enforce SA-only messaging
    $recipient_required = true;
    $valid_sa = false;
    if ($recipient_id) {
      foreach ($senior_architects as $sa) { if ((int)$sa['id'] === (int)$recipient_id) { $valid_sa = true; break; } }
    }
    if ($recipient_required && (!$recipient_id || !$valid_sa)) {
      $error_msg = 'Please select a Senior Architect as the recipient.';
    } else {
    // Create/extend client_inquiries table (adds recipient_id + category if missing)
    $conn->query("CREATE TABLE IF NOT EXISTS client_inquiries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT,
            project_id INT NULL,
            recipient_id INT NULL,
            category ENUM('general','project_request') DEFAULT 'general',
            subject VARCHAR(255),
            message TEXT,
            status ENUM('open', 'in_progress', 'resolved') DEFAULT 'open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (client_id), INDEX (project_id), INDEX (recipient_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Ensure columns exist if table pre-existed
    if (!af_col_exists($conn, 'client_inquiries', 'recipient_id')) { $conn->query("ALTER TABLE client_inquiries ADD COLUMN recipient_id INT NULL"); }
    if (!af_col_exists($conn, 'client_inquiries', 'category')) { $conn->query("ALTER TABLE client_inquiries ADD COLUMN category ENUM('general','project_request') DEFAULT 'general'"); }

    $stmt = $conn->prepare("INSERT INTO client_inquiries (client_id, project_id, recipient_id, category, subject, message) VALUES (?, ?, ?, 'general', ?, ?)");
    $stmt->bind_param("iiiss", $user_id, $project_id, $recipient_id, $inquiry_subject, $inquiry_message);
    $stmt->execute();
    $stmt->close();
      $success_msg = "Message sent to Senior Architect.";
    }
  }
}

// Handle project creation request
if (isset($_POST['submit_project_request'])) {
  $req_sa_id = isset($_POST['sa_id']) ? (int)$_POST['sa_id'] : 0;
  $req_name = trim($_POST['req_project_name'] ?? '');
  $req_type = trim($_POST['req_project_type'] ?? '');
  $req_start = trim($_POST['req_start_date'] ?? '');
  $req_location = trim($_POST['req_location'] ?? '');
  $req_budget = trim($_POST['req_budget'] ?? '');
  $req_details = trim($_POST['req_details'] ?? '');
  // Helper: compute SA inquiry load (public + client inquiries with active statuses)
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
  // Determine assignment SA: use provided one if >0, otherwise auto-pick least inquiries
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
  if ($assign_sa_id > 0 && $req_name !== '' && $req_type !== '') {
    // Create project_requests table
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

    // Also drop a record into client_inquiries for SA visibility (category project_request)
    $subject = 'Project Request: ' . $req_name;
    $message = $req_details !== '' ? $req_details : ('Client requested new project: ' . $req_name);
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
    $stmt2 = $conn->prepare("INSERT INTO client_inquiries (client_id, project_id, recipient_id, category, subject, message, status) VALUES (NULLIF(?,0), NULL, ?, 'project_request', ?, ?, 'open')");
    $stmt2->bind_param("iiss", $user_id, $assign_sa_id, $subject, $message);
    $stmt2->execute();
    $stmt2->close();

    // Notify the assigned Senior Architect
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

    // Add SA name to success message if available
    $saName = null; foreach ($senior_architects as $sa) { if ((int)$sa['id'] === (int)$assign_sa_id) { $saName = $sa['name']; break; } }
    $success_msg = 'Project request sent and assigned to Senior Architect' . ($saName ? (': ' . htmlspecialchars($saName)) : '.');
  }
}

// Handle chat message on project request
if (isset($_POST['submit_request_message'])) {
  $req_id = (int)($_POST['request_id'] ?? 0);
  $msg = trim($_POST['request_message'] ?? '');
  if ($req_id > 0 && $msg !== '') {
    // Verify ownership
    $chk = $conn->prepare("SELECT id FROM project_requests WHERE id=? AND client_id=?");
    $chk->bind_param("ii", $req_id, $user_id);
    $chk->execute();
    $own = $chk->get_result()->num_rows > 0;
    $chk->close();
    if ($own) {
      $conn->query("CREATE TABLE IF NOT EXISTS project_request_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        sender_id INT NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(request_id), INDEX(sender_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $ins = $conn->prepare("INSERT INTO project_request_messages (request_id, sender_id, message) VALUES (?,?,?)");
      $ins->bind_param("iis", $req_id, $user_id, $msg);
      $ins->execute();
      $ins->close();
      $success_msg = 'Message sent.';
    }
  }
}
?>
<?php $page_title = 'Client Dashboard';
include __DIR__ . '/includes/header.php'; ?>

<!-- Client Dashboard -->
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-purple-50 flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header Section -->
    <div class="mb-8">
      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
        <div>
          <div class="inline-flex items-center px-4 py-2 bg-blue-100 text-blue-800 rounded-full text-sm font-medium mb-4">
            <i class="fas fa-handshake mr-2"></i>Client Portal
          </div>
          <h1 class="text-3xl md:text-4xl font-black text-gray-900 mb-2">
            Welcome back, <span class="bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent"><?php echo htmlspecialchars($full_name); ?></span>
          </h1>
          <p class="text-lg text-gray-600 max-w-2xl">Manage your projects, track progress, and communicate with your team seamlessly.</p>
        </div>

        <!-- Quick Actions -->
        <div class="flex flex-wrap gap-3">
          <a href="#projects" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-folder-open mr-2"></i>Projects
          </a>
          <a href="#documents" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-file-lines mr-2"></i>Documents
          </a>
          <a href="#feedback" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-comments mr-2"></i>Feedback
          </a>
          <a href="#support" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-question-circle mr-2"></i>Support
          </a>
          <button id="openRequestModal" type="button" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition duration-200">
            <i class="fas fa-folder-plus mr-2"></i>Request Project
          </button>
        </div>
      </div>
    </div>

    <!-- Success Message -->
    <?php if (isset($success_msg)): ?>
      <div class="mb-6 flex items-center gap-3 bg-green-50 border border-green-200 rounded-xl px-6 py-4 text-green-800">
        <i class="fas fa-check-circle text-green-500"></i>
        <span class="font-medium"><?php echo htmlspecialchars($success_msg); ?></span>
      </div>
    <?php endif; ?>

    <!-- Stats Overview -->
    <section class="mb-12" id="stats">
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
        <?php
        $total_projects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE created_by = $user_id OR id IN (SELECT project_id FROM project_users WHERE user_id = $user_id)")->fetch_assoc()['count'];
        $active_projects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE status = 'active' AND (created_by = $user_id OR id IN (SELECT project_id FROM project_users WHERE user_id = $user_id))")->fetch_assoc()['count'];
        $completed_projects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE status = 'completed' AND (created_by = $user_id OR id IN (SELECT project_id FROM project_users WHERE user_id = $user_id))")->fetch_assoc()['count'];
        $total_documents = 0;
        $doc_check = $conn->query("SHOW TABLES LIKE 'project_files'");
        if ($doc_check->num_rows > 0) {
          $total_documents = $conn->query("SELECT COUNT(*) as count FROM project_files WHERE project_id IN (SELECT id FROM projects WHERE created_by = $user_id OR id IN (SELECT project_id FROM project_users WHERE user_id = $user_id))")->fetch_assoc()['count'];
        }
        $stat_items = [
          ['label' => 'Total Projects', 'value' => $total_projects, 'icon' => 'fa-layer-group', 'color' => 'text-blue-600', 'bg' => 'bg-blue-100'],
          ['label' => 'Active Projects', 'value' => $active_projects, 'icon' => 'fa-play-circle', 'color' => 'text-emerald-600', 'bg' => 'bg-emerald-100'],
          ['label' => 'Completed', 'value' => $completed_projects, 'icon' => 'fa-check-circle', 'color' => 'text-purple-600', 'bg' => 'bg-purple-100'],
          ['label' => 'Documents', 'value' => $total_documents, 'icon' => 'fa-file-lines', 'color' => 'text-orange-600', 'bg' => 'bg-orange-100'],
        ];
        foreach ($stat_items as $s): ?>
          <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6 hover:shadow-lg hover:bg-white transition-all duration-200">
            <div class="flex items-center justify-between">
              <div>
                <div class="text-2xl font-bold text-gray-900 mb-1"><?php echo $s['value']; ?></div>
                <div class="text-sm text-gray-600 font-medium"><?php echo $s['label']; ?></div>
              </div>
              <div class="w-12 h-12 <?php echo $s['bg']; ?> rounded-xl flex items-center justify-center">
                <i class="fas <?php echo $s['icon']; ?> <?php echo $s['color']; ?>"></i>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Main Content -->
    <div class="grid gap-8 lg:grid-cols-2">

      <!-- Left Column -->
      <div class="space-y-6">
      <!-- My Projects -->
        <section id="projects">
        <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
          <div class="flex items-center justify-between mb-6">
            <div>
              <h2 class="text-xl font-bold text-gray-900 flex items-center gap-3">
                <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center">
                  <i class="fas fa-folder-tree text-blue-600"></i>
                </div>
                My Projects
              </h2>
              <p class="text-sm text-gray-600 mt-1">Track progress and manage your active projects</p>
            </div>
          </div>

          <?php
          $projects = $conn->query("SELECT p.*, u.full_name as creator_name FROM projects p LEFT JOIN users u ON p.created_by = u.id WHERE p.created_by = $user_id OR p.id IN (SELECT project_id FROM project_users WHERE user_id = $user_id) ORDER BY p.id DESC");
          if ($projects->num_rows > 0):
          ?>
            <div class="space-y-4">
              <?php while ($project = $projects->fetch_assoc()): ?>
                <?php
                // Calculate project progress
                $project_id = $project['id'];
                $task_check = $conn->query("SHOW TABLES LIKE 'tasks'");
                $progress = 0;
                $total_tasks = 0;
                $completed_tasks = 0;

                if ($task_check->num_rows > 0) {
                  $task_stats = $conn->query("SELECT COUNT(*) as total, SUM(status = 'Done') as completed FROM tasks WHERE project_id = $project_id")->fetch_assoc();
                  $total_tasks = $task_stats['total'];
                  $completed_tasks = $task_stats['completed'] ?: 0;
                  $progress = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;
                }

                $status_colors = [
                  'active' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-800', 'border' => 'border-emerald-200'],
                  'completed' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-800', 'border' => 'border-blue-200'],
                  'pending' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-800', 'border' => 'border-amber-200'],
                  'on_hold' => ['bg' => 'bg-orange-100', 'text' => 'text-orange-800', 'border' => 'border-orange-200'],
                  'cancelled' => ['bg' => 'bg-red-100', 'text' => 'text-red-800', 'border' => 'border-red-200']
                ];
                $status_class = $status_colors[$project['status']] ?? ['bg' => 'bg-gray-100', 'text' => 'text-gray-800', 'border' => 'border-gray-200'];
                ?>
                <div class="bg-gray-50/80 rounded-lg p-4 hover:bg-white hover:shadow-sm transition-all duration-200">
                  <div class="flex items-start justify-between mb-3">
                    <div class="flex-1 min-w-0">
                      <h3 class="font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($project['project_name']); ?></h3>
                      <p class="text-sm text-gray-600 mt-1 line-clamp-2"><?php echo htmlspecialchars($project['description']); ?></p>
                    </div>
                    <div class="flex items-center gap-2 ml-4">
                      <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium <?php echo $status_class['bg'] . ' ' . $status_class['text']; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $project['status'])); ?>
                      </span>
                      <a href="project_details.php?project_id=<?php echo $project['id']; ?>" class="inline-flex items-center px-3 py-1.5 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fas fa-eye mr-1"></i>View
                      </a>
                    </div>
                  </div>

                  <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                      <div class="text-xs text-gray-500">
                        <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($project['creator_name']); ?>
                      </div>
                      <div class="text-xs text-gray-500">
                        <i class="fas fa-tasks mr-1"></i><?php echo $completed_tasks; ?>/<?php echo $total_tasks; ?> tasks
                      </div>
                    </div>
                    <div class="flex items-center gap-3">
                      <div class="text-sm font-medium text-gray-900"><?php echo $progress; ?>%</div>
                      <div class="w-24 h-2 bg-gray-200 rounded-full overflow-hidden">
                        <div class="h-full bg-gradient-to-r from-blue-500 to-purple-600 transition-all duration-300" style="width: <?php echo $progress; ?>%"></div>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endwhile; ?>
            </div>
          <?php else: ?>
            <div class="text-center py-12">
              <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-folder-open text-gray-400 text-2xl"></i>
              </div>
              <h3 class="text-lg font-semibold text-gray-900 mb-2">No Projects Yet</h3>
              <p class="text-gray-600 mb-6">You haven't been assigned to any projects yet.</p>
              <button class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <i class="fas fa-plus mr-2"></i>Contact Support
              </button>
            </div>
          <?php endif; ?>
        </div>
      </section>

        <!-- Recent Activity -->
        <section id="timeline">
          <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                <div class="w-8 h-8 bg-indigo-100 rounded-lg flex items-center justify-center">
                  <i class="fas fa-clock text-indigo-600"></i>
                </div>
                Recent Activity
              </h3>
              <span class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded-full">Latest</span>
            </div>

            <div class="space-y-4">
              <?php
              $activities = [];

              // Ensure projects table has a created_at column; if not, synthesize from id
              $projCols = $conn->query("SHOW COLUMNS FROM projects LIKE 'created_at'");
              if ($projCols && $projCols->num_rows > 0) {
                $recent_projects = $conn->query("SELECT project_name, created_at, 'project_created' as type FROM projects WHERE created_by = $user_id OR id IN (SELECT project_id FROM project_users WHERE user_id = $user_id) ORDER BY created_at DESC LIMIT 5");
              } else {
                $recent_projects = $conn->query("SELECT project_name, NOW() as created_at, 'project_created' as type FROM projects WHERE created_by = $user_id OR id IN (SELECT project_id FROM project_users WHERE user_id = $user_id) ORDER BY id DESC LIMIT 5");
              }
              while ($row = $recent_projects->fetch_assoc()) {
                $activities[] = $row;
              }

              // Get recent file uploads
              $doc_check = $conn->query("SHOW TABLES LIKE 'project_files'");
              if ($doc_check->num_rows > 0) {
                $recent_files = $conn->query("SELECT pf.file_name, pf.uploaded_at as created_at, 'file_uploaded' as type, p.project_name FROM project_files pf JOIN projects p ON pf.project_id = p.id WHERE p.created_by = $user_id OR p.id IN (SELECT project_id FROM project_users WHERE user_id = $user_id) ORDER BY pf.uploaded_at DESC LIMIT 5");
                while ($row = $recent_files->fetch_assoc()) {
                  $activities[] = $row;
                }
              }

              // Sort activities by date
              usort($activities, function ($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
              });

              $shown = 0;
              foreach ($activities as $activity):
                if ($shown >= 8) break;
              ?>
                <div class="flex items-start gap-4 p-4 bg-gray-50/80 rounded-lg hover:bg-white hover:shadow-sm transition-all duration-200">
                  <?php if ($activity['type'] == 'project_created'): ?>
                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                      <i class="fas fa-folder-plus text-blue-600"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm text-gray-900">
                        <span class="font-semibold"><?php echo htmlspecialchars($activity['project_name']); ?></span> project was created
                      </p>
                      <p class="text-xs text-gray-500 mt-1">
                        <i class="far fa-clock mr-1"></i><?php echo date('M d, Y \a\t H:i', strtotime($activity['created_at'])); ?>
                      </p>
                    </div>
                  <?php elseif ($activity['type'] == 'file_uploaded'): ?>
                    <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                      <i class="fas fa-file-arrow-up text-purple-600"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm text-gray-900">
                        <span class="font-semibold"><?php echo htmlspecialchars($activity['file_name']); ?></span> was uploaded to
                        <span class="font-semibold"><?php echo htmlspecialchars($activity['project_name']); ?></span>
                      </p>
                      <p class="text-xs text-gray-500 mt-1">
                        <i class="far fa-clock mr-1"></i><?php echo date('M d, Y \a\t H:i', strtotime($activity['created_at'])); ?>
                      </p>
                    </div>
                  <?php endif; ?>
                </div>
              <?php
                $shown++;
              endforeach;

              if ($shown == 0):
              ?>
                <div class="text-center py-8">
                  <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
                    <i class="fas fa-clock text-gray-400"></i>
                  </div>
                  <p class="text-sm text-gray-600">No recent activity</p>
                  <p class="text-xs text-gray-500 mt-1">Activity will appear here as you work on projects</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </section>
      </div>

      <!-- Right Column -->
      <div class="space-y-6">
  <!-- Documents -->
        <section id="documents">
          <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
              <div class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-file-lines text-purple-600"></i>
              </div>
              Documents
            </h3>
            <span class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded-full">Latest</span>
          </div>

          <?php
          $doc_check = $conn->query("SHOW TABLES LIKE 'project_files'");
          if ($doc_check->num_rows > 0):
            $documents = $conn->query("SELECT pf.*, p.project_name, u.full_name as uploader FROM project_files pf JOIN projects p ON pf.project_id = p.id LEFT JOIN users u ON pf.uploaded_by = u.id WHERE p.created_by = $user_id OR p.id IN (SELECT project_id FROM project_users WHERE user_id = $user_id) ORDER BY pf.uploaded_at DESC LIMIT 6");
            if ($documents->num_rows > 0):
          ?>
              <div class="space-y-3">
                <?php while ($doc = $documents->fetch_assoc()): ?>
                  <?php
                  $file_ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
                  $icon_class = 'fas fa-file';
                  $icon_color = 'text-gray-500';
                  $bg_color = 'bg-gray-100';

                  if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                    $icon_class = 'fas fa-image';
                    $icon_color = 'text-green-600';
                    $bg_color = 'bg-green-100';
                  } elseif ($file_ext === 'pdf') {
                    $icon_class = 'fas fa-file-pdf';
                    $icon_color = 'text-red-600';
                    $bg_color = 'bg-red-100';
                  } elseif (in_array($file_ext, ['doc', 'docx'])) {
                    $icon_class = 'fas fa-file-word';
                    $icon_color = 'text-blue-600';
                    $bg_color = 'bg-blue-100';
                  } elseif ($file_ext === 'dwg') {
                    $icon_class = 'fas fa-drafting-compass';
                    $icon_color = 'text-purple-600';
                    $bg_color = 'bg-purple-100';
                  }
                  ?>
                  <div class="flex items-start gap-3 p-3 bg-gray-50/80 rounded-lg hover:bg-white hover:shadow-sm transition-all duration-200">
                    <div class="w-10 h-10 <?php echo $bg_color; ?> rounded-lg flex items-center justify-center flex-shrink-0">
                      <i class="<?php echo $icon_class; ?> text-sm <?php echo $icon_color; ?>"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-medium text-gray-900 truncate" title="<?php echo htmlspecialchars($doc['file_name']); ?>">
                        <?php echo htmlspecialchars($doc['file_name']); ?>
                      </p>
                      <p class="text-xs text-gray-600 truncate"><?php echo htmlspecialchars($doc['project_name']); ?></p>
                      <div class="flex items-center justify-between mt-2">
                        <span class="text-xs text-gray-500">
                          <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($doc['uploader'] ?? 'Unknown'); ?>
                        </span>
                        <span class="text-xs text-gray-500"><?php echo date('M d', strtotime($doc['uploaded_at'])); ?></span>
                      </div>
                    </div>
                    <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="inline-flex items-center px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700 transition-colors">
                      <i class="fas fa-external-link-alt"></i>
                    </a>
                  </div>
                <?php endwhile; ?>
              </div>
            <?php else: ?>
              <div class="text-center py-8">
                <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
                  <i class="fas fa-file-lines text-gray-400"></i>
                </div>
                <p class="text-sm text-gray-600">No documents yet</p>
              </div>
            <?php endif;
          else: ?>
            <div class="text-center py-8">
              <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
                <i class="fas fa-cog text-gray-400"></i>
              </div>
              <p class="text-sm text-gray-600">Document system not configured</p>
            </div>
          <?php endif; ?>
        </div>
        </section>

        <!-- Feedback Section -->
        <section id="feedback">
          <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
          <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
              <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-comments text-blue-600"></i>
              </div>
              Share Feedback
            </h3>
          </div>

          <!-- Feedback Form -->
            <form method="post" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Project</label>
              <select name="project_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                <option value="">Select a project</option>
                <?php $feedback_projects = $conn->query("SELECT id, project_name FROM projects WHERE created_by = $user_id OR id IN (SELECT project_id FROM project_users WHERE user_id = $user_id)");
                while ($proj = $feedback_projects->fetch_assoc()): ?>
                  <option value="<?php echo $proj['id']; ?>"><?php echo htmlspecialchars($proj['project_name']); ?></option>
                <?php endwhile; ?>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Your Feedback</label>
              <textarea name="feedback_text" required rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors resize-none" placeholder="Share your thoughts about this project..."></textarea>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Rating</label>
              <div class="flex items-center gap-3">
                <div class="rating-stars flex gap-1">
                  <span class="star cursor-pointer text-2xl text-gray-300 hover:text-yellow-400 transition-colors" data-rating="1">★</span>
                  <span class="star cursor-pointer text-2xl text-gray-300 hover:text-yellow-400 transition-colors" data-rating="2">★</span>
                  <span class="star cursor-pointer text-2xl text-gray-300 hover:text-yellow-400 transition-colors" data-rating="3">★</span>
                  <span class="star cursor-pointer text-2xl text-gray-300 hover:text-yellow-400 transition-colors" data-rating="4">★</span>
                  <span class="star cursor-pointer text-2xl text-gray-300 hover:text-yellow-400 transition-colors" data-rating="5">★</span>
                </div>
                <input type="hidden" name="rating" id="rating" value="5">
              </div>
            </div>

            <button type="submit" name="submit_feedback" class="w-full bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700 transition-colors font-medium">
              <i class="fas fa-paper-plane mr-2"></i>Send Feedback
            </button>
          </form>
                        </div>
        </section>
          </div>
        </div>

    <!-- Support & Inquiries Section - Full Width -->
    <section class="mt-8" id="support">
      <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
          <div class="flex items-center justify-between mb-6">
          <h3 class="text-xl font-bold text-gray-900 flex items-center gap-2">
            <div class="w-10 h-10 bg-green-100 rounded-xl flex items-center justify-center">
                <i class="fas fa-circle-question text-green-600"></i>
              </div>
              Support & Inquiries
            </h3>
          </div>

          <!-- Inquiry Form -->
          <form method="post" class="space-y-4 mb-6">
            <div class="grid md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Subject</label>
                <input type="text" name="inquiry_subject" required placeholder="Brief subject line" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Related Project</label>
                <select name="inquiry_project_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                  <option value="">General Inquiry</option>
                  <?php $inquiry_projects = $conn->query("SELECT id, project_name FROM projects WHERE created_by = $user_id OR id IN (SELECT project_id FROM project_users WHERE user_id = $user_id)");
                  while ($proj = $inquiry_projects->fetch_assoc()): ?>
                    <option value="<?php echo $proj['id']; ?>"><?php echo htmlspecialchars($proj['project_name']); ?></option>
                  <?php endwhile; ?>
                </select>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Send To Senior Architect (optional)</label>
              <select name="recipient_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                <option value="">Any/Unassigned</option>
                <?php foreach ($senior_architects as $sa): ?>
                  <option value="<?php echo (int)$sa['id']; ?>"><?php echo htmlspecialchars($sa['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Message</label>
              <textarea name="inquiry_message" required rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors resize-none" placeholder="Describe your question or concern in detail..."></textarea>
            </div>

            <button type="submit" name="submit_inquiry" class="w-full bg-green-600 text-white py-2 px-4 rounded-lg hover:bg-green-700 transition-colors font-medium">
              <i class="fas fa-paper-plane mr-2"></i>Send Inquiry
            </button>
          </form>

          <!-- Recent Inquiries -->
          <div class="border-t border-gray-200 pt-6">
            <h4 class="text-sm font-semibold text-gray-900 mb-4">Recent Inquiries</h4>
            <?php
            $inquiry_check = $conn->query("SHOW TABLES LIKE 'client_inquiries'");
            if ($inquiry_check->num_rows > 0):
              $recent_inquiries = $conn->query("SELECT ci.*, p.project_name FROM client_inquiries ci LEFT JOIN projects p ON ci.project_id = p.id WHERE ci.client_id = $user_id ORDER BY ci.created_at DESC LIMIT 5");
              if ($recent_inquiries->num_rows > 0):
            ?>
                <div class="space-y-3">
                  <?php while ($inquiry = $recent_inquiries->fetch_assoc()): ?>
                    <div class="p-4 bg-gray-50/80 rounded-lg">
                      <div class="flex items-start justify-between mb-2">
                        <div class="flex-1">
                          <h5 class="font-medium text-gray-900"><?php echo htmlspecialchars($inquiry['subject']); ?></h5>
                          <p class="text-sm text-gray-600 mt-1 line-clamp-2"><?php echo htmlspecialchars(substr($inquiry['message'], 0, 120)) . (strlen($inquiry['message']) > 120 ? '...' : ''); ?></p>
                        </div>
                        <div class="text-right ml-4">
                          <?php
                          $status_colors = [
                            'open' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-800'],
                            'in_progress' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-800'],
                            'resolved' => ['bg' => 'bg-green-100', 'text' => 'text-green-800']
                          ];
                          $status_class = $status_colors[$inquiry['status']] ?? ['bg' => 'bg-gray-100', 'text' => 'text-gray-800'];
                          ?>
                          <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $status_class['bg'] . ' ' . $status_class['text']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $inquiry['status'])); ?>
                          </span>
                        </div>
                      </div>
                      <div class="flex items-center justify-between text-xs text-gray-500 mt-3">
                        <span><?php echo $inquiry['project_name'] ? htmlspecialchars($inquiry['project_name']) : 'General'; ?></span>
                        <span><?php echo date('M d, Y', strtotime($inquiry['created_at'])); ?></span>
                      </div>
                    </div>
                  <?php endwhile; ?>
                </div>
              <?php else: ?>
                <div class="text-center py-6">
                  <div class="w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-2">
                    <i class="fas fa-circle-question text-gray-400"></i>
                  </div>
                  <p class="text-sm text-gray-600">No inquiries yet</p>
                </div>
              <?php endif;
            else: ?>
              <div class="text-center py-6">
                <div class="w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-2">
                  <i class="fas fa-circle-question text-gray-400"></i>
                </div>
                <p class="text-sm text-gray-600">No inquiries yet</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <!-- Available Senior Architects & Project Request -->
      <section class="mt-8">
        <div class="grid gap-6 lg:grid-cols-2">
          <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2"><i class="fas fa-user-tie text-indigo-600"></i> Available Senior Architects</h3>
            <?php if (count($senior_architects) > 0): ?>
              <div class="space-y-3">
                <?php foreach ($senior_architects as $sa): ?>
                  <?php
                    $sa_id = (int)$sa['id'];
                    // Compute workload: projects where SA is linked in project_users OR creator, excluding completed/cancelled
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
                    <option value="fit_out">Fit In</option>
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
      </section>

      <!-- Recent Project Requests -->
      <section class="mt-6">
        <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
          <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2"><i class="fas fa-clock text-indigo-600"></i> Recent Project Requests</h3>
          <?php
          $recent_reqs = [];
          $req_tbl = $conn->query("SHOW TABLES LIKE 'project_requests'");
          if ($req_tbl && $req_tbl->num_rows > 0) {
            $stmtR = $conn->prepare("SELECT pr.*, u.$USERS_NAME_EXPR AS sa_name FROM project_requests pr LEFT JOIN users u ON u.$USERS_PK = pr.senior_architect_id WHERE pr.client_id = ? ORDER BY pr.created_at DESC LIMIT 8");
            $stmtR->bind_param('i', $user_id);
            $stmtR->execute();
            $recent_reqs = $stmtR->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtR->close();
          }
          if (empty($recent_reqs)): ?>
            <div class="text-sm text-gray-600">No requests yet.</div>
          <?php else: ?>
            <div class="space-y-3">
              <?php foreach ($recent_reqs as $r): ?>
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
      </section>
  </div>
</div>

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
      <div class="p-5 grid gap-6 md:grid-cols-2">
        <div>
          <h4 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><i class="fas fa-user-tie text-indigo-600"></i> Available Senior Architects</h4>
          <div class="space-y-2 max-h-72 overflow-auto pr-1">
            <?php if (count($senior_architects) > 0): ?>
              <?php foreach ($senior_architects as $sa): ?>
                <?php
                  $sa_id = (int)$sa['id'];
                  $status_filter = "(p.status IS NULL OR p.status NOT IN ('completed','cancelled'))";
                  $created_by_cond = $HAS_CREATED_BY ? "p.created_by = $sa_id" : "0";
                  $work_sql = "SELECT COUNT(DISTINCT p.$PROJECTS_PK) AS c FROM projects p LEFT JOIN project_users pu ON pu.project_id = p.$PROJECTS_PK AND pu.user_id = $sa_id WHERE ($created_by_cond OR pu.user_id IS NOT NULL) AND $status_filter";
                  $work_res = $conn->query($work_sql);
                  $work_cnt = $work_res ? (int)$work_res->fetch_assoc()['c'] : 0;
                ?>
                <button type="button" class="w-full flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 select-sa" data-sa="<?php echo (int)$sa['id']; ?>">
                  <div class="text-left">
                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($sa['name']); ?></div>
                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($sa['position'] ?? 'Senior Architect'); ?></div>
                  </div>
                  <div class="text-sm text-gray-700"><i class="fas fa-briefcase mr-1 text-indigo-600"></i><?php echo $work_cnt; ?> active</div>
                </button>
              <?php endforeach; ?>
            <?php else: ?>
              <p class="text-sm text-gray-600">No Senior Architects found.</p>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <h4 class="text-sm font-semibold text-gray-900 mb-3">Fill in request details</h4>
          <form method="post" class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Senior Architect</label>
              <select name="sa_id" id="modal_sa_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                <option value="">Auto-assign (least loaded)</option>
                <?php foreach ($senior_architects as $sa): ?>
                  <option value="<?php echo (int)$sa['id']; ?>"><?php echo htmlspecialchars($sa['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Project Name</label>
              <input type="text" name="req_project_name" required class="w-full px-3 py-2 border rounded-lg" placeholder="e.g., Residential Villa" />
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Project Type</label>
                <select name="req_project_type" required class="w-full px-3 py-2 border rounded-lg">
                  <option value="design_only">Design Only</option>
                  <option value="fit_out">Fit In</option>
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
            <div class="flex gap-2">
              <button type="submit" name="submit_project_request" class="flex-1 bg-green-600 text-white py-2 px-4 rounded-lg hover:bg-green-700 transition-colors font-medium">
                <i class="fas fa-paper-plane mr-2"></i>Send Request
              </button>
              <button type="button" data-close class="px-4 py-2 border rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
  
</div>

<!-- Rating Stars Script -->
<script>
  document.addEventListener('DOMContentLoaded', () => {
    // Project Request modal handling
    const openBtn = document.getElementById('openRequestModal');
    const modal = document.getElementById('requestProjectModal');
    const overlay = modal ? modal.querySelector('.modal-overlay') : null;
    const modalSa = document.getElementById('modal_sa_id');
    function openModal(){ if(modal){ modal.classList.remove('hidden'); } }
    function closeModal(){ if(modal){ modal.classList.add('hidden'); } }
    if(openBtn){ openBtn.addEventListener('click', openModal); }
    if(overlay){ overlay.addEventListener('click', (e)=>{ if(e.target===overlay) closeModal(); }); }
    if(modal){
      modal.querySelectorAll('[data-close]')?.forEach(btn=>btn.addEventListener('click', closeModal));
      modal.querySelectorAll('.select-sa')?.forEach(btn=>{
        btn.addEventListener('click', ()=>{
          const id = btn.getAttribute('data-sa');
          if(modalSa){ modalSa.value = id; }
        });
      });
    }

    const stars = document.querySelectorAll('#feedback .star');
    const hidden = document.getElementById('rating');

    function paint(rating) {
      stars.forEach((star, index) => {
        if (index < rating) {
          star.classList.remove('text-gray-300');
          star.classList.add('text-yellow-400');
        } else {
          star.classList.remove('text-yellow-400');
          star.classList.add('text-gray-300');
        }
      });
    }

    stars.forEach(star => {
      star.addEventListener('click', () => {
        const rating = parseInt(star.dataset.rating);
        hidden.value = rating;
        paint(rating);
      });

      star.addEventListener('mouseenter', () => {
        const rating = parseInt(star.dataset.rating);
        paint(rating);
      });

      star.addEventListener('mouseleave', () => {
        const currentRating = parseInt(hidden.value) || 5;
        paint(currentRating);
      });
    });

    // Initialize with default rating
    paint(5);
  });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
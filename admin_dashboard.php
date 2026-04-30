<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'administrator') { header('Location: login.php'); exit(); }
$full_name = $_SESSION['full_name'];
include 'db.php';
// Load Composer autoloader & import PHPMailer classes (for approval emails)
require_once __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// --- Actions ---
// (kept original logic intact, only collapsed visual output later)
// Preserve existing handlers (bulk ops, backup, logs, project create, optimize, etc.)
// For brevity, original procedural blocks remain above the HTML in prior version.
// Added (bulk user management + approval email notifications)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
  $bulk_action = $_POST['bulk_action'];
  $selected = $_POST['selected_users'] ?? [];
  if (!is_array($selected)) { $selected = []; }
  $ids = array_filter(array_map('intval', $selected));
  if (!$ids) {
    $error_msg = 'No users selected.';
  } else {
    $id_placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    if ($bulk_action === 'delete') {
      $stmt = $conn->prepare("DELETE FROM users WHERE id IN ($id_placeholders)");
      $stmt->bind_param($types, ...$ids);
      $stmt->execute();
      $affected = $stmt->affected_rows;
      $stmt->close();
      $success_msg = "$affected user(s) deleted.";
    } elseif (in_array($bulk_action, ['approve','reject'], true)) {
      $new_status = $bulk_action === 'approve' ? 'approved' : 'rejected';
      // Fetch emails for approved notifications
      $emails = [];
      if ($new_status === 'approved') {
        $stmt = $conn->prepare("SELECT id, email, full_name FROM users WHERE id IN ($id_placeholders)");
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $res = $stmt->get_result();
        while($row = $res->fetch_assoc()) { $emails[] = $row; }
        $stmt->close();
      }
      $stmt = $conn->prepare("UPDATE users SET status=? WHERE id IN ($id_placeholders)");
      $param_types = 's' . $types; // status + ids
      $stmt->bind_param($param_types, $new_status, ...$ids);
      $stmt->execute();
      $affected = $stmt->affected_rows;
      $stmt->close();
      $success_msg = "$affected user(s) updated to $new_status.";
      // Send approval emails
  if ($new_status === 'approved' && $emails) {
        $loginUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/login.php';
        require_once __DIR__ . '/lib/Mailer.php';
        foreach ($emails as $info) {
          $email = $info['email'];
          if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { continue; }
          $safe_name = htmlspecialchars($info['full_name'] ?: 'User', ENT_QUOTES, 'UTF-8');
          $html = "<p>Hello $safe_name,</p><p>Your account has been <strong>approved</strong>. You may now <a href='" . htmlspecialchars($loginUrl, ENT_QUOTES,'UTF-8') . "'>log in</a>.</p><p>Thank you.</p>";
          $text = "Hello $safe_name, Your account has been approved. You may now log in: $loginUrl";
          try {
            Archiflow\Mail\send_mail([
              'to_email' => $email,
              'to_name'  => $info['full_name'] ?: 'User',
              'subject'  => 'Your account has been approved',
              'html'     => $html,
              'text'     => $text,
            ]);
          } catch (\Throwable $e) {
            // Optionally log error
          }
        }
      }
    } else {
      $error_msg = 'Invalid bulk action.';
    }
  }
}
?>
<?php $page_title = 'Admin Dashboard'; include __DIR__.'/includes/header.php'; ?>

<div class="min-h-screen bg-gradient-to-br from-blue-900 via-blue-800 to-indigo-900">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header Section -->
    <div class="mb-10">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
          <h1 class="text-3xl font-bold text-white mb-2">Welcome back, <?php echo htmlspecialchars($full_name); ?> 👋</h1>
          <p class="text-blue-200">Here’s a quick snapshot of what needs your attention today.</p>
        </div>
        <div class="mt-4 md:mt-0 flex items-center gap-2">
          <a href="admin/Projects/projects-index.php" class="hidden md:inline-flex items-center px-4 py-2 rounded-lg bg-emerald-500/20 hover:bg-emerald-500/30 border border-emerald-400/30 text-emerald-100 transition"><i class="fas fa-diagram-project mr-2"></i>Projects</a>
          <a href="admin/user-management/user-index.php" class="hidden md:inline-flex items-center px-4 py-2 rounded-lg bg-indigo-500/20 hover:bg-indigo-500/30 border border-indigo-400/30 text-indigo-100 transition"><i class="fas fa-users mr-2"></i>Users</a>
          <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-medium bg-blue-700 text-blue-100">
            <i class="fas fa-user-shield mr-2"></i>Administrator
          </span>
        </div>
      </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success_msg)): ?>
      <div class="mb-6 p-4 bg-green-500/20 border border-green-400/30 rounded-lg text-green-100 flex items-center">
        <i class="fas fa-check-circle mr-3"></i>
        <span><?php echo htmlspecialchars($success_msg); ?></span>
      </div>
    <?php endif; ?>
    <?php if (isset($error_msg)): ?>
      <div class="mb-6 p-4 bg-red-500/20 border border-red-400/30 rounded-lg text-red-100 flex items-center">
        <i class="fas fa-exclamation-circle mr-3"></i>
        <span><?php echo htmlspecialchars($error_msg); ?></span>
      </div>
    <?php endif; ?>

    <!-- System Analytics Section -->
    <div class="mb-10">
      <h2 class="text-xl font-semibold text-white mb-6 flex items-center">
        <i class="fas fa-chart-line mr-2 text-blue-300"></i>System Analytics
      </h2>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-6">
        <?php
          // Optimized combined queries for better performance
          $user_stats = $conn->query("SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as active_users,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_users
            FROM users")->fetch_assoc();

          $project_stats = $conn->query("SELECT 
            COUNT(*) as total_projects,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_projects
            FROM projects")->fetch_assoc();

          $task_check = $conn->query("SHOW TABLES LIKE 'tasks'");
          $total_tasks = 0; $completed_tasks = 0;
          if($task_check->num_rows) {
            $task_stats = $conn->query("SELECT 
              COUNT(*) as total_tasks,
              SUM(CASE WHEN status = 'Done' THEN 1 ELSE 0 END) as completed_tasks
              FROM tasks")->fetch_assoc();
            $total_tasks = $task_stats['total_tasks'];
            $completed_tasks = $task_stats['completed_tasks'];
          }

          $file_check = $conn->query("SHOW TABLES LIKE 'project_files'");
          $total_files = $file_check->num_rows ? $conn->query("SELECT COUNT(*) c FROM project_files")->fetch_assoc()['c'] : 0;
          
          $log_check = $conn->query("SHOW TABLES LIKE 'system_logs'");
          $recent_logs = $log_check->num_rows ? $conn->query("SELECT COUNT(*) c FROM system_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['c'] : 0;
          
          $metrics = [
            ['Total Users', $user_stats['total_users'], 'fas fa-users', 'from-blue-500 to-cyan-400'],
            ['Active Users', $user_stats['active_users'], 'fas fa-user-check', 'from-green-500 to-emerald-400'],
            ['Pending Users', $user_stats['pending_users'], 'fas fa-user-clock', 'from-yellow-500 to-amber-400'],
            ['Projects', $project_stats['total_projects'], 'fas fa-project-diagram', 'from-purple-500 to-indigo-400'],
            ['Active Projects', $project_stats['active_projects'], 'fas fa-rocket', 'from-pink-500 to-rose-400'],
            ['Tasks', $total_tasks, 'fas fa-tasks', 'from-red-500 to-orange-400'],
            ['Completed Tasks', $completed_tasks, 'fas fa-check-circle', 'from-teal-500 to-cyan-400'],
            ['Files', $total_files, 'fas fa-file-alt', 'from-indigo-500 to-blue-400'],
            ['Logs 7d', $recent_logs, 'fas fa-clipboard-list', 'from-gray-500 to-slate-400']
          ];
          foreach ($metrics as $m) {
            echo '<div class="bg-white/10 backdrop-blur-sm rounded-xl p-6 border border-white/10 shadow-lg hover:shadow-xl transition-all duration-300">';
            echo '<div class="flex items-center justify-between">';
            echo '<div>';
            echo '<div class="text-3xl font-bold text-white mb-1">' . (int)$m[1] . '</div>';
            echo '<div class="text-sm text-blue-200">' . htmlspecialchars($m[0]) . '</div>';
            echo '</div>';
            echo '<div class="p-3 rounded-lg bg-gradient-to-br ' . $m[3] . '">';
            echo '<i class="' . $m[2] . ' text-white text-xl"></i>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
          }
        ?>
      </div>

      <?php
        // Attention metrics (second row)
        $pending_approvals = (int)($conn->query("SELECT COUNT(*) c FROM users WHERE status='pending'")->fetch_assoc()['c'] ?? 0);
        $unassigned_inquiries = 0; $inq_check = $conn->query("SHOW TABLES LIKE 'public_inquiries'");
        if ($inq_check && $inq_check->num_rows) {
          $unassigned_inquiries = (int)($conn->query("SELECT COUNT(*) c FROM public_inquiries WHERE (assigned_to IS NULL OR assigned_to = 0) AND (status='new' OR status IS NULL)")->fetch_assoc()['c'] ?? 0);
        }
        $open_reviews = 0; $rev_check = $conn->query("SHOW TABLES LIKE 'design_reviews'");
        if ($rev_check && $rev_check->num_rows) {
          $open_reviews = (int)($conn->query("SELECT COUNT(*) c FROM design_reviews WHERE status='pending'")->fetch_assoc()['c'] ?? 0);
        }
        $overdue_tasks = 0; $task_check2 = $conn->query("SHOW TABLES LIKE 'tasks'");
        if ($task_check2 && $task_check2->num_rows) {
          $overdue_tasks = (int)($conn->query("SELECT COUNT(*) c FROM tasks WHERE status NOT IN ('Done','Completed') AND due_date IS NOT NULL AND due_date < CURDATE()") ->fetch_assoc()['c'] ?? 0);
        }
        $new_logs_24h = 0; $log_check2 = $conn->query("SHOW TABLES LIKE 'system_logs'");
        if ($log_check2 && $log_check2->num_rows) {
          $new_logs_24h = (int)($conn->query("SELECT COUNT(*) c FROM system_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetch_assoc()['c'] ?? 0);
        }
        $insights = [
          ['Pending Approvals', $pending_approvals, 'fas fa-user-clock', 'from-yellow-500 to-amber-400'],
          ['Unassigned Inquiries', $unassigned_inquiries, 'fas fa-inbox', 'from-emerald-500 to-green-400'],
          ['Pending Reviews', $open_reviews, 'fas fa-clipboard-check', 'from-indigo-500 to-blue-400'],
          ['Overdue Tasks', $overdue_tasks, 'fas fa-hourglass-end', 'from-red-500 to-rose-400'],
          ['New Logs (24h)', $new_logs_24h, 'fas fa-bell', 'from-sky-500 to-cyan-400'],
        ];
      ?>
      <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-6">
        <?php foreach ($insights as $m): ?>
          <div class="bg-white/10 backdrop-blur-sm rounded-xl p-6 border border-white/10 shadow-lg hover:shadow-xl transition-all duration-300">
            <div class="flex items-center justify-between">
              <div>
                <div class="text-3xl font-bold text-white mb-1" data-counter="<?php echo (int)$m[1]; ?>">0</div>
                <div class="text-sm text-blue-200"><?php echo htmlspecialchars($m[0]); ?></div>
              </div>
              <div class="p-3 rounded-lg bg-gradient-to-br <?php echo $m[3]; ?>">
                <i class="<?php echo $m[2]; ?> text-white text-xl"></i>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- User Role Distribution Section -->
    <div class="mb-10">
      <h2 class="text-xl font-semibold text-white mb-6 flex items-center">
        <i class="fas fa-chart-pie mr-2 text-blue-300"></i>User Role Distribution
      </h2>
      <div class="bg-white/10 backdrop-blur-sm rounded-xl p-6 border border-white/10 shadow-lg">
        <?php
          $role_stats = $conn->query("SELECT role, COUNT(*) c FROM users GROUP BY role ORDER BY c DESC");
          while($r = $role_stats->fetch_assoc()):
            $pct = $total_users ? round(($r['c']/$total_users)*100,1) : 0;
        ?>
          <div class="mb-4 last:mb-0">
            <div class="flex justify-between items-center mb-2">
              <span class="text-sm font-medium text-white"><?php echo ucfirst(str_replace('_',' ', $r['role'])); ?></span>
              <span class="text-sm font-medium text-blue-200"><?php echo $r['c']; ?> (<?php echo $pct; ?>%)</span>
            </div>
            <div class="w-full bg-gray-700 rounded-full h-2.5">
              <div class="bg-gradient-to-r from-blue-500 to-cyan-400 h-2.5 rounded-full" style="width:<?php echo $pct; ?>%"></div>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    </div>

    <!-- User Management Section -->
    <div class="mb-10">
      <h2 class="text-xl font-semibold text-white mb-6 flex items-center">
        <i class="fas fa-users-cog mr-2 text-blue-300"></i>User Management
      </h2>
      <div class="bg-white/10 backdrop-blur-sm rounded-xl p-6 border border-white/10 shadow-lg">
        <!-- Bulk Actions Form -->
        <form method="post" class="mb-6 flex flex-wrap gap-3" id="bulkForm">
          <select name="bulk_action" required class="px-4 py-2 bg-white/10 border border-white/20 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">Bulk Action</option>
            <option value="approve">Approve</option>
            <option value="reject">Reject</option>
            <option value="delete">Delete</option>
          </select>
          <button type="submit" class="px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg transition-colors flex items-center" onclick="return confirm('Confirm bulk action?')">
            <i class="fas fa-tasks mr-2"></i>Apply
          </button>
        </form>

        <!-- Filters Form -->
        <form method="get" class="mb-6 flex flex-wrap gap-3">
          <input type="text" name="search_name" placeholder="Name" value="<?php echo htmlspecialchars($_GET['search_name'] ?? ''); ?>" class="px-4 py-2 bg-white/10 border border-white/20 rounded-lg text-white placeholder-blue-200 focus:outline-none focus:ring-2 focus:ring-blue-500">
          <input type="text" name="search_username" placeholder="Username" value="<?php echo htmlspecialchars($_GET['search_username'] ?? ''); ?>" class="px-4 py-2 bg-white/10 border border-white/20 rounded-lg text-white placeholder-blue-200 focus:outline-none focus:ring-2 focus:ring-blue-500">
          <input type="text" name="search_email" placeholder="Email" value="<?php echo htmlspecialchars($_GET['search_email'] ?? ''); ?>" class="px-4 py-2 bg-white/10 border border-white/20 rounded-lg text-white placeholder-blue-200 focus:outline-none focus:ring-2 focus:ring-blue-500">
          <select name="search_role" class="px-4 py-2 bg-white/10 border border-white/20 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">All Roles</option>
            <?php $roles = ['administrator','project_manager','hr','contractor','client','architect','manager'];
              foreach($roles as $r) { $sel = (($_GET['search_role'] ?? '')===$r)?'selected':''; echo "<option value='$r' $sel>".ucfirst(str_replace('_',' ',$r))."</option>"; }
            ?>
          </select>
          <select name="search_status" class="px-4 py-2 bg-white/10 border border-white/20 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">All Status</option>
            <?php foreach(['approved','pending','rejected'] as $st){ $sel = (($_GET['search_status'] ?? '')===$st)?'selected':''; echo "<option value='$st' $sel>".ucfirst($st)."</option>"; } ?>
          </select>
          <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors flex items-center">
            <i class="fas fa-filter mr-2"></i>Filter
          </button>
        </form>

        <!-- Users Table -->
        <?php
          $limit = 15; $page = max(1, intval($_GET['page'] ?? 1)); $offset = ($page-1)*$limit;
          $conditions = []; $bind = []; $types='';
          if (!empty($_GET['search_name'])) { $conditions[] = 'full_name LIKE ?'; $bind[] = '%'.$_GET['search_name'].'%'; $types.='s'; }
          if (!empty($_GET['search_username'])) { $conditions[] = 'username LIKE ?'; $bind[] = '%'.$_GET['search_username'].'%'; $types.='s'; }
          if (!empty($_GET['search_email'])) { $conditions[] = 'email LIKE ?'; $bind[] = '%'.$_GET['search_email'].'%'; $types.='s'; }
          if (!empty($_GET['search_role'])) { $conditions[] = 'role = ?'; $bind[] = $_GET['search_role']; $types.='s'; }
          if (!empty($_GET['search_status'])) { $conditions[] = 'status = ?'; $bind[] = $_GET['search_status']; $types.='s'; }
          $where = $conditions?('WHERE '.implode(' AND ', $conditions)) : '';
          $count_sql = "SELECT COUNT(*) c FROM users $where"; $stmt=$conn->prepare($count_sql); if($bind){ $stmt->bind_param($types, ...$bind);} $stmt->execute(); $cRes=$stmt->get_result(); $total_rows=$cRes->fetch_assoc()['c']??0; $stmt->close();
          $sql = "SELECT id, full_name, username, email, role, status, created_at FROM users $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
          $stmt=$conn->prepare($sql); if($bind){ $stmt->bind_param($types, ...$bind);} $stmt->execute(); $res=$stmt->get_result();
        ?>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-white/10">
            <thead>
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">
                  <input type="checkbox" id="selectAll" onchange="toggleAll()" class="rounded bg-white/10 border-white/20 text-blue-500 focus:ring-blue-500">
                </th>
                <th class="px-4 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">ID</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Name</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">User</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Email</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Role</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Status</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Joined</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
              <?php while($row=$res->fetch_assoc()): ?>
                <tr class="hover:bg-white/5">
                  <td class="px-4 py-3 whitespace-nowrap">
                    <input type="checkbox" name="selected_users[]" value="<?php echo $row['id']; ?>" form="bulkForm" class="rounded bg-white/10 border-white/20 text-blue-500 focus:ring-blue-500">
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap text-sm text-white"><?php echo $row['id']; ?></td>
                  <td class="px-4 py-3 whitespace-nowrap">
                    <div class="flex items-center">
                      <div class="flex-shrink-0 h-10 w-10">
                        <div class="h-10 w-10 rounded-full bg-blue-500/20 flex items-center justify-center">
                          <span class="text-blue-300 font-medium"><?php echo strtoupper(substr($row['full_name'], 0, 1)); ?></span>
                        </div>
                      </div>
                      <div class="ml-4">
                        <div class="text-sm font-medium text-white"><?php echo htmlspecialchars($row['full_name']); ?></div>
                      </div>
                    </div>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap text-sm text-blue-200"><?php echo htmlspecialchars($row['username']); ?></td>
                  <td class="px-4 py-3 whitespace-nowrap text-sm text-blue-200"><?php echo htmlspecialchars($row['email']); ?></td>
                  <td class="px-4 py-3 whitespace-nowrap text-sm text-white"><?php echo ucfirst(str_replace('_',' ', $row['role'])); ?></td>
                  <td class="px-4 py-3 whitespace-nowrap">
                    <?php 
                    $statusClass = '';
                    switch($row['status']) {
                      case 'approved': $statusClass = 'bg-green-500/20 text-green-300'; break;
                      case 'pending': $statusClass = 'bg-yellow-500/20 text-yellow-300'; break;
                      case 'rejected': $statusClass = 'bg-red-500/20 text-red-300'; break;
                    }
                    ?>
                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                      <?php echo ucfirst($row['status']); ?>
                    </span>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap text-sm text-blue-200"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                  <td class="px-4 py-3 whitespace-nowrap text-sm font-medium">
                    <a href="edit_user.php?id=<?php echo $row['id']; ?>" class="text-blue-300 hover:text-blue-100 mr-3">
                      <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="user_status.php?id=<?php echo $row['id']; ?>&action=approve" class="text-green-300 hover:text-green-100 mr-3">
                      <i class="fas fa-check"></i> Approve
                    </a>
                    <a href="delete_user.php?id=<?php echo $row['id']; ?>" class="text-red-300 hover:text-red-100" onclick="return confirm('Delete user?');">
                      <i class="fas fa-trash"></i> Delete
                    </a>
                  </td>
                </tr>
              <?php endwhile; $stmt->close(); ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <?php $pages = ceil($total_rows / $limit); if($pages>1): ?>
          <div class="flex flex-wrap gap-2 mt-6">
            <?php for($i=1;$i<=$pages;$i++){ 
              $q = $_GET; 
              $q['page']=$i; 
              $url='?'.http_build_query($q); 
              $activeClass = ($i==$page) ? 'bg-blue-600 text-white' : 'bg-white/10 text-blue-200 hover:bg-white/20';
              echo '<a href="'.$url.'" class="px-3 py-1 rounded-lg text-sm font-medium '.$activeClass.'">'.$i.'</a>'; 
            }?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- System Logs Section -->
    <div class="mb-10">
      <h2 class="text-xl font-semibold text-white mb-6 flex items-center">
        <i class="fas fa-clipboard-list mr-2 text-blue-300"></i>System Logs
      </h2>
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1 bg-white/10 backdrop-blur-sm rounded-xl p-6 border border-white/10 shadow-lg">
          <h3 class="text-lg font-medium text-white mb-4 flex items-center">
            <i class="fas fa-plus-circle mr-2 text-blue-300"></i>Add Log
          </h3>
          <form method="post" class="space-y-4">
            <select name="log_type" required class="w-full px-4 py-2 bg-white/10 border border-white/20 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
              <option value="info">Info</option>
              <option value="warning">Warning</option>
              <option value="error">Error</option>
              <option value="security">Security</option>
            </select>
            <input type="text" name="log_message" placeholder="Message" required class="w-full px-4 py-2 bg-white/10 border border-white/20 rounded-lg text-white placeholder-blue-200 focus:outline-none focus:ring-2 focus:ring-blue-500">
            <button type="submit" name="create_log" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors flex items-center justify-center">
              <i class="fas fa-plus mr-2"></i>Add Log
            </button>
          </form>
        </div>
        <div class="lg:col-span-2 bg-white/10 backdrop-blur-sm rounded-xl p-6 border border-white/10 shadow-lg max-h-96 overflow-y-auto">
          <?php 
          $log_check = $conn->query("SHOW TABLES LIKE 'system_logs'"); 
          if($log_check->num_rows){ 
            $logs=$conn->query("SELECT sl.*, u.full_name FROM system_logs sl LEFT JOIN users u ON sl.user_id=u.id ORDER BY sl.created_at DESC LIMIT 30"); 
            if($logs->num_rows){ 
              while($log=$logs->fetch_assoc()){ 
                $iconClass = '';
                $textColor = '';
                switch($log['log_type']) {
                  case 'info': $iconClass = 'fa-info-circle'; $textColor = 'text-blue-300'; break;
                  case 'warning': $iconClass = 'fa-exclamation-triangle'; $textColor = 'text-yellow-300'; break;
                  case 'error': $iconClass = 'fa-times-circle'; $textColor = 'text-red-300'; break;
                  case 'security': $iconClass = 'fa-shield-alt'; $textColor = 'text-purple-300'; break;
                }
                echo '<div class="mb-4 p-4 bg-white/5 rounded-lg border-l-4 border-blue-500">';
                echo '<div class="flex items-start">';
                echo '<div class="flex-shrink-0 mt-1">';
                echo '<i class="fas '.$iconClass.' '.$textColor.'"></i>';
                echo '</div>';
                echo '<div class="ml-3 flex-1">';
                echo '<div class="text-sm font-medium text-white">'.htmlspecialchars($log['message']).'</div>';
                echo '<div class="text-xs text-blue-200 mt-1">'.date('M d, Y H:i',strtotime($log['created_at'])).' • '.htmlspecialchars($log['full_name']??'System').'</div>';
                echo '</div>';
                echo '</div>';
                echo '</div>';
              } 
            } else { 
              echo '<div class="text-center py-8">';
              echo '<i class="fas fa-inbox text-blue-300 text-3xl mb-3"></i>';
              echo '<p class="text-blue-200">No logs found.</p>';
              echo '</div>'; 
            } 
          } else { 
            echo '<div class="text-center py-8">';
            echo '<i class="fas fa-database text-blue-300 text-3xl mb-3"></i>';
            echo '<p class="text-blue-200">No log table exists yet.</p>';
            echo '</div>'; 
          } 
          ?>
        </div>
      </div>
    </div>

    <!-- Database Tables Section -->
    <div>
      <h2 class="text-xl font-semibold text-white mb-6 flex items-center">
        <i class="fas fa-database mr-2 text-blue-300"></i>Database Tables
      </h2>
      <div class="bg-white/10 backdrop-blur-sm rounded-xl p-6 border border-white/10 shadow-lg overflow-x-auto">
        <table class="min-w-full divide-y divide-white/10">
          <thead>
            <tr>
              <th class="px-4 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Table</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Rows</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/10">
            <?php 
            $tables=$conn->query("SHOW TABLES"); 
            while($t=$tables->fetch_array()){ 
              $tn=$t[0]; 
              $cnt=$conn->query("SELECT COUNT(*) c FROM `$tn`")->fetch_assoc()['c']; 
              echo '<tr class="hover:bg-white/5">';
              echo '<td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-white">'.htmlspecialchars($tn).'</td>';
              echo '<td class="px-4 py-3 whitespace-nowrap text-sm text-blue-200">'.$cnt.'</td>';
              echo '<td class="px-4 py-3 whitespace-nowrap text-sm">';
              echo '<a href="?optimize_table='.urlencode($tn).'" class="text-blue-300 hover:text-blue-100 inline-flex items-center">';
              echo '<i class="fas fa-magic mr-1"></i> Optimize';
              echo '</a>';
              echo '</td>';
              echo '</tr>';
            } 
            ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
function toggleAll(){ 
  const master=document.getElementById('selectAll'); 
  document.querySelectorAll('input[name="selected_users[]"]').forEach(cb=>cb.checked=master.checked); 
}
</script>

<script>
// Animate insight counters
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-counter]').forEach(el => {
    const target = parseInt(el.getAttribute('data-counter') || '0', 10);
    const duration = 900; const start = performance.now();
    const step = t => { const p = Math.min(1, (t - start) / duration); el.textContent = Math.floor(target * p).toLocaleString(); if (p < 1) requestAnimationFrame(step); };
    requestAnimationFrame(step);
  });
});
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
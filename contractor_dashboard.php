<?php
$allowed_roles=['contractor'];
include __DIR__.'/includes/auth_check.php';
$full_name = $_SESSION['full_name'];
$user_id = $_SESSION['user_id'];
include 'db.php';
// Handle site report upload
if (isset($_POST['upload_report'])) {
    $project_id = intval($_POST['report_project_id']);
    $report_title = trim($_POST['report_title']);
    $report_description = trim($_POST['report_description']);
    $report_type = $_POST['report_type'];
    
    if ($report_title && $project_id) {
        // Create site_reports table if it doesn't exist
        $conn->query("CREATE TABLE IF NOT EXISTS site_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            contractor_id INT,
            project_id INT,
            title VARCHAR(255),
            description TEXT,
            report_type ENUM('daily', 'weekly', 'incident', 'progress', 'safety') DEFAULT 'daily',
            file_path VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (contractor_id) REFERENCES users(id),
            FOREIGN KEY (project_id) REFERENCES projects(id)
        )");
        
        $file_path = null;
        // Handle file upload if provided
        if (isset($_FILES['report_file']) && $_FILES['report_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['report_file'];
            $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png', 'image/jpg'];
            $max_size = 10 * 1024 * 1024; // 10MB
            $file_type = mime_content_type($file['tmp_name']);
            
            if (in_array($file_type, $allowed_types) && $file['size'] <= $max_size) {
                $upload_dir = 'uploads/reports/' . $project_id . '/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $filename = basename($file['name']);
                $file_path = $upload_dir . uniqid() . '_' . $filename;
                move_uploaded_file($file['tmp_name'], $file_path);
            }
        }
        
        $stmt = $conn->prepare("INSERT INTO site_reports (contractor_id, project_id, title, description, report_type, file_path) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissss", $user_id, $project_id, $report_title, $report_description, $report_type, $file_path);
        $stmt->execute();
        $stmt->close();
        $success_msg = "Site report uploaded successfully!";
    }
}
// Handle work log entry
if (isset($_POST['add_work_log'])) {
    $project_id = intval($_POST['log_project_id']);
    $work_date = $_POST['work_date'];
    $hours_worked = floatval($_POST['hours_worked']);
    $work_description = trim($_POST['work_description']);
    $materials_used = trim($_POST['materials_used']);
    
    if ($work_description && $project_id && $work_date) {
        // Create work_logs table if it doesn't exist
        $conn->query("CREATE TABLE IF NOT EXISTS work_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            contractor_id INT,
            project_id INT,
            work_date DATE,
            hours_worked DECIMAL(4,2),
            work_description TEXT,
            materials_used TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (contractor_id) REFERENCES users(id),
            FOREIGN KEY (project_id) REFERENCES projects(id)
        )");
        
        $stmt = $conn->prepare("INSERT INTO work_logs (contractor_id, project_id, work_date, hours_worked, work_description, materials_used) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisdss", $user_id, $project_id, $work_date, $hours_worked, $work_description, $materials_used);
        $stmt->execute();
        $stmt->close();
        $success_msg = "Work log entry added successfully!";
    }
}
// Handle task status update
if (isset($_POST['update_task_status'])) {
    $task_id = intval($_POST['task_id']);
    $new_status = $_POST['new_status'];
    $progress_notes = trim($_POST['progress_notes']);
    
    if ($task_id && in_array($new_status, ['To Do', 'In Progress', 'Done'])) {
        // Check if tasks table exists
        $task_check = $conn->query("SHOW TABLES LIKE 'tasks'");
        if ($task_check->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE tasks SET status = ? WHERE id = ? AND assigned_to = ?");
            $stmt->bind_param("sii", $new_status, $task_id, $user_id);
            $stmt->execute();
            $stmt->close();
            
            // Add progress note if provided
            if ($progress_notes) {
                // Create task_progress table if it doesn't exist
                $conn->query("CREATE TABLE IF NOT EXISTS task_progress (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    task_id INT,
                    contractor_id INT,
                    status VARCHAR(50),
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (task_id) REFERENCES tasks(id),
                    FOREIGN KEY (contractor_id) REFERENCES users(id)
                )");
                
                $stmt = $conn->prepare("INSERT INTO task_progress (task_id, contractor_id, status, notes) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iiss", $task_id, $user_id, $new_status, $progress_notes);
                $stmt->execute();
                $stmt->close();
            }
            
            $success_msg = "Task status updated successfully!";
        }
    }
}
// Handle project update submission
if (isset($_POST['submit_project_update'])) {
    $project_id = intval($_POST['update_project_id']);
    $update_details = trim($_POST['update_details']);
    if ($project_id && $update_details) {
        $stmt = $conn->prepare("INSERT INTO contractor_updates (project_id, contractor_id, update_details) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $project_id, $user_id, $update_details);
        $stmt->execute();
        $stmt->close();
        $success_msg = "Project update submitted!";
    }
}
?>
<?php $page_title='Contractor Dashboard'; include __DIR__.'/includes/header.php'; ?>

<div class="min-h-screen bg-gray-50">
  <div class="w-full">
    <!-- Header Section -->
    <div class="bg-white shadow-sm">
      <div class="px-4 sm:px-6 lg:px-8 py-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
          <div>
            <h1 class="text-2xl font-bold text-gray-900">Welcome, <?php echo htmlspecialchars($full_name); ?>!</h1>
            <p class="mt-1 text-sm text-gray-500">Contractor / Site Engineer Dashboard</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Success Message -->
    <?php if (isset($success_msg)): ?>
      <div class="w-full px-4 sm:px-6 lg:px-8 mt-4">
        <div class="bg-green-50 border-l-4 border-green-400 p-4">
          <div class="flex">
            <div class="flex-shrink-0">
              <i class="fas fa-check-circle text-green-400"></i>
            </div>
            <div class="ml-3">
              <p class="text-sm text-green-700"><?php echo htmlspecialchars($success_msg); ?></p>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <div class="w-full px-4 sm:px-6 lg:px-8 mt-6">
      <div class="border-b border-gray-200">
        <nav class="-mb-px flex space-x-8 overflow-x-auto" aria-label="Tabs">
          <a href="#overview" class="border-blue-500 text-blue-600 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
            <i class="fas fa-tachometer-alt mr-2"></i> Overview
          </a>
          <a href="#assigned-tasks" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
            <i class="fas fa-tasks mr-2"></i> My Tasks
          </a>
          <a href="#site-reports" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
            <i class="fas fa-file-alt mr-2"></i> Site Reports
          </a>
          <a href="#work-logs" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
            <i class="fas fa-clipboard-list mr-2"></i> Work Logs
          </a>
          <a href="#project-progress" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
            <i class="fas fa-chart-line mr-2"></i> Progress
          </a>
          <a href="#project-updates" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
            <i class="fas fa-comment-dots mr-2"></i> Updates
          </a>
        </nav>
      </div>
    </div>

    <!-- Main Content -->
    <div class="w-full px-4 sm:px-6 lg:px-8 py-6">
      <!-- Overview Section -->
      <div id="overview" class="mb-10">
        <h2 class="text-lg font-medium text-gray-900 mb-4">Work Overview</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <?php
          // Get contractor's statistics
          $assigned_projects = $conn->query("SELECT COUNT(DISTINCT p.id) as count FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id")->fetch_assoc()['count'];
          
          $assigned_tasks = 0;
          $completed_tasks = 0;
          $pending_tasks = 0;
          $task_check = $conn->query("SHOW TABLES LIKE 'tasks'");
          if ($task_check->num_rows > 0) {
              $assigned_tasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE assigned_to = $user_id")->fetch_assoc()['count'];
              $completed_tasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE assigned_to = $user_id AND status = 'Done'")->fetch_assoc()['count'];
              $pending_tasks = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE assigned_to = $user_id AND status IN ('To Do', 'In Progress')")->fetch_assoc()['count'];
          }
          
          $total_reports = 0;
          $report_check = $conn->query("SHOW TABLES LIKE 'site_reports'");
          if ($report_check->num_rows > 0) {
              $total_reports = $conn->query("SELECT COUNT(*) as count FROM site_reports WHERE contractor_id = $user_id")->fetch_assoc()['count'];
          }
          ?>
          <div class="bg-white overflow-hidden shadow rounded-lg">
            <div class="px-4 py-5 sm:p-6">
              <div class="flex items-center">
                <div class="flex-shrink-0 bg-blue-100 rounded-md p-3">
                  <i class="fas fa-project-diagram text-blue-600 text-xl"></i>
                </div>
                <div class="ml-5 w-0 flex-1">
                  <dl>
                    <dt class="text-sm font-medium text-gray-500 truncate">Active Projects</dt>
                    <dd class="flex items-baseline">
                      <div class="text-2xl font-semibold text-gray-900"><?php echo $assigned_projects; ?></div>
                    </dd>
                  </dl>
                </div>
              </div>
            </div>
          </div>
          
          <div class="bg-white overflow-hidden shadow rounded-lg">
            <div class="px-4 py-5 sm:p-6">
              <div class="flex items-center">
                <div class="flex-shrink-0 bg-green-100 rounded-md p-3">
                  <i class="fas fa-tasks text-green-600 text-xl"></i>
                </div>
                <div class="ml-5 w-0 flex-1">
                  <dl>
                    <dt class="text-sm font-medium text-gray-500 truncate">Assigned Tasks</dt>
                    <dd class="flex items-baseline">
                      <div class="text-2xl font-semibold text-gray-900"><?php echo $assigned_tasks; ?></div>
                    </dd>
                  </dl>
                </div>
              </div>
            </div>
          </div>
          
          <div class="bg-white overflow-hidden shadow rounded-lg">
            <div class="px-4 py-5 sm:p-6">
              <div class="flex items-center">
                <div class="flex-shrink-0 bg-purple-100 rounded-md p-3">
                  <i class="fas fa-check-circle text-purple-600 text-xl"></i>
                </div>
                <div class="ml-5 w-0 flex-1">
                  <dl>
                    <dt class="text-sm font-medium text-gray-500 truncate">Completed Tasks</dt>
                    <dd class="flex items-baseline">
                      <div class="text-2xl font-semibold text-gray-900"><?php echo $completed_tasks; ?></div>
                    </dd>
                  </dl>
                </div>
              </div>
            </div>
          </div>
          
          <div class="bg-white overflow-hidden shadow rounded-lg">
            <div class="px-4 py-5 sm:p-6">
              <div class="flex items-center">
                <div class="flex-shrink-0 bg-yellow-100 rounded-md p-3">
                  <i class="fas fa-file-alt text-yellow-600 text-xl"></i>
                </div>
                <div class="ml-5 w-0 flex-1">
                  <dl>
                    <dt class="text-sm font-medium text-gray-500 truncate">Site Reports</dt>
                    <dd class="flex items-baseline">
                      <div class="text-2xl font-semibold text-gray-900"><?php echo $total_reports; ?></div>
                    </dd>
                  </dl>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Assigned Tasks Section -->
      <div id="assigned-tasks" class="mb-10">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-lg font-medium text-gray-900">My Assigned Tasks</h2>
        </div>
        
        <div class="bg-white shadow overflow-hidden sm:rounded-md">
          <?php
          $task_check = $conn->query("SHOW TABLES LIKE 'tasks'");
          if ($task_check->num_rows > 0):
              $tasks = $conn->query("SELECT t.*, p.project_name FROM tasks t JOIN projects p ON t.project_id = p.id WHERE t.assigned_to = $user_id ORDER BY t.due_date ASC");
              if ($tasks->num_rows > 0):
          ?>
          <ul class="divide-y divide-gray-200">
            <?php while ($task = $tasks->fetch_assoc()): ?>
            <?php
            $priority = 'medium'; // Default priority
            $days_until_due = 999;
            if ($task['due_date']) {
                $days_until_due = (strtotime($task['due_date']) - time()) / (60 * 60 * 24);
                if ($days_until_due < 1) $priority = 'high';
                elseif ($days_until_due < 3) $priority = 'medium';
                else $priority = 'low';
            }
            
            $statusColor = '';
            switch ($task['status']) {
                case 'To Do': $statusColor = 'bg-yellow-100 text-yellow-800'; break;
                case 'In Progress': $statusColor = 'bg-blue-100 text-blue-800'; break;
                case 'Done': $statusColor = 'bg-green-100 text-green-800'; break;
            }
            
            $priorityColor = '';
            switch ($priority) {
                case 'high': $priorityColor = 'text-red-600'; break;
                case 'medium': $priorityColor = 'text-yellow-600'; break;
                case 'low': $priorityColor = 'text-green-600'; break;
            }
            ?>
            <li>
              <div class="px-4 py-4 sm:px-6">
                <div class="flex items-center justify-between">
                  <div class="flex items-center">
                    <div class="min-w-0 flex-1 flex items-center">
                      <div class="flex-shrink-0">
                        <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                          <span class="text-blue-800 font-medium"><?php echo strtoupper(substr($task['title'], 0, 1)); ?></span>
                        </div>
                      </div>
                      <div class="min-w-0 flex-1 px-4">
                        <div>
                          <div class="text-sm font-medium text-blue-600 truncate"><?php echo htmlspecialchars($task['project_name']); ?></div>
                          <p class="mt-1 flex items-center text-sm text-gray-500">
                            <i class="fas fa-tasks flex-shrink-0 mr-1.5 text-gray-400"></i>
                            <span class="truncate"><?php echo htmlspecialchars($task['title']); ?></span>
                          </p>
                        </div>
                      </div>
                    </div>
                    <div>
                      <div class="flex items-center space-x-4">
                        <div class="flex flex-col">
                          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $statusColor; ?>">
                            <?php echo $task['status']; ?>
                          </span>
                          <span class="mt-1 text-xs <?php echo $priorityColor; ?>">
                            <?php echo ucfirst($priority); ?>
                          </span>
                        </div>
                        <div>
                          <?php if ($task['due_date']): ?>
                            <div class="text-sm text-gray-900"><?php echo date('M d, Y', strtotime($task['due_date'])); ?></div>
                            <?php if ($days_until_due < 1): ?>
                              <div class="text-xs text-red-600">Overdue</div>
                            <?php elseif ($days_until_due < 3): ?>
                              <div class="text-xs text-yellow-600">Due Soon</div>
                            <?php endif; ?>
                          <?php else: ?>
                            <div class="text-sm text-gray-500">No due date</div>
                          <?php endif; ?>
                        </div>
                        <div>
                          <button onclick="openTaskModal(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars(addslashes($task['title'])); ?>', '<?php echo $task['status']; ?>')" class="inline-flex items-center px-3 py-1 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            <i class="fas fa-sync-alt mr-1"></i> Update
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <?php if ($task['description']): ?>
                <div class="mt-2">
                  <div class="text-sm text-gray-700 pl-14">
                    <?php echo htmlspecialchars($task['description']); ?>
                  </div>
                </div>
                <?php endif; ?>
              </div>
            </li>
            <?php endwhile; ?>
          </ul>
          <?php else: ?>
          <div class="text-center py-12">
            <div class="mx-auto h-12 w-12 rounded-full bg-gray-100 flex items-center justify-center">
              <i class="fas fa-tasks text-gray-400"></i>
            </div>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No tasks assigned yet</h3>
            <p class="mt-1 text-sm text-gray-500">You'll see your assigned tasks here.</p>
          </div>
          <?php endif; else: ?>
          <div class="text-center py-12">
            <div class="mx-auto h-12 w-12 rounded-full bg-gray-100 flex items-center justify-center">
              <i class="fas fa-tasks text-gray-400"></i>
            </div>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No tasks assigned yet</h3>
            <p class="mt-1 text-sm text-gray-500">You'll see your assigned tasks here.</p>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Site Reports Section -->
      <div id="site-reports" class="mb-10">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-lg font-medium text-gray-900">Site Reports</h2>
          <button onclick="openReportModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
            <i class="fas fa-plus mr-2"></i> New Report
          </button>
        </div>
        
        <div class="bg-white shadow overflow-hidden sm:rounded-md">
          <?php
          $report_check = $conn->query("SHOW TABLES LIKE 'site_reports'");
          if ($report_check->num_rows > 0):
              $reports = $conn->query("SELECT sr.*, p.project_name FROM site_reports sr JOIN projects p ON sr.project_id = p.id WHERE sr.contractor_id = $user_id ORDER BY sr.created_at DESC LIMIT 10");
              if ($reports->num_rows > 0):
          ?>
          <ul class="divide-y divide-gray-200">
            <?php while ($report = $reports->fetch_assoc()): ?>
            <li>
              <div class="px-4 py-4 sm:px-6">
                <div class="flex items-center justify-between">
                  <div class="flex items-center">
                    <div class="min-w-0 flex-1 flex items-center">
                      <div class="flex-shrink-0">
                        <div class="h-10 w-10 rounded-full bg-green-100 flex items-center justify-center">
                          <i class="fas fa-file-alt text-green-600"></i>
                        </div>
                      </div>
                      <div class="min-w-0 flex-1 px-4">
                        <div>
                          <div class="text-sm font-medium text-green-600 truncate"><?php echo htmlspecialchars($report['project_name']); ?></div>
                          <p class="mt-1 flex items-center text-sm text-gray-500">
                            <i class="fas fa-file-alt flex-shrink-0 mr-1.5 text-gray-400"></i>
                            <span class="truncate"><?php echo htmlspecialchars($report['title']); ?></span>
                          </p>
                        </div>
                      </div>
                    </div>
                    <div>
                      <div class="flex items-center space-x-4">
                        <div>
                          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            <?php echo ucfirst($report['report_type']); ?>
                          </span>
                        </div>
                        <div>
                          <div class="text-sm text-gray-900"><?php echo date('M d, Y H:i', strtotime($report['created_at'])); ?></div>
                        </div>
                        <div>
                          <?php if ($report['file_path']): ?>
                            <a href="<?php echo htmlspecialchars($report['file_path']); ?>" target="_blank" class="inline-flex items-center px-3 py-1 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                              <i class="fas fa-download mr-1"></i> Download
                            </a>
                          <?php else: ?>
                            <span class="text-sm text-gray-500">No file</span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <?php if ($report['description']): ?>
                <div class="mt-2">
                  <div class="text-sm text-gray-700 pl-14">
                    <?php echo htmlspecialchars($report['description']); ?>
                  </div>
                </div>
                <?php endif; ?>
              </div>
            </li>
            <?php endwhile; ?>
          </ul>
          <?php else: ?>
          <div class="text-center py-12">
            <div class="mx-auto h-12 w-12 rounded-full bg-gray-100 flex items-center justify-center">
              <i class="fas fa-file-alt text-gray-400"></i>
            </div>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No reports uploaded yet</h3>
            <p class="mt-1 text-sm text-gray-500">Upload your first site report.</p>
          </div>
          <?php endif; else: ?>
          <div class="text-center py-12">
            <div class="mx-auto h-12 w-12 rounded-full bg-gray-100 flex items-center justify-center">
              <i class="fas fa-file-alt text-gray-400"></i>
            </div>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No reports uploaded yet</h3>
            <p class="mt-1 text-sm text-gray-500">Upload your first site report.</p>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Work Logs Section -->
      <div id="work-logs" class="mb-10">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-lg font-medium text-gray-900">Daily Work Logs</h2>
          <button onclick="openWorkLogModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
            <i class="fas fa-plus mr-2"></i> Add Work Log
          </button>
        </div>
        
        <div class="bg-white shadow overflow-hidden sm:rounded-md">
          <?php
          $log_check = $conn->query("SHOW TABLES LIKE 'work_logs'");
          if ($log_check->num_rows > 0):
              $logs = $conn->query("SELECT wl.*, p.project_name FROM work_logs wl JOIN projects p ON wl.project_id = p.id WHERE wl.contractor_id = $user_id ORDER BY wl.work_date DESC LIMIT 15");
              if ($logs->num_rows > 0):
          ?>
          <ul class="divide-y divide-gray-200">
            <?php while ($log = $logs->fetch_assoc()): ?>
            <li>
              <div class="px-4 py-4 sm:px-6">
                <div class="flex items-center justify-between">
                  <div class="flex items-center">
                    <div class="min-w-0 flex-1 flex items-center">
                      <div class="flex-shrink-0">
                        <div class="h-10 w-10 rounded-full bg-purple-100 flex items-center justify-center">
                          <i class="fas fa-clipboard-list text-purple-600"></i>
                        </div>
                      </div>
                      <div class="min-w-0 flex-1 px-4">
                        <div>
                          <div class="text-sm font-medium text-purple-600 truncate"><?php echo htmlspecialchars($log['project_name']); ?></div>
                          <p class="mt-1 flex items-center text-sm text-gray-500">
                            <i class="fas fa-calendar flex-shrink-0 mr-1.5 text-gray-400"></i>
                            <span class="truncate"><?php echo date('M d, Y', strtotime($log['work_date'])); ?></span>
                          </p>
                        </div>
                      </div>
                    </div>
                    <div>
                      <div class="flex items-center space-x-4">
                        <div>
                          <div class="text-sm text-gray-900"><?php echo $log['hours_worked']; ?> hours</div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="mt-2">
                  <div class="text-sm text-gray-700 pl-14">
                    <div class="font-medium text-gray-900">Work Description:</div>
                    <div><?php echo htmlspecialchars($log['work_description']); ?></div>
                    <?php if ($log['materials_used']): ?>
                    <div class="font-medium text-gray-900 mt-2">Materials Used:</div>
                    <div><?php echo htmlspecialchars($log['materials_used']); ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </li>
            <?php endwhile; ?>
          </ul>
          <?php else: ?>
          <div class="text-center py-12">
            <div class="mx-auto h-12 w-12 rounded-full bg-gray-100 flex items-center justify-center">
              <i class="fas fa-clipboard-list text-gray-400"></i>
            </div>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No work logs recorded yet</h3>
            <p class="mt-1 text-sm text-gray-500">Add your first work log entry.</p>
          </div>
          <?php endif; else: ?>
          <div class="text-center py-12">
            <div class="mx-auto h-12 w-12 rounded-full bg-gray-100 flex items-center justify-center">
              <i class="fas fa-clipboard-list text-gray-400"></i>
            </div>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No work logs recorded yet</h3>
            <p class="mt-1 text-sm text-gray-500">Add your first work log entry.</p>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Project Progress Section -->
      <div id="project-progress" class="mb-10">
        <h2 class="text-lg font-medium text-gray-900 mb-4">Project Progress Overview</h2>
        
        <div class="bg-white shadow overflow-hidden sm:rounded-md">
          <?php
          $projects = $conn->query("SELECT p.id, p.project_name, p.status FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id");
          if ($projects->num_rows > 0):
          ?>
          <ul class="divide-y divide-gray-200">
            <?php while ($project = $projects->fetch_assoc()): ?>
            <?php
            $project_id = $project['id'];
            $my_tasks = 0;
            $completed_tasks = 0;
            $total_hours = 0;
            
            if ($task_check->num_rows > 0) {
                $task_stats = $conn->query("SELECT COUNT(*) as total, SUM(status = 'Done') as completed FROM tasks WHERE project_id = $project_id AND assigned_to = $user_id")->fetch_assoc();
                $my_tasks = $task_stats['total'];
                $completed_tasks = $task_stats['completed'] ?: 0;
            }
            
            if ($log_check->num_rows > 0) {
                $hours_result = $conn->query("SELECT SUM(hours_worked) as total FROM work_logs WHERE project_id = $project_id AND contractor_id = $user_id")->fetch_assoc();
                $total_hours = $hours_result['total'] ?: 0;
            }
            
            $progress = $my_tasks > 0 ? round(($completed_tasks / $my_tasks) * 100) : 0;
            
            $statusColor = '';
            switch ($project['status']) {
                case 'planning': $statusColor = 'bg-yellow-100 text-yellow-800'; break;
                case 'in progress': $statusColor = 'bg-blue-100 text-blue-800'; break;
                case 'completed': $statusColor = 'bg-green-100 text-green-800'; break;
                case 'on hold': $statusColor = 'bg-red-100 text-red-800'; break;
                default: $statusColor = 'bg-gray-100 text-gray-800'; break;
            }
            ?>
            <li>
              <div class="px-4 py-4 sm:px-6">
                <div class="flex items-center justify-between">
                  <div class="flex items-center">
                    <div class="min-w-0 flex-1 flex items-center">
                      <div class="flex-shrink-0">
                        <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center">
                          <span class="text-indigo-800 font-medium"><?php echo strtoupper(substr($project['project_name'], 0, 1)); ?></span>
                        </div>
                      </div>
                      <div class="min-w-0 flex-1 px-4">
                        <div>
                          <div class="text-sm font-medium text-indigo-600 truncate"><?php echo htmlspecialchars($project['project_name']); ?></div>
                        </div>
                      </div>
                    </div>
                    <div>
                      <div class="flex items-center space-x-4">
                        <div>
                          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $statusColor; ?>">
                            <?php echo ucfirst($project['status']); ?>
                          </span>
                        </div>
                        <div>
                          <div class="text-sm text-gray-900"><?php echo number_format($total_hours, 1); ?> hours</div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="mt-4">
                  <div class="text-sm text-gray-700 pl-14">
                    <div class="flex items-center justify-between mb-1">
                      <span class="text-sm font-medium text-gray-700">Task Progress</span>
                      <span class="text-sm font-medium text-gray-900"><?php echo $progress; ?>%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                      <div class="bg-blue-600 h-2.5 rounded-full" style="width: <?php echo $progress; ?>%"></div>
                    </div>
                    <div class="text-xs text-gray-500 mt-1"><?php echo $completed_tasks; ?> of <?php echo $my_tasks; ?> tasks completed</div>
                  </div>
                </div>
              </div>
            </li>
            <?php endwhile; ?>
          </ul>
          <?php else: ?>
          <div class="text-center py-12">
            <div class="mx-auto h-12 w-12 rounded-full bg-gray-100 flex items-center justify-center">
              <i class="fas fa-project-diagram text-gray-400"></i>
            </div>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No projects assigned yet</h3>
            <p class="mt-1 text-sm text-gray-500">You'll see your assigned projects here.</p>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Project Updates Section -->
      <div id="project-updates" class="mb-10">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-lg font-medium text-gray-900">Project Updates</h2>
          <button onclick="openUpdateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
            <i class="fas fa-plus mr-2"></i> Submit Update
          </button>
        </div>
        
        <div class="bg-white shadow overflow-hidden sm:rounded-md">
          <?php
          $updates = $conn->query("SELECT cu.*, p.project_name FROM contractor_updates cu JOIN projects p ON cu.project_id = p.id WHERE cu.contractor_id = $user_id ORDER BY cu.update_time DESC LIMIT 10");
          if ($updates && $updates->num_rows > 0):
          ?>
          <ul class="divide-y divide-gray-200">
            <?php while ($u = $updates->fetch_assoc()): ?>
            <li>
              <div class="px-4 py-4 sm:px-6">
                <div class="flex items-center justify-between">
                  <div class="flex items-center">
                    <div class="min-w-0 flex-1 flex items-center">
                      <div class="flex-shrink-0">
                        <div class="h-10 w-10 rounded-full bg-yellow-100 flex items-center justify-center">
                          <i class="fas fa-comment-dots text-yellow-600"></i>
                        </div>
                      </div>
                      <div class="min-w-0 flex-1 px-4">
                        <div>
                          <div class="text-sm font-medium text-yellow-600 truncate"><?php echo htmlspecialchars($u['project_name']); ?></div>
                          <p class="mt-1 flex items-center text-sm text-gray-500">
                            <i class="fas fa-clock flex-shrink-0 mr-1.5 text-gray-400"></i>
                            <span class="truncate"><?php echo htmlspecialchars($u['update_time']); ?></span>
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="mt-2">
                  <div class="text-sm text-gray-700 pl-14">
                    <?php echo nl2br(htmlspecialchars($u['update_details'])); ?>
                  </div>
                </div>
              </div>
            </li>
            <?php endwhile; ?>
          </ul>
          <?php else: ?>
          <div class="text-center py-12">
            <div class="mx-auto h-12 w-12 rounded-full bg-gray-100 flex items-center justify-center">
              <i class="fas fa-comment-dots text-gray-400"></i>
            </div>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No updates submitted yet</h3>
            <p class="mt-1 text-sm text-gray-500">Submit your first project update.</p>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Site Report Modal -->
<div id="reportModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
    <div class="px-6 py-4 border-b border-gray-200">
      <div class="flex items-center justify-between">
        <h3 class="text-lg font-medium text-gray-900">
          <i class="fas fa-file-alt text-green-600 mr-2"></i>Upload Site Report
        </h3>
        <button onclick="closeReportModal()" class="text-gray-400 hover:text-gray-500">
          <i class="fas fa-times"></i>
        </button>
      </div>
    </div>
    <form method="post" enctype="multipart/form-data" class="px-6 py-4">
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Project</label>
          <select name="report_project_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            <option value="">Select Project</option>
            <?php
            $projects = $conn->query("SELECT p.id, p.project_name FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id");
            while ($proj = $projects->fetch_assoc()):
            ?>
            <option value="<?php echo $proj['id']; ?>"><?php echo htmlspecialchars($proj['project_name']); ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Report Type</label>
          <select name="report_type" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            <option value="daily">Daily Report</option>
            <option value="weekly">Weekly Report</option>
            <option value="progress">Progress Report</option>
            <option value="incident">Incident Report</option>
            <option value="safety">Safety Report</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Report Title</label>
          <input type="text" name="report_title" placeholder="Report Title" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Report Description</label>
          <textarea name="report_description" placeholder="Report Description" required rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Attachment (Optional)</label>
          <input type="file" name="report_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
          <p class="mt-1 text-xs text-gray-500">PDF, DOC, or image file (Max 10MB)</p>
        </div>
      </div>
      <div class="px-6 py-4 bg-gray-50 flex justify-end space-x-3">
        <button type="button" onclick="closeReportModal()" class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
          Cancel
        </button>
        <button type="submit" name="upload_report" class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
          <i class="fas fa-upload mr-2"></i> Upload Report
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Work Log Modal -->
<div id="workLogModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
    <div class="px-6 py-4 border-b border-gray-200">
      <div class="flex items-center justify-between">
        <h3 class="text-lg font-medium text-gray-900">
          <i class="fas fa-clipboard-list text-purple-600 mr-2"></i>Add Work Log Entry
        </h3>
        <button onclick="closeWorkLogModal()" class="text-gray-400 hover:text-gray-500">
          <i class="fas fa-times"></i>
        </button>
      </div>
    </div>
    <form method="post" class="px-6 py-4">
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Project</label>
          <select name="log_project_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            <option value="">Select Project</option>
            <?php
            $projects = $conn->query("SELECT p.id, p.project_name FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id");
            while ($proj = $projects->fetch_assoc()):
            ?>
            <option value="<?php echo $proj['id']; ?>"><?php echo htmlspecialchars($proj['project_name']); ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
            <input type="date" name="work_date" required value="<?php echo date('Y-m-d'); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Hours Worked</label>
            <input type="number" name="hours_worked" placeholder="Hours" step="0.5" min="0" max="24" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Work Description</label>
          <textarea name="work_description" placeholder="Work Description" required rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Materials Used (Optional)</label>
          <textarea name="materials_used" placeholder="Materials Used" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
        </div>
      </div>
      <div class="px-6 py-4 bg-gray-50 flex justify-end space-x-3">
        <button type="button" onclick="closeWorkLogModal()" class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
          Cancel
        </button>
        <button type="submit" name="add_work_log" class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
          <i class="fas fa-save mr-2"></i> Add Log
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Project Update Modal -->
<div id="updateModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
    <div class="px-6 py-4 border-b border-gray-200">
      <div class="flex items-center justify-between">
        <h3 class="text-lg font-medium text-gray-900">
          <i class="fas fa-comment-dots text-yellow-600 mr-2"></i>Submit Project Update
        </h3>
        <button onclick="closeUpdateModal()" class="text-gray-400 hover:text-gray-500">
          <i class="fas fa-times"></i>
        </button>
      </div>
    </div>
    <form method="post" class="px-6 py-4">
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Project</label>
          <select name="update_project_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            <option value="">Select Project</option>
            <?php
            $projects = $conn->query("SELECT p.id, p.project_name FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id");
            while ($proj = $projects->fetch_assoc()):
            ?>
            <option value="<?php echo $proj['id']; ?>"><?php echo htmlspecialchars($proj['project_name']); ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Update Details</label>
          <textarea name="update_details" placeholder="Enter your project update..." required rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
        </div>
      </div>
      <div class="px-6 py-4 bg-gray-50 flex justify-end space-x-3">
        <button type="button" onclick="closeUpdateModal()" class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
          Cancel
        </button>
        <button type="submit" name="submit_project_update" class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
          <i class="fas fa-paper-plane mr-2"></i> Submit Update
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Task Update Modal -->
<div id="taskModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
    <div class="px-6 py-4 border-b border-gray-200">
      <div class="flex items-center justify-between">
        <h3 class="text-lg font-medium text-gray-900">
          <i class="fas fa-sync-alt text-blue-600 mr-2"></i>Update Task Status
        </h3>
        <button onclick="closeTaskModal()" class="text-gray-400 hover:text-gray-500">
          <i class="fas fa-times"></i>
        </button>
      </div>
    </div>
    <form method="post" id="taskForm" class="px-6 py-4">
      <div class="space-y-4">
        <input type="hidden" name="task_id" id="modal_task_id">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Task</label>
          <div id="modal_task_title" class="px-3 py-2 bg-gray-100 rounded-md font-medium"></div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">New Status</label>
          <select name="new_status" id="modal_status" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            <option value="To Do">To Do</option>
            <option value="In Progress">In Progress</option>
            <option value="Done">Done</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Progress Notes</label>
          <textarea name="progress_notes" placeholder="Add notes about your progress..." rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
        </div>
      </div>
      <div class="px-6 py-4 bg-gray-50 flex justify-end space-x-3">
        <button type="button" onclick="closeTaskModal()" class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
          Cancel
        </button>
        <button type="submit" name="update_task_status" class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
          <i class="fas fa-save mr-2"></i> Update Task
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// Modal functions
function openReportModal() {
  document.getElementById('reportModal').classList.remove('hidden');
  document.getElementById('reportModal').classList.add('flex');
}

function closeReportModal() {
  document.getElementById('reportModal').classList.add('hidden');
  document.getElementById('reportModal').classList.remove('flex');
}

function openWorkLogModal() {
  document.getElementById('workLogModal').classList.remove('hidden');
  document.getElementById('workLogModal').classList.add('flex');
}

function closeWorkLogModal() {
  document.getElementById('workLogModal').classList.add('hidden');
  document.getElementById('workLogModal').classList.remove('flex');
}

function openUpdateModal() {
  document.getElementById('updateModal').classList.remove('hidden');
  document.getElementById('updateModal').classList.add('flex');
}

function closeUpdateModal() {
  document.getElementById('updateModal').classList.add('hidden');
  document.getElementById('updateModal').classList.remove('flex');
}

function openTaskModal(taskId, taskTitle, currentStatus) {
  document.getElementById('modal_task_id').value = taskId;
  document.getElementById('modal_task_title').textContent = taskTitle;
  document.getElementById('modal_status').value = currentStatus;
  document.getElementById('taskModal').classList.remove('hidden');
  document.getElementById('taskModal').classList.add('flex');
}

function closeTaskModal() {
  document.getElementById('taskModal').classList.add('hidden');
  document.getElementById('taskModal').classList.remove('flex');
}

// Close modal when clicking outside of it
window.onclick = function(event) {
  if (event.target.classList.contains('bg-opacity-50')) {
    event.target.classList.add('hidden');
    event.target.classList.remove('flex');
  }
}

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape') {
    const modals = document.querySelectorAll('.fixed.inset-0.bg-black.bg-opacity-50');
    modals.forEach(modal => {
      modal.classList.add('hidden');
      modal.classList.remove('flex');
    });
  }
});
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
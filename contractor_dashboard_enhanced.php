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

$page_title = 'Contractor Dashboard';
include __DIR__.'/includes/header.php';
?>

<div class="dashboard-wrapper">
    <!-- Header Section -->
    <div class="dashboard-header">
        <div class="header-content">
            <div class="header-text">
                <h1 class="dashboard-title">Welcome back, <?php echo htmlspecialchars($full_name); ?>!</h1>
                <p class="dashboard-subtitle">Contractor & Site Engineering Dashboard</p>
            </div>
        </div>
    </div>

    <!-- Quick Navigation -->
    <div class="dashboard-nav">
        <a href="#tasks-section" class="nav-item">
            <span class="nav-icon">📋</span>
            <span>My Tasks</span>
        </a>
        <a href="#reports-section" class="nav-item">
            <span class="nav-icon">📊</span>
            <span>Site Reports</span>
        </a>
        <a href="#logs-section" class="nav-item">
            <span class="nav-icon">⏰</span>
            <span>Work Logs</span>
        </a>
        <a href="#progress-section" class="nav-item">
            <span class="nav-icon">📈</span>
            <span>Progress</span>
        </a>
    </div>

    <?php if (isset($success_msg)): ?>
        <div class="alert alert-success">
            <span class="alert-icon">✅</span>
            <?php echo htmlspecialchars($success_msg); ?>
        </div>
    <?php endif; ?>

    <!-- Statistics Overview -->
    <div class="stats-section">
        <h2 class="section-title">Work Overview</h2>
        <div class="stats-grid">
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
            <div class="stat-card">
                <div class="stat-icon">🏗️</div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $assigned_projects; ?></div>
                    <div class="stat-label">Active Projects</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📋</div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $assigned_tasks; ?></div>
                    <div class="stat-label">Assigned Tasks</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">✅</div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $completed_tasks; ?></div>
                    <div class="stat-label">Completed Tasks</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $total_reports; ?></div>
                    <div class="stat-label">Site Reports</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tasks Section -->
    <div class="section-card" id="tasks-section">
        <h2 class="section-title">My Assigned Tasks</h2>
        <?php
        $task_check = $conn->query("SHOW TABLES LIKE 'tasks'");
        if ($task_check->num_rows > 0):
            $tasks_result = $conn->query("SELECT t.*, p.title as project_title FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.assigned_to = $user_id ORDER BY t.due_date ASC");
            
            if ($tasks_result && $tasks_result->num_rows > 0):
        ?>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Project</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Due Date</th>
                            <th>Progress</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($task = $tasks_result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="task-info">
                                        <div class="task-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                        <?php if ($task['description']): ?>
                                            <div class="task-description"><?php echo htmlspecialchars(substr($task['description'], 0, 100)); ?>...</div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="project-name"><?php echo htmlspecialchars($task['project_title'] ?? 'N/A'); ?></span>
                                </td>
                                <td>
                                    <span class="priority-badge priority-<?php echo strtolower($task['priority']); ?>">
                                        <?php echo htmlspecialchars($task['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $task['status'])); ?>">
                                        <?php echo htmlspecialchars($task['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="due-date"><?php echo date('M j, Y', strtotime($task['due_date'])); ?></span>
                                </td>
                                <td>
                                    <div class="progress-container">
                                        <?php
                                        $progress = 0;
                                        if ($task['status'] == 'In Progress') $progress = 50;
                                        elseif ($task['status'] == 'Done') $progress = 100;
                                        ?>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                                        </div>
                                        <span class="progress-text"><?php echo $progress; ?>%</span>
                                    </div>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="openTaskModal(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars($task['status']); ?>')">
                                        Update
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">📋</div>
                <h3>No Tasks Assigned</h3>
                <p>You don't have any tasks assigned at the moment.</p>
            </div>
        <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">📋</div>
                <h3>Tasks Module Not Available</h3>
                <p>The tasks system hasn't been set up yet.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Site Reports Section -->
    <div class="section-card" id="reports-section">
        <h2 class="section-title">Site Reports</h2>
        
        <!-- Upload New Report Form -->
        <div class="form-card">
            <h3 class="form-title">Submit New Site Report</h3>
            <form method="POST" enctype="multipart/form-data" class="report-form">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="report_project_id" class="form-label">Project</label>
                        <select name="report_project_id" id="report_project_id" class="form-input" required>
                            <option value="">Select Project</option>
                            <?php
                            $projects_result = $conn->query("SELECT p.id, p.title FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id");
                            while ($project = $projects_result->fetch_assoc()):
                            ?>
                                <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['title']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="report_type" class="form-label">Report Type</label>
                        <select name="report_type" id="report_type" class="form-input" required>
                            <option value="daily">Daily Report</option>
                            <option value="weekly">Weekly Report</option>
                            <option value="progress">Progress Report</option>
                            <option value="incident">Incident Report</option>
                            <option value="safety">Safety Report</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="report_title" class="form-label">Report Title</label>
                    <input type="text" name="report_title" id="report_title" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label for="report_description" class="form-label">Description</label>
                    <textarea name="report_description" id="report_description" class="form-input" rows="4" placeholder="Describe the work completed, issues encountered, materials used, etc."></textarea>
                </div>
                
                <div class="form-group">
                    <label for="report_file" class="form-label">Attach File (Optional)</label>
                    <input type="file" name="report_file" id="report_file" class="form-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                    <small class="form-help">Supported: PDF, DOC, DOCX, JPG, PNG (Max 10MB)</small>
                </div>
                
                <button type="submit" name="upload_report" class="btn btn-primary">
                    <span class="btn-icon">📤</span>
                    Submit Report
                </button>
            </form>
        </div>

        <!-- Recent Reports List -->
        <div class="reports-list">
            <h3 class="form-title">Recent Reports</h3>
            <?php
            if ($report_check->num_rows > 0):
                $reports_result = $conn->query("SELECT sr.*, p.title as project_title FROM site_reports sr LEFT JOIN projects p ON sr.project_id = p.id WHERE sr.contractor_id = $user_id ORDER BY sr.created_at DESC LIMIT 10");
                
                if ($reports_result && $reports_result->num_rows > 0):
            ?>
                <div class="reports-grid">
                    <?php while ($report = $reports_result->fetch_assoc()): ?>
                        <div class="report-card">
                            <div class="report-header">
                                <h4 class="report-title"><?php echo htmlspecialchars($report['title']); ?></h4>
                                <span class="report-type"><?php echo ucfirst($report['report_type']); ?></span>
                            </div>
                            <div class="report-content">
                                <p class="report-project">Project: <?php echo htmlspecialchars($report['project_title']); ?></p>
                                <p class="report-description"><?php echo htmlspecialchars(substr($report['description'], 0, 150)); ?>...</p>
                                <div class="report-meta">
                                    <span class="report-date"><?php echo date('M j, Y g:i A', strtotime($report['created_at'])); ?></span>
                                    <?php if ($report['file_path']): ?>
                                        <a href="<?php echo htmlspecialchars($report['file_path']); ?>" class="report-attachment" download>
                                            📎 Attachment
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">📊</div>
                    <h3>No Reports Yet</h3>
                    <p>Submit your first site report using the form above.</p>
                </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Task Update Modal -->
<div id="taskModal" class="modal">
    <div class="modal-overlay" onclick="closeTaskModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Update Task Status</h3>
            <button class="modal-close" onclick="closeTaskModal()">&times;</button>
        </div>
        <form method="POST" class="modal-form">
            <input type="hidden" name="task_id" id="modal_task_id">
            
            <div class="form-group">
                <label for="new_status" class="form-label">Status</label>
                <select name="new_status" id="new_status" class="form-input" required>
                    <option value="To Do">To Do</option>
                    <option value="In Progress">In Progress</option>
                    <option value="Done">Done</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="progress_notes" class="form-label">Progress Notes</label>
                <textarea name="progress_notes" id="progress_notes" class="form-input" rows="4" placeholder="Add notes about your progress..."></textarea>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeTaskModal()">Cancel</button>
                <button type="submit" name="update_task_status" class="btn btn-primary">Update Task</button>
            </div>
        </form>
    </div>
</div>

<script>
function openTaskModal(taskId, currentStatus) {
    document.getElementById('modal_task_id').value = taskId;
    document.getElementById('new_status').value = currentStatus;
    document.getElementById('taskModal').style.display = 'block';
}

function closeTaskModal() {
    document.getElementById('taskModal').style.display = 'none';
}

// Smooth scrolling for navigation
document.querySelectorAll('.nav-item').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        const targetId = this.getAttribute('href');
        const targetElement = document.querySelector(targetId);
        if (targetElement) {
            targetElement.scrollIntoView({ behavior: 'smooth' });
        }
    });
});
</script>

<?php include __DIR__.'/includes/footer.php'; ?>

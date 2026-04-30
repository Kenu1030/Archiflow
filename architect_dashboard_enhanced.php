<?php
$allowed_roles = ['architect'];
include __DIR__ . '/includes/auth_check.php';
include 'db.php';

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Architect';

// Handle file upload for plans
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_plans']) && isset($_FILES['plans_file'])) {
    $file = $_FILES['plans_file'];
    $project_id = (int)($_POST['project_id'] ?? 0);
    
    if ($file['error'] === UPLOAD_ERR_OK && $project_id > 0) {
        $upload_dir = 'uploads/plans/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $filename = basename($file['name']);
        $target_path = $upload_dir . uniqid() . '_' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            $stmt = $conn->prepare("INSERT INTO architect_plans (project_id, architect_id, file_name, file_path) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiss", $project_id, $user_id, $filename, $target_path);
            if ($stmt->execute()) {
                echo '<div class="alert alert-success">✅ Plans uploaded successfully!</div>';
            } else {
                echo '<div class="alert alert-danger">❌ Failed to save plan to database.</div>';
            }
            $stmt->close();
        } else {
            echo '<div class="alert alert-danger">❌ Failed to upload file.</div>';
        }
    } else {
        echo '<div class="alert alert-warning">⚠️ Please select a valid file and project.</div>';
    }
}

// Handle plan deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_plan_id'])) {
    $plan_id = (int)$_POST['delete_plan_id'];
    
    $stmt = $conn->prepare("SELECT file_path FROM architect_plans WHERE id = ? AND architect_id = ?");
    $stmt->bind_param("ii", $plan_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($file_path);
    
    if ($stmt->fetch()) {
        $stmt->close();
        
        if (file_exists($file_path)) {
            unlink($file_path);
        }
        
        $stmt2 = $conn->prepare("DELETE FROM architect_plans WHERE id = ? AND architect_id = ?");
        $stmt2->bind_param("ii", $plan_id, $user_id);
        if ($stmt2->execute()) {
            echo '<div class="alert alert-success">✅ Plan deleted successfully!</div>';
        } else {
            echo '<div class="alert alert-danger">❌ Failed to delete plan.</div>';
        }
        $stmt2->close();
    } else {
        $stmt->close();
        echo '<div class="alert alert-warning">⚠️ Plan not found or access denied.</div>';
    }
}

$page_title = 'Architect Dashboard';
include __DIR__.'/includes/header.php';
?>

<div class="dashboard-wrapper">
    <div class="dashboard-container">
        <div class="dashboard-header">
            <h1 class="dashboard-title">Welcome, <?php echo htmlspecialchars($full_name); ?>! 🏗️</h1>
            <p class="dashboard-subtitle">Design, plan, and manage architectural projects with precision and creativity</p>
            
            <div class="dashboard-nav">
                <a href="#overview">📊 Overview</a>
                <a href="#my-projects">🏠 My Projects</a>
                <a href="#tasks">📋 Tasks</a>
                <a href="#plans">📐 Plans</a>
                <a href="#activity">📈 Activity</a>
                <a href="profile.php">👤 Profile</a>
                <a href="logout.php">🚪 Logout</a>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="stats-grid" id="overview">
            <?php
            $my_projects = $conn->prepare("SELECT COUNT(DISTINCT p.id) as count FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = ?");
            $my_projects->bind_param("i", $user_id);
            $my_projects->execute();
            $project_count = $my_projects->get_result()->fetch_assoc()['count'];
            $my_projects->close();

            $my_tasks = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'Done' THEN 1 ELSE 0 END) as completed FROM tasks WHERE assigned_to = ?");
            $my_tasks->bind_param("i", $user_id);
            $my_tasks->execute();
            $task_stats = $my_tasks->get_result()->fetch_assoc();
            $my_tasks->close();

            $my_plans = $conn->prepare("SELECT COUNT(*) as count FROM architect_plans WHERE architect_id = ?");
            $my_plans->bind_param("i", $user_id);
            $my_plans->execute();
            $plan_count = $my_plans->get_result()->fetch_assoc()['count'];
            $my_plans->close();
            ?>
            <div class="stat-card">
                <div class="stat-value"><?php echo $project_count; ?></div>
                <div class="stat-label">Active Projects</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $task_stats['total']; ?></div>
                <div class="stat-label">Total Tasks</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $task_stats['completed']; ?></div>
                <div class="stat-label">Completed Tasks</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $plan_count; ?></div>
                <div class="stat-label">Uploaded Plans</div>
            </div>
        </div>

        <div class="dashboard-grid">
            <!-- My Projects Section -->
            <div class="section-card" id="my-projects">
                <h3>🏠 My Projects</h3>
                <div class="table-container">
                    <table class="table-modern">
                        <thead>
                            <tr>
                                <th>Project Name</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $projects = $conn->prepare("
                                SELECT p.id, p.project_name, p.description 
                                FROM projects p 
                                JOIN project_users pu ON p.id = pu.project_id 
                                WHERE pu.user_id = ? 
                                ORDER BY p.project_name ASC
                            ");
                            $projects->bind_param("i", $user_id);
                            $projects->execute();
                            $result = $projects->get_result();
                            
                            if ($result->num_rows > 0) {
                                while ($project = $result->fetch_assoc()) {
                                    echo '<tr>';
                                    echo '<td>';
                                    echo '<strong>' . htmlspecialchars($project['project_name']) . '</strong>';
                                    if ($project['description']) {
                                        echo '<br><small class="text-gray-500">' . htmlspecialchars(substr($project['description'], 0, 100)) . '...</small>';
                                    }
                                    echo '</td>';
                                    echo '<td>';
                                    echo '<div class="flex gap-2">';
                                    echo '<a href="project_details.php?project_id=' . $project['id'] . '" class="btn btn-sm btn-primary">📋 Details</a>';
                                    echo '<button type="button" onclick="showUploadModal(' . $project['id'] . ', \'' . htmlspecialchars(addslashes($project['project_name'])) . '\')" class="btn btn-sm btn-secondary">📁 Upload Plans</button>';
                                    echo '</div>';
                                    echo '</td>';
                                    echo '</tr>';
                                }
                            } else {
                                echo '<tr><td colspan="2" class="text-gray-500" style="text-align:center;">No projects assigned yet</td></tr>';
                            }
                            $projects->close();
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- My Tasks Section -->
            <div class="section-card" id="tasks">
                <h3>📋 My Tasks</h3>
                <div class="table-container">
                    <table class="table-modern">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Project</th>
                                <th>Status</th>
                                <th>Due Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $tasks = $conn->prepare("
                                SELECT t.title, t.status, t.due_date, p.project_name
                                FROM tasks t 
                                LEFT JOIN projects p ON t.project_id = p.id 
                                WHERE t.assigned_to = ? 
                                ORDER BY t.due_date ASC
                            ");
                            $tasks->bind_param("i", $user_id);
                            $tasks->execute();
                            $task_result = $tasks->get_result();
                            
                            if ($task_result->num_rows > 0) {
                                while ($task = $task_result->fetch_assoc()) {
                                    $status_class = match($task['status']) {
                                        'Done' => 'badge-success',
                                        'In Progress' => 'badge-warning',
                                        default => 'badge-primary'
                                    };
                                    
                                    echo '<tr>';
                                    echo '<td><strong>' . htmlspecialchars($task['title']) . '</strong></td>';
                                    echo '<td>' . htmlspecialchars($task['project_name'] ?? 'No Project') . '</td>';
                                    echo '<td><span class="badge ' . $status_class . '">' . htmlspecialchars($task['status']) . '</span></td>';
                                    echo '<td>' . htmlspecialchars($task['due_date']) . '</td>';
                                    echo '</tr>';
                                }
                            } else {
                                echo '<tr><td colspan="4" class="text-gray-500" style="text-align:center;">No tasks assigned yet</td></tr>';
                            }
                            $tasks->close();
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Uploaded Plans Section -->
        <div class="section-card" id="plans">
            <h3>📐 My Plans</h3>
            <div class="table-container">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Project</th>
                            <th>Upload Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $plans = $conn->prepare("
                            SELECT ap.id, ap.file_name, ap.file_path, ap.uploaded_at, p.project_name
                            FROM architect_plans ap 
                            LEFT JOIN projects p ON ap.project_id = p.id 
                            WHERE ap.architect_id = ? 
                            ORDER BY ap.uploaded_at DESC
                        ");
                        $plans->bind_param("i", $user_id);
                        $plans->execute();
                        $plan_result = $plans->get_result();
                        
                        if ($plan_result->num_rows > 0) {
                            while ($plan = $plan_result->fetch_assoc()) {
                                $file_extension = strtolower(pathinfo($plan['file_name'], PATHINFO_EXTENSION));
                                $is_image = in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp']);
                                
                                echo '<tr>';
                                echo '<td>';
                                if ($is_image) {
                                    echo '<div class="flex items-center gap-3">';
                                    echo '<img src="' . htmlspecialchars($plan['file_path']) . '" alt="plan" style="width:40px;height:40px;object-fit:cover;border-radius:var(--radius-md);">';
                                    echo '<span>' . htmlspecialchars($plan['file_name']) . '</span>';
                                    echo '</div>';
                                } else {
                                    echo '📄 ' . htmlspecialchars($plan['file_name']);
                                }
                                echo '</td>';
                                echo '<td>' . htmlspecialchars($plan['project_name'] ?? 'Unknown Project') . '</td>';
                                echo '<td>' . htmlspecialchars($plan['uploaded_at']) . '</td>';
                                echo '<td>';
                                echo '<div class="flex gap-2">';
                                echo '<a href="' . htmlspecialchars($plan['file_path']) . '" target="_blank" class="btn btn-sm btn-secondary">👁️ View</a>';
                                echo '<button type="button" onclick="deletePlan(' . $plan['id'] . ')" class="btn btn-sm btn-danger">🗑️ Delete</button>';
                                echo '</div>';
                                echo '</td>';
                                echo '</tr>';
                            }
                        } else {
                            echo '<tr><td colspan="4" class="text-gray-500" style="text-align:center;">No plans uploaded yet</td></tr>';
                        }
                        $plans->close();
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Upload Plans Modal -->
<div id="uploadModal" class="modal-overlay">
    <div class="modal-panel" style="padding: var(--space-8); max-width: 500px;">
        <h3 style="margin-bottom: var(--space-6);">📁 Upload Plans</h3>
        <form method="post" enctype="multipart/form-data" id="uploadForm">
            <div class="form-group">
                <label class="form-label">Project</label>
                <input type="text" id="modalProjectName" class="form-control" readonly>
                <input type="hidden" name="project_id" id="modalProjectId">
            </div>
            <div class="form-group">
                <label class="form-label">Select File</label>
                <input type="file" name="plans_file" class="form-control" accept=".pdf,.dwg,.jpg,.jpeg,.png,.gif" required>
                <small class="text-gray-500">Supported formats: PDF, DWG, JPG, PNG, GIF</small>
            </div>
            <div class="form-actions">
                <button type="submit" name="upload_plans" class="btn btn-primary">📤 Upload</button>
                <button type="button" onclick="closeUploadModal()" class="btn btn-secondary">❌ Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function showUploadModal(projectId, projectName) {
    document.getElementById('modalProjectId').value = projectId;
    document.getElementById('modalProjectName').value = projectName;
    document.getElementById('uploadModal').classList.add('active');
}

function closeUploadModal() {
    document.getElementById('uploadModal').classList.remove('active');
}

function deletePlan(planId) {
    if (confirm('Are you sure you want to delete this plan? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'post';
        form.style.display = 'none';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'delete_plan_id';
        input.value = planId;
        
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }
}

// Close modal when clicking outside
document.getElementById('uploadModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeUploadModal();
    }
});
</script>

<?php include __DIR__.'/includes/footer.php'; ?>

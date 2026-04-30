<?php
$allowed_roles=['manager'];
include __DIR__.'/includes/auth_check.php';
$full_name = $_SESSION['full_name'];
$user_id = $_SESSION['user_id'];
include 'db.php';

// Handle team member assignment
if (isset($_POST['assign_team_member'])) {
    $project_id = intval($_POST['project_id']);
    $member_id = intval($_POST['member_id']);
    $role_in_project = trim($_POST['role_in_project']);
    if ($project_id && $member_id && $role_in_project) {
        $check = $conn->prepare("SELECT id FROM project_users WHERE project_id = ? AND user_id = ?");
        $check->bind_param("ii", $project_id, $member_id);
        $check->execute();
        $result = $check->get_result();
        if ($result->num_rows == 0) {
            $stmt = $conn->prepare("INSERT INTO project_users (project_id, user_id, role_in_project) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $project_id, $member_id, $role_in_project);
            $stmt->execute();
            $stmt->close();
            $success_msg = "Team member assigned successfully!";
        } else {
            $error_msg = "User is already assigned to this project.";
        }
        $check->close();
    }
}

// Handle team member removal
if (isset($_POST['remove_team_member'])) {
    $project_id = intval($_POST['remove_project_id']);
    $member_id = intval($_POST['remove_member_id']);
    if ($project_id && $member_id) {
        $stmt = $conn->prepare("DELETE FROM project_users WHERE project_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $project_id, $member_id);
        if ($stmt->execute()) {
            $success_msg = "Team member removed successfully!";
        } else {
            $error_msg = "Failed to remove team member.";
        }
        $stmt->close();
    }
}

// Handle team member role edit
if (isset($_POST['edit_team_member_role'])) {
    $project_id = intval($_POST['edit_project_id']);
    $member_id = intval($_POST['edit_member_id']);
    $new_role = trim($_POST['new_role_in_project']);
    if ($project_id && $member_id && $new_role) {
        $stmt = $conn->prepare("UPDATE project_users SET role_in_project = ? WHERE project_id = ? AND user_id = ?");
        $stmt->bind_param("sii", $new_role, $project_id, $member_id);
        if ($stmt->execute()) {
            $success_msg = "Team member role updated successfully!";
        } else {
            $error_msg = "Failed to update team member role.";
        }
        $stmt->close();
    }
}

// Handle resource allocation
if (isset($_POST['allocate_resource'])) {
    $project_id = intval($_POST['resource_project_id']);
    $resource_name = trim($_POST['resource_name']);
    $resource_type = $_POST['resource_type'];
    $quantity = intval($_POST['quantity']);
    $cost = floatval($_POST['cost']);
    
    if ($project_id && $resource_name && $quantity) {
        // Create resources table if it doesn't exist
        $conn->query("CREATE TABLE IF NOT EXISTS project_resources (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT,
            manager_id INT,
            resource_name VARCHAR(255),
            resource_type ENUM('equipment', 'material', 'labor', 'other') DEFAULT 'other',
            quantity INT,
            cost DECIMAL(10,2),
            allocated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id),
            FOREIGN KEY (manager_id) REFERENCES users(id)
        )");
        
        $stmt = $conn->prepare("INSERT INTO project_resources (project_id, manager_id, resource_name, resource_type, quantity, cost) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissid", $project_id, $user_id, $resource_name, $resource_type, $quantity, $cost);
        $stmt->execute();
        $stmt->close();
        $success_msg = "Resource allocated successfully!";
    }
}

// Handle budget tracking
if (isset($_POST['add_budget_entry'])) {
    $project_id = intval($_POST['budget_project_id']);
    $category = $_POST['budget_category'];
    $amount = floatval($_POST['amount']);
    $description = trim($_POST['budget_description']);
    $entry_type = $_POST['entry_type'];
    
    if ($project_id && $amount && $description) {
        // Create budget_tracking table if it doesn't exist
        $conn->query("CREATE TABLE IF NOT EXISTS budget_tracking (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT,
            manager_id INT,
            category ENUM('labor', 'materials', 'equipment', 'overhead', 'other') DEFAULT 'other',
            entry_type ENUM('budget', 'expense') DEFAULT 'expense',
            amount DECIMAL(10,2),
            description TEXT,
            entry_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id),
            FOREIGN KEY (manager_id) REFERENCES users(id)
        )");
        
        $stmt = $conn->prepare("INSERT INTO budget_tracking (project_id, manager_id, category, entry_type, amount, description) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissds", $project_id, $user_id, $category, $entry_type, $amount, $description);
        $stmt->execute();
        $stmt->close();
        $success_msg = "Budget entry added successfully!";
    }
}
?>
<?php $page_title='Manager Dashboard'; include __DIR__.'/includes/header.php'; ?>
<style>
        .dashboard-container {
            background: var(--form-bg);
            padding: 40px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            max-width: 1200px;
            margin: 40px auto;
            text-align: center;
        }
        .dashboard-container h1 {
            margin-bottom: 20px;
        }
        .dashboard-links a {
            display: inline-block;
            margin: 10px 15px;
            color: #0077b6;
            text-decoration: none;
            font-weight: bold;
        }
        .dashboard-links a:hover {
            text-decoration: underline;
        }
        .manager-section {
            margin: 30px 0;
            text-align: left;
        }
        .manager-section h3 {
            color: #0077b6;
            border-bottom: 2px solid #0077b6;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f0f0f0;
            font-weight: bold;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .stat-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #0077b6;
        }
        .stat-label {
            color: #666;
            margin-top: 5px;
        }
        .form-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .form-section h4 {
            margin-top: 0;
            color: #0077b6;
        }
        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .form-row input, .form-row select, .form-row textarea {
            flex: 1;
            min-width: 150px;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .form-row textarea {
            min-height: 80px;
            resize: vertical;
        }
        .btn {
            background: #0077b6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn:hover {
            background: #005a8b;
        }
        .btn-assign {
            background: #28a745;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        .success-msg {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
        .error-msg {
            background: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-active { background: #d4edda; color: #155724; }
        .status-completed { background: #cce5ff; color: #004085; }
        .status-pending { background: #fff3cd; color: #856404; }
        .progress-bar {
            background: #e9ecef;
            border-radius: 10px;
            height: 20px;
            overflow: hidden;
            margin: 10px 0;
        }
        .progress-fill {
            background: linear-gradient(90deg, #0077b6, #00b4d8);
            height: 100%;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 12px;
        }
        .budget-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }
        .budget-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        .budget-amount {
            font-size: 1.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .budget-positive { color: #28a745; }
        .budget-negative { color: #dc3545; }
        .budget-neutral { color: #0077b6; }
    </style>
    <div class="dashboard-container">
        <h1>Welcome, <?php echo htmlspecialchars($full_name); ?>!</h1>
        <h2>Manager Dashboard</h2>
        <div class="dashboard-links">
            <a href="#my-projects">My Projects</a>
            <a href="team_management.php">Team Management</a>
            <a href="resource_allocation.php">Resources</a>
            <a href="budget_tracking.php">Budget</a>
            <a href="profile.php">Profile</a>
            <a href="logout.php">Logout</a>
        </div>
        
        <?php if (isset($success_msg)): ?>
            <div class="success-msg"><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if (isset($error_msg)): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <!-- Manager Statistics -->
        <div class="manager-section">
            <h3>Management Overview</h3>
            <div class="stats-grid">
                <?php
                // Get manager's statistics
                $managed_projects = $conn->query("SELECT COUNT(DISTINCT p.id) as count FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id AND pu.role_in_project LIKE '%manager%'")->fetch_assoc()['count'];
                
                $team_members = $conn->query("SELECT COUNT(DISTINCT pu2.user_id) as count FROM project_users pu1 JOIN project_users pu2 ON pu1.project_id = pu2.project_id WHERE pu1.user_id = $user_id AND pu1.role_in_project LIKE '%manager%' AND pu2.user_id != $user_id")->fetch_assoc()['count'];
                
                $total_budget = 0;
                $total_expenses = 0;
                $budget_check = $conn->query("SHOW TABLES LIKE 'budget_tracking'");
                if ($budget_check->num_rows > 0) {
                    $budget_result = $conn->query("SELECT SUM(CASE WHEN entry_type = 'budget' THEN amount ELSE 0 END) as budget, SUM(CASE WHEN entry_type = 'expense' THEN amount ELSE 0 END) as expenses FROM budget_tracking bt JOIN project_users pu ON bt.project_id = pu.project_id WHERE pu.user_id = $user_id AND pu.role_in_project LIKE '%manager%'")->fetch_assoc();
                    $total_budget = $budget_result['budget'] ?: 0;
                    $total_expenses = $budget_result['expenses'] ?: 0;
                }
                
                $active_tasks = 0;
                $task_check = $conn->query("SHOW TABLES LIKE 'tasks'");
                if ($task_check->num_rows > 0) {
                    $active_tasks = $conn->query("SELECT COUNT(*) as count FROM tasks t JOIN project_users pu ON t.project_id = pu.project_id WHERE pu.user_id = $user_id AND pu.role_in_project LIKE '%manager%' AND t.status IN ('To Do', 'In Progress')")->fetch_assoc()['count'];
                }
                ?>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $managed_projects; ?></div>
                    <div class="stat-label">Managed Projects</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $team_members; ?></div>
                    <div class="stat-label">Team Members</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">$<?php echo number_format($total_budget - $total_expenses, 0); ?></div>
                    <div class="stat-label">Budget Remaining</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $active_tasks; ?></div>
                    <div class="stat-label">Active Tasks</div>
                </div>
            </div>
        </div>

        <!-- My Projects -->
        <div class="manager-section" id="my-projects">
            <h3>My Managed Projects</h3>
            <?php
            $projects = $conn->query("SELECT p.*, u.full_name as creator_name FROM projects p LEFT JOIN users u ON p.created_by = u.id JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id AND pu.role_in_project LIKE '%manager%' ORDER BY p.id DESC");
            if ($projects->num_rows > 0):
            ?>
            <table>
                <tr>
                    <th>Project Name</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Progress</th>
                    <th>Team Size</th>
                    <th>Budget Status</th>
                </tr>
                <?php while ($project = $projects->fetch_assoc()): ?>
                <?php
                // Calculate project progress
                $project_id = $project['id'];
                $progress = 0;
                $total_tasks = 0;
                $completed_tasks = 0;
                
                if ($task_check->num_rows > 0) {
                    $task_stats = $conn->query("SELECT COUNT(*) as total, SUM(status = 'Done') as completed FROM tasks WHERE project_id = $project_id")->fetch_assoc();
                    $total_tasks = $task_stats['total'];
                    $completed_tasks = $task_stats['completed'] ?: 0;
                    $progress = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;
                }
                
                // Get team size
                $team_size = $conn->query("SELECT COUNT(*) as count FROM project_users WHERE project_id = $project_id")->fetch_assoc()['count'];
                
                // Get budget status
                $project_budget = 0;
                $project_expenses = 0;
                if ($budget_check->num_rows > 0) {
                    $budget_result = $conn->query("SELECT SUM(CASE WHEN entry_type = 'budget' THEN amount ELSE 0 END) as budget, SUM(CASE WHEN entry_type = 'expense' THEN amount ELSE 0 END) as expenses FROM budget_tracking WHERE project_id = $project_id")->fetch_assoc();
                    $project_budget = $budget_result['budget'] ?: 0;
                    $project_expenses = $budget_result['expenses'] ?: 0;
                }
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($project['project_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars(substr($project['description'], 0, 100)) . (strlen($project['description']) > 100 ? '...' : ''); ?></td>
                    <td>
                        <span class="status-badge status-<?php echo $project['status']; ?>">
                            <?php echo ucfirst($project['status']); ?>
                        </span>
                    </td>
                    <td>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $progress; ?>%">
                                <?php echo $progress; ?>%
                            </div>
                        </div>
                        <small><?php echo $completed_tasks; ?> of <?php echo $total_tasks; ?> tasks</small>
                    </td>
                    <td><?php echo $team_size; ?> members</td>
                    <td>
                        <?php if ($project_budget > 0): ?>
                            <div class="budget-amount <?php echo ($project_budget - $project_expenses) >= 0 ? 'budget-positive' : 'budget-negative'; ?>">
                                $<?php echo number_format($project_budget - $project_expenses, 0); ?>
                            </div>
                            <small><?php echo round(($project_expenses / $project_budget) * 100, 1); ?>% used</small>
                        <?php else: ?>
                            <span style="color: #666;">No budget set</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </table>
            <?php else: ?>
            <p style="color: #666;">No projects assigned as manager yet.</p>
            <?php endif; ?>
        </div>

        <!-- Team Management -->
        <div class="manager-section" id="team-management">
            <h3>Team Management</h3>
            
            <!-- Assign Team Member Form -->
            <div class="form-section">
                <h4>Assign Team Member to Project</h4>
                <form method="post">
                    <div class="form-row">
                        <select name="project_id" required>
                            <option value="">Select Project</option>
                            <?php
                            $manager_projects = $conn->query("SELECT p.id, p.project_name FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id AND pu.role_in_project LIKE '%manager%'");
                            while ($proj = $manager_projects->fetch_assoc()):
                            ?>
                            <option value="<?php echo $proj['id']; ?>"><?php echo htmlspecialchars($proj['project_name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                        <select name="member_id" required>
                            <option value="">Select Team Member</option>
                            <?php
                            $team_members = $conn->query("SELECT id, full_name, role FROM users WHERE role != 'client' AND status = 'approved' AND id != $user_id");
                            while ($member = $team_members->fetch_assoc()):
                            ?>
                            <option value="<?php echo $member['id']; ?>"><?php echo htmlspecialchars($member['full_name']) . ' (' . ucfirst(str_replace('_', ' ', $member['role'])) . ')'; ?></option>
                            <?php endwhile; ?>
                        </select>
                        <input type="text" name="role_in_project" placeholder="Role in Project" required>
                    </div>
                    <button type="submit" name="assign_team_member" class="btn">Assign Team Member</button>
                </form>
            </div>

            <!-- Current Team Overview -->
            <h4>Current Team Members</h4>
            <?php
            $team_overview = $conn->query("SELECT p.project_name, u.full_name, u.role, pu.role_in_project FROM project_users pu JOIN projects p ON pu.project_id = p.id JOIN users u ON pu.user_id = u.id JOIN project_users pu2 ON p.id = pu2.project_id WHERE pu2.user_id = $user_id AND pu2.role_in_project LIKE '%manager%' AND pu.user_id != $user_id ORDER BY p.project_name, u.full_name");
            if ($team_overview->num_rows > 0):
            ?>
            <table>
                <tr>
                    <th>Project</th>
                    <th>Team Member</th>
                    <th>Main Role</th>
                    <th>Project Role</th>
                    <th>Actions</th>
                </tr>
                <?php while ($member = $team_overview->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($member['project_name']); ?></td>
                    <td><?php echo htmlspecialchars($member['full_name']); ?></td>
                    <td><?php echo ucfirst(str_replace('_', ' ', $member['role'])); ?></td>
                    <td>
                        <?php if (isset($_POST['edit_mode']) && $_POST['edit_project_id'] == $member['project_id'] && $_POST['edit_member_id'] == $member['user_id']): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="edit_project_id" value="<?php echo $member['project_id']; ?>">
                                <input type="hidden" name="edit_member_id" value="<?php echo $member['user_id']; ?>">
                                <input type="text" name="new_role_in_project" value="<?php echo htmlspecialchars($member['role_in_project']); ?>" required>
                                <button type="submit" name="edit_team_member_role" class="btn-assign" style="background:#0077b6;">Save</button>
                            </form>
                        <?php else: ?>
                            <?php echo htmlspecialchars($member['role_in_project']); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Remove this team member from project?');">
                            <input type="hidden" name="remove_project_id" value="<?php echo $member['project_id']; ?>">
                            <input type="hidden" name="remove_member_id" value="<?php echo $member['user_id']; ?>">
                            <button type="submit" name="remove_team_member" class="btn-assign" style="background:#d90429;">Remove</button>
                        </form>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="edit_mode" value="1">
                            <input type="hidden" name="edit_project_id" value="<?php echo $member['project_id']; ?>">
                            <input type="hidden" name="edit_member_id" value="<?php echo $member['user_id']; ?>">
                            <button type="submit" class="btn-assign" style="background:#ffc107;color:#222;">Edit</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </table>
            <?php else: ?>
            <p style="color: #666;">No team members assigned yet.</p>
            <?php endif; ?>
        </div>

        <!-- Resource Allocation -->
        <div class="manager-section" id="resource-allocation">
            <h3>Resource Allocation</h3>
            
            <!-- Allocate Resource Form -->
            <div class="form-section">
                <h4>Allocate Resource</h4>
                <form method="post">
                    <div class="form-row">
                        <select name="resource_project_id" required>
                            <option value="">Select Project</option>
                            <?php
                            $resource_projects = $conn->query("SELECT p.id, p.project_name FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id AND pu.role_in_project LIKE '%manager%'");
                            while ($proj = $resource_projects->fetch_assoc()):
                            ?>
                            <option value="<?php echo $proj['id']; ?>"><?php echo htmlspecialchars($proj['project_name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                        <input type="text" name="resource_name" placeholder="Resource Name" required>
                        <select name="resource_type" required>
                            <option value="equipment">Equipment</option>
                            <option value="material">Material</option>
                            <option value="labor">Labor</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <input type="number" name="quantity" placeholder="Quantity" min="1" required>
                        <input type="number" name="cost" placeholder="Total Cost" step="0.01" min="0" required>
                    </div>
                    <button type="submit" name="allocate_resource" class="btn">Allocate Resource</button>
                </form>
            </div>

            <!-- Recent Resource Allocations -->
            <h4>Recent Resource Allocations</h4>
            <?php
            $resource_check = $conn->query("SHOW TABLES LIKE 'project_resources'");
            if ($resource_check->num_rows > 0):
                $resources = $conn->query("SELECT pr.*, p.project_name FROM project_resources pr JOIN projects p ON pr.project_id = p.id WHERE pr.manager_id = $user_id ORDER BY pr.allocated_date DESC LIMIT 10");
                if ($resources->num_rows > 0):
            ?>
            <table>
                <tr>
                    <th>Project</th>
                    <th>Resource</th>
                    <th>Type</th>
                    <th>Quantity</th>
                    <th>Cost</th>
                    <th>Date</th>
                </tr>
                <?php while ($resource = $resources->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($resource['project_name']); ?></td>
                    <td><?php echo htmlspecialchars($resource['resource_name']); ?></td>
                    <td><?php echo ucfirst($resource['resource_type']); ?></td>
                    <td><?php echo $resource['quantity']; ?></td>
                    <td>$<?php echo number_format($resource['cost'], 2); ?></td>
                    <td><?php echo date('M d, Y', strtotime($resource['allocated_date'])); ?></td>
                </tr>
                <?php endwhile; ?>
            </table>
            <?php else: ?>
            <p style="color: #666;">No resources allocated yet.</p>
            <?php endif; else: ?>
            <p style="color: #666;">No resources allocated yet.</p>
            <?php endif; ?>
        </div>

        <!-- Budget Tracking -->
        <div class="manager-section" id="budget-tracking">
            <h3>Budget Tracking</h3>
            
            <!-- Add Budget Entry Form -->
            <div class="form-section">
                <h4>Add Budget Entry</h4>
                <form method="post">
                    <div class="form-row">
                        <select name="budget_project_id" required>
                            <option value="">Select Project</option>
                            <?php
                            $budget_projects = $conn->query("SELECT p.id, p.project_name FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id AND pu.role_in_project LIKE '%manager%'");
                            while ($proj = $budget_projects->fetch_assoc()):
                            ?>
                            <option value="<?php echo $proj['id']; ?>"><?php echo htmlspecialchars($proj['project_name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                        <select name="entry_type" required>
                            <option value="budget">Budget Allocation</option>
                            <option value="expense">Expense</option>
                        </select>
                        <select name="budget_category" required>
                            <option value="labor">Labor</option>
                            <option value="materials">Materials</option>
                            <option value="equipment">Equipment</option>
                            <option value="overhead">Overhead</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <input type="number" name="amount" placeholder="Amount" step="0.01" min="0" required>
                        <input type="text" name="budget_description" placeholder="Description" required>
                    </div>
                    <button type="submit" name="add_budget_entry" class="btn">Add Budget Entry</button>
                </form>
            </div>

            <!-- Budget Summary by Project -->
            <h4>Budget Summary</h4>
            <?php
            if ($budget_check->num_rows > 0):
                $budget_summary = $conn->query("SELECT p.project_name, SUM(CASE WHEN bt.entry_type = 'budget' THEN bt.amount ELSE 0 END) as total_budget, SUM(CASE WHEN bt.entry_type = 'expense' THEN bt.amount ELSE 0 END) as total_expenses FROM budget_tracking bt JOIN projects p ON bt.project_id = p.id JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id AND pu.role_in_project LIKE '%manager%' GROUP BY p.id, p.project_name");
                if ($budget_summary->num_rows > 0):
            ?>
            <table>
                <tr>
                    <th>Project</th>
                    <th>Total Budget</th>
                    <th>Total Expenses</th>
                    <th>Remaining</th>
                    <th>Usage %</th>
                </tr>
                <?php while ($budget = $budget_summary->fetch_assoc()): ?>
                <?php
                $remaining = $budget['total_budget'] - $budget['total_expenses'];
                $usage_percent = $budget['total_budget'] > 0 ? round(($budget['total_expenses'] / $budget['total_budget']) * 100, 1) : 0;
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($budget['project_name']); ?></td>
                    <td>$<?php echo number_format($budget['total_budget'], 2); ?></td>
                    <td>$<?php echo number_format($budget['total_expenses'], 2); ?></td>
                    <td class="<?php echo $remaining >= 0 ? 'budget-positive' : 'budget-negative'; ?>">
                        $<?php echo number_format($remaining, 2); ?>
                    </td>
                    <td>
                        <span class="<?php echo $usage_percent > 90 ? 'budget-negative' : ($usage_percent > 75 ? 'budget-neutral' : 'budget-positive'); ?>">
                            <?php echo $usage_percent; ?>%
                        </span>
                    </td>
                </tr>
                <?php endwhile; ?>
            </table>
            <?php else: ?>
            <p style="color: #666;">No budget entries yet.</p>
            <?php endif; else: ?>
            <p style="color: #666;">No budget entries yet.</p>
            <?php endif; ?>
        </div>
    </div>
<?php include __DIR__.'/includes/footer.php'; ?>

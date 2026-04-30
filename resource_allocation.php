<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header('Location: login.php');
    exit();
}
$full_name = $_SESSION['full_name'];
$user_id = $_SESSION['user_id'];
include 'db.php';
// Handle resource allocation (mirrors logic in manager_dashboard.php)
if (isset($_POST['allocate_resource'])) {
    $project_id = intval($_POST['resource_project_id'] ?? 0);
    $resource_name = trim($_POST['resource_name'] ?? '');
    $resource_type = $_POST['resource_type'] ?? 'other';
    $quantity = intval($_POST['quantity'] ?? 0);
    $cost = floatval($_POST['cost'] ?? 0);
    if ($project_id && $resource_name && $quantity > 0) {
        $conn->query("CREATE TABLE IF NOT EXISTS project_resources (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT, manager_id INT, resource_name VARCHAR(255), resource_type ENUM('equipment','material','labor','other') DEFAULT 'other', quantity INT, cost DECIMAL(10,2), allocated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (project_id) REFERENCES projects(id), FOREIGN KEY (manager_id) REFERENCES users(id))");
        $stmt = $conn->prepare("INSERT INTO project_resources (project_id, manager_id, resource_name, resource_type, quantity, cost) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iissid', $project_id, $user_id, $resource_name, $resource_type, $quantity, $cost);
        $stmt->execute();
        $stmt->close();
        $success_msg = 'Resource allocated successfully!';
    } else {
        $error_msg = 'Please fill all required resource fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resource Allocation</title>
    <link rel="stylesheet" href="css/auth.css">
    <link rel="stylesheet" href="css/manager_dashboard.css">
</head>
<body>
    <div class="dashboard-container">
        <h1>Resource Allocation</h1>
        <div class="dashboard-links">
            <a href="manager_dashboard.php">Dashboard Home</a>
            <a href="team_management.php">Team Management</a>
            <a href="budget_tracking.php">Budget</a>
            <a href="profile.php">Profile</a>
            <a href="logout.php">Logout</a>
        </div>
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
</body>
</html>

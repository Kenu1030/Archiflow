<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header('Location: login.php');
    exit();
}
$full_name = $_SESSION['full_name'];
$user_id = $_SESSION['user_id'];
include 'db.php';
// Handle budget entry submission
if (isset($_POST['add_budget_entry'])) {
    $project_id = intval($_POST['budget_project_id'] ?? 0);
    $entry_type = $_POST['entry_type'] ?? 'expense';
    $category = $_POST['budget_category'] ?? 'other';
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['budget_description'] ?? '');
    if ($project_id && $amount > 0 && $description) {
        $conn->query("CREATE TABLE IF NOT EXISTS budget_tracking (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT, manager_id INT, category ENUM('labor','materials','equipment','overhead','other') DEFAULT 'other', entry_type ENUM('budget','expense') DEFAULT 'expense', amount DECIMAL(10,2), description TEXT, entry_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (project_id) REFERENCES projects(id), FOREIGN KEY (manager_id) REFERENCES users(id))");
        $stmt = $conn->prepare("INSERT INTO budget_tracking (project_id, manager_id, category, entry_type, amount, description) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iissds', $project_id, $user_id, $category, $entry_type, $amount, $description);
        $stmt->execute();
        $stmt->close();
        $success_msg = 'Budget entry added!';
    } else {
        $error_msg = 'All budget fields are required.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Budget Tracking</title>
    <link rel="stylesheet" href="css/auth.css">
    <link rel="stylesheet" href="css/manager_dashboard.css">
</head>
<body>
    <div class="dashboard-container">
        <h1>Budget Tracking</h1>
        <div class="dashboard-links">
            <a href="manager_dashboard.php">Dashboard Home</a>
            <a href="team_management.php">Team Management</a>
            <a href="resource_allocation.php">Resources</a>
            <a href="profile.php">Profile</a>
            <a href="logout.php">Logout</a>
        </div>
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
        $budget_check = $conn->query("SHOW TABLES LIKE 'budget_tracking'");
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
</body>
</html>

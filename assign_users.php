<?php
// assign_users.php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['administrator', 'project_manager', 'architect'])) {
    header('Location: login.php');
    exit();
}
include 'db.php';

// Get project ID
if (!isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
    echo "Invalid project.";
    exit();
}
$project_id = intval($_GET['project_id']);

// Handle assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    $user_id = intval($_POST['user_id']);
    $role_in_project = trim($_POST['role_in_project']);
    $stmt = $conn->prepare("INSERT INTO project_users (project_id, user_id, role_in_project) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $project_id, $user_id, $role_in_project);
    $stmt->execute();
    $stmt->close();
    header('Location: assign_users.php?project_id=' . $project_id . '&assigned=1');
    exit();
}

// Get project info
$stmt = $conn->prepare("SELECT project_name FROM projects WHERE id=?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->bind_result($project_name);
$stmt->fetch();
$stmt->close();

// Get all users except clients
$users = $conn->query("SELECT id, full_name, role FROM users WHERE role != 'client' AND status = 'approved'");

// Get assigned users
$assigned = $conn->query("SELECT pu.id, u.full_name, u.role, pu.role_in_project FROM project_users pu JOIN users u ON pu.user_id = u.id WHERE pu.project_id = $project_id");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assign Users to Project</title>
    <link rel="stylesheet" href="css/auth.css">
    <style>
        .assign-container { max-width: 600px; margin: 40px auto; background: var(--form-bg); padding: 30px; border-radius: var(--radius); box-shadow: var(--shadow); }
        .assign-container h2 { text-align: center; }
        .assign-form { margin-bottom: 30px; }
        .assign-form select, .assign-form input { width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid var(--border-color); border-radius: var(--radius); }
        .assign-form button { width: 100%; padding: 12px; background: var(--primary-gradient); border: none; border-radius: var(--radius); color: white; font-weight: bold; cursor: pointer; }
        .assign-form button:hover { background: var(--primary-hover); }
        .assigned-list table { width: 100%; border-collapse: collapse; }
        .assigned-list th, .assigned-list td { padding: 10px; border-bottom: 1px solid #ddd; }
        .assigned-list th { background: #f0f0f0; }
    </style>
</head>
<body>
    <div class="assign-container">
        <h2>Assign Users to Project: <?php echo htmlspecialchars($project_name); ?></h2>
        <form class="assign-form" method="post">
            <label for="user_id">Select User:</label>
            <select name="user_id" id="user_id" required>
                <option value="">-- Select User --</option>
                <?php while ($row = $users->fetch_assoc()): ?>
                <option value="<?php echo $row['id']; ?>"><?php echo htmlspecialchars($row['full_name']) . " (" . $row['role'] . ")"; ?></option>
                <?php endwhile; ?>
            </select>
            <label for="role_in_project">Role in Project:</label>
            <input type="text" name="role_in_project" id="role_in_project" placeholder="e.g. Lead Architect, Site Engineer" required>
            <button type="submit">Assign User</button>
        </form>
        <div class="assigned-list">
            <h3>Assigned Users</h3>
            <table>
                <tr><th>Name</th><th>Main Role</th><th>Role in Project</th></tr>
                <?php while ($row = $assigned->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['role']); ?></td>
                    <td><?php echo htmlspecialchars($row['role_in_project']); ?></td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>
        <a href="projects.php" style="display:block;text-align:center;margin-top:20px;color:#0077b6;">Back to Projects</a>
    </div>
</body>
</html>

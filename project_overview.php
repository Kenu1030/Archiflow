<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'administrator') {
    header('Location: login.php');
    exit();
}
include 'db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Project Overview</title>
    <link rel="stylesheet" href="css/auth.css">
    <style>
        .overview-container { max-width: 900px; margin: 40px auto; background: var(--form-bg); padding: 30px; border-radius: var(--radius); box-shadow: var(--shadow); }
        .overview-container h2 { text-align: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; border-bottom: 1px solid #ddd; }
        th { background: #f0f0f0; }
    </style>
</head>
<body>
    <div class="overview-container">
        <h2>Project Overview</h2>
        <table>
            <tr><th>ID</th><th>Name</th><th>Description</th><th>Status</th><th>Creator</th><th>Actions</th></tr>
            <?php
            $result = $conn->query("SELECT p.id, p.project_name, p.description, p.status, u.full_name AS creator FROM projects p LEFT JOIN users u ON p.created_by = u.id ORDER BY p.id DESC");
            while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?php echo $row['id']; ?></td>
                <td><?php echo htmlspecialchars($row['project_name']); ?></td>
                <td><?php echo htmlspecialchars($row['description']); ?></td>
                <td><?php echo htmlspecialchars($row['status']); ?></td>
                <td><?php echo htmlspecialchars($row['creator']); ?></td>
                <td>
                    <a href="project_details.php?project_id=<?php echo $row['id']; ?>" style="color:#0077b6;">View</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>
        <a href="admin_dashboard.php" style="display:block;text-align:center;margin-top:20px;color:#0077b6;">Back to Dashboard</a>
    </div>
</body>
</html>

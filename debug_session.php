<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Session Debug</title>
</head>
<body>
    <h1>Session Debug Information</h1>
    <h2>Current Session Variables:</h2>
    <pre><?php var_dump($_SESSION); ?></pre>
    
    <h2>Authentication Check Results:</h2>
    <?php
    $user_type = $_SESSION['user_type'] ?? 'NOT SET';
    $position = $_SESSION['position'] ?? 'NOT SET';
    $logged_in = $_SESSION['logged_in'] ?? 'NOT SET';
    $user_id = $_SESSION['user_id'] ?? 'NOT SET';
    
    echo "<p>user_type: " . htmlspecialchars($user_type) . "</p>";
    echo "<p>position: " . htmlspecialchars($position) . "</p>";
    echo "<p>logged_in: " . htmlspecialchars(var_export($logged_in, true)) . "</p>";
    echo "<p>user_id: " . htmlspecialchars($user_id) . "</p>";
    
    echo "<h3>Project Manager Check:</h3>";
    $is_employee = (($_SESSION['user_type'] ?? '') === 'employee');
    $is_pm = (strtolower((string)($_SESSION['position'] ?? '')) === 'project_manager');
    
    echo "<p>Is employee: " . ($is_employee ? 'YES' : 'NO') . "</p>";
    echo "<p>Is project manager: " . ($is_pm ? 'YES' : 'NO') . "</p>";
    echo "<p>Position normalized: '" . strtolower((string)($_SESSION['position'] ?? '')) . "'</p>";
    
    if ($is_employee && $is_pm) {
        echo "<p style='color: green;'><strong>✅ SHOULD PASS PROJECT MANAGER AUTH CHECK</strong></p>";
    } else {
        echo "<p style='color: red;'><strong>❌ WILL FAIL PROJECT MANAGER AUTH CHECK</strong></p>";
    }
    ?>
    
    <h2>Database Connection Test:</h2>
    <?php
    try {
        require_once __DIR__ . '/backend/connection/connect.php';
        $db = getDB();
        if ($db) {
            echo "<p style='color: green;'>✅ Database connection successful</p>";
            
            // Test if employees table exists and has data
            $userId = (int)($_SESSION['user_id'] ?? 0);
            if ($userId > 0) {
                $empStmt = $db->prepare('SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1');
                $empStmt->execute([$userId]);
                $empRow = $empStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($empRow) {
                    echo "<p style='color: green;'>✅ Employee record found: employee_id = " . $empRow['employee_id'] . "</p>";
                } else {
                    echo "<p style='color: red;'>❌ No employee record found for user_id = $userId</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ Invalid user_id in session</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Database connection failed</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    ?>
    
    <p><a href="login.php">Back to Login</a> | <a href="employees/project_manager/project_manager-dashboard.php">Try PM Dashboard</a></p>
</body>
</html>
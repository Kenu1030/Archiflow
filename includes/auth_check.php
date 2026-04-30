<?php
// Centralized role/auth guard
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Optional: pass allowed roles as array in $allowed_roles before include
if (isset($allowed_roles) && is_array($allowed_roles)) {
    $user_role = null;
    
    // Determine the user's role based on user_type and position
    $user_type = $_SESSION['user_type'] ?? '';
    $position = strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? ''))));
    
    if ($user_type === 'admin') {
        $user_role = 'admin';
    } elseif ($user_type === 'hr') {
        $user_role = 'hr';
    } elseif ($user_type === 'client') {
        $user_role = 'client';
    } elseif ($user_type === 'employee') {
        // For employees, the role is determined by their position
        if ($position === 'architect') {
            $user_role = 'architect';
        } elseif ($position === 'senior_architect') {
            $user_role = 'senior_architect';
        } elseif ($position === 'project_manager') {
            $user_role = 'project_manager';
        }
    }
    
    // Check if the user's role is in the allowed roles
    if (!in_array($user_role, $allowed_roles, true)) {
        header('Location: login.php');
        exit();
    }
}
?>

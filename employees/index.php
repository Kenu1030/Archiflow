<?php
// Session and role/position guard before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Compute app base (supports /ArchiFlow subfolder)
$APP_BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($APP_BASE === '/' || $APP_BASE === '.') { $APP_BASE = ''; }

// Compute root base (go up one level from employees/)
$ROOT_BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$ROOT_BASE = rtrim(str_replace('\\', '/', dirname($ROOT_BASE)), '/');
if ($ROOT_BASE === '/' || $ROOT_BASE === '.') { $ROOT_BASE = ''; }

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ' . $ROOT_BASE . '/login.php');
    exit;
}

// Get user type and position
$userType = $_SESSION['user_type'] ?? '';
$position = strtolower((string)($_SESSION['position'] ?? ''));

// Redirect based on user type and position
switch ($userType) {
    case 'employee':
        // Redirect employees to their specific dashboard based on position
        $normalizedPosition = strtolower(str_replace(' ', '_', trim($position)));
        switch ($normalizedPosition) {
            case 'architect':
                header('Location: ' . $ROOT_BASE . '/employees/architects/architects-dashboard.php');
                exit;
                
            case 'senior_architect':
                header('Location: ' . $ROOT_BASE . '/employees/senior_architects/senior_architects-dashboard.php');
                exit;
                
            case 'project_manager':
                header('Location: ' . $ROOT_BASE . '/employees/project_manager/project_manager-dashboard.php');
                exit;
                
            default:
                // Unknown employee position - redirect to main index
                header('Location: ' . $ROOT_BASE . '/index.php');
                exit;
        }
        break;
        
    default:
        // Unknown user type - redirect to main index
        header('Location: ' . $ROOT_BASE . '/index.php');
        exit;
}
?>

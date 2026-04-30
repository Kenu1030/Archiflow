<?php
session_start();
require_once 'auth.php';
require_once 'connection/connect.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Check if user is logged in and is admin
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}
// Allow admin or project_manager employee
$isAdmin = (($_SESSION['user_type'] ?? '') === 'admin');
$isPM = (($_SESSION['user_type'] ?? '') === 'employee' && strtolower((string)($_SESSION['position'] ?? '')) === 'project_manager');

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start();
    
    try {
        $action = $_POST['action'] ?? '';
        $db = getDB();
        
        if (!$db) {
            throw new Exception('Database connection failed');
        }
        
        ob_clean();
        header('Content-Type: application/json');
        
        switch ($action) {
            case 'addProject':
                $result = addProject($db, $_POST);
                echo json_encode($result);
                break;
                
            case 'updateProject':
                $result = updateProject($db, $_POST);
                echo json_encode($result);
                        if (!$isAdmin) { echo json_encode(['success'=>false,'message'=>'Admin only']); break; }
                break;
                
            case 'deleteProject':
                $result = deleteProject($db, $_POST);
                echo json_encode($result);
                        if (!$isAdmin) { echo json_encode(['success'=>false,'message'=>'Admin only']); break; }
                break;
                
            case 'listSeniorArchitectEmployees':
                $result = listSeniorArchitectEmployees($db);
                echo json_encode($result);
                        if (!$isAdmin) { echo json_encode(['success'=>false,'message'=>'Admin only']); break; }
                break;
            case 'listProjectAssignments':
                $result = listProjectAssignments($db, (int)($_POST['project_id'] ?? 0));
                echo json_encode($result);
                    case 'listSeniorArchitectEmployees':
                        // Read-only, allow any logged-in
                        $result = listSeniorArchitectEmployees($db);
                        echo json_encode($result);
                        break;
                    case 'listProjectAssignments':
                        // Read-only, allow any logged-in
                        $result = listProjectAssignments($db, (int)($_POST['project_id'] ?? 0));
                        echo json_encode($result);
                        break;
                    case 'assignSeniorArchitect':
                        if (!$isAdmin && !$isPM) { echo json_encode(['success'=>false,'message'=>'Admin or Project Manager only']); break; }
                        $result = assignSeniorArchitect($db, (int)($_POST['project_id'] ?? 0), (int)($_POST['employee_id'] ?? 0), ($_POST['role'] ?? 'advisor'));
                        echo json_encode($result);
                        break;
                    case 'removeSeniorArchitect':
                        if (!$isAdmin && !$isPM) { echo json_encode(['success'=>false,'message'=>'Admin or Project Manager only']); break; }
                        $result = removeSeniorArchitect($db, (int)($_POST['project_id'] ?? 0), (int)($_POST['employee_id'] ?? 0));
                        echo json_encode($result);
                        break;
                break;
            case 'assignSeniorArchitect':
                $result = assignSeniorArchitect($db, (int)($_POST['project_id'] ?? 0), (int)($_POST['employee_id'] ?? 0), ($_POST['role'] ?? 'advisor'));
                echo json_encode($result);
                break;
            case 'removeSeniorArchitect':
                $result = removeSeniorArchitect($db, (int)($_POST['project_id'] ?? 0), (int)($_POST['employee_id'] ?? 0));
                echo json_encode($result);
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                break;
        }
        
    } catch (Exception $e) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    
    ob_end_flush();
    exit();
}

function addProject($db, $data) {
    try {
        $query = "INSERT INTO projects (project_name, project_code, project_type, description, location, start_date, end_date, budget, status, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'planning', NOW())";
        
        $stmt = $db->prepare($query);
        $result = $stmt->execute([
            $data['project_name'],
            $data['project_code'],
            $data['project_type'],
            $data['description'] ?? null,
            $data['location'] ?? null,
            $data['start_date'] ?? null,
            $data['end_date'] ?? null,
            $data['budget'] ?? null
        ]);
        
        if ($result) {
            return ['success' => true, 'message' => 'Project added successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to add project'];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function updateProject($db, $data) {
    try {
        $query = "UPDATE projects SET project_name = ?, project_code = ?, project_type = ?, description = ?, 
                 location = ?, start_date = ?, end_date = ?, budget = ?, status = ? WHERE project_id = ?";
        
        $stmt = $db->prepare($query);
        $result = $stmt->execute([
            $data['project_name'],
            $data['project_code'],
            $data['project_type'],
            $data['description'] ?? null,
            $data['location'] ?? null,
            $data['start_date'] ?? null,
            $data['end_date'] ?? null,
            $data['budget'] ?? null,
            $data['status'] ?? 'planning',
            $data['project_id']
        ]);
        
        if ($result) {
            return ['success' => true, 'message' => 'Project updated successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to update project'];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function deleteProject($db, $data) {
    try {
        $query = "DELETE FROM projects WHERE project_id = ?";
        $stmt = $db->prepare($query);
        $result = $stmt->execute([$data['project_id']]);
        
        if ($result) {
            return ['success' => true, 'message' => 'Project deleted successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to delete project'];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// New: Senior Architect assignment helpers
function listSeniorArchitectEmployees($db) {
    try {
        $stmt = $db->query("SELECT e.employee_id, u.user_id, u.first_name, u.last_name, e.department
                             FROM employees e JOIN users u ON u.user_id = e.user_id
                             WHERE e.position = 'senior_architect' AND (e.status IS NULL OR e.status = 'active')");
        return ['success'=>true, 'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
    } catch (PDOException $e) {
        return ['success'=>false, 'message'=>'Database error: ' . $e->getMessage()];
    }
}

function listProjectAssignments($db, $projectId) {
    if (!$projectId) return ['success'=>false, 'message'=>'project_id required'];
    try {
        $stmt = $db->prepare("SELECT psa.project_id, psa.employee_id, psa.role, psa.assigned_at,
                                     u.first_name, u.last_name
                              FROM project_senior_architects psa
                              JOIN employees e ON e.employee_id = psa.employee_id
                              JOIN users u ON u.user_id = e.user_id
                              WHERE psa.project_id = ?");
        $stmt->execute([$projectId]);
        return ['success'=>true, 'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
    } catch (PDOException $e) {
        return ['success'=>false, 'message'=>'Database error: ' . $e->getMessage()];
    }
}

function assignSeniorArchitect($db, $projectId, $employeeId, $role) {
    if (!$projectId || !$employeeId) return ['success'=>false, 'message'=>'project_id and employee_id required'];
    if (!in_array($role, ['lead','reviewer','advisor'], true)) $role = 'advisor';
    try {
        // ensure employee is senior_architect
        $stmt = $db->prepare("SELECT 1 FROM employees WHERE employee_id=? AND position='senior_architect' LIMIT 1");
        $stmt->execute([$employeeId]);
        if (!$stmt->fetch()) return ['success'=>false, 'message'=>'Employee is not a Senior Architect'];

        // insert or update
        $stmt = $db->prepare("INSERT INTO project_senior_architects (project_id, employee_id, role, assigned_at)
                              VALUES (?,?,?, NOW())
                              ON DUPLICATE KEY UPDATE role=VALUES(role), assigned_at=NOW()");
        $stmt->execute([$projectId, $employeeId, $role]);
        return ['success'=>true, 'message'=>'Assigned'];
    } catch (PDOException $e) {
        return ['success'=>false, 'message'=>'Database error: ' . $e->getMessage()];
    }
}

function removeSeniorArchitect($db, $projectId, $employeeId) {
    if (!$projectId || !$employeeId) return ['success'=>false, 'message'=>'project_id and employee_id required'];
    try {
        $stmt = $db->prepare("DELETE FROM project_senior_architects WHERE project_id=? AND employee_id=?");
        $stmt->execute([$projectId, $employeeId]);
        return ['success'=>true, 'message'=>'Removed'];
    } catch (PDOException $e) {
        return ['success'=>false, 'message'=>'Database error: ' . $e->getMessage()];
    }
}
?>

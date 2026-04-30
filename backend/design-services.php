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
if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required.']);
    exit();
}

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
            case 'addService':
                $result = addService($db, $_POST);
                echo json_encode($result);
                break;
                
            case 'updateService':
                $result = updateService($db, $_POST);
                echo json_encode($result);
                break;
                
            case 'deleteService':
                $result = deleteService($db, $_POST);
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

function addService($db, $data) {
    try {
        $query = "INSERT INTO design_services (service_name, service_type, description, base_price, price_per_sqm, created_at) 
                 VALUES (?, ?, ?, ?, ?, NOW())";
        
        $stmt = $db->prepare($query);
        $result = $stmt->execute([
            $data['service_name'],
            $data['service_type'],
            $data['description'] ?? null,
            $data['base_price'],
            $data['price_per_sqm']
        ]);
        
        if ($result) {
            return ['success' => true, 'message' => 'Service added successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to add service'];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function updateService($db, $data) {
    try {
        $query = "UPDATE design_services SET service_name = ?, service_type = ?, description = ?, 
                 base_price = ?, price_per_sqm = ? WHERE service_id = ?";
        
        $stmt = $db->prepare($query);
        $result = $stmt->execute([
            $data['service_name'],
            $data['service_type'],
            $data['description'] ?? null,
            $data['base_price'],
            $data['price_per_sqm'],
            $data['service_id']
        ]);
        
        if ($result) {
            return ['success' => true, 'message' => 'Service updated successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to update service'];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function deleteService($db, $data) {
    try {
        $query = "DELETE FROM design_services WHERE service_id = ?";
        $stmt = $db->prepare($query);
        $result = $stmt->execute([$data['service_id']]);
        
        if ($result) {
            return ['success' => true, 'message' => 'Service deleted successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to delete service'];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}
?>

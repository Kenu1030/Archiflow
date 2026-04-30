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
            case 'addInvoice':
                $result = addInvoice($db, $_POST);
                echo json_encode($result);
                break;
                
            case 'updateInvoice':
                $result = updateInvoice($db, $_POST);
                echo json_encode($result);
                break;
                
            case 'deleteInvoice':
                $result = deleteInvoice($db, $_POST);
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

function addInvoice($db, $data) {
    try {
        $query = "INSERT INTO invoices (invoice_number, project_id, client_id, invoice_date, due_date, total_amount, status, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $db->prepare($query);
        $result = $stmt->execute([
            $data['invoice_number'],
            $data['project_id'] ?? null,
            $data['client_id'] ?? null,
            $data['invoice_date'],
            $data['due_date'] ?? null,
            $data['total_amount'],
            $data['status'] ?? 'draft'
        ]);
        
        if ($result) {
            return ['success' => true, 'message' => 'Invoice created successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to create invoice'];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function updateInvoice($db, $data) {
    try {
        $query = "UPDATE invoices SET invoice_number = ?, project_id = ?, client_id = ?, invoice_date = ?, 
                 due_date = ?, total_amount = ?, status = ? WHERE invoice_id = ?";
        
        $stmt = $db->prepare($query);
        $result = $stmt->execute([
            $data['invoice_number'],
            $data['project_id'] ?? null,
            $data['client_id'] ?? null,
            $data['invoice_date'],
            $data['due_date'] ?? null,
            $data['total_amount'],
            $data['status'] ?? 'draft',
            $data['invoice_id']
        ]);
        
        if ($result) {
            return ['success' => true, 'message' => 'Invoice updated successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to update invoice'];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function deleteInvoice($db, $data) {
    try {
        $query = "DELETE FROM invoices WHERE invoice_id = ?";
        $stmt = $db->prepare($query);
        $result = $stmt->execute([$data['invoice_id']]);
        
        if ($result) {
            return ['success' => true, 'message' => 'Invoice deleted successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to delete invoice'];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}
?>

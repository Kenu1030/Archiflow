<?php
require_once 'connection/connect.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$field = $input['field'] ?? '';
$value = $input['value'] ?? '';

if (empty($field) || empty($value)) {
    echo json_encode(['error' => 'Field and value are required']);
    exit;
}

try {
    $db = getDB();
    $response = ['available' => true, 'message' => ''];
    
    switch ($field) {
        case 'username':
            // For login validation, we want to check if username/email exists
            $stmt = $db->prepare("SELECT user_id FROM users WHERE (username = ? OR email = ?) AND is_active = 1");
            $stmt->execute([$value, $value]);
            if ($stmt->rowCount() > 0) {
                $response = ['available' => false, 'message' => 'Username found'];
            } else {
                // Check username format
                if (strlen($value) < 3) {
                    $response = ['available' => false, 'message' => 'Username must be at least 3 characters'];
                } elseif (!preg_match('/^[a-zA-Z0-9_@.]+$/', $value)) {
                    $response = ['available' => false, 'message' => 'Username can only contain letters, numbers, underscores, @ and dots'];
                } else {
                    $response = ['available' => true, 'message' => 'Username not found'];
                }
            }
            break;
            
        case 'email':
            $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->execute([$value]);
            if ($stmt->rowCount() > 0) {
                $response = ['available' => false, 'message' => 'Email already registered'];
            } else {
                // Check email format
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $response = ['available' => false, 'message' => 'Please enter a valid email address'];
                } else {
                    $response = ['available' => true, 'message' => 'Email is available'];
                }
            }
            break;
            
        case 'firstName':
        case 'lastName':
            // Check name format
            $fieldName = $field === 'firstName' ? 'First name' : 'Last name';
            if (strlen($value) < 2) {
                $response = ['available' => false, 'message' => $fieldName . ' must be at least 2 characters'];
            } elseif (!preg_match('/^[a-zA-Z\s\'-]+$/', $value)) {
                $response = ['available' => false, 'message' => $fieldName . ' can only contain letters, spaces, hyphens, and apostrophes'];
            } else {
                $response = ['available' => true, 'message' => $fieldName . ' is valid'];
            }
            break;
            
        case 'phone':
            // Check phone format
            if (!empty($value)) {
                $cleaned = preg_replace('/[^0-9+]/', '', $value);
                if (strlen($cleaned) < 10) {
                    $response = ['available' => false, 'message' => 'Please enter a valid phone number'];
                } else {
                    $response = ['available' => true, 'message' => 'Phone number is valid'];
                }
            } else {
                $response = ['available' => true, 'message' => 'Phone number is optional'];
            }
            break;
            
        case 'position':
            // Validate employee position
            $allowedPositions = ['architect', 'senior_architect', 'project_manager'];
            $pos = strtolower(trim($value));
            if (!in_array($pos, $allowedPositions, true)) {
                $response = ['available' => false, 'message' => 'Invalid position'];
            } else {
                $response = ['available' => true, 'message' => 'Position is valid'];
            }
            break;
            
        default:
            $response = ['available' => false, 'message' => 'Invalid field'];
    }
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>

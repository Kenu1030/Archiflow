<?php
/**
 * Authentication System
 * ArchiFlow - Architectural Works Monitoring and Management System
 */

// Disable error display to prevent HTML output in JSON response
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'connection/connect.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
        if (!$this->db) {
            throw new Exception('Database connection failed');
        }
    }
    
    /**
     * Register a new user (public registration - clients only)
     */
    public function register($data) {
        try {
            // Debug: Log the data being processed
            error_log('Register function data: ' . print_r($data, true));
            error_log('Username value: ' . ($data['username'] ?? 'NOT SET'));
            error_log('Email value: ' . ($data['email'] ?? 'NOT SET'));
            
            // Check if username or email already exists
            $checkQuery = "SELECT user_id FROM users WHERE username = ? OR email = ?";
            $checkStmt = $this->db->prepare($checkQuery);
            $checkStmt->execute([$data['username'], $data['email']]);
            
            if ($checkStmt->rowCount() > 0) {
                return ['success' => false, 'message' => 'Username or email already exists'];
            }
            
            // Hash password using SHA256
            $hashedPassword = hash('sha256', $data['password']);
            
            // Force user_type to 'client' for public registrations
            $userType = 'client';
            $position = null; // Clients don't have positions
            
            // Insert new user
            $query = "INSERT INTO users (username, email, password, user_type, position, first_name, last_name, phone, address, is_active) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
            
            $stmt = $this->db->prepare($query);
            $result = $stmt->execute([
                $data['username'],
                $data['email'],
                $hashedPassword,
                $userType,
                $position,
                $data['first_name'],
                $data['last_name'],
                $data['phone'] ?? null,
                $data['address'] ?? null
            ]);
            
            if ($result) {
                // Auto-provision clients table row if table exists
                try {
                    $insUserId = (int)$this->db->lastInsertId();
                    // Verify clients table exists and has user_id column
                    $chkTbl = $this->db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients'");
                    $chkTbl->execute();
                    if ((int)$chkTbl->fetchColumn() > 0) {
                        $chkCol = $this->db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'user_id'");
                        $chkCol->execute();
                        if ((int)$chkCol->fetchColumn() > 0) {
                            // Insert only if not existing
                            $exists = $this->db->prepare('SELECT 1 FROM clients WHERE user_id = ? LIMIT 1');
                            $exists->execute([$insUserId]);
                            if (!$exists->fetch()) {
                                $insClient = $this->db->prepare('INSERT INTO clients (user_id) VALUES (?)');
                                $insClient->execute([$insUserId]);
                            }
                        }
                    }
                } catch (\Throwable $e) { /* best-effort */ }
                return ['success' => true, 'message' => 'Client registration successful! You can now login to your account.'];
            } else {
                return ['success' => false, 'message' => 'Registration failed'];
            }
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Add new user (admin only - can set any role)
     */
    public function addUser($data) {
        try {
            // Normalize and validate role
            $allowedRoles = ['admin','employee','hr','client'];
            $data['user_type'] = strtolower(trim($data['user_type'] ?? ''));
            if (!in_array($data['user_type'], $allowedRoles, true)) {
                return ['success' => false, 'message' => 'Invalid user role'];
            }
            // If employee, position is required and must be one of the allowed
            if ($data['user_type'] === 'employee') {
                $allowedPositions = ['architect','senior_architect','project_manager'];
                $pos = strtolower(trim($data['position'] ?? ''));
                if ($pos === '' || !in_array($pos, $allowedPositions, true)) {
                    return ['success' => false, 'message' => 'Position is required for employees (architect, senior_architect or project_manager)'];
                }
                $data['position'] = $pos;
            } else {
                $data['position'] = null;
            }
            // Check if username or email already exists
            $checkQuery = "SELECT user_id FROM users WHERE username = ? OR email = ?";
            $checkStmt = $this->db->prepare($checkQuery);
            $checkStmt->execute([$data['username'], $data['email']]);
            
            if ($checkStmt->rowCount() > 0) {
                return ['success' => false, 'message' => 'Username or email already exists'];
            }
            
            // Hash password using SHA256
            $hashedPassword = hash('sha256', $data['password']);
            
            // Insert new user with specified role and position
            $query = "INSERT INTO users (username, email, password, user_type, position, first_name, last_name, phone, address, is_active) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
            
            $stmt = $this->db->prepare($query);
            $result = $stmt->execute([
                $data['username'],
                $data['email'],
                $hashedPassword,
                $data['user_type'],
                $data['position'] ?? null,
                $data['first_name'],
                $data['last_name'],
                $data['phone'] ?? null,
                $data['address'] ?? null
            ]);
            
            if ($result) {
                // Auto-provision clients row if a client user was added
                try {
                    if (($data['user_type'] ?? '') === 'client') {
                        $insUserId = (int)$this->db->lastInsertId();
                        $chkTbl = $this->db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients'");
                        $chkTbl->execute();
                        if ((int)$chkTbl->fetchColumn() > 0) {
                            $chkCol = $this->db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'user_id'");
                            $chkCol->execute();
                            if ((int)$chkCol->fetchColumn() > 0) {
                                $exists = $this->db->prepare('SELECT 1 FROM clients WHERE user_id = ? LIMIT 1');
                                $exists->execute([$insUserId]);
                                if (!$exists->fetch()) {
                                    $insClient = $this->db->prepare('INSERT INTO clients (user_id) VALUES (?)');
                                    $insClient->execute([$insUserId]);
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) { /* best-effort */ }
                return ['success' => true, 'message' => 'User added successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to add user'];
            }
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update user
     */
    public function updateUser($data) {
        try {
            $userId = $data['user_id'];
            // Validate role and position on update
            if (!empty($data['user_type'])) {
                $allowedRoles = ['admin','employee','hr','client'];
                $role = strtolower(trim($data['user_type']));
                if (!in_array($role, $allowedRoles, true)) {
                    return ['success' => false, 'message' => 'Invalid user role'];
                }
                $data['user_type'] = $role;
                if ($role === 'employee') {
                    $allowedPositions = ['architect','senior_architect','project_manager'];
                    $pos = strtolower(trim($data['position'] ?? ''));
                    if ($pos === '' || !in_array($pos, $allowedPositions, true)) {
                        return ['success' => false, 'message' => 'Position is required for employees (architect, senior_architect or project_manager)'];
                    }
                    $data['position'] = $pos;
                } else {
                    $data['position'] = null;
                }
            }
            
            // Check if username or email already exists (excluding current user)
            $checkQuery = "SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?";
            $checkStmt = $this->db->prepare($checkQuery);
            $checkStmt->execute([$data['username'], $data['email'], $userId]);
            
            if ($checkStmt->fetch()) {
                return ['success' => false, 'message' => 'Username or email already exists'];
            }
            
            // Build update query
            $updateFields = [];
            $updateValues = [];
            
            $updateFields[] = "username = ?";
            $updateValues[] = $data['username'];
            
            $updateFields[] = "email = ?";
            $updateValues[] = $data['email'];
            
            $updateFields[] = "user_type = ?";
            $updateValues[] = $data['user_type'];
            
            $updateFields[] = "position = ?";
            $updateValues[] = $data['position'] ?? null;
            
            $updateFields[] = "first_name = ?";
            $updateValues[] = $data['first_name'];
            
            $updateFields[] = "last_name = ?";
            $updateValues[] = $data['last_name'];
            
            $updateFields[] = "phone = ?";
            $updateValues[] = $data['phone'] ?? null;
            
            $updateFields[] = "address = ?";
            $updateValues[] = $data['address'] ?? null;
            
            $updateFields[] = "is_active = ?";
            $updateValues[] = $data['is_active'];
            
            // Update password only if provided
            if (!empty($data['password'])) {
                $hashedPassword = hash('sha256', $data['password']);
                $updateFields[] = "password = ?";
                $updateValues[] = $hashedPassword;
            }
            
            $updateValues[] = $userId;
            
            $query = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE user_id = ?";
            $stmt = $this->db->prepare($query);
            $result = $stmt->execute($updateValues);
            
            if ($result) {
                return ['success' => true, 'message' => 'User updated successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to update user'];
            }
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Login user
     */
    public function login($username, $password) {
        try {
            // Normalize inputs
            $username = trim($username ?? '');
            $password = (string)($password ?? '');
            $hashedPassword = hash('sha256', $password);
            
            // Debug: Log login attempt
            error_log('[LOGIN] Attempt for: ' . $username);
            error_log('[LOGIN] Hashed password: ' . $hashedPassword);
            
            $query = "SELECT user_id, username, email, user_type, position, first_name, last_name, is_active 
                     FROM users 
                     WHERE (username = ? OR email = ?) AND password = ? AND is_active = 1";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$username, $username, $hashedPassword]);
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch();
                
                // Set session variables
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['user_type'] = $user['user_type'];
                $_SESSION['position'] = $user['position'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['logged_in'] = true;

                // Compute redirect path (relative to app root) based on role/position
                $redirect = 'index.php';
                switch (strtolower((string)$user['user_type'])) {
                    case 'admin':
                        $redirect = 'admin/dashboard.php';
                        break;
                    case 'client':
                        $redirect = 'client/dashboard.php';
                        break;
                    case 'hr':
                        $redirect = 'hr/hr-dashboard.php';
                        break;
                    case 'employee':
                        $pos = strtolower(str_replace(' ', '_', trim((string)$user['position'])));
                        if ($pos === 'architect') {
                            $redirect = 'employees/architects/architects-dashboard.php';
                        } elseif ($pos === 'senior_architect') {
                            $redirect = 'employees/senior_architects/senior_architects-dashboard.php';
                        } elseif ($pos === 'project_manager') {
                            $redirect = 'employees/project_manager/project_manager-dashboard.php';
                        } else {
                            // Fallback for unknown employee position
                            $redirect = 'employees/architects/architects-dashboard.php';
                        }
                        break;
                    default:
                        $redirect = 'index.php';
                }
                error_log('[LOGIN] Success for user: ' . $user['username']);
                return ['success' => true, 'message' => 'Login successful', 'user' => $user, 'redirect' => $redirect];
            } else {
                // Check if user exists but password is wrong
                $checkUserQuery = "SELECT user_id FROM users WHERE (username = ? OR email = ?) AND is_active = 1";
                $checkStmt = $this->db->prepare($checkUserQuery);
                $checkStmt->execute([$username, $username]);
                if ($checkStmt->rowCount() > 0) {
                    error_log('[LOGIN] Failed: Wrong password for user: ' . $username);
                    return ['success' => false, 'message' => 'Invalid password'];
                } else {
                    error_log('[LOGIN] Failed: User not found: ' . $username);
                    return ['success' => false, 'message' => 'User not found'];
                }
            }
        } catch (PDOException $e) {
            error_log('[LOGIN] Database error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Logout user
     */
    public function logout() {
        session_destroy();
        return ['success' => true, 'message' => 'Logged out successfully'];
    }
    
    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
    
    /**
     * Get current user data
     */
    public function getCurrentUser() {
        if ($this->isLoggedIn()) {
            return [
                'user_id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'email' => $_SESSION['email'],
                'user_type' => $_SESSION['user_type'],
                'position' => $_SESSION['position'],
                'first_name' => $_SESSION['first_name'],
                'last_name' => $_SESSION['last_name']
            ];
        }
        return null;
    }
    
    /**
     * Redirect based on user type
     */
    public function redirectByUserType() {
        if (!$this->isLoggedIn()) {
            return;
        }
        
        $userType = $_SESSION['user_type'] ?? '';
        $position = strtolower((string)($_SESSION['position'] ?? ''));

        // Compute application base path (e.g., /ArchiFlow)
        $appBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        if ($appBase === '' || $appBase === '\\') { $appBase = '/'; }

        switch ($userType) {
            case 'admin':
                header('Location: ' . $appBase . '/admin/dashboard.php');
                exit();
            case 'employee':
                $normalizedPosition = strtolower(str_replace(' ', '_', trim($position)));
                switch ($normalizedPosition) {
                    case 'architect':
                        header('Location: ' . $appBase . '/employees/architects/architects-dashboard.php');
                        exit();
                    case 'senior_architect':
                        header('Location: ' . $appBase . '/employees/senior_architects/senior_architects-dashboard.php');
                        exit();
                    case 'project_manager':
                        header('Location: ' . $appBase . '/employees/project_manager/project_manager-dashboard.php');
                        exit();
                    default:
                        header('Location: ' . $appBase . '/employees/architects/architects-dashboard.php');
                        exit();
                }
            case 'client':
                header('Location: ' . $appBase . '/client/dashboard.php');
                exit();
            case 'hr':
                header('Location: ' . $appBase . '/hr/hr-dashboard.php');
                exit();
            default:
                header('Location: ' . $appBase . '/index.php');
                exit();
        }
    }
}

// Handle AJAX requests (only when explicitly targeted with an action)
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Start output buffering to catch any unexpected output
    ob_start();
    
    try {
        $auth = new Auth();
        $action = $_POST['action'] ?? '';
        
        // Clear any output that might have been generated
        ob_clean();
        
        header('Content-Type: application/json');
        
        switch ($action) {
        case 'register':
            // Debug: Log the received data
            error_log('Register data received: ' . print_r($_POST, true));
            error_log('POST keys: ' . implode(', ', array_keys($_POST)));
            error_log('POST username: ' . ($_POST['username'] ?? 'NOT SET'));
            error_log('POST email: ' . ($_POST['email'] ?? 'NOT SET'));
            error_log('POST first_name: ' . ($_POST['first_name'] ?? 'NOT SET'));
            error_log('POST last_name: ' . ($_POST['last_name'] ?? 'NOT SET'));
            $result = $auth->register($_POST);
            echo json_encode($result);
            break;
            
        case 'addUser':
            // Check if user is admin
            if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
                echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required.']);
                break;
            }
            $result = $auth->addUser($_POST);
            echo json_encode($result);
            break;
            
        case 'updateUser':
            // Check if user is admin
            if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
                echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required.']);
                break;
            }
            $result = $auth->updateUser($_POST);
            echo json_encode($result);
            break;
            
        case 'login':
            $result = $auth->login($_POST['username'], $_POST['password']);
            echo json_encode($result);
            break;
            
        case 'logout':
            $result = $auth->logout();
            echo json_encode($result);
            break;
            
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        // Clear any output and send proper JSON error
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    
    // End output buffering
    ob_end_flush();
    exit();
}
?>

<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__.'/auth.php';
require_once __DIR__.'/connection/connect.php';

$auth = new Auth();
if (!$auth->isLoggedIn() || ($_SESSION['role'] ?? '') !== 'senior_architect') {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access denied']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$db = getDB();
if(!$db){
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'DB unavailable']);
    exit();
}

try {
    switch ($action) {
        case 'listInquiries':
            listInquiries($db);
            break;
        case 'createProjectFromInquiry':
            createProjectFromInquiry($db);
            break;
        default:
            echo json_encode(['success'=>false,'message'=>'Invalid action']);
    }
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error: '.$e->getMessage()]);
}

function listInquiries(PDO $db){
    $stmt = $db->prepare("SELECT id, name, email, phone, inquiry_type, project_type, budget_range, location, message, status, created_at FROM public_inquiries WHERE status IN ('new','contacted') ORDER BY created_at DESC LIMIT 100");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'data'=>$rows]);
}

function createProjectFromInquiry(PDO $db){
    $inquiry_id = (int)($_POST['inquiry_id'] ?? 0);
    $pm_id = (int)($_POST['pm_user_id'] ?? 0);
    $deadline = trim($_POST['deadline'] ?? '');
    $project_name = trim($_POST['project_name'] ?? '');
    $scope = trim($_POST['scope'] ?? 'Design Only');
    $project_type = trim($_POST['project_type'] ?? 'General');
    $sa_id = (int)($_SESSION['user_id'] ?? 0);

    if(!$inquiry_id || !$pm_id || !$project_name || !$deadline){
        echo json_encode(['success'=>false,'message'=>'Missing required fields']);
        return;
    }

    $db->beginTransaction();
    try {
        // Fetch inquiry details
        $inq = $db->prepare('SELECT * FROM public_inquiries WHERE id=? FOR UPDATE');
        $inq->execute([$inquiry_id]);
        $inqRow = $inq->fetch(PDO::FETCH_ASSOC);
        if(!$inqRow){ throw new Exception('Inquiry not found'); }

        // Insert project
        $stmt = $db->prepare("INSERT INTO projects (project_name, description, created_by, deadline, project_type, scope, client_name, status) VALUES (?,?,?,?,?,?,?, 'pending_pm')");
        $desc = $inqRow['message'];
        $client_name = $inqRow['name'];
        $stmt->execute([$project_name, $desc, $sa_id, $deadline, $project_type, $scope, $client_name]);
        $project_id = (int)$db->lastInsertId();

        // Assign manager
        $up = $db->prepare('UPDATE projects SET manager_id=? WHERE id=?');
        $up->execute([$pm_id, $project_id]);

        // project_users linking if table exists
        $hasPU = $db->query("SHOW TABLES LIKE 'project_users'")->rowCount();
        if ($hasPU){
            $pu = $db->prepare('INSERT INTO project_users (project_id, user_id, role_in_project) VALUES (?,?,?)');
            $pu->execute([$project_id, $sa_id, 'Senior Architect']);
            $pu->execute([$project_id, $pm_id, 'Project Manager']);
        }

        // Mark inquiry in progress
        $uinq = $db->prepare("UPDATE public_inquiries SET status='in_progress', updated_at=NOW() WHERE id=?");
        $uinq->execute([$inquiry_id]);

        $db->commit();
        echo json_encode(['success'=>true,'message'=>'Project created from inquiry','project_id'=>$project_id]);
    } catch(Throwable $e){
        $db->rollBack();
        echo json_encode(['success'=>false,'message'=>'Failed: '.$e->getMessage()]);
    }
}

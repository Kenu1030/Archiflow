<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__.'/auth.php';
require_once __DIR__.'/connection/connect.php';
$auth = new Auth();
if(!$auth->isLoggedIn() || ($_SESSION['role'] ?? '') !== 'senior_architect'){
  http_response_code(403);
  echo json_encode(['success'=>false,'message'=>'Access denied']);
  exit();
}
$db = getDB();
if(!$db){ echo json_encode(['success'=>false,'message'=>'DB unavailable']); exit(); }
try {
  // Support both modern schema (user_type/position) and legacy (role)
  $cols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN,0);
  $hasUserType = in_array('user_type',$cols); $hasPosition = in_array('position',$cols); $hasRole = in_array('role',$cols);
  if($hasUserType && $hasPosition){
    $stmt = $db->query("SELECT 
        (CASE WHEN position='project_manager' THEN user_id ELSE NULL END) as id,
        CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) as full_name
      FROM users
      WHERE user_type='employee' AND position='project_manager'");
    $rows = [];
    while($r=$stmt->fetch(PDO::FETCH_ASSOC)){
      if(!$r['id']) continue;
      $countStmt = $db->prepare("SELECT COUNT(*) FROM projects WHERE manager_id=?");
      $countStmt->execute([$r['id']]);
      $rows[] = ['id'=>(int)$r['id'],'name'=>trim($r['full_name']), 'active_projects'=>(int)$countStmt->fetchColumn()];
    }
  } elseif($hasRole) {
    $stmt = $db->query("SELECT id, full_name FROM users WHERE role='project_manager'");
    $rows = [];
    while($r=$stmt->fetch(PDO::FETCH_ASSOC)){
      $countStmt=$db->prepare("SELECT COUNT(*) FROM projects WHERE manager_id=?");
      $countStmt->execute([$r['id']]);
      $rows[]=['id'=>(int)$r['id'],'name'=>$r['full_name'],'active_projects'=>(int)$countStmt->fetchColumn()];
    }
  } else {
    $rows=[];
  }
  echo json_encode(['success'=>true,'data'=>$rows]);
} catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}

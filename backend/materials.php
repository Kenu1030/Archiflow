<?php
// Materials management endpoint
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');
require_once __DIR__ . '/connection/connect.php';
$db = getDB();
if (!$db) { http_response_code(500); echo json_encode(['error'=>'DB connection failed']); exit; }
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Role check: only architects (employee position=architect) or senior_architect may manage materials
$userType = $_SESSION['user_type'] ?? null;
$position = strtolower((string)($_SESSION['position'] ?? ''));
if (!($userType === 'employee' && ($position === 'architect' || $position === 'senior_architect'))) {
  http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit;
}

// Ensure tables exist (minimal schema)
try {
  $db->exec("CREATE TABLE IF NOT EXISTS material_categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    parent_id INT NULL,
    INDEX(parent_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $db->exec("CREATE TABLE IF NOT EXISTS materials (
    material_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    category_id INT NULL,
    default_unit VARCHAR(20) DEFAULT 'pcs',
    description TEXT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(category_id), INDEX(is_active)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $db->exec("CREATE TABLE IF NOT EXISTS project_materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    material_id INT NULL,
    custom_name VARCHAR(150) NULL,
    quantity DECIMAL(12,3) DEFAULT 0,
    unit VARCHAR(20) DEFAULT 'pcs',
    cost_per_unit DECIMAL(12,2) NULL,
    notes TEXT NULL,
    added_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(project_id), INDEX(material_id), INDEX(added_by)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
  http_response_code(500); echo json_encode(['error'=>'Schema creation failed','detail'=>$e->getMessage()]); exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list_project';

function json_out($payload){ echo json_encode($payload); }

switch ($action) {
  case 'search':
    $term = trim((string)($_GET['q'] ?? ''));
    $sql = "SELECT material_id, name, default_unit FROM materials WHERE is_active=1";
    $params = [];
    if ($term !== '') { $sql .= " AND name LIKE ?"; $params[] = '%'.$term.'%'; }
    $sql .= " ORDER BY name ASC LIMIT 25";
    $st = $db->prepare($sql); $st->execute($params);
    json_out(['results'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    break;
  case 'add_material':
    $name = trim((string)($_POST['name'] ?? ''));
    $unit = trim((string)($_POST['default_unit'] ?? 'pcs'));
    if ($name === '') { http_response_code(422); json_out(['error'=>'Name required']); break; }
    $ins = $db->prepare('INSERT INTO materials (name, default_unit) VALUES (?, ?)');
    $ins->execute([$name, $unit]);
    json_out(['ok'=>true,'material_id'=>$db->lastInsertId()]);
    break;
  case 'add_project_material':
    $projectId = (int)($_POST['project_id'] ?? 0);
    $materialId = (int)($_POST['material_id'] ?? 0);
    $customName = trim((string)($_POST['custom_name'] ?? '')) ?: null;
    if ($projectId <= 0) { http_response_code(422); json_out(['error'=>'project_id required']); break; }
    if ($materialId <= 0 && ($customName === null || $customName === '')) { http_response_code(422); json_out(['error'=>'Select existing material or enter a name']); break; }
    // Basic project visibility check: ensure architect is linked to project via project_users or tasks
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $allowed = false;
    try {
      $check = $db->prepare('SELECT 1 FROM project_users WHERE project_id = ? AND user_id = ? LIMIT 1');
      $check->execute([$projectId, $uid]);
      $allowed = (bool)$check->fetchColumn();
    } catch (Throwable $e) {}
    if (!$allowed) {
      // Fallback: see if any task assigned on that project
      try {
        $chk2 = $db->prepare('SELECT 1 FROM tasks WHERE project_id = ? AND assigned_to = ? LIMIT 1');
        $chk2->execute([$projectId, $uid]);
        $allowed = (bool)$chk2->fetchColumn();
      } catch (Throwable $e2) {}
    }
    if(!$allowed){ http_response_code(403); json_out(['error'=>'No access to project']); break; }
    // Insert with minimal fields; other columns left default
    $ins = $db->prepare('INSERT INTO project_materials (project_id, material_id, custom_name, added_by) VALUES (?,?,?,?)');
    $ins->execute([$projectId, $materialId ?: null, $customName, $uid]);
    json_out(['ok'=>true,'id'=>$db->lastInsertId()]);
    break;
  case 'update_project_material':
    $rowId = (int)($_POST['id'] ?? 0);
    $newMaterialId = (int)($_POST['material_id'] ?? 0);
    $newCustomName = trim((string)($_POST['custom_name'] ?? '')) ?: null;
    if ($rowId <= 0) { http_response_code(422); json_out(['error'=>'id required']); break; }
    // Fetch row to verify ownership
    $row = null;
    try {
      $st = $db->prepare('SELECT project_id FROM project_materials WHERE id=? LIMIT 1');
      $st->execute([$rowId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    if (!$row) { http_response_code(404); json_out(['error'=>'Row not found']); break; }
    $projectId = (int)$row['project_id'];
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $allowed = false;
    try { $chk = $db->prepare('SELECT 1 FROM project_users WHERE project_id=? AND user_id=? LIMIT 1'); $chk->execute([$projectId,$uid]); $allowed = (bool)$chk->fetchColumn(); } catch (Throwable $e) {}
    if(!$allowed){
      try { $chk2 = $db->prepare('SELECT 1 FROM tasks WHERE project_id=? AND assigned_to=? LIMIT 1'); $chk2->execute([$projectId,$uid]); $allowed = (bool)$chk2->fetchColumn(); } catch (Throwable $e2) {}
    }
    if(!$allowed){ http_response_code(403); json_out(['error'=>'No access']); break; }
    if ($newMaterialId <= 0 && ($newCustomName === null || $newCustomName === '')) { http_response_code(422); json_out(['error'=>'Provide material_id or custom_name']); break; }
    $up = $db->prepare('UPDATE project_materials SET material_id = ?, custom_name = ? WHERE id = ? LIMIT 1');
    $up->execute([$newMaterialId ?: null, $newCustomName, $rowId]);
    json_out(['ok'=>true]);
    break;
  case 'delete_project_material':
    $rowId = (int)($_POST['id'] ?? 0);
    if ($rowId <= 0) { http_response_code(422); json_out(['error'=>'id required']); break; }
    $row = null;
    try { $st = $db->prepare('SELECT project_id FROM project_materials WHERE id=? LIMIT 1'); $st->execute([$rowId]); $row = $st->fetch(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    if (!$row) { http_response_code(404); json_out(['error'=>'Row not found']); break; }
    $projectId = (int)$row['project_id'];
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $allowed = false;
    try { $chk = $db->prepare('SELECT 1 FROM project_users WHERE project_id=? AND user_id=? LIMIT 1'); $chk->execute([$projectId,$uid]); $allowed = (bool)$chk->fetchColumn(); } catch (Throwable $e) {}
    if(!$allowed){
      try { $chk2 = $db->prepare('SELECT 1 FROM tasks WHERE project_id=? AND assigned_to=? LIMIT 1'); $chk2->execute([$projectId,$uid]); $allowed = (bool)$chk2->fetchColumn(); } catch (Throwable $e2) {}
    }
    if(!$allowed){ http_response_code(403); json_out(['error'=>'No access']); break; }
    $del = $db->prepare('DELETE FROM project_materials WHERE id=? LIMIT 1');
    $del->execute([$rowId]);
    json_out(['ok'=>true]);
    break;
  case 'list_project':
    $projectId = (int)($_GET['project_id'] ?? 0);
    if ($projectId <= 0) { http_response_code(422); json_out(['error'=>'project_id required']); break; }
    $st = $db->prepare('SELECT pm.id, pm.project_id, pm.material_id, COALESCE(pm.custom_name, m.name) AS name, pm.created_at FROM project_materials pm LEFT JOIN materials m ON m.material_id = pm.material_id WHERE pm.project_id = ? ORDER BY pm.created_at DESC');
    $st->execute([$projectId]);
    json_out(['materials'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    break;
  default:
    http_response_code(400); json_out(['error'=>'Unknown action']);
}

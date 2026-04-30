<?php
session_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/connection/connect.php';

header('Content-Type: application/json');

try {
  $auth = new Auth();
  if (!$auth->isLoggedIn()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Not authenticated']); exit; }

  $db = getDB();
  if (!$db) { throw new Exception('DB connection failed'); }
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $action = $_POST['action'] ?? '';
  switch ($action) {
    case 'createReview':
      // Inputs: project_id, milestone_id? document_id? reviewer_id (SA)
      if (($_SESSION['user_type'] ?? '') !== 'employee') throw new Exception('Only employees can create reviews');
      $projectId = (int)($_POST['project_id'] ?? 0);
      $milestoneId = isset($_POST['milestone_id']) && $_POST['milestone_id']!=='' ? (int)$_POST['milestone_id'] : null;
      $documentId = isset($_POST['document_id']) && $_POST['document_id']!=='' ? (int)$_POST['document_id'] : null;
      $reviewerId = (int)($_POST['reviewer_id'] ?? 0);
      if (!$projectId || !$reviewerId) throw new Exception('Missing project_id or reviewer_id');

      // verify reviewer is senior_architect employee and assigned to project
      $stmt = $db->prepare('SELECT e.employee_id FROM employees e WHERE e.employee_id=? AND e.position = "senior_architect" LIMIT 1');
      $stmt->execute([$reviewerId]);
      if (!$stmt->fetch()) throw new Exception('Reviewer must be a Senior Architect');

      $stmt = $db->prepare('SELECT 1 FROM project_senior_architects WHERE project_id=? AND employee_id=? LIMIT 1');
      $stmt->execute([$projectId, $reviewerId]);
      if (!$stmt->fetch()) throw new Exception('Reviewer is not assigned to this project');

      $stmt = $db->prepare('INSERT INTO design_reviews (project_id, milestone_id, document_id, reviewer_id, status, created_at) VALUES (?,?,?,?,"pending", NOW())');
      $stmt->execute([$projectId, $milestoneId, $documentId, $reviewerId]);
      echo json_encode(['success'=>true,'message'=>'Review requested']);
      break;

    case 'updateReviewStatus':
      if (($_SESSION['user_type'] ?? '') !== 'employee' || strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? '')))) !== 'senior_architect') throw new Exception('Only Senior Architects can perform this action');
      $reviewId = (int)($_POST['review_id'] ?? 0);
      $status = $_POST['status'] ?? '';
      $comments = trim((string)($_POST['comments'] ?? ''));
      if (!$reviewId || !in_array($status, ['approved','changes_requested','rejected'], true)) throw new Exception('Invalid inputs');

      // fetch employee_id of current user
      $stmt = $db->prepare('SELECT employee_id FROM employees WHERE user_id=? LIMIT 1');
      $stmt->execute([$_SESSION['user_id']]);
      $emp = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$emp) throw new Exception('Employee not found');
      $employeeId = (int)$emp['employee_id'];

      // ensure the review belongs to this reviewer and is pending
      $stmt = $db->prepare('SELECT reviewer_id FROM design_reviews WHERE review_id=? AND status="pending" LIMIT 1');
      $stmt->execute([$reviewId]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$row || (int)$row['reviewer_id'] !== $employeeId) throw new Exception('Review not found or not assigned to you');

      $stmt = $db->prepare('UPDATE design_reviews SET status=?, comments=?, reviewed_at=NOW() WHERE review_id=?');
      $stmt->execute([$status, $comments, $reviewId]);

      // Notify architect and project manager
      $stmt = $db->prepare('SELECT dr.project_id, dr.milestone_id, dr.document_id, p.architect_id, p.project_manager_id
                            FROM design_reviews dr JOIN projects p ON p.project_id=dr.project_id WHERE dr.review_id=?');
      $stmt->execute([$reviewId]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row) {
        $projectId = (int)$row['project_id'];
        $milestoneId = $row['milestone_id'];
        $documentId = $row['document_id'];
        $architectId = $row['architect_id'];
        $pmId = $row['project_manager_id'];
        $notifMsg = 'Design review ' . ($status === 'approved' ? 'approved' : ($status === 'changes_requested' ? 'requires changes' : 'rejected')) . ' by Senior Architect.';
        $notifLink = null;
        if ($milestoneId) $notifLink = 'employees/architects/milestones.php';
        if ($documentId) $notifLink = 'employees/architects/documents.php';
        // Notify architect (if set)
        if ($architectId) {
          $stmtU = $db->prepare('SELECT user_id FROM employees WHERE employee_id=?');
          $stmtU->execute([$architectId]);
          $u = $stmtU->fetch(PDO::FETCH_ASSOC);
          if ($u && $u['user_id']) {
            $stmtN = $db->prepare('INSERT INTO notifications (user_id, message, link, is_read, created_at) VALUES (?,?,?,?,NOW())');
            $stmtN->execute([$u['user_id'], $notifMsg, $notifLink, 0]);
          }
        }
        // Notify project manager (if set)
        if ($pmId) {
          $stmtU = $db->prepare('SELECT user_id FROM employees WHERE employee_id=?');
          $stmtU->execute([$pmId]);
          $u = $stmtU->fetch(PDO::FETCH_ASSOC);
          if ($u && $u['user_id']) {
            $stmtN = $db->prepare('INSERT INTO notifications (user_id, message, link, is_read, created_at) VALUES (?,?,?,?,NOW())');
            $stmtN->execute([$u['user_id'], $notifMsg, $notifLink, 0]);
          }
        }
      }
      echo json_encode(['success'=>true,'message'=>'Review updated']);
      break;

    case 'listMyReviews':
      // optional utility
      $stmt = $db->prepare('SELECT * FROM design_reviews WHERE reviewer_id = (SELECT employee_id FROM employees WHERE user_id=? LIMIT 1)');
      $stmt->execute([$_SESSION['user_id']]);
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
      break;

    default:
      echo json_encode(['success'=>false,'message'=>'Invalid action']);
  }
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}

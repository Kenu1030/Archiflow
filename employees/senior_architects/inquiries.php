<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$allowed_roles = ['senior_architect'];
include __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../backend/connection/connect.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Mailer.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Allowed inquiry statuses (shared by filters and updates)
$validStatuses = ['new','open','in_review','in_progress','resolved','dismissed'];

// Handle sending email response
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_inquiry_email'])) {
  $emailType = $_POST['email_type'] ?? ''; // public|client
  $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
  $to = trim($_POST['to_email'] ?? '');
  $subject = trim($_POST['reply_subject'] ?? '');
  $body = trim($_POST['reply_message'] ?? '');
  $email_error = '';
  $email_success = false;
  if (!$inquiryId || !filter_var($to, FILTER_VALIDATE_EMAIL) || $subject === '' || $body === '') {
    $email_error = 'All email fields are required and must be valid.';
  } else {
    // Basic headers; in production use authenticated mailer
    [$sent, $err] = Archiflow\Mail\send_mail([
      'to_email' => $to,
      'to_name'  => '',
      'subject'  => $subject,
      'html'     => nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')),
      'text'     => $body,
    ]);
    if ($sent) {
      $email_success = true;
      // Optional: mark inquiry in_review if still new
      try {
        if ($emailType === 'public') {
          $stmtUpd = $db->prepare("UPDATE public_inquiries SET status = CASE WHEN status='new' THEN 'in_review' ELSE status END WHERE id=?");
          $stmtUpd->execute([$inquiryId]);
        } elseif ($emailType === 'client') {
          $stmtUpd = $db->prepare("UPDATE client_inquiries SET status = CASE WHEN status='new' THEN 'in_review' ELSE status END WHERE id=?");
          $stmtUpd->execute([$inquiryId]);
        }
      } catch (Throwable $ie) {}
    } else {
      $email_error = 'Failed to send email: ' . htmlspecialchars($err ?: 'unknown error');
    }
  }
}

// Handle status update (AJAX or form)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
  $type = $_POST['type'] ?? '';
  $id = (int)($_POST['id'] ?? 0);
  $status = $_POST['status'] ?? '';
  $currentUid = (int)($_SESSION['user_id'] ?? 0);
  if ($id && in_array($status, $validStatuses, true)) {
    try {
      if ($type === 'public') {
        // Only the assigned Senior Architect can update
        $stmt = $db->prepare("UPDATE public_inquiries SET status=? WHERE id=? AND assigned_to=?");
        $stmt->execute([$status, $id, $currentUid]);
        if ($stmt->rowCount() === 0) { $error = 'Not authorized to update this public inquiry.'; }
            } elseif ($type === 'client') {
        // Ensure table/columns exist; add recipient_id if missing
                $db->exec("CREATE TABLE IF NOT EXISTS client_inquiries (id INT PRIMARY KEY AUTO_INCREMENT, client_id INT NULL, project_id INT NULL, request_id INT NULL, recipient_id INT NULL, subject VARCHAR(255), message TEXT, status VARCHAR(50) DEFAULT 'new', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $colRec = $db->query("SHOW COLUMNS FROM client_inquiries LIKE 'recipient_id'")->fetch();
        if (!$colRec) { $db->exec("ALTER TABLE client_inquiries ADD COLUMN recipient_id INT NULL"); }
        $cols = $db->query("SHOW COLUMNS FROM client_inquiries LIKE 'status'")->fetch();
        if (!$cols) { $db->exec("ALTER TABLE client_inquiries ADD COLUMN status VARCHAR(50) DEFAULT 'new'"); }
                // Ensure request_id exists for linkage
                $colReq = $db->query("SHOW COLUMNS FROM client_inquiries LIKE 'request_id'")->fetch();
                if (!$colReq) { $db->exec("ALTER TABLE client_inquiries ADD COLUMN request_id INT NULL"); }
        // Only the recipient Senior Architect can update
        $stmt = $db->prepare("UPDATE client_inquiries SET status=? WHERE id=? AND recipient_id=?");
        $stmt->execute([$status, $id, $currentUid]);
        if ($stmt->rowCount() === 0) { $error = 'Not authorized to update this client inquiry.'; }
                // Attempt to sync project_request status if this inquiry is linked to a request
                if (empty($error)) {
                  try {
                    $rq = $db->prepare("SELECT request_id FROM client_inquiries WHERE id=?");
                    $rq->execute([$id]);
                    $rid = (int)$rq->fetchColumn();
                    if ($rid > 0) {
                      // Determine correct project request table name
                      $PR_TABLE = 'project_requests';
                      try {
                        $hasSing = $db->query("SHOW TABLES LIKE 'project_request'");
                        $hasPlu = $db->query("SHOW TABLES LIKE 'project_requests'");
                        $singular = $hasSing && $hasSing->rowCount() > 0;
                        $plural = $hasPlu && $hasPlu->rowCount() > 0;
                        if ($singular && $plural) {
                          $c1 = 0; $c2 = 0;
                          try { $c1 = (int)$db->query("SELECT COUNT(*) FROM project_request")->fetchColumn(); } catch (Throwable $ie1) {}
                          try { $c2 = (int)$db->query("SELECT COUNT(*) FROM project_requests")->fetchColumn(); } catch (Throwable $ie2) {}
                          $PR_TABLE = ($c1 >= $c2) ? 'project_request' : 'project_requests';
                        } elseif ($singular) { $PR_TABLE = 'project_request'; }
                      } catch (Throwable $te) {}
                      // Map inquiry statuses to request statuses
                      $reqStatus = 'review';
                      if ($status === 'open' || $status === 'new') { $reqStatus = 'pending'; }
                      elseif ($status === 'in_progress' || $status === 'in_review') { $reqStatus = 'review'; }
                      elseif ($status === 'resolved') { $reqStatus = 'approved'; }
                      elseif ($status === 'dismissed') { $reqStatus = 'declined'; }
                      // Update the request if current SA is assigned
                      // Determine SA assignment column
                      $prCols = [];
                      try { foreach($db->query('SHOW COLUMNS FROM ' . $PR_TABLE) as $c){ $prCols[$c['Field']] = true; } } catch (Throwable $pe) {}
                      $PR_SA_COL = isset($prCols['senior_architect_id']) ? 'senior_architect_id' : (isset($prCols['assigned_to']) ? 'assigned_to' : (isset($prCols['sa_id']) ? 'sa_id' : null));
                      if ($PR_SA_COL) {
                        $stRU = $db->prepare('UPDATE ' . $PR_TABLE . ' SET status = ? WHERE id = ? AND ' . $PR_SA_COL . ' = ?');
                        $stRU->execute([$reqStatus, $rid, $currentUid]);
                      } else {
                        // Fallback: update by id only
                        $stRU = $db->prepare('UPDATE ' . $PR_TABLE . ' SET status = ? WHERE id = ?');
                        $stRU->execute([$reqStatus, $rid]);
                      }
                    }
                  } catch (Throwable $se) {}
                }
      }
      if (empty($error)) {
        header('Location: inquiries.php?updated=1');
        exit;
      }
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }
}

// Handle claim of unassigned public inquiry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_public'])) {
  $id = (int)($_POST['id'] ?? 0);
  $currentUid = (int)($_SESSION['user_id'] ?? 0);
  if ($id > 0 && $currentUid > 0) {
    try {
      // Claim only if currently unassigned, set status to in_review if new/null
      $stmt = $db->prepare("UPDATE public_inquiries 
        SET assigned_to = ?, status = CASE WHEN status IS NULL OR status='new' THEN 'in_review' ELSE status END 
        WHERE id = ? AND (assigned_to IS NULL OR assigned_to = 0)");
      $stmt->execute([$currentUid, $id]);
      header('Location: inquiries.php?claimed=' . ($stmt->rowCount() > 0 ? '1' : '0'));
      exit;
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }
}

// Handle create project from client inquiry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
  $type = $_POST['type'] ?? 'client';
  $id = (int)($_POST['id'] ?? 0);
  $pmId = isset($_POST['project_manager_id']) ? (int)$_POST['project_manager_id'] : 0;
  $currentUid = (int)($_SESSION['user_id'] ?? 0);
  if ($type !== 'client' || $id <= 0) {
    $error = 'Invalid inquiry for project creation.';
  } else {
    try {
      // Ensure inquiry exists and is assigned to current SA
      $st = $db->prepare('SELECT * FROM client_inquiries WHERE id=? AND recipient_id=? LIMIT 1');
      $st->execute([$id, $currentUid]);
      $inq = $st->fetch(PDO::FETCH_ASSOC);
      if (!$inq) { throw new Exception('Inquiry not found or not assigned to you.'); }

      // Detect projects table columns
      $projCols = [];
      foreach($db->query('SHOW COLUMNS FROM projects') as $c){ $projCols[$c['Field']] = true; }
      if (!$projCols) { throw new Exception('Projects table not found.'); }

      // If PM not provided, auto-assign the PM with the least active projects
      if (!$pmId) {
        try {
          // Build PM candidates list (schema-tolerant)
          $usersCols2 = [];
          foreach($db->query('SHOW COLUMNS FROM users') as $c){ $usersCols2[$c['Field']] = true; }
          $IDCOL = isset($usersCols2['id']) ? 'id' : (isset($usersCols2['user_id']) ? 'user_id' : 'id');
          $conds = [];
          if (isset($usersCols2['position'])) {
            $conds[] = "LOWER(REPLACE(REPLACE(position,'_',' '),'-',' ')) LIKE '%project manager%'";
            $conds[] = "LOWER(position) IN ('project_manager','project manager','pm')";
          }
          if (isset($usersCols2['role'])) { $conds[] = "LOWER(role) IN ('project_manager','pm','project manager')"; }
          if (isset($usersCols2['user_type']) && isset($usersCols2['position'])) {
            $conds[] = "(LOWER(user_type)='employee' AND LOWER(REPLACE(REPLACE(position,'_',' '),'-',' ')) LIKE '%project manager%')";
          } elseif (isset($usersCols2['user_type'])) { $conds[] = "LOWER(user_type) IN ('project_manager','pm')"; }
          $where = $conds ? implode(' OR ', $conds) : '1=0';
          $pmSql = "SELECT $IDCOL AS id FROM users WHERE $where LIMIT 300";
          $candStmt = $db->query($pmSql);
          $candIds = array_map(static function($r){ return (int)$r['id']; }, $candStmt->fetchAll(PDO::FETCH_ASSOC));

          if ($candIds) {
            $minLoad = PHP_INT_MAX; $pick = 0;
            // Check existence of project_users table and columns
            $hasProjectUsers = false; $hasRoleCol = false;
            try {
              $chkPU = $db->query("SHOW TABLES LIKE 'project_users'");
              $hasProjectUsers = $chkPU && $chkPU->rowCount() > 0;
              if ($hasProjectUsers) {
                $puCols = [];
                foreach($db->query('SHOW COLUMNS FROM project_users') as $c){ $puCols[$c['Field']] = true; }
                $hasRoleCol = isset($puCols['role_in_project']);
              }
            } catch (Throwable $ie) { $hasProjectUsers = false; }

            $cntProjectsByPM = null;
            if (isset($projCols['project_manager_id'])) {
              $cntProjectsByPM = $db->prepare('SELECT COUNT(*) FROM projects WHERE project_manager_id = ?');
            }
            $cntPUByUser = ($hasProjectUsers)
              ? ($hasRoleCol
                  ? $db->prepare("SELECT COUNT(DISTINCT project_id) FROM project_users WHERE user_id = ? AND (role_in_project = 'project_manager' OR role_in_project = 'pm' OR role_in_project IS NULL)")
                  : $db->prepare('SELECT COUNT(DISTINCT project_id) FROM project_users WHERE user_id = ?')
                )
              : null;

            foreach ($candIds as $cid) {
              $load = 0;
              try { if ($cntProjectsByPM) { $cntProjectsByPM->execute([$cid]); $load += (int)$cntProjectsByPM->fetchColumn(); } } catch (Throwable $e1) {}
              try { if ($cntPUByUser) { $cntPUByUser->execute([$cid]); $load += (int)$cntPUByUser->fetchColumn(); } } catch (Throwable $e2) {}
              if ($load < $minLoad || ($load === $minLoad && ($pick === 0 || $cid < $pick))) { $minLoad = $load; $pick = $cid; }
            }
            if ($pick) { $pmId = $pick; }
          }
        } catch (Throwable $ae) { /* ignore auto-assign errors */ }
      }

  // Optional: pull request info if available
      $req = [];
      $rid = (int)($inq['request_id'] ?? 0);
      if ($rid > 0) {
        try {
          $PR_TABLE = null; $prCols = [];
          $hasSing = $db->query("SHOW TABLES LIKE 'project_request'");
          $hasPlu  = $db->query("SHOW TABLES LIKE 'project_requests'");
          $singular = $hasSing && $hasSing->rowCount() > 0; $plural = $hasPlu && $hasPlu->rowCount() > 0;
          if ($singular && $plural) {
            $c1 = 0; $c2 = 0; try { $c1 = (int)$db->query('SELECT COUNT(*) FROM project_request')->fetchColumn(); } catch(Throwable $e1){}
            try { $c2 = (int)$db->query('SELECT COUNT(*) FROM project_requests')->fetchColumn(); } catch(Throwable $e2){}
            $PR_TABLE = ($c1 >= $c2) ? 'project_request' : 'project_requests';
          } elseif ($singular) { $PR_TABLE = 'project_request'; }
          elseif ($plural) { $PR_TABLE = 'project_requests'; }
          if ($PR_TABLE) {
            $stmtR = $db->prepare('SELECT * FROM ' . $PR_TABLE . ' WHERE id = ? OR request_id = ? LIMIT 1');
            $stmtR->execute([$rid, $rid]);
            $req = $stmtR->fetch(PDO::FETCH_ASSOC) ?: [];
          }
        } catch (Throwable $re) { /* ignore */ }
      }

  // Build insert payload dynamically
      $insCols = []; $vals = [];
  // Project name
      $rName = $req['project_name'] ?? ($req['project'] ?? ($req['name'] ?? ($req['title'] ?? null)));
      $pname = $rName ?: (trim((string)($inq['subject'] ?? '')) !== '' ? trim((string)$inq['subject']) : 'New Project');
      if (isset($projCols['project_name'])) { $insCols[] = 'project_name'; $vals[] = $pname; }
      // Description/details
      $rDetails = $req['details'] ?? ($req['project_details'] ?? ($req['description'] ?? null));
      $desc = $rDetails ?: (string)($inq['message'] ?? '');
      if ($desc !== '' && isset($projCols['description'])) { $insCols[] = 'description'; $vals[] = $desc; }
      // Client linkage
      $cliId = isset($inq['client_id']) ? (int)$inq['client_id'] : null;
      if ($cliId && isset($projCols['client_id'])) { $insCols[] = 'client_id'; $vals[] = $cliId; }
      // Created by current SA
      if (isset($projCols['created_by'])) { $insCols[] = 'created_by'; $vals[] = $currentUid; }
      // Project type (normalize to design_only|fit_out; accept "fit in")
      $rType = $req['project_type'] ?? ($req['type'] ?? null);
      if ($rType && isset($projCols['project_type'])) {
        $t = strtolower(trim((string)$rType));
        if ($t === 'fit in' || $t === 'fit_in') { $t = 'fit_out'; }
        if ($t !== 'design_only' && $t !== 'fit_out') { $t = 'design_only'; }
        $insCols[] = 'project_type'; $vals[] = $t;
      }
  // Project manager
  if ($pmId && isset($projCols['project_manager_id'])) { $insCols[] = 'project_manager_id'; $vals[] = $pmId; }
      // Location
      $rLoc = $req['location'] ?? ($req['address'] ?? ($req['site_location'] ?? null));
      if ($rLoc) {
        if (isset($projCols['location'])) { $insCols[] = 'location'; $vals[] = $rLoc; }
        elseif (isset($projCols['location_text'])) { $insCols[] = 'location_text'; $vals[] = $rLoc; }
      }
      // Budget
      $rBudget = $req['budget'] ?? ($req['budget_range'] ?? ($req['estimated_budget'] ?? null));
      if ($rBudget) {
        $budgetNum = preg_replace('/[^0-9.\-]/','',(string)$rBudget);
        if ($budgetNum === '') { $budgetNum = null; }
        if ($budgetNum !== null) {
          if (isset($projCols['budget'])) { $insCols[] = 'budget'; $vals[] = $budgetNum; }
          elseif (isset($projCols['budget_amount'])) { $insCols[] = 'budget_amount'; $vals[] = $budgetNum; }
        }
      }

      // Start date from request if available
      $rStart = $req['preferred_start_date'] ?? ($req['start_date'] ?? null);
      if ($rStart && isset($projCols['start_date'])) { $insCols[] = 'start_date'; $vals[] = $rStart; }

      if (!$insCols) { throw new Exception('No insertable project fields available.'); }
      $ph = rtrim(str_repeat('?,', count($insCols)), ',');
      $sql = 'INSERT INTO projects (' . implode(',', $insCols) . ') VALUES (' . $ph . ')';
      $ins = $db->prepare($sql); $ins->execute($vals);
      $newPid = (int)$db->lastInsertId();

      // Best-effort: link SA and PM in project_users if table exists or create minimal table
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS project_users (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, user_id INT NOT NULL, role_in_project VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(project_id), INDEX(user_id)) ENGINE=InnoDB");
        $link = $db->prepare('INSERT INTO project_users (project_id, user_id, role_in_project) VALUES (?,?,?)');
        $link->execute([$newPid, $currentUid, 'architect']);
  if ($pmId) { $link->execute([$newPid, $pmId, 'project_manager']); }
      } catch (Throwable $ie) { /* ignore */ }

      // Best-effort: overseen assignment for Senior Architect
      try {
        $db->exec("CREATE TABLE IF NOT EXISTS project_senior_architects (psa_id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, employee_id INT NOT NULL, role ENUM('lead','reviewer','advisor') NOT NULL DEFAULT 'advisor', assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX(project_id), INDEX(employee_id)) ENGINE=InnoDB");
        $empId = null;
        $stmtEmp = $db->prepare('SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1');
        $stmtEmp->execute([$currentUid]); $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);
        if ($emp && isset($emp['employee_id'])) {
          $empId = (int)$emp['employee_id'];
          $chk = $db->prepare('SELECT COUNT(*) FROM project_senior_architects WHERE project_id = ? AND employee_id = ?');
          $chk->execute([$newPid, $empId]);
          if ((int)$chk->fetchColumn() === 0) {
            $psaIns = $db->prepare("INSERT INTO project_senior_architects (project_id, employee_id, role) VALUES (?,?,'lead')");
            $psaIns->execute([$newPid, $empId]);
          }
        }
      } catch (Throwable $ie) { /* ignore */ }

      // Link inquiry to project and move status forward
      try { $db->prepare('UPDATE client_inquiries SET project_id=?, status=\'in_progress\' WHERE id=?')->execute([$newPid, $id]); } catch (Throwable $ue) {}

      header('Location: inquiries.php?created=1&pid=' . $newPid);
      exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
  }
}

// Filters
$f_type = $_GET['type'] ?? 'all'; // all|public|client
$f_status = $_GET['status'] ?? 'all';

// Build queries
$public = [];
$client = [];
$currentUid = (int)($_SESSION['user_id'] ?? 0);
try {
  // Public inquiries ensure table
  $db->exec("CREATE TABLE IF NOT EXISTS public_inquiries (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150), email VARCHAR(150), phone VARCHAR(50), inquiry_type VARCHAR(100), project_type VARCHAR(150), budget_range VARCHAR(100), message TEXT, location VARCHAR(255), status VARCHAR(50) DEFAULT 'new', assigned_to INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    // Add status column if legacy
    $col = $db->query("SHOW COLUMNS FROM public_inquiries LIKE 'status'")->fetch();
    if (!$col) { $db->exec("ALTER TABLE public_inquiries ADD COLUMN status VARCHAR(50) DEFAULT 'new'"); }
  // Ensure assigned_to column exists
  $colA = $db->query("SHOW COLUMNS FROM public_inquiries LIKE 'assigned_to'")->fetch();
  if (!$colA) { $db->exec("ALTER TABLE public_inquiries ADD COLUMN assigned_to INT NULL"); }

  $wherePub = [];
  $paramsPub = [];
  if ($f_status !== 'all' && in_array($f_status, $validStatuses, true)) { $wherePub[] = 'status = :status'; $paramsPub[':status'] = $f_status; }
  // Show inquiries assigned to current SA OR unassigned (claimable)
  $wherePub[] = '(assigned_to = :uid OR assigned_to IS NULL OR assigned_to = 0)';
  $sqlPub = 'SELECT id, name, email, inquiry_type, project_type, budget_range, message, location, status, assigned_to, created_at FROM public_inquiries';
  $sqlPub .= ' WHERE ' . implode(' AND ', $wherePub) . ' ORDER BY created_at DESC LIMIT 100';
  $stmtPub = $db->prepare($sqlPub);
  $paramsPub[':uid'] = $currentUid;
  $stmtPub->execute($paramsPub); $public = $stmtPub->fetchAll(PDO::FETCH_ASSOC);

    // Client inquiries
  $db->exec("CREATE TABLE IF NOT EXISTS client_inquiries (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, project_id INT NULL, request_id INT NULL, recipient_id INT NULL, subject VARCHAR(255), message TEXT, status VARCHAR(50) DEFAULT 'new', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
  // Ensure recipient_id exists
  $colRec = $db->query("SHOW COLUMNS FROM client_inquiries LIKE 'recipient_id'")->fetch();
  if (!$colRec) { $db->exec("ALTER TABLE client_inquiries ADD COLUMN recipient_id INT NULL"); }
    $col2 = $db->query("SHOW COLUMNS FROM client_inquiries LIKE 'status'")->fetch();
    if (!$col2) { $db->exec("ALTER TABLE client_inquiries ADD COLUMN status VARCHAR(50) DEFAULT 'new'"); }
  // Ensure request_id exists for linkage to project requests
  $colReq = $db->query("SHOW COLUMNS FROM client_inquiries LIKE 'request_id'")->fetch();
  if (!$colReq) { $db->exec("ALTER TABLE client_inquiries ADD COLUMN request_id INT NULL"); }
  $whereCli = [];$paramsCli = [];
  if ($f_status !== 'all' && in_array($f_status, $validStatuses, true)) { $whereCli[] = 'ci.status = ?'; $paramsCli[] = $f_status; }
  // Only show inquiries assigned to current SA
  $whereCli[] = 'ci.recipient_id = ?'; $paramsCli[] = $currentUid;
  // Detect actual projects PK (id vs project_id)
  $projCol = 'id';
  try {
    $colsProj = $db->query("SHOW COLUMNS FROM projects LIKE 'project_id'")->fetch();
    if ($colsProj) { $projCol = 'project_id'; }
  } catch (Throwable $ie) {}
  // Derive client display name robustly
  $usersCols = [];$clientsCols = [];$ciCols = [];
  try { foreach($db->query('SHOW COLUMNS FROM users') as $c){ $usersCols[$c['Field']] = true; } } catch (Throwable $eU) {}
  try { foreach($db->query('SHOW COLUMNS FROM clients') as $c){ $clientsCols[$c['Field']] = true; } } catch (Throwable $eC) {}
  try { foreach($db->query('SHOW COLUMNS FROM client_inquiries') as $c){ $ciCols[$c['Field']] = true; } } catch (Throwable $eCICols) {}

  $USERS_PK = isset($usersCols['id']) ? 'id' : (isset($usersCols['user_id']) ? 'user_id' : 'id');
  // Build safe name/email expressions for users (alias u and u2)
  if (isset($usersCols['full_name'])) { $USERS_NAME_SQL_U = 'u.full_name'; $USERS_NAME_SQL_U2 = 'u2.full_name'; }
  elseif (isset($usersCols['first_name']) && isset($usersCols['last_name'])) { $USERS_NAME_SQL_U = "CONCAT(u.first_name,' ',u.last_name)"; $USERS_NAME_SQL_U2 = "CONCAT(u2.first_name,' ',u2.last_name)"; }
  elseif (isset($usersCols['username'])) { $USERS_NAME_SQL_U = 'u.username'; $USERS_NAME_SQL_U2 = 'u2.username'; }
  elseif (isset($usersCols['email'])) { $USERS_NAME_SQL_U = 'u.email'; $USERS_NAME_SQL_U2 = 'u2.email'; }
  else { $USERS_NAME_SQL_U = "''"; $USERS_NAME_SQL_U2 = "''"; }
  $USERS_EMAIL_SQL_U = isset($usersCols['email']) ? 'u.email' : "NULL";
  $USERS_EMAIL_SQL_U2 = isset($usersCols['email']) ? 'u2.email' : "NULL";

  // Build a safe name expression for clients
  $CLIENTS_PK = isset($clientsCols['client_id']) ? 'client_id' : (isset($clientsCols['id']) ? 'id' : 'client_id');
  if (isset($clientsCols['full_name'])) { $CLIENTS_NAME_SQL = 'c.full_name'; }
  elseif (isset($clientsCols['client_name'])) { $CLIENTS_NAME_SQL = 'c.client_name'; }
  elseif (isset($clientsCols['company_name'])) { $CLIENTS_NAME_SQL = 'c.company_name'; }
  elseif (isset($clientsCols['name'])) { $CLIENTS_NAME_SQL = 'c.name'; }
  elseif (isset($clientsCols['first_name']) && isset($clientsCols['last_name'])) { $CLIENTS_NAME_SQL = "CONCAT(c.first_name,' ',c.last_name)"; }
  else { $CLIENTS_NAME_SQL = NULL; }
  $CLIENTS_EMAIL_SQL = isset($clientsCols['email']) ? 'c.email' : NULL;

  // Build joins: projects, clients (if available), and up to two users aliases
  $join = 'LEFT JOIN projects p ON ci.project_id = p.' . $projCol . ' ';
  $hasCiClientId = isset($ciCols['client_id']);
  $hasCiUserId = isset($ciCols['user_id']);
  if ($hasCiClientId && !empty($clientsCols)) { $join .= 'LEFT JOIN clients c ON ci.client_id = c.' . $CLIENTS_PK . ' '; }
  if (!empty($usersCols)) {
    if ($hasCiUserId) { $join .= 'LEFT JOIN users u ON ci.user_id = u.' . $USERS_PK . ' '; }
    if (!empty($clientsCols) && isset($clientsCols['user_id'])) { $join .= 'LEFT JOIN users u2 ON c.user_id = u2.' . $USERS_PK . ' '; }
    // Fallback: try mapping ci.client_id to users.user_id if schema used that earlier
    if ($hasCiClientId) { $join .= 'LEFT JOIN users u3 ON ci.client_id = u3.user_id '; }
  }

  // Build COALESCE name/email across clients and user aliases
  $clientNamePieces = [];
  if (!empty($clientsCols) && $hasCiClientId) {
    $clientNamePieces[] = "NULLIF(TRIM(" . ($CLIENTS_NAME_SQL ?: "''") . "), '')";
  }
  if (!empty($usersCols)) {
    if ($hasCiUserId) { $clientNamePieces[] = "NULLIF(TRIM(" . $USERS_NAME_SQL_U . "), '')"; }
    if (!empty($clientsCols) && isset($clientsCols['user_id'])) { $clientNamePieces[] = "NULLIF(TRIM(" . $USERS_NAME_SQL_U2 . "), '')"; }
    if ($hasCiClientId) {
      // third alias u3 fallback
      if (isset($usersCols['full_name'])) { $USER_NAME_SQL_U3 = 'u3.full_name'; }
      elseif (isset($usersCols['first_name']) && isset($usersCols['last_name'])) { $USER_NAME_SQL_U3 = "CONCAT(u3.first_name,' ',u3.last_name)"; }
      elseif (isset($usersCols['username'])) { $USER_NAME_SQL_U3 = 'u3.username'; }
      elseif (isset($usersCols['email'])) { $USER_NAME_SQL_U3 = 'u3.email'; }
      else { $USER_NAME_SQL_U3 = "''"; }
      $clientNamePieces[] = "NULLIF(TRIM(" . $USER_NAME_SQL_U3 . "), '')";
    }
  }
  $nameSel = 'COALESCE(' . implode(', ', $clientNamePieces ?: ["'Unknown'"]) . ", 'Unknown') AS client_name";

  $emailPieces = [];
  if (!empty($clientsCols) && $hasCiClientId && $CLIENTS_EMAIL_SQL) { $emailPieces[] = $CLIENTS_EMAIL_SQL; }
  if (!empty($usersCols)) {
    if ($hasCiUserId) { $emailPieces[] = $USERS_EMAIL_SQL_U; }
    if (!empty($clientsCols) && isset($clientsCols['user_id'])) { $emailPieces[] = $USERS_EMAIL_SQL_U2; }
    if ($hasCiClientId) { $emailPieces[] = (isset($usersCols['email']) ? 'u3.email' : 'NULL'); }
  }
  $emailSel = 'COALESCE(' . implode(', ', $emailPieces ?: ['NULL']) . ') AS client_email';

  // Detect project request table and columns
  $PR_TABLE = null; $prCols = [];
  try {
    $hasSing = $db->query("SHOW TABLES LIKE 'project_request'");
    $hasPlu  = $db->query("SHOW TABLES LIKE 'project_requests'");
    $singular = $hasSing && $hasSing->rowCount() > 0;
    $plural  = $hasPlu && $hasPlu->rowCount() > 0;
    if ($singular && $plural) {
      $c1 = 0; $c2 = 0;
      try { $c1 = (int)$db->query("SELECT COUNT(*) FROM project_request")->fetchColumn(); } catch (Throwable $ie1) {}
      try { $c2 = (int)$db->query("SELECT COUNT(*) FROM project_requests")->fetchColumn(); } catch (Throwable $ie2) {}
      $PR_TABLE = ($c1 >= $c2) ? 'project_request' : 'project_requests';
    } elseif ($singular) { $PR_TABLE = 'project_request'; }
    elseif ($plural) { $PR_TABLE = 'project_requests'; }
  } catch (Throwable $te) {}
  if ($PR_TABLE) {
    try { foreach($db->query('SHOW COLUMNS FROM ' . $PR_TABLE) as $c){ $prCols[$c['Field']] = true; } } catch (Throwable $pe) {}
  }
  $PR_PK = $PR_TABLE ? (isset($prCols['id']) ? 'id' : (isset($prCols['request_id']) ? 'request_id' : null)) : null;
  if ($PR_TABLE && $PR_PK) {
    $join .= 'LEFT JOIN ' . $PR_TABLE . ' pr ON pr.' . $PR_PK . ' = ci.request_id ';
  }

  // Map request fields safely
  $REQ_NAME_SQL    = isset($prCols['project_name']) ? 'pr.project_name' : (isset($prCols['project']) ? 'pr.project' : (isset($prCols['name']) ? 'pr.name' : (isset($prCols['title']) ? 'pr.title' : 'NULL')));
  $REQ_TYPE_SQL    = isset($prCols['project_type']) ? 'pr.project_type' : (isset($prCols['type']) ? 'pr.type' : 'NULL');
  $REQ_BUDGET_SQL  = isset($prCols['budget']) ? 'pr.budget' : (isset($prCols['budget_range']) ? 'pr.budget_range' : (isset($prCols['estimated_budget']) ? 'pr.estimated_budget' : 'NULL'));
  $REQ_LOC_SQL     = isset($prCols['location']) ? 'pr.location' : (isset($prCols['address']) ? 'pr.address' : (isset($prCols['site_location']) ? 'pr.site_location' : 'NULL'));
  $REQ_STATUS_SQL  = isset($prCols['status']) ? 'pr.status' : (isset($prCols['request_status']) ? 'pr.request_status' : 'NULL');
  $REQ_DETAILS_SQL = isset($prCols['details']) ? 'pr.details' : (isset($prCols['project_details']) ? 'pr.project_details' : (isset($prCols['description']) ? 'pr.description' : 'NULL'));
  $REQ_CREATED_SQL = isset($prCols['created_at']) ? 'pr.created_at' : (isset($prCols['created_on']) ? 'pr.created_on' : (isset($prCols['created_date']) ? 'pr.created_date' : (isset($prCols['submitted_at']) ? 'pr.submitted_at' : 'NULL')));

  $sqlCli = 'SELECT ci.id, ci.subject, ci.message, ci.status, ci.created_at, ci.request_id, p.project_name, '
    . $nameSel . ', ' . $emailSel . ', '
    . ($PR_TABLE && $PR_PK ? 'pr.' . $PR_PK . ' AS req_id, ' : 'NULL AS req_id, ')
    . $REQ_NAME_SQL . ' AS req_project_name, '
    . $REQ_TYPE_SQL . ' AS req_project_type, '
    . $REQ_LOC_SQL . ' AS req_location, '
    . $REQ_BUDGET_SQL . ' AS req_budget, '
    . $REQ_STATUS_SQL . ' AS req_status, '
    . $REQ_CREATED_SQL . ' AS req_created_at, '
    . $REQ_DETAILS_SQL . ' AS req_details '
    . 'FROM client_inquiries ci '
    . $join;
  if ($whereCli) { $sqlCli .= ' WHERE ' . implode(' AND ', $whereCli); }
    $sqlCli .= ' ORDER BY ci.created_at DESC LIMIT 100';
    $stmtCli = $db->prepare($sqlCli); $stmtCli->execute($paramsCli); $client = $stmtCli->fetchAll(PDO::FETCH_ASSOC);
    // Load PM candidates for assignment dropdown
    $pmCandidates = [];
    try {
      $usersCols2 = [];
      foreach($db->query('SHOW COLUMNS FROM users') as $c){ $usersCols2[$c['Field']]=true; }
      $idCol = isset($usersCols2['id']) ? 'id' : (isset($usersCols2['user_id']) ? 'user_id' : 'id');
      $nameExpr = isset($usersCols2['full_name']) ? 'full_name' : (isset($usersCols2['username']) ? 'username' : (isset($usersCols2['email']) ? 'email' : $idCol));
      $conds = [];
      if (isset($usersCols2['position'])) {
        $conds[] = "LOWER(REPLACE(REPLACE(position,'_',' '),'-',' ')) LIKE '%project manager%'";
        $conds[] = "LOWER(position) IN ('project_manager','project manager','pm')";
      }
      if (isset($usersCols2['role'])) { $conds[] = "LOWER(role) IN ('project_manager','pm','project manager')"; }
      if (isset($usersCols2['user_type']) && isset($usersCols2['position'])) {
        $conds[] = "(LOWER(user_type)='employee' AND LOWER(REPLACE(REPLACE(position,'_',' '),'-',' ')) LIKE '%project manager%')";
      } elseif (isset($usersCols2['user_type'])) { $conds[] = "LOWER(user_type) IN ('project_manager','pm')"; }
      $where = $conds ? implode(' OR ', $conds) : '1=1';
      $pmSql = "SELECT $idCol AS id, $nameExpr AS name FROM users WHERE $where ORDER BY name LIMIT 300";
      $pmCandidates = $db->query($pmSql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $pe) { $pmCandidates = []; }
} catch (Throwable $e) { $error = $e->getMessage(); }

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-blue-50 p-6">
  <div class="max-w-full">
    <div class="mb-8">
      <h1 class="text-3xl font-bold mb-2">Inquiries</h1>
      <p class="text-gray-600">Review and manage public & client inquiries.</p>
      <?php if (!empty($error)): ?>
        <div class="mt-4 p-4 bg-red-50 text-red-700 ring-1 ring-red-200 rounded">Error: <?php echo htmlspecialchars($error); ?></div>
      <?php elseif(isset($_GET['updated'])): ?>
        <div class="mt-4 p-4 bg-green-50 text-green-700 ring-1 ring-green-200 rounded">Status updated.</div>
      <?php elseif(isset($_GET['created'])): ?>
        <div class="mt-4 p-4 bg-green-50 text-green-700 ring-1 ring-green-200 rounded">Project created successfully.</div>
      <?php endif; ?>
      <?php if(isset($email_success) && $email_success): ?>
        <div class="mt-4 p-4 bg-green-50 text-green-700 ring-1 ring-green-200 rounded">Email sent.</div>
      <?php elseif(!empty($email_error)): ?>
        <div class="mt-4 p-4 bg-red-50 text-red-700 ring-1 ring-red-200 rounded">Email Error: <?php echo htmlspecialchars($email_error); ?></div>
      <?php endif; ?>
    </div>

    <!-- Filters -->
    <form method="get" class="flex flex-wrap gap-3 mb-6 items-end">
      <div>
        <label class="block text-xs font-semibold text-gray-500 mb-1">Type</label>
        <select name="type" class="px-3 py-2 border rounded-lg">
          <option value="all" <?php if($f_type==='all') echo 'selected';?>>All</option>
          <option value="public" <?php if($f_type==='public') echo 'selected';?>>Public</option>
          <option value="client" <?php if($f_type==='client') echo 'selected';?>>Client</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-500 mb-1">Status</label>
        <select name="status" class="px-3 py-2 border rounded-lg">
          <option value="all" <?php if($f_status==='all') echo 'selected';?>>All</option>
          <?php foreach($validStatuses as $vs): ?>
            <option value="<?php echo $vs; ?>" <?php if($f_status===$vs) echo 'selected';?>><?php echo ucfirst(str_replace('_',' ',$vs)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="px-4 py-2 bg-slate-800 text-white rounded-lg hover:bg-slate-700">Apply</button>
    </form>

    <div class="grid gap-6 lg:grid-cols-2">
      <!-- Public Inquiries -->
      <?php if($f_type==='all' || $f_type==='public'): ?>
      <section class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="p-4 border-b bg-slate-50">
          <h2 class="font-semibold">Public Inquiries (<?php echo count($public); ?>)</h2>
        </div>
        <div class="divide-y max-h-[600px] overflow-y-auto">
          <?php if(!$public): ?>
            <div class="p-4 text-gray-500">No public inquiries.</div>
          <?php else: foreach($public as $pi): ?>
            <div class="p-4 flex flex-col gap-2">
              <div class="flex justify-between items-start">
                <div>
                  <div class="font-medium text-gray-900"><?php echo htmlspecialchars($pi['name']); ?> <span class="text-xs text-gray-500">(<?php echo htmlspecialchars($pi['email']); ?>)</span></div>
                  <div class="text-xs text-gray-500">Type: <?php echo htmlspecialchars($pi['inquiry_type'] ?: '-'); ?> | Project: <?php echo htmlspecialchars($pi['project_type'] ?: '-'); ?> | Budget: <?php echo htmlspecialchars($pi['budget_range'] ?: '-'); ?></div>
                </div>
                <div class="flex items-center gap-2">
                  <?php if (empty($pi['assigned_to'])): ?>
                    <span class="px-2 py-1 rounded-full text-xs bg-yellow-100 text-yellow-700 border border-yellow-200">Unassigned</span>
                  <?php else: ?>
                    <span class="px-2 py-1 rounded-full text-xs bg-slate-100 text-slate-700 border border-slate-200"><?php echo htmlspecialchars($pi['status']); ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="text-sm text-gray-700 whitespace-pre-line"><?php echo nl2br(htmlspecialchars($pi['message'])); ?></div>
              <div class="flex justify-between items-center text-xs text-gray-500">
                <span><i class="far fa-clock mr-1"></i><?php echo date('M d, Y H:i', strtotime($pi['created_at'])); ?></span>
                <div class="flex items-center gap-2">
                  <?php if (empty($pi['assigned_to'])): ?>
                    <form method="post" class="inline">
                      <input type="hidden" name="id" value="<?php echo (int)$pi['id']; ?>">
                      <button name="claim_public" value="1" class="px-2 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-xs">Claim</button>
                    </form>
                  <?php else: ?>
                    <form method="post" class="flex items-center gap-2">
                      <input type="hidden" name="id" value="<?php echo (int)$pi['id']; ?>">
                      <input type="hidden" name="type" value="public">
                      <select name="status" class="text-xs border rounded px-2 py-1">
                        <?php foreach($validStatuses as $vs): ?>
                          <option value="<?php echo $vs; ?>" <?php if($pi['status']===$vs) echo 'selected';?>><?php echo ucfirst(str_replace('_',' ',$vs)); ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button name="update_status" class="px-2 py-1 bg-blue-600 text-white rounded text-xs">Save</button>
                    </form>
                    <button type="button"
                            class="px-2 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-xs"
                            data-open-email-modal
                            data-inquiry-type="public"
                            data-inquiry-id="<?php echo (int)$pi['id']; ?>"
                            data-inquiry-email="<?php echo htmlspecialchars($pi['email'], ENT_QUOTES); ?>"
                            data-inquiry-name="<?php echo htmlspecialchars($pi['name'], ENT_QUOTES); ?>"
                            data-inquiry-subject="Re: <?php echo htmlspecialchars($pi['inquiry_type'] ?: 'Inquiry', ENT_QUOTES); ?>">
                      Email Inquiry
                    </button>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </section>
      <?php endif; ?>
      <!-- Client Inquiries -->
      <?php if($f_type==='all' || $f_type==='client'): ?>
      <section class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="p-4 border-b bg-slate-50">
          <h2 class="font-semibold">Client Inquiries (<?php echo count($client); ?>)</h2>
        </div>
        <div class="divide-y max-h-[600px] overflow-y-auto">
          <?php if(!$client): ?>
            <div class="p-4 text-gray-500">No client inquiries.</div>
          <?php else: foreach($client as $ci): ?>
            <div class="p-4 flex flex-col gap-2">
              <div class="flex justify-between items-start">
                <div>
                  <div class="font-medium text-gray-900">Subject: <?php echo htmlspecialchars($ci['subject']); ?></div>
                  <div class="text-xs text-gray-500">Project: <?php echo htmlspecialchars($ci['project_name'] ?: '-'); ?> | Client: <?php echo htmlspecialchars(trim((string)($ci['client_name'] ?? '')) !== '' ? trim((string)$ci['client_name']) : 'Unknown'); ?></div>
                </div>
                <span class="px-2 py-1 rounded-full text-xs bg-slate-100 text-slate-700 border border-slate-200"><?php echo htmlspecialchars($ci['status']); ?></span>
              </div>
              <div class="text-sm text-gray-700 whitespace-pre-line"><?php echo nl2br(htmlspecialchars($ci['message'])); ?></div>
              <div class="flex justify-between items-center text-xs text-gray-500">
                <span><i class="far fa-clock mr-1"></i><?php echo date('M d, Y H:i', strtotime($ci['created_at'])); ?></span>
                <div class="flex items-center gap-2">
                  <?php if(!empty($ci['request_id'])): ?>
                  <button type="button"
                          class="px-2 py-1 bg-slate-700 hover:bg-slate-800 text-white rounded text-xs"
                          data-open-request-modal
                          data-req-id="<?php echo (int)($ci['req_id'] ?? $ci['request_id']); ?>"
                          data-req-name="<?php echo htmlspecialchars((string)($ci['req_project_name'] ?? ''), ENT_QUOTES); ?>"
                          data-req-type="<?php echo htmlspecialchars((string)($ci['req_project_type'] ?? ''), ENT_QUOTES); ?>"
                          data-req-location="<?php echo htmlspecialchars((string)($ci['req_location'] ?? ''), ENT_QUOTES); ?>"
                          data-req-budget="<?php echo htmlspecialchars((string)($ci['req_budget'] ?? ''), ENT_QUOTES); ?>"
                          data-req-status="<?php echo htmlspecialchars((string)($ci['req_status'] ?? ''), ENT_QUOTES); ?>"
                          data-req-created="<?php echo htmlspecialchars((string)($ci['req_created_at'] ?? ''), ENT_QUOTES); ?>"
                          data-req-details="<?php echo htmlspecialchars((string)($ci['req_details'] ?? ''), ENT_QUOTES); ?>">
                    View
                  </button>
                  <?php endif; ?>
                  <form method="post" class="flex items-center gap-2">
                    <input type="hidden" name="id" value="<?php echo (int)$ci['id']; ?>">
                    <input type="hidden" name="type" value="client">
                    <select name="status" class="text-xs border rounded px-2 py-1">
                      <?php foreach($validStatuses as $vs): ?>
                        <option value="<?php echo $vs; ?>" <?php if($ci['status']===$vs) echo 'selected';?>><?php echo ucfirst(str_replace('_',' ',$vs)); ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button name="update_status" class="px-2 py-1 bg-blue-600 text-white rounded text-xs">Save</button>
                  </form>
                  <button type="button"
                          class="px-2 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-xs"
                          data-open-email-modal
                          data-inquiry-type="client"
                          data-inquiry-id="<?php echo (int)$ci['id']; ?>"
                          data-inquiry-email="<?php echo htmlspecialchars($ci['client_email'] ?? '', ENT_QUOTES); ?>"
                          data-inquiry-name="<?php echo htmlspecialchars((trim((string)($ci['client_name'] ?? '')) !== '' ? trim((string)$ci['client_name']) : 'Client'), ENT_QUOTES); ?>"
                          data-inquiry-subject="Re: <?php echo htmlspecialchars($ci['subject'] ?: 'Client Inquiry', ENT_QUOTES); ?>">
                    Email Inquiry
                  </button>
                  <form method="post" class="flex items-center gap-2">
                    <input type="hidden" name="id" value="<?php echo (int)$ci['id']; ?>">
                    <input type="hidden" name="type" value="client">
                    <?php if (!empty($pmCandidates)): ?>
                      <select name="project_manager_id" class="text-xs border rounded px-2 py-1">
                        <option value="">PM</option>
                        <?php foreach($pmCandidates as $pm): ?>
                          <option value="<?php echo (int)$pm['id']; ?>"><?php echo htmlspecialchars($pm['name']); ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php endif; ?>
                    <button name="create_project" class="px-2 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-xs">Create Project</button>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </section>
      <?php endif; ?>
    </div>
  </div>
</main>

<!-- Request Details Modal -->
<div id="requestDetailsModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
  <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" data-close-request-modal></div>
  <div class="relative max-w-2xl mx-auto mt-16 bg-white rounded-xl shadow-xl ring-1 ring-slate-200 overflow-hidden">
    <div class="p-6">
      <div class="flex justify-between items-start mb-4">
        <h3 class="text-lg font-semibold">Project Request Details</h3>
        <button type="button" class="text-slate-400 hover:text-slate-600" data-close-request-modal>&times;</button>
      </div>
      <div class="grid md:grid-cols-2 gap-4 text-sm">
        <div>
          <div class="text-slate-500">Project Name</div>
          <div id="req_project_name" class="font-medium text-slate-900">-</div>
        </div>
        <div>
          <div class="text-slate-500">Type</div>
          <div id="req_project_type" class="font-medium text-slate-900">-</div>
        </div>
        <div>
          <div class="text-slate-500">Location</div>
          <div id="req_location" class="font-medium text-slate-900">-</div>
        </div>
        <div>
          <div class="text-slate-500">Budget</div>
          <div id="req_budget" class="font-medium text-slate-900">-</div>
        </div>
        <div>
          <div class="text-slate-500">Status</div>
          <div id="req_status" class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-slate-100 text-slate-700 border border-slate-200">-</div>
        </div>
        <div>
          <div class="text-slate-500">Submitted</div>
          <div id="req_created" class="font-medium text-slate-900">-</div>
        </div>
      </div>
      <div class="mt-4">
        <div class="text-slate-500 mb-1">Details</div>
        <div id="req_details" class="text-slate-800 whitespace-pre-line"></div>
      </div>
      <div class="mt-4 text-xs text-slate-500">Request ID: <span id="req_id">-</span></div>
      <div class="flex justify-end mt-6">
        <button type="button" class="px-4 py-2 text-sm border rounded-lg" data-close-request-modal>Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Email Inquiry Modal -->
<div id="emailInquiryModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
  <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" data-close-email-modal></div>
  <div class="relative max-w-lg mx-auto mt-24 bg-white rounded-xl shadow-xl ring-1 ring-slate-200 overflow-hidden">
    <form method="post" class="p-6 space-y-4" id="emailInquiryForm">
      <input type="hidden" name="send_inquiry_email" value="1">
      <input type="hidden" name="email_type" id="modal_email_type" value="">
      <input type="hidden" name="inquiry_id" id="modal_inquiry_id" value="">
      <div class="flex justify-between items-start">
        <h3 class="text-lg font-semibold">Email Inquiry</h3>
        <button type="button" class="text-slate-400 hover:text-slate-600" data-close-email-modal>&times;</button>
      </div>
      <div class="grid md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-slate-500 mb-1">To</label>
          <input name="to_email" id="modal_to_email" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="recipient@example.com" required>
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-500 mb-1">Subject</label>
          <input name="reply_subject" id="modal_reply_subject" class="w-full px-3 py-2 border rounded-lg text-sm" required>
        </div>
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-500 mb-1">Message</label>
        <textarea name="reply_message" id="modal_reply_message" rows="6" class="w-full px-3 py-2 border rounded-lg text-sm" required></textarea>
      </div>
      <div class="flex justify-end gap-2 pt-2">
        <button type="button" class="px-4 py-2 text-sm border rounded-lg" data-close-email-modal>Cancel</button>
        <button class="px-4 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Send Email</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../../backend/core/footer.php'; ?>
<script>
(function(){
  // Request Details Modal logic
  const reqModal = document.getElementById('requestDetailsModal');
  const reqFields = reqModal ? {
    id: reqModal.querySelector('#req_id'),
    name: reqModal.querySelector('#req_project_name'),
    type: reqModal.querySelector('#req_project_type'),
    location: reqModal.querySelector('#req_location'),
    budget: reqModal.querySelector('#req_budget'),
    status: reqModal.querySelector('#req_status'),
    created: reqModal.querySelector('#req_created'),
    details: reqModal.querySelector('#req_details')
  } : {};
  function openReqModal(data){
    if(!reqModal) return;
    if(reqFields.id) reqFields.id.textContent = data.id || '-';
    if(reqFields.name) reqFields.name.textContent = data.name || '-';
    if(reqFields.type) reqFields.type.textContent = data.type || '-';
    if(reqFields.location) reqFields.location.textContent = data.location || '-';
    if(reqFields.budget) reqFields.budget.textContent = data.budget || '-';
    if(reqFields.status) reqFields.status.textContent = data.status || '-';
    if(reqFields.created) reqFields.created.textContent = data.created ? (new Date(data.created)).toLocaleString() : '-';
    if(reqFields.details) reqFields.details.textContent = data.details || '';
    reqModal.classList.remove('hidden');
    reqModal.setAttribute('aria-hidden','false');
  }
  function closeReqModal(){
    if(!reqModal) return;
    reqModal.classList.add('hidden');
    reqModal.setAttribute('aria-hidden','true');
  }
  const modal = document.getElementById('emailInquiryModal');
  const form = document.getElementById('emailInquiryForm');
  const emailType = document.getElementById('modal_email_type');
  const inquiryId = document.getElementById('modal_inquiry_id');
  const toEmail = document.getElementById('modal_to_email');
  const subj = document.getElementById('modal_reply_subject');
  const msg = document.getElementById('modal_reply_message');

  function openModal(data){
    emailType.value = data.type || '';
    inquiryId.value = data.id || '';
    toEmail.value = data.email || '';
    subj.value = data.subject || '';
    const greetingName = data.name ? data.name : 'Client';
    if(!msg.value || msg.getAttribute('data-autofilled') !== '1'){
      msg.value = 'Hi ' + greetingName + ',\n\n';
      msg.setAttribute('data-autofilled','1');
    }
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden','false');
    toEmail.focus();
  }
  function closeModal(){
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden','true');
  }
  document.addEventListener('click', function(e){
    // Open Request Details modal
    const reqBtn = e.target.closest('[data-open-request-modal]');
    if(reqBtn){
      openReqModal({
        id: reqBtn.getAttribute('data-req-id'),
        name: reqBtn.getAttribute('data-req-name'),
        type: reqBtn.getAttribute('data-req-type'),
        location: reqBtn.getAttribute('data-req-location'),
        budget: reqBtn.getAttribute('data-req-budget'),
        status: reqBtn.getAttribute('data-req-status'),
        created: reqBtn.getAttribute('data-req-created'),
        details: reqBtn.getAttribute('data-req-details')
      });
      return;
    }
    const openBtn = e.target.closest('[data-open-email-modal]');
    if(openBtn){
      openModal({
        type: openBtn.getAttribute('data-inquiry-type'),
        id: openBtn.getAttribute('data-inquiry-id'),
        email: openBtn.getAttribute('data-inquiry-email') || '',
        name: openBtn.getAttribute('data-inquiry-name') || '',
        subject: openBtn.getAttribute('data-inquiry-subject') || ''
      });
      return;
    }
    if(e.target.matches('[data-close-request-modal]')){ closeReqModal(); }
    if(e.target.matches('[data-close-email-modal]')){
      closeModal();
    }
  });
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
      if(reqModal && !reqModal.classList.contains('hidden')) closeReqModal();
      if(!modal.classList.contains('hidden')) closeModal();
    }
  });
  modal.addEventListener('click', function(e){
    if(e.target === modal) closeModal();
  });
  if(reqModal){ reqModal.addEventListener('click', function(e){ if(e.target === reqModal) closeReqModal(); }); }
})();
</script>

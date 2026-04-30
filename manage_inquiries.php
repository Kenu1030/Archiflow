<?php
// Session and role/position guard before any output (admin required)
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Compute app base (supports /ArchiFlow subfolder)
$APP_BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($APP_BASE === '/' || $APP_BASE === '.') { $APP_BASE = ''; }

// Compute root base for redirects
$ROOT_BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($ROOT_BASE === '/' || $ROOT_BASE === '.') { $ROOT_BASE = ''; }

// Accept both schemes: backend (user_type=admin) or legacy (role in admin/administrator)
$userType = strtolower((string)($_SESSION['user_type'] ?? ''));
$roleVal  = strtolower((string)($_SESSION['role'] ?? ''));
$isLogged = isset($_SESSION['logged_in']) ? ($_SESSION['logged_in'] === true) : isset($_SESSION['user_id']);
if (!$isLogged || !($userType === 'admin' || in_array($roleVal, ['admin','administrator'], true))) {
    header('Location: ' . $ROOT_BASE . '/login.php');
    exit;
}

require_once __DIR__ . '/backend/connection/connect.php';

$db = null;
$errorMsg = '';

// Inputs
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$typeFilter = isset($_GET['type']) ? trim((string)$_GET['type']) : '';

// Utilities
function fetchUsersColumns(PDO $db): array {
  $cols = [];
  $stmt = $db->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
  $stmt->execute();
  foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $c) { $cols[$c] = true; }
  $idCol = isset($cols['user_id']) ? 'user_id' : (isset($cols['id']) ? 'id' : null);
  $nameExpr = isset($cols['first_name']) && isset($cols['last_name']) ? "CONCAT(first_name,' ',last_name)"
    : (isset($cols['username']) ? 'username' : (isset($cols['email']) ? 'email' : "CONCAT('User #'," . ($idCol ?: '0') . ")"));
  $hasUserType = isset($cols['user_type']);
  $hasPosition = isset($cols['position']);
  $hasRole = isset($cols['role']);
  return [$idCol, $nameExpr, $hasUserType, $hasPosition, $hasRole];
}

// Admin user deletion handler (soft delete preferred, hard delete with unlink)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_delete_user'])) {
  $delId = (int)($_POST['admin_delete_user'] ?? 0);
  $delError = '';
  try {
    // Ensure $db is initialized
    if (!isset($db) || !$db) {
      require_once __DIR__ . '/backend/connection/connect.php';
      $db = getDB();
      $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    $uCols = [];
    foreach ($db->query('SHOW COLUMNS FROM users') as $uc) { $uCols[$uc['Field']] = true; }
    $pk = isset($uCols['user_id']) ? 'user_id' : (isset($uCols['id']) ? 'id' : null);
    if ($pk === null) throw new Exception('Users PK not found');
    $hasIsDeleted = isset($uCols['is_deleted']);
    $hasStatus = isset($uCols['status']);
    // Prefer soft delete
    if ($hasIsDeleted) {
      $db->prepare("UPDATE users SET is_deleted=1 WHERE `$pk`=?")->execute([$delId]);
    } elseif ($hasStatus) {
      $db->prepare("UPDATE users SET status='deleted' WHERE `$pk`=?")->execute([$delId]);
    } else {
      // Try hard delete with dynamic best-effort unlink
      $db->beginTransaction();
      // Find all tables/columns referencing users
      $refCols = $db->prepare("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND (COLUMN_NAME='user_id' OR COLUMN_NAME='id' OR COLUMN_NAME=?)");
      $refCols->execute([$pk]);
      $refs = $refCols->fetchAll(PDO::FETCH_ASSOC);
      foreach ($refs as $ref) {
        $table = $ref['TABLE_NAME'];
        $col = $ref['COLUMN_NAME'];
        // Don't null users PK itself
        if ($table === 'users' && $col === $pk) continue;
        // Only null if column is nullable
        $colInfo = $db->prepare("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $colInfo->execute([$table, $col]);
        $isNullable = ($colInfo->fetchColumn() === 'YES');
        if ($isNullable) {
          $db->prepare("UPDATE `$table` SET `$col`=NULL WHERE `$col`=?")->execute([$delId]);
        }
      }
      // Also null known inquiry assignment columns
      foreach ([['public_inquiries','assigned_to'],['client_inquiries','recipient_id']] as $pair) {
        $table = $pair[0]; $col = $pair[1];
        $colInfo = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $colInfo->execute([$table, $col]);
        if ((int)$colInfo->fetchColumn() > 0) {
          $db->prepare("UPDATE `$table` SET `$col`=NULL WHERE `$col`=?")->execute([$delId]);
        }
      }
      $db->prepare("DELETE FROM users WHERE `$pk`=?")->execute([$delId]);
      $db->commit();
    }
  } catch (Throwable $e) {
    if (isset($db) && $db && method_exists($db, 'inTransaction') && $db->inTransaction()) $db->rollBack();
    $delError = 'Failed to delete user: ' . $e->getMessage();
  }
  if ($delError) {
    echo '<div class="mb-6 p-4 rounded-lg ring-1 ring-red-200 bg-red-50 text-red-800"><strong>' . htmlspecialchars($delError) . '</strong></div>';
  } else {
    echo '<div class="mb-6 p-4 rounded-lg ring-1 ring-green-200 bg-green-50 text-green-800"><strong>User deleted successfully.</strong></div>';
  }
}

try {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure public_inquiries schema exists
    $db->exec("CREATE TABLE IF NOT EXISTS public_inquiries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150),
        email VARCHAR(150),
        phone VARCHAR(50),
        inquiry_type VARCHAR(100),
        project_type VARCHAR(150),
        budget_range VARCHAR(100),
        location VARCHAR(255),
        message TEXT,
        status VARCHAR(50) DEFAULT 'new',
        assigned_to INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Add assigned_to if missing
    $chk = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='public_inquiries' AND COLUMN_NAME='assigned_to'");
    $chk->execute();
    if ((int)$chk->fetchColumn() === 0) { $db->exec("ALTER TABLE public_inquiries ADD COLUMN assigned_to INT NULL"); }

    // Ensure client_inquiries schema (defensive & tolerant)
    $db->exec("CREATE TABLE IF NOT EXISTS client_inquiries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NULL,
        project_id INT NULL,
        recipient_id INT NULL,
        category VARCHAR(50) DEFAULT 'general',
        subject VARCHAR(255),
        message TEXT,
        status VARCHAR(50) DEFAULT 'new',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Add missing columns as needed
    foreach ([["recipient_id","INT NULL"], ["category","VARCHAR(50) DEFAULT 'general'"], ["status","VARCHAR(50) DEFAULT 'new'"]] as $col) {
        $chkCol = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_inquiries' AND COLUMN_NAME = ?");
        $chkCol->execute([$col[0]]);
        if ((int)$chkCol->fetchColumn() === 0) { $db->exec("ALTER TABLE client_inquiries ADD COLUMN {$col[0]} {$col[1]}"); }
    }

    // Ensure notifications table (best-effort)
    $db->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255),
        message TEXT,
        type VARCHAR(50) DEFAULT 'inquiry',
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Public inquiries actions
        if (isset($_POST['update_status'])) {
            $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
            $newStatus = trim((string)($_POST['status'] ?? 'new'));
            if ($inquiryId > 0) {
                $st = $db->prepare('UPDATE public_inquiries SET status = ? WHERE id = ?');
                $st->execute([$newStatus, $inquiryId]);
            }
            header('Location: manage_inquiries.php'); exit;
        }
        if (isset($_POST['delete_inquiry'])) {
            $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
            if ($inquiryId > 0) {
                $st = $db->prepare('DELETE FROM public_inquiries WHERE id = ?');
                $st->execute([$inquiryId]);
            }
            header('Location: manage_inquiries.php'); exit;
        }
        if (isset($_POST['assign_inquiry'])) {
            $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
            $assignTo = isset($_POST['assign_to']) && $_POST['assign_to'] !== '' ? (int)$_POST['assign_to'] : null;
            $st = $db->prepare("UPDATE public_inquiries SET assigned_to = :uid, status = CASE WHEN status='new' THEN 'in_review' ELSE status END WHERE id = :id");
            if ($assignTo === null) { $st->bindValue(':uid', null, PDO::PARAM_NULL); } else { $st->bindValue(':uid', $assignTo, PDO::PARAM_INT); }
            $st->bindValue(':id', $inquiryId, PDO::PARAM_INT);
            $st->execute();
            if ($assignTo) {
                $title = 'Public Inquiry Assigned';
                $msg = 'A public inquiry (ID ' . $inquiryId . ') has been assigned to you.';
                $ins = $db->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, "inquiry")');
                $ins->execute([$assignTo, $title, $msg]);
            }
            header('Location: manage_inquiries.php'); exit;
        }

        // Client inquiries actions
        if (isset($_POST['c_update_status'])) {
            $cid = (int)($_POST['c_inquiry_id'] ?? 0);
            $new = trim((string)($_POST['c_status'] ?? 'new'));
            if ($cid > 0) { $db->prepare('UPDATE client_inquiries SET status = ? WHERE id = ?')->execute([$new, $cid]); }
            header('Location: manage_inquiries.php'); exit;
        }
        if (isset($_POST['c_delete_inquiry'])) {
            $cid = (int)($_POST['c_inquiry_id'] ?? 0);
            if ($cid > 0) { $db->prepare('DELETE FROM client_inquiries WHERE id = ?')->execute([$cid]); }
            header('Location: manage_inquiries.php'); exit;
        }
        if (isset($_POST['c_assign_inquiry'])) {
            $cid = (int)($_POST['c_inquiry_id'] ?? 0);
            $assignTo = isset($_POST['c_assign_to']) && $_POST['c_assign_to'] !== '' ? (int)$_POST['c_assign_to'] : null;
            if ($cid > 0) {
                $stmt = $db->prepare("UPDATE client_inquiries SET recipient_id = :rid, status = CASE WHEN status IN ('new','open') THEN 'in_review' ELSE status END WHERE id = :id");
                if ($assignTo === null) { $stmt->bindValue(':rid', null, PDO::PARAM_NULL); } else { $stmt->bindValue(':rid', $assignTo, PDO::PARAM_INT); }
                $stmt->bindValue(':id', $cid, PDO::PARAM_INT);
                $stmt->execute();
                if ($assignTo) {
                    $title = 'Client Inquiry Assigned';
                    $msg = 'A client inquiry (ID ' . $cid . ') has been assigned to you.';
                    $db->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, "inquiry")')->execute([$assignTo, $title, $msg]);
                }
            }
            header('Location: manage_inquiries.php'); exit;
        }

    // Auto-assign all unassigned public inquiries fairly to Senior Architects
    if (isset($_POST['auto_assign_unassigned'])) {
      try {
        // Collect SA candidates (schema tolerant)
        [$idCol, $nameExpr, $hasUserType, $hasPosition, $hasRole] = fetchUsersColumns($db);
        $saIds = [];
        if ($idCol) {
          $conds = [];
          if ($hasUserType && $hasPosition) { $conds[] = "(LOWER(user_type)='employee' AND LOWER(REPLACE(REPLACE(position,'_',' '),'-',' ')) LIKE '%senior architect%')"; }
          if ($hasRole) { $conds[] = "(LOWER(REPLACE(REPLACE(role,'_',' '),'-',' ')) LIKE '%senior architect%')"; }
          $whereSA = $conds ? ('WHERE ' . implode(' OR ', $conds)) : 'WHERE 1=0';
          $stmtSA = $db->query("SELECT $idCol AS id FROM users $whereSA");
          foreach ($stmtSA->fetchAll(PDO::FETCH_ASSOC) as $r) { $saIds[] = (int)$r['id']; }
        }
        if ($saIds) {
          // Initialize active counts (new + in_review)
          $cntStmt = $db->prepare("SELECT COUNT(*) FROM public_inquiries WHERE assigned_to = ? AND (status = 'new' OR status = 'in_review')");
          $load = [];
          foreach ($saIds as $sid) { $cntStmt->execute([$sid]); $load[$sid] = (int)$cntStmt->fetchColumn(); }
          // Fetch unassigned NEW inquiries
          $uStmt = $db->query("SELECT id FROM public_inquiries WHERE (assigned_to IS NULL OR assigned_to=0) AND (status IS NULL OR status='new') ORDER BY created_at ASC");
          $pending = $uStmt->fetchAll(PDO::FETCH_COLUMN);
          if ($pending) {
            $upd = $db->prepare("UPDATE public_inquiries SET assigned_to = ?, status = CASE WHEN status IS NULL OR status='new' THEN 'in_review' ELSE status END WHERE id = ?");
            $insN = $db->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, "inquiry")');
            foreach ($pending as $inqId) {
              // pick SA with min current load (stable tie-break by smallest id)
              $pick = null; $min = PHP_INT_MAX;
              foreach ($saIds as $sid) {
                $c = $load[$sid] ?? 0;
                if ($c < $min || ($c === $min && ($pick === null || $sid < $pick))) { $min = $c; $pick = $sid; }
              }
              if ($pick !== null) {
                $upd->execute([$pick, (int)$inqId]);
                $load[$pick] = ($load[$pick] ?? 0) + 1;
                // best-effort notify
                try { $insN->execute([$pick, 'Public Inquiry Assigned', 'An unassigned public inquiry was auto-assigned to you.', 'inquiry']); } catch (Throwable $ne) {}
              }
            }
          }
        }
      } catch (Throwable $ae) { /* ignore and continue */ }
      header('Location: manage_inquiries.php'); exit;
    }
    }

    // Public inquiries stats
    $row = $db->query("SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS new_count,
            SUM(CASE WHEN status = 'contacted' THEN 1 ELSE 0 END) AS contacted_count,
            SUM(CASE WHEN status IN ('in_progress','in review','in_review') THEN 1 ELSE 0 END) AS in_progress_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count
        FROM public_inquiries")->fetch(PDO::FETCH_ASSOC) ?: [];
  $stats = [
        'total' => (int)($row['total'] ?? 0),
        'new_count' => (int)($row['new_count'] ?? 0),
        'contacted_count' => (int)($row['contacted_count'] ?? 0),
        'in_progress_count' => (int)($row['in_progress_count'] ?? 0),
        'completed_count' => (int)($row['completed_count'] ?? 0),
    ];
  // Count unassigned new inquiries for admin action
  $unassignedCount = 0;
  try { $unassignedCount = (int)$db->query("SELECT COUNT(*) FROM public_inquiries WHERE (assigned_to IS NULL OR assigned_to=0) AND (status IS NULL OR status='new')")->fetchColumn(); } catch (Throwable $uc) { $unassignedCount = 0; }

    // Filters & pagination
    $where = [];$vals=[];
    if ($statusFilter !== '') { $where[]='status = ?'; $vals[]=$statusFilter; }
    if ($typeFilter !== '')   { $where[]='inquiry_type = ?'; $vals[]=$typeFilter; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $cst = $db->prepare("SELECT COUNT(*) FROM public_inquiries $whereSql");
    $cst->execute($vals);
    $total = (int)$cst->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

    $q = $db->prepare("SELECT id, name, email, phone, inquiry_type, project_type, budget_range, message, location, status, assigned_to, created_at
                        FROM public_inquiries $whereSql ORDER BY created_at DESC LIMIT :lim OFFSET :off");
    $q->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $q->bindValue(':off', $offset, PDO::PARAM_INT);
    // Bind filters
    $i = 1;
    foreach ($vals as $v) { $q->bindValue($i, $v); $i++; }
    $q->execute();
    $inquiries = $q->fetchAll(PDO::FETCH_ASSOC);

    // Senior Architect options (schema tolerant)
    [$idCol, $nameExpr, $hasUserType, $hasPosition, $hasRole] = fetchUsersColumns($db);
    $saOptions = [];
    if ($idCol) {
        $conds = [];
        if ($hasUserType && $hasPosition) { $conds[] = "(LOWER(user_type)='employee' AND LOWER(position) IN ('senior_architect','senior architect','senior-architect'))"; }
        if ($hasRole) { $conds[] = "(LOWER(role) IN ('senior_architect','senior architect','senior-architect'))"; }
        $whereSA = $conds ? ('WHERE ' . implode(' OR ', $conds)) : 'WHERE 1=0';
        $sqlSA = "SELECT $idCol AS id, $nameExpr AS name FROM users $whereSA ORDER BY name";
        $saStmt = $db->query($sqlSA);
        $saOptions = $saStmt->fetchAll(PDO::FETCH_ASSOC);
    }

  // Client inquiries stats
  $cStats = ['total'=>0,'new_open'=>0,'in_review'=>0,'completed'=>0];
  $rowC = $db->query("SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status IN ('new','open') THEN 1 ELSE 0 END) AS new_open,
        SUM(CASE WHEN status = 'in_review' THEN 1 ELSE 0 END) AS in_review,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
      FROM client_inquiries")->fetch(PDO::FETCH_ASSOC);
  if ($rowC) { $cStats = array_map('intval', $rowC) + $cStats; }

  // Client inquiries filters & pagination
  $cStatusFilter = isset($_GET['cstatus']) ? trim((string)$_GET['cstatus']) : '';
  $cCategoryFilter = isset($_GET['ccat']) ? trim((string)$_GET['ccat']) : '';
  $cPage = max(1, (int)($_GET['cpage'] ?? 1));
  $cPerPage = 12;
  $cOffset = ($cPage - 1) * $cPerPage;
  $cw = [];$cv=[];
  if ($cStatusFilter !== '') { $cw[]='status = ?'; $cv[]=$cStatusFilter; }
  if ($cCategoryFilter !== '') { $cw[]='category = ?'; $cv[]=$cCategoryFilter; }
  $cWhereSql = $cw ? ('WHERE ' . implode(' AND ', $cw)) : '';
  $cs = $db->prepare('SELECT COUNT(*) FROM client_inquiries ' . $cWhereSql);
  $cs->execute($cv);
  $cTotal = (int)$cs->fetchColumn();
  $cTotalPages = max(1, (int)ceil($cTotal / $cPerPage));
  if ($cPage > $cTotalPages) { $cPage = $cTotalPages; $cOffset = ($cPage - 1) * $cPerPage; }
  $csql = 'SELECT id, client_id, project_id, recipient_id, category, subject, message, status, created_at FROM client_inquiries ' . $cWhereSql . ' ORDER BY created_at DESC LIMIT ' . (int)$cPerPage . ' OFFSET ' . (int)$cOffset;
  $cl = $db->prepare($csql);
  $cl->execute($cv);
  $clientInqs = $cl->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $errorMsg = $e->getMessage();
}

include __DIR__ . '/backend/core/header.php';
?>

<main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-50">
  <div class="max-w-full px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex items-center justify-between mb-8">
      <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-slate-900">Manage Public Inquiries</h1>
        <p class="text-slate-500 mt-1">Admin • <?php echo date('l, F j, Y'); ?></p>
      </div>
      <div class="flex items-center gap-2">
        <?php if (($unassignedCount ?? 0) > 0): ?>
          <form method="POST" onsubmit="return confirm('Auto-assign all unassigned NEW inquiries to Senior Architects by current workload?');">
            <button name="auto_assign_unassigned" value="1" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
              <i class="fas fa-random"></i>
              <span>Auto-assign <?php echo (int)$unassignedCount; ?> Unassigned</span>
            </button>
          </form>
        <?php else: ?>
          <span class="text-sm text-slate-500">No unassigned new inquiries</span>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($errorMsg)): ?>
      <div class="mb-6 p-4 rounded-lg ring-1 ring-red-200 bg-red-50 text-red-800">
        <strong>Unable to load inquiries:</strong> <?php echo htmlspecialchars($errorMsg); ?>
      </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
      <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">Total</p><p class="text-3xl font-bold"><?php echo (int)$stats['total']; ?></p></div><span class="p-3 rounded-xl bg-blue-50 text-blue-600"><i class="fas fa-inbox"></i></span></div></div>
      <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">New</p><p class="text-3xl font-bold"><?php echo (int)$stats['new_count']; ?></p></div><span class="p-3 rounded-xl bg-emerald-50 text-emerald-600"><i class="fas fa-plus-circle"></i></span></div></div>
      <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">Contacted</p><p class="text-3xl font-bold"><?php echo (int)$stats['contacted_count']; ?></p></div><span class="p-3 rounded-xl bg-sky-50 text-sky-600"><i class="fas fa-user-check"></i></span></div></div>
      <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">In Progress</p><p class="text-3xl font-bold"><?php echo (int)$stats['in_progress_count']; ?></p></div><span class="p-3 rounded-xl bg-amber-50 text-amber-600"><i class="fas fa-spinner"></i></span></div></div>
      <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">Completed</p><p class="text-3xl font-bold"><?php echo (int)$stats['completed_count']; ?></p></div><span class="p-3 rounded-xl bg-green-50 text-green-600"><i class="fas fa-check-circle"></i></span></div></div>
    </div>

    <!-- Filters -->
    <form method="GET" class="mb-6 grid grid-cols-1 md:grid-cols-4 gap-3">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
        <select name="status" class="w-full rounded-lg border-slate-300">
          <?php $opts=[''=>'All Statuses','new'=>'New','contacted'=>'Contacted','in_progress'=>'In Progress','completed'=>'Completed','cancelled'=>'Cancelled'];
          foreach($opts as $k=>$v){ $sel = ($statusFilter===$k)?'selected':''; echo "<option value=\"".htmlspecialchars($k)."\" $sel>".htmlspecialchars($v)."</option>"; } ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Inquiry Type</label>
        <select name="type" class="w-full rounded-lg border-slate-300">
          <?php $types=[''=>'All Types','new_construction'=>'New Construction','renovation'=>'Renovation','consultation'=>'Consultation','planning'=>'Planning','other'=>'Other'];
          foreach($types as $k=>$v){ $sel = ($typeFilter===$k)?'selected':''; echo "<option value=\"".htmlspecialchars($k)."\" $sel>".htmlspecialchars($v)."</option>"; } ?>
        </select>
      </div>
      <div class="flex items-end gap-2">
        <button class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800" type="submit"><i class="fas fa-filter"></i><span>Apply</span></button>
        <a href="manage_inquiries.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-100 text-slate-800 hover:bg-slate-200"><i class="fas fa-rotate-left"></i><span>Clear</span></a>
      </div>
    </form>

    <!-- List -->
    <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-4 shadow-sm overflow-x-auto">
      <table class="min-w-full divide-y divide-slate-200">
        <thead>
          <tr class="text-left text-xs font-medium uppercase tracking-wider text-slate-500">
            <th class="py-3 px-3">Name</th>
            <th class="py-3 px-3">Contact</th>
            <th class="py-3 px-3">Type</th>
            <th class="py-3 px-3">Status</th>
            <th class="py-3 px-3">Assigned</th>
            <th class="py-3 px-3">Created</th>
            <th class="py-3 px-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
        <?php if (!$inquiries): ?>
          <tr><td colspan="7" class="py-6 text-center text-slate-500">No inquiries found.</td></tr>
        <?php else: foreach ($inquiries as $inq): ?>
          <tr class="hover:bg-slate-50">
            <td class="py-3 px-3">
              <div class="font-medium text-slate-900"><?php echo htmlspecialchars($inq['name'] ?: 'Anonymous'); ?></div>
              <div class="text-xs text-slate-500 break-words max-w-[24ch]">Location: <?php echo htmlspecialchars($inq['location'] ?: '—'); ?></div>
            </td>
            <td class="py-3 px-3 text-sm text-slate-700">
              <div class="break-words max-w-[28ch]">📧 <?php echo htmlspecialchars($inq['email']); ?></div>
              <?php if (!empty($inq['phone'])): ?><div>📞 <?php echo htmlspecialchars($inq['phone']); ?></div><?php endif; ?>
            </td>
            <td class="py-3 px-3 capitalize text-slate-700"><?php echo htmlspecialchars(str_replace('_',' ',$inq['inquiry_type'] ?: '')); ?></td>
            <td class="py-3 px-3">
              <?php $statusClass = [
                'new'=>'bg-emerald-100 text-emerald-800',
                'contacted'=>'bg-sky-100 text-sky-800',
                'in_progress'=>'bg-amber-100 text-amber-800',
                'completed'=>'bg-green-100 text-green-800',
                'cancelled'=>'bg-rose-100 text-rose-800',
                'in_review'=>'bg-indigo-100 text-indigo-800',
              ][strtolower((string)$inq['status'])] ?? 'bg-slate-100 text-slate-800'; ?>
              <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $statusClass; ?>"><?php echo htmlspecialchars(str_replace('_',' ',$inq['status'])); ?></span>
            </td>
            <td class="py-3 px-3 text-sm">
              <form method="POST" class="flex items-center gap-2">
                <input type="hidden" name="inquiry_id" value="<?php echo (int)$inq['id']; ?>">
                <select name="assign_to" class="rounded-lg border-slate-300">
                  <option value="">Unassigned</option>
                  <?php foreach ($saOptions as $sa): $sel = ((int)($inq['assigned_to'] ?? 0) === (int)$sa['id']) ? 'selected' : ''; ?>
                    <option value="<?php echo (int)$sa['id']; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($sa['name'] ?: ('#'.(int)$sa['id'])); ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" name="assign_inquiry" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-slate-900 text-white hover:bg-slate-800 text-xs"><i class="fas fa-user-plus"></i><span>Assign</span></button>
              </form>
            </td>
            <td class="py-3 px-3 text-slate-600 text-sm"><?php echo htmlspecialchars(date('M j, Y', strtotime($inq['created_at']))); ?></td>
            <td class="py-3 px-3 text-right">
              <div class="flex items-center gap-2 justify-end">
                <details>
                  <summary class="cursor-pointer text-slate-700 text-sm underline">View</summary>
                  <div class="mt-2 p-3 rounded-lg ring-1 ring-slate-200 bg-slate-50 max-w-[520px] text-left">
                    <?php if (!empty($inq['project_type'])): ?><div class="text-xs text-slate-600"><strong>Project Type:</strong> <?php echo htmlspecialchars(str_replace('_',' ',$inq['project_type'])); ?></div><?php endif; ?>
                    <?php if (!empty($inq['budget_range'])): ?><div class="text-xs text-slate-600"><strong>Budget:</strong> <?php echo htmlspecialchars(str_replace('_',' ',$inq['budget_range'])); ?></div><?php endif; ?>
                    <div class="text-sm mt-2"><strong>Message</strong></div>
                    <div class="text-sm whitespace-pre-wrap break-words"><?php echo htmlspecialchars((string)$inq['message']); ?></div>
                  </div>
                </details>
                <form method="POST" class="inline-flex items-center gap-2">
                  <input type="hidden" name="inquiry_id" value="<?php echo (int)$inq['id']; ?>">
                  <select name="status" class="rounded-lg border-slate-300 text-sm">
                    <?php $stOpts=['new'=>'New','in_review'=>'In Review','contacted'=>'Contacted','in_progress'=>'In Progress','completed'=>'Completed','cancelled'=>'Cancelled'];
                    foreach($stOpts as $k=>$v){ $sel = (strtolower((string)$inq['status'])===strtolower($k))?'selected':''; echo "<option value=\"".htmlspecialchars($k)."\" $sel>".htmlspecialchars($v)."</option>"; } ?>
                  </select>
                  <button type="submit" name="update_status" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-sky-600 text-white hover:bg-sky-700 text-xs"><i class="fas fa-sync"></i><span>Update</span></button>
                </form>
                <form method="POST" onsubmit="return confirm('Delete this inquiry?');" class="inline">
                  <input type="hidden" name="inquiry_id" value="<?php echo (int)$inq['id']; ?>">
                  <button type="submit" name="delete_inquiry" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-rose-600 text-white hover:bg-rose-700 text-xs"><i class="fas fa-trash"></i><span>Delete</span></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <div class="mt-6 flex justify-center items-center gap-2">
        <?php if ($page > 1): $q = http_build_query(['page'=>$page-1,'status'=>$statusFilter,'type'=>$typeFilter]); ?>
          <a class="px-3 py-1.5 rounded-lg ring-1 ring-slate-200 bg-white hover:bg-slate-50" href="?<?php echo $q; ?>">← Prev</a>
        <?php endif; ?>
        <span class="text-sm text-slate-600">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
        <?php if ($page < $totalPages): $q = http_build_query(['page'=>$page+1,'status'=>$statusFilter,'type'=>$typeFilter]); ?>
          <a class="px-3 py-1.5 rounded-lg ring-1 ring-slate-200 bg-white hover:bg-slate-50" href="?<?php echo $q; ?>">Next →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Client Inquiries Section -->
    <div class="mt-12">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h2 class="text-xl sm:text-2xl font-semibold text-slate-900">Client Inquiries</h2>
          <p class="text-slate-500 mt-1">Private messages and project requests from logged-in clients.</p>
        </div>
      </div>

      <!-- Client Stats -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">Total</p><p class="text-3xl font-bold"><?php echo (int)$cStats['total']; ?></p></div><span class="p-3 rounded-xl bg-blue-50 text-blue-600"><i class="fas fa-inbox"></i></span></div></div>
        <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">New/Open</p><p class="text-3xl font-bold"><?php echo (int)$cStats['new_open']; ?></p></div><span class="p-3 rounded-xl bg-emerald-50 text-emerald-600"><i class="fas fa-plus-circle"></i></span></div></div>
        <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">In Review</p><p class="text-3xl font-bold"><?php echo (int)$cStats['in_review']; ?></p></div><span class="p-3 rounded-xl bg-indigo-50 text-indigo-600"><i class="fas fa-search"></i></span></div></div>
        <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><div><p class="text-slate-500">Completed</p><p class="text-3xl font-bold"><?php echo (int)$cStats['completed']; ?></p></div><span class="p-3 rounded-xl bg-green-50 text-green-600"><i class="fas fa-check-circle"></i></span></div></div>
      </div>

      <!-- Client Filters -->
      <form method="GET" class="mb-6 grid grid-cols-1 md:grid-cols-4 gap-3">
        <input type="hidden" name="page" value="<?php echo (int)$page; ?>">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
        <input type="hidden" name="type" value="<?php echo htmlspecialchars($typeFilter); ?>">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
          <select name="cstatus" class="w-full rounded-lg border-slate-300">
            <?php $cOpts=[''=>'All Statuses','new'=>'New','open'=>'Open','in_review'=>'In Review','completed'=>'Completed'];
            foreach($cOpts as $k=>$v){ $sel = ($cStatusFilter===$k)?'selected':''; echo "<option value=\"".htmlspecialchars($k)."\" $sel>".htmlspecialchars($v)."</option>"; } ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Category</label>
          <select name="ccat" class="w-full rounded-lg border-slate-300">
            <?php $cats=[''=>'All Categories','general'=>'General','project_request'=>'Project Request','billing'=>'Billing','support'=>'Support'];
            foreach($cats as $k=>$v){ $sel = ($cCategoryFilter===$k)?'selected':''; echo "<option value=\"".htmlspecialchars($k)."\" $sel>".htmlspecialchars($v)."</option>"; } ?>
          </select>
        </div>
        <div class="flex items-end gap-2">
          <button class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800" type="submit"><i class="fas fa-filter"></i><span>Apply</span></button>
          <a href="manage_inquiries.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-100 text-slate-800 hover:bg-slate-200"><i class="fas fa-rotate-left"></i><span>Clear</span></a>
        </div>
      </form>

      <!-- Client List -->
      <div class="rounded-2xl ring-1 ring-slate-200 bg-white p-4 shadow-sm overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
          <thead>
            <tr class="text-left text-xs font-medium uppercase tracking-wider text-slate-500">
              <th class="py-3 px-3">Subject</th>
              <th class="py-3 px-3">Category</th>
              <th class="py-3 px-3">Status</th>
              <th class="py-3 px-3">Recipient</th>
              <th class="py-3 px-3">Created</th>
              <th class="py-3 px-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
          <?php if (!$clientInqs): ?>
            <tr><td colspan="6" class="py-6 text-center text-slate-500">No client inquiries found.</td></tr>
          <?php else: foreach ($clientInqs as $ci): ?>
            <tr class="hover:bg-slate-50">
              <td class="py-3 px-3">
                <div class="font-medium text-slate-900 break-words max-w-[50ch]"><?php echo htmlspecialchars($ci['subject'] ?: '(No subject)'); ?></div>
                <div class="text-xs text-slate-500 mt-1 break-words max-w-[60ch]"><?php echo nl2br(htmlspecialchars((string)$ci['message'])); ?></div>
              </td>
              <td class="py-3 px-3 text-sm text-slate-700 capitalize"><?php echo htmlspecialchars(str_replace('_',' ', (string)$ci['category'])); ?></td>
              <td class="py-3 px-3">
                <?php $cStatusClass = [
                  'new'=>'bg-emerald-100 text-emerald-800',
                  'open'=>'bg-blue-100 text-blue-800',
                  'in_review'=>'bg-indigo-100 text-indigo-800',
                  'completed'=>'bg-green-100 text-green-800',
                ][strtolower((string)$ci['status'])] ?? 'bg-slate-100 text-slate-800'; ?>
                <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $cStatusClass; ?>"><?php echo htmlspecialchars(str_replace('_',' ', (string)$ci['status'])); ?></span>
              </td>
              <td class="py-3 px-3 text-sm">
                <form method="POST" class="flex items-center gap-2">
                  <input type="hidden" name="c_inquiry_id" value="<?php echo (int)$ci['id']; ?>">
                  <select name="c_assign_to" class="rounded-lg border-slate-300">
                    <option value="">Unassigned</option>
                    <?php foreach ($saOptions as $sa): $sel = ((int)($ci['recipient_id'] ?? 0) === (int)$sa['id']) ? 'selected' : ''; ?>
                      <option value="<?php echo (int)$sa['id']; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($sa['name'] ?: ('#'.(int)$sa['id'])); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" name="c_assign_inquiry" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-slate-900 text-white hover:bg-slate-800 text-xs"><i class="fas fa-user-plus"></i><span>Assign</span></button>
                </form>
              </td>
              <td class="py-3 px-3 text-slate-600 text-sm"><?php echo htmlspecialchars(date('M j, Y', strtotime($ci['created_at']))); ?></td>
              <td class="py-3 px-3 text-right">
                <div class="flex items-center gap-2 justify-end">
                  <form method="POST" class="inline-flex items-center gap-2">
                    <input type="hidden" name="c_inquiry_id" value="<?php echo (int)$ci['id']; ?>">
                    <select name="c_status" class="rounded-lg border-slate-300 text-sm">
                      <?php $cStOpts=['new'=>'New','open'=>'Open','in_review'=>'In Review','completed'=>'Completed'];
                      foreach($cStOpts as $k=>$v){ $sel = (strtolower((string)$ci['status'])===strtolower($k))?'selected':''; echo "<option value=\"".htmlspecialchars($k)."\" $sel>".htmlspecialchars($v)."</option>"; } ?>
                    </select>
                    <button type="submit" name="c_update_status" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-sky-600 text-white hover:bg-sky-700 text-xs"><i class="fas fa-sync"></i><span>Update</span></button>
                  </form>
                  <form method="POST" onsubmit="return confirm('Delete this client inquiry?');" class="inline">
                    <input type="hidden" name="c_inquiry_id" value="<?php echo (int)$ci['id']; ?>">
                    <button type="submit" name="c_delete_inquiry" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-rose-600 text-white hover:bg-rose-700 text-xs"><i class="fas fa-trash"></i><span>Delete</span></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Client Pagination -->
      <?php if ($cTotalPages > 1): ?>
        <div class="mt-6 flex justify-center items-center gap-2">
          <?php if ($cPage > 1): $q = http_build_query(['cpage'=>$cPage-1,'cstatus'=>$cStatusFilter,'ccat'=>$cCategoryFilter,'page'=>$page,'status'=>$statusFilter,'type'=>$typeFilter]); ?>
            <a class="px-3 py-1.5 rounded-lg ring-1 ring-slate-200 bg-white hover:bg-slate-50" href="?<?php echo $q; ?>">← Prev</a>
          <?php endif; ?>
          <span class="text-sm text-slate-600">Page <?php echo $cPage; ?> of <?php echo $cTotalPages; ?></span>
          <?php if ($cPage < $cTotalPages): $q = http_build_query(['cpage'=>$cPage+1,'cstatus'=>$cStatusFilter,'ccat'=>$cCategoryFilter,'page'=>$page,'status'=>$statusFilter,'type'=>$typeFilter]); ?>
            <a class="px-3 py-1.5 rounded-lg ring-1 ring-slate-200 bg-white hover:bg-slate-50" href="?<?php echo $q; ?>">Next →</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>
</main>

<?php include __DIR__ . '/backend/core/footer.php'; ?>

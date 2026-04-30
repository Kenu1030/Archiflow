<?php
// Use centralized auth guard with proper role names
$allowed_roles = ['project_manager', 'senior_architect', 'admin'];
include __DIR__ . '/includes/auth_check.php';
include 'db.php';

// Enable output buffering so redirects work even if some output occurs later
if (!headers_sent()) { ob_start(); }

// Determine role & defer disabling until project status is known
$position_norm = strtolower(str_replace(' ', '_', (string)($_SESSION['position'] ?? '')));
$is_pm = ($position_norm === 'project_manager');
$pm_assignment_disabled = false; // will be set true only if project completed / archived / deleted
$assignment_block_reason = '';

// Helper: check if a column exists
function column_exists($conn, $table, $column) {
    // MariaDB/MySQL don't support preparing SHOW COLUMNS reliably; use direct query
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $column = $conn->real_escape_string((string)$column);
    $sql = "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'";
    if ($res = $conn->query($sql)) {
        $exists = ($res->num_rows > 0);
        $res->free();
        return $exists;
    }
    return false;
}

// Helper: pick the first existing column from candidate list
function first_existing_column($conn, $table, $candidates, $default = null) {
    foreach ($candidates as $c) {
        if (column_exists($conn, $table, $c)) { return $c; }
    }
    return $default ?? $candidates[0] ?? null;
}

// Ensure tasks.phase column exists for 4 design phases
if (!column_exists($conn, 'tasks', 'phase')) {
    $conn->query("ALTER TABLE tasks ADD COLUMN phase ENUM('Pre-Design / Programming','Schematic Design (SD)','Design Development (DD)','Final Design') NULL AFTER status");
}

// Detect project PK column
$projectIdCol = column_exists($conn, 'projects', 'id') ? 'id' : 'project_id';

// Early determine selected project (GET has priority like later logic)
$selected_project_for_flag = (int)($_GET['project_id'] ?? ($_POST['project_id'] ?? 0));
if ($selected_project_for_flag > 0) {
    // Build status query including archive/delete flags if present
    $cols = [];
    $cols[] = 'status';
    $has_arch = column_exists($conn, 'projects', 'is_archived');
    $has_del  = column_exists($conn, 'projects', 'is_deleted');
    if ($has_arch) { $cols[] = 'is_archived'; }
    if ($has_del)  { $cols[] = 'is_deleted'; }
    $colList = implode(',', $cols);
    $sqlP = "SELECT $colList FROM projects WHERE $projectIdCol = ? LIMIT 1";
    if ($stmtP = $conn->prepare($sqlP)) {
        $stmtP->bind_param('i', $selected_project_for_flag);
        if ($stmtP->execute()) {
            $resP = $stmtP->get_result();
            if ($resP && ($prow = $resP->fetch_assoc())) {
                $pStatus = strtolower((string)($prow['status'] ?? ''));
                $archived = $has_arch ? (int)$prow['is_archived'] === 1 : false;
                $deleted  = $has_del  ? ((int)$prow['is_deleted'] === 1) : false;
                if ($archived) {
                    $pm_assignment_disabled = true; $assignment_block_reason = 'Project is archived; task assignment disabled.';
                } elseif ($deleted) {
                    $pm_assignment_disabled = true; $assignment_block_reason = 'Project is deleted; task assignment disabled.';
                } elseif ($is_pm && $pStatus === 'completed') {
                    $pm_assignment_disabled = true; $assignment_block_reason = 'Completed project; task assignment disabled for Project Managers.';
                }
            }
        }
        $stmtP->close();
    }
}

// Phases list
$PHASES = [
    'Pre-Design / Programming',
    'Schematic Design (SD)',
    'Design Development (DD)',
    'Final Design',
];

// Detect tasks PK column name (id vs task_id)
$tasksPkCol = column_exists($conn, 'tasks', 'id') ? 'id' : (column_exists($conn, 'tasks', 'task_id') ? 'task_id' : 'id');
// Detect tasks title column
$tasksTitleCol = first_existing_column($conn, 'tasks', ['title','task_title','name','task_name'], 'title');
// Detect due date column
$tasksDueCol = first_existing_column($conn, 'tasks', ['due_date','deadline','due','task_due','dueAt'], 'due_date');
// Detect description column
$tasksDescCol = first_existing_column($conn, 'tasks', ['description','task_description','details','notes'], 'description');
$hasDescCol = column_exists($conn, 'tasks', $tasksDescCol);
// Ensure tasks.created_by column exists (stores users.user_id of assigner)
if (!column_exists($conn, 'tasks', 'created_by')) {
    $conn->query("ALTER TABLE tasks ADD COLUMN created_by INT NULL AFTER phase");
}
// Current user id
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
// Handle create task POST at the top before any output
// Quick add architect to project (regular architect only, not senior) if none exist yet
if (!headers_sent() && isset($_POST['quick_add_architect'])) {
    $quick_project_id = (int)($_POST['quick_project_id'] ?? 0);
    $quick_user_id    = (int)($_POST['quick_user_id'] ?? 0);
    if ($quick_project_id > 0 && $quick_user_id > 0) {
        // Validate user is an architect (not senior)
        $qU = $conn->prepare("SELECT position, user_type FROM users WHERE user_id=? LIMIT 1");
        $qU->bind_param('i', $quick_user_id);
        $qU->execute();
        $qRes = $qU->get_result();
        $uRow = $qRes ? $qRes->fetch_assoc() : null;
        $qU->close();
        $okInsert = false; $qa_msg='';
        if ($uRow && ($uRow['user_type'] === 'employee' || $uRow['user_type'] === null)) {
            $posL = strtolower($uRow['position'] ?? '');
            if (strpos($posL,'architect') !== false && strpos($posL,'senior') === false) {
                // Check if already assigned
                $dup = $conn->prepare("SELECT id FROM project_users WHERE project_id=? AND user_id=? LIMIT 1");
                $dup->bind_param('ii', $quick_project_id, $quick_user_id);
                $dup->execute();
                $dup->store_result();
                if ($dup->num_rows === 0) {
                    $dup->close();
                    $ins = $conn->prepare("INSERT INTO project_users (project_id, user_id, role_in_project) VALUES (?,?, 'Architect')");
                    $ins->bind_param('ii', $quick_project_id, $quick_user_id);
                    $okInsert = $ins->execute();
                    $ins->close();
                } else { $dup->close(); $qa_msg='already'; }
            } else { $qa_msg='not_regular_architect'; }
        } else { $qa_msg='invalid_user'; }
        // Redirect to clear POST and refresh architect list
        header('Location: assign_tasks.php?project_id=' . $quick_project_id . '&qa=' . ($okInsert ? '1':'0') . ($qa_msg?('&qa_reason='.$qa_msg):''));
        exit;
    }
}
// Feedback variables
$assign_error = '';
$assign_success = '';
// Debug: log POST data if form is submitted
if (isset($_POST['create_task']) && !$pm_assignment_disabled) {
    error_log('Assign Task POST: ' . print_r($_POST, true));
    echo '<div style="background: #ffe0e0; color: #900; padding: 8px; margin-bottom: 8px; border: 1px solid #f00;">';
    echo '<strong>DEBUG:</strong> Status received from form: <b>' . htmlspecialchars($_POST['status'] ?? '') . '</b><br>';
    echo 'Full POST: <pre>' . htmlspecialchars(print_r($_POST, true)) . '</pre>';
    echo '</div>';
}
if (isset($_POST['create_task']) && !$pm_assignment_disabled) {
    $selected_project = isset($_GET['project_id']) ? (int)$_GET['project_id'] : (isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0);
    $project_id = ($selected_project > 0) ? $selected_project : intval($_POST['project_id'] ?? 0);
    $title = trim($_POST['task_title'] ?? '');
    $desc = trim($_POST['task_desc'] ?? '');
    $due = $_POST['task_due'] ?? null;
    $phase = $_POST['phase'] ?? '';
    $status = $_POST['status'] ?? 'To Do';
    $assignedRaw = $_POST['assigned_to'] ?? null;
    if (is_string($assignedRaw) && strlen($assignedRaw) > 1 && $assignedRaw[0] === 'U') {
        $userIdMap = (int)substr($assignedRaw,1);
        if ($userIdMap > 0) {
            // Ensure employee row exists
            if (!function_exists('ensure_employee_row_for_user')) {
                function ensure_employee_row_for_user($conn, $userId, $basePosition='architect') {
                    $userId = (int)$userId; if ($userId<=0) return false;
                    $chk = $conn->prepare("SELECT employee_id FROM employees WHERE user_id=? LIMIT 1");
                    $chk->bind_param('i',$userId);$chk->execute();$res=$chk->get_result();$row=$res?$res->fetch_assoc():null;$chk->close();
                    if ($row && isset($row['employee_id'])) return true;
                    $uQ=$conn->prepare("SELECT position FROM users WHERE user_id=? LIMIT 1");$uQ->bind_param('i',$userId);$uQ->execute();$uR=$uQ->get_result();$uRow=$uR?$uR->fetch_assoc():null;$uQ->close();
                    $positionEnum='architect';$posLower=strtolower($uRow['position']??'');
                    if (strpos($posLower,'senior')!==false && strpos($posLower,'architect')!==false) { $positionEnum='senior_architect'; }
                    elseif (strpos($posLower,'project')!==false && strpos($posLower,'manager')!==false) { $positionEnum='project_manager'; }
                    $code='AUTO'.str_pad($userId,5,'0',STR_PAD_LEFT);
                    $dept='Architecture';
                    $ins=$conn->prepare("INSERT INTO employees (user_id, employee_code, position, department, hire_date, salary, status) VALUES (?,?,?,?,CURDATE(),0,'active')");
                    $ins->bind_param('isss',$userId,$code,$positionEnum,$dept);$ins->execute();$ins->close();
                    return true;
                }
            }
            ensure_employee_row_for_user($conn,$userIdMap);
            // Look up new employee_id
            $eidStmt=$conn->prepare("SELECT employee_id FROM employees WHERE user_id=? LIMIT 1");
            $eidStmt->bind_param('i',$userIdMap);$eidStmt->execute();$eidRes=$eidStmt->get_result();$eidRow=$eidRes?$eidRes->fetch_assoc():null;$eidStmt->close();
            $assigned_to = $eidRow ? (int)$eidRow['employee_id'] : null;
        } else { $assigned_to=null; }
    } else {
        $assigned_to = isset($assignedRaw) ? (int)$assignedRaw : null; // employees.employee_id
    }
    $assigned_ok = true;
    if ($selected_project > 0 && $assigned_to) {
        $mStmt = $conn->prepare("SELECT user_id FROM employees WHERE employee_id=? LIMIT 1");
        $mStmt->bind_param('i', $assigned_to);
        $mStmt->execute();
        $mRes = $mStmt->get_result();
        $mRow = $mRes ? $mRes->fetch_assoc() : null;
        $mStmt->close();
        $assigned_user_id = $mRow ? (int)$mRow['user_id'] : 0;
        $chkStmt = $conn->prepare("SELECT COUNT(*) c FROM project_users WHERE project_id=? AND user_id=? AND LOWER(role_in_project) LIKE '%architect%'");
        $chkStmt->bind_param('ii', $selected_project, $assigned_user_id);
        $chkStmt->execute();
        $chkRes = $chkStmt->get_result();
        $rowC = $chkRes ? $chkRes->fetch_assoc() : ['c'=>0];
        $chkStmt->close();
        $assigned_ok = ((int)($rowC['c'] ?? 0) > 0);
        // Block senior architect assignment for tasks
        if ($assigned_ok && $assigned_user_id) {
            $pChk = $conn->prepare("SELECT position FROM users WHERE user_id=? LIMIT 1");
            $pChk->bind_param('i', $assigned_user_id);
            $pChk->execute();
            $pRes = $pChk->get_result();
            $posRow = $pRes ? $pRes->fetch_assoc() : null;
            $pChk->close();
            $posLower = strtolower($posRow['position'] ?? '');
            if (strpos($posLower,'senior') !== false && strpos($posLower,'architect') !== false) {
                $assigned_ok = false;
                $assign_error = 'Cannot assign tasks directly to Senior Architects.';
            }
        }
    }
    if ($title && $project_id && $phase && !empty($assigned_to) && $assigned_ok) {
        $sqlIns = "INSERT INTO tasks (project_id, {$tasksTitleCol}, description, assigned_to, {$tasksDueCol}, status, phase, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sqlIns);
        $stmt->bind_param("ississsi", $project_id, $title, $desc, $assigned_to, $due, $status, $phase, $current_user_id);
        $execResult = $stmt->execute();
        echo '<div style="background: #e0ffe0; color: #090; padding: 8px; margin-bottom: 8px; border: 1px solid #0f0;">';
        echo '<strong>DEBUG:</strong> SQL executed: ' . ($execResult ? 'SUCCESS' : 'FAIL') . '<br>';
        echo 'Status sent to DB: <b>' . htmlspecialchars($status) . '</b><br>';
        if (!$execResult) {
            echo 'SQL Error: ' . htmlspecialchars($stmt->error) . '<br>';
        } else {
            // Fetch last inserted row's status value
            $last_id = $conn->insert_id;
            $q = $conn->prepare("SELECT status FROM tasks WHERE {$tasksPkCol}=? LIMIT 1");
            $q->bind_param('i', $last_id);
            $q->execute();
            $res = $q->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $q->close();
            echo 'Last inserted row status: <b>' . htmlspecialchars($row['status'] ?? 'N/A') . '</b><br>';
        }
        echo '</div>';
        $stmt->close();
        if ($execResult) {
            $assign_success = 'Task assigned!';
            $redir = 'assign_tasks.php?project_id=' . (int)$project_id . '&assigned=1';
            header('Location: ' . $redir);
            exit;
        }
    } else {
        // If PM tried to submit, give clear feedback (even though button will be hidden)
        if (isset($_POST['create_task']) && $pm_assignment_disabled && !$assign_error) {
            $assign_error = $assignment_block_reason ?: 'Task assignment disabled.';
        }
        $assign_error = 'Validation failed. Debug info: ' .
            'title=' . var_export($title, true) . ', project_id=' . var_export($project_id, true) . ', phase=' . var_export($phase, true) . ', assigned_to=' . var_export($assigned_to, true) . ', assigned_ok=' . var_export($assigned_ok, true) . ', status=' . var_export($status, true);
        error_log('Assign Task ERROR: ' . $assign_error);
    }
}
?>
<?php include __DIR__ . '/backend/core/header.php'; ?>

<main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-50">
    <?php if (!empty($assign_error)): ?>
        <div class="mb-4 p-3 rounded-lg ring-1 ring-red-200 bg-red-50 text-red-800"><?php echo $assign_error; ?></div>
    <?php endif; ?>
    <?php if (!empty($assign_success)): ?>
        <div class="mb-4 p-3 rounded-lg ring-1 ring-green-200 bg-green-50 text-green-800"><?php echo $assign_success; ?></div>
    <?php endif; ?>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <section class="rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-xl font-semibold text-slate-900 flex items-center gap-3">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-blue-50 text-blue-600"><i class="fas fa-tasks"></i></span>
                                        <?php echo $pm_assignment_disabled ? 'Project Tasks' : 'Assign Tasks'; ?>
                </h2>
            </div>
                        <?php if ($pm_assignment_disabled && $assignment_block_reason): ?>
                            <div class="mb-4 p-3 rounded-lg bg-amber-50 ring-1 ring-amber-200 text-amber-800 text-sm">
                                <?php echo htmlspecialchars($assignment_block_reason); ?>
                            </div>
                        <?php endif; ?>
            <?php
                $selected_project = isset($_GET['project_id']) ? (int)$_GET['project_id'] : (isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0);
                // Fetch selected project name for display
                $selected_project_name = '';
                if ($selected_project > 0) {
                    $stmtPN = $conn->prepare("SELECT * FROM projects WHERE $projectIdCol = ? LIMIT 1");
                    $stmtPN->bind_param('i', $selected_project);
                    if ($stmtPN->execute()) {
                        $rpn = $stmtPN->get_result();
                        if ($rpn && ($rowpn = $rpn->fetch_assoc())) {
                              $selected_project_name = (string)($rowpn['project_name'] ?? ($rowpn['name'] ?? ('Project #'.$selected_project)));
                        }
                    }
                    $stmtPN->close();
                }
            ?>
                <form method="post">
        <?php
        // Handle task updates (Edit)
    if ($selected_project > 0 && isset($_POST['update_task']) && !$pm_assignment_disabled) {
            $task_id = (int)($_POST['task_id'] ?? 0);
            $title_u = trim($_POST['task_title'] ?? '');
            $desc_u = trim($_POST['task_desc'] ?? '');
            $due_u = $_POST['task_due'] ?? null;
            $phase_u = $_POST['phase'] ?? '';
            $status_u = $_POST['status'] ?? 'To Do';
            $assigned_to_u = isset($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;

            // Verify task belongs to selected project
            $chk = $conn->prepare("SELECT project_id FROM tasks WHERE {$tasksPkCol} = ? LIMIT 1");
            $chk->bind_param('i', $task_id);
            $chk->execute();
            $r = $chk->get_result();
            $rowt = $r ? $r->fetch_assoc() : null;
            $chk->close();
            if (!$rowt || (int)$rowt['project_id'] !== (int)$selected_project) {
                echo '<div class="mb-4 p-3 rounded-lg ring-1 ring-red-200 bg-red-50 text-red-800">Invalid task or project mismatch.</div>';
            } else {
                // Validate assigned_to is assigned to this project as architect
                $assigned_ok2 = true;
                if ($assigned_to_u) {
                    $mStmt2 = $conn->prepare("SELECT user_id FROM employees WHERE employee_id=? LIMIT 1");
                    $mStmt2->bind_param('i', $assigned_to_u);
                    $mStmt2->execute();
                    $mRes2 = $mStmt2->get_result();
                    $mRow2 = $mRes2 ? $mRes2->fetch_assoc() : null;
                    $mStmt2->close();
                    $assigned_user_id2 = $mRow2 ? (int)$mRow2['user_id'] : 0;

                    $chk2 = $conn->prepare("SELECT COUNT(*) c FROM project_users WHERE project_id=? AND user_id=? AND LOWER(role_in_project) LIKE '%architect%'");
                    $chk2->bind_param('ii', $selected_project, $assigned_user_id2);
                    $chk2->execute();
                    $cr2 = $chk2->get_result();
                    $rc2 = $cr2 ? $cr2->fetch_assoc() : ['c'=>0];
                    $chk2->close();
                    $assigned_ok2 = ((int)($rc2['c'] ?? 0) > 0);
                }
                if ($title_u && $phase_u && in_array($phase_u, $PHASES, true) && !empty($assigned_to_u) && $assigned_ok2) {
                    if ($hasDescCol) {
                        $sqlUp = "UPDATE tasks SET {$tasksTitleCol}=?, {$tasksDescCol}=?, assigned_to=?, {$tasksDueCol}=?, status=?, phase=? WHERE {$tasksPkCol}=? AND project_id=?";
                        $stUp = $conn->prepare($sqlUp);
                        $stUp->bind_param('ssisssii', $title_u, $desc_u, $assigned_to_u, $due_u, $status_u, $phase_u, $task_id, $selected_project);
                    } else {
                        $sqlUp = "UPDATE tasks SET {$tasksTitleCol}=?, assigned_to=?, {$tasksDueCol}=?, status=?, phase=? WHERE {$tasksPkCol}=? AND project_id=?";
                        $stUp = $conn->prepare($sqlUp);
                        $stUp->bind_param('sisssii', $title_u, $assigned_to_u, $due_u, $status_u, $phase_u, $task_id, $selected_project);
                    }
                    $execResultUp = $stUp->execute();
                    echo '<div style="background: #e0ffe0; color: #090; padding: 8px; margin-bottom: 8px; border: 1px solid #0f0;">';
                    echo '<strong>DEBUG:</strong> SQL UPDATE executed: ' . ($execResultUp ? 'SUCCESS' : 'FAIL') . '<br>';
                    echo 'Status sent to DB (update): <b>' . htmlspecialchars($status_u) . '</b><br>';
                    if (!$execResultUp) {
                        echo 'SQL Error: ' . htmlspecialchars($stUp->error) . '<br>';
                    }
                    echo '</div>';
                    $stUp->close();
                    if ($execResultUp) {
                        header('Location: assign_tasks.php?project_id='.(int)$selected_project.'&updated=1');
                        exit;
                    }
                } else {
                    if (!$assigned_ok2) {
                        echo '<div class="mb-4 p-3 rounded-lg ring-1 ring-red-200 bg-red-50 text-red-800">Selected architect is not assigned to this project.</div>';
                    } else {
                        echo '<div class="mb-4 p-3 rounded-lg ring-1 ring-red-200 bg-red-50 text-red-800">Please complete all fields for update.</div>';
                    }
                }
            }
        }

        // Handle delete
    if ($selected_project > 0 && isset($_POST['delete_task']) && isset($_POST['delete_task_id']) && !$pm_assignment_disabled) {
            $task_id_del = (int)$_POST['delete_task_id'];
            $del = $conn->prepare("DELETE FROM tasks WHERE {$tasksPkCol}=? AND project_id=?");
            $del->bind_param('ii', $task_id_del, $selected_project);
            $del->execute();
            $del->close();
            header('Location: assign_tasks.php?project_id='.(int)$selected_project.'&deleted=1');
            exit;
        }

        // Preload task for view/edit if requested
        $edit_task = null; $view_task = null;
        if ($selected_project > 0 && isset($_GET['edit'])) {
            $edit_id = (int)$_GET['edit'];
            $q = "SELECT t.{$tasksPkCol} AS id, t.project_id, t.{$tasksTitleCol} AS title, ".($hasDescCol?"t.{$tasksDescCol} AS description, ":"' ' AS description, ")."t.status, t.{$tasksDueCol} AS due_date, t.phase, t.assigned_to,
                         u.first_name, u.last_name
                  FROM tasks t
                  LEFT JOIN employees e ON e.employee_id = t.assigned_to
                  LEFT JOIN users u ON u.user_id = e.user_id
                  WHERE t.{$tasksPkCol}=? AND t.project_id=? LIMIT 1";
            $st = $conn->prepare($q);
            $st->bind_param('ii', $edit_id, $selected_project);
            $st->execute();
            $edit_task = $st->get_result()->fetch_assoc();
            $st->close();
        }
        if ($selected_project > 0 && isset($_GET['view'])) {
            $view_id = (int)$_GET['view'];
            $qv = "SELECT t.{$tasksPkCol} AS id, t.project_id, t.{$tasksTitleCol} AS title, ".($hasDescCol?"t.{$tasksDescCol} AS description, ":"' ' AS description, ")."t.status, t.{$tasksDueCol} AS due_date, t.phase, t.assigned_to,
                          CONCAT(uu.first_name,' ',uu.last_name) AS assignee_name,
                          CONCAT(au.first_name,' ',au.last_name) AS assigner_name
                   FROM tasks t
                   LEFT JOIN employees e ON e.employee_id = t.assigned_to
                   LEFT JOIN users uu ON uu.user_id = e.user_id
                   LEFT JOIN users au ON au.user_id = t.created_by
                   WHERE t.{$tasksPkCol}=? AND t.project_id=? LIMIT 1";
            $stv = $conn->prepare($qv);
            $stv->bind_param('ii', $view_id, $selected_project);
            $stv->execute();
            $view_task = $stv->get_result()->fetch_assoc();
            $stv->close();
        }
        ?>

        <?php if (isset($_GET['updated']) && $_GET['updated'] == '1'): ?>
            <div class="mb-4 p-3 rounded-lg ring-1 ring-green-200 bg-green-50 text-green-800">Task updated.</div>
        <?php endif; ?>
        <?php if (isset($_GET['deleted']) && $_GET['deleted'] == '1'): ?>
            <div class="mb-4 p-3 rounded-lg ring-1 ring-green-200 bg-green-50 text-green-800">Task deleted.</div>
        <?php endif; ?>

    <?php if ($edit_task && !$pm_assignment_disabled): ?>
            <h3 class="text-lg font-semibold text-slate-900 mt-6">Edit Task</h3>
            <form method="post" class="mb-6">
                <input type="hidden" name="task_id" value="<?php echo (int)$edit_task['id']; ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Assign to Architect</label>
                        <select name="assigned_to" required class="w-full px-3 py-2 border border-slate-200 rounded-lg">
                            <option value="">Select Architect</option>
                            <?php
                            $stmtA2 = $conn->prepare(
                                "SELECT u.user_id, u.first_name, u.last_name, u.position, e.employee_id
                                 FROM project_users pu
                                 JOIN users u ON u.user_id = pu.user_id AND u.user_type='employee' AND LOWER(u.position) LIKE '%architect%' AND LOWER(u.position) NOT LIKE '%senior%'
                                 LEFT JOIN employees e ON e.user_id = u.user_id
                                 WHERE pu.project_id = ? AND LOWER(pu.role_in_project) LIKE '%architect%' AND LOWER(pu.role_in_project) NOT LIKE '%senior%'
                                 ORDER BY u.first_name, u.last_name"
                            );
                            $stmtA2->bind_param('i', $selected_project);
                            $stmtA2->execute();
                            $archs2 = $stmtA2->get_result();
                            while ($archs2 && ($a2 = $archs2->fetch_assoc())):
                                $labelExtra2 = '';
                                $posl2 = strtolower($a2['position'] ?? '');
                                if (strpos($posl2,'senior')!==false && strpos($posl2,'architect')!==false) { $labelExtra2 = ' — Senior Architect'; }
                                elseif (strpos($posl2,'architect')!==false) { $labelExtra2 = ' — Architect'; }
                                $empIdVal2 = isset($a2['employee_id']) ? (int)$a2['employee_id'] : 0;
                                if ($empIdVal2 <= 0) { continue; }
                            ?>
                            <option value="<?php echo $empIdVal2; ?>" <?php if((int)$edit_task['assigned_to'] === $empIdVal2) echo 'selected'; ?>>
                                <?php echo htmlspecialchars(($a2['first_name']??'').' '.($a2['last_name']??'').$labelExtra2); ?>
                            </option>
                            <?php endwhile; $stmtA2->close(); ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                        <select name="status" required class="w-full px-3 py-2 border border-slate-200 rounded-lg">
                            <?php $statuses = ['To Do','In Progress','Done']; foreach ($statuses as $st): ?>
                                <option value="<?php echo $st; ?>" <?php if(($edit_task['status'] ?? ($_POST['status'] ?? 'To Do')) === $st) echo 'selected'; ?>><?php echo $st; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Phase</label>
                        <select name="phase" required class="w-full px-3 py-2 border border-slate-200 rounded-lg">
                            <?php foreach ($PHASES as $ph): ?>
                                <option value="<?php echo htmlspecialchars($ph); ?>" <?php if(($edit_task['phase'] ?? '') === $ph) echo 'selected'; ?>><?php echo htmlspecialchars($ph); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Due date</label>
                        <input type="date" name="task_due" required class="w-full px-3 py-2 border border-slate-200 rounded-lg" value="<?php echo htmlspecialchars($edit_task['due_date'] ?? ''); ?>">
                    </div>
                </div>
                <div>
                    <input type="text" name="task_title" placeholder="Task Title" required class="w-full px-3 py-2 border border-slate-200 rounded-lg mt-3" value="<?php echo htmlspecialchars($edit_task['title'] ?? ''); ?>">
                    <input type="text" name="task_desc" placeholder="Task Description" class="w-full px-3 py-2 border border-slate-200 rounded-lg mt-3" value="<?php echo htmlspecialchars($edit_task['description'] ?? ''); ?>">
                </div>
                <button type="submit" name="update_task" class="mt-4 inline-flex items-center px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition">Update Task</button>
                <a href="assign_tasks.php?project_id=<?php echo (int)$selected_project; ?>" class="ml-4 inline-flex items-center px-4 py-2 rounded-lg bg-slate-200 text-slate-800 hover:bg-slate-300 transition">Cancel</a>
            </form>
        <?php endif; ?>

    <?php if ($view_task): ?>
            <h3 class="text-lg font-semibold text-slate-900 mt-6">Task Details</h3>
            <div class="border border-slate-200 rounded-lg p-4 bg-slate-50">
                <div><strong>Title:</strong> <?php echo htmlspecialchars($view_task['title'] ?? ''); ?></div>
                <div><strong>Description:</strong> <?php echo htmlspecialchars($view_task['description'] ?? ''); ?></div>
                <div><strong>Phase:</strong> <?php echo htmlspecialchars($view_task['phase'] ?? ''); ?></div>
                <div><strong>Status:</strong> <?php echo htmlspecialchars($view_task['status'] ?? ''); ?></div>
                <div><strong>Due:</strong> <?php echo htmlspecialchars($view_task['due_date'] ?? ''); ?></div>
                <div><strong>Assigned To:</strong> <?php echo htmlspecialchars($view_task['assignee_name'] ?? '—'); ?></div>
                <div><strong>Assigned By:</strong> <?php echo htmlspecialchars($view_task['assigner_name'] ?? '—'); ?></div>
                <div class="mt-3"><a href="assign_tasks.php?project_id=<?php echo (int)$selected_project; ?>" class="inline-flex items-center px-4 py-2 rounded-lg bg-slate-200 text-slate-800 hover:bg-slate-300 transition">Close</a></div>
            </div>
        <?php endif; ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Project</label>
                    <?php if ($selected_project > 0): ?>
                        <div class="px-3 py-2 border border-slate-200 rounded-lg bg-slate-50">
                            <?php echo htmlspecialchars($selected_project_name ?: ('Project #'.$selected_project)); ?>
                        </div>
                        <input type="hidden" name="project_id" value="<?php echo (int)$selected_project; ?>">
                    <?php else: ?>
                        <select name="project_id" required class="w-full px-3 py-2 border border-slate-200 rounded-lg">
                            <option value="">Select Project</option>
                            <?php
                            $projects = $conn->query("SELECT $projectIdCol AS id, project_name FROM projects ORDER BY project_name");
                            while ($p = $projects->fetch_assoc()): ?>
                            <option value="<?php echo (int)$p['id']; ?>" <?php echo $selected_project===(int)$p['id']?'selected':''; ?>>
                                <?php echo htmlspecialchars($p['project_name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Phase</label>
                    <select name="phase" required class="w-full px-3 py-2 border border-slate-200 rounded-lg">
                        <option value="">Select Phase</option>
                        <?php foreach ($PHASES as $ph): ?>
                            <option value="<?php echo htmlspecialchars($ph); ?>" <?php if(($_POST['phase'] ?? '')===$ph) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($ph); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php if (!$pm_assignment_disabled): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Assign to Architect</label>
                    <select name="assigned_to" required class="w-full px-3 py-2 border border-slate-200 rounded-lg">
                        <option value="">Select Architect</option>
                        <?php
                          // If a project is selected, show ONLY architects assigned to that project
                          $archs = null; $arch_count = 0;
                                                    // Helper to create employee row if missing
                                                    if (!function_exists('ensure_employee_row_for_user')) {
                                                        function ensure_employee_row_for_user($conn, $userId, $basePosition = 'architect') {
                                                            $userId = (int)$userId;
                                                            if ($userId <= 0) return false;
                                                            $chk = $conn->prepare("SELECT employee_id FROM employees WHERE user_id=? LIMIT 1");
                                                            $chk->bind_param('i', $userId);
                                                            $chk->execute();
                                                            $res = $chk->get_result();
                                                            $row = $res ? $res->fetch_assoc() : null;
                                                            $chk->close();
                                                            if ($row && isset($row['employee_id'])) return true;
                                                            // Need a user record for name, build employee_code
                                                            $uQ = $conn->prepare("SELECT first_name, last_name, position FROM users WHERE user_id=? LIMIT 1");
                                                            $uQ->bind_param('i', $userId);
                                                            $uQ->execute();
                                                            $uR = $uQ->get_result();
                                                            $uRow = $uR ? $uR->fetch_assoc() : null;
                                                            $uQ->close();
                                                            if (!$uRow) return false;
                                                            $positionEnum = 'architect';
                                                            $posLower = strtolower($uRow['position'] ?? '');
                                                            if (strpos($posLower,'senior') !== false && strpos($posLower,'architect') !== false) { $positionEnum = 'senior_architect'; }
                                                            elseif (strpos($posLower,'project') !== false && strpos($posLower,'manager') !== false) { $positionEnum = 'project_manager'; }
                                                            $code = 'AUTO' . str_pad($userId, 5, '0', STR_PAD_LEFT);
                                                            $ins = $conn->prepare("INSERT INTO employees (user_id, employee_code, position, department, hire_date, salary, status) VALUES (?,?,?,?,CURDATE(),0,'active')");
                                                            $dept = 'Architecture';
                                                            $ins->bind_param('isss', $userId, $code, $positionEnum, $dept);
                                                            $ok = $ins->execute();
                                                            $err = $ins->error;
                                                            $ins->close();
                                                            return $ok ? true : $err;
                                                        }
                                                    }

                                                    // Build base query depending on context, then auto-provision missing employee rows for architects
                                                    if ($selected_project > 0) {
                                                            // First, fetch candidate architect user_ids (excluding senior) regardless of employee row
                                                            $candidates = [];
                                                            $candStmt = $conn->prepare(
                                                                "SELECT u.user_id
                                                                 FROM project_users pu
                                                                 JOIN users u ON u.user_id=pu.user_id
                                                                 WHERE pu.project_id=?
                                                                     AND LOWER(pu.role_in_project) LIKE '%architect%'
                                                                     AND LOWER(u.position) LIKE '%architect%'
                                                                     AND LOWER(u.position) NOT LIKE '%senior%'"
                                                            );
                                                            $candStmt->bind_param('i', $selected_project);
                                                            $candStmt->execute();
                                                            $candRes = $candStmt->get_result();
                                                            while ($candRes && ($cr = $candRes->fetch_assoc())) { $candidates[] = (int)$cr['user_id']; }
                                                            $candStmt->close();
                                                            // Ensure employees rows exist
                                                            foreach ($candidates as $cu) { ensure_employee_row_for_user($conn, $cu); }
                                                            // Now fetch enriched list including employee_id
                                                            $stmtA = $conn->prepare(
                                                                    "SELECT u.user_id, u.first_name, u.last_name, u.position, e.employee_id
                                                                     FROM project_users pu
                                                                     JOIN users u ON u.user_id = pu.user_id AND LOWER(u.position) LIKE '%architect%' AND LOWER(u.position) NOT LIKE '%senior%'
                                                                     LEFT JOIN employees e ON e.user_id = u.user_id
                                                                     WHERE pu.project_id = ? AND LOWER(pu.role_in_project) LIKE '%architect%'
                                                                     ORDER BY u.first_name, u.last_name"
                                                            );
                                                            $stmtA->bind_param('i', $selected_project);
                                                            $stmtA->execute();
                                                            $archs = $stmtA->get_result();
                                                            $arch_count = $archs ? $archs->num_rows : 0;
                                                    } else {
                                                            // Global list (non-senior architects), provision missing employee rows as we go
                                                            $archsRaw = $conn->query("SELECT u.user_id, u.first_name, u.last_name, u.position FROM users u WHERE LOWER(u.position) LIKE '%architect%' AND LOWER(u.position) NOT LIKE '%senior%' ORDER BY u.first_name, u.last_name");
                                                            $tmp = [];
                                                            while ($archsRaw && ($rr = $archsRaw->fetch_assoc())) { ensure_employee_row_for_user($conn, (int)$rr['user_id']); $tmp[]=$rr; }
                                                            // Re-query with employees join for employee_id
                                                            $archs = $conn->query("SELECT u.user_id, u.first_name, u.last_name, u.position, e.employee_id FROM users u LEFT JOIN employees e ON e.user_id=u.user_id WHERE LOWER(u.position) LIKE '%architect%' AND LOWER(u.position) NOT LIKE '%senior%' ORDER BY u.first_name, u.last_name");
                                                            $arch_count = $archs ? $archs->num_rows : 0;
                                                    }
                          if ($arch_count === 0 && $selected_project > 0) {
                              echo '<option value="">No architects assigned to this project</option>';
                          }
                          while ($archs && ($a = $archs->fetch_assoc())):
                              $labelExtra = '';
                              $posl = strtolower($a['position'] ?? '');
                              // Skip senior architects entirely (PM cannot assign tasks to them)
                              if (strpos($posl,'senior')!==false && strpos($posl,'architect')!==false) { continue; }
                              if (strpos($posl,'architect')!==false) { $labelExtra = ' — Architect'; }
                              $empIdVal = isset($a['employee_id']) ? (int)$a['employee_id'] : 0;
                              // If employee row still missing, keep but mark as (Unlinked) and allow selection using a temp hidden input value
                              if ($empIdVal <= 0) {
                                  $labelExtra .= ' (Unlinked)';
                              }
                        ?>
                        <option value="<?php echo $empIdVal > 0 ? $empIdVal : ('U'.$a['user_id']); ?>" <?php if((string)($_POST['assigned_to'] ?? '')===($empIdVal > 0 ? (string)$empIdVal : 'U'.$a['user_id'])) echo 'selected'; ?>>
                            <?php echo htmlspecialchars(($a['first_name']??'').' '.($a['last_name']??'').$labelExtra); ?>
                        </option>
                        <?php endwhile; if(isset($stmtA)){ $stmtA->close(); }
                          if ($arch_count === 0) {
                              $assignLink = $selected_project > 0 ? 'assign_architects.php?project_id='.(int)$selected_project : 'assign_architects.php';
                              // Detect if senior architects exist but are suppressed
                              $seniorExplain = '';
                              if ($selected_project > 0) {
                                  $seniorQ = $conn->prepare("SELECT COUNT(*) c FROM project_users pu JOIN users ux ON ux.user_id=pu.user_id WHERE pu.project_id=? AND ( (LOWER(pu.role_in_project) LIKE '%senior%' AND LOWER(pu.role_in_project) LIKE '%architect%') OR (LOWER(ux.position) LIKE '%senior%' AND LOWER(ux.position) LIKE '%architect%') )");
                                  $seniorQ->bind_param('i', $selected_project);
                                  $seniorQ->execute();
                                  $sr = $seniorQ->get_result();
                                  $srRow = $sr ? $sr->fetch_assoc() : ['c'=>0];
                                  $sCount = (int)($srRow['c'] ?? 0);
                                  $seniorQ->close();
                                  if ($sCount > 0) {
                                      $seniorExplain = ' A Senior Architect is assigned, but tasks cannot be assigned directly to Senior Architects.';
                                  }
                              }
                              echo '<option value="">No architects assigned — add one first</option>';
                              echo '</select><p class="mt-2 text-xs text-slate-500">No architects available for this project.' . $seniorExplain . ' <a class="text-blue-600 underline" href="'.htmlspecialchars($assignLink).'">Assign an architect</a> then return here.</p>';
                              // Inline quick-add list of eligible architects not yet assigned
                              // Quick-add panel removed per request.
                              if (isset($_GET['debug'])) {
                                  // Show raw project_users architect-like rows (including seniors) for diagnosis
                                  $dbg = $conn->prepare("SELECT pu.user_id, pu.role_in_project, u.position, e.employee_id FROM project_users pu JOIN users u ON u.user_id=pu.user_id LEFT JOIN employees e ON e.user_id=u.user_id WHERE pu.project_id=?");
                                  $dbg->bind_param('i', $selected_project);
                                  $dbg->execute();
                                  $dbgRes = $dbg->get_result();
                                  echo '<div class=\'mt-2 p-2 bg-slate-100 border border-slate-200 rounded text-[10px] text-slate-600\'><strong>DEBUG LIST</strong><br>';
                                  while ($dbgRes && ($dr = $dbgRes->fetch_assoc())) {
                                      $poslD = strtolower($dr['position'] ?? '');
                                      $reasons = [];
                                      if (strpos($poslD,'architect') === false) $reasons[] = 'not architect';
                                      if (strpos($poslD,'senior') !== false) $reasons[] = 'filtered senior';
                                      if (empty($dr['employee_id'])) $reasons[] = 'no employees row';
                                      echo 'user_id '.$dr['user_id'].' role_in_project='.htmlspecialchars($dr['role_in_project']).' position='.htmlspecialchars($dr['position']).' emp_id='.htmlspecialchars($dr['employee_id'] ?? '').' => '.(empty($reasons)?'VISIBLE':'HIDDEN: '.implode(', ',$reasons)).'<br>';
                                  }
                                  echo '</div>';
                                  $dbg->close();
                              }
                              echo '<select style="display:none">';
                          }
                        ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                    <select name="status" required class="w-full px-3 py-2 border border-slate-200 rounded-lg">
                        <?php $statuses = ['To Do','In Progress','Done']; foreach ($statuses as $st): ?>
                            <option value="<?php echo $st; ?>" <?php if(($_POST['status'] ?? 'To Do') === $st) echo 'selected'; ?>><?php echo $st; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Due date</label>
                    <input type="date" name="task_due" required class="w-full px-3 py-2 border border-slate-200 rounded-lg" value="<?php echo htmlspecialchars($_POST['task_due'] ?? ''); ?>">
                </div>
            </div>
            <div>
                <input type="text" name="task_title" placeholder="Task Title" required class="w-full px-3 py-2 border border-slate-200 rounded-lg mt-3" value="<?php echo htmlspecialchars($_POST['task_title'] ?? ''); ?>">
                <input type="text" name="task_desc" placeholder="Task Description" class="w-full px-3 py-2 border border-slate-200 rounded-lg mt-3" value="<?php echo htmlspecialchars($_POST['task_desc'] ?? ''); ?>">
            </div>
                <button type="submit" name="create_task" class="mt-4 inline-flex items-center px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition">Assign Task</button>
            <?php else: ?>
                <div class="mt-4 p-3 rounded-lg bg-amber-50 text-amber-800 border border-amber-200 text-sm">Project Managers can no longer assign or modify tasks. View-only mode.</div>
            <?php endif; ?>
                    </form>
        <?php
        ?>

        <?php if (isset($_GET['assigned']) && $_GET['assigned'] == '1'): ?>
            <div class="mb-4 p-3 rounded-lg ring-1 ring-green-200 bg-green-50 text-green-800">Task assigned!</div>
        <?php endif; ?>

        <?php if ($selected_project > 0): ?>
            <?php
                // Fetch tasks for selected project grouped by phase
                $tasks_sql =
                    "SELECT t.$tasksPkCol AS id, t.$tasksTitleCol AS title, t.status, t.$tasksDueCol AS due_date, t.phase,
                            u.first_name, u.last_name
                     FROM tasks t
                     LEFT JOIN employees e ON e.employee_id = t.assigned_to
                     LEFT JOIN users u ON u.user_id = e.user_id
                     WHERE t.project_id = ?
                     ORDER BY FIELD(t.phase, 'Pre-Design / Programming','Schematic Design (SD)','Design Development (DD)','Final Design'), t.$tasksDueCol, t.$tasksPkCol";
                $tasks_stmt = $conn->prepare($tasks_sql);
                $tasks_stmt->bind_param('i', $selected_project);
                $tasks_stmt->execute();
                $res = $tasks_stmt->get_result();
                $byPhase = [
                    'Pre-Design / Programming' => [],
                    'Schematic Design (SD)' => [],
                    'Design Development (DD)' => [],
                    'Final Design' => [],
                ];
                while ($row = $res->fetch_assoc()) {
                    $ph = $row['phase'] ?: 'Pre-Design / Programming';
                    if (!isset($byPhase[$ph])) { $byPhase[$ph] = []; }
                    $byPhase[$ph][] = $row;
                }
                $tasks_stmt->close();
            ?>
            <h3 class="text-lg font-semibold text-slate-900 mt-6">Tasks for selected project</h3>
            <?php foreach ($PHASES as $ph): ?>
                <h4 class="mt-4 font-medium text-slate-800"><?php echo htmlspecialchars($ph); ?> <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800"><?php echo count($byPhase[$ph] ?? []); ?></span></h4>
                <table class="min-w-full divide-y divide-slate-200 mt-2">
                    <thead>
                        <tr class="text-left text-xs font-medium uppercase tracking-wider text-slate-500">
                            <th class="py-2 pr-3">Title</th>
                            <th class="py-2 px-3">Assigned To</th>
                            <th class="py-2 px-3">Status</th>
                            <th class="py-2 pl-3">Due</th>
                            <th class="py-2 px-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (!empty($byPhase[$ph])): ?>
                            <?php foreach ($byPhase[$ph] as $t): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="py-2 pr-3 text-slate-900"><?php echo htmlspecialchars($t['title']); ?></td>
                                    <td class="py-2 px-3 text-slate-700">
                                      <?php echo htmlspecialchars(trim(($t['first_name'] ?? '').' '.($t['last_name'] ?? '')) ?: '—'); ?>
                                    </td>
                                    <td class="py-2 px-3 text-slate-700"><?php echo htmlspecialchars($t['status']); ?></td>
                                    <td class="py-2 pl-3 text-slate-700"><?php echo htmlspecialchars($t['due_date']); ?></td>
                                    <td class="py-2 px-3 text-sm">
                                        <a class="text-blue-600 hover:underline" href="assign_tasks.php?project_id=<?php echo (int)$selected_project; ?>&view=<?php echo (int)$t['id']; ?>">View</a>
                                        <?php if (!$pm_assignment_disabled): ?>
                                            <span class="mx-1 text-gray-400">|</span>
                                            <a class="text-indigo-600 hover:underline" href="assign_tasks.php?project_id=<?php echo (int)$selected_project; ?>&edit=<?php echo (int)$t['id']; ?>">Edit</a>
                                            <span class="mx-1 text-gray-400">|</span>
                                            <form method="post" class="inline" onsubmit="return confirm('Delete this task?');">
                                                <input type="hidden" name="delete_task_id" value="<?php echo (int)$t['id']; ?>">
                                                <button type="submit" name="delete_task" class="text-red-600 hover:underline">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="py-3 text-slate-500">No tasks in this phase.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>

        <?php endif; ?>

        <div class="mt-6">
          <a href="pm_dashboard.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition"><i class="fas fa-arrow-left"></i> Back to PM Dashboard</a>
        </div>
    </section>
  </div>
</main>

<?php include __DIR__ . '/backend/core/footer.php'; ?>

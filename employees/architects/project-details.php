<?php
// Session and role/position guard before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$APP_BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($APP_BASE === '/' || $APP_BASE === '.') { $APP_BASE = ''; }
$ROOT_BASE = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($ROOT_BASE === '/' || $ROOT_BASE === '.') { $ROOT_BASE = ''; }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ' . $ROOT_BASE . '/login.php');
    exit;
}
if (($_SESSION['user_type'] ?? '') !== 'employee' || strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? '')))) !== 'architect') {
    $userType = $_SESSION['user_type'] ?? '';
    switch ($userType) {
        case 'admin':
            header('Location: ' . $ROOT_BASE . '/admin/dashboard.php');
            break;
        case 'client':
            header('Location: ' . $ROOT_BASE . '/client/dashboard.php');
            break;
        case 'hr':
            header('Location: ' . $ROOT_BASE . '/hr/hr-dashboard.php');
            break;
        case 'employee':
            $position = strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? ''))));
            if ($position === 'senior_architect') {
                header('Location: ' . $ROOT_BASE . '/employees/senior_architects/senior_architects-dashboard.php');
            } elseif ($position === 'project_manager') {
                header('Location: ' . $ROOT_BASE . '/employees/project_manager/project_manager-dashboard.php');
            } else {
                header('Location: ' . $ROOT_BASE . '/employees/index.php');
            }
            break;
        default:
            header('Location: ' . $ROOT_BASE . '/index.php');
    }
    exit;
}
require_once __DIR__ . '/../../backend/connection/connect.php';
include __DIR__ . '/../../backend/core/header.php';

$db = null;
$errorMsg = '';
$project = null;
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

try {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $db->prepare("SELECT p.*, c.company_name, c.client_type FROM archiflow_db.projects p LEFT JOIN archiflow_db.clients c ON p.client_id = c.client_id WHERE p.project_id = ? LIMIT 1");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$project) {
        throw new Exception('Project not found.');
    }
    // --- Extended metadata & fallbacks (budget, location, start date) ---
    $meta = ['raw'=>$project];
    $invalidDates = ["0000-00-00","0000-00-00 00:00:00","1970-01-01","0001-01-01"];
    // Detect budget directly from project row
    $budgetColsProj = ['budget_amount','budget','project_budget','estimated_budget','budget_estimate','total_budget','design_budget','design_fee','fee_estimate'];
    $projectBudget = null; $projectBudgetKey = null;
    foreach ($budgetColsProj as $bc) {
        if (isset($project[$bc]) && $project[$bc] !== '' && $project[$bc] !== null) {
            $val = $project[$bc];
            if (is_string($val)) { $clean = preg_replace('/[^0-9.]/','',$val); if ($clean !== '') { $val = $clean; } }
            if (is_numeric($val)) { $projectBudget = (float)$val; $projectBudgetKey = $bc; break; }
        }
    }
    // Location direct
    $locationVal = '';
    foreach (['location','location_text','address','site_location'] as $lc) { if (!empty($project[$lc])) { $locationVal = $project[$lc]; break; } }
    // Start date direct
    $startDateVal = (!empty($project['start_date']) && !in_array($project['start_date'],$invalidDates,true)) ? $project['start_date'] : '';
    // Fallback scan project_requests if any missing
    if (($projectBudget === null || $locationVal === '' || $startDateVal === '') && isset($project['client_id'])) {
        try {
            $chk = $db->query("SHOW TABLES LIKE 'project_requests'");
            if ($chk && $chk->rowCount() > 0) {
                $colsPR = [];
                foreach ($db->query('SHOW COLUMNS FROM project_requests') as $c) { $colsPR[$c['Field']] = true; }
                $budgetColsPR = array_values(array_filter(['budget','budget_amount','estimated_budget','project_budget','total_budget','design_budget','design_fee','fee_estimate','quotation_amount','project_cost'], fn($n) => isset($colsPR[$n])));
                $locColsPR = array_values(array_filter(['location','site','site_location','address'], fn($n) => isset($colsPR[$n])));
                $startColsPR = array_values(array_filter(['preferred_start_date','start_date','preferred_date','proposed_start','target_start'], fn($n) => isset($colsPR[$n])));
                $selCols = implode(',', array_unique(array_merge($budgetColsPR,$locColsPR,$startColsPR,['id','created_at'])));
                $stPR = $db->prepare("SELECT $selCols FROM project_requests WHERE client_id = ? ORDER BY created_at DESC LIMIT 10");
                $stPR->execute([(int)$project['client_id']]);
                $rowsPR = $stPR->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rowsPR as $r) {
                    if ($projectBudget === null) {
                        foreach ($budgetColsPR as $bc) {
                            if (isset($r[$bc]) && $r[$bc] !== '' && $r[$bc] !== null) {
                                $v = $r[$bc]; if (is_string($v)) { $cl = preg_replace('/[^0-9.]/','',$v); if ($cl !== '') { $v = $cl; } }
                                if (is_numeric($v)) { $projectBudget = (float)$v; $projectBudgetKey = $bc . ' (request #'.($r['id'] ?? '?').')'; break; }
                            }
                        }
                    }
                    if ($locationVal === '') {
                        foreach ($locColsPR as $lc) { if (!empty($r[$lc])) { $locationVal = $r[$lc]; break; } }
                    }
                    if ($startDateVal === '') {
                        foreach ($startColsPR as $sc) { if (!empty($r[$sc]) && !in_array($r[$sc],$invalidDates,true)) { $startDateVal = $r[$sc]; break; } }
                    }
                    if (($projectBudget !== null) && $locationVal !== '' && $startDateVal !== '') { break; }
                }
            }
        } catch (Throwable $ePR) { /* ignore fallback errors */ }
    }
    $meta['budget_value'] = $projectBudget; $meta['budget_key'] = $projectBudgetKey; $meta['location_final'] = $locationVal; $meta['start_date_final'] = $startDateVal;
    // Materials (optional list)
    $materials = [];
    try {
        $chkMat = $db->query("SHOW TABLES LIKE 'project_materials'");
        if ($chkMat && $chkMat->rowCount() > 0) {
            $sqlMat = "SELECT pm.id, COALESCE(pm.custom_name, m.name) AS name, pm.created_at FROM project_materials pm LEFT JOIN materials m ON m.material_id = pm.material_id WHERE pm.project_id = ? ORDER BY pm.created_at DESC LIMIT 100";
            $stmMat = $db->prepare($sqlMat); $stmMat->execute([$projectId]);
            $materials = $stmMat->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $eMat) { $materials = []; }
} catch (Throwable $e) {
    $errorMsg = $e->getMessage();
}
?>
<main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-50">
    <div class="max-w-full px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-slate-900">Project Details</h1>
                <p class="text-slate-500 mt-1">Architect • <?php echo date('l, F j, Y'); ?></p>
            </div>
            <div class="flex items-center gap-2">
                <a href="architects-dashboard.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 transition">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Dashboard</span>
                </a>
            </div>
        </div>
        <?php if (!empty($errorMsg)): ?>
            <div class="mb-6 p-4 rounded-lg ring-1 ring-red-200 bg-red-50 text-red-800">
                <strong>Error:</strong> <?php echo htmlspecialchars($errorMsg); ?>
            </div>
        <?php else: ?>
            <section class="rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm mb-8">
                <h2 class="text-lg font-semibold text-slate-900 mb-4">Project Info</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-sm">
                    <div>
                        <div class="mb-1 text-slate-500">Project Name</div>
                        <div class="font-medium text-slate-900"><?php echo htmlspecialchars($project['project_name']); ?></div>
                    </div>
                    <div>
                        <div class="mb-1 text-slate-500">Client</div>
                        <div class="font-medium text-slate-900"><?php echo htmlspecialchars($project['company_name'] ?? '—'); ?></div>
                    </div>
                    <div>
                        <div class="mb-1 text-slate-500">Status</div>
                        <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo status_badge_class($project['status']); ?>"><?php echo htmlspecialchars($project['status']); ?></span>
                    </div>
                    <div>
                        <div class="mb-1 text-slate-500">Client Type</div>
                        <div class="font-medium text-slate-900"><?php echo htmlspecialchars($project['client_type'] ?? '—'); ?></div>
                    </div>
                    <div>
                        <div class="mb-1 text-slate-500">Budget</div>
                        <div class="font-medium text-slate-900"><?php echo ($meta['budget_value'] !== null) ? ('₱' . number_format((float)$meta['budget_value'],2)) : '—'; ?></div>
                    </div>
                    <div>
                        <div class="mb-1 text-slate-500">Location</div>
                        <div class="font-medium text-slate-900"><?php echo $meta['location_final'] !== '' ? htmlspecialchars($meta['location_final']) : '—'; ?></div>
                    </div>
                    <div>
                        <div class="mb-1 text-slate-500">Start Date</div>
                        <div class="font-medium text-slate-900"><?php
                            if ($meta['start_date_final'] !== '' && !in_array($meta['start_date_final'],$invalidDates,true)) {
                                $tsSD = strtotime($meta['start_date_final']); echo $tsSD ? htmlspecialchars(date('M j, Y',$tsSD)) : '—';
                            } else { echo '—'; }
                        ?></div>
                    </div>
                    <div>
                        <div class="mb-1 text-slate-500">Created At</div>
                        <div class="font-medium text-slate-900"><?php echo htmlspecialchars(date('M j, Y', strtotime($project['created_at']))); ?></div>
                    </div>
                    <div class="md:col-span-3">
                        <div class="mb-1 text-slate-500">Description</div>
                        <div class="font-medium text-slate-700 whitespace-pre-line"><?php echo $project['description'] ? htmlspecialchars($project['description']) : '—'; ?></div>
                    </div>
                </div>
            </section>
            <?php if (!empty($materials)): ?>
            <section class="rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm mb-8">
                <h2 class="text-lg font-semibold text-slate-900 mb-4">Materials</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    <?php foreach ($materials as $m): ?>
                        <li class="py-3 flex items-center justify-between">
                            <div class="flex flex-col">
                                <span class="font-medium text-slate-800 truncate" title="<?php echo htmlspecialchars($m['name']); ?>"><?php echo htmlspecialchars($m['name']); ?></span>
                                <span class="text-xs text-slate-500">Added <?php echo htmlspecialchars(date('M j, Y', strtotime($m['created_at']))); ?></span>
                            </div>
                            <span class="w-8 h-8 rounded-full bg-indigo-50 text-indigo-700 flex items-center justify-center text-xs"><i class="fas fa-cube"></i></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <!-- Architect's Assigned Tasks for This Project -->
            <section class="rounded-2xl ring-1 ring-slate-200 bg-white p-6 shadow-sm mb-8">
                <h2 class="text-lg font-semibold text-slate-900 mb-4">Your Tasks for This Project</h2>
                <?php
                $tasks = [];
                try {
                    $stmtT = $db->prepare("SELECT t.task_id, t.task_name, t.status, t.due_date FROM tasks t WHERE t.project_id = ? AND t.assigned_to = ? ORDER BY (t.due_date IS NULL), t.due_date ASC, t.task_id DESC");
                    $stmtT->execute([$projectId, $_SESSION['user_id']]);
                    $tasks = $stmtT->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $ex) {}
                ?>
                <?php if (!$tasks): ?>
                    <div class="text-gray-500">No tasks assigned to you for this project.</div>
                <?php else: ?>
                    <ul class="divide-y divide-gray-100">
                        <?php foreach ($tasks as $t): ?>
                        <li class="py-3 flex items-center justify-between">
                            <div>
                                <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($t['task_name']); ?></div>
                                <div class="text-xs text-gray-500">Due: <?php echo $t['due_date'] ? htmlspecialchars(date('M j, Y', strtotime($t['due_date']))) : 'No due date'; ?></div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-700"><?php echo htmlspecialchars(str_replace('_',' ', $t['status'])); ?></span>
                                <a href="/ArchiFlow/employees/architects/task-details.php?task_id=<?php echo (int)$t['task_id']; ?>" class="ml-2 px-2 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 text-xs">View</a>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>
<?php
// Helper: status badge classes
function status_badge_class($status) {
    $map = [
        'planning' => 'bg-yellow-100 text-yellow-800',
        'design' => 'bg-blue-100 text-blue-800',
        'construction' => 'bg-purple-100 text-purple-800',
        'completed' => 'bg-green-100 text-green-800',
        'cancelled' => 'bg-red-100 text-red-800',
        'pending' => 'bg-amber-100 text-amber-800',
        'in_progress' => 'bg-indigo-100 text-indigo-800',
    ];
    return $map[strtolower((string)$status)] ?? 'bg-gray-100 text-gray-800';
}
?>

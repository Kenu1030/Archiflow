<?php
// Comprehensive client project detail view
require_once __DIR__ . '/_client_common.php';

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if(!$projectId){ header('Location: projects-client.php'); exit; }

// Load project (ensure ownership)
$projectStmt = $pdo->prepare("SELECT * FROM projects WHERE project_id=? AND client_id=? LIMIT 1");
$projectStmt->execute([$projectId, $clientId]);
$project = $projectStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$projectNotFound = $project === null;

// Attempt to detect a budget field from known possible keys
$projectBudget = null; $projectBudgetKey = null;
if(!$projectNotFound){
  $budgetCandidateKeys = ['budget','project_budget','estimated_budget','budget_estimate','total_budget','design_budget','design_fee','fee_estimate'];
  foreach($budgetCandidateKeys as $bk){
    if(array_key_exists($bk, $project) && $project[$bk] !== null && $project[$bk] !== ''){
      // Accept numeric-like values; clean strings like "₱525,000"
      $val = $project[$bk];
      if(is_string($val)){
        $clean = preg_replace('/[^0-9.]/','', $val);
        if($clean !== '') { $val = $clean; }
      }
      if(is_numeric($val)){
        $projectBudget = (float)$val;
        $projectBudgetKey = $bk;
        break;
      }
    }
  }
}

// Back button URL logic (safe fallback)
$backUrl = 'projects-client.php';
if(!empty($_SERVER['HTTP_REFERER'])){
  $ref = $_SERVER['HTTP_REFERER'];
  // Only allow same host or relative
  $refHost = parse_url($ref, PHP_URL_HOST);
  $curHost = $_SERVER['HTTP_HOST'] ?? null;
  if(!$refHost || $refHost === $curHost){
    // Prevent redirecting to current page
    $refPath = parse_url($ref, PHP_URL_PATH);
    $curPath = $_SERVER['REQUEST_URI'] ?? '';
    if($refPath && $refPath !== $curPath){
      $backUrl = $ref;
    }
  }
}

// Helper: safe table exists
$tableExists = function(PDO $pdo, string $table): bool {
    try { $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1"); return true; } catch(Throwable $e){ return false; }
};

// Fallback: derive budget from project_requests when project budget is missing
if(!$projectNotFound && $projectBudget === null && $tableExists($pdo,'project_requests')){
  try {
    $colsStmt = $pdo->query('SHOW COLUMNS FROM project_requests');
    $prColsArr = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $prCols = array_flip($prColsArr);
    $budgetCols = array_values(array_filter([
      'budget','budget_amount','estimated_budget','project_budget','total_budget','design_budget','design_fee','fee_estimate','quotation_amount','project_cost'
    ], function($n) use ($prCols){ return isset($prCols[$n]); }));
    if($budgetCols){
      $selCols = implode(',', array_unique(array_merge($budgetCols, ['id','project_name','created_at'])));
      // Prefer recent requests; scan a few to find first valid numeric budget
      $q = $pdo->prepare("SELECT $selCols FROM project_requests WHERE client_id=? ORDER BY created_at DESC LIMIT 10");
      $q->execute([$clientId]);
      $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach($rows as $r){
        foreach($budgetCols as $bc){
          if(isset($r[$bc]) && $r[$bc] !== '' && $r[$bc] !== null){
            $val = $r[$bc];
            if(is_string($val)){
              $clean = preg_replace('/[^0-9.]/','', $val);
              if($clean !== '') { $val = $clean; }
            }
            if(is_numeric($val)){
              $projectBudget = (float)$val;
              $projectBudgetKey = $bc . ' (request #'.($r['id'] ?? '?').')';
              break 2;
            }
          }
        }
      }
    }
  } catch(Throwable $e){ /* ignore fallback errors on client view */ }
}

// Related data containers
$tasks = $milestones = $reviewFiles = $seniorArchitects = $teamMembers = $contracts = $invoices = $materials = [];
$activityTimeline = [];
$errors = [];

if(!$projectNotFound){
    // Tasks
    if($tableExists($pdo,'tasks')){
        try {
            $t = $pdo->prepare('SELECT task_id, task_name, status, assigned_to, created_at FROM tasks WHERE project_id=? ORDER BY created_at DESC LIMIT 50');
            $t->execute([$projectId]);
            $tasks = $t->fetchAll(PDO::FETCH_ASSOC);
        } catch(Throwable $e){ $errors[]='Tasks load failed'; }
    }
    // Milestones
    if($tableExists($pdo,'milestones')){
        try {
            $m = $pdo->prepare('SELECT milestone_id, name, target_date, completion_date, created_at FROM milestones WHERE project_id=? ORDER BY target_date ASC LIMIT 50');
            $m->execute([$projectId]);
            $milestones = $m->fetchAll(PDO::FETCH_ASSOC);
        } catch(Throwable $e){ $errors[]='Milestones load failed'; }
    }
    // Review Files (include file_path for direct linking)
    if($tableExists($pdo,'project_client_review_files')){
      try {
        $r = $pdo->prepare('SELECT id, original_name, version, review_status, file_path, created_at FROM project_client_review_files WHERE project_id=? ORDER BY created_at DESC LIMIT 40');
        $r->execute([$projectId]);
        $reviewFiles = $r->fetchAll(PDO::FETCH_ASSOC);
      } catch(Throwable $e){ $errors[]='Review files load failed'; }
    }
    $latestFile = $reviewFiles ? $reviewFiles[0] : null;
    // Materials (architect-entered) exposed read-only to client
    if($tableExists($pdo,'project_materials')){
      try {
        $dbgMat = ['detected'=>[], 'sql'=>null, 'rows'=>0];
        // Discover schema for project_materials
        $colsPM = $pdo->query('SHOW COLUMNS FROM project_materials')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $hasPM = array_flip($colsPM);
        $PM_ID_COL = isset($hasPM['id']) ? 'id' : (isset($hasPM['pm_id']) ? 'pm_id' : 'id');
        $PM_PID_COL = isset($hasPM['project_id']) ? 'project_id' : (isset($hasPM['proj_id']) ? 'proj_id' : (isset($hasPM['projects_id']) ? 'projects_id' : (isset($hasPM['projectID']) ? 'projectID' : (isset($hasPM['projectId']) ? 'projectId' : (isset($hasPM['project']) ? 'project' : null)))));
        $PM_TS_COL = isset($hasPM['created_at']) ? 'created_at' : (isset($hasPM['created_on']) ? 'created_on' : (isset($hasPM['added_at']) ? 'added_at' : null));
        $PM_CUSTOM_NAME_COL = isset($hasPM['custom_name']) ? 'custom_name' : (isset($hasPM['name']) ? 'name' : null);
        $PM_MATERIAL_FK = isset($hasPM['material_id']) ? 'material_id' : (isset($hasPM['materials_id']) ? 'materials_id' : null);
        $dbgMat['detected'] = compact('PM_ID_COL','PM_PID_COL','PM_TS_COL','PM_CUSTOM_NAME_COL','PM_MATERIAL_FK');

        // Optional join to materials table if present and FK exists
        $joinSql = '';
        $MT_NAME_COL = null;
        if($PM_MATERIAL_FK && $tableExists($pdo,'materials')){
          $colsMT = $pdo->query('SHOW COLUMNS FROM materials')->fetchAll(PDO::FETCH_COLUMN) ?: [];
          $hasMT = array_flip($colsMT);
          $MT_PK = isset($hasMT['material_id']) ? 'material_id' : (isset($hasMT['id']) ? 'id' : null);
          if($MT_PK){
            $MT_NAME_COL = isset($hasMT['name']) ? 'name' : (isset($hasMT['material_name']) ? 'material_name' : null);
            $joinSql = " LEFT JOIN materials m ON m.$MT_PK = pm.$PM_MATERIAL_FK ";
          }
        }

        // Build name expression
        if($PM_CUSTOM_NAME_COL && $MT_NAME_COL){
          $NAME_EXPR = "COALESCE(pm.$PM_CUSTOM_NAME_COL, m.$MT_NAME_COL)";
        } elseif($PM_CUSTOM_NAME_COL){
          $NAME_EXPR = "pm.$PM_CUSTOM_NAME_COL";
        } elseif($MT_NAME_COL){
          $NAME_EXPR = "m.$MT_NAME_COL";
        } else {
          $NAME_EXPR = "CONCAT('Material #', pm.$PM_ID_COL)";
        }

        // Ensure we have a project id column to filter
        if($PM_PID_COL){
          $tsExpr = $PM_TS_COL ? "pm.$PM_TS_COL" : "NOW()";
          $sqlMat = "SELECT pm.$PM_ID_COL AS id, $NAME_EXPR AS name, $tsExpr AS created_at FROM project_materials pm$joinSql WHERE pm.$PM_PID_COL=? ORDER BY $tsExpr DESC LIMIT 100";
          $stmMat = $pdo->prepare($sqlMat); $stmMat->execute([$projectId]);
          $materials = $stmMat->fetchAll(PDO::FETCH_ASSOC) ?: [];
          $dbgMat['sql'] = $sqlMat; $dbgMat['rows'] = count($materials);
          // If debug mode and no rows matched this project, sample other rows for diagnostics
          if(isset($_GET['debug']) && !$materials){
            try {
              $sampleSql = "SELECT pm.$PM_PID_COL AS pid, pm.$PM_ID_COL AS id".
                ($PM_CUSTOM_NAME_COL ? ", pm.$PM_CUSTOM_NAME_COL AS custom_name" : "") .
                " FROM project_materials pm ORDER BY " . ($PM_TS_COL ? "pm.$PM_TS_COL" : "pm.$PM_ID_COL") . " DESC LIMIT 10";
              $sample = $pdo->query($sampleSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
              $errors[] = 'Materials sample (no project match): ' . json_encode($sample);
              // Distinct project ids present
              $distinctSql = "SELECT pm.$PM_PID_COL AS pid, COUNT(*) AS cnt FROM project_materials pm GROUP BY pm.$PM_PID_COL ORDER BY cnt DESC LIMIT 10";
              $distinct = $pdo->query($distinctSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
              $errors[] = 'Materials distinct project ids: ' . json_encode($distinct);
            } catch(Throwable $e){ $errors[] = 'Materials sampling error: '.$e->getMessage(); }
          }
        } else {
          $materials = [];
        }
        if(isset($_GET['debug'])){ $errors[] = 'Materials debug: '.json_encode($dbgMat); }
      } catch(Throwable $e){ $errors[]='Materials load failed'; }
    }

    // Fallback discovery: scan other tables with 'material' in the name if still empty
    if(!$materials){
      try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach($tables as $tbl){
          if(stripos($tbl,'material') === false) continue;
          if(!$tableExists($pdo,$tbl)) continue;
          if(!preg_match('/^[a-zA-Z0-9_]+$/',$tbl)) continue; // safety
          $cols = $pdo->query('SHOW COLUMNS FROM `'.$tbl.'`')->fetchAll(PDO::FETCH_COLUMN) ?: [];
          $has = array_flip($cols);
          $PID_COL = isset($has['project_id']) ? 'project_id' : (isset($has['proj_id']) ? 'proj_id' : (isset($has['projects_id']) ? 'projects_id' : (isset($has['projectID']) ? 'projectID' : (isset($has['projectId']) ? 'projectId' : (isset($has['project']) ? 'project' : null)))));
          if(!$PID_COL) continue;
          $ID_COL = isset($has['id']) ? 'id' : (isset($has['material_id']) ? 'material_id' : (isset($has['pm_id']) ? 'pm_id' : 'id'));
          $TS_COL = isset($has['created_at']) ? 'created_at' : (isset($has['created_on']) ? 'created_on' : (isset($has['added_at']) ? 'added_at' : null));
          $NAME_COL = isset($has['name']) ? 'name' : (isset($has['material_name']) ? 'material_name' : (isset($has['custom_name']) ? 'custom_name' : null));
          $tsExpr = $TS_COL ? '`'.$tbl.'`.`'.$TS_COL.'`' : 'NOW()';
          $nameExpr = $NAME_COL ? '`'.$tbl.'`.`'.$NAME_COL.'`' : "CONCAT('Material #', `".$tbl."`.`".$ID_COL."`)";
          if(!preg_match('/^[a-zA-Z0-9_]+$/',$PID_COL) || !preg_match('/^[a-zA-Z0-9_]+$/',$ID_COL)) continue; // safety
          $sql = "SELECT `".$tbl."`.`".$ID_COL."` AS id, $nameExpr AS name, $tsExpr AS created_at FROM `".$tbl."` WHERE `".$tbl."`.`".$PID_COL."` = ? ORDER BY ".$tsExpr." DESC LIMIT 100";
          $st = $pdo->prepare($sql); $st->execute([$projectId]);
          $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
          if(isset($_GET['debug'])){ $errors[] = 'Materials fallback tried: '.$tbl.' rows='.count($rows); }
          if($rows){ $materials = $rows; break; }
        }
      } catch(Throwable $e){ if(isset($_GET['debug'])){ $errors[] = 'Materials fallback error: '.$e->getMessage(); } }
    }
    // Senior Architect Assignments (fix: employees table lacks full_name; derive from users)
    if($tableExists($pdo,'project_senior_architects') && $tableExists($pdo,'employees')){
      try {
        $hasUsers = $tableExists($pdo,'users');
        if($hasUsers){
          $sqlSA = 'SELECT psa.role, psa.assigned_at, e.employee_id, CONCAT(u.first_name, " ", u.last_name) AS full_name '
            . 'FROM project_senior_architects psa '
            . 'JOIN employees e ON e.employee_id = psa.employee_id '
            . 'LEFT JOIN users u ON u.user_id = e.user_id '
            . 'WHERE psa.project_id = ? ORDER BY psa.assigned_at';
        } else {
          // Fallback without users table
          $sqlSA = 'SELECT psa.role, psa.assigned_at, e.employee_id, e.employee_code AS full_name '
            . 'FROM project_senior_architects psa '
            . 'JOIN employees e ON e.employee_id = psa.employee_id '
            . 'WHERE psa.project_id = ? ORDER BY psa.assigned_at';
        }
        $sa = $pdo->prepare($sqlSA);
        $sa->execute([$projectId]);
        $seniorArchitects = $sa->fetchAll(PDO::FETCH_ASSOC);
      } catch(Throwable $e){ $errors[]='Senior architect load failed'; }
    }
    // Additional team members (generic project_users table if exists)
    if($tableExists($pdo,'project_users') && $tableExists($pdo,'users')){
        try {
            $tu = $pdo->prepare('SELECT pu.user_id, u.full_name, u.email, pu.role, pu.added_at FROM project_users pu JOIN users u ON u.id=pu.user_id WHERE pu.project_id=? ORDER BY pu.added_at');
            $tu->execute([$projectId]);
            $teamMembers = $tu->fetchAll(PDO::FETCH_ASSOC);
        } catch(Throwable $e){ $errors[]='Team members load failed'; }
    }
    // Contracts
    if($tableExists($pdo,'contracts')){
        try {
            $c = $pdo->prepare('SELECT contract_id, contract_value, status, signed_date, created_at FROM contracts WHERE project_id=? ORDER BY created_at DESC');
            $c->execute([$projectId]);
            $contracts = $c->fetchAll(PDO::FETCH_ASSOC);
        } catch(Throwable $e){ $errors[]='Contracts load failed'; }
    }
    // Invoices
    if($tableExists($pdo,'invoices')){
        try {
            $inv = $pdo->prepare('SELECT invoice_id, total_amount, paid_amount, status, issued_date, due_date FROM invoices WHERE project_id=? ORDER BY issued_date DESC');
            $inv->execute([$projectId]);
            $invoices = $inv->fetchAll(PDO::FETCH_ASSOC);
        } catch(Throwable $e){ $errors[]='Invoices load failed'; }
    }

    // Build activity timeline (simple merge of key events)
    foreach($tasks as $t){ $activityTimeline[] = ['ts'=>$t['created_at'],'type'=>'task_created','label'=>'Task: '.($t['task_name'] ?: 'Task #'.$t['task_id'])]; }
    foreach($milestones as $m){ $activityTimeline[] = ['ts'=>$m['created_at'] ?? $m['target_date'],'type'=>'milestone','label'=>'Milestone: '.($m['name'] ?: 'Milestone #'.$m['milestone_id'])]; }
    foreach($reviewFiles as $rf){ $activityTimeline[] = ['ts'=>$rf['created_at'],'type'=>'review_file','label'=>'Review File: '.$rf['original_name'].' v'.$rf['version']]; }
    usort($activityTimeline, function($a,$b){ return strcmp($b['ts'],$a['ts']); });
    $activityTimeline = array_slice($activityTimeline,0,30);
}

// Aggregate metrics
$metrics = [
  'tasks_total' => count($tasks),
  'tasks_completed' => count(array_filter($tasks, fn($t)=> strtolower((string)($t['status']??''))==='completed')),
  'milestones_total' => count($milestones),
  'milestones_completed' => count(array_filter($milestones, fn($m)=> !empty($m['completion_date']))),
  'review_files_total' => count($reviewFiles),
  'review_files_approved' => count(array_filter($reviewFiles, fn($rf)=> strtolower((string)$rf['review_status'])==='approved')),
  'contracts_total' => count($contracts),
  'contracts_value' => array_reduce($contracts, fn($c,$r)=> $c + (float)($r['contract_value'] ?? 0), 0.0),
  'invoices_total' => count($invoices),
  'invoices_outstanding' => array_reduce($invoices, fn($c,$r)=> $c + max(0, (float)($r['total_amount']??0) - (float)($r['paid_amount']??0)), 0.0),
  'materials_total' => count($materials),
  'project_budget' => $projectBudget,
];


include_once __DIR__ . '/../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-blue-900 via-blue-800 to-indigo-800 text-white py-10 relative overflow-hidden">
  <div class="absolute inset-0 opacity-10 bg-[radial-gradient(circle_at_top_left,var(--tw-gradient-stops))] from-white/40 via-transparent to-transparent pointer-events-none"></div>
  <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col gap-6 relative">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
      <div class="space-y-3">
        <div class="flex items-center gap-3">
          <span class="w-12 h-12 rounded-xl bg-white/10 backdrop-blur text-white flex items-center justify-center text-xl"><i class="fas fa-folder-open"></i></span>
          <div>
            <h1 class="text-3xl font-bold tracking-tight">
              <?php echo $projectNotFound ? 'Project Not Found' : htmlspecialchars($project['project_name'] ?: 'Project #'.$projectId); ?>
            </h1>
            <p class="text-sm text-white/70">Comprehensive Project Details</p>
          </div>
        </div>
        <?php if(!$projectNotFound): ?>
        <div class="flex flex-wrap gap-2 text-[11px] font-medium text-white/80">
          <?php if(isset($project['status'])): ?>
            <?php $st = strtolower((string)$project['status']); $cls='bg-white/10 text-white ring-white/20';
              if($st==='planning') $cls='bg-yellow-400/20 text-yellow-200 ring-yellow-300/30';
              elseif($st==='design') $cls='bg-blue-400/20 text-blue-200 ring-blue-300/30';
              elseif($st==='construction') $cls='bg-purple-400/20 text-purple-200 ring-purple-300/30';
              elseif($st==='completed') $cls='bg-green-500/20 text-green-200 ring-green-300/30';
            ?>
            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full ring-1 <?php echo $cls; ?>"><i class="fas fa-circle text-[7px]"></i><?php echo htmlspecialchars($project['status']); ?></span>
          <?php endif; ?>
          <?php if(isset($project['phase'])): ?><span class="px-2 py-0.5 rounded bg-white/10 ring-1 ring-white/20">Phase: <?php echo htmlspecialchars($project['phase']); ?></span><?php endif; ?>
          <?php if(isset($project['created_at'])): ?><span class="px-2 py-0.5 rounded bg-white/10 ring-1 ring-white/20">Created: <?php echo htmlspecialchars(date('Y-m-d', strtotime($project['created_at']))); ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="flex items-center gap-3">
        <a href="<?php echo htmlspecialchars($backUrl); ?>" onclick="if(document.referrer){history.back();return false;}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-white text-blue-800 ring-1 ring-white/40 hover:bg-blue-50 shadow-sm"><i class="fas fa-arrow-left"></i><span>Back</span></a>
        <?php if(!$projectNotFound && $reviewFiles): ?>
          <a href="review_files.php?project_id=<?php echo $projectId; ?>" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-white/10 text-white ring-1 ring-white/30 hover:bg-white/15 shadow"><i class="fas fa-clipboard-check"></i><span>Review Files</span></a>
        <?php endif; ?>
      </div>
    </div>
    <!-- Metrics grid removed per client visibility requirements -->
  </div>
</section>
<main class="min-h-screen bg-gray-100 pb-20 -mt-6">
  <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-8 space-y-10">
    <?php if($projectNotFound): ?>
      <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-10 text-center">
        <div class="flex flex-col items-center gap-4 text-gray-500 text-sm">
          <i class="fas fa-triangle-exclamation text-3xl text-gray-300"></i>
          <p>This project is not available or you do not have permission to view it.</p>
          <a href="projects-client.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-semibold shadow hover:bg-blue-700"><i class="fas fa-arrow-left"></i><span>Return to Projects</span></a>
        </div>
      </div>
    <?php else: ?>

    <!-- Core Details -->
    <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6">
      <h2 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-info-circle text-blue-600"></i><span>Core Details</span></h2>
      <dl class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-x-8 gap-y-4 text-[13px]">
        <div><dt class="text-gray-500">Project ID</dt><dd class="font-medium text-gray-800">#<?php echo $projectId; ?></dd></div>
        <?php if($projectBudget !== null): ?>
          <div>
            <dt class="text-gray-500">Budget</dt>
            <dd class="font-medium text-gray-800">₱<?php echo number_format((float)$projectBudget,2); ?></dd>
          </div>
        <?php else: ?>
          <div>
            <dt class="text-gray-500">Budget</dt>
            <dd class="font-medium text-gray-400">Not set</dd>
          </div>
        <?php endif; ?>
        <?php foreach($project as $k=>$v):
          if(in_array($k,['project_id','client_id',$projectBudgetKey,'is_archived','is_deleted','archived','deleted'])) continue;
          if($v===null||$v==='') continue;
          // Skip placeholder zero dates
          if(is_string($v)){
            $trimV = trim($v);
            if(in_array($trimV,['0000-00-00','0000-00-00 00:00:00','1970-01-01','0001-01-01'])) continue;
          }
        ?>
          <div>
            <dt class="text-gray-500 capitalize"><?php echo htmlspecialchars(str_replace('_',' ',$k)); ?></dt>
            <dd class="font-medium text-gray-800 max-w-xs truncate" title="<?php echo htmlspecialchars((string)$v); ?>">
              <?php echo htmlspecialchars(is_scalar($v)? (string)$v : json_encode($v)); ?>
            </dd>
          </div>
        <?php endforeach; ?>
      </dl>
    </div>

    <!-- Senior Architects (Project Team removed per request) -->
    <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6">
      <h2 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-user-tie text-blue-600"></i><span>Senior Architects</span></h2>
      <?php if(!$seniorArchitects): ?><div class="text-[12px] text-gray-500">None assigned.</div><?php else: ?>
        <ul class="divide-y text-[13px]">
          <?php foreach($seniorArchitects as $sa): ?>
            <li class="py-3 flex items-center justify-between gap-4">
              <div class="min-w-0">
                <div class="font-medium text-gray-800 truncate"><?php echo htmlspecialchars($sa['full_name'] ?: ('Employee #'.$sa['employee_id'])); ?></div>
                <div class="text-[11px] text-gray-400"><?php echo htmlspecialchars($sa['role']); ?> • <?php echo htmlspecialchars(date('Y-m-d', strtotime($sa['assigned_at']))); ?></div>
              </div>
              <span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-blue-100 text-blue-700">Lead</span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <!-- Tasks & Milestones removed per request -->

    <!-- Latest Upload (Preview) -->
    <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6">
      <h2 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-file-image text-blue-600"></i><span>Latest Upload</span></h2>
      <?php if(!$latestFile): ?>
        <div class="text-[12px] text-gray-500">No files uploaded yet.</div>
      <?php else: ?>
        <?php $lfp = (string)($latestFile['file_path'] ?? ''); $lext = strtolower(pathinfo($lfp, PATHINFO_EXTENSION)); $lIsImg = preg_match('/\.(png|jpe?g|gif|webp)$/i',$lfp); $lIsPdf = $lext==='pdf'; ?>
        <div class="space-y-3 text-[13px]">
          <div class="font-medium text-gray-800 truncate" title="<?php echo htmlspecialchars($latestFile['original_name']); ?>"><?php echo htmlspecialchars($latestFile['original_name']); ?> <span class="text-gray-400">v<?php echo (int)$latestFile['version']; ?></span></div>
          <div class="text-[11px] text-gray-400">Uploaded <?php echo htmlspecialchars(date('Y-m-d', strtotime($latestFile['created_at']))); ?></div>
          <?php if($lIsImg): ?>
            <button type="button" class="p-0 border-0 bg-transparent cursor-pointer group latest-file-thumb" data-src="<?php echo htmlspecialchars($lfp); ?>" title="Preview full image">
              <img src="<?php echo htmlspecialchars($lfp); ?>" alt="Latest file preview" class="w-full max-h-[320px] object-contain rounded-md ring-1 ring-gray-200 group-hover:ring-blue-400" loading="lazy" />
            </button>
          <?php elseif($lIsPdf): ?>
            <div class="rounded-md overflow-hidden ring-1 ring-gray-200 bg-gray-50 h-[320px] flex items-center justify-center">
              <iframe src="<?php echo htmlspecialchars($lfp); ?>" class="w-full h-full" title="PDF Preview" loading="lazy"></iframe>
            </div>
          <?php else: ?>
            <div class="text-[12px] text-gray-600 flex items-center gap-2"><i class="fas fa-file"></i><span><?php echo htmlspecialchars(basename($lfp)); ?></span></div>
          <?php endif; ?>
          <div class="flex gap-3 pt-1">
            <a href="<?php echo htmlspecialchars($lfp ?: 'review_files.php?project_id='.$projectId.'&id='.(int)$latestFile['id']); ?>" target="_blank" class="px-3 py-1.5 rounded bg-blue-600 text-white text-[12px] font-medium hover:bg-blue-700"><i class="fas fa-eye"></i> View</a>
            <a href="<?php echo htmlspecialchars($lfp ?: 'review_files.php?project_id='.$projectId.'&id='.(int)$latestFile['id']); ?>" download class="px-3 py-1.5 rounded bg-gray-100 text-gray-700 text-[12px] font-medium hover:bg-gray-200"><i class="fas fa-download"></i> Download</a>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Review Files, Materials & Activity -->
    <div class="grid lg:grid-cols-3 gap-8">
      <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6">
        <h2 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-clipboard-check text-blue-600"></i><span>Recent Review Files</span></h2>
        <?php if(!$reviewFiles): ?><div class="text-[12px] text-gray-500">No review files yet.</div><?php else: ?>
          <ul class="space-y-3 text-[13px] max-h-80 overflow-auto pr-1">
            <?php foreach($reviewFiles as $rf): $b = match(strtolower((string)$rf['review_status'])) { 'approved'=>'bg-green-100 text-green-700', 'changes_requested'=>'bg-red-100 text-red-700', default=>'bg-amber-100 text-amber-700' }; ?>
              <li class="flex items-start gap-3">
                <span class="px-2 py-0.5 rounded-full text-[10px] font-medium <?php echo $b; ?>"><?php echo htmlspecialchars(str_replace('_',' ',$rf['review_status'])); ?></span>
                <div class="min-w-0 flex-1">
                  <div class="font-medium text-gray-800 truncate"><a href="<?php echo htmlspecialchars($rf['file_path'] ?? 'review_files.php?project_id='.$projectId.'&id='.(int)$rf['id']); ?>" target="_blank" class="hover:underline"><?php echo htmlspecialchars($rf['original_name']); ?></a> <span class="text-gray-400">v<?php echo (int)$rf['version']; ?></span></div>
                  <div class="text-[10px] text-gray-400"><?php echo htmlspecialchars(date('Y-m-d', strtotime($rf['created_at']))); ?></div>
                </div>
                <a href="<?php echo htmlspecialchars($rf['file_path'] ?? 'review_files.php?project_id='.$projectId.'&id='.(int)$rf['id']); ?>" download class="text-gray-400 hover:text-gray-600" title="Download"><i class="fas fa-download"></i></a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6">
        <h2 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-toolbox text-blue-600"></i><span>Materials Used</span></h2>
        <?php if($materials): ?>
          <ul class="space-y-3 text-[13px] max-h-80 overflow-auto pr-1">
            <?php foreach($materials as $m): ?>
              <li class="flex items-start gap-3">
                <span class="w-6 h-6 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center text-[11px]"><i class="fas fa-cube"></i></span>
                <div class="min-w-0 flex-1">
                  <div class="font-medium text-gray-800 truncate"><?php echo htmlspecialchars($m['name']); ?></div>
                  <div class="text-[10px] text-gray-400">Added <?php echo htmlspecialchars(date('Y-m-d', strtotime($m['created_at']))); ?></div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="text-[12px] text-gray-500">No materials listed yet.</div>
        <?php endif; ?>
      </div>
      <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6">
        <h2 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-clock text-blue-600"></i><span>Recent Activity</span></h2>
        <?php if(!$activityTimeline): ?><div class="text-[12px] text-gray-500">No activity recorded.</div><?php else: ?>
          <ul class="space-y-3 text-[12px] max-h-80 overflow-auto pr-1">
            <?php foreach($activityTimeline as $ev): ?>
              <li class="flex items-start gap-3">
                <span class="w-6 h-6 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center text-[11px]"><i class="fas fa-circle"></i></span>
                <div class="min-w-0">
                  <div class="font-medium text-gray-800 leading-snug"><?php echo htmlspecialchars($ev['label']); ?></div>
                  <div class="text-[10px] text-gray-400"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($ev['ts']))); ?></div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <!-- Financial (optional) -->
    <?php if($contracts || $invoices): ?>
    <div class="grid lg:grid-cols-2 gap-8">
      <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6">
        <h2 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-file-signature text-blue-600"></i><span>Contracts</span></h2>
        <?php if(!$contracts): ?><div class="text-[12px] text-gray-500">No contracts.</div><?php else: ?>
          <ul class="divide-y text-[13px] max-h-80 overflow-auto pr-1">
            <?php foreach($contracts as $c): ?>
              <li class="py-3 flex items-center justify-between gap-4">
                <div class="min-w-0">
                  <div class="font-medium text-gray-800 truncate">Contract #<?php echo (int)$c['contract_id']; ?></div>
                  <div class="text-[11px] text-gray-400"><?php echo htmlspecialchars($c['status'] ?: 'unknown'); ?> • ₱<?php echo number_format((float)($c['contract_value']??0),2); ?></div>
                </div>
                <?php if(!empty($c['signed_date'])): ?><span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-green-100 text-green-700">Signed</span><?php else: ?><span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-amber-100 text-amber-700">Pending</span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6">
        <h2 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-file-invoice-dollar text-blue-600"></i><span>Invoices</span></h2>
        <?php if(!$invoices): ?><div class="text-[12px] text-gray-500">No invoices.</div><?php else: ?>
          <ul class="divide-y text-[13px] max-h-80 overflow-auto pr-1">
            <?php foreach($invoices as $inv): $out=max(0,(float)($inv['total_amount']??0)-(float)($inv['paid_amount']??0)); ?>
              <li class="py-3 flex items-center justify-between gap-4">
                <div class="min-w-0">
                  <div class="font-medium text-gray-800 truncate">Invoice #<?php echo (int)$inv['invoice_id']; ?> (₱<?php echo number_format((float)($inv['total_amount']??0),2); ?>)</div>
                  <div class="text-[11px] text-gray-400"><?php echo htmlspecialchars($inv['status'] ?? ''); ?> • Due <?php echo htmlspecialchars($inv['due_date'] ?? 'N/A'); ?></div>
                </div>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-medium <?php echo $out>0 ? 'bg-red-100 text-red-700':'bg-green-100 text-green-700'; ?>"><?php echo $out>0 ? ('₱'.number_format($out,2).' due'):'Paid'; ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if($errors && isset($_GET['debug'])): ?>
      <div class="bg-amber-50 border border-amber-200 text-amber-700 text-xs p-3 rounded">Load warnings: <?php echo htmlspecialchars(implode(', ',$errors)); ?></div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</main>
<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>
<script>
// Simple image modal preview for latest upload & review files thumbnails
(function(){
  const modalMarkup = `\n<div id="pcImgModal" class="fixed inset-0 hidden z-50">\n <div class="absolute inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">\n   <div class="relative max-w-4xl w-full">\n     <button type="button" class="absolute -top-3 -right-3 w-10 h-10 rounded-full bg-white text-gray-700 shadow flex items-center justify-center text-lg font-semibold modal-close" aria-label="Close">&times;</button>\n     <div class="bg-white rounded-xl shadow-2xl overflow-hidden ring-1 ring-gray-200">\n       <div class="p-2 bg-gray-50 border-b flex items-center justify-between">\n         <span id="pcImgName" class="text-[12px] font-medium text-gray-600"></span>\n         <div class="flex items-center gap-2">\n           <a id="pcImgView" href="#" target="_blank" class="px-3 py-1.5 rounded bg-blue-600 text-white text-[12px] font-medium hover:bg-blue-700"><i class="fas fa-eye"></i> View</a>\n           <a id="pcImgDownload" href="#" download class="px-3 py-1.5 rounded bg-gray-100 text-gray-700 text-[12px] font-medium hover:bg-gray-200"><i class="fas fa-download"></i> Download</a>\n         </div>\n       </div>\n       <div class="max-h-[70vh] overflow-auto bg-black flex items-center justify-center">\n         <img id="pcImgEl" src="" alt="preview" class="max-w-full h-auto object-contain"/>\n       </div>\n     </div>\n   </div>\n </div>\n</div>`;
  if(!document.getElementById('pcImgModal')){ document.body.insertAdjacentHTML('beforeend', modalMarkup); }
  const modal = document.getElementById('pcImgModal');
  const imgEl = document.getElementById('pcImgEl');
  const nameEl = document.getElementById('pcImgName');
  const viewBtn = document.getElementById('pcImgView');
  const dlBtn = document.getElementById('pcImgDownload');
  function open(src){ imgEl.src=src; nameEl.textContent=src.split('/').pop(); viewBtn.href=src; dlBtn.href=src; modal.classList.remove('hidden'); }
  function close(){ modal.classList.add('hidden'); imgEl.src=''; }
  modal.addEventListener('click', e=>{ if(e.target===modal || e.target.classList.contains('modal-close')) close(); });
  document.querySelectorAll('.latest-file-thumb').forEach(btn=>{ btn.addEventListener('click', ()=>{ const src=btn.getAttribute('data-src'); if(src) open(src); }); });
})();
</script>

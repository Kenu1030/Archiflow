<?php
require_once __DIR__ . '/_client_common.php';

// Configuration: toggle whether a client can self-create projects.
// Set to false if projects are created only by staff/admin and clients should only view assigned ones.
$clientCanCreateProjects = false; // Disabled: clients cannot self-create projects.
// New: make requirement for senior architect assignment configurable (relaxed to show all client-linked projects)
$requireSeniorArchitectAssignmentForClientVisibility = false; // set true to enforce EXISTS filter
// Enable lightweight debug diagnostics with ?debug=1
$debugClientProjects = isset($_GET['debug']);

// Handle create project request (modal form submit)
if ($clientCanCreateProjects && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='create_project') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(400); exit('Bad token'); }
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    try {
        // The provided SQL lacks columns; use minimal insert if columns exist
        $cols = [];$vals=[];$ph=[];
        if ($hasColumn($pdo,'projects','client_id')) { $cols[]='client_id'; $vals[]=$clientId; $ph[]='?'; }
        if ($hasColumn($pdo,'projects','project_name')) { $cols[]='project_name'; $vals[]=$name ?: null; $ph[]='?'; }
        if ($hasColumn($pdo,'projects','description')) { $cols[]='description'; $vals[]=$desc ?: null; $ph[]='?'; }
        if ($cols){
            $sql = 'INSERT INTO projects (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')';
            $stmt=$pdo->prepare($sql); $stmt->execute($vals);
        } else {
            // Fallback: cannot insert without known columns
        }
    } catch(Throwable $e) { /* swallow softly or log */ }
    header('Location: projects-client.php'); exit;
}
// Suppress footer on this page
$HIDE_FOOTER = true;
include_once __DIR__ . '/../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-blue-900 via-blue-800 to-indigo-800 text-white py-12 relative overflow-hidden">
  <div class="absolute inset-0 opacity-10 bg-[radial-gradient(circle_at_top_left,var(--tw-gradient-stops))] from-white/40 via-transparent to-transparent pointer-events-none"></div>
  <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row md:items-center md:justify-between gap-6 relative">
    <div class="space-y-2">
      <div class="flex items-center gap-3">
        <span class="w-12 h-12 rounded-xl bg-white/10 backdrop-blur text-white flex items-center justify-center text-xl"><i class="fas fa-home"></i></span>
        <div>
          <h1 class="text-3xl font-bold tracking-tight">My Projects</h1>
          <p class="text-sm text-white/70">All active and recently created projects</p>
        </div>
      </div>
      <div class="flex flex-wrap gap-2 text-[11px] font-medium text-white/80">
        <span class="px-2 py-0.5 rounded bg-white/10 ring-1 ring-white/20">Design Reviews</span>
        <span class="px-2 py-0.5 rounded bg-white/10 ring-1 ring-white/20">Version Tracking</span>
        <span class="px-2 py-0.5 rounded bg-white/10 ring-1 ring-white/20">Client Portal</span>
      </div>
    </div>
    <div class="flex items-center gap-3">
      <?php if ($clientCanCreateProjects): ?>
        <button id="openCreateProject" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-white text-blue-700 text-sm font-semibold shadow hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-white/50">
          <i class="fas fa-plus"></i>
          <span>New Project</span>
        </button>
      <?php endif; ?>
      <a href="review_files.php" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-white/10 text-white ring-1 ring-white/30 text-sm font-medium hover:bg-white/15 focus:outline-none focus:ring-2 focus:ring-white/40">
        <i class="fas fa-clipboard-check"></i>
        <span>Review Files</span>
      </a>
    </div>
  </div>
</section>
<main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 -mt-8 pb-16">
  <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-200 p-6 md:p-8">
    <div class="overflow-x-auto -mx-4 md:mx-0">
      <table class="min-w-full align-top text-sm">
        <thead class="bg-gray-50">
          <tr class="text-left text-[11px] uppercase tracking-wider text-gray-600">
            <th class="py-3 pl-4 pr-4 font-semibold">Project</th>
            <th class="py-3 pr-4 font-semibold whitespace-nowrap">Created</th>
            <th class="py-3 pr-4 font-semibold">Review Files (Latest 3)</th>
            <th class="py-3 pr-4 font-semibold">Actions</th>
          </tr>
        </thead>
        <tbody class="text-sm divide-y divide-gray-100">
          <?php
          // Build a legit projects list safely
          $rows = [];
          $clientProjectsError = '';
      if ($clientId === null || $clientId === false) {
              $clientProjectsError = 'Your client profile could not be resolved.';
          } else {
              try {
                  $baseSql = 'SELECT project_id, project_name, created_at';
                  // Optionally include status if exists for further filtering / display (not shown yet)
                  $hasStatus = $hasColumn($pdo, 'projects', 'status');
                  $hasArchived = $hasColumn($pdo, 'projects', 'is_archived');
                  $hasDeleted = $hasColumn($pdo, 'projects', 'is_deleted');
                  if ($hasStatus) { $baseSql .= ', status'; }
                  $baseSql .= ' FROM projects WHERE client_id = ?';
                  $params = [$clientId];
          // Only include projects that have a senior architect assignment IF configured
          $psaExists = false;
          try { $pdo->query('SELECT 1 FROM project_senior_architects LIMIT 1'); $psaExists = true; } catch (Throwable $ignore) {}
          if ($psaExists && $requireSeniorArchitectAssignmentForClientVisibility) {
            $baseSql .= ' AND EXISTS (SELECT 1 FROM project_senior_architects psa WHERE psa.project_id = projects.project_id)';
          }
          // Exclude archived / deleted if columns exist
          if ($hasArchived) { $baseSql .= ' AND is_archived = 0'; }
          if ($hasDeleted) { $baseSql .= ' AND (is_deleted = 0 OR is_deleted IS NULL)'; }

          // Optional legitimacy/status filter if status column exists
                  if ($hasStatus) {
                      // Expanded allowed statuses (previously missing planning/design/construction causing empty lists)
                      $allowedStatuses = [
                        'active','ongoing','in_progress','pending_review','completed',
                        'planning','design','construction'
                      ];
                      $inPlaceholders = implode(',', array_fill(0, count($allowedStatuses), '?'));
                      $baseSql .= ' AND status IN (' . $inPlaceholders . ')';
                      $params = array_merge($params, $allowedStatuses);
                  }
                  $baseSql .= ' ORDER BY created_at DESC';
          $originalSql = $baseSql; $originalParams = $params;
          $stmt = $pdo->prepare($baseSql);
          $stmt->execute($params);
          $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
          // If no rows and we had enforced status filter, attempt fallback (remove status filter) to surface potential mismatch
          if (!$rows && $hasStatus) {
            // Re-run without status IN constraint
            $fallbackSql = preg_replace('/ AND status IN \([^)]*\)/','',$baseSql,1);
            if ($fallbackSql !== $baseSql) {
              $stmt = $pdo->prepare($fallbackSql);
              // remove trailing allowed statuses from params (last N entries)
              $fallbackParams = array_slice($params,0,1); // retain only client_id initially (since status placeholders were appended after first param)
              $stmt->execute($fallbackParams);
              $fallbackRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
              if ($fallbackRows) { $rows = $fallbackRows; $statusFilterRelaxed = true; }
            }
          }
                  // Defensive in-PHP filtering in case columns were added after initial query build or caching
                  if ($rows) {
                    $rows = array_values(array_filter($rows,function($r){
                      if (array_key_exists('is_archived',$r) && (int)$r['is_archived']===1) return false;
                      if (array_key_exists('is_deleted',$r) && (int)$r['is_deleted']===1) return false;
                      return true;
                    }));
                  }
        } catch (Throwable $e) {
          $clientProjectsError = 'Could not load projects.'; // Avoid leaking SQL specifics
        }
          }

          if ($clientProjectsError) : ?>
            <tr><td colspan="4" class="py-10 text-center text-red-500 text-sm"><?php echo htmlspecialchars($clientProjectsError); ?></td></tr>
          <?php elseif (!$rows): ?>
            <tr>
              <td colspan="4" class="py-12 text-center">
                <div class="flex flex-col items-center gap-3 text-sm text-gray-500">
                  <i class="fas fa-folder-open text-2xl text-gray-300"></i>
                  <?php if ($clientCanCreateProjects): ?>
                    <span>No projects yet. Start by creating your first project.</span>
                    <button type="button" id="openCreateProject_empty" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-medium shadow hover:bg-blue-700"><i class="fas fa-plus"></i><span>Create Project</span></button>
                  <?php else: ?>
                    <span>You currently have no visible projects. If you believe this is an error, please contact support.</span>
                    <?php if ($debugClientProjects): ?>
                      <div class="mt-3 text-[11px] text-left text-gray-400 max-w-md">
                        <div class="font-semibold text-gray-500 mb-1">Debug Info</div>
                        <div>Client ID: <?php echo htmlspecialchars((string)$clientId); ?></div>
                        <div>Senior Architect Assignment Required: <?php echo $requireSeniorArchitectAssignmentForClientVisibility ? 'yes':'no'; ?> (table present: <?php echo $psaExists?'yes':'no'; ?>)</div>
                        <div>Status Column: <?php echo $hasStatus?'yes':'no'; ?><?php if(isset($allowedStatuses)): ?> (allowed: <?php echo htmlspecialchars(implode(',',$allowedStatuses)); ?>)<?php endif; ?></div>
                        <div>Original SQL: <code class="block whitespace-pre-wrap text-[10px] leading-snug"><?php echo htmlspecialchars($originalSql); ?></code></div>
                        <div>Params (count <?php echo isset($originalParams)?count($originalParams):0; ?>): <code class="block whitespace-pre-wrap text-[10px] leading-snug"><?php echo htmlspecialchars(json_encode($originalParams)); ?></code></div>
                        <?php if(isset($statusFilterRelaxed) && $statusFilterRelaxed): ?><div class="text-amber-600">Status filter relaxed during fallback (still produced 0 rows).</div><?php endif; ?>
                      </div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php
              // Preload latest review files for all projects in one query
              $ids = array_column($rows,'project_id');
              $reviewIndex = [];
              if ($ids) {
                $in = implode(',', array_map('intval',$ids));
                try {
                  $q = $pdo->query("SELECT project_id, id, original_name, version, review_status, file_path, created_at FROM project_client_review_files WHERE project_id IN ($in) ORDER BY created_at DESC");
                  while($rf = $q->fetch(PDO::FETCH_ASSOC)) {
                    $pid = (int)$rf['project_id'];
                    if(!isset($reviewIndex[$pid])) { $reviewIndex[$pid]=[]; }
                    if(count($reviewIndex[$pid])<3) { $reviewIndex[$pid][] = $rf; }
                  }
                } catch(Throwable $e) {
                  // Table may not exist yet; ignore
                }
              }
            ?>
            <?php foreach ($rows as $r): $pid=(int)$r['project_id']; $pName = $r['project_name'] ?: ('Project #'.$pid); $files = $reviewIndex[$pid] ?? []; ?>
              <tr class="align-top hover:bg-gray-50/60 transition">
                <td class="py-4 pr-4 w-60">
                  <div class="flex items-start gap-3">
                    <div class="w-9 h-9 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-sm font-semibold">P</div>
                    <div class="min-w-0">
                      <div class="font-semibold text-gray-800 truncate" title="<?php echo htmlspecialchars($pName); ?>"><?php echo htmlspecialchars($pName); ?><?php if(isset($_GET['debug']) && (isset($r['is_archived'])||isset($r['is_deleted']))): ?><span class="ml-1 text-[10px] font-mono text-gray-400"><?php echo (isset($r['is_archived'])?('A'.(int)$r['is_archived']):'').(isset($r['is_deleted'])?('D'.(int)$r['is_deleted']):''); ?></span><?php endif; ?></div>
                      <div class="text-[11px] text-gray-400">ID #<?php echo $pid; ?></div>
                    </div>
                  </div>
                </td>
                <td class="py-4 pr-4 text-gray-500 whitespace-nowrap align-top text-[13px]">
                  <div class="leading-tight">
                    <div><?php echo htmlspecialchars(date('Y-m-d', strtotime($r['created_at']))); ?></div>
                    <div class="text-[10px] text-gray-400"><?php echo htmlspecialchars(date('H:i', strtotime($r['created_at']))); ?></div>
                  </div>
                </td>
                <td class="py-4 pr-4 align-top">
                  <?php if(!$files): ?>
                    <span class="text-[11px] text-gray-400 italic">No review files yet</span>
                  <?php else: ?>
                    <ul class="space-y-1.5">
                      <?php foreach ($files as $f): $badge = match($f['review_status']) { 'approved'=>'bg-green-100 text-green-700 ring-green-200','changes_requested'=>'bg-red-100 text-red-700 ring-red-200', default=>'bg-amber-100 text-amber-700 ring-amber-200'}; ?>
                        <li class="flex items-center gap-2 text-[11px]">
                          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full ring-1 font-medium <?php echo $badge; ?>" title="Status: <?php echo htmlspecialchars($f['review_status']); ?>">
                            <i class="fas fa-circle text-[6px]"></i>
                            <?php echo htmlspecialchars(str_replace('_',' ', $f['review_status'])); ?>
                          </span>
                          <a href="<?php echo htmlspecialchars($f['file_path']); ?>" target="_blank" class="text-blue-600 hover:underline font-medium" title="View v<?php echo (int)$f['version']; ?>">
                            <?php echo htmlspecialchars(mb_strimwidth($f['original_name'],0,34,'…')); ?> <span class="text-gray-400">v<?php echo (int)$f['version']; ?></span>
                          </a>
                          <a href="<?php echo htmlspecialchars($f['file_path']); ?>" download class="text-gray-400 hover:text-gray-600" title="Download"><i class="fas fa-download"></i></a>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </td>
                <td class="py-4 pr-4 align-top">
                  <div class="flex flex-col gap-2 min-w-[110px]">
                    <button type="button" data-url="project_details_client.php?project_id=<?php echo $pid; ?>" class="project-open-btn flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-blue-600 text-white text-[12px] font-semibold shadow hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-400">
                      <i class="fas fa-eye"></i><span>Open</span>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<?php if ($clientCanCreateProjects): ?>
  <!-- Create Project Modal (visible only if client creation enabled) -->
  <div id="createProjectModal" class="fixed inset-0 hidden items-center justify-center z-50">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>
    <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg p-8 ring-1 ring-gray-200">
      <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold flex items-center gap-2 text-gray-800"><i class="fas fa-plus-circle text-blue-600"></i><span>Create Project</span></h3>
        <button id="closeCreateProject" class="text-gray-400 hover:text-gray-600 transition"><i class="fas fa-times"></i></button>
      </div>
      <form method="post" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>" />
        <input type="hidden" name="action" value="create_project" />
        <div class="space-y-2">
          <label class="block text-[12px] font-semibold text-gray-600 tracking-wide uppercase">Project Name</label>
          <input type="text" name="name" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Optional, depending on schema">
        </div>
        <div class="space-y-2">
          <label class="block text-[12px] font-semibold text-gray-600 tracking-wide uppercase">Description</label>
          <textarea name="description" rows="3" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Optional"></textarea>
        </div>
        <div class="flex justify-end gap-3 pt-2">
          <button type="button" id="cancelCreateProject" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white ring-1 ring-gray-200 text-gray-700 text-sm font-medium hover:bg-gray-50"><i class="fas fa-rotate-left"></i><span>Cancel</span></button>
          <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-semibold shadow hover:bg-blue-700"><i class="fas fa-check"></i><span>Create</span></button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>
<script>
  // Fallback navigation for Open buttons (in case default anchor clicks were being blocked by overlay/JS)
  document.querySelectorAll('.project-open-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const url = btn.getAttribute('data-url');
      if(!url){ return; }
      try {
        window.location.href = url;
      } catch(e) {
        console.error('Navigation failed', e);
      }
    });
  });

  const openBtn = document.getElementById('openCreateProject');
  if (openBtn) {
    openBtn.addEventListener('click',()=>{
      document.getElementById('createProjectModal').classList.remove('hidden');
      document.getElementById('createProjectModal').classList.add('flex');
    });
  }
  const openEmpty = document.getElementById('openCreateProject_empty');
  if (openEmpty) {
    openEmpty.addEventListener('click',()=>{
      document.getElementById('createProjectModal').classList.remove('hidden');
      document.getElementById('createProjectModal').classList.add('flex');
    });
  }
  const close = ()=>{
    document.getElementById('createProjectModal').classList.add('hidden');
    document.getElementById('createProjectModal').classList.remove('flex');
  };
  document.getElementById('closeCreateProject').addEventListener('click', close);
  document.getElementById('cancelCreateProject').addEventListener('click', close);
</script>

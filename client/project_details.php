<?php
require_once __DIR__ . '/_client_common.php';

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if (!$projectId) { header('Location: projects-client.php'); exit; }

// Ensure client owns project (defer rendering error inside layout instead of raw exit)
$stmt = $pdo->prepare("SELECT * FROM projects WHERE project_id=? AND client_id=? LIMIT 1");
$stmt->execute([$projectId, $clientId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
$projectNotFound = $project === null;

// Fetch related (best-effort, ignore missing tables)
$tasks = $milestones = $reviewFiles = $materials = [];
$latestFile = null;
try { if($pdo->query('SELECT 1 FROM tasks LIMIT 1')) { $t=$pdo->prepare('SELECT task_id, task_name, status, assigned_to, created_at FROM tasks WHERE project_id=? ORDER BY created_at DESC LIMIT 20'); $t->execute([$projectId]); $tasks=$t->fetchAll(PDO::FETCH_ASSOC); } } catch(Throwable $e){}
try { if($pdo->query('SELECT 1 FROM milestones LIMIT 1')) { $m=$pdo->prepare('SELECT milestone_id, name, target_date, completion_date FROM milestones WHERE project_id=? ORDER BY target_date LIMIT 15'); $m->execute([$projectId]); $milestones=$m->fetchAll(PDO::FETCH_ASSOC); } } catch(Throwable $e){}
try {
  if($pdo->query('SELECT 1 FROM project_client_review_files LIMIT 1')) {
    $r=$pdo->prepare('SELECT id, original_name, version, review_status, file_path, created_at FROM project_client_review_files WHERE project_id=? ORDER BY created_at DESC LIMIT 10');
    $r->execute([$projectId]);
    $reviewFiles=$r->fetchAll(PDO::FETCH_ASSOC);
    if($reviewFiles){ $latestFile = $reviewFiles[0]; }
  }
} catch(Throwable $e){}
// Materials (architect-entered) read-only for client
try {
  if($pdo->query('SELECT 1 FROM project_materials LIMIT 1')) {
    $mat=$pdo->prepare('SELECT pm.id, COALESCE(pm.custom_name,m.name) AS name, pm.created_at FROM project_materials pm LEFT JOIN materials m ON m.material_id=pm.material_id WHERE pm.project_id=? ORDER BY pm.created_at DESC LIMIT 50');
    $mat->execute([$projectId]);
    $materials = $mat->fetchAll(PDO::FETCH_ASSOC);
  }
} catch(Throwable $e){}

// Derive per-project metrics for hero stats (safe defaults if unavailable)
$metrics = [
  'tasks_total' => 0,
  'tasks_completed' => 0,
  'milestones_total' => 0,
  'milestones_completed' => 0,
  'review_files_total' => 0,
  'review_files_approved' => 0,
  'materials_total' => 0,
];
if(!$projectNotFound) {
  if($tasks) {
    $metrics['tasks_total'] = count($tasks);
    foreach($tasks as $t){ if(strtolower((string)($t['status'] ?? ''))==='completed') $metrics['tasks_completed']++; }
  }
  if($milestones) {
    $metrics['milestones_total'] = count($milestones);
    foreach($milestones as $m){ if(!empty($m['completion_date'])) $metrics['milestones_completed']++; }
  }
  if($reviewFiles) {
    $metrics['review_files_total'] = count($reviewFiles);
    foreach($reviewFiles as $rf){ if(strtolower((string)$rf['review_status'])==='approved') $metrics['review_files_approved']++; }
  }
  if($materials) { $metrics['materials_total'] = count($materials); }
}

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
          <p class="text-sm text-white/70">Project Overview & Details</p>
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
        <a href="projects-client.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-white text-blue-800 ring-1 ring-white/40 hover:bg-blue-50 shadow-sm"><i class="fas fa-arrow-left"></i><span>Back</span></a>
        <?php if(!$projectNotFound): ?>
          <a href="review_files.php?project_id=<?php echo $projectId; ?>" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium bg-white/10 text-white ring-1 ring-white/30 hover:bg-white/15 shadow"><i class="fas fa-clipboard-check"></i><span>Review Files</span></a>
        <?php endif; ?>
      </div>
    </div>
    <?php if(!$projectNotFound): ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-4">
      <div class="bg-white/10 p-3 rounded">
        <div class="text-[11px] text-white/60 flex items-center justify-between">Tasks Total <i class="fas fa-list"></i></div>
        <div class="text-2xl font-semibold mt-1"><?php echo $metrics['tasks_total']; ?></div>
      </div>
      <div class="bg-white/10 p-3 rounded">
        <div class="text-[11px] text-white/60 flex items-center justify-between">Tasks Done <i class="fas fa-check"></i></div>
        <div class="text-2xl font-semibold mt-1"><?php echo $metrics['tasks_completed']; ?></div>
      </div>
      <div class="bg-white/10 p-3 rounded">
        <div class="text-[11px] text-white/60 flex items-center justify-between">Milestones <i class="fas fa-road"></i></div>
        <div class="text-2xl font-semibold mt-1"><?php echo $metrics['milestones_total']; ?></div>
      </div>
      <div class="bg-white/10 p-3 rounded">
        <div class="text-[11px] text-white/60 flex items-center justify-between">Milestones Done <i class="fas fa-flag-checkered"></i></div>
        <div class="text-2xl font-semibold mt-1"><?php echo $metrics['milestones_completed']; ?></div>
      </div>
      <div class="bg-white/10 p-3 rounded">
        <div class="text-[11px] text-white/60 flex items-center justify-between">Review Files <i class="fas fa-clipboard-check"></i></div>
        <div class="text-2xl font-semibold mt-1"><?php echo $metrics['review_files_total']; ?></div>
      </div>
      <div class="bg-white/10 p-3 rounded">
        <div class="text-[11px] text-white/60 flex items-center justify-between">Approved <i class="fas fa-thumbs-up"></i></div>
        <div class="text-2xl font-semibold mt-1"><?php echo $metrics['review_files_approved']; ?></div>
      </div>
      <div class="bg-white/10 p-3 rounded">
        <div class="text-[11px] text-white/60 flex items-center justify-between">Materials <i class="fas fa-toolbox"></i></div>
        <div class="text-2xl font-semibold mt-1"><?php echo $metrics['materials_total']; ?></div>
      </div>
    </div>
    <?php endif; ?>
    </div>
</section>
<main class="min-h-screen bg-gray-100 pb-16 -mt-6">
  <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-8">
    <?php if($projectNotFound): ?>
      <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-10 text-center">
        <div class="flex flex-col items-center gap-4 text-gray-500 text-sm">
          <i class="fas fa-triangle-exclamation text-3xl text-gray-300"></i>
          <p>This project is not available or you do not have permission to view it.</p>
          <a href="projects-client.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-semibold shadow hover:bg-blue-700"><i class="fas fa-arrow-left"></i><span>Return to Projects</span></a>
        </div>
      </div>
    </div>
  </main>
<?php include_once __DIR__ . '/../backend/core/footer.php'; return; endif; ?>

  <div class="grid lg:grid-cols-3 gap-8">
      <div class="lg:col-span-2 space-y-8">
        <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6">
          <h2 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-info-circle text-blue-600"></i><span>Core Details</span></h2>
          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4 text-[13px]">
            <div><dt class="text-gray-500">Project ID</dt><dd class="font-medium text-gray-800">#<?php echo $projectId; ?></dd></div>
            <?php if(isset($project['project_name'])): ?><div><dt class="text-gray-500">Name</dt><dd class="font-medium text-gray-800"><?php echo htmlspecialchars($project['project_name']); ?></dd></div><?php endif; ?>
            <?php if(isset($project['status'])): ?><div><dt class="text-gray-500">Status</dt><dd class="font-medium text-gray-800"><?php echo htmlspecialchars($project['status']); ?></dd></div><?php endif; ?>
            <?php if(isset($project['phase'])): ?><div><dt class="text-gray-500">Phase</dt><dd class="font-medium text-gray-800"><?php echo htmlspecialchars($project['phase']); ?></dd></div><?php endif; ?>
            <?php if(isset($project['created_at'])): ?><div><dt class="text-gray-500">Created</dt><dd class="font-medium text-gray-800"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($project['created_at']))); ?></dd></div><?php endif; ?>
            <?php if(isset($project['updated_at'])): ?><div><dt class="text-gray-500">Updated</dt><dd class="font-medium text-gray-800"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($project['updated_at']))); ?></dd></div><?php endif; ?>
          </dl>
        </div>

        <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6">
          <h2 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-list-check text-blue-600"></i><span>Recent Tasks</span></h2>
          <?php if(!$tasks): ?>
            <div class="text-[12px] text-gray-500">No tasks found.</div>
          <?php else: ?>
            <ul class="divide-y text-[13px]">
              <?php foreach($tasks as $t): $st = strtolower((string)($t['status'] ?? '')); $c='bg-gray-100 text-gray-700'; if($st==='completed') $c='bg-green-100 text-green-700'; elseif($st==='in_progress'||$st==='ongoing') $c='bg-blue-100 text-blue-700'; ?>
                <li class="py-3 flex items-center justify-between gap-4">
                  <div class="min-w-0">
                    <div class="font-medium text-gray-800 truncate"><?php echo htmlspecialchars($t['task_name'] ?? ('Task #'.$t['task_id'])); ?></div>
                    <div class="text-[11px] text-gray-400">Created <?php echo htmlspecialchars(date('Y-m-d', strtotime($t['created_at']))); ?></div>
                  </div>
                  <span class="px-2 py-0.5 rounded-full text-[10px] font-medium <?php echo $c; ?>"><?php echo htmlspecialchars($t['status']); ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6">
          <h2 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-road text-blue-600"></i><span>Milestones</span></h2>
          <?php if(!$milestones): ?>
            <div class="text-[12px] text-gray-500">No milestones listed.</div>
          <?php else: ?>
            <ul class="divide-y text-[13px]">
              <?php foreach($milestones as $m): $done = !empty($m['completion_date']); ?>
                <li class="py-3 flex items-center justify-between gap-4">
                  <div class="min-w-0">
                    <div class="font-medium text-gray-800 truncate"><?php echo htmlspecialchars($m['name'] ?? ('Milestone #'.$m['milestone_id'])); ?></div>
                    <div class="text-[11px] text-gray-400">Target <?php echo htmlspecialchars($m['target_date']); ?></div>
                  </div>
                  <span class="px-2 py-0.5 rounded-full text-[10px] font-medium <?php echo $done ? 'bg-green-100 text-green-700':'bg-amber-100 text-amber-700'; ?>"><?php echo $done ? 'Done':'Pending'; ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
      <div class="lg:col-span-1 space-y-8">
        <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6">
          <h2 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-file-image text-blue-600"></i><span>Latest Upload</span></h2>
          <?php if(!$latestFile): ?>
            <div class="text-[12px] text-gray-500">No files uploaded yet.</div>
          <?php else: ?>
            <div class="space-y-3 text-[13px]">
              <div class="font-medium text-gray-800 truncate"><?php echo htmlspecialchars($latestFile['original_name']); ?> <span class="text-gray-400">v<?php echo (int)$latestFile['version']; ?></span></div>
              <div class="text-[11px] text-gray-400">Uploaded <?php echo htmlspecialchars(date('Y-m-d', strtotime($latestFile['created_at']))); ?></div>
              <?php $fp = (string)($latestFile['file_path'] ?? ''); $isImg = preg_match('/\.(png|jpe?g|gif|webp)$/i',$fp); ?>
              <?php if($isImg): ?>
                <div class="rounded-md overflow-hidden ring-1 ring-gray-200 bg-gray-50">
                  <img src="<?php echo htmlspecialchars($fp); ?>" alt="Latest file preview" class="w-full h-auto block" loading="lazy" />
                </div>
              <?php else: ?>
                <div class="text-[12px] text-gray-600 flex items-center gap-2"><i class="fas fa-file"></i><span><?php echo htmlspecialchars(basename($fp)); ?></span></div>
              <?php endif; ?>
              <div class="flex gap-3">
                <a href="<?php echo htmlspecialchars($fp ?: 'review_files.php?project_id='.$projectId.'&id='.(int)$latestFile['id']); ?>" target="_blank" class="px-3 py-1.5 rounded bg-blue-600 text-white text-[12px] font-medium hover:bg-blue-700"><i class="fas fa-eye"></i> View</a>
                <a href="<?php echo htmlspecialchars($fp ?: 'review_files.php?project_id='.$projectId.'&id='.(int)$latestFile['id']); ?>" download class="px-3 py-1.5 rounded bg-gray-100 text-gray-700 text-[12px] font-medium hover:bg-gray-200"><i class="fas fa-download"></i> Download</a>
              </div>
            </div>
          <?php endif; ?>
        </div>
        <div class="bg-white rounded-xl ring-1 ring-gray-200 shadow-sm p-6">
          <h2 class="text-sm font-semibold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-clipboard-check text-blue-600"></i><span>Recent Review Files</span></h2>
          <?php if(!$reviewFiles): ?>
            <div class="text-[12px] text-gray-500">No review files yet.</div>
          <?php else: ?>
            <ul class="space-y-3 text-[13px]">
              <?php foreach($reviewFiles as $rf): $b = match(strtolower((string)$rf['review_status'])) { 'approved'=>'bg-green-100 text-green-700', 'changes_requested'=>'bg-red-100 text-red-700', default=>'bg-amber-100 text-amber-700' }; $fp=(string)($rf['file_path']??''); $isImg=preg_match('/\.(png|jpe?g|gif|webp)$/i',$fp); ?>
                <li class="flex items-start gap-3">
                  <?php if($isImg): ?><button type="button" class="p-0 border-0 bg-transparent cursor-pointer group pd-file-thumb" data-src="<?php echo htmlspecialchars($fp); ?>" title="Preview"><img src="<?php echo htmlspecialchars($fp); ?>" alt="thumb" class="w-12 h-12 object-cover rounded-md ring-1 ring-gray-200 group-hover:ring-blue-400" loading="lazy" /></button><?php endif; ?>
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
          <?php if(!$materials): ?>
            <div class="text-[12px] text-gray-500">No materials listed.</div>
          <?php else: ?>
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
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>
<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>
<script>
// Project details image preview modal
(function(){
  const modalMarkup = `\n<div id="pdImgModal" class="fixed inset-0 hidden z-50">\n <div class="absolute inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">\n   <div class="relative max-w-4xl w-full">\n     <button type="button" class="absolute -top-3 -right-3 w-10 h-10 rounded-full bg-white text-gray-700 shadow flex items-center justify-center text-lg font-semibold modal-close" aria-label="Close">&times;</button>\n     <div class="bg-white rounded-xl shadow-2xl overflow-hidden ring-1 ring-gray-200">\n       <div class="p-2 bg-gray-50 border-b flex items-center justify-between">\n         <span id="pdImgName" class="text-[12px] font-medium text-gray-600"></span>\n         <div class="flex items-center gap-2">\n           <a id="pdImgView" href="#" target="_blank" class="px-3 py-1.5 rounded bg-blue-600 text-white text-[12px] font-medium hover:bg-blue-700"><i class="fas fa-eye"></i> View</a>\n           <a id="pdImgDownload" href="#" download class="px-3 py-1.5 rounded bg-gray-100 text-gray-700 text-[12px] font-medium hover:bg-gray-200"><i class="fas fa-download"></i> Download</a>\n         </div>\n       </div>\n       <div class="max-h-[70vh] overflow-auto bg-black flex items-center justify-center">\n         <img id="pdImgEl" src="" alt="preview" class="max-w-full h-auto object-contain"/>\n       </div>\n     </div>\n   </div>\n </div>\n</div>`;
  if(!document.getElementById('pdImgModal')){ document.body.insertAdjacentHTML('beforeend', modalMarkup); }
  const modal = document.getElementById('pdImgModal');
  const imgEl = document.getElementById('pdImgEl');
  const nameEl = document.getElementById('pdImgName');
  const viewBtn = document.getElementById('pdImgView');
  const dlBtn = document.getElementById('pdImgDownload');
  function open(src){ imgEl.src=src; nameEl.textContent=src.split('/').pop(); viewBtn.href=src; dlBtn.href=src; modal.classList.remove('hidden'); }
  function close(){ modal.classList.add('hidden'); imgEl.src=''; }
  modal.addEventListener('click', e=>{ if(e.target===modal || e.target.classList.contains('modal-close')) close(); });
  document.querySelectorAll('.pd-file-thumb').forEach(btn=>{ btn.addEventListener('click', ()=>{ const src=btn.getAttribute('data-src'); if(src) open(src); }); });
})();
</script>

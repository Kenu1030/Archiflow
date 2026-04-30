<?php
// Project Manager view of Senior Architect responses to PM uploads
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../../login.php'); exit; }
$position = strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? ''))));
if (($_SESSION['user_type'] ?? '') !== 'employee' || $position !== 'project_manager') { header('Location: ../../index.php'); exit; }

require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Resolve current PM employee id
$userId = (int)($_SESSION['user_id'] ?? 0);
$employeeId = 0;
try {
  $es = $db->prepare('SELECT employee_id FROM employees WHERE user_id=? LIMIT 1');
  $es->execute([$userId]);
  $row = $es->fetch(PDO::FETCH_ASSOC);
  $employeeId = $row ? (int)$row['employee_id'] : 0;
} catch (Throwable $e) {}

// Discover columns for pm_senior_files
$cols = [];
try { $cr = $db->query("SHOW COLUMNS FROM pm_senior_files"); while ($c=$cr->fetch(PDO::FETCH_ASSOC)) { $cols[$c['Field']] = true; } } catch (Throwable $e) {}
$hasStatus = isset($cols['status']);
$hasSeniorComment = isset($cols['senior_comment']);
$hasReviewedAt = isset($cols['reviewed_at']);
$hasReviewer = isset($cols['reviewed_by_employee_id']);

// Determine projects PK and name column
$projCols = [];
try { foreach($db->query('SHOW COLUMNS FROM projects') as $c){ $projCols[$c['Field']] = true; } } catch (Throwable $e) {}
$PROJECTS_PK = isset($projCols['project_id']) ? 'project_id' : (isset($projCols['id']) ? 'id' : 'project_id');
$PROJECT_NAME_COL = isset($projCols['project_name']) ? 'project_name' : (isset($projCols['name']) ? 'name' : (isset($projCols['title']) ? 'title' : null));

// Filters
$filterStatus = $_GET['status'] ?? '';
$validStatuses = ['pending','forwarded','reviewed','revisions_requested'];
if ($filterStatus && !in_array($filterStatus,$validStatuses,true)) $filterStatus = '';
$onlyResponses = isset($_GET['only_responses']) ? (int)$_GET['only_responses'] : 1; // default focus on responses

// Build base select
$select = ['f.id','f.project_id','f.design_phase','f.original_name','f.stored_name','f.relative_path','f.mime_type','f.size','f.note','f.uploaded_at'];
if ($hasStatus) $select[] = 'f.status';
if ($hasSeniorComment) $select[] = 'f.senior_comment';
if ($hasReviewedAt) $select[] = 'f.reviewed_at';
if ($hasReviewer) $select[] = 'f.reviewed_by_employee_id';
if ($PROJECT_NAME_COL) $select[] = 'p.' . $PROJECT_NAME_COL . ' AS project_name';

$where = ['f.uploaded_by_employee_id = ?'];
$params = [$employeeId];
if ($hasStatus && $filterStatus) { $where[] = 'f.status = ?'; $params[] = $filterStatus; }
if ($onlyResponses && $hasStatus) { $where[] = "f.status IN ('reviewed','revisions_requested')"; }

$sql = 'SELECT ' . implode(',', $select) . ' FROM pm_senior_files f JOIN projects p ON p.' . $PROJECTS_PK . ' = f.project_id';
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= $hasReviewedAt ? ' ORDER BY f.reviewed_at DESC, f.uploaded_at DESC' : ' ORDER BY f.uploaded_at DESC';
$sql .= ' LIMIT 300';

$rows = [];
try { $st = $db->prepare($sql); $st->execute($params); $rows = $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

// Optional reviewer name mapping
$reviewerMap = [];
if ($hasReviewer && $rows) {
  $ids = [];
  foreach ($rows as $r) { if (!empty($r['reviewed_by_employee_id'])) $ids[(int)$r['reviewed_by_employee_id']] = true; }
  if ($ids) {
    $in = implode(',', array_map('intval', array_keys($ids)));
    try {
      $q = $db->query("SELECT e.employee_id, CONCAT(u.first_name,' ',u.last_name) AS full_name FROM employees e JOIN users u ON u.user_id=e.user_id WHERE e.employee_id IN ($in)");
      while ($row = $q->fetch(PDO::FETCH_ASSOC)) { $reviewerMap[(int)$row['employee_id']] = $row['full_name']; }
    } catch (Throwable $e) {}
  }
}

include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
  <div class="max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold">Senior Architect Responses</h1>
      <a href="/Archiflow/employees/project_manager/pm_send_senior.php" class="px-3 py-2 text-sm rounded bg-white ring-1 ring-gray-200 text-gray-700 hover:bg-gray-50"><i class="fas fa-upload mr-2"></i>Send New</a>
    </div>

    <form method="get" class="mb-5 flex flex-wrap gap-3 items-end text-sm">
      <div>
        <label class="block text-xs font-medium mb-1">Status</label>
        <select name="status" class="border rounded px-2 py-1">
          <option value="">All</option>
          <?php foreach ($validStatuses as $vs): ?>
            <option value="<?php echo htmlspecialchars($vs); ?>" <?php if ($filterStatus===$vs) echo 'selected'; ?>><?php echo htmlspecialchars(str_replace('_',' ',$vs)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <label class="inline-flex items-center gap-2 text-xs">
        <input type="checkbox" name="only_responses" value="1" <?php echo $onlyResponses? 'checked' : ''; ?> onchange="this.form.submit()">
        <span>Show only Reviewed/Revisions</span>
      </label>
      <div>
        <button class="px-4 py-2 bg-slate-700 hover:bg-slate-800 text-white rounded">Filter</button>
      </div>
    </form>

    <div class="overflow-x-auto bg-white rounded-xl shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-100 text-slate-600 uppercase text-[11px] tracking-wide">
          <tr>
            <th class="py-2 px-3 text-left">File</th>
            <th class="py-2 px-3 text-left">Project</th>
            <th class="py-2 px-3 text-left">Phase</th>
            <th class="py-2 px-3 text-left">Uploaded</th>
            <?php if ($hasStatus): ?><th class="py-2 px-3 text-left">Status</th><?php endif; ?>
            <?php if ($hasSeniorComment): ?><th class="py-2 px-3 text-left">Senior Comment</th><?php endif; ?>
            <?php if ($hasReviewedAt): ?><th class="py-2 px-3 text-left">Reviewed</th><?php endif; ?>
            <th class="py-2 px-3 text-left">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="p-4 text-slate-500">No responses yet.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <?php
            $status = $r['status'] ?? null;
            $statusColor = 'bg-slate-200 text-slate-700';
            if ($status === 'pending') $statusColor='bg-amber-100 text-amber-700';
            elseif ($status==='forwarded') $statusColor='bg-sky-100 text-sky-700';
            elseif ($status==='reviewed') $statusColor='bg-green-100 text-green-700';
            elseif ($status==='revisions_requested') $statusColor='bg-red-100 text-red-700';
            $fileUrl = '/ArchiFlow/' . ($r['relative_path'] ?? ('PMuploads/' . $r['stored_name']));
            $reviewerName = '';
            if ($hasReviewer && !empty($r['reviewed_by_employee_id'])) {
              $rid = (int)$r['reviewed_by_employee_id'];
              $reviewerName = $reviewerMap[$rid] ?? '';
            }
          ?>
          <tr class="hover:bg-slate-50">
            <td class="py-2 px-3">
              <div class="font-medium text-slate-900 max-w-[220px] truncate" title="<?php echo htmlspecialchars($r['original_name']); ?>"><?php echo htmlspecialchars($r['original_name']); ?></div>
              <div class="text-[10px] text-slate-500 font-mono">#<?php echo (int)$r['id']; ?></div>
            </td>
            <td class="py-2 px-3 text-slate-700 max-w-[180px] truncate" title="<?php echo htmlspecialchars($r['project_name'] ?? ''); ?>"><?php echo htmlspecialchars($r['project_name'] ?? ''); ?></td>
            <td class="py-2 px-3 text-slate-700"><?php echo htmlspecialchars($r['design_phase'] ?? ''); ?></td>
            <td class="py-2 px-3 text-slate-600"><?php echo htmlspecialchars(date('M j, Y H:i', strtotime($r['uploaded_at']))); ?></td>
            <?php if ($hasStatus): ?><td class="py-2 px-3"><span class="px-2 py-0.5 rounded-full text-[10px] <?php echo $statusColor; ?> capitalize"><?php echo htmlspecialchars(str_replace('_',' ',$status)); ?></span></td><?php endif; ?>
            <?php if ($hasSeniorComment): ?><td class="py-2 px-3 text-slate-600 max-w-[320px] truncate" title="<?php echo htmlspecialchars($r['senior_comment'] ?? ''); ?>"><?php echo htmlspecialchars($r['senior_comment'] ?? ''); ?><?php if($reviewerName): ?><span class="text-[10px] text-slate-400 ml-2">— <?php echo htmlspecialchars($reviewerName); ?></span><?php endif; ?></td><?php endif; ?>
            <?php if ($hasReviewedAt): ?><td class="py-2 px-3 text-slate-600"><?php echo !empty($r['reviewed_at']) ? htmlspecialchars(date('M j, Y H:i', strtotime($r['reviewed_at']))) : '—'; ?></td><?php endif; ?>
            <td class="py-2 px-3">
              <div class="flex items-center gap-2">
                <a href="<?php echo htmlspecialchars($fileUrl); ?>" target="_blank" class="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">Open</a>
                <a href="<?php echo htmlspecialchars($fileUrl); ?>" download class="px-2 py-1 bg-green-600 text-white rounded text-xs hover:bg-green-700">Download</a>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="mt-4 text-[11px] text-slate-500">
      Tip: You can forward a file with revisions requested to your assigned Architect from the "Send to Senior" page.
    </div>
  </div>
</main>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

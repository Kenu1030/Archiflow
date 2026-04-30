<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: ../../login.php'); exit; }
if (($_SESSION['user_type'] ?? '') !== 'employee' || !in_array(strtolower((string)($_SESSION['position'] ?? '')), ['architect','senior_architect'])) { header('Location: ../../index.php'); exit; }
require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$projectId = (int)($_GET['project_id'] ?? 0);
// Load projects assigned to this architect (via project_users or tasks)
$uid = (int)($_SESSION['user_id'] ?? 0);
$myProjects = [];
try {
  $stP = $db->prepare("SELECT DISTINCT p.project_id, p.project_name
                        FROM projects p
                        LEFT JOIN project_users pu ON pu.project_id = p.project_id
                        LEFT JOIN tasks t ON t.project_id = p.project_id
                        WHERE pu.user_id = ? OR t.assigned_to = ?
                        ORDER BY p.project_name ASC
                        LIMIT 200");
  $stP->execute([$uid, $uid]);
  $myProjects = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $myProjects = []; }
include __DIR__ . '/../../backend/core/header.php';
?>
<main class="min-h-screen bg-gray-50 p-6">
  <div class="max-w-full">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold flex items-center gap-2"><i class="fas fa-tools text-blue-600"></i> Project Materials</h1>
      <div class="flex items-center gap-2">
        <div class="flex items-center gap-2">
          <label for="pm-project" class="text-sm text-slate-600">Project</label>
          <select id="pm-project" class="px-3 py-2 border rounded-md min-w-64">
            <option value="">Select a project…</option>
            <?php foreach ($myProjects as $p): $pid=(int)($p['project_id']??0); $pname=trim((string)($p['project_name']??('Project #'.$pid))); ?>
              <option value="<?php echo $pid; ?>" <?php echo ($pid === $projectId ? 'selected' : ''); ?>><?php echo htmlspecialchars($pname); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button id="pm-open-add" class="px-3 py-2 bg-green-600 text-white rounded-md text-sm flex items-center gap-1"><i class="fas fa-plus"></i> Add Material</button>
      </div>
    </div>

    <?php if ($projectId <= 0): ?>
      <div class="p-4 bg-yellow-50 text-yellow-800 rounded-md mb-6">Select a project from the dropdown to manage materials.</div>
    <?php endif; ?>

    <div id="pm-list" class="bg-white rounded-xl shadow-sm ring-1 ring-slate-200 overflow-hidden">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-100 text-slate-700">
          <tr>
            <th class="text-left px-3 py-2">Name</th>
            <th class="text-left px-3 py-2 w-40">Added</th>
            <th class="text-left px-3 py-2 w-32">Actions</th>
          </tr>
        </thead>
        <tbody id="pm-tbody" class="divide-y divide-slate-100"></tbody>
      </table>
      <div id="pm-empty" class="hidden p-4 text-center text-slate-500">No materials yet.</div>
    </div>
  </div>
</main>

<!-- Add Material Modal -->
<div id="pm-modal" class="hidden fixed inset-0 z-50">
  <div class="modal-overlay absolute inset-0 bg-black/40 flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-2xl rounded-xl shadow-xl overflow-hidden">
      <div class="flex items-center justify-between px-5 py-4 border-b">
        <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2"><i class="fas fa-plus-circle text-green-600"></i> Add Material to Project</h3>
        <button type="button" data-close class="text-gray-500 hover:text-gray-700"><i class="fas fa-times"></i></button>
      </div>
      <div class="p-5 space-y-6 text-sm">
        <div class="space-y-2">
          <label class="font-medium text-gray-700">Search Existing Material</label>
          <input id="pm-search" type="text" placeholder="e.g. Concrete, 12mm rebar" class="w-full px-3 py-2 border rounded-md" />
          <div id="pm-results" class="max-h-40 overflow-y-auto border rounded-md hidden"></div>
          <div id="pm-selected" class="mt-1 text-xs text-green-700 font-medium hidden"></div>
        </div>
        <div class="space-y-2">
          <label class="font-medium text-gray-700">Or New Material Name</label>
          <input id="pm-new-name" type="text" placeholder="Custom name (if not found)" class="w-full px-3 py-2 border rounded-md" />
          <p class="text-xs text-slate-500">Quantity, unit, cost, and notes have been removed per simplification request.</p>
        </div>
        <div class="flex items-center justify-end gap-3 pt-2">
          <button type="button" data-close class="px-4 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">Cancel</button>
          <button id="pm-save" type="button" class="px-4 py-2 rounded-lg bg-green-600 text-white hover:bg-green-700">Add</button>
        </div>
        <div id="pm-msg" class="text-xs text-red-600 font-medium hidden"></div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const projectId = <?php echo $projectId; ?>;
  const el = id => document.getElementById(id);
  const modal = el('pm-modal');
  const tbody = el('pm-tbody');
  const empty = el('pm-empty');
  const openBtn = el('pm-open-add');
  const msg = el('pm-msg');
  const resultsBox = el('pm-results');
  const selectedInfo = el('pm-selected');
  let chosenMaterialId = 0;

  // Project selector redirect
  const projSel = el('pm-project');
  projSel?.addEventListener('change', ()=>{
    const v = projSel.value;
    if(v){ window.location.href = `project-materials.php?project_id=${encodeURIComponent(v)}`; }
  });

  function open(){ if(modal) modal.classList.remove('hidden'); }
  function close(){ if(modal) modal.classList.add('hidden'); resetForm(); }
  function resetForm(){ chosenMaterialId=0; el('pm-search').value=''; resultsBox.innerHTML=''; resultsBox.classList.add('hidden'); el('pm-new-name').value=''; msg.classList.add('hidden'); }

  modal?.querySelectorAll('[data-close]')?.forEach(b=>b.addEventListener('click', close));
  openBtn?.addEventListener('click', ()=>{ if(projectId>0) open(); else alert('Load a project first.'); });

  async function fetchProject(){
    if(projectId<=0){ tbody.innerHTML=''; empty.classList.remove('hidden'); return; }
    try {
      const r = await fetch(`/ArchiFlow/backend/materials.php?action=list_project&project_id=${projectId}`);
      const data = await r.json();
      const items = data.materials||[];
      tbody.innerHTML='';
      if(!items.length){ empty.classList.remove('hidden'); return; } else { empty.classList.add('hidden'); }
      items.forEach(m=>{
        const tr=document.createElement('tr');
        tr.innerHTML=`<td class='px-3 py-2 font-medium text-slate-800'>${escapeHtml(m.name||'Material')}</td>
                      <td class='px-3 py-2 text-slate-500 text-xs'>${escapeHtml(m.created_at||'')}</td>
                      <td class='px-3 py-2 text-xs flex gap-2'>
                        <button data-edit='${m.id}' class='px-2 py-1 rounded bg-blue-600 text-white hover:bg-blue-700'>Edit</button>
                        <button data-delete='${m.id}' class='px-2 py-1 rounded bg-red-600 text-white hover:bg-red-700'>Delete</button>
                      </td>`;
        tbody.appendChild(tr);
      });
      // Attach actions
      tbody.querySelectorAll('button[data-delete]')?.forEach(btn=>{
        btn.addEventListener('click', async ()=>{
          if(!confirm('Delete this material entry?')) return;
          const id = btn.getAttribute('data-delete');
          try {
            const resp = await fetch('/ArchiFlow/backend/materials.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams({action:'delete_project_material', id})});
            const d = await resp.json();
            if(!d.ok){ alert(d.error||'Delete failed'); } else { fetchProject(); }
          } catch(e){ alert('Delete error'); }
        });
      });
      tbody.querySelectorAll('button[data-edit]')?.forEach(btn=>{
        btn.addEventListener('click', async ()=>{
          const id = btn.getAttribute('data-edit');
          const newName = prompt('New custom name (leave blank to keep underlying material name):','');
          if(newName===null) return;
          try {
            const form = new URLSearchParams({action:'update_project_material', id, custom_name:newName.trim()});
            const resp = await fetch('/ArchiFlow/backend/materials.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:form});
            const d = await resp.json();
            if(!d.ok){ alert(d.error||'Update failed'); } else { fetchProject(); }
          } catch(e){ alert('Update error'); }
        });
      });
    } catch(e){ console.error(e); }
  }

  function escapeHtml(str){ return str.replace(/[&<>'\"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c])); }
  function truncate(s,n){ return s.length>n? s.slice(0,n-1)+'…': s; }

  // Search existing materials
  let searchTimer=null;
  el('pm-search').addEventListener('input', ()=>{
    const term = el('pm-search').value.trim();
    chosenMaterialId=0; resultsBox.innerHTML=''; resultsBox.classList.add('hidden');
    if(searchTimer) clearTimeout(searchTimer);
    if(term===''){ return; }
    searchTimer=setTimeout(async()=>{
      try {
        const r = await fetch(`/ArchiFlow/backend/materials.php?action=search&q=${encodeURIComponent(term)}`);
        const data = await r.json();
        const list = data.results||[];
        if(!list.length){ resultsBox.innerHTML='<div class="p-2 text-xs text-slate-500">No matches.</div>'; resultsBox.classList.remove('hidden'); return; }
        resultsBox.innerHTML='';
        list.forEach(item=>{
          const div=document.createElement('div');
          div.className='px-3 py-2 cursor-pointer hover:bg-blue-50 text-sm flex justify-between';
          div.innerHTML=`<span>${escapeHtml(item.name)}</span>`;
          div.addEventListener('click',()=>{ chosenMaterialId=item.material_id; el('pm-new-name').value=''; resultsBox.classList.add('hidden'); selectedInfo.textContent='Selected existing: '+item.name; selectedInfo.classList.remove('hidden'); });
          resultsBox.appendChild(div);
        });
        resultsBox.classList.remove('hidden');
      } catch(e){ console.error(e); }
    },300);
  });

  async function save(){
    msg.classList.add('hidden');
    if(projectId<=0){ alert('Load a project first.'); return; }
    const newName= el('pm-new-name').value.trim();
    if(chosenMaterialId===0 && newName===''){ msg.textContent='Select an existing material or enter a name.'; msg.classList.remove('hidden'); return; }
    if(chosenMaterialId===0 && newName.length<2){ msg.textContent='Name too short (min 2 chars).'; msg.classList.remove('hidden'); return; }
    let materialId= chosenMaterialId;
    if(materialId===0 && newName!==''){
      // create material first
      try {
        const r = await fetch('/ArchiFlow/backend/materials.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams({action:'add_material', name:newName, default_unit:'pcs'})});
        const d = await r.json();
        if(!d.ok){ msg.textContent=d.error||'Failed creating material'; msg.classList.remove('hidden'); return; }
        materialId = d.material_id;
      } catch(e){ msg.textContent='Error creating material'; msg.classList.remove('hidden'); return; }
    }
    try {
      const form = new URLSearchParams({action:'add_project_material', project_id:String(projectId), material_id:String(materialId||0), custom_name:(materialId? '' : newName)});
      const r = await fetch('/ArchiFlow/backend/materials.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:form});
      const d = await r.json();
      if(!d.ok){ msg.textContent=d.error||'Failed adding to project'; msg.classList.remove('hidden'); return; }
      close(); fetchProject();
    } catch(e){ msg.textContent='Request failed'; msg.classList.remove('hidden'); }
  }

  el('pm-save').addEventListener('click', save);

  fetchProject();
})();
</script>
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>

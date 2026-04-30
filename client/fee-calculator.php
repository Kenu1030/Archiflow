<?php
// Client-only design fee calculator page
require_once __DIR__ . '/_client_common.php'; // Auth & session
include_once __DIR__ . '/../backend/core/header.php';
?>
<main class="p-6 space-y-6">
  <div class="flex items-center justify-between">
    <h1 class="text-xl font-semibold text-gray-900 flex items-center gap-2"><i class="fas fa-calculator text-blue-600"></i> Design Fee Calculator</h1>
    <a href="client/dashboard.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium"><i class="fas fa-arrow-left mr-1"></i>Back to Dashboard</a>
  </div>

  <div class="grid lg:grid-cols-2 gap-6">
    <!-- Calculator Card -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-6">
      <div>
        <h2 class="text-lg font-semibold text-gray-800 mb-1">Estimate Design Fees</h2>
        <p class="text-sm text-gray-500">Uses a standard baseline construction cost and professional fee group percentages. Adjust assumptions during formal proposal.</p>
      </div>

      <div class="space-y-5">
        <div class="space-y-2">
          <label class="block text-sm font-medium text-gray-700 mb-1" for="fc-area">Project Area</label>
          <div class="flex">
            <input id="fc-area" type="number" min="0" step="0.1" placeholder="Enter area or leave blank" class="flex-1 px-3 py-2 border border-gray-300 rounded-l-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
            <select id="fc-unit" class="px-3 py-2 border border-gray-300 rounded-r-md bg-gray-50 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
              <option value="sqm">sqm</option>
              <option value="sqft">sqft</option>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div class="space-y-0.5">
              <label class="text-[11px] text-gray-600">Length 1</label>
              <input id="fc-l1" type="number" min="0" step="0.1" placeholder="e.g. 10" class="w-full px-2 py-1.5 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
            </div>
            <div class="space-y-0.5">
              <label class="text-[11px] text-gray-600">Width 1</label>
              <input id="fc-w1" type="number" min="0" step="0.1" placeholder="e.g. 12" class="w-full px-2 py-1.5 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
            </div>
            <div class="space-y-0.5">
              <label class="text-[11px] text-gray-600">Length 2 (optional)</label>
              <input id="fc-l2" type="number" min="0" step="0.1" placeholder="e.g. 8" class="w-full px-2 py-1.5 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
            </div>
            <div class="space-y-0.5">
              <label class="text-[11px] text-gray-600">Width 2 (optional)</label>
              <input id="fc-w2" type="number" min="0" step="0.1" placeholder="e.g. 5" class="w-full px-2 py-1.5 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
            </div>
          </div>
          <div class="flex items-center justify-between text-[11px] text-gray-600">
            <span id="fc-auto-area" class="font-medium text-blue-600">Auto area: 0 sqm</span>
            <div class="flex gap-1.5">
              <button type="button" id="fc-use-auto" class="px-2 py-1 rounded bg-blue-600 text-white hover:bg-blue-700">Use Auto</button>
              <button type="button" id="fc-clear-area" class="px-2 py-1 rounded bg-gray-100 text-gray-700 hover:bg-gray-200">Clear Manual</button>
            </div>
          </div>
          <p class="text-[11px] text-gray-500">Leave manual area blank to auto-calc from up to two rectangles (length × width) in the selected unit.</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="fc-group">Fee Group</label>
          <select id="fc-group" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            <option value="g1">Group 1 – Simple Structures (6%)</option>
            <option value="g2">Group 2 – Moderate Complexity (7%)</option>
            <option value="g3">Group 3 – Exceptional Complexity (8%)</option>
            <option value="g4">Group 4 – Residences (10%)</option>
            <option value="g5">Group 5 – Monumental Buildings (12%)</option>
          </select>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 text-xs">
          <div class="p-3 rounded-lg bg-blue-50">
            <div class="text-[10px] text-gray-500">Standard Cost</div>
            <div id="fc-base" class="font-semibold">₱35,000 / sqm</div>
          </div>
          <div class="p-3 rounded-lg bg-green-50">
            <div class="text-[10px] text-gray-500">Area (sqm)</div>
            <div id="fc-area-out" class="font-semibold">0</div>
          </div>
          <div class="p-3 rounded-lg bg-yellow-50">
            <div class="text-[10px] text-gray-500">Project Cost</div>
            <div id="fc-project" class="font-semibold">₱0</div>
          </div>
          <div class="p-3 rounded-lg bg-purple-50">
            <div class="text-[10px] text-gray-500">Design Rate</div>
            <div id="fc-rate" class="font-semibold">0%</div>
          </div>
          <div class="p-3 rounded-lg bg-pink-50 sm:col-span-2 lg:col-span-1">
            <div class="text-[10px] text-gray-500">Design Fee</div>
            <div id="fc-fee" class="font-semibold">₱0</div>
          </div>
        </div>
        <div>
          <div class="text-xs font-medium text-gray-600 mb-1">Estimated Design Fee</div>
          <div id="fc-fee-large" class="text-3xl font-extrabold text-blue-600">₱0</div>
          <p id="fc-note" class="text-xs text-gray-500 mt-1">Enter area and select a fee group.</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <button id="fc-calc" type="button" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-md"><i class="fas fa-sync-alt mr-1"></i> Calculate</button>
          <button id="fc-reset" type="button" class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-semibold px-4 py-2 rounded-md">Reset</button>
          <button id="fc-request" type="button" class="bg-green-600 hover:bg-green-700 text-white text-sm font-semibold px-4 py-2 rounded-md"><i class="fas fa-folder-plus mr-1"></i> Request Project</button>
        </div>
        <div class="text-[11px] text-gray-400 space-y-1">
          <p><strong>Formula:</strong> Project Cost = Area × 35,000; Design Fee = Project Cost × Group %.</p>
          <p>Percentages illustrative; final fees depend on scope, consultants, and complexity.</p>
        </div>
      </div>
    </div>

    <!-- Guidance Card -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-5">
      <h2 class="text-lg font-semibold text-gray-800">Fee Group Guide</h2>
      <ul class="space-y-3 text-sm text-gray-600">
        <li><span class="font-semibold text-gray-800">Group 1 (6%) –</span> Simple structures (warehouses, sheds, utility buildings).</li>
        <li><span class="font-semibold text-gray-800">Group 2 (7%) –</span> Moderate complexity (offices, schools, stores, banks, malls).</li>
        <li><span class="font-semibold text-gray-800">Group 3 (8%) –</span> Exceptional complexity (hospitals, hotels, theaters, airports, laboratories).</li>
        <li><span class="font-semibold text-gray-800">Group 4 (10%) –</span> Residences (single-family, duplexes, townhouses).</li>
        <li><span class="font-semibold text-gray-800">Group 5 (12%) –</span> Monumental / landmark buildings (museums, memorials, exposition halls).</li>
      </ul>
      <div class="text-xs text-gray-500 border-t pt-4">Contact us for detailed proposals, consultant coordination, and phased service breakdown.</div>
    </div>
  </div>
</main>

<script>
(function(){
  const el = id => document.getElementById(id);
  const fmt = new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP',maximumFractionDigits:0});
  const STANDARD = 35000;
  const GROUPS = {
    g1:{rate:0.06,label:'Group 1 – Simple Structures'},
    g2:{rate:0.07,label:'Group 2 – Moderate Complexity'},
    g3:{rate:0.08,label:'Group 3 – Exceptional Complexity'},
    g4:{rate:0.10,label:'Group 4 – Residences'},
    g5:{rate:0.12,label:'Group 5 – Monumental Buildings'}
  };
  // Debug mode: enable with ?debug=1 in URL
  const DEBUG = window.location.search.indexOf('debug=1') !== -1;
  let debugPanel = null;
  function ensurePanel(){
    if(!DEBUG) return null;
    if(!debugPanel){
      debugPanel = document.createElement('div');
      debugPanel.id='fc-debug-panel';
      debugPanel.style.cssText='position:fixed;bottom:0;right:0;z-index:9999;background:#111;color:#0f0;font:12px/1.3 monospace;padding:8px;max-width:340px;max-height:40vh;overflow:auto;border-top-left-radius:6px;box-shadow:0 0 0 1px #0f0 inset';
      debugPanel.textContent='[FeeCalc] debug panel ready';
      document.body.appendChild(debugPanel);
    }
    return debugPanel;
  }
  function dbg(label, data){
    if(!DEBUG) return;
    console.log('[FeeCalc]', label, data);
    const p = ensurePanel();
    if(p){
      const time = new Date().toLocaleTimeString();
      const lines = [time+' '+label, JSON.stringify(data,null,2)];
      p.textContent = lines.join('\n');
    }
  }
  function updateDebug(extra){
    if(!DEBUG) return;
    const p = ensurePanel();
    if(!p) return;
    const time = new Date().toLocaleTimeString();
    const lines = [
      time + ' STATE',
      'l1='+ (el('fc-l1')?.value||'') + ' w1='+ (el('fc-w1')?.value||'') + ' l2=' + (el('fc-l2')?.value||'') + ' w2=' + (el('fc-w2')?.value||''),
      'manualArea=' + (el('fc-area')?.value||''),
      'unit=' + (el('fc-unit')?.value||''),
      'autoRaw=' + (extra.autoRaw),
      'effectiveRaw=' + (extra.effectiveRaw),
      'sqm=' + (extra.sqm),
      'projectCost=' + (extra.projectCost),
      'designFee=' + (extra.designFee),
      'group=' + (extra.group) + ' rate=' + (extra.rate)
    ];
    p.textContent = lines.join('\n');
  }
  function toSqm(v,u){v=parseFloat(v)||0;return u==='sqft'?v*0.092903:v;}
  function calc(){
    const unit=el('fc-unit')?.value||'sqm';
    const group=el('fc-group')?.value||'g1';
    const data=GROUPS[group]||GROUPS.g1;
    const manualRaw=parseFloat(el('fc-area')?.value||'0');
    const autoRaw=computeAutoArea(unit);
    const effectiveRaw=manualRaw>0?manualRaw:autoRaw;
    const sqm=toSqm(effectiveRaw>0?effectiveRaw:0,unit);
    const projectCost=sqm*STANDARD;
    const fee=projectCost*data.rate;
    const autoExtra=unit==='sqft'&&autoRaw>0?` (${toSqm(autoRaw,'sqft').toFixed(1)} sqm)`:'';
    // Optional chaining cannot be on assignment LHS; guard explicitly
    const autoAreaEl = el('fc-auto-area');
    if (autoAreaEl) autoAreaEl.textContent = `Auto area: ${autoRaw.toFixed(1)} ${unit}${autoExtra}`;
    el('fc-area-out').textContent=sqm.toFixed(1);
    el('fc-project').textContent=fmt.format(projectCost||0);
    el('fc-rate').textContent=(data.rate*100).toFixed(0)+'%';
    el('fc-fee').textContent=fmt.format(fee||0);
    el('fc-fee-large').textContent=fmt.format(fee||0);
    el('fc-note').textContent=sqm>0?`Using ${data.label} at ${(data.rate*100).toFixed(0)}% of project cost.`:'Enter area or dimensions, then select a fee group.';
    updateDebug({autoRaw,effectiveRaw,sqm,projectCost,designFee:fee,group,rate:data.rate});
  }
  function computeAutoArea(unit){
    // Parse values; treat anything non-positive as 0
    const l1 = Number(el('fc-l1')?.value || 0);
    const w1 = Number(el('fc-w1')?.value || 0);
    const l2 = Number(el('fc-l2')?.value || 0);
    const w2 = Number(el('fc-w2')?.value || 0);
    const rect1 = (l1 > 0 && w1 > 0) ? l1 * w1 : 0;
    const rect2 = (l2 > 0 && w2 > 0) ? l2 * w2 : 0;
    const total = rect1 + rect2;
    const span = el('fc-auto-area');
    if (span) {
      const extra = unit === 'sqft' && total > 0 ? ` (${(total * 0.092903).toFixed(1)} sqm)` : '';
      span.textContent = `Auto area: ${total.toFixed(1)} ${unit}${extra}`;
    }
    dbg('computeAutoArea',{l1,w1,l2,w2,rect1,rect2,total,unit});
    return total;
  }
  function reset(){
    el('fc-area').value='';
    el('fc-unit').value='sqm';
    el('fc-group').value='g1';
    calc();
  }
  function prefillRequest(){
    const unit=el('fc-unit')?.value||'sqm';
    const group=el('fc-group')?.value||'g1';
    const label=GROUPS[group]?.label||'Design Fee';
    const manualRaw=parseFloat(el('fc-area')?.value||'');
    const autoRaw=computeAutoArea(unit);
    const effectiveRaw=manualRaw>0?manualRaw:autoRaw;
    const mode=manualRaw>0?'manual':'auto';
    const areaDisplay=effectiveRaw>0?effectiveRaw.toFixed(1).replace(/\.0$/,''):'0';
    const fee=el('fc-fee')?.textContent||'₱0';
    const project=el('fc-project')?.textContent||'₱0';
    try{sessionStorage.setItem('dfc_prefill', JSON.stringify({area:areaDisplay,unit,fee,project,label,mode,t:Date.now()}));}catch(e){}
    window.location.href='client/dashboard.php#prefill-request';
  }
  // Attach both input & change to be resilient across browsers and numeric controls
  ['fc-area','fc-unit','fc-group'].forEach(id=>{
    const n = el(id); if(!n) return; ['input','change'].forEach(ev=>n.addEventListener(ev,calc));
  });
  ['fc-l1','fc-w1','fc-l2','fc-w2'].forEach(id=>{
    const n = el(id); if(!n) return; ['input','change','blur'].forEach(ev=>n.addEventListener(ev,calc));
  });
  el('fc-use-auto')?.addEventListener('click',()=>{
    const unit=el('fc-unit')?.value||'sqm';
    const autoRaw=computeAutoArea(unit);
    // Debug hint (remove after confirmation): show current dimension values in console
    try { console.log('[UseAuto] l1,w1,l2,w2,autoRaw=', el('fc-l1')?.value, el('fc-w1')?.value, el('fc-l2')?.value, el('fc-w2')?.value, autoRaw); } catch(e) {}
    if(autoRaw>0){
      // Populate manual field (override mode) and recalc
      const manualField = el('fc-area');
      manualField.value=autoRaw.toFixed(2).replace(/\.00$/,'');
      // Fire an input event so any listeners react uniformly
      manualField.dispatchEvent(new Event('input',{bubbles:true}));
      dbg('useAutoApplied',{autoRaw,valueApplied:manualField.value});
      // Focus for user clarity
      manualField.focus();
      // Visual nudge: briefly highlight area considered box
      const areaOutBox = el('fc-area-out');
      if(areaOutBox){
        areaOutBox.classList.add('bg-green-200');
        setTimeout(()=>areaOutBox.classList.remove('bg-green-200'),600);
      }
    } else {
      // Provide subtle feedback that dimensions are incomplete
      const span=el('fc-auto-area');
      if(span){
        const orig=span.textContent;
        span.textContent='Auto area: add both length & width';
        span.classList.remove('text-blue-600');
        span.classList.add('text-red-600');
        setTimeout(()=>{
          span.textContent=orig||'Auto area: 0 '+unit;
          span.classList.remove('text-red-600');
          span.classList.add('text-blue-600');
        },1800);
      }
      dbg('useAutoIncomplete',{autoRaw,unit});
    }
  });
  el('fc-clear-area')?.addEventListener('click',()=>{
    const f=el('fc-area');
    if(f){f.value='';f.removeAttribute('value');}
    // Also clear all dimension inputs
    ['fc-l1','fc-w1','fc-l2','fc-w2'].forEach(id=>{ const n=el(id); if(n){ n.value=''; n.removeAttribute('value'); } });
    // Ensure auto area refreshes immediately after clearing manual override
    computeAutoArea(el('fc-unit')?.value||'sqm');
    calc();
  });
  el('fc-calc')?.addEventListener('click',calc);
  el('fc-reset')?.addEventListener('click',reset);
  el('fc-request')?.addEventListener('click',prefillRequest);
  calc();
})();
</script>

<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>

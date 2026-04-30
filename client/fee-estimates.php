<?php
require_once __DIR__ . '/_client_common.php';

// Create estimate (modal)
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='create_estimate') {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(400); exit('Bad token'); }
  $serviceId = (int)($_POST['service_id'] ?? 0);
  $sqm = (float)($_POST['sqm'] ?? 0);
  // We don't have a table to store client estimates in the SQL dump; just compute on the fly and show.
  $_SESSION['last_estimate'] = ['service_id'=>$serviceId,'sqm'=>$sqm];
  header('Location: fee-estimates.php'); exit;
}
// Suppress footer on this page
$HIDE_FOOTER = true;
include_once __DIR__ . '/../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-8">
  <div class="max-w-full px-4 flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-semibold">Fee Estimates</h1>
      <p class="text-white/70">Estimate design costs</p>
    </div>
    <button id="openEstimate" class="bg-white text-blue-700 px-4 py-2 rounded-lg">Create Estimate</button>
  </div>
</section>
<main class="max-w-full px-4 -mt-6">
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <?php if (!empty($_SESSION['last_estimate'])): $le=$_SESSION['last_estimate']; unset($_SESSION['last_estimate']);
      $svc = $pdo->prepare('SELECT service_name, base_price, price_per_sqm FROM design_services WHERE service_id=?');
      $svc->execute([$le['service_id']]);
      $s=$svc->fetch();
      if ($s):
        $total = (float)($s['base_price'] ?? 0) + $le['sqm'] * (float)($s['price_per_sqm'] ?? 0);
    ?>
      <div class="bg-blue-50 border border-blue-200 rounded p-4 mb-4">
        <div class="font-semibold">Estimate for <?php echo htmlspecialchars($s['service_name']); ?></div>
        <div class="text-gray-600">Area: <?php echo number_format($le['sqm'],2); ?> sqm</div>
        <div class="text-gray-800 font-semibold mt-1">Estimated Total: ₱<?php echo number_format($total,2); ?></div>
      </div>
    <?php endif; endif; ?>

    <div class="overflow-x-auto">
      <table class="min-w-full">
        <thead>
          <tr class="text-left text-sm text-gray-500">
            <th class="py-2">Service</th>
            <th class="py-2">Base Price</th>
            <th class="py-2">Price / sqm</th>
          </tr>
        </thead>
        <tbody class="text-sm">
          <?php $rows=$pdo->query('SELECT service_id, service_name, base_price, price_per_sqm FROM design_services ORDER BY service_id')->fetchAll();
          if (!$rows): ?>
            <tr><td colspan="3" class="py-6 text-center text-gray-500">No services.</td></tr>
          <?php else: foreach($rows as $r): ?>
            <tr class="border-t">
              <td class="py-2"><?php echo htmlspecialchars($r['service_name']); ?></td>
              <td class="py-2 text-gray-600"><?php echo number_format((float)$r['base_price'],2); ?></td>
              <td class="py-2 text-gray-600"><?php echo number_format((float)$r['price_per_sqm'],2); ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- Create Estimate Modal -->
<div id="estimateModal" class="fixed inset-0 hidden items-center justify-center z-50">
  <div class="absolute inset-0 bg-black/50"></div>
  <div class="relative bg-white rounded-xl shadow-xl w-full max-w-lg p-6">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold">Create Estimate</h3>
      <button id="closeEstimate" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times"></i></button>
    </div>
    <form method="post" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>" />
      <input type="hidden" name="action" value="create_estimate" />
      <div>
        <label class="block text-sm font-medium text-gray-700">Service</label>
        <select name="service_id" class="mt-1 w-full border rounded-lg p-2" required>
          <option value="">Select…</option>
          <?php $svcs=$pdo->query('SELECT service_id, service_name FROM design_services ORDER BY service_name')->fetchAll(); foreach($svcs as $s): ?>
            <option value="<?php echo (int)$s['service_id']; ?>"><?php echo htmlspecialchars($s['service_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Area (sqm)</label>
        <input type="number" name="sqm" step="0.01" min="0" class="mt-1 w-full border rounded-lg p-2" required>
      </div>
      <div class="flex justify-end space-x-3">
        <button type="button" id="cancelEstimate" class="px-4 py-2 bg-gray-200 rounded-lg">Cancel</button>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg">Create</button>
      </div>
    </form>
  </div>
</div>

<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>
<script>
  const estM = document.getElementById('estimateModal');
  const open = ()=>{ estM.classList.remove('hidden'); estM.classList.add('flex'); };
  const close = ()=>{ estM.classList.add('hidden'); estM.classList.remove('flex'); };
  document.getElementById('openEstimate').addEventListener('click', open);
  document.getElementById('closeEstimate').addEventListener('click', close);
  document.getElementById('cancelEstimate').addEventListener('click', close);
</script>

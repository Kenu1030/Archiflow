<?php
// Suppress footer on this page
$HIDE_FOOTER = true;
require_once __DIR__ . '/_client_common.php';
include_once __DIR__ . '/../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-8">
  <div class="max-w-full px-4">
    <h1 class="text-2xl font-semibold">Design Gallery</h1>
    <p class="text-white/70">Browse available design services</p>
  </div>
</section>
<main class="max-w-full px-4 -mt-6">
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <div class="overflow-x-auto">
      <table class="min-w-full">
        <thead>
          <tr class="text-left text-sm text-gray-500">
            <th class="py-2">Service</th>
            <th class="py-2">Type</th>
            <th class="py-2">Base Price</th>
            <th class="py-2">Price / sqm</th>
          </tr>
        </thead>
        <tbody class="text-sm">
          <?php $rows=$pdo->query("SELECT service_id, service_name, service_type, base_price, price_per_sqm FROM design_services ORDER BY service_id")->fetchAll();
          if (!$rows): ?>
            <tr><td colspan="4" class="py-6 text-center text-gray-500">No services.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr class="border-t">
              <td class="py-2"><?php echo htmlspecialchars($r['service_name'] ?? ('Service #'.(int)$r['service_id'])); ?></td>
              <td class="py-2 text-gray-600"><?php echo htmlspecialchars($r['service_type'] ?? ''); ?></td>
              <td class="py-2 text-gray-600"><?php echo isset($r['base_price']) ? number_format((float)$r['base_price'],2) : '—'; ?></td>
              <td class="py-2 text-gray-600"><?php echo isset($r['price_per_sqm']) ? number_format((float)$r['price_per_sqm'],2) : '—'; ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>

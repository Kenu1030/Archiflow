<?php
// Suppress footer on this page
$HIDE_FOOTER = true;
require_once __DIR__ . '/_client_common.php';
include_once __DIR__ . '/../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-8">
  <div class="max-w-full px-4">
    <h1 class="text-2xl font-semibold">Contracts</h1>
    <p class="text-white/70">Your project contracts</p>
  </div>
</section>
<main class="max-w-full px-4 -mt-6">
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <div class="overflow-x-auto">
      <table class="min-w-full">
        <thead>
          <tr class="text-left text-sm text-gray-500">
            <th class="py-2">Contract</th>
            <th class="py-2">Project</th>
            <th class="py-2">Created</th>
          </tr>
        </thead>
        <tbody class="text-sm">
          <?php $rows=$pdo->query("SELECT c.contract_id, c.created_at, c.project_id FROM contracts c JOIN projects p ON p.project_id=c.project_id WHERE p.client_id=".(int)$clientId." ORDER BY c.created_at DESC")->fetchAll();
          if (!$rows): ?>
            <tr><td colspan="3" class="py-6 text-center text-gray-500">No contracts.</td></tr>
          <?php else: foreach($rows as $r): ?>
            <tr class="border-t">
              <td class="py-2">#<?php echo (int)$r['contract_id']; ?></td>
              <td class="py-2">#<?php echo (int)$r['project_id']; ?></td>
              <td class="py-2 text-gray-500"><?php echo htmlspecialchars($r['created_at']); ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>

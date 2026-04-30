<?php
// Suppress footer on this page
$HIDE_FOOTER = true;
require_once __DIR__ . '/_client_common.php';
include_once __DIR__ . '/../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-8">
  <div class="max-w-full px-4">
    <h1 class="text-2xl font-semibold">Notifications</h1>
    <p class="text-white/70">Latest updates</p>
  </div>
</section>
<main class="max-w-full px-4 -mt-6">
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <div class="overflow-x-auto">
      <table class="min-w-full">
        <thead>
          <tr class="text-left text-sm text-gray-500">
            <th class="py-2">Message</th>
            <th class="py-2">Created</th>
          </tr>
        </thead>
        <tbody class="text-sm">
          <?php $rows=$pdo->query("SELECT notification_id, created_at FROM notifications WHERE user_id=".(int)$_SESSION['user_id']." ORDER BY created_at DESC LIMIT 50")->fetchAll();
          if (!$rows): ?>
            <tr><td colspan="2" class="py-6 text-center text-gray-500">No notifications.</td></tr>
          <?php else: foreach($rows as $r): ?>
            <tr class="border-t">
              <td class="py-2">Notification #<?php echo (int)$r['notification_id']; ?></td>
              <td class="py-2 text-gray-500"><?php echo htmlspecialchars($r['created_at']); ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>

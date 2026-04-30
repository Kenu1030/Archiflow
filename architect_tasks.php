<?php
$allowed_roles = ['architect'];
include __DIR__.'/includes/auth_check.php';
include 'db.php';
$page_title = 'Architect Tasks';
include __DIR__.'/includes/header.php';
$user_id = $_SESSION['user_id'];

// Filter handling (simple status filter)
$status_filter = $_GET['status'] ?? '';
$valid_status = ['To Do','In Progress','Done'];
$where = 'assigned_to=?';
$params = [$user_id];
$types = 'i';
if($status_filter && in_array($status_filter,$valid_status)){
  $where .= ' AND status=?';
  $params[] = $status_filter;
  $types .= 's';
}
$sql = "SELECT id,title,project_id,status,created_at FROM tasks WHERE $where ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types,...$params);
$stmt->execute();
$res = $stmt->get_result();

// Get task counts for each status
$task_counts = ['To Do' => 0, 'In Progress' => 0, 'Done' => 0];
$count_sql = "SELECT status, COUNT(*) as count FROM tasks WHERE assigned_to=? GROUP BY status";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param('i', $user_id);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
while ($row = $count_result->fetch_assoc()) {
    if (isset($task_counts[$row['status']])) {
        $task_counts[$row['status']] = $row['count'];
    }
}
$count_stmt->close();
?>

<!-- Architect Tasks -->
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-purple-50 flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header Section -->
    <div class="mb-8">
      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
        <div>
          <div class="inline-flex items-center px-4 py-2 bg-orange-100 text-orange-800 rounded-full text-sm font-medium mb-4">
            <i class="fas fa-clipboard-list mr-2"></i>Architect Tasks
          </div>
          <h1 class="text-3xl md:text-4xl font-black text-gray-900 mb-2">
            My Tasks
          </h1>
          <p class="text-lg text-gray-600 max-w-2xl">Track and manage all your assigned tasks across projects</p>
        </div>

        <!-- Quick Actions -->
        <div class="flex flex-wrap gap-3">
          <a href="architect_dashboard.php" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
          </a>
          <a href="architect_projects.php" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-folder-open mr-2"></i>My Projects
          </a>
        </div>
      </div>
    </div>

    <!-- Task Statistics -->
    <div class="mb-8">
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
        <?php
        $total_tasks = array_sum($task_counts);
        $stat_items = [
          ['label' => 'Total Tasks', 'value' => $total_tasks, 'icon' => 'fa-clipboard-list', 'color' => 'text-blue-600', 'bg' => 'bg-blue-100'],
          ['label' => 'To Do', 'value' => $task_counts['To Do'], 'icon' => 'fa-circle', 'color' => 'text-red-600', 'bg' => 'bg-red-100'],
          ['label' => 'In Progress', 'value' => $task_counts['In Progress'], 'icon' => 'fa-clock', 'color' => 'text-yellow-600', 'bg' => 'bg-yellow-100'],
          ['label' => 'Done', 'value' => $task_counts['Done'], 'icon' => 'fa-check', 'color' => 'text-green-600', 'bg' => 'bg-green-100'],
        ];
        foreach ($stat_items as $s): ?>
          <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6 hover:shadow-lg hover:bg-white transition-all duration-200">
            <div class="flex items-center justify-between">
              <div>
                <div class="text-2xl font-bold text-gray-900 mb-1"><?php echo $s['value']; ?></div>
                <div class="text-sm text-gray-600 font-medium"><?php echo $s['label']; ?></div>
              </div>
              <div class="w-12 h-12 <?php echo $s['bg']; ?> rounded-xl flex items-center justify-center">
                <i class="fas <?php echo $s['icon']; ?> <?php echo $s['color']; ?>"></i>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Filter Section -->
    <div class="mb-6">
      <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
        <form method="get" class="flex flex-col sm:flex-row gap-4 items-start sm:items-center">
          <div class="flex items-center gap-3">
            <label class="text-sm font-medium text-gray-700">Filter by Status:</label>
            <select name="status" onchange="this.form.submit()"
                    class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
              <option value="">All Tasks</option>
              <?php foreach($valid_status as $s): ?>
                <option value="<?= $s ?>" <?= $s===$status_filter?'selected':''; ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if($status_filter): ?>
            <a href="architect_tasks.php" class="inline-flex items-center px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
              <i class="fas fa-times mr-2"></i>Clear Filter
            </a>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- Tasks Table -->
    <?php if($res && $res->num_rows): ?>
      <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead class="bg-gray-50/80">
              <tr>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Task</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Project</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
              <?php while($t=$res->fetch_assoc()):
                $status_colors = [
                  'To Do' => ['bg' => 'bg-red-100', 'text' => 'text-red-800'],
                  'In Progress' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800'],
                  'Done' => ['bg' => 'bg-green-100', 'text' => 'text-green-800']
                ];
                $status_class = $status_colors[$t['status']] ?? ['bg' => 'bg-gray-100', 'text' => 'text-gray-800'];
              ?>
                <tr class="hover:bg-gray-50/80 transition-colors">
                  <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">#<?php echo $t['id']; ?></td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($t['title']); ?></div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                    Project #<?php echo $t['project_id']; ?>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_class['bg'] . ' ' . $status_class['text']; ?>">
                      <?php echo htmlspecialchars($t['status']); ?>
                    </span>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                    <?php echo date('M d, Y', strtotime($t['created_at'])); ?>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <a href="project_details.php?project_id=<?php echo $t['project_id']; ?>#task-<?php echo $t['id']; ?>"
                       class="inline-flex items-center px-3 py-1 bg-blue-600 text-white text-xs rounded-lg hover:bg-blue-700 transition-colors">
                      <i class="fas fa-eye mr-1"></i>View
                    </a>
                  </td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php else: ?>
      <!-- Empty State -->
      <div class="text-center py-12">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <i class="fas fa-clipboard-list text-gray-400 text-2xl"></i>
        </div>
        <h3 class="text-lg font-semibold text-gray-900 mb-2">
          <?php echo $status_filter ? 'No ' . strtolower($status_filter) . ' tasks found' : 'No tasks found'; ?>
        </h3>
        <p class="text-gray-600 mb-6">
          <?php echo $status_filter ? 'You don\'t have any tasks with status "' . $status_filter . '"' : 'You haven\'t been assigned any tasks yet.'; ?>
        </p>
        <?php if($status_filter): ?>
          <a href="architect_tasks.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            <i class="fas fa-list mr-2"></i>View All Tasks
          </a>
        <?php else: ?>
          <a href="architect_dashboard.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>

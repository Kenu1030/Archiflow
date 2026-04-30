<?php
$allowed_roles = ['architect'];
include __DIR__.'/includes/auth_check.php';
include 'db.php';
$page_title = 'Architect Activity';
include __DIR__.'/includes/header.php';
$user_id = $_SESSION['user_id'];

// Fetch combined recent activity (tasks + file uploads) similar to dashboard but fuller list
$activity = [];
$task_stmt = $conn->prepare("SELECT id,title,status,created_at,project_id FROM tasks WHERE assigned_to=? ORDER BY created_at DESC LIMIT 50");
$task_stmt->bind_param('i',$user_id);
$task_stmt->execute();
$task_res = $task_stmt->get_result();
while($row=$task_res->fetch_assoc()){
  $activity[] = [ 'type'=>'task', 'title'=>$row['title'], 'status'=>$row['status'], 'dt'=>$row['created_at'], 'id'=>$row['id'], 'project_id'=>$row['project_id'] ];
}
$task_stmt->close();

$file_stmt = $conn->prepare("SELECT id,file_name,file_path,uploaded_at,project_id FROM project_files WHERE uploaded_by=? ORDER BY uploaded_at DESC LIMIT 50");
$file_stmt->bind_param('i',$user_id);
$file_stmt->execute();
$file_res = $file_stmt->get_result();
while($row=$file_res->fetch_assoc()){
  $activity[] = [ 'type'=>'file', 'file_name'=>$row['file_name'], 'file_path'=>$row['file_path'], 'dt'=>$row['uploaded_at'], 'project_id'=>$row['project_id'] ];
}
$file_stmt->close();

usort($activity,function($a,$b){ return strtotime($b['dt']) - strtotime($a['dt']); });

// Get activity stats
$activity_stats = [
  'tasks' => count(array_filter($activity, function($item) { return $item['type'] === 'task'; })),
  'files' => count(array_filter($activity, function($item) { return $item['type'] === 'file'; })),
  'total' => count($activity)
];
?>

<!-- Architect Activity -->
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-purple-50 flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header Section -->
    <div class="mb-8">
      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
        <div>
          <div class="inline-flex items-center px-4 py-2 bg-indigo-100 text-indigo-800 rounded-full text-sm font-medium mb-4">
            <i class="fas fa-clock mr-2"></i>Architect Activity
          </div>
          <h1 class="text-3xl md:text-4xl font-black text-gray-900 mb-2">
            Recent Activity
          </h1>
          <p class="text-lg text-gray-600 max-w-2xl">Track all your recent tasks and file uploads across projects</p>
        </div>

        <!-- Quick Actions -->
        <div class="flex flex-wrap gap-3">
          <a href="architect_dashboard.php" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
          </a>
          <a href="architect_tasks.php" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-clipboard-list mr-2"></i>My Tasks
          </a>
          <a href="architect_projects.php" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-folder-open mr-2"></i>My Projects
          </a>
        </div>
      </div>
    </div>

    <!-- Activity Statistics -->
    <div class="mb-8">
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
        <?php
        $stat_items = [
          ['label' => 'Total Activity', 'value' => $activity_stats['total'], 'icon' => 'fa-clock', 'color' => 'text-blue-600', 'bg' => 'bg-blue-100'],
          ['label' => 'Tasks', 'value' => $activity_stats['tasks'], 'icon' => 'fa-clipboard-list', 'color' => 'text-orange-600', 'bg' => 'bg-orange-100'],
          ['label' => 'Files Uploaded', 'value' => $activity_stats['files'], 'icon' => 'fa-file-arrow-up', 'color' => 'text-purple-600', 'bg' => 'bg-purple-100'],
          ['label' => 'This Week', 'value' => count(array_filter($activity, function($item) {
            return strtotime($item['dt']) >= strtotime('monday this week');
          })), 'icon' => 'fa-calendar-week', 'color' => 'text-green-600', 'bg' => 'bg-green-100'],
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

    <!-- Activity Feed -->
    <?php if($activity): ?>
      <div class="space-y-4">
        <?php foreach($activity as $index => $item):
          $is_recent = strtotime($item['dt']) >= strtotime('-7 days');
          $status_colors = [
            'To Do' => ['bg' => 'bg-red-100', 'text' => 'text-red-800', 'icon' => 'fa-circle'],
            'In Progress' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800', 'icon' => 'fa-clock'],
            'Done' => ['bg' => 'bg-green-100', 'text' => 'text-green-800', 'icon' => 'fa-check']
          ];
        ?>
          <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6 hover:shadow-lg hover:bg-white transition-all duration-200 <?php echo $is_recent ? 'ring-2 ring-blue-100' : ''; ?>">
            <div class="flex items-start gap-4">
              <!-- Activity Icon -->
              <div class="flex-shrink-0">
                <?php if($item['type']==='task'): ?>
                  <div class="w-12 h-12 bg-orange-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-tasks text-orange-600"></i>
                  </div>
                <?php else: ?>
                  <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-file-arrow-up text-purple-600"></i>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Activity Content -->
              <div class="flex-1 min-w-0">
                <div class="flex items-start justify-between">
                  <div class="flex-1">
                    <?php if($item['type']==='task'): ?>
                      <div class="flex items-center gap-3 mb-2">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium <?php echo ($status_colors[$item['status']] ?? ['bg' => 'bg-gray-100', 'text' => 'text-gray-800'])['bg'] . ' ' . ($status_colors[$item['status']] ?? ['bg' => 'bg-gray-100', 'text' => 'text-gray-800'])['text']; ?>">
                          <i class="fas <?php echo ($status_colors[$item['status']] ?? ['icon' => 'fa-circle'])['icon']; ?> mr-1 text-xs"></i>
                          <?php echo htmlspecialchars($item['status']); ?>
                        </span>
                        <span class="text-sm text-gray-500">Project #<?php echo $item['project_id']; ?></span>
                      </div>
                      <h3 class="text-lg font-semibold text-gray-900 mb-1"><?php echo htmlspecialchars($item['title']); ?></h3>
                      <p class="text-sm text-gray-600">Task assigned and updated</p>
                    <?php else: ?>
                      <div class="flex items-center gap-3 mb-2">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                          <i class="fas fa-file mr-1 text-xs"></i>
                          File Upload
                        </span>
                        <span class="text-sm text-gray-500">Project #<?php echo $item['project_id']; ?></span>
                      </div>
                      <h3 class="text-lg font-semibold text-gray-900 mb-1">
                        <a href="<?php echo htmlspecialchars($item['file_path']); ?>" target="_blank" class="text-blue-600 hover:text-blue-800 hover:underline">
                          <?php echo htmlspecialchars($item['file_name']); ?>
                        </a>
                      </h3>
                      <p class="text-sm text-gray-600">File uploaded to project</p>
                    <?php endif; ?>
                  </div>

                  <!-- Timestamp -->
                  <div class="flex-shrink-0 text-right">
                    <div class="text-sm text-gray-500">
                      <?php echo date('M d, Y', strtotime($item['dt'])); ?>
                    </div>
                    <div class="text-xs text-gray-400">
                      <?php echo date('H:i', strtotime($item['dt'])); ?>
                    </div>
                    <?php if($is_recent): ?>
                      <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 mt-2">
                        <i class="fas fa-star mr-1"></i>Recent
                      </span>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Action Button -->
                <div class="mt-4">
                  <a href="project_details.php?project_id=<?php echo $item['project_id']; ?><?php echo $item['type'] === 'task' ? '#task-' . $item['id'] : ''; ?>"
                     class="inline-flex items-center px-3 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200 transition-colors">
                    <i class="fas fa-eye mr-2"></i>
                    <?php echo $item['type'] === 'task' ? 'View Task' : 'View Project'; ?>
                  </a>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <!-- Empty State -->
      <div class="text-center py-12">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <i class="fas fa-clock text-gray-400 text-2xl"></i>
        </div>
        <h3 class="text-lg font-semibold text-gray-900 mb-2">No Recent Activity</h3>
        <p class="text-gray-600 mb-6">Your activity timeline will appear here as you work on tasks and upload files.</p>
        <div class="flex flex-col sm:flex-row gap-3 justify-center">
          <a href="architect_tasks.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            <i class="fas fa-clipboard-list mr-2"></i>View Tasks
          </a>
          <a href="architect_dashboard.php" class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
            <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
          </a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>

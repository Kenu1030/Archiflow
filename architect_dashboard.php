<?php
// Unified auth & layout header
$allowed_roles = ['architect'];
include __DIR__.'/includes/auth_check.php';
$full_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'User';
include 'db.php';
$page_title = 'Architect Dashboard';
include __DIR__.'/includes/header.php';

// Get dashboard stats
$user_id = $_SESSION['user_id'];
$stats = [];

// Total projects
$stats['total_projects'] = $conn->query("SELECT COUNT(*) as count FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id AND pu.role_in_project = 'architect'")->fetch_assoc()['count'];

// Active projects
$stats['active_projects'] = $conn->query("SELECT COUNT(*) as count FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id AND pu.role_in_project = 'architect' AND p.status = 'active'")->fetch_assoc()['count'];

// Completed projects
$stats['completed_projects'] = $conn->query("SELECT COUNT(*) as count FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id AND pu.role_in_project = 'architect' AND p.status = 'completed'")->fetch_assoc()['count'];

// Total tasks
$task_stats = $conn->query("SELECT status, COUNT(*) as count FROM tasks WHERE assigned_to = $user_id GROUP BY status")->fetch_assoc();
$stats['total_tasks'] = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE assigned_to = $user_id")->fetch_assoc()['count'];

// Recent activity count
$stats['recent_activity'] = $conn->query("SELECT COUNT(*) as count FROM (
    SELECT id FROM tasks WHERE assigned_to = $user_id AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    UNION ALL
    SELECT id FROM project_files WHERE uploaded_by = $user_id AND uploaded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
) as combined")->fetch_assoc()['count'];
?>
<!-- Architect Dashboard -->
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-purple-50 flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header Section -->
    <div class="mb-8">
      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
        <div>
          <div class="inline-flex items-center px-4 py-2 bg-blue-100 text-blue-800 rounded-full text-sm font-medium mb-4">
            <i class="fas fa-drafting-compass mr-2"></i>Architect Portal
          </div>
          <h1 class="text-3xl md:text-4xl font-black text-gray-900 mb-2">
            Welcome back, <span class="bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent"><?php echo htmlspecialchars($full_name); ?></span>
          </h1>
          <p class="text-lg text-gray-600 max-w-2xl">Design, collaborate, and manage your architectural projects with precision and creativity.</p>
        </div>

        <!-- Quick Actions -->
        <div class="flex flex-wrap gap-3">
          <a href="#projects" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-folder-open mr-2"></i>My Projects
          </a>
          <a href="#tasks" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-tasks mr-2"></i>My Tasks
          </a>
          <a href="#activity" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-clock mr-2"></i>Activity
          </a>
        </div>
      </div>
    </div>

    <!-- Stats Overview -->
    <section class="mb-12" id="stats">
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
        <?php
        $stat_items = [
          ['label' => 'Total Projects', 'value' => $stats['total_projects'], 'icon' => 'fa-layer-group', 'color' => 'text-blue-600', 'bg' => 'bg-blue-100'],
          ['label' => 'Active Projects', 'value' => $stats['active_projects'], 'icon' => 'fa-play-circle', 'color' => 'text-emerald-600', 'bg' => 'bg-emerald-100'],
          ['label' => 'Completed', 'value' => $stats['completed_projects'], 'icon' => 'fa-check-circle', 'color' => 'text-purple-600', 'bg' => 'bg-purple-100'],
          ['label' => 'Total Tasks', 'value' => $stats['total_tasks'], 'icon' => 'fa-clipboard-list', 'color' => 'text-orange-600', 'bg' => 'bg-orange-100'],
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
    </section>

    <!-- Main Content -->
    <div class="grid gap-8 lg:grid-cols-2">
      <!-- Left Column -->
      <div class="space-y-6">
        <!-- Task Overview -->
        <section id="tasks">
          <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-6">
              <div>
                <h2 class="text-xl font-bold text-gray-900 flex items-center gap-3">
                  <div class="w-10 h-10 bg-orange-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-clipboard-list text-orange-600"></i>
                  </div>
                  My Tasks
                </h2>
                <p class="text-sm text-gray-600 mt-1">Track your task progress and deadlines</p>
              </div>
            </div>

            <?php
            $task_stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM tasks WHERE assigned_to = ? GROUP BY status");
            $task_stmt->bind_param('i', $user_id);
            $task_stmt->execute();
            $task_result = $task_stmt->get_result();
            $task_counts = ['To Do' => 0, 'In Progress' => 0, 'Done' => 0];
            while ($row = $task_result->fetch_assoc()) {
                $status = $row['status'];
                if (isset($task_counts[$status])) {
                    $task_counts[$status] = $row['count'];
                }
            }
            $task_stmt->close();
            ?>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="bg-gradient-to-br from-red-50 to-red-100 rounded-lg p-4 border border-red-200">
                <div class="flex items-center justify-between">
                  <div>
                    <div class="text-2xl font-bold text-red-600"><?php echo $task_counts['To Do']; ?></div>
                    <div class="text-sm text-red-700 font-medium">To Do</div>
                  </div>
                  <div class="w-10 h-10 bg-red-200 rounded-lg flex items-center justify-center">
                    <i class="fas fa-circle text-red-600"></i>
                  </div>
                </div>
              </div>

              <div class="bg-gradient-to-br from-yellow-50 to-yellow-100 rounded-lg p-4 border border-yellow-200">
                <div class="flex items-center justify-between">
                  <div>
                    <div class="text-2xl font-bold text-yellow-600"><?php echo $task_counts['In Progress']; ?></div>
                    <div class="text-sm text-yellow-700 font-medium">In Progress</div>
                  </div>
                  <div class="w-10 h-10 bg-yellow-200 rounded-lg flex items-center justify-center">
                    <i class="fas fa-clock text-yellow-600"></i>
                  </div>
                </div>
              </div>

              <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg p-4 border border-green-200">
                <div class="flex items-center justify-between">
                  <div>
                    <div class="text-2xl font-bold text-green-600"><?php echo $task_counts['Done']; ?></div>
                    <div class="text-sm text-green-700 font-medium">Done</div>
                  </div>
                  <div class="w-10 h-10 bg-green-200 rounded-lg flex items-center justify-center">
                    <i class="fas fa-check text-green-600"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- Recent Activity -->
        <section id="activity">
          <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-6">
              <div>
                <h2 class="text-xl font-bold text-gray-900 flex items-center gap-3">
                  <div class="w-10 h-10 bg-indigo-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-clock text-indigo-600"></i>
                  </div>
                  Recent Activity
                </h2>
                <p class="text-sm text-gray-600 mt-1">Latest tasks and file uploads</p>
              </div>
            </div>

            <div class="space-y-4">
              <?php
              $activity = [];
              $task_stmt = $conn->prepare("SELECT id, title, status, created_at FROM tasks WHERE assigned_to = ? ORDER BY created_at DESC LIMIT 8");
              $task_stmt->bind_param('i', $user_id);
              $task_stmt->execute();
              $task_result = $task_stmt->get_result();
              while ($row = $task_result->fetch_assoc()) {
                  $activity[] = [
                      'type' => 'task',
                      'title' => $row['title'],
                      'status' => $row['status'],
                      'created_at' => $row['created_at'],
                      'id' => $row['id']
                  ];
              }
              $task_stmt->close();

              $file_stmt = $conn->prepare("SELECT file_name, file_path, uploaded_at, project_id FROM project_files WHERE uploaded_by = ? ORDER BY uploaded_at DESC LIMIT 8");
              $file_stmt->bind_param('i', $user_id);
              $file_stmt->execute();
              $file_result = $file_stmt->get_result();
              while ($row = $file_result->fetch_assoc()) {
                  $activity[] = [
                      'type' => 'file',
                      'file_name' => $row['file_name'],
                      'file_path' => $row['file_path'],
                      'uploaded_at' => $row['uploaded_at'],
                      'project_id' => $row['project_id']
                  ];
              }
              $file_stmt->close();

              usort($activity, function($a, $b) {
                  $dateA = isset($a['created_at']) ? $a['created_at'] : $a['uploaded_at'];
                  $dateB = isset($b['created_at']) ? $b['created_at'] : $b['uploaded_at'];
                  return strtotime($dateB) - strtotime($dateA);
              });

              $shown = 0;
              foreach ($activity as $item):
                  if ($shown >= 6) break;
              ?>
                <div class="flex items-start gap-4 p-4 bg-gray-50/80 rounded-lg hover:bg-white hover:shadow-sm transition-all duration-200">
                  <?php if ($item['type'] == 'task'): ?>
                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                      <i class="fas fa-tasks text-blue-600"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm text-gray-900">
                        <span class="font-semibold"><?php echo htmlspecialchars($item['title']); ?></span>
                        <span class="text-xs text-gray-500 ml-2">(<?php echo htmlspecialchars($item['status']); ?>)</span>
                      </p>
                      <p class="text-xs text-gray-500 mt-1">
                        <i class="far fa-clock mr-1"></i><?php echo date('M d, Y \a\t H:i', strtotime($item['created_at'])); ?>
                      </p>
                    </div>
                  <?php elseif ($item['type'] == 'file'): ?>
                    <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                      <i class="fas fa-file-arrow-up text-purple-600"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm text-gray-900">
                        Uploaded <span class="font-semibold"><?php echo htmlspecialchars($item['file_name']); ?></span>
                      </p>
                      <p class="text-xs text-gray-500 mt-1">
                        <i class="far fa-clock mr-1"></i><?php echo date('M d, Y \a\t H:i', strtotime($item['uploaded_at'])); ?>
                      </p>
                    </div>
                  <?php endif; ?>
                </div>
              <?php
                $shown++;
              endforeach;

              if ($shown == 0):
              ?>
                <div class="text-center py-8">
                  <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
                    <i class="fas fa-clock text-gray-400"></i>
                  </div>
                  <p class="text-sm text-gray-600">No recent activity</p>
                  <p class="text-xs text-gray-500 mt-1">Activity will appear here as you work on projects</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </section>
      </div>

      <!-- Right Column -->
      <div class="space-y-6">
        <!-- My Projects -->
        <section id="projects">
          <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-6">
              <div>
                <h2 class="text-xl font-bold text-gray-900 flex items-center gap-3">
                  <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-folder-tree text-blue-600"></i>
                  </div>
                  My Projects
                </h2>
                <p class="text-sm text-gray-600 mt-1">Manage your architectural projects</p>
              </div>
            </div>
            <?php
            include 'db.php';
            $user_id = $_SESSION['user_id'];
            // Get projects assigned to this architect
            $stmt = $conn->prepare("SELECT p.id, p.project_name, p.description, p.status FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = ? AND pu.role_in_project = 'architect'");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $modals = [];
            if ($result && $result->num_rows > 0) {
                echo '<table style="width:100%;border-collapse:collapse;margin:20px 0;">';
                echo '<tr><th>ID</th><th>Name</th><th>Description</th><th>Status</th><th>Progress</th><th style="text-align:center;">Actions</th></tr>';
                while ($row = $result->fetch_assoc()) {
                    // Project progress analytics
                    $project_id = $row['id'];
                    // Get total and completed tasks for this project
                    $task_stats_stmt = $conn->prepare("SELECT COUNT(*) as total, SUM(status = 'Done') as completed FROM tasks WHERE project_id = ?");
                    $task_stats_stmt->bind_param('i', $project_id);
                    $task_stats_stmt->execute();
                    $task_stats_stmt->bind_result($total_tasks, $completed_tasks);
                    $task_stats_stmt->fetch();
                    $task_stats_stmt->close();
                    $progress = ($total_tasks > 0) ? round(($completed_tasks / $total_tasks) * 100) : 0;
                    echo '<tr>';
                    echo '<td>' . $row['id'] . '</td>';
                    echo '<td>' . htmlspecialchars($row['project_name']) . '</td>';
                    echo '<td>' . htmlspecialchars($row['description']) . '</td>';
                    echo '<td>' . htmlspecialchars($row['status']) . '</td>';
                    // Progress bar
                    echo '<td style="min-width:120px;">';
                    echo '<div style="background:#eee;border-radius:6px;height:18px;width:100%;position:relative;">';
                    echo '<div style="background:#00b4d8;height:18px;border-radius:6px;width:' . $progress . '%;transition:width 0.4s;"></div>';
                    echo '<span style="position:absolute;left:50%;top:0;transform:translateX(-50%);font-size:12px;color:#222;line-height:18px;">' . $progress . '%</span>';
                    echo '</div>';
                    echo '<span style="font-size:11px;color:#888;">' . $completed_tasks . ' of ' . $total_tasks . ' tasks completed</span>';
                    echo '</td>';
                    echo '<td style="text-align:center;display:flex;flex-direction:column;gap:4px;align-items:center;">';
                    echo '<a href="project_details.php?project_id=' . $row['id'] . '" class="btn sm" style="text-decoration:none;">Details</a>';
                    echo '<a href="project_details.php?project_id=' . $row['id'] . '#tasks" style="color:#00b4d8;font-size:.7rem;">View Tasks</a>';
                    echo '</td></tr>';
                    // (Removed upload plans modal - use task-based submissions instead)
                    echo '</tr>';
                }
                echo '</table>';
            } else {
                echo '<p>No projects assigned.</p>';
            }
            $stmt->close();
            ?>
        </div>
    </div>
    <!-- Render all modals outside the dashboard container -->
    <?php foreach ($modals as $modalHtml) { echo $modalHtml; } ?>
    <script>
    // (Removed upload modal and validation functionality - use task-based submissions instead)
    </script>
<?php include __DIR__.'/includes/footer.php'; ?>

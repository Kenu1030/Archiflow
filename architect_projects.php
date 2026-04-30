<?php
$allowed_roles = ['architect'];
include __DIR__.'/includes/auth_check.php';
include 'db.php';
$page_title = 'Architect Projects';
include __DIR__.'/includes/header.php';
$user_id = $_SESSION['user_id'];

// Fetch projects where this user is architect
$stmt = $conn->prepare("SELECT p.id, p.project_name, p.status, p.description FROM projects p JOIN project_users pu ON p.id=pu.project_id WHERE pu.user_id=? AND pu.role_in_project='architect' ORDER BY p.id DESC");
$stmt->bind_param('i',$user_id);
$stmt->execute();
$res = $stmt->get_result();
?>

<!-- Architect Projects -->
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-purple-50 flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header Section -->
    <div class="mb-8">
      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
        <div>
          <div class="inline-flex items-center px-4 py-2 bg-blue-100 text-blue-800 rounded-full text-sm font-medium mb-4">
            <i class="fas fa-drafting-compass mr-2"></i>Architect Projects
          </div>
          <h1 class="text-3xl md:text-4xl font-black text-gray-900 mb-2">
            My Projects
          </h1>
          <p class="text-lg text-gray-600 max-w-2xl">Manage and track progress on all your architectural projects</p>
        </div>

        <!-- Quick Actions -->
        <div class="flex flex-wrap gap-3">
          <a href="architect_dashboard.php" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
          </a>
          <a href="architect_tasks.php" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-tasks mr-2"></i>My Tasks
          </a>
        </div>
      </div>
    </div>

    <!-- Projects Grid -->
    <?php if($res && $res->num_rows): ?>
      <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
        <?php while($p=$res->fetch_assoc()): ?>
          <?php
            $pid = $p['id'];
            // Task stats
            $tstmt = $conn->prepare("SELECT COUNT(*) total, SUM(status='Done') done FROM tasks WHERE project_id=?");
            $tstmt->bind_param('i',$pid);
            $tstmt->execute();
            $tstmt->bind_result($ttotal,$tdone);
            $tstmt->fetch();
            $tstmt->close();
            $progress = $ttotal>0 ? round(($tdone/$ttotal)*100) : 0;

            $status_colors = [
              'active' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-800', 'border' => 'border-emerald-200'],
              'completed' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-800', 'border' => 'border-blue-200'],
              'pending' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-800', 'border' => 'border-amber-200'],
              'on_hold' => ['bg' => 'bg-orange-100', 'text' => 'text-orange-800', 'border' => 'border-orange-200']
            ];
            $status_class = $status_colors[$p['status']] ?? ['bg' => 'bg-gray-100', 'text' => 'text-gray-800', 'border' => 'border-gray-200'];
          ?>

          <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6 hover:shadow-lg hover:bg-white transition-all duration-200">
            <div class="flex items-start justify-between mb-4">
              <div class="flex-1 min-w-0">
                <h3 class="font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($p['project_name']); ?></h3>
                <p class="text-sm text-gray-600 mt-1 line-clamp-2"><?php echo htmlspecialchars($p['description']); ?></p>
              </div>
              <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium <?php echo $status_class['bg'] . ' ' . $status_class['text']; ?> ml-4">
                <?php echo ucfirst(str_replace('_', ' ', $p['status'])); ?>
              </span>
            </div>

            <div class="space-y-4">
              <!-- Task Progress -->
              <div>
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm font-medium text-gray-700">Progress</span>
                  <span class="text-sm text-gray-600"><?php echo $tdone; ?>/<?php echo $ttotal; ?> tasks</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                  <div class="bg-gradient-to-r from-blue-500 to-purple-600 h-2 rounded-full transition-all duration-300" style="width: <?php echo $progress; ?>%"></div>
                </div>
                <div class="text-right mt-1">
                  <span class="text-sm font-medium text-gray-900"><?php echo $progress; ?>%</span>
                </div>
              </div>

              <!-- Actions -->
              <div class="flex gap-2">
                <a href="project_details.php?project_id=<?php echo $pid; ?>" class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors">
                  <i class="fas fa-eye mr-1"></i>View
                </a>
                <a href="project_details.php?project_id=<?php echo $pid; ?>#tasks" class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200 transition-colors">
                  <i class="fas fa-tasks mr-1"></i>Tasks
                </a>
              </div>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    <?php else: ?>
      <!-- Empty State -->
      <div class="text-center py-12">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <i class="fas fa-folder-open text-gray-400 text-2xl"></i>
        </div>
        <h3 class="text-lg font-semibold text-gray-900 mb-2">No Projects Yet</h3>
        <p class="text-gray-600 mb-6">You haven't been assigned to any projects yet.</p>
        <a href="architect_dashboard.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
          <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
        </a>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>

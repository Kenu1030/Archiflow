<?php
// Assign Architects to Projects
$allowed_roles = ['project_manager'];
include __DIR__ . '/includes/auth_check.php';
include 'db.php';
$full_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'User';

// Assign architect to project (via project_users table, avoid duplicates)
if (isset($_POST['assign_architect'])) {
    $project_id = (int)($_POST['project_id'] ?? 0);
    $architect_id = (int)($_POST['architect_id'] ?? 0);
    
    if ($project_id && $architect_id) {
        // Ensure selected user is an architect
        $role_chk = $conn->prepare("SELECT user_type, position FROM users WHERE user_id=? LIMIT 1");
        $role_chk->bind_param('i', $architect_id);
        $role_chk->execute();
        $role_chk->bind_result($u_user_type, $u_position);
        $role_chk->fetch();
        $role_chk->close();
        
  // Must be an architect (employee, position contains architect) and NOT a senior architect (PMs cannot assign seniors)
  if ($u_user_type !== 'employee' || stripos((string)$u_position, 'architect') === false) {
            $message = '<div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-lg text-amber-800 flex items-center">'
                     . '<i class="fas fa-triangle-exclamation mr-3"></i><span>Selected user is not an architect.</span></div>';
    } elseif (stripos((string)$u_position,'senior') !== false && stripos((string)$u_position,'architect') !== false) {
      // Explicitly block senior architect assignment from PM page
      $message = '<div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 flex items-center">'
           . '<i class="fas fa-ban mr-3"></i><span>Project Managers cannot assign Senior Architects. Please request a Senior Architect assignment from a Senior Architect or admin.</span></div>';
        } else {
            // Check duplicate in project_users
            $dup = $conn->prepare("SELECT id FROM project_users WHERE project_id=? AND user_id=?");
            $dup->bind_param('ii', $project_id, $architect_id);
            $dup->execute();
            $dup->store_result();
            
            if ($dup->num_rows === 0) {
        // Always insert as Architect (PMs cannot assign senior architects)
        $role_to_insert = 'Architect';
        $ins = $conn->prepare("INSERT INTO project_users (project_id, user_id, role_in_project) VALUES (?,?, ?)");
        $ins->bind_param('iis', $project_id, $architect_id, $role_to_insert);
                $ins->execute();
                $ins->close();
                $message = '<div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-800 flex items-center">'
                         . '<i class="fas fa-check-circle mr-3"></i><span>Architect assigned to project successfully!</span></div>';
            } else {
                $message = '<div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-lg text-amber-800 flex items-center">'
                         . '<i class="fas fa-triangle-exclamation mr-3"></i><span>Architect is already assigned to this project.</span></div>';
            }
            $dup->close();
        }
    } else {
        $message = '<div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-800 flex items-center">'
                 . '<i class="fas fa-exclamation-circle mr-3"></i><span>Please select both project and architect.</span></div>';
    }
}

include __DIR__ . '/backend/core/header.php';

// Create project_users table if it doesn't exist
$create_table_sql = "
CREATE TABLE IF NOT EXISTS `project_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_in_project` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
";
$conn->query($create_table_sql);

// Get all projects (exclude deleted if column exists)
$has_is_deleted = false;
$col_chk = $conn->query("SHOW COLUMNS FROM projects LIKE 'is_deleted'");
if ($col_chk && $col_chk->num_rows > 0) { $has_is_deleted = true; }
if ($col_chk) { $col_chk->close(); }

$project_sql = "SELECT project_id as id, project_name, status FROM projects";
if ($has_is_deleted) {
  $project_sql .= " WHERE (is_deleted IS NULL OR is_deleted = 0)";
}
$project_sql .= " ORDER BY project_name";
$projects_result = $conn->query($project_sql);

// Get all architects EXCLUDING Senior Architects (PM cannot assign seniors)
$architects_result = $conn->query("SELECT user_id, first_name, last_name, position FROM users WHERE user_type = 'employee' AND LOWER(position) LIKE '%architect%' AND LOWER(position) NOT LIKE '%senior%' ORDER BY first_name, last_name");

// Get current project assignments
// Current assignments (exclude deleted projects if column exists)
$assignments_query = "
    SELECT p.project_id as project_id, p.project_name, u.user_id, u.first_name, u.last_name, u.position AS u_position, pu.role_in_project
    FROM projects p
    LEFT JOIN project_users pu 
      ON p.project_id = pu.project_id 
     AND (LOWER(pu.role_in_project) LIKE '%architect%')
    LEFT JOIN users u 
      ON pu.user_id = u.user_id 
     AND u.user_type = 'employee' 
     AND LOWER(u.position) LIKE '%architect%'";
if ($has_is_deleted) {
    $assignments_query .= "\n    WHERE (p.is_deleted IS NULL OR p.is_deleted = 0)";
}
$assignments_query .= "\n    ORDER BY p.project_name, u.first_name, u.last_name";
$assignments_result = $conn->query($assignments_query);
?>

<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-indigo-50 flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header Section -->
    <div class="mb-8">
      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
        <div>
          <div class="inline-flex items-center px-4 py-2 bg-blue-100 text-blue-800 rounded-full text-sm font-medium mb-4">
            <i class="fas fa-drafting-compass mr-2"></i>Team Management
          </div>
          <h1 class="text-3xl md:text-4xl font-black text-gray-900 mb-2">
            Assign <span class="bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent">Architects</span>
          </h1>
          <p class="text-lg text-gray-600 max-w-2xl">Assign talented architects to your projects and build winning teams.</p>
        </div>

        <!-- Quick Actions -->
        <div class="flex flex-wrap gap-3">
          <a href="pm_dashboard.php" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
          </a>
          <a href="#assignments" class="inline-flex items-center px-4 py-2 bg-white/80 backdrop-blur-sm border border-gray-200 rounded-lg text-gray-700 hover:bg-white hover:border-blue-300 transition-all duration-200">
            <i class="fas fa-list mr-2"></i>View Assignments
          </a>
        </div>
      </div>
    </div>

    <?php if (isset($message)): ?>
      <?php echo $message; ?>
    <?php endif; ?>

    <!-- Main Content -->
    <div class="grid gap-8 lg:grid-cols-2">
      <!-- Left Column - Assignment Form -->
      <div class="space-y-6">
        <section>
          <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-6">
              <h2 class="text-xl font-bold text-gray-900 flex items-center gap-3">
                <i class="fas fa-plus-circle text-blue-600"></i>
                Assign New Architect
              </h2>
            </div>

            <form method="post" class="space-y-6">
              <!-- Project Selection -->
              <div>
                <label for="project_id" class="block text-sm font-medium text-gray-700 mb-2">
                  <i class="fas fa-project-diagram mr-2 text-blue-600"></i>Select Project
                </label>
                <select name="project_id" id="project_id" required 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                  <option value="">Choose a project...</option>
                  <?php while ($project = $projects_result->fetch_assoc()): ?>
                    <option value="<?php echo $project['id']; ?>">
                      <?php echo htmlspecialchars($project['project_name']); ?> 
                      <span class="text-gray-500">(<?php echo ucfirst($project['status']); ?>)</span>
                    </option>
                  <?php endwhile; ?>
                </select>
              </div>

              <!-- Architect Selection -->
              <div>
                <label for="architect_id" class="block text-sm font-medium text-gray-700 mb-2">
                  <i class="fas fa-drafting-compass mr-2 text-blue-600"></i>Select Architect
                </label>
                <select name="architect_id" id="architect_id" required 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                  <option value="">Choose an architect...</option>
                  <?php while ($architect = $architects_result->fetch_assoc()): ?>
                    <?php $pos_lower = strtolower($architect['position'] ?? ''); ?>
                    <option value="<?php echo $architect['user_id']; ?>">
                      <?php echo htmlspecialchars($architect['first_name'] . ' ' . $architect['last_name'] . ' — Architect'); ?>
                    </option>
                  <?php endwhile; ?>
                </select>
              </div>

              <!-- Submit Button -->
              <div class="flex gap-3">
                <button type="submit" name="assign_architect" 
                        class="flex-1 bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-4 py-2 rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all duration-200 flex items-center justify-center gap-2">
                  <i class="fas fa-plus"></i>
                  Assign Architect
                </button>
              </div>
            </form>
          </div>
        </section>
      </div>

      <!-- Right Column - Current Assignments -->
      <div class="space-y-6">
        <section id="assignments">
          <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-6">
              <h2 class="text-xl font-bold text-gray-900 flex items-center gap-3">
                <i class="fas fa-users text-indigo-600"></i>
                Current Assignments
              </h2>
            </div>

            <div class="space-y-4 max-h-96 overflow-y-auto">
              <?php 
              $assignments_result->data_seek(0);
              $current_project = null;
              $has_assignments = false;
              
              while ($assignment = $assignments_result->fetch_assoc()): 
                $has_assignments = true;
                if ($current_project !== $assignment['project_id']): 
                  if ($current_project !== null): ?>
                    </div></div>
                  <?php endif; ?>
                  <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <div class="bg-gray-50 px-4 py-3 border-b border-gray-200">
                      <h3 class="font-semibold text-gray-900">
                        <i class="fas fa-folder mr-2 text-blue-600"></i>
                        <?php echo htmlspecialchars($assignment['project_name']); ?>
                      </h3>
                    </div>
                    <div class="p-4 space-y-2">
                  <?php $current_project = $assignment['project_id']; ?>
                <?php endif; ?>
                
                <?php if ($assignment['user_id']): ?>
                  <div class="flex items-center justify-between py-2 px-3 bg-blue-50 rounded-lg">
                    <div class="flex items-center gap-3">
                      <i class="fas fa-drafting-compass text-blue-600"></i>
                      <span class="font-medium text-gray-900">
                        <?php echo htmlspecialchars($assignment['first_name'] . ' ' . $assignment['last_name']); ?>
                      </span>
                    </div>
                    <?php 
                      // Compute display role: show Senior Architect if either stored role or position indicates seniority
                      $role_l = strtolower((string)($assignment['role_in_project'] ?? ''));
                      $pos_l  = strtolower((string)($assignment['u_position'] ?? ''));
                      $is_architect = (strpos($role_l, 'architect') !== false) || (strpos($pos_l, 'architect') !== false);
                      $is_senior_arch = ((strpos($role_l, 'senior') !== false && strpos($role_l, 'architect') !== false)
                                      || (strpos($pos_l, 'senior') !== false && strpos($pos_l, 'architect') !== false));

                      if ($is_senior_arch) {
                        $display_role = 'Senior Architect';
                      } elseif ($is_architect) {
                        $display_role = 'Architect';
                      } elseif (!empty($assignment['role_in_project'])) {
                        $display_role = ucwords($assignment['role_in_project']);
                      } else {
                        $display_role = '';
                      }
                    ?>
                    <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs font-medium">
                      <?php echo htmlspecialchars($display_role); ?>
                    </span>
                  </div>
                <?php else: ?>
                  <div class="py-2 px-3 text-gray-500 italic text-sm">
                    <i class="fas fa-info-circle mr-2"></i>No architects assigned yet
                  </div>
                <?php endif; ?>
              <?php endwhile; ?>
              
              <?php if ($current_project !== null): ?>
                    </div>
                  </div>
              <?php endif; ?>
              
              <?php if (!$has_assignments): ?>
                <div class="text-center py-8 text-gray-500">
                  <i class="fas fa-inbox text-4xl mb-4 text-gray-300"></i>
                  <p>No project assignments found.</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </section>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/backend/core/footer.php'; ?>
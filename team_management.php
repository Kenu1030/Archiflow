<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header('Location: login.php');
    exit();
}
$full_name = $_SESSION['full_name'];
$user_id = $_SESSION['user_id'];
include 'db.php';
// Server-side handlers (previously only in manager_dashboard.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Assign team member
    if (isset($_POST['assign_team_member'])) {
        $project_id = intval($_POST['project_id'] ?? 0);
        $member_id = intval($_POST['member_id'] ?? 0);
        $role_in_project = trim($_POST['role_in_project'] ?? '');
        if ($project_id && $member_id && $role_in_project) {
            $check = $conn->prepare("SELECT id FROM project_users WHERE project_id = ? AND user_id = ?");
            $check->bind_param('ii', $project_id, $member_id);
            $check->execute();
            $res = $check->get_result();
            if ($res->num_rows === 0) {
                $stmt = $conn->prepare("INSERT INTO project_users (project_id, user_id, role_in_project) VALUES (?, ?, ?)");
                $stmt->bind_param('iis', $project_id, $member_id, $role_in_project);
                $stmt->execute();
                $stmt->close();
                $success_msg = 'Team member assigned successfully!';
            } else {
                $error_msg = 'User already assigned to this project.';
            }
            $check->close();
        } else {
            $error_msg = 'All fields are required.';
        }
    }
    // Remove team member
    if (isset($_POST['remove_team_member'])) {
        $project_id = intval($_POST['remove_project_id'] ?? 0);
        $member_id = intval($_POST['remove_member_id'] ?? 0);
        if ($project_id && $member_id) {
            $stmt = $conn->prepare("DELETE FROM project_users WHERE project_id = ? AND user_id = ?");
            $stmt->bind_param('ii', $project_id, $member_id);
            if ($stmt->execute()) {
                $success_msg = 'Team member removed.';
            } else {
                $error_msg = 'Removal failed.';
            }
            $stmt->close();
        }
    }
    // Edit team member role
    if (isset($_POST['edit_team_member_role'])) {
        $project_id = intval($_POST['edit_project_id'] ?? 0);
        $member_id = intval($_POST['edit_member_id'] ?? 0);
        $new_role = trim($_POST['new_role_in_project'] ?? '');
        if ($project_id && $member_id && $new_role) {
            $stmt = $conn->prepare("UPDATE project_users SET role_in_project = ? WHERE project_id = ? AND user_id = ?");
            $stmt->bind_param('sii', $new_role, $project_id, $member_id);
            if ($stmt->execute()) {
                $success_msg = 'Role updated.';
            } else {
                $error_msg = 'Update failed.';
            }
            $stmt->close();
        }
    }
}
$page_title = 'Team Management';
include __DIR__ . '/includes/header.php';
?>

<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-purple-50 flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    <div class="w-full max-w-5xl mx-auto">
      <!-- Header -->
      <div class="mb-6">
        <div class="inline-flex items-center px-4 py-2 bg-blue-100 text-blue-800 rounded-full text-sm font-medium mb-3">
          <i class="fas fa-users-cog mr-2"></i>Team Management
        </div>
        <h1 class="text-2xl md:text-3xl font-black text-gray-900">Manage your project team</h1>
        <p class="text-gray-600 mt-1">Assign, edit roles, and keep your team organized.</p>
      </div>

      <?php if (isset($success_msg)): ?>
        <div class="mb-4 w-full flex items-center gap-3 bg-green-50 border border-green-200 rounded-lg px-4 py-3 text-green-800">
          <i class="fas fa-check-circle text-green-500"></i>
          <span class="font-medium"><?php echo htmlspecialchars($success_msg); ?></span>
        </div>
      <?php endif; ?>
      <?php if (isset($error_msg)): ?>
        <div class="mb-4 w-full flex items-center gap-3 bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-red-800">
          <i class="fas fa-exclamation-circle text-red-500"></i>
          <span class="font-medium"><?php echo htmlspecialchars($error_msg); ?></span>
        </div>
      <?php endif; ?>

      <!-- Assign Team Member Form -->
      <div class="w-full bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6 mb-8">
        <h2 class="text-lg font-bold text-gray-900 mb-4">Assign Team Member to Project</h2>
        <form method="post" class="w-full space-y-4">
          <div class="w-full grid gap-4 sm:grid-cols-3">
            <div class="w-full">
              <label class="block text-sm font-medium text-gray-700 mb-2">Project</label>
              <select name="project_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">Select Project</option>
                <?php
                $manager_projects = $conn->query("SELECT p.id, p.project_name FROM projects p JOIN project_users pu ON p.id = pu.project_id WHERE pu.user_id = $user_id AND pu.role_in_project LIKE '%manager%'");
                while ($proj = $manager_projects->fetch_assoc()):
                ?>
                  <option value="<?php echo $proj['id']; ?>"><?php echo htmlspecialchars($proj['project_name']); ?></option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="w-full">
              <label class="block text-sm font-medium text-gray-700 mb-2">Team Member</label>
              <select name="member_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">Select Team Member</option>
                <?php
                $team_members = $conn->query("SELECT id, full_name, role FROM users WHERE role != 'client' AND status = 'approved' AND id != $user_id");
                while ($member = $team_members->fetch_assoc()):
                ?>
                  <option value="<?php echo $member['id']; ?>"><?php echo htmlspecialchars($member['full_name']) . ' (' . ucfirst(str_replace('_', ' ', $member['role'])) . ')'; ?></option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="w-full">
              <label class="block text-sm font-medium text-gray-700 mb-2">Role in Project</label>
              <input type="text" name="role_in_project" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
            </div>
          </div>
          <div class="w-full flex items-center justify-end">
            <button type="submit" name="assign_team_member" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
              <i class="fas fa-user-plus mr-2"></i>Assign Team Member
            </button>
          </div>
        </form>
      </div>

      <!-- Current Team Overview -->
      <div class="w-full bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-lg font-bold text-gray-900">Current Team Members</h2>
          <span class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded-full">Latest</span>
        </div>
        <?php
        $team_overview = $conn->query("SELECT p.project_name, u.full_name, u.role, pu.role_in_project, pu.project_id, pu.user_id FROM project_users pu JOIN projects p ON pu.project_id = p.id JOIN users u ON pu.user_id = u.id JOIN project_users pu2 ON p.id = pu2.project_id WHERE pu2.user_id = $user_id AND pu2.role_in_project LIKE '%manager%' AND pu.user_id != $user_id ORDER BY p.project_name, u.full_name");
        if ($team_overview && $team_overview->num_rows > 0):
        ?>
          <div class="w-full overflow-x-auto">
            <table class="w-full min-w-full">
              <thead class="bg-gray-50/80">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Project</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Team Member</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Main Role</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Project Role</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200">
                <?php while ($member = $team_overview->fetch_assoc()): ?>
                <tr class="hover:bg-gray-50/80">
                  <td class="px-4 py-3 text-sm text-gray-900"><?php echo htmlspecialchars($member['project_name']); ?></td>
                  <td class="px-4 py-3 text-sm text-gray-900"><?php echo htmlspecialchars($member['full_name']); ?></td>
                  <td class="px-4 py-3 text-sm text-gray-600"><?php echo ucfirst(str_replace('_', ' ', $member['role'])); ?></td>
                  <td class="px-4 py-3 text-sm text-gray-900">
                    <?php if (isset($_POST['edit_mode']) && $_POST['edit_project_id'] == $member['project_id'] && $_POST['edit_member_id'] == $member['user_id']): ?>
                      <form method="post" class="inline-flex w-full max-w-xs gap-2">
                        <input type="hidden" name="edit_project_id" value="<?php echo $member['project_id']; ?>">
                        <input type="hidden" name="edit_member_id" value="<?php echo $member['user_id']; ?>">
                        <input type="text" name="new_role_in_project" value="<?php echo htmlspecialchars($member['role_in_project']); ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
                        <button type="submit" name="edit_team_member_role" class="inline-flex items-center px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-xs">Save</button>
                      </form>
                    <?php else: ?>
                      <?php echo htmlspecialchars($member['role_in_project']); ?>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-3 text-sm">
                    <div class="flex items-center gap-2">
                      <form method="post" class="inline" onsubmit="return confirm('Remove this team member from project?');">
                        <input type="hidden" name="remove_project_id" value="<?php echo $member['project_id']; ?>">
                        <input type="hidden" name="remove_member_id" value="<?php echo $member['user_id']; ?>">
                        <button type="submit" name="remove_team_member" class="inline-flex items-center px-3 py-1.5 bg-red-600 text-white rounded-lg hover:bg-red-700 text-xs">Remove</button>
                      </form>
                      <form method="post" class="inline">
                        <input type="hidden" name="edit_mode" value="1">
                        <input type="hidden" name="edit_project_id" value="<?php echo $member['project_id']; ?>">
                        <input type="hidden" name="edit_member_id" value="<?php echo $member['user_id']; ?>">
                        <button type="submit" class="inline-flex items-center px-3 py-1.5 bg-amber-500 text-white rounded-lg hover:bg-amber-600 text-xs">Edit</button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="w-full text-center py-10">
            <div class="w-16 h-16 bg-gray-100 rounded-full mx-auto flex items-center justify-center mb-3"><i class="fas fa-users text-gray-400"></i></div>
            <p class="text-gray-600">No team members assigned yet.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

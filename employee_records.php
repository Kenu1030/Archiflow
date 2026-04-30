```php
<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['hr', 'administrator'])) {
    header('Location: login.php');
    exit();
}
include 'db.php';
// Handle employee record updates
if (isset($_POST['update_employee'])) {
    $user_id = intval($_POST['user_id']);
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $status = $_POST['status'];
    
    $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, status = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $full_name, $email, $role, $status, $user_id);
    $stmt->execute();
    $stmt->close();
    $success_msg = "Employee record updated successfully!";
}
// Handle search and filtering
$search_name = isset($_GET['search_name']) ? trim($_GET['search_name']) : '';
$search_role = isset($_GET['search_role']) ? $_GET['search_role'] : '';
$search_status = isset($_GET['search_status']) ? $_GET['search_status'] : '';
$query = "SELECT id, full_name, username, email, role, status, created_at FROM users WHERE role != 'client'";
$params = [];
$types = "";
if ($search_name) {
    $query .= " AND full_name LIKE ?";
    $params[] = "%" . $search_name . "%";
    $types .= "s";
}
if ($search_role) {
    $query .= " AND role = ?";
    $params[] = $search_role;
    $types .= "s";
}
if ($search_status) {
    $query .= " AND status = ?";
    $params[] = $search_status;
    $types .= "s";
}
$query .= " ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$page_title = 'Employee Records Management';
include __DIR__.'/includes/header.php';
?>

<div class="min-h-screen bg-gradient-to-br from-blue-900 via-blue-800 to-indigo-900">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header Section -->
    <div class="mb-10">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
          <h1 class="text-3xl font-bold text-white mb-2">Employee Records Management</h1>
          <p class="text-blue-200">Manage and view all employee information</p>
        </div>
        <div class="mt-4 md:mt-0">
          <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-medium bg-blue-700 text-blue-100">
            <i class="fas fa-users-cog mr-2"></i>HR Management
          </span>
        </div>
      </div>
    </div>

    <!-- Success Message -->
    <?php if (isset($success_msg)): ?>
      <div class="mb-6 p-4 bg-green-500/20 border border-green-400/30 rounded-lg text-green-100 flex items-center">
        <i class="fas fa-check-circle mr-3"></i>
        <span><?php echo htmlspecialchars($success_msg); ?></span>
      </div>
    <?php endif; ?>

    <!-- Statistics Section -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
      <?php
      $total_employees = $conn->query("SELECT COUNT(*) as count FROM users WHERE role != 'client'")->fetch_assoc()['count'];
      $approved_employees = $conn->query("SELECT COUNT(*) as count FROM users WHERE role != 'client' AND status = 'approved'")->fetch_assoc()['count'];
      $pending_employees = $conn->query("SELECT COUNT(*) as count FROM users WHERE role != 'client' AND status = 'pending'")->fetch_assoc()['count'];
      $rejected_employees = $conn->query("SELECT COUNT(*) as count FROM users WHERE role != 'client' AND status = 'rejected'")->fetch_assoc()['count'];
      ?>
      <div class="bg-white/10 backdrop-blur-sm rounded-xl p-6 border border-white/10 shadow-lg">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-3xl font-bold text-white"><?php echo $total_employees; ?></div>
            <div class="text-sm text-blue-200 mt-1">Total Employees</div>
          </div>
          <div class="p-3 rounded-lg bg-blue-500/20">
            <i class="fas fa-users text-blue-400 text-xl"></i>
          </div>
        </div>
      </div>
      
      <div class="bg-white/10 backdrop-blur-sm rounded-xl p-6 border border-white/10 shadow-lg">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-3xl font-bold text-white"><?php echo $approved_employees; ?></div>
            <div class="text-sm text-blue-200 mt-1">Approved</div>
          </div>
          <div class="p-3 rounded-lg bg-green-500/20">
            <i class="fas fa-user-check text-green-400 text-xl"></i>
          </div>
        </div>
      </div>
      
      <div class="bg-white/10 backdrop-blur-sm rounded-xl p-6 border border-white/10 shadow-lg">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-3xl font-bold text-white"><?php echo $pending_employees; ?></div>
            <div class="text-sm text-blue-200 mt-1">Pending</div>
          </div>
          <div class="p-3 rounded-lg bg-yellow-500/20">
            <i class="fas fa-user-clock text-yellow-400 text-xl"></i>
          </div>
        </div>
      </div>
      
      <div class="bg-white/10 backdrop-blur-sm rounded-xl p-6 border border-white/10 shadow-lg">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-3xl font-bold text-white"><?php echo $rejected_employees; ?></div>
            <div class="text-sm text-blue-200 mt-1">Rejected</div>
          </div>
          <div class="p-3 rounded-lg bg-red-500/20">
            <i class="fas fa-user-times text-red-400 text-xl"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Search and Filter Section -->
    <div class="bg-white/10 backdrop-blur-sm rounded-xl p-6 border border-white/10 shadow-lg mb-10">
      <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
        <i class="fas fa-search mr-2 text-blue-300"></i>Search & Filter Employees
      </h3>
      <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <input type="text" name="search_name" placeholder="Search by name..." value="<?php echo htmlspecialchars($search_name); ?>" 
               class="px-4 py-2 bg-white/10 border border-white/20 rounded-lg text-white placeholder-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-500">
        <select name="search_role" class="px-4 py-2 bg-white/10 border border-white/20 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">All Roles</option>
          <option value="administrator" <?php echo $search_role == 'administrator' ? 'selected' : ''; ?>>Administrator</option>
          <option value="architect" <?php echo $search_role == 'architect' ? 'selected' : ''; ?>>Architect</option>
          <option value="project_manager" <?php echo $search_role == 'project_manager' ? 'selected' : ''; ?>>Project Manager</option>
          <option value="hr" <?php echo $search_role == 'hr' ? 'selected' : ''; ?>>HR</option>
          <option value="contractor" <?php echo $search_role == 'contractor' ? 'selected' : ''; ?>>Contractor</option>
          <option value="manager" <?php echo $search_role == 'manager' ? 'selected' : ''; ?>>Manager</option>
        </select>
        <select name="search_status" class="px-4 py-2 bg-white/10 border border-white/20 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">All Statuses</option>
          <option value="approved" <?php echo $search_status == 'approved' ? 'selected' : ''; ?>>Approved</option>
          <option value="pending" <?php echo $search_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
          <option value="rejected" <?php echo $search_status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
        </select>
        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors flex items-center justify-center">
          <i class="fas fa-search mr-2"></i> Search
        </button>
      </form>
    </div>

    <!-- Employee Records Table -->
    <div class="bg-white/10 backdrop-blur-sm rounded-xl p-6 border border-white/10 shadow-lg overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-white/10">
          <thead>
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">ID</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Name</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Username</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Email</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Role</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Status</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Joined Date</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-blue-200 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/10">
            <?php while ($row = $result->fetch_assoc()): ?>
            <tr class="hover:bg-white/5">
              <td class="px-6 py-4 whitespace-nowrap text-sm text-white"><?php echo $row['id']; ?></td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center">
                  <div class="flex-shrink-0 h-10 w-10">
                    <div class="h-10 w-10 rounded-full bg-blue-500/20 flex items-center justify-center">
                      <span class="text-blue-300 font-medium"><?php echo strtoupper(substr($row['full_name'], 0, 1)); ?></span>
                    </div>
                  </div>
                  <div class="ml-4">
                    <div class="text-sm font-medium text-white"><?php echo htmlspecialchars($row['full_name']); ?></div>
                  </div>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-200"><?php echo htmlspecialchars($row['username']); ?></td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-200"><?php echo htmlspecialchars($row['email']); ?></td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-white"><?php echo ucfirst(str_replace('_', ' ', $row['role'])); ?></td>
              <td class="px-6 py-4 whitespace-nowrap">
                <?php 
                $statusClass = '';
                switch($row['status']) {
                  case 'approved': $statusClass = 'bg-green-500/20 text-green-300'; break;
                  case 'pending': $statusClass = 'bg-yellow-500/20 text-yellow-300'; break;
                  case 'rejected': $statusClass = 'bg-red-500/20 text-red-300'; break;
                }
                ?>
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                  <?php echo ucfirst($row['status']); ?>
                </span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-200"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
              <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($row)); ?>)" class="px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                  <i class="fas fa-edit mr-1"></i> Edit
                </button>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      
      <div class="mt-6 text-center">
        <a href="hr_dashboard.php" class="inline-flex items-center px-4 py-2 bg-white/10 hover:bg-white/20 text-blue-200 rounded-lg transition-colors">
          <i class="fas fa-arrow-left mr-2"></i> Back to HR Dashboard
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Edit Employee Modal -->
<div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
    <div class="px-6 py-4 border-b border-gray-200">
      <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-gray-900">
          <i class="fas fa-user-edit text-blue-600 mr-2"></i>Edit Employee Record
        </h3>
        <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-500">
          <i class="fas fa-times"></i>
        </button>
      </div>
    </div>
    <form method="post" class="px-6 py-4">
      <input type="hidden" name="user_id" id="edit_user_id">
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
          <input type="text" name="full_name" id="edit_full_name" required 
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input type="email" name="email" id="edit_email" required 
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
          <select name="role" id="edit_role" required 
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            <option value="administrator">Administrator</option>
            <option value="architect">Architect</option>
            <option value="project_manager">Project Manager</option>
            <option value="hr">HR</option>
            <option value="contractor">Contractor</option>
            <option value="manager">Manager</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
          <select name="status" id="edit_status" required 
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            <option value="approved">Approved</option>
            <option value="pending">Pending</option>
            <option value="rejected">Rejected</option>
          </select>
        </div>
      </div>
      <div class="px-6 py-4 bg-gray-50 flex justify-end space-x-3">
        <button type="button" onclick="closeEditModal()" 
                class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
          Cancel
        </button>
        <button type="submit" name="update_employee" 
                class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
          Update Employee
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditModal(employee) {
    document.getElementById('edit_user_id').value = employee.id;
    document.getElementById('edit_full_name').value = employee.full_name;
    document.getElementById('edit_email').value = employee.email;
    document.getElementById('edit_role').value = employee.role;
    document.getElementById('edit_status').value = employee.status;
    document.getElementById('editModal').classList.remove('hidden');
    document.getElementById('editModal').classList.add('flex');
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
    document.getElementById('editModal').classList.remove('flex');
}

// Close modal when clicking outside of it
window.onclick = function(event) {
    const modal = document.getElementById('editModal');
    if (event.target === modal) {
        closeEditModal();
    }
}

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeEditModal();
    }
});
</script>

<?php include __DIR__.'/includes/footer.php'; ?>

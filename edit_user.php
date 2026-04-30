<?php
// edit_user.php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'administrator') {
    header('Location: login.php');
    exit();
}
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: admin_dashboard.php');
    exit();
}
$user_id = intval($_GET['id']);
include 'db.php';
// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $stmt = $conn->prepare("UPDATE users SET full_name=?, username=?, email=?, role=? WHERE id=?");
    $stmt->bind_param("ssssi", $full_name, $username, $email, $role, $user_id);
    $stmt->execute();
    $stmt->close();
    header('Location: admin_dashboard.php?edited=1');
    exit();
}
// Fetch user data
$stmt = $conn->prepare("SELECT full_name, username, email, role FROM users WHERE id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($full_name, $username, $email, $role);
$stmt->fetch();
$stmt->close();
$page_title = 'Edit User';
include __DIR__.'/includes/header.php';
?>

<div class="min-h-screen bg-gradient-to-br from-blue-900 via-blue-800 to-indigo-900 flex items-center justify-center p-4">
  <div class="w-full max-w-md">
    <div class="bg-white/10 backdrop-blur-sm rounded-2xl shadow-xl p-8 border border-white/10">
      <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-white mb-2">Edit User</h1>
        <p class="text-blue-200">Update user information</p>
      </div>
      
      <form method="post" class="space-y-6">
        <div>
          <label for="full_name" class="block text-sm font-medium text-blue-200 mb-2">Full Name</label>
          <input type="text" name="full_name" id="full_name" value="<?php echo htmlspecialchars($full_name); ?>" required 
                 class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
        </div>
        
        <div>
          <label for="username" class="block text-sm font-medium text-blue-200 mb-2">Username</label>
          <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($username); ?>" required 
                 class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
        </div>
        
        <div>
          <label for="email" class="block text-sm font-medium text-blue-200 mb-2">Email</label>
          <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($email); ?>" required 
                 class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
        </div>
        
        <div>
          <label for="role" class="block text-sm font-medium text-blue-200 mb-2">Role</label>
          <select name="role" id="role" required 
                  class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            <option value="administrator" <?php if($role=='administrator') echo 'selected'; ?>>Administrator</option>
            <option value="architect" <?php if($role=='architect') echo 'selected'; ?>>Architect</option>
            <option value="project_manager" <?php if($role=='project_manager') echo 'selected'; ?>>Project Manager</option>
            <option value="hr" <?php if($role=='hr') echo 'selected'; ?>>Human Resources (HR)</option>
            <option value="client" <?php if($role=='client') echo 'selected'; ?>>Client</option>
            <option value="contractor" <?php if($role=='contractor') echo 'selected'; ?>>Contractor / Site Engineer</option>
            <option value="manager" <?php if($role=='manager') echo 'selected'; ?>>Manager</option>
          </select>
        </div>
        
        <div class="flex flex-col space-y-4">
          <button type="submit" class="w-full py-3 px-4 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-medium rounded-lg transition-all duration-300 transform hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
            Save Changes
          </button>
          
          <a href="admin_dashboard.php" class="w-full py-3 px-4 text-center bg-white/10 hover:bg-white/20 text-blue-200 font-medium rounded-lg transition-colors duration-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
            Cancel
          </a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__.'/includes/footer.php'; ?>
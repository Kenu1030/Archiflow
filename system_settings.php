<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'administrator') {
    header('Location: login.php');
    exit();
}

// Handle form submission
if (isset($_POST['save_settings'])) {
    $site_name = trim($_POST['site_name']);
    $default_role = $_POST['default_role'];
    
    // Here you would typically save these settings to a database table or config file
    // For demonstration purposes, we'll just show a success message
    $success_msg = "Settings saved successfully!";
}

$page_title = 'System Settings';
include __DIR__.'/includes/header.php';
?>

<div class="min-h-screen bg-gradient-to-br from-blue-900 via-blue-800 to-indigo-900 flex items-center justify-center p-4">
  <div class="bg-white/10 backdrop-blur-sm rounded-xl shadow-2xl p-8 w-full max-w-md border border-white/10">
    <div class="text-center mb-8">
      <h1 class="text-3xl font-bold text-white mb-2">System Settings</h1>
      <p class="text-blue-200">Configure system preferences</p>
    </div>
    
    <?php if (isset($success_msg)): ?>
      <div class="mb-6 p-4 bg-green-500/20 border border-green-400/30 rounded-lg text-green-100 flex items-center">
        <i class="fas fa-check-circle mr-3"></i>
        <span><?php echo htmlspecialchars($success_msg); ?></span>
      </div>
    <?php endif; ?>
    
    <form method="post" class="space-y-6">
      <div>
        <label for="site_name" class="block text-sm font-medium text-blue-200 mb-2">Site Name</label>
        <input type="text" name="site_name" id="site_name" 
               value="<?php echo isset($_POST['site_name']) ? htmlspecialchars($_POST['site_name']) : 'Capstone Project Management'; ?>" 
               class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
      </div>
      
      <div>
        <label for="default_role" class="block text-sm font-medium text-blue-200 mb-2">Default User Role</label>
        <select name="default_role" id="default_role" 
                class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
          <option value="client">Client</option>
          <option value="architect">Architect</option>
          <option value="project_manager">Project Manager</option>
          <option value="hr">HR</option>
          <option value="contractor">Contractor</option>
        </select>
      </div>
      
      <div class="pt-4">
        <button type="submit" name="save_settings" 
                class="w-full py-3 px-4 bg-gradient-to-r from-blue-600 to-indigo-700 hover:from-blue-700 hover:to-indigo-800 text-white font-medium rounded-lg transition-all duration-300 transform hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
          <i class="fas fa-save mr-2"></i> Save Settings
        </button>
      </div>
    </form>
    
    <div class="mt-6 text-center">
      <a href="admin_dashboard.php" 
         class="inline-flex items-center px-4 py-2 bg-white/10 hover:bg-white/20 text-blue-200 rounded-lg transition-colors">
        <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
      </a>
    </div>
  </div>
</div>

<?php include __DIR__.'/includes/footer.php'; ?>
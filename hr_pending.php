<?php
$allowed_roles=['hr'];
include __DIR__.'/includes/auth_check.php';
$full_name = $_SESSION['full_name'];
include 'db.php';
$page_title='HR - Pending Approvals';
if(isset($_POST['approve_user'])){ 
    $uid=intval($_POST['user_id']); 
    $stmt=$conn->prepare("UPDATE users SET status='approved', approved_at=NOW() WHERE id=?"); 
    $stmt->bind_param('i',$uid); 
    $stmt->execute(); 
    $stmt->close(); 
}
if(isset($_POST['reject_user'])){ 
    $uid=intval($_POST['user_id']); 
    $stmt=$conn->prepare("UPDATE users SET status='rejected' WHERE id=?"); 
    $stmt->bind_param('i',$uid); 
    $stmt->execute(); 
    $stmt->close(); 
}
$pending=$conn->query("SELECT id, full_name, email, role, created_at FROM users WHERE status='pending' AND role != 'client' ORDER BY created_at ASC");
include __DIR__.'/includes/header.php';
?>

<div class="min-h-screen bg-gray-50">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header Section -->
    <div class="mb-8">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Pending Approvals</h1>
          <p class="mt-1 text-gray-600">Approve or reject newly registered users</p>
        </div>
        <div class="mt-4 md:mt-0">
          <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800">
            <i class="fas fa-user-clock mr-2"></i>
            <?php echo $pending->num_rows; ?> Pending
          </span>
        </div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
      <?php if($pending->num_rows): ?>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Registered</th>
                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php while($p=$pending->fetch_assoc()): ?>
              <tr class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="flex items-center">
                    <div class="flex-shrink-0 h-10 w-10">
                      <div class="h-10 w-10 rounded-full bg-yellow-100 flex items-center justify-center">
                        <span class="text-yellow-800 font-medium"><?php echo strtoupper(substr($p['full_name'], 0, 1)); ?></span>
                      </div>
                    </div>
                    <div class="ml-4">
                      <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($p['full_name']); ?></div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="text-sm text-gray-900"><?php echo htmlspecialchars($p['email']); ?></div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                    <?php echo htmlspecialchars($p['role']); ?>
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                  <?php echo htmlspecialchars($p['created_at']); ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                  <form method="post" class="inline-flex">
                    <input type="hidden" name="user_id" value="<?php echo $p['id']; ?>">
                    <button type="submit" name="approve_user" 
                            onclick="return confirm('Approve this user?')" 
                            class="mr-2 inline-flex items-center px-3 py-1 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                      <i class="fas fa-check mr-1"></i> Approve
                    </button>
                  </form>
                  <form method="post" class="inline-flex">
                    <input type="hidden" name="user_id" value="<?php echo $p['id']; ?>">
                    <button type="submit" name="reject_user" 
                            onclick="return confirm('Reject this user?')" 
                            class="inline-flex items-center px-3 py-1 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                      <i class="fas fa-times mr-1"></i> Reject
                    </button>
                  </form>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="py-12 text-center">
          <div class="mx-auto h-16 w-16 flex items-center justify-center rounded-full bg-green-100">
            <i class="fas fa-check-circle text-green-600 text-2xl"></i>
          </div>
          <h3 class="mt-4 text-lg font-medium text-gray-900">No pending approvals</h3>
          <p class="mt-1 text-sm text-gray-500">All users have been processed. Great job!</p>
          <div class="mt-6">
            <a href="hr_dashboard.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
              <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
            </a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__.'/includes/footer.php'; ?>
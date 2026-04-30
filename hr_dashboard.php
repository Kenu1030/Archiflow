<?php
// Clean HR dashboard: statistics only; other functionality moved to dedicated pages.
$allowed_roles=['hr'];
include __DIR__.'/includes/auth_check.php';
$full_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'User';
include 'db.php';
$page_title='HR Dashboard';
include __DIR__.'/includes/header.php';
// Fetch basic statistics
$total_employees = $conn->query("SELECT COUNT(*) as c FROM users WHERE user_type != 'client'")->fetch_assoc()['c'] ?? 0;
$active_employees = $conn->query("SELECT COUNT(*) as c FROM users WHERE is_active=1 AND user_type != 'client'")->fetch_assoc()['c'] ?? 0;
$pending_employees = $conn->query("SELECT COUNT(*) as c FROM users WHERE is_active=0 AND user_type != 'client'")->fetch_assoc()['c'] ?? 0;
$rejected_employees = 0; // No rejected status in current schema
$payroll_count = 0;
$payroll_table = $conn->query("SHOW TABLES LIKE 'payroll'");
if ($payroll_table && $payroll_table->num_rows) {
        $payroll_count = $conn->query("SELECT COUNT(*) as c FROM payroll")->fetch_assoc()['c'] ?? 0;
}
// Simple recent approvals (last 5 approved users) for quick glance with schema flexibility
$approved_order_expr = 'created_at';
$colApproved = $conn->query("SHOW COLUMNS FROM users LIKE 'approved_at'");
if ($colApproved && $colApproved->num_rows) {
    $approved_order_expr = 'approved_at';
} else {
    $colUpdated = $conn->query("SHOW COLUMNS FROM users LIKE 'updated_at'");
    if ($colUpdated && $colUpdated->num_rows) {
        $approved_order_expr = 'updated_at';
    }
}
$recent_approved = $conn->query("SELECT CONCAT(first_name, ' ', last_name) as full_name, 
    CASE 
        WHEN user_type = 'employee' THEN position 
        ELSE user_type 
    END as role, 
    created_at AS approved_at 
    FROM users 
    WHERE is_active=1 AND user_type != 'client' 
    ORDER BY created_at DESC LIMIT 5");
?>

<div class="min-h-screen bg-gray-50">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header Section -->
    <div class="mb-10">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
          <h1 class="text-3xl font-bold text-gray-900">Welcome, <?php echo htmlspecialchars($full_name); ?>!</h1>
          <p class="mt-2 text-gray-600">Human Resources Dashboard - Overview</p>
        </div>
        <div class="mt-4 md:mt-0">
          <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
            <i class="fas fa-user-tie mr-2"></i>HR Manager
          </span>
        </div>
      </div>
    </div>

    <!-- Key Metrics Section -->
    <div class="mb-12">
      <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-semibold text-gray-800">Key Metrics</h2>
        <div class="h-px bg-gray-200 flex-1 ml-4"></div>
      </div>
      
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6">
        <!-- Total Employees Card -->
        <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100 transition-all hover:shadow-md">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-gray-500">Total Employees</p>
              <p class="mt-2 text-3xl font-bold text-gray-900"><?php echo $total_employees; ?></p>
              <p class="mt-1 text-xs text-gray-500">All non-client accounts</p>
            </div>
            <div class="p-3 rounded-lg bg-blue-50">
              <i class="fas fa-users text-blue-600 text-xl"></i>
            </div>
          </div>
        </div>
        
        <!-- Active Employees Card -->
        <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100 transition-all hover:shadow-md">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-gray-500">Active</p>
              <p class="mt-2 text-3xl font-bold text-gray-900"><?php echo $active_employees; ?></p>
              <p class="mt-1 text-xs text-gray-500">Approved & active</p>
            </div>
            <div class="p-3 rounded-lg bg-green-50">
              <i class="fas fa-user-check text-green-600 text-xl"></i>
            </div>
          </div>
        </div>
        
        <!-- Pending Employees Card -->
        <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100 transition-all hover:shadow-md">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-gray-500">Pending</p>
              <p class="mt-2 text-3xl font-bold text-gray-900"><?php echo $pending_employees; ?></p>
              <p class="mt-1 text-xs text-gray-500">Awaiting approval</p>
            </div>
            <div class="p-3 rounded-lg bg-yellow-50">
              <i class="fas fa-user-clock text-yellow-600 text-xl"></i>
            </div>
          </div>
        </div>
        
        <!-- Rejected Employees Card -->
        <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100 transition-all hover:shadow-md">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-gray-500">Rejected</p>
              <p class="mt-2 text-3xl font-bold text-gray-900"><?php echo $rejected_employees; ?></p>
              <p class="mt-1 text-xs text-gray-500">Rejected registrations</p>
            </div>
            <div class="p-3 rounded-lg bg-red-50">
              <i class="fas fa-user-times text-red-600 text-xl"></i>
            </div>
          </div>
        </div>
        
        <!-- Payroll Records Card -->
        <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100 transition-all hover:shadow-md">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-gray-500">Payroll Records</p>
              <p class="mt-2 text-3xl font-bold text-gray-900"><?php echo $payroll_count; ?></p>
              <p class="mt-1 text-xs text-gray-500">Entries in payroll</p>
            </div>
            <div class="p-3 rounded-lg bg-purple-50">
              <i class="fas fa-money-check-alt text-purple-600 text-xl"></i>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Recently Approved Section -->
    <div class="mb-12">
      <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-semibold text-gray-800">Recently Approved</h2>
        <div class="h-px bg-gray-200 flex-1 ml-4"></div>
      </div>
      
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <?php if($recent_approved && $recent_approved->num_rows): ?>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                  <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                  <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Approved At</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <?php while($ra = $recent_approved->fetch_assoc()): ?>
                <tr class="hover:bg-gray-50">
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center">
                      <div class="flex-shrink-0 h-10 w-10">
                        <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                          <span class="text-blue-800 font-medium"><?php echo strtoupper(substr($ra['full_name'], 0, 1)); ?></span>
                        </div>
                      </div>
                      <div class="ml-4">
                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($ra['full_name']); ?></div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($ra['role']); ?></div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    <?php echo htmlspecialchars($ra['approved_at'] ?? ''); ?>
                  </td>
                </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="px-6 py-12 text-center">
            <div class="mx-auto h-12 w-12 rounded-full bg-gray-100 flex items-center justify-center">
              <i class="fas fa-inbox text-gray-400"></i>
            </div>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No recent approvals</h3>
            <p class="mt-1 text-sm text-gray-500">There are no recently approved employees at this time.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Next Actions Section -->
    <div>
      <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-semibold text-gray-800">Next Actions</h2>
        <div class="h-px bg-gray-200 flex-1 ml-4"></div>
      </div>
      
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <ul class="space-y-4">
          <li class="flex items-start">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-6 w-6 rounded-md bg-blue-500 text-white">
                <i class="fas fa-check text-xs"></i>
              </div>
            </div>
            <div class="ml-3">
              <p class="text-sm font-medium text-gray-900">Review and approve pending accounts</p>
            </div>
          </li>
          <li class="flex items-start">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-6 w-6 rounded-md bg-blue-500 text-white">
                <i class="fas fa-check text-xs"></i>
              </div>
            </div>
            <div class="ml-3">
              <p class="text-sm font-medium text-gray-900">Record recent attendance updates</p>
            </div>
          </li>
          <li class="flex items-start">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-6 w-6 rounded-md bg-blue-500 text-white">
                <i class="fas fa-check text-xs"></i>
              </div>
            </div>
            <div class="ml-3">
              <p class="text-sm font-medium text-gray-900">Process current period payroll</p>
            </div>
          </li>
          <li class="flex items-start">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-6 w-6 rounded-md bg-blue-500 text-white">
                <i class="fas fa-check text-xs"></i>
              </div>
            </div>
            <div class="ml-3">
              <p class="text-sm font-medium text-gray-900">Update employee rates or details</p>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__.'/includes/footer.php'; ?>
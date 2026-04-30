<?php
$allowed_roles=['hr'];
include __DIR__.'/includes/auth_check.php';
$full_name = $_SESSION['full_name'];
include 'db.php';
$page_title='HR - Employees';
include __DIR__.'/includes/header.php';

// Fetch employees (non-client)
$employees = $conn->query("SELECT id, full_name, email, role, status, rate_per_hour FROM users WHERE role != 'client' ORDER BY full_name ASC");
?>

<!-- FontAwesome CDN -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<div class="w-full bg-white mx-auto my-10 p-10 rounded-lg shadow-lg">
  <!-- Header Section -->
  <div class="mb-8">
    <div class="flex items-center gap-3 mb-2">
      <i class="fas fa-users text-2xl text-blue-600"></i>
      <h1 class="text-3xl font-bold text-gray-800 m-0">Employees</h1>
    </div>
    <p class="text-gray-600 m-0">All registered non-client users.</p>
  </div>

  <!-- Search Filter -->
  <div class="mb-6">
    <div class="relative">
      <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
        <i class="fas fa-search text-gray-400"></i>
      </div>
      <input 
        type="text" 
        id="empSearch" 
        placeholder="Search name, email or role..." 
        oninput="filterEmployees()" 
        class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors duration-200"
      />
    </div>
  </div>

  <!-- Table Container -->
  <div class="w-full overflow-auto rounded-lg border border-gray-200">
    <table class="w-full border-collapse bg-white" id="empTable">
      <thead>
        <tr class="bg-gray-50">
          <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider border-b border-gray-200">
            <div class="flex items-center gap-2">
              <i class="fas fa-user text-gray-500"></i>
              Name
            </div>
          </th>
          <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider border-b border-gray-200">
            <div class="flex items-center gap-2">
              <i class="fas fa-envelope text-gray-500"></i>
              Email
            </div>
          </th>
          <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider border-b border-gray-200">
            <div class="flex items-center gap-2">
              <i class="fas fa-id-badge text-gray-500"></i>
              Role
            </div>
          </th>
          <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider border-b border-gray-200">
            <div class="flex items-center gap-2">
              <i class="fas fa-circle-check text-gray-500"></i>
              Status
            </div>
          </th>
          <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider border-b border-gray-200">
            <div class="flex items-center gap-2">
              <i class="fas fa-dollar-sign text-gray-500"></i>
              Rate / hr
            </div>
          </th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-200">
        <?php while($e = $employees->fetch_assoc()): ?>
        <tr class="hover:bg-gray-50 transition-colors duration-150">
          <td class="px-6 py-4 whitespace-nowrap">
            <div class="flex items-center">
              <div class="flex-shrink-0 h-8 w-8">
                <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                  <i class="fas fa-user text-blue-600 text-sm"></i>
                </div>
              </div>
              <div class="ml-3">
                <div class="text-sm font-medium text-gray-900">
                  <?php echo htmlspecialchars($e['full_name']); ?>
                </div>
              </div>
            </div>
          </td>
          <td class="px-6 py-4 whitespace-nowrap">
            <div class="flex items-center text-sm text-gray-600">
              <i class="fas fa-envelope text-gray-400 mr-2"></i>
              <?php echo htmlspecialchars($e['email']); ?>
            </div>
          </td>
          <td class="px-6 py-4 whitespace-nowrap">
            <div class="flex items-center">
              <i class="fas fa-briefcase text-gray-400 mr-2"></i>
              <span class="text-sm text-gray-900 capitalize">
                <?php echo htmlspecialchars($e['role']); ?>
              </span>
            </div>
          </td>
          <td class="px-6 py-4 whitespace-nowrap">
            <?php 
            $status = htmlspecialchars($e['status']);
            $statusClasses = [
              'pending' => 'bg-yellow-100 text-yellow-800',
              'approved' => 'bg-green-100 text-green-800', 
              'rejected' => 'bg-red-100 text-red-800'
            ];
            $statusIcons = [
              'pending' => 'fas fa-clock',
              'approved' => 'fas fa-check-circle',
              'rejected' => 'fas fa-times-circle'
            ];
            $statusClass = $statusClasses[$status] ?? 'bg-gray-100 text-gray-800';
            $statusIcon = $statusIcons[$status] ?? 'fas fa-question-circle';
            ?>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?php echo $statusClass; ?>">
              <i class="<?php echo $statusIcon; ?> mr-1"></i>
              <?php echo ucfirst($status); ?>
            </span>
          </td>
          <td class="px-6 py-4 whitespace-nowrap">
            <div class="flex items-center">
              <i class="fas fa-money-bill text-gray-400 mr-2"></i>
              <span class="text-sm font-mono bg-gray-100 px-3 py-1 rounded-md text-gray-800">
                <?php echo $e['rate_per_hour'] !== null ? '₱' . number_format($e['rate_per_hour'],2) : '-'; ?>
              </span>
            </div>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <!-- Empty State (hidden by default, shown when no results) -->
  <div id="emptyState" class="hidden text-center py-12">
    <i class="fas fa-search text-4xl text-gray-300 mb-4"></i>
    <h3 class="text-lg font-medium text-gray-600 mb-2">No employees found</h3>
    <p class="text-gray-500">Try adjusting your search criteria</p>
  </div>
</div>

<script>
function filterEmployees(){
  const q = document.getElementById('empSearch').value.toLowerCase();
  const tbody = document.querySelector('#empTable tbody');
  const rows = [...tbody.querySelectorAll('tr')];
  const emptyState = document.getElementById('emptyState');
  let visibleCount = 0;
  
  rows.forEach(r => {
    const t = r.innerText.toLowerCase();
    const isVisible = t.indexOf(q) > -1;
    r.style.display = isVisible ? '' : 'none';
    if (isVisible) visibleCount++;
  });
  
  // Show/hide empty state
  if (visibleCount === 0 && q.length > 0) {
    document.querySelector('#empTable').parentElement.classList.add('hidden');
    emptyState.classList.remove('hidden');
  } else {
    document.querySelector('#empTable').parentElement.classList.remove('hidden');
    emptyState.classList.add('hidden');
  }
}
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
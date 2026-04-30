<?php
$allowed_roles=['hr'];
include __DIR__.'/includes/auth_check.php';
$full_name = $_SESSION['full_name'];
include 'db.php';
$page_title='HR - Attendance';
// CSRF token setup
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
if(isset($_POST['add_attendance'])){
  // CSRF check
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    die('Invalid request');
  }
  $uid = intval($_POST['attendance_user_id']);
  $date = $_POST['attendance_date'];
  $status = $_POST['attendance_status'];
  $check_in = $_POST['check_in'] ?: null;
  $check_out = $_POST['check_out'] ?: null;
  $conn->query("CREATE TABLE IF NOT EXISTS attendance (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, date DATE, status VARCHAR(20), check_in TIME NULL, check_out TIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id))");
  $stmt = $conn->prepare("INSERT INTO attendance (user_id,date,status,check_in,check_out) VALUES (?,?,?,?,?)");
  $stmt->bind_param('issss',$uid,$date,$status,$check_in,$check_out);
  $stmt->execute();
  $stmt->close();
  header('Location: hr_attendance.php?added=1');
  exit;
}
// Ensure attendance table exists before querying (in case no record added yet)
$conn->query("CREATE TABLE IF NOT EXISTS attendance (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, date DATE, status VARCHAR(20), check_in TIME NULL, check_out TIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id))");
$records = $conn->query("SELECT a.*, u.full_name FROM attendance a JOIN users u ON a.user_id=u.id ORDER BY a.date DESC, a.created_at DESC LIMIT 50");
$employees = $conn->query("SELECT id, full_name FROM users WHERE status='approved' AND role != 'client' ORDER BY full_name ASC");
include __DIR__.'/includes/header.php';
?>

<div class="min-h-screen bg-gray-50">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header Section -->
    <div class="mb-8">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Attendance</h1>
          <p class="mt-1 text-gray-600">Add and review recent attendance records</p>
        </div>
        <div class="mt-4 md:mt-0">
          <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
            <i class="fas fa-calendar-check mr-2"></i>HR Management
          </span>
        </div>
      </div>
    </div>

    <!-- Success Message -->
    <?php if(isset($_GET['added'])): ?>
      <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-800 flex items-center">
        <i class="fas fa-check-circle mr-3"></i>
        <span>Attendance record added successfully.</span>
      </div>
    <?php endif; ?>

    <!-- Add Record Form -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-8">
      <div class="flex items-center mb-4">
        <i class="fas fa-plus-circle text-blue-600 mr-2"></i>
        <h2 class="text-lg font-semibold text-gray-800">Add Attendance Record</h2>
      </div>
      
      <form method="post" class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Employee</label>
            <select name="attendance_user_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
              <option value="">Select Employee</option>
              <?php while($e=$employees->fetch_assoc()): ?>
              <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['full_name']); ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
            <input type="date" name="attendance_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
          </div>
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Check In</label>
            <input type="time" name="check_in" placeholder="Check In" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
          </div>
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Check Out</label>
            <input type="time" name="check_out" placeholder="Check Out" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
          </div>
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select name="attendance_status" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
              <option value="present">Present</option>
              <option value="absent">Absent</option>
              <option value="late">Late</option>
              <option value="half_day">Half Day</option>
            </select>
          </div>
        </div>
        
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        
        <div class="flex justify-end">
          <button type="submit" name="add_attendance" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
            <i class="fas fa-save mr-2"></i> Add Attendance
          </button>
        </div>
      </form>
    </div>

    <!-- Recent Records Section -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
      <div class="px-6 py-4 border-b border-gray-200">
        <div class="flex items-center">
          <i class="fas fa-history text-blue-600 mr-2"></i>
          <h2 class="text-lg font-semibold text-gray-800">Recent Records</h2>
        </div>
      </div>
      
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Check In</th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Check Out</th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Logged</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <?php while($r=$records->fetch_assoc()): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center">
                  <div class="flex-shrink-0 h-10 w-10">
                    <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                      <span class="text-blue-800 font-medium"><?php echo strtoupper(substr($r['full_name'], 0, 1)); ?></span>
                    </div>
                  </div>
                  <div class="ml-4">
                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($r['full_name']); ?></div>
                  </div>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                <?php echo htmlspecialchars($r['date']); ?>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <?php 
                $status = htmlspecialchars($r['status']);
                $statusClass = '';
                $statusIcon = '';
                
                switch($status) {
                  case 'present':
                    $statusClass = 'bg-green-100 text-green-800';
                    $statusIcon = 'fa-check-circle';
                    break;
                  case 'absent':
                    $statusClass = 'bg-red-100 text-red-800';
                    $statusIcon = 'fa-times-circle';
                    break;
                  case 'late':
                    $statusClass = 'bg-yellow-100 text-yellow-800';
                    $statusIcon = 'fa-clock';
                    break;
                  case 'half_day':
                    $statusClass = 'bg-blue-100 text-blue-800';
                    $statusIcon = 'fa-adjust';
                    break;
                }
                ?>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $statusClass; ?>">
                  <i class="fas <?php echo $statusIcon; ?> mr-1"></i>
                  <?php echo htmlspecialchars(str_replace('_',' ',$status)); ?>
                </span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                <?php echo htmlspecialchars($r['check_in'] ?: '-'); ?>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                <?php echo htmlspecialchars($r['check_out'] ?: '-'); ?>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                <?php echo htmlspecialchars($r['created_at']); ?>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      
      <?php if($records->num_rows == 0): ?>
        <div class="py-12 text-center">
          <div class="mx-auto h-16 w-16 flex items-center justify-center rounded-full bg-gray-100">
            <i class="fas fa-calendar-times text-gray-400 text-2xl"></i>
          </div>
          <h3 class="mt-4 text-lg font-medium text-gray-900">No attendance records</h3>
          <p class="mt-1 text-sm text-gray-500">Start by adding attendance records using the form above.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__.'/includes/footer.php'; ?>
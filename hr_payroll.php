<?php
$allowed_roles=['hr'];
include __DIR__.'/includes/auth_check.php';
$full_name = $_SESSION['full_name'];
include 'db.php';
$page_title='HR - Payroll';
if(isset($_POST['add_payroll'])){
  $user_id = intval($_POST['payroll_user_id']);
  $month = intval($_POST['payroll_month']);
  $year = intval($_POST['payroll_year']);
  $working_days = floatval($_POST['working_days'] ?? 0);
  $holidays = floatval($_POST['holidays'] ?? 0);
  $wfh_days = floatval($_POST['wfh_days'] ?? 0);
  $absences_days = floatval($_POST['absences_days'] ?? 0);
  $late_ut_hours = floatval($_POST['late_ut_hours'] ?? 0);
  $rate_per_hour = floatval($_POST['rate_per_hour'] ?? 0);
  $overtime_hours = floatval($_POST['overtime_hours'] ?? 0);
  $holiday_pay = floatval($_POST['holiday_pay'] ?? 0);
  $sss = floatval($_POST['sss'] ?? 0);
  $pagibig = floatval($_POST['pagibig'] ?? 0);
  $philhealth = floatval($_POST['philhealth'] ?? 0);
  $hmo = floatval($_POST['hmo'] ?? 0);
  $cash_advance = floatval($_POST['cash_advance'] ?? 0);
  $other_deductions = floatval($_POST['other_deductions'] ?? 0);
  $total_working_hours = $working_days * 9;
  $basic_salary = round($rate_per_hour * $total_working_hours, 2);
  $overtime_amount = round($overtime_hours * $rate_per_hour * 1.25, 2);
  $absence_amount = round($absences_days * $rate_per_hour * 9, 2);
  $tardiness_amount = round($late_ut_hours * $rate_per_hour, 2);
  $gross_income = round($basic_salary + $overtime_amount + $holiday_pay, 2);
  $deductions_total = round($sss + $pagibig + $philhealth + $absence_amount + $tardiness_amount + $hmo + $cash_advance + $other_deductions, 2);
  $net_pay = round($gross_income - $deductions_total, 2);
  $conn->query("CREATE TABLE IF NOT EXISTS payroll (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    month INT,
    year INT,
    basic_salary DECIMAL(10,2),
    allowances DECIMAL(10,2) DEFAULT 0,
    deductions DECIMAL(10,2) DEFAULT 0,
    net_pay DECIMAL(10,2),
    sss DECIMAL(10,2) DEFAULT 0,
    pagibig DECIMAL(10,2) DEFAULT 0,
    philhealth DECIMAL(10,2) DEFAULT 0,
    hmo DECIMAL(10,2) DEFAULT 0,
    cash_advance DECIMAL(10,2) DEFAULT 0,
    other_deductions DECIMAL(10,2) DEFAULT 0,
    holiday_pay DECIMAL(10,2) DEFAULT 0,
    overtime_hours DECIMAL(10,2) DEFAULT 0,
    rate_per_hour DECIMAL(10,2) DEFAULT 0,
    working_days DECIMAL(10,2) DEFAULT 0,
    wfh_days DECIMAL(10,2) DEFAULT 0,
    absences_days DECIMAL(10,2) DEFAULT 0,
    late_ut_hours DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
  )");
  $allowances = 0.00; $deductions = $deductions_total;
  $stmt = $conn->prepare("INSERT INTO payroll (user_id,month,year,basic_salary,allowances,deductions,net_pay,sss,pagibig,philhealth,hmo,cash_advance,other_deductions,holiday_pay,overtime_hours,rate_per_hour,working_days,wfh_days,absences_days,late_ut_hours) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
  $stmt->bind_param("iiidddddddddddddiddi",$user_id,$month,$year,$basic_salary,$allowances,$deductions,$net_pay,$sss,$pagibig,$philhealth,$hmo,$cash_advance,$other_deductions,$holiday_pay,$overtime_hours,$rate_per_hour,$working_days,$wfh_days,$absences_days,$late_ut_hours);
  $stmt->execute();
  $stmt->close();
}
$employees = $conn->query("SELECT id, full_name FROM users WHERE status='approved' AND role != 'client' ORDER BY full_name ASC");
$payroll = $conn->query("SELECT p.*, u.full_name FROM payroll p JOIN users u ON p.user_id=u.id ORDER BY p.year DESC, p.month DESC, p.created_at DESC LIMIT 40");
include __DIR__.'/includes/header.php';
?>

<div class="min-h-screen bg-gray-50">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header Section -->
    <div class="mb-8">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Payroll</h1>
          <p class="mt-1 text-gray-600">Create payroll records and view recent entries</p>
        </div>
        <div class="mt-4 md:mt-0">
          <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
            <i class="fas fa-money-check-alt mr-2"></i>HR Management
          </span>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
      <!-- Payroll Form Section -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="flex items-center mb-4">
          <i class="fas fa-plus-circle text-blue-600 mr-2"></i>
          <h2 class="text-lg font-semibold text-gray-800">Add Payroll Record</h2>
        </div>
        
        <form method="post" onsubmit="return validatePayrollForm();" class="space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Employee</label>
              <select name="payroll_user_id" required id="payroll_user_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">Select Employee</option>
                <?php while($e=$employees->fetch_assoc()): ?>
                <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['full_name']); ?></option>
                <?php endwhile; ?>
              </select>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Month</label>
              <select name="payroll_month" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">Select Month</option>
                <?php for($i=1;$i<=12;$i++): ?>
                <option value="<?php echo $i; ?>"><?php echo date('M', mktime(0,0,0,$i,1)); ?></option>
                <?php endfor; ?>
              </select>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Year</label>
              <select name="payroll_year" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">Select Year</option>
                <?php for($y=date('Y');$y>=date('Y')-5;$y--): ?>
                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                <?php endfor; ?>
              </select>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Working Days</label>
              <input type="number" step="0.5" name="working_days" placeholder="Working Days" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Holidays</label>
              <input type="number" step="0.5" name="holidays" placeholder="Holidays" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">WFH Days</label>
              <input type="number" step="0.5" name="wfh_days" placeholder="WFH Days" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Absences</label>
              <input type="number" step="0.5" name="absences_days" placeholder="Absences" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Late/UT Hours</label>
              <input type="number" step="0.25" name="late_ut_hours" placeholder="Late/UT hrs" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">OT Hours</label>
              <input type="number" step="0.25" name="overtime_hours" placeholder="OT hrs" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Rate / Hour</label>
              <input type="number" step="0.01" name="rate_per_hour" id="rate_per_hour" placeholder="Rate / hr" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Holiday Pay</label>
              <input type="number" step="0.01" name="holiday_pay" placeholder="Holiday Pay" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">SSS</label>
              <input type="number" step="0.01" name="sss" placeholder="SSS" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">PAG-IBIG</label>
              <input type="number" step="0.01" name="pagibig" placeholder="PAG-IBIG" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">PHILHEALTH</label>
              <input type="number" step="0.01" name="philhealth" placeholder="PHILHEALTH" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">HMO</label>
              <input type="number" step="0.01" name="hmo" placeholder="HMO" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Cash Advance</label>
              <input type="number" step="0.01" name="cash_advance" placeholder="Cash Adv" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Other Deductions</label>
              <input type="number" step="0.01" name="other_deductions" placeholder="Other Deduct" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
          </div>
          
          <div class="flex justify-end">
            <button type="submit" name="add_payroll" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
              <i class="fas fa-save mr-2"></i> Add Payroll
            </button>
          </div>
        </form>
      </div>

      <!-- Recent Payroll Section -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="flex items-center mb-4">
          <i class="fas fa-history text-blue-600 mr-2"></i>
          <h2 class="text-lg font-semibold text-gray-800">Recent Payroll</h2>
        </div>
        
        <div class="overflow-y-auto max-h-[520px]">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50 sticky top-0">
              <tr>
                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Period</th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Basic</th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Deductions</th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Net</th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php while($p=$payroll->fetch_assoc()): ?>
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 whitespace-nowrap">
                  <div class="flex items-center">
                    <div class="flex-shrink-0 h-8 w-8">
                      <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                        <span class="text-blue-800 font-medium text-xs"><?php echo strtoupper(substr($p['full_name'], 0, 1)); ?></span>
                      </div>
                    </div>
                    <div class="ml-3">
                      <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($p['full_name']); ?></div>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                  <?php echo date('M', mktime(0,0,0,$p['month'],1)).' '.$p['year']; ?>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                  <?php echo number_format($p['basic_salary'],2); ?>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                  <?php echo number_format($p['deductions'],2); ?>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                  <?php echo number_format($p['net_pay'],2); ?>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium">
                  <a href="payslip.php?id=<?php echo $p['id']; ?>" target="_blank" class="inline-flex items-center px-2.5 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-file-invoice mr-1"></i> Payslip
                  </a>
                  <a href="send_payslip.php?id=<?php echo $p['id']; ?>" class="ml-2 inline-flex items-center px-2.5 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-paper-plane mr-1"></i> Send
                  </a>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
          
          <?php if($payroll->num_rows == 0): ?>
            <div class="py-12 text-center">
              <div class="mx-auto h-16 w-16 flex items-center justify-center rounded-full bg-gray-100">
                <i class="fas fa-file-invoice-dollar text-gray-400 text-2xl"></i>
              </div>
              <h3 class="mt-4 text-lg font-medium text-gray-900">No payroll records</h3>
              <p class="mt-1 text-sm text-gray-500">Start by adding payroll records using the form.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function validatePayrollForm(){
  const rate=parseFloat(document.getElementById('rate_per_hour').value||0);
  const wd=parseFloat(document.querySelector('[name=working_days]').value||0);
  if(rate<=0){alert('Enter valid rate');return false;} 
  if(wd<=0){alert('Enter valid working days');return false;} 
  return true;
}
// Auto-fill rate if endpoint exists
const userSel=document.getElementById('payroll_user_id');
userSel && userSel.addEventListener('change',()=>{
  const id=userSel.value; if(!id){document.getElementById('rate_per_hour').value='';return;}
  fetch('get_user_rate.php?id='+id).then(r=>r.json()).then(d=>{ if(d.rate_per_hour) document.getElementById('rate_per_hour').value=d.rate_per_hour; }).catch(()=>{});
});
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
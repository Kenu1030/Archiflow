<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['user_type'] ?? null) !== 'hr') {
    http_response_code(403); exit('Forbidden');
}
require_once __DIR__ . '/../backend/connection/connect.php';
$pdo = getDB();
if (!$pdo) { http_response_code(500); exit('DB error'); }
$type = $_GET['type'] ?? '';
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html><html><head><meta charset="utf-8"><title>Print</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:24px;color:#111}
.table{width:100%;border-collapse:collapse}
.table th,.table td{border:1px solid #ddd;padding:6px;font-size:12px}
.h{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.btn{display:inline-block;padding:6px 10px;background:#111;color:#fff;text-decoration:none;border-radius:4px}
@media print {.btn{display:none}}
</style></head><body>
<div class="h"><h2>Print - <?php echo htmlspecialchars(ucfirst($type)); ?></h2><a class="btn" href="#" onclick="window.print();return false;">Print</a></div>
<?php
switch($type){
  case 'attendance':
    $date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : (new DateTime('today'))->format('Y-m-d');
    echo '<p>Date: '.htmlspecialchars($date).'</p>';
    echo '<table class="table"><thead><tr><th>Employee</th><th>Status</th><th>In</th><th>Out</th><th>Hours</th><th>OT</th></tr></thead><tbody>';
    $stmt=$pdo->prepare("SELECT employee_id,status,time_in,time_out,hours_worked,overtime_hours FROM attendance WHERE work_date=? ORDER BY employee_id");
    $stmt->execute([$date]);
    while($r=$stmt->fetch(PDO::FETCH_ASSOC)){
      echo '<tr><td>#'.(int)$r['employee_id'].'</td><td>'.htmlspecialchars($r['status']).'</td><td>'.htmlspecialchars($r['time_in']).'</td><td>'.htmlspecialchars($r['time_out']).'</td><td>'.htmlspecialchars($r['hours_worked']).'</td><td>'.htmlspecialchars($r['overtime_hours']).'</td></tr>';
    }
    echo '</tbody></table>';
    break;
  case 'leaves':
    echo '<table class="table"><thead><tr><th>ID</th><th>Employee</th><th>Type</th><th>Start</th><th>End</th><th>Days</th><th>Status</th><th>Applied</th></tr></thead><tbody>';
    foreach($pdo->query("SELECT leave_id, employee_id, leave_type, start_date, end_date, days_count, status, applied_date FROM leave_requests ORDER BY applied_date DESC") as $r){
      echo '<tr><td>#'.(int)$r['leave_id'].'</td><td>#'.(int)$r['employee_id'].'</td><td>'.htmlspecialchars($r['leave_type']).'</td><td>'.htmlspecialchars($r['start_date']).'</td><td>'.htmlspecialchars($r['end_date']).'</td><td>'.htmlspecialchars($r['days_count']).'</td><td>'.htmlspecialchars($r['status']).'</td><td>'.htmlspecialchars($r['applied_date']).'</td></tr>';
    }
    echo '</tbody></table>';
    break;
  case 'payroll':
    echo '<table class="table"><thead><tr><th>ID</th><th>Employee</th><th>Month</th><th>Reg Hrs</th><th>OT Hrs</th><th>Gross</th><th>Deductions</th><th>Net</th><th>Status</th><th>Created</th></tr></thead><tbody>';
    foreach($pdo->query("SELECT payroll_id, employee_id, month_year, regular_hours, overtime_hours, gross_pay, deductions, net_pay, status, created_at FROM payroll ORDER BY created_at DESC") as $r){
      echo '<tr><td>#'.(int)$r['payroll_id'].'</td><td>#'.(int)$r['employee_id'].'</td><td>'.htmlspecialchars($r['month_year']).'</td><td>'.htmlspecialchars($r['regular_hours']).'</td><td>'.htmlspecialchars($r['overtime_hours']).'</td><td>'.htmlspecialchars($r['gross_pay']).'</td><td>'.htmlspecialchars($r['deductions']).'</td><td>'.htmlspecialchars($r['net_pay']).'</td><td>'.htmlspecialchars($r['status']).'</td><td>'.htmlspecialchars($r['created_at']).'</td></tr>';
    }
    echo '</tbody></table>';
    break;
  default:
    echo '<p>Unknown type</p>';
}
?>
</body></html>

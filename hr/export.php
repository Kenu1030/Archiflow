<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['user_type'] ?? null) !== 'hr') {
    http_response_code(403); exit('Forbidden');
}
require_once __DIR__ . '/../backend/connection/connect.php';
$pdo = getDB();
if (!$pdo) { http_response_code(500); exit('DB error'); }

$type = $_GET['type'] ?? '';
$token = $_GET['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) { http_response_code(400); exit('Bad token'); }

header('Content-Type: text/csv');
$filename = $type . '-' . date('Ymd-His') . '.csv';
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');

switch ($type) {
  case 'attendance':
    $date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : (new DateTime('today'))->format('Y-m-d');
    fputcsv($out, ['employee_id','work_date','status','time_in','time_out','hours_worked','overtime_hours']);
    $stmt = $pdo->prepare('SELECT employee_id, work_date, status, time_in, time_out, hours_worked, overtime_hours FROM attendance WHERE work_date = ? ORDER BY employee_id');
    $stmt->execute([$date]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { fputcsv($out, $row); }
    break;
  case 'leaves':
    fputcsv($out, ['leave_id','employee_id','leave_type','start_date','end_date','days_count','status','applied_date']);
    $stmt = $pdo->query('SELECT leave_id, employee_id, leave_type, start_date, end_date, days_count, status, applied_date FROM leave_requests ORDER BY applied_date DESC');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { fputcsv($out, $row); }
    break;
  case 'payroll':
    fputcsv($out, ['payroll_id','employee_id','month_year','regular_hours','overtime_hours','gross_pay','deductions','net_pay','status','created_at']);
    $stmt = $pdo->query('SELECT payroll_id, employee_id, month_year, regular_hours, overtime_hours, gross_pay, deductions, net_pay, status, created_at FROM payroll ORDER BY created_at DESC');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { fputcsv($out, $row); }
    break;
  case 'payroll_payout':
    // Bank batch payout CSV for a given month (YYYY-MM)
    $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;
    if (!$month) { http_response_code(400); echo 'Missing or invalid month'; break; }
    // Detect bank columns in employees table
    $hasCol = function(string $col) use ($pdo): bool {
      $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = ?");
      $q->execute([$col]);
      return ((int)$q->fetchColumn()) > 0;
    };
    $hasBankName = $hasCol('bank_name');
    $hasAcctName = $hasCol('account_name');
    $hasAcctNum  = $hasCol('account_number');
    // Header: adjust to your bank's format as needed
    fputcsv($out, ['Employee Name','Employee Code','Bank Name','Account Name','Account Number','Amount','Period','Employee ID']);
    $sql = "SELECT p.employee_id, p.month_year, p.net_pay, e.employee_code, u.first_name, u.last_name".
           ($hasBankName ? ", e.bank_name" : ", NULL AS bank_name").
           ($hasAcctName ? ", e.account_name" : ", NULL AS account_name").
           ($hasAcctNum  ? ", e.account_number" : ", NULL AS account_number").
           " FROM payroll p JOIN employees e ON p.employee_id = e.employee_id LEFT JOIN users u ON e.user_id = u.user_id WHERE p.month_year = ? ORDER BY e.employee_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$month]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
      if ($name === '') { $name = 'Employee #' . (int)$r['employee_id']; }
      $row = [
        $name,
        $r['employee_code'] ?? '',
        $r['bank_name'] ?? '',
        $r['account_name'] ?? $name,
        $r['account_number'] ?? '',
        number_format((float)($r['net_pay'] ?? 0), 2, '.', ''),
        $r['month_year'] ?? $month,
        (int)$r['employee_id'],
      ];
      fputcsv($out, $row);
    }
    break;
  default:
    http_response_code(400); echo 'Unknown type';
}

fclose($out);

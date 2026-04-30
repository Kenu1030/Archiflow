<?php
include 'db.php';
if (!isset($_GET['id'])) {
    die('No payroll record specified.');
}
$payroll_id = intval($_GET['id']);
$res = $conn->query("SELECT p.*, u.full_name, u.email, u.role FROM payroll p JOIN users u ON p.user_id = u.id WHERE p.id = $payroll_id LIMIT 1");
if (!$row = $res->fetch_assoc()) {
    die('Payroll record not found.');
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payslip for <?php echo htmlspecialchars($row['full_name']); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .payslip-box { max-width: 500px; margin: auto; border: 1px solid #ccc; padding: 24px; background: #fff; }
        h2 { text-align: center; }
        table { width: 100%; margin-top: 16px; border-collapse: collapse; }
        td, th { padding: 8px; border-bottom: 1px solid #eee; }
        .right { text-align: right; }
        .net-pay { font-weight: bold; color: green; }
        .print-btn { margin: 16px 0; display: block; width: 100%; }
        @media print { .print-btn { display: none; } }
    </style>
</head>
<body>
    <div class="payslip-box">
        <h2>Payslip</h2>
        <p><strong>Employee:</strong> <?php echo htmlspecialchars($row['full_name']); ?><br>
           <strong>Email:</strong> <?php echo htmlspecialchars($row['email']); ?><br>
           <strong>Role:</strong> <?php echo ucfirst(str_replace('_', ' ', $row['role'])); ?><br>
           <strong>Period:</strong> <?php echo date('F', mktime(0,0,0,$row['month'],1)) . ' ' . $row['year']; ?></p>
        <table>
            <tr><th colspan="2">Earnings</th></tr>
            <tr><td>Rate per Hour</td><td class="right">$<?php echo number_format(isset($row['rate_per_hour']) ? $row['rate_per_hour'] : 0,2); ?></td></tr>
            <tr><td>Working Days</td><td class="right"><?php echo isset($row['working_days']) ? $row['working_days'] : 0; ?></td></tr>
            <tr><td>Total Working Hours</td><td class="right"><?php echo (isset($row['working_days']) ? $row['working_days'] : 0) * 9; ?></td></tr>
            <tr><td>WFH Days</td><td class="right"><?php echo isset($row['wfh_days']) ? $row['wfh_days'] : 0; ?></td></tr>
            <tr><td>Holiday Pay</td><td class="right">$<?php echo number_format(isset($row['holiday_pay']) ? $row['holiday_pay'] : 0,2); ?></td></tr>
            <tr><td>Overtime Hours</td><td class="right"><?php echo isset($row['overtime_hours']) ? $row['overtime_hours'] : 0; ?></td></tr>
            <tr><td>Basic Salary</td><td class="right">$
            <?php 
            $working_days = isset($row['working_days']) ? $row['working_days'] : 0;
            $rate_per_hour = isset($row['rate_per_hour']) ? $row['rate_per_hour'] : 0;
            $basic_salary = $rate_per_hour * $working_days * 9;
            echo number_format($basic_salary,2);
            ?>
            </td></tr>
            <tr><th colspan="2">Deductions</th></tr>
            <?php
            $absence_amount = (isset($row['absences_days']) ? $row['absences_days'] : 0) * (isset($row['rate_per_hour']) ? $row['rate_per_hour'] : 0) * 9;
            $tardiness_amount = (isset($row['late_ut_hours']) ? $row['late_ut_hours'] : 0) * (isset($row['rate_per_hour']) ? $row['rate_per_hour'] : 0);
            ?>
            <tr><td>Absences (days)</td><td class="right"><?php echo isset($row['absences_days']) ? $row['absences_days'] : 0; ?> ($<?php echo number_format($absence_amount,2); ?>)</td></tr>
            <tr><td>Late/UT (hours)</td><td class="right"><?php echo isset($row['late_ut_hours']) ? $row['late_ut_hours'] : 0; ?> ($<?php echo number_format($tardiness_amount,2); ?>)</td></tr>
            <tr><td>SSS</td><td class="right">$<?php echo number_format(isset($row['sss']) ? $row['sss'] : 0,2); ?></td></tr>
            <tr><td>PAG-IBIG</td><td class="right">$<?php echo number_format(isset($row['pagibig']) ? $row['pagibig'] : 0,2); ?></td></tr>
            <tr><td>PHILHEALTH</td><td class="right">$<?php echo number_format(isset($row['philhealth']) ? $row['philhealth'] : 0,2); ?></td></tr>
            <tr><td>HMO</td><td class="right">$<?php echo number_format(isset($row['hmo']) ? $row['hmo'] : 0,2); ?></td></tr>
            <tr><td>Cash Advance</td><td class="right">$<?php echo number_format(isset($row['cash_advance']) ? $row['cash_advance'] : 0,2); ?></td></tr>
            <tr><td>Other Deductions</td><td class="right">$<?php echo number_format(isset($row['other_deductions']) ? $row['other_deductions'] : 0,2); ?></td></tr>
            <tr><td><strong>Total Deductions</strong></td><td class="right"><strong>$<?php echo number_format($row['deductions'],2); ?></strong></td></tr>
            <tr><th>Net Pay</th><th class="right net-pay">$<?php echo number_format($row['net_pay'],2); ?></th></tr>
        </table>
        <button class="print-btn" onclick="window.print()">Print Payslip</button>
    </div>
</body>
</html>

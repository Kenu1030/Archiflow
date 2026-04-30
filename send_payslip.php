<?php
require 'db.php';
require 'vendor/autoload.php'; // Composer autoload (PHPMailer, mPDF)
require __DIR__ . '/lib/Mailer.php';
use function Archiflow\Mail\send_mail;
use Mpdf\Mpdf;

if (!isset($_GET['id'])) {
    die('No payroll record specified.');
}
$payroll_id = intval($_GET['id']);
$res = $conn->query("SELECT p.*, u.full_name, u.email, u.role FROM payroll p JOIN users u ON p.user_id = u.id WHERE p.id = $payroll_id LIMIT 1");
if (!$row = $res->fetch_assoc()) {
    die('Payroll record not found.');
}

// Generate payslip HTML
$payslipHtml = '<h2>Payslip</h2>';
$payslipHtml .= '<p><strong>Employee:</strong> '.htmlspecialchars($row['full_name']).'<br>';
$payslipHtml .= '<strong>Email:</strong> '.htmlspecialchars($row['email']).'<br>';
$payslipHtml .= '<strong>Role:</strong> '.ucfirst(str_replace('_', ' ', $row['role'])).'<br>';
$payslipHtml .= '<strong>Period:</strong> '.date('F', mktime(0,0,0,$row['month'],1)).' '.$row['year'].'</p>';
$payslipHtml .= '<table border="1" cellpadding="6" style="width:100%;border-collapse:collapse;">';
$payslipHtml .= '<tr><th colspan="2">Earnings</th></tr>';
$payslipHtml .= '<tr><td>Rate per Hour</td><td>$'.number_format($row['rate_per_hour'],2).'</td></tr>';
$payslipHtml .= '<tr><td>Working Days</td><td>'.$row['working_days'].'</td></tr>';
$payslipHtml .= '<tr><td>Total Working Hours</td><td>'.($row['working_days']*9).'</td></tr>';
$payslipHtml .= '<tr><td>WFH Days</td><td>'.$row['wfh_days'].'</td></tr>';
$payslipHtml .= '<tr><td>Holiday Pay</td><td>$'.number_format($row['holiday_pay'],2).'</td></tr>';
$payslipHtml .= '<tr><td>Overtime Hours</td><td>'.$row['overtime_hours'].'</td></tr>';
$payslipHtml .= '<tr><td>Basic Salary</td><td>$'.number_format($row['basic_salary'],2).'</td></tr>';
$payslipHtml .= '<tr><th colspan="2">Deductions</th></tr>';
$payslipHtml .= '<tr><td>Absences (days)</td><td>'.$row['absences_days'].' ($'.number_format($row['absences_days']*$row['rate_per_hour']*9,2).')</td></tr>';
$payslipHtml .= '<tr><td>Late/UT (hours)</td><td>'.$row['late_ut_hours'].' ($'.number_format($row['late_ut_hours']*$row['rate_per_hour'],2).')</td></tr>';
$payslipHtml .= '<tr><td>SSS</td><td>$'.number_format($row['sss'],2).'</td></tr>';
$payslipHtml .= '<tr><td>PAG-IBIG</td><td>$'.number_format($row['pagibig'],2).'</td></tr>';
$payslipHtml .= '<tr><td>PHILHEALTH</td><td>$'.number_format($row['philhealth'],2).'</td></tr>';
$payslipHtml .= '<tr><td>HMO</td><td>$'.number_format($row['hmo'],2).'</td></tr>';
$payslipHtml .= '<tr><td>Cash Advance</td><td>$'.number_format($row['cash_advance'],2).'</td></tr>';
$payslipHtml .= '<tr><td>Other Deductions</td><td>$'.number_format($row['other_deductions'],2).'</td></tr>';
$payslipHtml .= '<tr><td><strong>Total Deductions</strong></td><td><strong>$'.number_format($row['deductions'],2).'</strong></td></tr>';
$payslipHtml .= '<tr><th>Net Pay</th><th>$'.number_format($row['net_pay'],2).'</th></tr>';
$payslipHtml .= '</table>';

// Generate PDF
$mpdf = new Mpdf();
$mpdf->WriteHTML($payslipHtml);
$pdfContent = $mpdf->Output('', 'S');

// Send email via wrapper
$subject = 'Your Payslip for '.date('F', mktime(0,0,0,$row['month'],1)).' '.$row['year'];
[$ok, $err] = send_mail([
    'to_email' => $row['email'],
    'to_name'  => $row['full_name'],
    'subject'  => $subject,
    'html'     => '<p>Please find your payslip attached.</p>',
    'text'     => 'Please find your payslip attached.',
    'attachments' => [
        ['data' => $pdfContent, 'name' => 'Payslip.pdf', 'type' => 'application/pdf']
    ]
]);
if ($ok) {
    echo '<div style="max-width:500px;margin:40px auto;padding:32px;background:#fffbe6;border-radius:12px;box-shadow:0 4px 24px rgba(255,183,3,0.12);text-align:center;">'
       . '<h2 style="color:#ffb703;margin-bottom:12px;">🎉 Payslip Sent Successfully!</h2>'
       . '<p style="font-size:1.1em;color:#333;">The payslip for <strong>' . htmlspecialchars($row['full_name']) . '</strong> (<span style="color:#0077b6;">' . htmlspecialchars($row['email']) . '</span>) has been delivered to their inbox.</p>'
       . '<p style="margin-top:18px;color:#888;">Check your email for the attached PDF.<br>Thank you for using our payroll system!</p>'
       . '<a href="hr_dashboard.php" style="display:inline-block;margin-top:24px;padding:10px 24px;background:linear-gradient(135deg,#ffb703,#ffcb69);color:#fff;border-radius:6px;text-decoration:none;font-weight:bold;box-shadow:0 2px 8px rgba(255,183,3,0.10);">← Back to HR Dashboard</a>'
       . '</div>';
} else {
    echo '<div style="max-width:500px;margin:40px auto;padding:24px;background:#fee2e2;border:1px solid #fecaca;color:#b91c1c;border-radius:8px;">'
       . '<h3 style="margin-top:0;margin-bottom:8px;">Error sending payslip</h3>'
       . '<p style="margin:0;font-size:14px;">' . htmlspecialchars($err) . '</p>'
       . '<p style="margin-top:12px;"><a href="hr_dashboard.php">← Back to HR Dashboard</a></p>'
       . '</div>';
}
?>

<?php
// user_status.php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'administrator') {
    header('Location: login.php');
    exit();
}
if (!isset($_GET['id']) || !is_numeric($_GET['id']) || !isset($_GET['action'])) {
    header('Location: admin_dashboard.php');
    exit();
}
$user_id = intval($_GET['id']);
$action = $_GET['action'];

if (!in_array($action, ['approve', 'reject'])) {
    header('Location: admin_dashboard.php');
    exit();
}

include 'db.php';

// Fetch user email & name first
$user_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id=? LIMIT 1");
$user_stmt->bind_param('i', $user_id);
$user_stmt->execute();
$user_stmt->bind_result($target_email, $target_name);
$user_stmt->fetch();
$user_stmt->close();

$status = $action === 'approve' ? 'approved' : 'rejected';
$stmt = $conn->prepare("UPDATE users SET status=? WHERE id=?");
$stmt->bind_param("si", $status, $user_id);
$stmt->execute();
$stmt->close();

// Send notification email only if approved and email exists
if ($status === 'approved' && filter_var($target_email, FILTER_VALIDATE_EMAIL)) {
    require __DIR__ . '/vendor/autoload.php';
    require __DIR__ . '/lib/Mailer.php';
    $safe_name = htmlspecialchars($target_name ?: 'User', ENT_QUOTES, 'UTF-8');
    $login_url = (isset($_SERVER['HTTPS'])?'https://':'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/login.php';
    $html = "<p>Hello $safe_name,</p><p>Your account has been <strong>approved</strong>. You may now <a href='" . htmlspecialchars($login_url, ENT_QUOTES,'UTF-8') . "'>log in</a>.</p><p>Thank you.</p>";
    $text = "Hello $safe_name, Your account has been approved. You may now log in: $login_url";
    try {
        [$_ok, $_err] = Archiflow\Mail\send_mail([
            'to_email' => $target_email,
            'to_name'  => $target_name ?: 'User',
            'subject'  => 'Your account has been approved',
            'html'     => $html,
            'text'     => $text,
        ]);
        // Optionally log $_err if !$_ok
    } catch (\Throwable $e) {
        // swallow or log
    }
}

header('Location: admin_dashboard.php?status_updated=1');
exit();

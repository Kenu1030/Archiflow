<?php
include 'db.php';
if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'No user ID']);
    exit;
}
$id = intval($_GET['id']);
$res = $conn->query("SELECT rate_per_hour, rate_per_day FROM users WHERE id = $id LIMIT 1");
if ($row = $res->fetch_assoc()) {
    echo json_encode($row);
} else {
    echo json_encode(['rate_per_hour' => '', 'rate_per_day' => '']);
}
?>

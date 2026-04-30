<?php
include 'db.php';

echo "<h2>Testing Inquiry Form Submission</h2>";

// Check if table exists
$check_table = "SHOW TABLES LIKE 'public_inquiries'";
$result = $conn->query($check_table);

if ($result->num_rows > 0) {
    echo "✓ Table 'public_inquiries' exists<br><br>";
    
    // Check table structure
    $structure = $conn->query("DESCRIBE public_inquiries");
    echo "<h3>Table Structure:</h3>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $structure->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . $row['Default'] . "</td>";
        echo "</tr>";
    }
    echo "</table><br>";
    
    // Test the exact same insert statement as in index.php
    echo "<h3>Testing Insert Statement:</h3>";
    $test_stmt = $conn->prepare("INSERT INTO public_inquiries (name, email, phone, inquiry_type, project_type, budget_range, message, location, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    
    if ($test_stmt) {
        $test_name = "Test User " . date('Y-m-d H:i:s');
        $test_email = "test" . time() . "@example.com";
        $test_phone = "1234567890";
        $test_inquiry_type = "consultation";
        $test_project_type = "residential";
        $test_budget_range = "100k_500k";
        $test_message = "This is a test inquiry to verify database insertion";
        $test_location = "Test City";
        
        $test_stmt->bind_param("ssssssss", $test_name, $test_email, $test_phone, $test_inquiry_type, $test_project_type, $test_budget_range, $test_message, $test_location);
        
        if ($test_stmt->execute()) {
            echo "✓ Test insert successful! ID: " . $conn->insert_id . "<br>";
        } else {
            echo "✗ Test insert failed: " . $test_stmt->error . "<br>";
        }
        $test_stmt->close();
    } else {
        echo "✗ Failed to prepare statement: " . $conn->error . "<br>";
    }
    
    // Show current records
    echo "<h3>Current Records in Database:</h3>";
    $records_result = $conn->query("SELECT * FROM public_inquiries ORDER BY created_at DESC LIMIT 10");
    if ($records_result && $records_result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Inquiry Type</th><th>Message</th><th>Created At</th></tr>";
        while ($row = $records_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . htmlspecialchars($row['name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['email']) . "</td>";
            echo "<td>" . htmlspecialchars($row['inquiry_type']) . "</td>";
            echo "<td>" . htmlspecialchars(substr($row['message'], 0, 50)) . "...</td>";
            echo "<td>" . $row['created_at'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "No records found in the database.";
    }
    
    $count_result = $conn->query("SELECT COUNT(*) as count FROM public_inquiries");
    $count = $count_result->fetch_assoc()['count'];
    echo "<br><br><strong>Total records in table: " . $count . "</strong><br>";
    
} else {
    echo "✗ Table 'public_inquiries' does not exist<br>";
    echo "Please run the SQL command to create the table first.";
}

echo "<br><br><h3>Form Submission Test:</h3>";
echo "<p>Try submitting the form on the main page (index.php) and then refresh this page to see if new records appear.</p>";

$conn->close();
?>

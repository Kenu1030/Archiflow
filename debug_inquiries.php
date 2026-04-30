<?php
include 'db.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Inquiry Form Debug Test</h2>";

// Test if we can connect to database
if ($conn->connect_error) {
    echo "❌ Database connection failed: " . $conn->connect_error . "<br>";
    exit;
} else {
    echo "✅ Database connection successful<br>";
}

// Check if table exists
$check_table = "SHOW TABLES LIKE 'public_inquiries'";
$result = $conn->query($check_table);

if ($result->num_rows > 0) {
    echo "✅ Table 'public_inquiries' exists<br>";
} else {
    echo "❌ Table 'public_inquiries' does not exist<br>";
    echo "Creating table...<br>";
    
    // Create table with same structure as in index.php
    $create_table = "CREATE TABLE public_inquiries (
        id int(11) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        email varchar(100) NOT NULL,
        phone varchar(20),
        inquiry_type varchar(50) NOT NULL,
        project_type varchar(50),
        budget_range varchar(50),
        message text NOT NULL,
        location varchar(100),
        status enum('new','contacted','in_progress','completed') DEFAULT 'new',
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id)
    )";
    
    if ($conn->query($create_table)) {
        echo "✅ Table created successfully<br>";
    } else {
        echo "❌ Failed to create table: " . $conn->error . "<br>";
    }
}

// Test form submission simulation
echo "<br><h3>Simulating Form Submission:</h3>";

$test_data = [
    'name' => 'John Test',
    'email' => 'john.test@example.com',
    'phone' => '+1234567890',
    'inquiry_type' => 'consultation',
    'project_type' => 'residential',
    'budget_range' => '100k_500k',
    'message' => 'This is a test inquiry message to verify the form submission process.',
    'location' => 'Test City, Test State'
];

// Validate the same way as index.php
$errors = [];
if (empty($test_data['name'])) $errors[] = 'Name is required';
if (empty($test_data['email']) || !filter_var($test_data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
if (empty($test_data['inquiry_type'])) $errors[] = 'Please select an inquiry type';
if (empty($test_data['message'])) $errors[] = 'Message is required';

if (empty($errors)) {
    echo "✅ Validation passed<br>";
    
    // Test the exact same insert statement as index.php
    $stmt = $conn->prepare("INSERT INTO public_inquiries (name, email, phone, inquiry_type, project_type, budget_range, message, location, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    
    if ($stmt) {
        echo "✅ Statement prepared successfully<br>";
        
        $stmt->bind_param("ssssssss", 
            $test_data['name'], 
            $test_data['email'], 
            $test_data['phone'], 
            $test_data['inquiry_type'], 
            $test_data['project_type'], 
            $test_data['budget_range'], 
            $test_data['message'], 
            $test_data['location']
        );
        
        if ($stmt->execute()) {
            echo "✅ Insert successful! New record ID: " . $conn->insert_id . "<br>";
        } else {
            echo "❌ Insert failed: " . $stmt->error . "<br>";
        }
        $stmt->close();
    } else {
        echo "❌ Failed to prepare statement: " . $conn->error . "<br>";
    }
} else {
    echo "❌ Validation errors: " . implode(', ', $errors) . "<br>";
}

// Show all records
echo "<br><h3>All Records in Database:</h3>";
$all_records = $conn->query("SELECT * FROM public_inquiries ORDER BY created_at DESC");

if ($all_records && $all_records->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background-color: #f2f2f2;'>";
    echo "<th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Inquiry Type</th><th>Project Type</th><th>Budget Range</th><th>Location</th><th>Message</th><th>Status</th><th>Created At</th>";
    echo "</tr>";
    
    while ($row = $all_records->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['email']) . "</td>";
        echo "<td>" . htmlspecialchars($row['phone']) . "</td>";
        echo "<td>" . htmlspecialchars($row['inquiry_type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['project_type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['budget_range']) . "</td>";
        echo "<td>" . htmlspecialchars($row['location']) . "</td>";
        echo "<td>" . htmlspecialchars(substr($row['message'], 0, 50)) . "...</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "<td>" . $row['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<br><strong>Total records: " . $all_records->num_rows . "</strong>";
} else {
    echo "No records found in the database.";
}

echo "<br><br><h3>Instructions:</h3>";
echo "<ol>";
echo "<li>Go to your main page: <a href='index.php'>index.php</a></li>";
echo "<li>Scroll down to the contact form</li>";
echo "<li>Fill out and submit the form</li>";
echo "<li>Come back to this page and refresh to see if the new inquiry appears</li>";
echo "</ol>";

$conn->close();
?>

<style>
table { border-collapse: collapse; width: 100%; margin: 10px 0; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background-color: #f2f2f2; font-weight: bold; }
tr:nth-child(even) { background-color: #f9f9f9; }
h2, h3 { color: #333; }
</style>

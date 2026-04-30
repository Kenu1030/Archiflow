<?php
// Simple form test to isolate the inquiry submission issue
include 'db.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h3>POST Data Received:</h3>";
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";
    
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $inquiry_type = $_POST['inquiry_type'] ?? '';
    $project_type = $_POST['project_type'] ?? '';
    $budget_range = $_POST['budget_range'] ?? '';
    $message_text = $_POST['message'] ?? '';
    $location = $_POST['location'] ?? '';
    
    // Validation
    $errors = [];
    if (empty($name)) $errors[] = 'Name is required';
    if (empty($email)) $errors[] = 'Email is required';
    if (empty($inquiry_type)) $errors[] = 'Inquiry type is required';
    if (empty($message_text)) $errors[] = 'Message is required';
    
    if (empty($errors)) {
        // Try to insert
        echo "<h3>Attempting Database Insert:</h3>";
        
        // Check if table exists first
        $check_table = "SHOW TABLES LIKE 'public_inquiries'";
        $table_result = $conn->query($check_table);
        
        if ($table_result->num_rows == 0) {
            echo "Table doesn't exist. Creating it...<br>";
            $create_sql = "CREATE TABLE `public_inquiries` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `name` varchar(255) NOT NULL,
              `email` varchar(255) NOT NULL,
              `phone` varchar(20) DEFAULT NULL,
              `inquiry_type` enum('new_construction','renovation','consultation','planning','other') NOT NULL DEFAULT 'other',
              `project_type` varchar(100) DEFAULT NULL,
              `budget_range` varchar(50) DEFAULT NULL,
              `location` varchar(255) DEFAULT NULL,
              `message` text NOT NULL,
              `status` enum('new','contacted','in_progress','completed','cancelled') NOT NULL DEFAULT 'new',
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
            
            if ($conn->query($create_sql)) {
                echo "✓ Table created successfully<br>";
            } else {
                echo "✗ Failed to create table: " . $conn->error . "<br>";
            }
        } else {
            echo "✓ Table exists<br>";
        }
        
        // Now try to insert
        $sql = "INSERT INTO public_inquiries (name, email, phone, inquiry_type, project_type, budget_range, message, location) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            echo "✓ Statement prepared<br>";
            $stmt->bind_param("ssssssss", $name, $email, $phone, $inquiry_type, $project_type, $budget_range, $message_text, $location);
            
            if ($stmt->execute()) {
                echo "✓ <strong style='color: green;'>SUCCESS! Inquiry inserted with ID: " . $conn->insert_id . "</strong><br>";
                $message = "Form submitted successfully!";
            } else {
                echo "✗ Insert failed: " . $stmt->error . "<br>";
                $error = "Database insert failed: " . $stmt->error;
            }
            $stmt->close();
        } else {
            echo "✗ Failed to prepare statement: " . $conn->error . "<br>";
            $error = "Database prepare failed: " . $conn->error;
        }
    } else {
        echo "<h3>Validation Errors:</h3>";
        foreach ($errors as $err) {
            echo "• " . $err . "<br>";
        }
        $error = implode(", ", $errors);
    }
    
    echo "<hr>";
}

// Show current records
echo "<h3>Current Records in Database:</h3>";
$records = $conn->query("SELECT * FROM public_inquiries ORDER BY created_at DESC LIMIT 5");
if ($records && $records->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Inquiry Type</th><th>Message</th><th>Created</th></tr>";
    while ($row = $records->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['email']) . "</td>";
        echo "<td>" . htmlspecialchars($row['inquiry_type']) . "</td>";
        echo "<td>" . htmlspecialchars(substr($row['message'], 0, 30)) . "...</td>";
        echo "<td>" . $row['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No records found.";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Simple Inquiry Form Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { width: 300px; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        textarea { height: 80px; }
        button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #005a87; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Simple Inquiry Form Test</h1>
    
    <?php if ($message): ?>
        <div class="success"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <label for="name">Name *:</label>
            <input type="text" id="name" name="name" required>
        </div>
        
        <div class="form-group">
            <label for="email">Email *:</label>
            <input type="email" id="email" name="email" required>
        </div>
        
        <div class="form-group">
            <label for="phone">Phone:</label>
            <input type="text" id="phone" name="phone">
        </div>
        
        <div class="form-group">
            <label for="inquiry_type">Inquiry Type *:</label>
            <select id="inquiry_type" name="inquiry_type" required>
                <option value="">Select...</option>
                <option value="new_construction">New Construction</option>
                <option value="renovation">Renovation</option>
                <option value="consultation">Consultation</option>
                <option value="planning">Planning</option>
                <option value="other">Other</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="project_type">Project Type:</label>
            <select id="project_type" name="project_type">
                <option value="">Select...</option>
                <option value="residential">Residential</option>
                <option value="commercial">Commercial</option>
                <option value="institutional">Institutional</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="budget_range">Budget Range:</label>
            <select id="budget_range" name="budget_range">
                <option value="">Select...</option>
                <option value="under_100k">Under $100k</option>
                <option value="100k_500k">$100k - $500k</option>
                <option value="500k_1m">$500k - $1M</option>
                <option value="over_1m">Over $1M</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="location">Location:</label>
            <input type="text" id="location" name="location">
        </div>
        
        <div class="form-group">
            <label for="message">Message *:</label>
            <textarea id="message" name="message" required></textarea>
        </div>
        
        <button type="submit">Submit Test Form</button>
    </form>
    
    <hr>
    <p><strong>Instructions:</strong></p>
    <ol>
        <li>Fill out this simple form and submit it</li>
        <li>Check if it appears in the "Current Records" table above</li>
        <li>If this works, then the issue is with the main index.php form</li>
        <li>If this doesn't work, we'll see the exact error message</li>
    </ol>
</body>
</html>

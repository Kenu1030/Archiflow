<?php
// Debug form submission for index.php
echo "<h2>Form Submission Debug</h2>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h3>✅ POST Request Received!</h3>";
    echo "<h4>All POST Data:</h4>";
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";
    
    echo "<h4>Form Processing Results:</h4>";
    if (isset($_POST['inquiry_type'])) {
        echo "✅ inquiry_type field found: " . $_POST['inquiry_type'] . "<br>";
    } else {
        echo "❌ inquiry_type field NOT found in POST data<br>";
    }
} else {
    echo "<h3>❌ No POST Request - Form Not Submitted</h3>";
    echo "Current request method: " . $_SERVER['REQUEST_METHOD'] . "<br>";
}

echo "<h3>Testing Form Submission</h3>";
?>

<!DOCTYPE html>
<html>
<head>
    <title>Form Debug Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { width: 300px; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        textarea { height: 80px; }
        button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #005a87; }
    </style>
</head>
<body>
    <h3>Simple Test Form</h3>
    <p>This form should submit to the same page and show POST data above.</p>
    
    <form method="POST" action="">
        <div class="form-group">
            <label>Name:</label>
            <input type="text" name="name" value="Test User" required>
        </div>
        
        <div class="form-group">
            <label>Email:</label>
            <input type="email" name="email" value="test@example.com" required>
        </div>
        
        <div class="form-group">
            <label>Inquiry Type:</label>
            <select name="inquiry_type" required>
                <option value="">Select...</option>
                <option value="consultation" selected>Consultation</option>
                <option value="new_construction">New Construction</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>Message:</label>
            <textarea name="message" required>This is a test message</textarea>
        </div>
        
        <button type="submit">Test Submit</button>
    </form>
    
    <hr>
    
    <h3>JavaScript Test</h3>
    <button onclick="testJS()">Test JavaScript</button>
    <div id="js-test"></div>
    
    <script>
        function testJS() {
            document.getElementById('js-test').innerHTML = '✅ JavaScript is working!';
        }
        
        // Check for form submission errors
        document.querySelector('form').addEventListener('submit', function(e) {
            console.log('Form submission detected');
            console.log('Form data:', new FormData(this));
        });
        
        // Log any JavaScript errors
        window.addEventListener('error', function(e) {
            console.error('JavaScript Error:', e.message, 'at', e.filename + ':' + e.lineno);
        });
    </script>
    
    <h3>Debugging Steps:</h3>
    <ol>
        <li>Click "Test JavaScript" button - should show green text</li>
        <li>Submit the form above - should show POST data at the top</li>
        <li>Check browser console (F12) for any JavaScript errors</li>
        <li>If this works but index.php doesn't, the issue is in index.php form</li>
    </ol>
</body>
</html>

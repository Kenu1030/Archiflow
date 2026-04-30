<?php
session_start();
require_once '../../backend/auth.php';
require_once '../../backend/connection/connect.php';

$auth = new Auth();
if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$user = $auth->getCurrentUser();

// Get user ID from URL
$userId = $_GET['id'] ?? null;
if (!$userId) {
    header('Location: user-index.php');
    exit();
}

// Get user details from database
$db = getDB();
$userData = null;
if ($db) {
    $query = "SELECT * FROM users WHERE user_id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$userId]);
    $userData = $stmt->fetch();
}

if (!$userData) {
    header('Location: user-index.php');
    exit();
}
?>

<?php include '../../backend/core/header.php'; ?>

<main class="p-6">
    <div class="max-w-full">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex items-center mb-4">
                <a href="user-details.php?id=<?php echo $userData['user_id']; ?>" class="text-blue-600 hover:text-blue-800 mr-4">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Edit User</h1>
                    <p class="text-gray-600 mt-2">Update user information and settings</p>
                </div>
            </div>
        </div>

        <!-- Edit User Form -->
        <div class="bg-white rounded-lg shadow-lg p-8">
            <form id="editUserForm" class="space-y-6">
                <input type="hidden" name="user_id" value="<?php echo $userData['user_id']; ?>">
                
                <!-- User Type Selection -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">User Role *</label>
                    <select name="user_type" id="userType" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300">
                        <option value="">Select User Role</option>
                        <option value="admin" <?php echo $userData['user_type'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="employee" <?php echo $userData['user_type'] === 'employee' ? 'selected' : ''; ?>>Employee (Architect/Senior Architect/Project Manager)</option>
                        <option value="hr" <?php echo $userData['user_type'] === 'hr' ? 'selected' : ''; ?>>HR Manager</option>
                        <option value="client" <?php echo $userData['user_type'] === 'client' ? 'selected' : ''; ?>>Client</option>
                    </select>
                </div>

                <!-- Position Selection (for employees only) -->
                <div id="positionField" class="<?php echo $userData['user_type'] === 'employee' ? '' : 'hidden'; ?>">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Position *</label>
                    <select name="position" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300">
                        <option value="">Select Position</option>
                        <option value="architect" <?php echo $userData['position'] === 'architect' ? 'selected' : ''; ?>>Architect</option>
                        <option value="senior_architect" <?php echo $userData['position'] === 'senior_architect' ? 'selected' : ''; ?>>Senior Architect</option>
                        <option value="project_manager" <?php echo $userData['position'] === 'project_manager' ? 'selected' : ''; ?>>Project Manager</option>
                    </select>
                </div>

                <!-- Name Fields -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">First Name *</label>
                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($userData['first_name']); ?>" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter first name">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Last Name *</label>
                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($userData['last_name']); ?>" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter last name">
                    </div>
                </div>

                <!-- Username and Email -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Username *</label>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($userData['username']); ?>" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Choose a username">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email Address *</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($userData['email']); ?>" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter email address">
                    </div>
                </div>

                <!-- Password (Optional) -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">New Password (Leave blank to keep current)</label>
                    <input type="password" name="password" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter new password">
                </div>

                <!-- Phone and Address -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($userData['phone'] ?? ''); ?>" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter phone number">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Address</label>
                        <input type="text" name="address" value="<?php echo htmlspecialchars($userData['address'] ?? ''); ?>" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter address">
                    </div>
                </div>

                <!-- Account Status -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Account Status</label>
                    <select name="is_active" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300">
                        <option value="1" <?php echo $userData['is_active'] ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo !$userData['is_active'] ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <!-- Form Actions -->
                <div class="flex justify-end space-x-4 pt-6">
                    <a href="user-details.php?id=<?php echo $userData['user_id']; ?>" class="px-6 py-3 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition duration-300">
                        Cancel
                    </a>
                    <button type="submit" id="editUserBtn" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-300 flex items-center">
                        <i class="fas fa-save mr-2"></i>
                        <span class="editUserBtnText">Update User</span>
                        <div class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin ml-2 hidden" id="editUserSpinner"></div>
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const userTypeSelect = document.getElementById('userType');
    const positionField = document.getElementById('positionField');
    const positionSelect = positionField.querySelector('select');

    // Show/hide position field based on user type
    userTypeSelect.addEventListener('change', function() {
        if (this.value === 'employee') {
            positionField.classList.remove('hidden');
            positionSelect.required = true;
        } else {
            positionField.classList.add('hidden');
            positionSelect.required = false;
            positionSelect.value = '';
        }
    });

    // Handle form submission
    document.getElementById('editUserForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const editUserBtn = document.getElementById('editUserBtn');
        
        if (!editUserBtn) {
            console.error('Submit button not found');
            alert('Submit button not found. Please refresh the page and try again.');
            return;
        }
        
        // Check if the button already has the correct structure, if not, reconstruct it
        let editUserSpinner = editUserBtn.querySelector('#editUserSpinner');
        let editUserBtnText = editUserBtn.querySelector('.editUserBtnText');
        
        if (!editUserSpinner || !editUserBtnText) {
            console.log('Reconstructing button HTML structure...');
            // Reconstruct the button content
            editUserBtn.innerHTML = `
                <i class="fas fa-save mr-2"></i>
                <span class="editUserBtnText">Update User</span>
                <div class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin ml-2 hidden" id="editUserSpinner"></div>
            `;
            
            // Re-select the elements
            editUserSpinner = editUserBtn.querySelector('#editUserSpinner');
            editUserBtnText = editUserBtn.querySelector('.editUserBtnText');
        }
        
        // Final check
        if (!editUserSpinner || !editUserBtnText) {
            console.error('Failed to create required elements');
            alert('Failed to initialize form elements. Please refresh the page and try again.');
            return;
        }
        
        // Show loading state
        editUserBtn.disabled = true;
        editUserSpinner.classList.remove('hidden');
        editUserBtnText.textContent = 'Updating...';
        
        const formData = new FormData(this);
        formData.append('action', 'updateUser');
        
        console.log('Submitting form data:', Object.fromEntries(formData));
        
        fetch('/Archiflow/backend/auth.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(text => {
            console.log('Raw response:', text);
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Failed to parse JSON:', e);
                throw new Error('Invalid JSON response: ' + text.substring(0, 100));
            }
            
            if (data.success) {
                alert('User updated successfully!');
                window.location.href = 'user-details.php?id=' + formData.get('user_id');
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred: ' + error.message);
        })
        .finally(() => {
            // Reset button state
            if (editUserBtn) {
                editUserBtn.disabled = false;
                // Restore original button content
                editUserBtn.innerHTML = `
                    <i class="fas fa-save mr-2"></i>
                    <span class="editUserBtnText">Update User</span>
                    <div class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin ml-2 hidden" id="editUserSpinner"></div>
                `;
            }
        });
    });
});
</script>

<?php include '../../backend/core/footer.php'; ?>

<?php
session_start();
require_once '../../backend/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$user = $auth->getCurrentUser();
?>

<?php include '../../backend/core/header.php'; ?>

<main class="p-6">
    <div class="max-w-full">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex items-center mb-4">
                <a href="admin/user-management/user-index.php" class="text-blue-600 hover:text-blue-800 mr-4">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Add New User</h1>
                    <p class="text-gray-600 mt-2">Create a new user account with specific role</p>
                </div>
            </div>
        </div>

        <!-- Add User Form -->
        <div class="bg-white rounded-lg shadow-lg p-8">
            <form id="addUserForm" class="space-y-6">
                <!-- User Type Selection -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">User Role *</label>
                    <select name="user_type" id="userType" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300">
                        <option value="">Select User Role</option>
                        <option value="admin">Admin</option>
                        <option value="employee">Employee (Architect/Senior Architect/Project Manager)</option>
                        <option value="hr">HR Manager</option>
                    </select>
                </div>

                <!-- Position Selection (for employees only) -->
                <div id="positionField" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Position (Architect, Senior Architect, or Project Manager) *</label>
                    <select name="position" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300">
                        <option value="">Select Position</option>
                        <option value="architect">Architect</option>
                        <option value="senior_architect">Senior Architect</option>
                        <option value="project_manager">Project Manager</option>
                    </select>
                </div>

                <!-- Name Fields -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">First Name *</label>
                        <input type="text" name="first_name" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter first name">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Last Name *</label>
                        <input type="text" name="last_name" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter last name">
                    </div>
                </div>

                <!-- Username and Email -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Username *</label>
                        <input type="text" name="username" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Choose a username">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email Address *</label>
                        <input type="email" name="email" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter email address">
                    </div>
                </div>

                <!-- Password -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Password *</label>
                    <input type="password" name="password" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter password">
                </div>

                <!-- Phone and Address -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                        <input type="tel" name="phone" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter phone number">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Address</label>
                        <input type="text" name="address" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter address">
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex justify-end space-x-4 pt-6">
                    <a href="admin/user-management/user-index.php" class="px-6 py-3 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition duration-300">
                        Cancel
                    </a>
                    <button type="submit" id="addUserBtn" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-300 flex items-center">
                        <i class="fas fa-user-plus mr-2"></i>
                        <span class="addUserBtnText">Add User</span>
                        <div class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin ml-2 hidden" id="addUserSpinner"></div>
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const addUserForm = document.getElementById('addUserForm');
    if (!addUserForm) return;

    // Scope queries to the form
    const userTypeSelect = addUserForm.querySelector('#userType');
    const positionField = addUserForm.querySelector('#positionField');
    const positionSelect = positionField ? positionField.querySelector('select') : null;

    // Guard against missing elements
    if (userTypeSelect && positionField && positionSelect) {
        // Initialize state on load (in case of back/forward cache)
        const init = () => {
            if (userTypeSelect.value === 'employee') {
                positionField.classList.remove('hidden');
                positionSelect.required = true;
            } else {
                positionField.classList.add('hidden');
                positionSelect.required = false;
                positionSelect.value = '';
            }
        };
        init();

        // Show/hide position field based on user type
        userTypeSelect.addEventListener('change', init);
    }

    // Handle form submission
    addUserForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const addUserBtn = addUserForm.querySelector('#addUserBtn');
        const addUserSpinner = addUserForm.querySelector('#addUserSpinner');
        const addUserBtnText = addUserForm.querySelector('.addUserBtnText');
        
        // Show loading state
        if (addUserBtn) addUserBtn.disabled = true;
        if (addUserSpinner) addUserSpinner.classList.remove('hidden');
        if (addUserBtnText) addUserBtnText.textContent = 'Adding User...';
        
    const formData = new FormData(addUserForm);
        formData.append('action', 'addUser');
        
    // Use path relative to application root (base href set in header)
    fetch('backend/auth.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('User added successfully!');
        window.location.href = 'admin/user-management/user-index.php';
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        })
        .finally(() => {
            // Reset button state
            if (addUserBtn) addUserBtn.disabled = false;
            if (addUserSpinner) addUserSpinner.classList.add('hidden');
            if (addUserBtnText) addUserBtnText.textContent = 'Add User';
        });
    });
});
</script>

<?php include '../../backend/core/footer.php'; ?>

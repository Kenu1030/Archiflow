<?php
// Start session early and redirect before any output if already logged in
if (session_status() === PHP_SESSION_NONE) {
    // Use an app-local session directory to avoid OS-level permission issues
    $sessDir = __DIR__ . '/tmp/sessions';
    if (!is_dir($sessDir)) { @mkdir($sessDir, 0777, true); }
    if (is_dir($sessDir) && is_writable($sessDir)) { @ini_set('session.save_path', $sessDir); }
    // Ensure session cookie is valid for the whole app and survives top-level OAuth redirects
    @ini_set('session.cookie_path', '/');
    @ini_set('session.cookie_samesite', 'Lax');
    @session_start();
}
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $userType = $_SESSION['user_type'] ?? '';
    $position = strtolower((string)($_SESSION['position'] ?? ''));
    // DEBUG: Output session values for troubleshooting
    error_log('LOGIN REDIRECT DEBUG: userType=' . $userType . ' position=' . $position);
    // Compute app base (e.g., /ArchiFlow) and build absolute path
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($base === '/' || $base === '.' ) { $base = ''; }
    switch ($userType) {
        case 'admin':
            header('Location: ' . $base . '/admin/dashboard.php');
            exit;
        case 'client':
            header('Location: ' . $base . '/client/dashboard.php');
            exit;
        case 'hr':
            header('Location: ' . $base . '/hr/hr-dashboard.php');
            exit;
        case 'employee':
            $normalizedPosition = strtolower(str_replace(' ', '_', trim($position)));
            if ($normalizedPosition === 'architect') {
                header('Location: ' . $base . '/employees/architects/architects-dashboard.php');
                exit;
            }
            if ($normalizedPosition === 'senior_architect') {
                header('Location: ' . $base . '/employees/senior_architects/senior_architects-dashboard.php');
                exit;
            }
            if ($normalizedPosition === 'project_manager') {
                header('Location: ' . $base . '/employees/project_manager/project_manager-dashboard.php');
                exit;
            }
            // Fallback for unknown employee position
            header('Location: ' . $base . '/employees/architects/architects-dashboard.php');
            exit;
    }
}
// If login.php was previously used as OAuth redirect, avoid processing it here to prevent loops
include 'backend/core/header.php';
?>



<main class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-blue-50 flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
        <!-- Header -->
        <div class="text-center">
            <div class="flex items-center justify-center space-x-2 mb-6">
                <i class="fas fa-building text-blue-600 text-4xl"></i>
                <span class="text-3xl font-bold text-blue-600">ArchiFlow</span>
            </div>
            <h2 class="text-3xl font-bold text-gray-900 mb-2">Welcome Back</h2>
            <p class="text-gray-600">Sign in to your account to continue</p>
        </div>

        <!-- Login Form -->
        <div class="bg-white rounded-2xl shadow-xl p-8 relative">
            <!-- Loading Overlay -->
            <div id="loginLoadingOverlay" class="absolute inset-0 bg-white bg-opacity-90 rounded-2xl flex items-center justify-center z-10 hidden">
                <div class="text-center">
                    <div class="w-12 h-12 border-4 border-blue-200 border-t-blue-600 rounded-full animate-spin mx-auto mb-4"></div>
                    <p class="text-gray-600 font-medium">Signing you in...</p>
                    <p class="text-sm text-gray-500 mt-1">Please wait while we authenticate your account</p>
                </div>
            </div>
            
            <form id="loginForm" class="space-y-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-user mr-2 text-blue-600"></i>Username or Email
                    </label>
                    <input type="text" id="loginUsername" name="username" required
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300"
                           placeholder="Enter your username or email">
                    <div id="loginUsernameError" class="text-red-500 text-sm mt-1 hidden"></div>
                    <div id="loginUsernameSuccess" class="text-green-500 text-sm mt-1 hidden"></div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-lock mr-2 text-blue-600"></i>Password
                    </label>
                    <div class="relative">
                        <input type="password" id="loginPassword" name="password" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300 pr-12"
                               placeholder="Enter your password">
                        <button type="button" id="toggleLoginPassword" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div id="loginPasswordError" class="text-red-500 text-sm mt-1 hidden"></div>
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex items-center">
                        <input type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="ml-2 text-sm text-gray-600">Remember me</span>
                    </label>
                    <a href="#" class="text-sm text-blue-600 hover:text-blue-800 transition duration-300">Forgot password?</a>
                </div>

                <button type="submit" id="loginBtn" class="w-full bg-blue-600 text-white py-3 px-6 rounded-lg hover:bg-blue-700 transition duration-300 font-semibold flex items-center justify-center">
                    <i class="fas fa-sign-in-alt mr-2"></i>
                    <span class="loginBtnText">Sign In</span>
                    <div class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin ml-2 hidden" id="loginSpinner"></div>
                </button>

                <div id="loginMessage" class="hidden p-4 rounded-lg"></div>
            </form>

            <!-- Social Login removed -->
            <div class="mt-6 text-center text-sm text-gray-400">
                <!-- Google sign-in disabled -->
            </div>

            <!-- Divider -->
            <div class="mt-6">
                <div class="relative">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-gray-300"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="px-2 bg-white text-gray-500">New to ArchiFlow?</span>
                    </div>
                </div>
            </div>

            <!-- Register Link -->
            <div class="mt-6 text-center">
                <a href="register.php" class="w-full bg-gray-100 text-gray-700 py-3 px-6 rounded-lg hover:bg-gray-200 transition duration-300 font-semibold flex items-center justify-center">
                    <i class="fas fa-user-plus mr-2"></i>Create New Account
                </a>
            </div>
        </div>

        <!-- Back to Home -->
        <div class="text-center">
            <a href="index.php" class="text-blue-600 hover:text-blue-800 transition duration-300">
                <i class="fas fa-arrow-left mr-2"></i>Back to Home
            </a>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Real-time validation for login
    const loginUsername = document.getElementById('loginUsername');
    let loginValidationTimeout;
    
    loginUsername.addEventListener('input', function() {
        clearTimeout(loginValidationTimeout);
        loginValidationTimeout = setTimeout(() => {
            validateLoginField('loginUsername', this.value);
        }, 500);
    });
    
    // Toggle password visibility
    const toggleLoginPassword = document.getElementById('toggleLoginPassword');
    const loginPassword = document.getElementById('loginPassword');

    toggleLoginPassword.addEventListener('click', function() {
        const type = loginPassword.getAttribute('type') === 'password' ? 'text' : 'password';
        loginPassword.setAttribute('type', type);
        this.querySelector('i').classList.toggle('fa-eye');
        this.querySelector('i').classList.toggle('fa-eye-slash');
    });

    // Login form submission
    const loginForm = document.getElementById('loginForm');
    loginForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Show loading state
        showLoadingState();
        
        // Clear previous errors
        clearErrors();
        
        const formData = new FormData();
        formData.append('action', 'login');
        formData.append('username', document.getElementById('loginUsername').value.trim());
        formData.append('password', document.getElementById('loginPassword').value);
        console.log('Submitting login with username:', formData.get('username'));
        
        fetch('backend/auth.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status);
            console.log('Response ok:', response.ok);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text(); // Get as text first to see raw response
        })
        .then(text => {
            console.log('Raw response:', text);
            try {
                const data = JSON.parse(text);
                console.log('Parsed data:', data);
                // cache for redirect helper to read position when needed
                window.lastLoginData = data;
        if (data.success) {
                    showMessage('success', data.message);
                    // Redirect based on user type
                    setTimeout(() => {
                        const appBase = (function(){
                            try { return (document.querySelector('base')?.href || window.location.origin + window.location.pathname).replace(/\/$/, ''); } catch(e){ return ''; }
                        })();
                        const targetPath = data.redirect || getRedirectUrl(data.user.user_type);
                        const absolute = targetPath.startsWith('http') ? targetPath : (appBase ? appBase + '/' + targetPath.replace(/^\/?/, '') : targetPath);
                        window.location.href = absolute;
                    }, 1500);
                } else {
                    showMessage('error', data.message);
                }
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response text:', text);
                showMessage('error', 'Invalid response from server');
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            showMessage('error', 'An error occurred. Please try again. Error: ' + error.message);
        })
        .finally(() => {
            // Reset button state
            hideLoadingState();
        });
    });

    function clearErrors() {
        const errorElements = document.querySelectorAll('[id$="Error"]');
        errorElements.forEach(element => {
            element.classList.add('hidden');
            element.textContent = '';
        });
    }

    function showMessage(type, message) {
        const messageElement = document.getElementById('loginMessage');
        messageElement.className = `p-4 rounded-lg ${type === 'success' ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200'}`;
        messageElement.textContent = message;
        messageElement.classList.remove('hidden');
        
        // Hide message after 5 seconds
        setTimeout(() => {
            messageElement.classList.add('hidden');
        }, 5000);
    }

    function getRedirectUrl(userType) {
        // Prefer server-side redirect; this is a client fallback using returned payload
        if (userType === 'admin') return 'admin/dashboard.php';
        if (userType === 'client') return 'client/dashboard.php';
        if (userType === 'hr') return 'hr/hr-dashboard.php';
        if (userType === 'employee') {
            const pos = (window.lastLoginData && window.lastLoginData.user && window.lastLoginData.user.position || '').toLowerCase().replace(' ', '_');
            if (pos === 'architect') return 'employees/architects/architects-dashboard.php';
            if (pos === 'senior_architect') return 'employees/senior_architects/senior_architects-dashboard.php';
            if (pos === 'project_manager') return 'employees/project_manager/project_manager-dashboard.php';
            // Fallback for unknown employee position
            return 'employees/architects/architects-dashboard.php';
        }
        return 'index.php';
    }
    
    // Real-time validation function for login
    async function validateLoginField(fieldId, value) {
        if (!value.trim()) {
            hideLoginFieldMessages(fieldId);
            return;
        }
        
        try {
            const response = await fetch('backend/validate.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    field: 'username', // Check if username exists
                    value: value
                })
            });
            
            const result = await response.json();
            
            if (result.available) {
                showLoginFieldError(fieldId, 'Username not found');
            } else {
                showLoginFieldSuccess(fieldId, 'Username found');
            }
        } catch (error) {
            console.error('Validation error:', error);
        }
    }
    
    function showLoginFieldError(fieldId, message) {
        const errorElement = document.getElementById(fieldId + 'Error');
        const successElement = document.getElementById(fieldId + 'Success');
        
        if (errorElement) {
            errorElement.textContent = message;
            errorElement.classList.remove('hidden');
        }
        if (successElement) {
            successElement.classList.add('hidden');
        }
        
        // Update input border color
        const input = document.getElementById(fieldId);
        if (input) {
            input.classList.remove('border-green-500');
            input.classList.add('border-red-500');
        }
    }
    
    function showLoginFieldSuccess(fieldId, message) {
        const errorElement = document.getElementById(fieldId + 'Error');
        const successElement = document.getElementById(fieldId + 'Success');
        
        if (errorElement) {
            errorElement.classList.add('hidden');
        }
        if (successElement) {
            successElement.textContent = message;
            successElement.classList.remove('hidden');
        }
        
        // Update input border color
        const input = document.getElementById(fieldId);
        if (input) {
            input.classList.remove('border-red-500');
            input.classList.add('border-green-500');
        }
    }
    
    function hideLoginFieldMessages(fieldId) {
        const errorElement = document.getElementById(fieldId + 'Error');
        const successElement = document.getElementById(fieldId + 'Success');
        
        if (errorElement) {
            errorElement.classList.add('hidden');
        }
        if (successElement) {
            successElement.classList.add('hidden');
        }
        
        // Reset input border color
        const input = document.getElementById(fieldId);
        if (input) {
            input.classList.remove('border-red-500', 'border-green-500');
            input.classList.add('border-gray-300');
        }
    }
    
    // Loading state functions
    function showLoadingState() {
        const overlay = document.getElementById('loginLoadingOverlay');
        const loginBtn = document.getElementById('loginBtn');
        const formInputs = document.querySelectorAll('#loginForm input, #loginForm button');
        
        // Show overlay
        if (overlay) {
            overlay.classList.remove('hidden');
        }
        
        // Disable form elements
        formInputs.forEach(input => {
            input.disabled = true;
            input.classList.add('opacity-50', 'cursor-not-allowed');
        });
        
        // Disable submit button
        if (loginBtn) {
            loginBtn.disabled = true;
            loginBtn.classList.add('opacity-50', 'cursor-not-allowed');
        }
    }
    
    function hideLoadingState() {
        const overlay = document.getElementById('loginLoadingOverlay');
        const loginBtn = document.getElementById('loginBtn');
        const formInputs = document.querySelectorAll('#loginForm input, #loginForm button');
        
        // Hide overlay
        if (overlay) {
            overlay.classList.add('hidden');
        }
        
        // Enable form elements
        formInputs.forEach(input => {
            input.disabled = false;
            input.classList.remove('opacity-50', 'cursor-not-allowed');
        });
        
        // Enable submit button
        if (loginBtn) {
            loginBtn.disabled = false;
            loginBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        }
    }
});
</script>

<?php include 'backend/core/footer.php'; ?>
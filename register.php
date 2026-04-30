<?php include 'backend/core/header.php'; ?>

<main class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-blue-50 flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-4xl w-full space-y-8">
        <!-- Header -->
        <div class="text-center">
            <div class="flex items-center justify-center space-x-2 mb-6">
                <i class="fas fa-building text-blue-600 text-4xl"></i>
                <span class="text-3xl font-bold text-blue-600">ArchiFlow</span>
            </div>
            <h2 class="text-3xl font-bold text-gray-900 mb-2">Client Registration</h2>
            <p class="text-gray-600">Register as a client to start your architectural project with us</p>
        </div>

        <!-- Register Form -->
        <div class="bg-white rounded-2xl shadow-xl p-8 relative">
            <!-- Loading Overlay -->
            <div id="registerLoadingOverlay" class="absolute inset-0 bg-white bg-opacity-90 rounded-2xl flex items-center justify-center z-10 hidden">
                <div class="text-center">
                    <div class="w-12 h-12 border-4 border-blue-200 border-t-blue-600 rounded-full animate-spin mx-auto mb-4"></div>
                    <p class="text-gray-600 font-medium">Creating your account...</p>
                    <p class="text-sm text-gray-500 mt-1">Please wait while we set up your client account</p>
                </div>
            </div>
            <!-- Client Registration Notice -->
            <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-info-circle text-blue-600 mr-3"></i>
                    <div>
                        <h3 class="text-sm font-semibold text-blue-800">Client Registration</h3>
                        <p class="text-sm text-blue-700">Register here to become a client and start your architectural project with us.</p>
                    </div>
                </div>
            </div>

            <form id="registerForm" class="space-y-6">
                <!-- Hidden field to set user type as client -->
                <input type="hidden" name="user_type" value="client">

                <!-- Personal Information -->
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-user mr-2 text-blue-600"></i>Personal Information
                    </h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">First Name *</label>
                            <input type="text" id="firstName" name="first_name" required
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300"
                                   placeholder="Enter your first name">
                            <div id="firstNameError" class="text-red-500 text-sm mt-1 hidden"></div>
                            <div id="firstNameSuccess" class="text-green-500 text-sm mt-1 hidden"></div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Last Name *</label>
                            <input type="text" id="lastName" name="last_name" required
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300"
                                   placeholder="Enter your last name">
                            <div id="lastNameError" class="text-red-500 text-sm mt-1 hidden"></div>
                            <div id="lastNameSuccess" class="text-green-500 text-sm mt-1 hidden"></div>
                        </div>
                    </div>
                </div>

                <!-- Account Information -->
                <div class="border-t pt-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-key mr-2 text-blue-600"></i>Account Information
                    </h3>
                    
                    <div class="space-y-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Username *</label>
                            <input type="text" id="username" name="username" required
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300"
                                   placeholder="Choose a unique username">
                            <div id="usernameError" class="text-red-500 text-sm mt-1 hidden"></div>
                            <div id="usernameSuccess" class="text-green-500 text-sm mt-1 hidden"></div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Email Address *</label>
                            <input type="email" id="email" name="email" required
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300"
                                   placeholder="your.email@example.com">
                            <div id="emailError" class="text-red-500 text-sm mt-1 hidden"></div>
                            <div id="emailSuccess" class="text-green-500 text-sm mt-1 hidden"></div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Password *</label>
                            <div class="relative">
                                <input type="password" id="password" name="password" required
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300 pr-12"
                                       placeholder="Create a strong password">
                                <button type="button" id="togglePassword" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <!-- Password Strength Indicator -->
                            <div id="passwordStrength" class="mt-2 hidden">
                                <div class="flex items-center space-x-2 mb-2">
                                    <div class="flex-1 bg-gray-200 rounded-full h-2">
                                        <div id="strengthBar" class="h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                                    </div>
                                    <span id="strengthText" class="text-sm font-medium">Weak</span>
                                </div>
                                <div id="passwordRequirements" class="text-xs space-y-1">
                                    <div id="req-length" class="flex items-center">
                                        <i class="fas fa-times text-red-500 mr-2"></i>
                                        <span>At least 8 characters</span>
                                    </div>
                                    <div id="req-uppercase" class="flex items-center">
                                        <i class="fas fa-times text-red-500 mr-2"></i>
                                        <span>One uppercase letter</span>
                                    </div>
                                    <div id="req-lowercase" class="flex items-center">
                                        <i class="fas fa-times text-red-500 mr-2"></i>
                                        <span>One lowercase letter</span>
                                    </div>
                                    <div id="req-number" class="flex items-center">
                                        <i class="fas fa-times text-red-500 mr-2"></i>
                                        <span>One number</span>
                                    </div>
                                    <div id="req-special" class="flex items-center">
                                        <i class="fas fa-times text-red-500 mr-2"></i>
                                        <span>One special character</span>
                                    </div>
                                </div>
                            </div>
                            <div id="passwordError" class="text-red-500 text-sm mt-1 hidden"></div>
                        </div>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="border-t pt-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-address-book mr-2 text-blue-600"></i>Contact Information (Optional)
                    </h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                            <input type="tel" id="phone" name="phone"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300"
                                   placeholder="+63 912 345 6789">
                            <div id="phoneError" class="text-red-500 text-sm mt-1 hidden"></div>
                            <div id="phoneSuccess" class="text-green-500 text-sm mt-1 hidden"></div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Address</label>
                            <input type="text" id="address" name="address"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300"
                                   placeholder="Your complete address">
                        </div>
                    </div>
                </div>

                <!-- Terms and Conditions -->
                <div class="border-t pt-6">
                    <div class="flex items-start">
                        <input type="checkbox" id="terms" required class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <label for="terms" class="ml-3 text-sm text-gray-600">
                            I agree to the 
                            <a href="#" id="openTos" class="text-blue-600 hover:text-blue-800 underline" role="button">Terms of Service</a> 
                            and 
                            <a href="#" id="openPrivacy" class="text-blue-600 hover:text-blue-800 underline" role="button">Privacy Policy</a> *
                        </label>
                    </div>
                    <div id="termsError" class="text-red-500 text-sm mt-1 hidden"></div>
                </div>

                <!-- Submit Button -->
                <div class="pt-6">
                    <button type="submit" id="registerBtn" class="w-full bg-blue-600 text-white py-4 px-6 rounded-lg hover:bg-blue-700 transition duration-300 font-semibold flex items-center justify-center text-lg">
                        <i class="fas fa-user-plus mr-2"></i>
                        <span class="registerBtnText">Register as Client</span>
                        <div class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin ml-2 hidden" id="registerSpinner"></div>
                    </button>
                </div>

                <div id="registerMessage" class="hidden p-4 rounded-lg"></div>
            </form>

            <!-- Divider -->
            <div class="mt-8">
                <div class="relative">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-gray-300"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="px-2 bg-white text-gray-500">Already have an account?</span>
                    </div>
                </div>
            </div>

            <!-- Login Link -->
            <div class="mt-6 text-center">
                <a href="login.php" class="w-full bg-gray-100 text-gray-700 py-3 px-6 rounded-lg hover:bg-gray-200 transition duration-300 font-semibold flex items-center justify-center">
                    <i class="fas fa-sign-in-alt mr-2"></i>Sign In to Existing Account
                </a>
            </div>
        </div>

        <!-- Back to Home -->
        <div class="text-center mt-8">
            <a href="index.php" class="text-blue-600 hover:text-blue-800 transition duration-300">
                <i class="fas fa-arrow-left mr-2"></i>Back to Home
            </a>
        </div>
    </div>
</main>

<!-- Terms of Service Modal -->
<div id="tosModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-black/40" data-close="tosModal"></div>
    <div class="relative bg-white w-full max-w-3xl rounded-lg shadow-lg ring-1 ring-slate-200 p-6 mx-4 animate-fadeIn">
        <div class="flex items-start justify-between mb-3">
            <h2 class="text-lg font-semibold">Terms of Service</h2>
            <button class="text-slate-500 hover:text-slate-700" data-close="tosModal" aria-label="Close Terms">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="prose prose-sm max-w-none text-slate-700 overflow-y-auto max-h-[60vh]">
            <p class="mb-2">Welcome to ArchiFlow. By creating an account, you agree to the following terms:</p>
            <ol class="list-decimal ml-5 space-y-1">
                <li>Use the platform in compliance with applicable laws and company policies.</li>
                <li>Provide accurate information during registration and keep your credentials secure.</li>
                <li>Do not upload content that infringes on intellectual property or privacy rights.</li>
                <li>We may update these terms from time to time; continued use constitutes acceptance.</li>
                <li>Service is provided “as is” without warranties to the extent permitted by law.</li>
            </ol>
            <p class="mt-3 text-sm text-slate-500">For a bespoke policy, we can replace this text with your official Terms page.</p>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button class="px-3 py-2 text-sm rounded bg-slate-600 text-white hover:bg-slate-700" data-close="tosModal">Close</button>
        </div>
    </div>
    <style>
        @keyframes fadeIn { from { opacity:0; transform:translateY(4px);} to { opacity:1; transform:translateY(0);} }
        .animate-fadeIn { animation: fadeIn .18s ease-out; }
    </style>
    </div>

<!-- Privacy Policy Modal -->
<div id="privacyModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-black/40" data-close="privacyModal"></div>
    <div class="relative bg-white w-full max-w-3xl rounded-lg shadow-lg ring-1 ring-slate-200 p-6 mx-4 animate-fadeIn">
        <div class="flex items-start justify-between mb-3">
            <h2 class="text-lg font-semibold">Privacy Policy</h2>
            <button class="text-slate-500 hover:text-slate-700" data-close="privacyModal" aria-label="Close Privacy">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="prose prose-sm max-w-none text-slate-700 overflow-y-auto max-h-[60vh]">
            <p class="mb-2">We care about your privacy. This policy explains how we process your data:</p>
            <ul class="list-disc ml-5 space-y-1">
                <li>We collect information you provide (name, email, contact) to create and manage your account.</li>
                <li>We use technical and organizational measures to protect your data.</li>
                <li>Your data is used to deliver services and communicate updates related to your projects.</li>
                <li>We do not sell your personal information. Limited sharing may occur with service providers as needed.</li>
                <li>You may request access, correction, or deletion of your data as permitted by law.</li>
            </ul>
            <p class="mt-3 text-sm text-slate-500">Replace this with your official Privacy Policy when available.</p>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button class="px-3 py-2 text-sm rounded bg-slate-600 text-white hover:bg-slate-700" data-close="privacyModal">Close</button>
        </div>
    </div>
    <style>
        @keyframes fadeIn { from { opacity:0; transform:translateY(4px);} to { opacity:1; transform:translateY(0);} }
        .animate-fadeIn { animation: fadeIn .18s ease-out; }
    </style>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Real-time validation
    const fieldsToValidate = ['username', 'email', 'firstName', 'lastName', 'phone'];
    const validationTimeouts = {};
    
    // Add real-time validation to fields
    fieldsToValidate.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.addEventListener('input', function() {
                clearTimeout(validationTimeouts[fieldId]);
                validationTimeouts[fieldId] = setTimeout(() => {
                    validateField(fieldId, this.value);
                }, 500); // 500ms delay
            });
        }
    });
    
    // Password strength indicator
    const passwordField = document.getElementById('password');
    const passwordStrength = document.getElementById('passwordStrength');
    
    passwordField.addEventListener('input', function() {
        const password = this.value;
        if (password.length > 0) {
            passwordStrength.classList.remove('hidden');
            updatePasswordStrength(password);
        } else {
            passwordStrength.classList.add('hidden');
        }
    });
    
    // Toggle password visibility
    const togglePassword = document.getElementById('togglePassword');
    const password = document.getElementById('password');

    togglePassword.addEventListener('click', function() {
        const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
        password.setAttribute('type', type);
        this.querySelector('i').classList.toggle('fa-eye');
        this.querySelector('i').classList.toggle('fa-eye-slash');
    });

    // No need for user type validation since it's automatically set to 'client'

    // Register form submission
    const registerForm = document.getElementById('registerForm');
    registerForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        console.log('Form submitted!');
        
        const registerBtn = document.getElementById('registerBtn');
        const registerSpinner = document.getElementById('registerSpinner');
        const registerBtnText = document.querySelector('.registerBtnText');
        
        // Validate terms acceptance
        const termsAccepted = document.getElementById('terms').checked;
        if (!termsAccepted) {
            showFieldError('termsError', 'You must accept the terms and conditions');
            return;
        }
        
        // Show loading state
        showLoadingState();
        
        // Clear previous errors
        clearErrors();
        
        // Try manual FormData creation instead of using form reference
        const formData = new FormData();
        formData.append('action', 'register');
        formData.append('username', document.getElementById('username').value);
        formData.append('email', document.getElementById('email').value);
        formData.append('first_name', document.getElementById('firstName').value);
        formData.append('last_name', document.getElementById('lastName').value);
        formData.append('password', document.getElementById('password').value);
        formData.append('phone', document.getElementById('phone').value);
        formData.append('address', document.getElementById('address').value);
        formData.append('user_type', 'client');
        
        // Debug: Log form data being sent
        console.log('Form data being sent:');
        for (let [key, value] of formData.entries()) {
            console.log(key + ': ' + value);
        }
        
        // Debug: Check if form data has the expected keys
        console.log('FormData has username:', formData.has('username'));
        console.log('FormData has email:', formData.has('email'));
        console.log('FormData has first_name:', formData.has('first_name'));
        console.log('FormData has last_name:', formData.has('last_name'));
        
        // Also log individual field values
        console.log('Username field value:', document.getElementById('username').value);
        console.log('Email field value:', document.getElementById('email').value);
        console.log('First name field value:', document.getElementById('firstName').value);
        console.log('Last name field value:', document.getElementById('lastName').value);
        
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
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data);
            if (data.success) {
                showMessage('success', data.message);
                // Redirect to login page after successful registration
                setTimeout(() => {
                    window.location.href = 'login.php';
                }, 2000);
            } else {
                showMessage('error', data.message);
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

    function showFieldError(fieldId, message) {
        const errorElement = document.getElementById(fieldId + 'Error');
        if (errorElement) {
            errorElement.textContent = message;
            errorElement.classList.remove('hidden');
        }
    }

    function showMessage(type, message) {
        const messageElement = document.getElementById('registerMessage');
        messageElement.className = `p-4 rounded-lg ${type === 'success' ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200'}`;
        messageElement.textContent = message;
        messageElement.classList.remove('hidden');
        
        // Hide message after 5 seconds
        setTimeout(() => {
            messageElement.classList.add('hidden');
        }, 5000);
    }
    
    // Real-time validation function
    async function validateField(fieldId, value) {
        if (!value.trim()) {
            hideFieldMessages(fieldId);
            return;
        }
        
        try {
            const response = await fetch('backend/validate.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    field: fieldId,
                    value: value
                })
            });
            
            const result = await response.json();
            
            if (result.available) {
                showFieldSuccess(fieldId, result.message);
            } else {
                showFieldError(fieldId, result.message);
            }
        } catch (error) {
            console.error('Validation error:', error);
        }
    }
    
    function showFieldSuccess(fieldId, message) {
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
    
    function hideFieldMessages(fieldId) {
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
    
    // Password strength function
    function updatePasswordStrength(password) {
        const requirements = {
            length: password.length >= 8,
            uppercase: /[A-Z]/.test(password),
            lowercase: /[a-z]/.test(password),
            number: /[0-9]/.test(password),
            special: /[^A-Za-z0-9]/.test(password)
        };
        
        // Update requirement indicators
        Object.keys(requirements).forEach(req => {
            const element = document.getElementById(`req-${req}`);
            const icon = element.querySelector('i');
            
            if (requirements[req]) {
                icon.classList.remove('fa-times', 'text-red-500');
                icon.classList.add('fa-check', 'text-green-500');
            } else {
                icon.classList.remove('fa-check', 'text-green-500');
                icon.classList.add('fa-times', 'text-red-500');
            }
        });
        
        // Calculate strength
        const score = Object.values(requirements).filter(Boolean).length;
        const strengthBar = document.getElementById('strengthBar');
        const strengthText = document.getElementById('strengthText');
        
        let strength = 'Weak';
        let color = 'bg-red-500';
        let width = '20%';
        
        if (score >= 4) {
            strength = 'Strong';
            color = 'bg-green-500';
            width = '100%';
        } else if (score >= 3) {
            strength = 'Good';
            color = 'bg-yellow-500';
            width = '75%';
        } else if (score >= 2) {
            strength = 'Fair';
            color = 'bg-orange-500';
            width = '50%';
        }
        
        strengthBar.className = `h-2 rounded-full transition-all duration-300 ${color}`;
        strengthBar.style.width = width;
        strengthText.textContent = strength;
        strengthText.className = `text-sm font-medium ${color.replace('bg-', 'text-')}`;
    }
    
    // Loading state functions
    function showLoadingState() {
        const overlay = document.getElementById('registerLoadingOverlay');
        const registerBtn = document.getElementById('registerBtn');
        const formInputs = document.querySelectorAll('#registerForm input, #registerForm button');
        
        // Show overlay
        overlay.classList.remove('hidden');
        
        // Disable form elements
        formInputs.forEach(input => {
            input.disabled = true;
            input.classList.add('opacity-50', 'cursor-not-allowed');
        });
        
        // Disable submit button
        registerBtn.disabled = true;
        registerBtn.classList.add('opacity-50', 'cursor-not-allowed');
    }
    
    function hideLoadingState() {
        const overlay = document.getElementById('registerLoadingOverlay');
        const registerBtn = document.getElementById('registerBtn');
        const formInputs = document.querySelectorAll('#registerForm input, #registerForm button');
        
        // Hide overlay
        overlay.classList.add('hidden');
        
        // Enable form elements
        formInputs.forEach(input => {
            input.disabled = false;
            input.classList.remove('opacity-50', 'cursor-not-allowed');
        });
        
        // Enable submit button
        registerBtn.disabled = false;
        registerBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    }

        // Modal helpers
        function openModal(id){ var el=document.getElementById(id); if(!el) return; el.classList.remove('hidden'); }
        function closeModal(id){ var el=document.getElementById(id); if(!el) return; el.classList.add('hidden'); }
        // Wire triggers
        var tosLink = document.getElementById('openTos');
        var privacyLink = document.getElementById('openPrivacy');
        if (tosLink) tosLink.addEventListener('click', function(e){ e.preventDefault(); openModal('tosModal'); });
        if (privacyLink) privacyLink.addEventListener('click', function(e){ e.preventDefault(); openModal('privacyModal'); });
        // Close buttons and overlay
        document.addEventListener('click', function(e){
            var closeFor = e.target.getAttribute && e.target.getAttribute('data-close');
            if (!closeFor && e.target && e.target.closest) {
                var cbtn = e.target.closest('[data-close]');
                if (cbtn) closeFor = cbtn.getAttribute('data-close');
            }
            if (closeFor) { closeModal(closeFor); }
        });
        // Escape key to close
        document.addEventListener('keydown', function(e){ if (e.key==='Escape'){ ['tosModal','privacyModal'].forEach(closeModal); }});
});
</script>

<?php include 'backend/core/footer.php'; ?>

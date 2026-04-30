<?php
session_start();
require_once '../backend/auth.php';
// Only allow logged-in admin users
$auth = new Auth();
if (!$auth->isLoggedIn() || ($_SESSION['user_type'] ?? '') !== 'admin') {
	header('Location: ../login.php');
	exit();
}
$currentUser = $auth->getCurrentUser();
$db = getDB();
$userData = null;
$success = null;
$error = null;
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

if ($db) {
	// Load latest data for the current user
	$stmt = $db->prepare('SELECT user_id, username, email, user_type, position, first_name, last_name, phone, address, is_active, created_at FROM users WHERE user_id = ?');
	$stmt->execute([$currentUser['user_id']]);
	$userData = $stmt->fetch();
}
// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
	try {
		if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
			throw new Exception('Invalid form token.');
		}
		if (!$db) {
			throw new Exception('Database connection failed');
		}
	// Collect inputs
	$username   = trim($_POST['username'] ?? '');
	$email      = trim($_POST['email'] ?? '');
	$first_name = trim($_POST['first_name'] ?? '');
	$last_name  = trim($_POST['last_name'] ?? '');
	$phone      = trim($_POST['phone'] ?? '');
	$address    = trim($_POST['address'] ?? '');
	$password   = (string)($_POST['password'] ?? '');
	$confirm    = (string)($_POST['confirm_password'] ?? '');
		// Basic validation
		if ($username === '' || $email === '' || $first_name === '' || $last_name === '') {
			throw new Exception('Please fill in all required fields.');
		}
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new Exception('Please provide a valid email address.');
		}
		if ($password !== '') {
			if (strlen($password) < 6) {
				throw new Exception('New password must be at least 6 characters.');
			}
			if ($password !== $confirm) {
				throw new Exception('Password and confirmation do not match.');
			}
		}

	// Uniqueness checks
	$check = $db->prepare('SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?');
	$check->execute([$username, $email, $currentUser['user_id']]);
	if ($check->fetch()) {
			throw new Exception('Username or email already exists.');
		}

		// Build update
		$fields = [
			'username'   => $username,
			'email'      => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'phone'      => ($phone !== '' ? $phone : null),
			'address'    => ($address !== '' ? $address : null),
		];
		$setParts = [];
		$values = [];
		foreach ($fields as $col => $val) {
			$setParts[] = "$col = ?";
			$values[] = $val;
		}
		if ($password !== '') {
			// Keep hashing scheme consistent with current app (sha256)
			$setParts[] = 'password = ?';
			$values[] = hash('sha256', $password);
	}
	$values[] = $currentUser['user_id'];

	$sql = 'UPDATE users SET ' . implode(', ', $setParts) . ' WHERE user_id = ?';
	$upd = $db->prepare($sql);
	$ok = $upd->execute($values);
		if ($ok) {
			$success = 'Profile updated successfully.';
			// Refresh loaded data
			$stmt = $db->prepare('SELECT user_id, username, email, user_type, position, first_name, last_name, phone, address, is_active, created_at FROM users WHERE user_id = ?');
			$stmt->execute([$currentUser['user_id']]);
			$userData = $stmt->fetch();
			// Update session display names if changed
			$_SESSION['username'] = $userData['username'];
			$_SESSION['email'] = $userData['email'];
			$_SESSION['first_name'] = $userData['first_name'];
			$_SESSION['last_name'] = $userData['last_name'];
		} else {
			throw new Exception('No changes were saved.');
		}
	} catch (Exception $e) {
		$error = $e->getMessage();
	}
}
?>

<?php include '../backend/core/header.php'; ?>

<main class="p-6">
	<div class="max-w-3xl mx-auto">
		<!-- Header -->
		<div class="mb-8">
			<div class="flex items-center mb-4">
				<a href="admin/dashboard.php" class="text-blue-600 hover:text-blue-800 mr-4">
					<i class="fas fa-arrow-left"></i>
				</a>
				<div>
					<h1 class="text-3xl font-bold text-gray-900">My Profile</h1>
					<p class="text-gray-600 mt-2">View and update your account information</p>
				</div>
			</div>
		</div>

	<!-- Alerts -->
	<?php if ($success): ?>
			<div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
				<div class="flex">
					<div class="flex-shrink-0">
						<i class="fas fa-check-circle text-green-500"></i>
					</div>
					<div class="ml-3 text-sm text-green-800"><?php echo htmlspecialchars($success); ?></div>
				</div>
			</div>
		<?php endif; ?>
		<?php if ($error): ?>
			<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
				<div class="flex">
					<div class="flex-shrink-0">
						<i class="fas fa-exclamation-circle text-red-500"></i>
					</div>
					<div class="ml-3 text-sm text-red-800"><?php echo htmlspecialchars($error); ?></div>
				</div>
			</div>
	<?php endif; ?>

	<?php if ($userData): ?>
		<form method="POST" class="space-y-8">
			<input type="hidden" name="action" value="update_profile">
			<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" />

			<!-- Basic Info -->
			<div class="bg-white rounded-lg shadow-lg p-8">
				<h2 class="text-xl font-semibold text-gray-900 mb-6">Basic Information</h2>
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
				<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-2">Username *</label>
						<input type="text" name="username" value="<?php echo htmlspecialchars($userData['username']); ?>" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Choose a username">
					</div>
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-2">Email Address *</label>
						<input type="email" name="email" value="<?php echo htmlspecialchars($userData['email']); ?>" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter email address">
					</div>
				</div>
				<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
						<input type="tel" name="phone" value="<?php echo htmlspecialchars($userData['phone'] ?? ''); ?>" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter phone number">
					</div>
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-2">Address</label>
						<input type="text" name="address" value="<?php echo htmlspecialchars($userData['address'] ?? ''); ?>" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter address">
					</div>
				</div>
			</div>

			<!-- Security -->
			<div class="bg-white rounded-lg shadow-lg p-8">
				<h2 class="text-xl font-semibold text-gray-900 mb-6">Security</h2>
				<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
						<input type="password" name="password" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Enter new password (optional)">
					</div>
					<div>
						<label class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
						<input type="password" name="confirm_password" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="Re-enter new password">
					</div>
				</div>
			</div>

			<div class="flex justify-end space-x-4">
				<a href="admin/dashboard.php" class="px-6 py-3 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition duration-300">Cancel</a>
				<button type="submit" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-300 flex items-center">
					<i class="fas fa-save mr-2"></i>
					Save Changes
				</button>
			</div>
		</form>
		<?php endif; ?>
	</div>
    
</main>

<?php include '../backend/core/footer.php'; ?>

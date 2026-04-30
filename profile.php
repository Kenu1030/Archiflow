<?php
// Global Profile Page for all roles
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/backend/connection/connect.php';
$pdo = getDB();
if (!$pdo) { http_response_code(500); echo 'Database connection failed.'; exit; }

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { header('Location: login.php'); exit; }

// CSRF token
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_token'];

$errors = [];
$success = '';

// Fetch current user
$stmt = $pdo->prepare('SELECT user_id, username, email, user_type, position, first_name, last_name, phone, address, profile_image FROM users WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { session_destroy(); header('Location: login.php'); exit; }

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $removeImage = !empty($_POST['remove_image']);

    // Basic validation
    if ($username === '') { $errors[] = 'Username is required.'; }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Valid email is required.'; }
    if ($password !== '' && $password !== $confirm) { $errors[] = 'Passwords do not match.'; }

    // Unique username/email check
    $chk = $pdo->prepare('SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id <> ? LIMIT 1');
    $chk->execute([$username, $email, $userId]);
    if ($chk->fetch()) { $errors[] = 'Username or email already in use.'; }

    // Handle avatar upload (store file path instead of raw binary for varchar(255) column)
    $newAvatarPath = null;
    if (!$removeImage && isset($_FILES['profile_image']) && is_uploaded_file($_FILES['profile_image']['tmp_name'])) {
      $f = $_FILES['profile_image'];
      if ($f['error'] === UPLOAD_ERR_OK) {
        if ($f['size'] > 2 * 1024 * 1024) {
          $errors[] = 'Profile image must be 2MB or less.';
        } else {
          $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
          $allowed = ['png','jpg','jpeg','gif','webp'];
          if (!in_array($ext, $allowed, true)) {
            $errors[] = 'Unsupported image type.';
          } else {
            $baseDir = __DIR__ . '/uploads/avatars/' . $userId;
            if (!is_dir($baseDir)) { @mkdir($baseDir, 0777, true); }
            if (!is_dir($baseDir)) { $errors[] = 'Failed to create avatar directory.'; }
            if (!$errors) {
              // Remove previous file if path stored
              if (!empty($user['profile_image'])) {
                $oldPath = __DIR__ . '/' . ltrim($user['profile_image'], '/');
                if (is_file($oldPath)) { @unlink($oldPath); }
              }
              $safeName = 'avatar_' . $userId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
              $destRel = 'uploads/avatars/' . $userId . '/' . $safeName;
              $destAbs = __DIR__ . '/' . $destRel;
              if (move_uploaded_file($f['tmp_name'], $destAbs)) {
                $newAvatarPath = $destRel;
              } else {
                $errors[] = 'Failed to save uploaded image.';
              }
            }
          }
        }
      } else {
        $errors[] = 'Image upload failed.';
      }
    }

    if (!$errors) {
        // Build update
        $fields = ['username = ?', 'email = ?', 'first_name = ?', 'last_name = ?', 'phone = ?', 'address = ?'];
        $vals = [$username, $email, $first, $last, $phone !== '' ? $phone : null, $address !== '' ? $address : null];

        if ($password !== '') {
            $hashed = hash('sha256', $password);
            $fields[] = 'password = ?';
            $vals[] = $hashed;
        }
        if ($removeImage) {
          // Delete existing file
          if (!empty($user['profile_image'])) {
            $oldPath = __DIR__ . '/' . ltrim($user['profile_image'], '/');
            if (is_file($oldPath)) { @unlink($oldPath); }
          }
          $fields[] = 'profile_image = NULL';
        } elseif ($newAvatarPath !== null) {
          $fields[] = 'profile_image = ?';
          $vals[] = $newAvatarPath;
        }
        $vals[] = $userId;

        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE user_id = ?';
        $upd = $pdo->prepare($sql);
        $ok = $upd->execute($vals);
        if ($ok) {
            // Refresh session display fields
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            $_SESSION['first_name'] = $first;
            $_SESSION['last_name'] = $last;

            $success = 'Profile updated successfully';
            // Re-fetch user for display
            $stmt = $pdo->prepare('SELECT user_id, username, email, user_type, position, first_name, last_name, phone, address, profile_image FROM users WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $errors[] = 'Failed to update profile.';
        }
    }
}
// Suppress footer on this page
$HIDE_FOOTER = true;
include_once __DIR__ . '/backend/core/header.php';
?>

<section class="bg-white py-8">
  <div class="max-w-full px-4">
    <div class="flex items-center space-x-3 mb-6">
      <div class="w-12 h-12 bg-blue-600 text-white rounded-lg flex items-center justify-center">
        <i class="fas fa-user-cog"></i>
      </div>
      <div>
        <h1 class="text-2xl font-semibold">Profile Settings</h1>
        <p class="text-gray-500">Manage your account information</p>
      </div>
    </div>

    <?php if ($errors): ?>
      <div class="bg-red-50 text-red-700 p-4 rounded-lg mb-4">
        <ul class="list-disc ml-6">
          <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php elseif ($success): ?>
      <div class="bg-green-50 text-green-700 p-4 rounded-lg mb-4"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
      <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">

        <div class="md:col-span-2 flex items-center space-x-4">
          <div class="w-20 h-20 rounded-full overflow-hidden bg-gray-200 flex items-center justify-center">
            <?php if (!empty($user['profile_image'])): ?>
              <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Avatar" class="w-full h-full object-cover">
            <?php else: ?>
              <i class="fas fa-user text-3xl text-gray-500"></i>
            <?php endif; ?>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Profile Image</label>
            <input type="file" name="profile_image" accept="image/*" class="block w-full text-sm text-gray-700">
            <label class="inline-flex items-center mt-2 text-sm text-gray-600">
              <input type="checkbox" name="remove_image" class="mr-2">
              Remove current image
            </label>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700">Username</label>
          <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required class="mt-1 w-full border rounded-lg p-2">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Email</label>
          <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required class="mt-1 w-full border rounded-lg p-2">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700">First Name</label>
          <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" class="mt-1 w-full border rounded-lg p-2">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Last Name</label>
          <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" class="mt-1 w-full border rounded-lg p-2">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700">Phone</label>
          <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" class="mt-1 w-full border rounded-lg p-2">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Address</label>
          <textarea name="address" rows="3" class="mt-1 w-full border rounded-lg p-2"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700">Role</label>
          <input type="text" value="<?php echo htmlspecialchars($user['user_type']); ?>" class="mt-1 w-full border rounded-lg p-2 bg-gray-100" disabled>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Position</label>
          <input type="text" value="<?php echo htmlspecialchars($user['position'] ?? ''); ?>" class="mt-1 w-full border rounded-lg p-2 bg-gray-100" disabled>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700">New Password</label>
          <input type="password" name="password" class="mt-1 w-full border rounded-lg p-2" placeholder="Leave blank to keep current">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Confirm Password</label>
          <input type="password" name="confirm_password" class="mt-1 w-full border rounded-lg p-2" placeholder="Confirm new password">
        </div>

        <div class="md:col-span-2 flex justify-end">
          <button class="bg-blue-600 text-white px-5 py-2 rounded-lg">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</section>

<?php include_once __DIR__ . '/backend/core/footer.php'; ?>

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

// Get settings from database
$db = getDB();
$settings = [];
if ($db) {
    $query = "SELECT setting_name, setting_value FROM settings";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
}

// Handle form submission
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form token.';
    } else {
        try {
            // Editable settings whitelist
            $editable = ['tax_rate','working_hours_per_day','overtime_rate','system_version'];
            foreach ($_POST as $key => $value) {
                if (in_array($key, ['action','submit','csrf_token'], true)) { continue; }
                if (!in_array($key, $editable, true)) { continue; }
                $val = (string)$value;
                $updateStmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_name = ?");
                $updateStmt->execute([$val, $key]);
                if ($updateStmt->rowCount() === 0) {
                    $ins = $db->prepare("INSERT INTO settings (setting_name, setting_value) VALUES (?, ?)");
                    $ins->execute([$key, $val]);
                }
                $settings[$key] = $val;
            }
            // Update last-updated timestamp
            $now = date('Y-m-d H:i:s');
            $upd = $db->prepare("UPDATE settings SET setting_value=? WHERE setting_name='settings_last_updated'");
            $upd->execute([$now]);
            if ($upd->rowCount() === 0) {
                $ins2 = $db->prepare("INSERT INTO settings (setting_name, setting_value) VALUES ('settings_last_updated', ?)");
                $ins2->execute([$now]);
            }
            $settings['settings_last_updated'] = $now;
            $success = "Settings updated successfully!";
        } catch (Exception $e) {
            $error = "Error updating settings: " . $e->getMessage();
        }
    }
}
?>

<?php include '../../backend/core/header.php'; ?>

<main class="p-6">
    <div class="max-w-full">
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">System Settings</h1>
            <p class="text-gray-600 mt-2">Configure system-wide settings and preferences</p>
        </div>

        <!-- Settings Form -->
        <form method="POST" class="space-y-8">
            <input type="hidden" name="action" value="update_settings">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" />
            

            <!-- Financial Settings -->
            <div class="bg-white rounded-lg shadow-lg p-8">
                <h2 class="text-xl font-semibold text-gray-900 mb-6">Financial Settings</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Tax Rate (%)</label>
                        <input type="number" name="tax_rate" step="0.01" value="<?php echo htmlspecialchars($settings['tax_rate'] ?? '12.00'); ?>" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="12.00">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Working Hours per Day</label>
                        <input type="number" name="working_hours_per_day" value="<?php echo htmlspecialchars($settings['working_hours_per_day'] ?? '8'); ?>" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="8">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Overtime Rate Multiplier</label>
                        <input type="number" name="overtime_rate" step="0.01" value="<?php echo htmlspecialchars($settings['overtime_rate'] ?? '1.25'); ?>" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300" placeholder="1.25">
                    </div>
                </div>
            </div>

            <!-- System Information -->
            <div class="bg-white rounded-lg shadow-lg p-8">
                <h2 class="text-xl font-semibold text-gray-900 mb-6">System Information</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">System Version</label>
                        <input type="text" name="system_version" value="<?php echo htmlspecialchars($settings['system_version'] ?? 'ArchiFlow v1.0.0'); ?>" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-300">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Last Updated</label>
                        <?php $lu = $settings['settings_last_updated'] ?? null; $luFmt = $lu ? date('F j, Y', strtotime($lu)) : '—'; ?>
                        <input type="text" value="<?php echo htmlspecialchars($luFmt); ?>" readonly class="w-full px-4 py-3 border border-gray-300 rounded-lg bg-gray-50 text-gray-500">
                    </div>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($success)): ?>
                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle text-green-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-green-700"><?php echo htmlspecialchars($success); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle text-red-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-red-700"><?php echo htmlspecialchars($error); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Form Actions -->
            <div class="flex justify-end space-x-4">
                <button type="button" onclick="resetForm()" class="px-6 py-3 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition duration-300">
                    Reset
                </button>
                <button type="submit" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-300 flex items-center">
                    <i class="fas fa-save mr-2"></i>
                    Save Settings
                </button>
            </div>
        </form>
    </div>
</main>

<script>
function resetForm() {
    if (confirm('Are you sure you want to reset all settings to their default values?')) {
        document.querySelector('form').reset();
    }
}
</script>

<?php include '../../backend/core/footer.php'; ?>

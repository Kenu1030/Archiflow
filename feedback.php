<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
include 'db.php';

$page_title = 'Feedback';
include __DIR__ . '/includes/header.php';
?>

<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-purple-50 flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-3xl mx-auto">
      <!-- Header -->
      <div class="mb-6 flex items-center justify-between">
        <div>
          <div class="inline-flex items-center px-4 py-2 bg-blue-100 text-blue-800 rounded-full text-sm font-medium mb-3">
            <i class="fas fa-comments mr-2"></i>Feedback
          </div>
          <h1 class="text-2xl md:text-3xl font-black text-gray-900">Share your feedback</h1>
          <p class="text-gray-600 mt-1">Tell us what you think. Your feedback helps us improve ArchiFlow.</p>
        </div>
      </div>

      <!-- Feedback Form -->
      <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6 mb-8">
        <?php
        $success = null; $error = null;
        if (isset($_POST['submit_feedback'])) {
            $user_id = $_SESSION['user_id'];
            $feedback = trim($_POST['feedback']);
            if ($feedback) {
                // Ensure feedback table exists
                $conn->query("CREATE TABLE IF NOT EXISTS feedback (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  user_id INT NOT NULL,
                  feedback TEXT NOT NULL,
                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  FOREIGN KEY (user_id) REFERENCES users(id)
                )");

                $stmt = $conn->prepare("INSERT INTO feedback (user_id, feedback) VALUES (?, ?)");
                $stmt->bind_param("is", $user_id, $feedback);
                if ($stmt->execute()) {
                    $success = 'Thank you for your feedback!';
                } else {
                    $error = 'Something went wrong. Please try again.';
                }
                $stmt->close();
            } else {
                $error = 'Please enter your feedback.';
            }
        }
        ?>

        <?php if ($success): ?>
          <div class="mb-4 flex items-center gap-3 bg-green-50 border border-green-200 rounded-lg px-4 py-3 text-green-800">
            <i class="fas fa-check-circle text-green-500"></i>
            <span class="font-medium"><?php echo htmlspecialchars($success); ?></span>
          </div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="mb-4 flex items-center gap-3 bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-red-800">
            <i class="fas fa-exclamation-circle text-red-500"></i>
            <span class="font-medium"><?php echo htmlspecialchars($error); ?></span>
          </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Your Feedback</label>
            <textarea name="feedback" required rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors resize-none" placeholder="Share your thoughts..."></textarea>
          </div>
          <div class="flex items-center justify-end">
            <button type="submit" name="submit_feedback" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
              <i class="fas fa-paper-plane mr-2"></i>Submit
            </button>
          </div>
        </form>
      </div>

      <!-- Recent Feedback -->
      <div class="bg-white/80 backdrop-blur-sm rounded-xl border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-lg font-bold text-gray-900">Recent Feedback</h2>
          <span class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded-full">Latest</span>
        </div>
        <div class="space-y-3">
          <?php
          $result = $conn->query("SELECT f.feedback, u.full_name, f.created_at FROM feedback f JOIN users u ON f.user_id = u.id ORDER BY f.created_at DESC LIMIT 10");
          if ($result && $result->num_rows):
            while ($row = $result->fetch_assoc()): ?>
              <div class="p-4 bg-gray-50/80 rounded-lg">
                <div class="flex items-start justify-between mb-1">
                  <div class="flex items-center gap-2">
                    <span class="inline-flex w-8 h-8 items-center justify-center rounded-full bg-gradient-to-r from-blue-600 to-purple-600 text-white text-xs">
                      <i class="fas fa-user"></i>
                    </span>
                    <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($row['full_name']); ?></span>
                  </div>
                  <span class="text-xs text-gray-500"><?php echo date('M d, Y H:i', strtotime($row['created_at'])); ?></span>
                </div>
                <p class="text-sm text-gray-700"><?php echo nl2br(htmlspecialchars($row['feedback'])); ?></p>
              </div>
          <?php endwhile; else: ?>
              <div class="text-center py-8">
                <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
                  <i class="fas fa-comments text-gray-400"></i>
                </div>
                <p class="text-sm text-gray-600">No feedback yet</p>
              </div>
          <?php endif; ?>
        </div>
        <div class="mt-6 text-center">
          <a href="client_dashboard.php" class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>Back to Client Dashboard
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

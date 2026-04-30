<?php
include 'backend/core/header.php';
?>
<style>
  :root { --space: 0.25rem; }
  .px-4 { padding-inline: calc(var(--space, 0.25rem) * 2) !important; }
  @supports not (padding-inline: 1px) {
    .px-4 {
      padding-left: calc(var(--space, 0.25rem) * 2) !important;
      padding-right: calc(var(--space, 0.25rem) * 2) !important;
    }
  }
</style>
<?php
// Handle public inquiry submission
// Load Composer autoloader and Mailer helper
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/Mailer.php';
require_once __DIR__ . '/backend/connection/connect.php';
$db = null; $inq_success = false; $inq_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['public_inquiry_submit'])) {
  // Compute app base (supports /ArchiFlow subfolder)
  $APP_BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  if ($APP_BASE === '/' || $APP_BASE === '.') { $APP_BASE = ''; }
  // Basic sanitization
  $first = trim($_POST['first_name'] ?? '');
  $last = trim($_POST['last_name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $subject = trim($_POST['subject'] ?? '');
  $message = trim($_POST['message'] ?? '');
  try {
    if ($first === '' || $last === '' || $email === '' || $subject === '' || $message === '') {
      throw new Exception('All fields are required.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new Exception('Invalid email address.'); }
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Ensure table exists (public_inquiries reused by admin & senior architect view)
    $db->exec("CREATE TABLE IF NOT EXISTS public_inquiries (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(150),
      email VARCHAR(150),
      phone VARCHAR(50) NULL,
      inquiry_type VARCHAR(100) NULL,
      project_type VARCHAR(150) NULL,
      budget_range VARCHAR(100) NULL,
      message TEXT,
      location VARCHAR(255) NULL,
      status VARCHAR(50) DEFAULT 'new',
      assigned_to INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(assigned_to), INDEX(status), INDEX(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // Safety: ensure assigned_to column exists on legacy tables
    try { $db->exec("ALTER TABLE public_inquiries ADD COLUMN assigned_to INT NULL"); } catch (Throwable $eAssigned) {}

    // Determine least-loaded Senior Architect to auto-assign
    $assignedTo = null;
    try {
      // Gather SA candidates
      $cands = [];
      $q = $db->query("SELECT id, user_id, role, position FROM users");
      foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $role = strtolower((string)($r['role'] ?? ''));
        $pos  = strtolower((string)($r['position'] ?? ''));
        if ($role === 'senior_architect' || strpos($pos, 'senior') !== false && strpos($pos, 'architect') !== false) {
          $pk = (int)($r['user_id'] ?? 0) ?: (int)($r['id'] ?? 0);
          if ($pk) { $cands[] = $pk; }
        }
      }
      // Find SA with fewest active inquiries (new/in_review)
      if ($cands) {
        $minCount = PHP_INT_MAX; $chosen = null;
        $stCnt = $db->prepare("SELECT COUNT(*) FROM public_inquiries WHERE assigned_to = ? AND (status = 'new' OR status = 'in_review')");
        foreach ($cands as $pk) {
          try { $stCnt->execute([$pk]); $cnt = (int)$stCnt->fetchColumn(); } catch (Throwable $ie) { $cnt = 0; }
          if ($cnt < $minCount || ($cnt === $minCount && ($chosen === null || $pk < $chosen))) { $minCount = $cnt; $chosen = $pk; }
        }
        $assignedTo = $chosen;
      }
    } catch (Throwable $ePick) { $assignedTo = null; }

    // Insert (combine first+last into name; subject stored in inquiry_type)
    $stmt = $db->prepare('INSERT INTO public_inquiries (name, email, inquiry_type, message, status, assigned_to) VALUES (?,?,?,?,"new",?)');
    $fullName = $first . ' ' . $last;
    $stmt->execute([$fullName, $email, $subject, $message, $assignedTo]);

    // Send email to inquirer inviting to register (via PHPMailer SMTP)
    if (stripos($email, '@gmail.com') !== false) { // Only send to Gmail addresses per request
      $registerUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $APP_BASE . '/register.php';
      $html = '<p>Hello ' . htmlspecialchars($fullName) . ',</p>'
            . '<p>Thank you for reaching out to ArchiFlow.</p>'
            . '<p>To track your inquiry, receive updates, and access more features, please create an account using the link below:</p>'
            . '<p><a href="' . htmlspecialchars($registerUrl) . '" target="_blank" rel="noopener">Create your ArchiFlow account</a></p>'
            . '<p>If you have any questions, just reply to this email.</p>'
            . '<p>Best regards,<br>ArchiFlow Team</p>';
      $text = "Hello $fullName,\n\nThank you for reaching out to ArchiFlow.\n\nTo track your inquiry, receive updates, and access more features, please create an account using the link below:\n\n$registerUrl\n\nIf you have any questions, reply to this email.\n\nBest regards,\nArchiFlow Team";
      try {
        [$ok, $err] = \Archiflow\Mail\send_mail([
          'to_email' => $email,
          'to_name'  => $fullName,
          'subject'  => 'Thank you for your inquiry - Create your ArchiFlow account',
          'html'     => $html,
          'text'     => $text,
        ]);
        // Optional: Log failure to admin notifications
        if (!$ok) {
          try {
            $db->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (0, ?, ?, "inquiry")')
               ->execute(['Email delivery failure', 'Could not send invite to ' . $email . ': ' . $err]);
          } catch (Throwable $logE) {}
        }
      } catch (Throwable $mailE) {
        // Swallow to not block submission; optionally log
        try {
          $db->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (0, ?, ?, "inquiry")')
             ->execute(['Email exception', $mailE->getMessage()]);
        } catch (Throwable $logE2) {}
      }
    }

    // Notify admins about new public inquiry
    try {
      $db->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NULL,
        type VARCHAR(50) DEFAULT 'inquiry',
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id), INDEX(is_read)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      // Find admin users (role/position tolerant)
      $admins = [];
      try {
        $q = $db->query("SELECT id, user_id, username, role, position FROM users");
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
          $role = strtolower((string)($r['role'] ?? ''));
          $pos  = strtolower((string)($r['position'] ?? ''));
          if ($role === 'admin' || strpos($pos, 'admin') !== false) { $admins[] = (int)($r['id'] ?? 0); }
        }
      } catch (Throwable $ie) {}
      if ($admins) {
        $title = 'New Public Inquiry';
        $msg = 'From ' . $fullName . ' (' . $email . '): ' . $subject;
        $ins = $db->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, "inquiry")');
        foreach ($admins as $aid) { if ($aid) { $ins->execute([$aid, $title, $msg]); } }
      }
    } catch (Throwable $ne) {}
    // Notify assigned Senior Architect (if chosen)
    try {
      if (!empty($assignedTo)) {
        $insSa = $db->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, "inquiry")');
        $insSa->execute([$assignedTo, 'New Inquiry Assigned', $fullName . ' (' . $email . ') submitted an inquiry: ' . $subject,]);
      }
    } catch (Throwable $ne2) {}
    $inq_success = true;
  } catch (Throwable $e) {
    $inq_error = $e->getMessage();
  }
}
?>

<main>
  <?php if ($inq_success): ?>
    <!-- Floating success toast -->
    <div id="inq-toast" class="fixed top-4 right-4 z-50 bg-green-600 text-white shadow-lg rounded-lg px-4 py-3 flex items-start gap-3 transition transform">
      <i class="fas fa-check-circle mt-0.5"></i>
      <div>
        <p class="font-semibold">Message sent</p>
        <p class="text-sm opacity-90">Please check your email for our response.</p>
      </div>
      <button type="button" data-close class="ml-4 text-white/80 hover:text-white leading-none text-xl">&times;</button>
    </div>
  <?php endif; ?>
  <!-- Hero Section -->
  <section class="relative min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-900 via-blue-800 to-blue-700 overflow-hidden">
    <!-- Background Pattern -->
    <div class="absolute inset-0 opacity-10">
      <div class="absolute inset-0 bg-grid-pattern"></div>
    </div>
    
    <div class="relative z-10 text-center text-white px-4 max-w-6xl mx-auto">
      <div class="mb-8">
        <i class="fas fa-building text-6xl md:text-8xl text-blue-300 mb-6 animate-pulse"></i>
        <h1 class="text-5xl md:text-7xl font-bold mb-6 bg-gradient-to-r from-white to-blue-200 bg-clip-text text-transparent">
          ArchiFlow
        </h1>
        <p class="text-xl md:text-2xl text-blue-100 mb-8 max-w-4xl mx-auto leading-relaxed">
          Streamlining Architectural Excellence
        </p>
        <p class="text-lg md:text-xl text-blue-200 mb-12 max-w-3xl mx-auto">
          Complete project management solution for modern architectural firms
        </p>
      </div>
      
      <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
        <a href="#features" class="bg-white text-blue-900 hover:bg-blue-50 font-semibold py-4 px-8 rounded-lg transition duration-300 transform hover:scale-105 shadow-lg">
          <i class="fas fa-rocket mr-2"></i>Explore Features
        </a>
        <a href="login.php" class="border-2 border-white text-white hover:bg-white hover:text-blue-900 font-semibold py-4 px-8 rounded-lg transition duration-300 transform hover:scale-105">
          <i class="fas fa-sign-in-alt mr-2"></i>Get Started
        </a>
      </div>
    </div>
    
    <!-- Scroll Indicator -->
    <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 animate-bounce">
      <i class="fas fa-chevron-down text-white text-2xl"></i>
    </div>
  </section>

  <!-- Features Section -->
  <section id="features" class="py-20 bg-gray-50">
    <div class="container mx-auto px-4 max-w-7xl">
      <div class="text-center mb-16">
        <h2 class="text-4xl md:text-5xl font-bold text-gray-800 mb-6">Powerful Features</h2>
        <p class="text-xl text-gray-600 max-w-3xl mx-auto">
          Everything you need to manage architectural projects efficiently
        </p>
      </div>
      
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
        <!-- Project Management -->
        <div class="bg-white rounded-xl shadow-lg hover:shadow-xl transition duration-300 p-8 transform hover:-translate-y-2 opacity-0 translate-y-8 hover:shadow-2xl">
          <div class="text-center">
            <div class="bg-blue-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-6">
              <i class="fas fa-project-diagram text-blue-600 text-2xl"></i>
            </div>
            <h3 class="text-xl font-semibold text-gray-800 mb-4">Project Management</h3>
            <p class="text-gray-600">Track projects from planning to completion with real-time updates and milestone management.</p>
          </div>
        </div>
        
        <!-- Real-time Collaboration -->
        <div class="bg-white rounded-xl shadow-lg hover:shadow-xl transition duration-300 p-8 transform hover:-translate-y-2 opacity-0 translate-y-8 hover:shadow-2xl">
          <div class="text-center">
            <div class="bg-green-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-6">
              <i class="fas fa-users text-green-600 text-2xl"></i>
            </div>
            <h3 class="text-xl font-semibold text-gray-800 mb-4">Real-time Collaboration</h3>
            <p class="text-gray-600">Seamless communication between architects, clients, and contractors with instant messaging.</p>
          </div>
        </div>
        
        <!-- Automated Payroll -->
        <div class="bg-white rounded-xl shadow-lg hover:shadow-xl transition duration-300 p-8 transform hover:-translate-y-2 opacity-0 translate-y-8 hover:shadow-2xl">
          <div class="text-center">
            <div class="bg-purple-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-6">
              <i class="fas fa-calculator text-purple-600 text-2xl"></i>
            </div>
            <h3 class="text-xl font-semibold text-gray-800 mb-4">Automated Payroll & HR</h3>
            <p class="text-gray-600">Streamlined HR processes with automated payroll, attendance tracking, and leave management.</p>
          </div>
        </div>
        
        <!-- Client Portal -->
        <div class="bg-white rounded-xl shadow-lg hover:shadow-xl transition duration-300 p-8 transform hover:-translate-y-2 opacity-0 translate-y-8 hover:shadow-2xl">
          <div class="text-center">
            <div class="bg-orange-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-6">
              <i class="fas fa-user-friends text-orange-600 text-2xl"></i>
            </div>
            <h3 class="text-xl font-semibold text-gray-800 mb-4">Client Communication</h3>
            <p class="text-gray-600">Dedicated client portal for project updates, document sharing, and transparent communication.</p>
          </div>
        </div>
        
        <!-- Document Management -->
        <div class="bg-white rounded-xl shadow-lg hover:shadow-xl transition duration-300 p-8 transform hover:-translate-y-2 opacity-0 translate-y-8 hover:shadow-2xl">
          <div class="text-center">
            <div class="bg-red-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-6">
              <i class="fas fa-file-alt text-red-600 text-2xl"></i>
            </div>
            <h3 class="text-xl font-semibold text-gray-800 mb-4">Document Management</h3>
            <p class="text-gray-600">Secure storage and organization of blueprints, contracts, and project documents.</p>
          </div>
        </div>
        
        <!-- Supplier Database -->
        <div class="bg-white rounded-xl shadow-lg hover:shadow-xl transition duration-300 p-8 transform hover:-translate-y-2 opacity-0 translate-y-8 hover:shadow-2xl">
          <div class="text-center">
            <div class="bg-indigo-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-6">
              <i class="fas fa-warehouse text-indigo-600 text-2xl"></i>
            </div>
            <h3 class="text-xl font-semibold text-gray-800 mb-4">Supplier Database</h3>
            <p class="text-gray-600">Comprehensive database of suppliers, materials, and equipment for efficient procurement.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Services Section -->
  <section class="py-20 bg-white">
    <div class="container mx-auto px-4 max-w-7xl">
      <div class="text-center mb-16">
        <h2 class="text-4xl md:text-5xl font-bold text-gray-800 mb-6">Our Services</h2>
        <p class="text-xl text-gray-600 max-w-3xl mx-auto">
          Comprehensive architectural solutions for every project type
        </p>
      </div>
      
      <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        <!-- Residential Design -->
        <div class="relative group cursor-pointer">
          <div class="bg-gradient-to-br from-blue-500 to-blue-700 rounded-xl overflow-hidden shadow-lg group-hover:shadow-2xl transition duration-300">
            <div class="p-8 text-white">
              <div class="text-center">
                <i class="fas fa-home text-4xl mb-4"></i>
                <h3 class="text-2xl font-bold mb-4">Residential Design</h3>
                <p class="text-blue-100 mb-6">Complete house design solutions with modern architectural principles.</p>
                <div class="text-sm text-blue-200">
                  <p><i class="fas fa-check mr-2"></i>Custom home designs</p>
                  <p><i class="fas fa-check mr-2"></i>Space optimization</p>
                  <p><i class="fas fa-check mr-2"></i>Sustainable solutions</p>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Commercial Design -->
        <div class="relative group cursor-pointer">
          <div class="bg-gradient-to-br from-green-500 to-green-700 rounded-xl overflow-hidden shadow-lg group-hover:shadow-2xl transition duration-300">
            <div class="p-8 text-white">
              <div class="text-center">
                <i class="fas fa-building text-4xl mb-4"></i>
                <h3 class="text-2xl font-bold mb-4">Commercial Design</h3>
                <p class="text-green-100 mb-6">Office and retail space designs that maximize functionality and aesthetics.</p>
                <div class="text-sm text-green-200">
                  <p><i class="fas fa-check mr-2"></i>Office layouts</p>
                  <p><i class="fas fa-check mr-2"></i>Retail spaces</p>
                  <p><i class="fas fa-check mr-2"></i>Brand integration</p>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Industrial Design -->
        <div class="relative group cursor-pointer">
          <div class="bg-gradient-to-br from-purple-500 to-purple-700 rounded-xl overflow-hidden shadow-lg group-hover:shadow-2xl transition duration-300">
            <div class="p-8 text-white">
              <div class="text-center">
                <i class="fas fa-industry text-4xl mb-4"></i>
                <h3 class="text-2xl font-bold mb-4">Industrial Design</h3>
                <p class="text-purple-100 mb-6">Specialized industrial facility designs with focus on efficiency and safety.</p>
                <div class="text-sm text-purple-200">
                  <p><i class="fas fa-check mr-2"></i>Manufacturing facilities</p>
                  <p><i class="fas fa-check mr-2"></i>Warehouse designs</p>
                  <p><i class="fas fa-check mr-2"></i>Safety compliance</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- About Section -->
  <section id="about" class="py-20 bg-gray-50">
    <div class="container mx-auto px-4 max-w-7xl">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
        <div class="slide-in-left">
          <h2 class="text-4xl md:text-5xl font-bold text-gray-800 mb-6">About ArchiFlow</h2>
          <p class="text-lg text-gray-600 mb-6">
            ArchiFlow is a comprehensive project management system designed specifically for architectural firms. 
            We understand the unique challenges faced by architects and provide tailored solutions to streamline 
            your workflow and enhance productivity.
          </p>
          <p class="text-lg text-gray-600 mb-8">
            From initial client consultation to project completion, ArchiFlow manages every aspect of your 
            architectural practice with precision and efficiency.
          </p>
          
          <div class="grid grid-cols-2 gap-6">
            <div class="text-center">
              <div class="text-3xl font-bold text-blue-600 mb-2" data-counter="500">0</div>
              <div class="text-gray-600">Projects Completed</div>
            </div>
            <div class="text-center">
              <div class="text-3xl font-bold text-blue-600 mb-2" data-counter="50">0</div>
              <div class="text-gray-600">Happy Clients</div>
            </div>
            <div class="text-center">
              <div class="text-3xl font-bold text-blue-600 mb-2" data-counter="15">0</div>
              <div class="text-gray-600">Years Experience</div>
            </div>
            <div class="text-center">
              <div class="text-3xl font-bold text-blue-600 mb-2">24/7</div>
              <div class="text-gray-600">Support Available</div>
            </div>
          </div>
        </div>
        
        <div class="relative slide-in-right">
          <div class="bg-gradient-to-br from-blue-500 to-blue-700 rounded-2xl p-8 text-white hover-lift">
            <div class="text-center">
              <i class="fas fa-award text-6xl mb-6 text-blue-200"></i>
              <h3 class="text-2xl font-bold mb-4">Professional Excellence</h3>
              <p class="text-blue-100 mb-6">
                Award-winning architectural firm with a proven track record of delivering 
                exceptional designs and outstanding project management.
              </p>
              <div class="flex justify-center space-x-4">
                <div class="text-center">
                  <div class="text-2xl font-bold">ISO 9001</div>
                  <div class="text-sm text-blue-200">Certified</div>
                </div>
                <div class="text-center">
                  <div class="text-2xl font-bold">LEED</div>
                  <div class="text-sm text-blue-200">Accredited</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Budget Calculator Section -->
  <section id="budget" class="py-20 bg-gray-50">
    <div class="container mx-auto px-4 max-w-7xl">
      <div class="text-center mb-12">
        <h2 class="text-4xl md:text-5xl font-bold text-gray-800 mb-4">Design Fee Calculator</h2>
        <p class="text-lg text-gray-600 max-w-3xl mx-auto">Estimate architectural design fees using the standard construction cost and PRC-aligned percentage groups. Figures are indicative and exclude specialty consultants, permits, and escalation.</p>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-stretch">
        <!-- Inputs (Revised) -->
        <div class="bg-white rounded-2xl shadow-lg p-8">
          <div class="space-y-7">
            <div class="space-y-3">
              <label class="block text-sm font-medium text-gray-700">Project Area</label>
              <div class="flex">
                <input id="bc-area" type="number" min="0" step="0.1" placeholder="Enter area or leave blank" class="flex-1 px-4 py-3 border border-gray-300 rounded-l-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
                <select id="bc-unit" class="px-4 py-3 border border-gray-300 rounded-r-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-gray-50">
                  <option value="sqm">sqm</option>
                  <option value="sqft">sqft</option>
                </select>
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div class="space-y-1">
                  <label class="text-xs text-gray-600">Length 1</label>
                  <input id="bc-l1" type="number" min="0" step="0.1" placeholder="e.g. 10" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
                </div>
                <div class="space-y-1">
                  <label class="text-xs text-gray-600">Width 1</label>
                  <input id="bc-w1" type="number" min="0" step="0.1" placeholder="e.g. 12" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
                </div>
                <div class="space-y-1">
                  <label class="text-xs text-gray-600">Length 2 (optional)</label>
                  <input id="bc-l2" type="number" min="0" step="0.1" placeholder="e.g. 8" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
                </div>
                <div class="space-y-1">
                  <label class="text-xs text-gray-600">Width 2 (optional)</label>
                  <input id="bc-w2" type="number" min="0" step="0.1" placeholder="e.g. 5" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
                </div>
              </div>
              <div class="flex items-center justify-between text-xs text-gray-600">
                <span id="bc-auto-area" class="font-medium text-blue-600">Auto area: 0 sqm</span>
                <div class="flex gap-2">
                  <button type="button" id="bc-use-auto" class="px-2 py-1 rounded bg-blue-600 text-white hover:bg-blue-700">Use Auto</button>
                  <button type="button" id="bc-clear-area" class="px-2 py-1 rounded bg-gray-100 text-gray-700 hover:bg-gray-200">Clear Manual</button>
                </div>
              </div>
              <p class="text-xs text-gray-500">Leave area blank to auto-calculate from up to two length×width rectangles. Dimensions use the selected unit.</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Design Fee Group</label>
              <select id="bc-group" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="g1">Group 1 – Simple Structures (6%)</option>
                <option value="g2">Group 2 – Moderate Complexity (7%)</option>
                <option value="g3">Group 3 – Exceptional Complexity (8%)</option>
                <option value="g4">Group 4 – Residences (10%)</option>
                <option value="g5">Group 5 – Monumental Buildings (12%)</option>
              </select>
              <p class="text-xs text-gray-500 mt-2">Based on common professional fee brackets (illustrative).</p>
            </div>

            <div class="flex flex-wrap gap-3 pt-2">
              <button id="bc-calc" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-3 rounded-lg">
                <i class="fas fa-calculator mr-2"></i>Calculate
              </button>
              <button id="bc-reset" class="bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold px-6 py-3 rounded-lg">Reset</button>
            </div>
          </div>
          <hr class="my-8" />
          <div class="space-y-4 text-xs text-gray-500">
            <p><strong>Standard Cost:</strong> ₱35,000 / sqm (assumed baseline construction cost).</p>
            <p><strong>Formula:</strong> Project Cost = Area × Standard Cost; Design Fee = Project Cost × Group Rate.</p>
            <p>Adjust rates or cost assumptions as needed for detailed proposals.</p>
          </div>
        </div>

        <!-- Result (Revised) -->
        <div class="bg-white rounded-2xl shadow-lg p-8 flex flex-col">
          <div class="flex items-center justify-between mb-6">
            <h3 class="text-2xl font-bold text-gray-800">Design Fee Estimate</h3>
            <span id="bc-updated" class="text-sm text-gray-500"></span>
          </div>
          <div class="text-5xl font-extrabold text-blue-600 mb-4" id="bc-design-fee">₱0</div>
          <div class="text-gray-600 mb-6" id="bc-note">Enter area and select a fee group.</div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
              <div class="p-4 bg-blue-50 rounded-lg">
                <div class="text-gray-500">Standard Cost</div>
                <div class="font-semibold" id="bc-base">₱35,000 / sqm</div>
              </div>
              <div class="p-4 bg-green-50 rounded-lg">
                <div class="text-gray-500">Area Considered</div>
                <div class="font-semibold" id="bc-area-out">0 sqm</div>
              </div>
              <div class="p-4 bg-yellow-50 rounded-lg">
                <div class="text-gray-500">Project Cost</div>
                <div class="font-semibold" id="bc-project">₱0</div>
              </div>
              <div class="p-4 bg-purple-50 rounded-lg">
                <div class="text-gray-500">Design Rate</div>
                <div class="font-semibold" id="bc-rate">0%</div>
              </div>
            </div>

          <div class="mt-8">
            <button id="bc-cta" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-3 rounded-lg">
              Request Detailed Quote
            </button>
            <p class="text-xs text-gray-500 mt-3">Indicative only. Actual fees depend on scope, consultants, and contract terms.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Contact Section -->
  <section id="contact" class="py-20 bg-white">
    <div class="container mx-auto px-4 max-w-7xl">
      <div class="text-center mb-16">
        <h2 class="text-4xl md:text-5xl font-bold text-gray-800 mb-6">Get In Touch</h2>
        <p class="text-xl text-gray-600 max-w-3xl mx-auto">
          Ready to transform your architectural practice? Contact us today.
        </p>
      </div>
      
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">
        <!-- Contact Information -->
        <div>
          <h3 class="text-2xl font-bold text-gray-800 mb-8">Contact Information</h3>
          
          <div class="space-y-6">
            <div class="flex items-start space-x-4">
              <div class="bg-blue-100 p-3 rounded-lg">
                <i class="fas fa-building text-blue-600 text-xl"></i>
              </div>
              <div>
                <h4 class="font-semibold text-gray-800 mb-1">Abadia Architects and Designers Cebu</h4>
                <p class="text-gray-600">2F The Rosedale Place, Gov. M Cuenco Ave, Banilad, Cebu City, Philippines, 6000</p>
              </div>
            </div>
            
            <div class="flex items-start space-x-4">
              <div class="bg-green-100 p-3 rounded-lg">
                <i class="fas fa-phone text-green-600 text-xl"></i>
              </div>
              <div>
                <h4 class="font-semibold text-gray-800 mb-1">Phone</h4>
                <p class="text-gray-600">+63 32 123 4567</p>
              </div>
            </div>
            
            <div class="flex items-start space-x-4">
              <div class="bg-purple-100 p-3 rounded-lg">
                <i class="fas fa-envelope text-purple-600 text-xl"></i>
              </div>
              <div>
                <h4 class="font-semibold text-gray-800 mb-1">Email</h4>
                <p class="text-gray-600">info@archiflow.com</p>
              </div>
            </div>
            
            <div class="flex items-start space-x-4">
              <div class="bg-orange-100 p-3 rounded-lg">
                <i class="fas fa-clock text-orange-600 text-xl"></i>
              </div>
              <div>
                <h4 class="font-semibold text-gray-800 mb-1">Business Hours</h4>
                <p class="text-gray-600">Monday - Friday: 8:00 AM - 6:00 PM<br>Saturday: 9:00 AM - 3:00 PM</p>
              </div>
            </div>
          </div>
          
          <!-- Social Links -->
          <div class="mt-8">
            <h4 class="font-semibold text-gray-800 mb-4">Follow Us</h4>
            <div class="flex space-x-4">
              <a href="#" class="bg-blue-600 text-white p-3 rounded-lg hover:bg-blue-700 transition duration-300">
                <i class="fab fa-facebook-f"></i>
              </a>
              <a href="#" class="bg-blue-400 text-white p-3 rounded-lg hover:bg-blue-500 transition duration-300">
                <i class="fab fa-twitter"></i>
              </a>
              <a href="#" class="bg-blue-700 text-white p-3 rounded-lg hover:bg-blue-800 transition duration-300">
                <i class="fab fa-linkedin-in"></i>
              </a>
              <a href="#" class="bg-pink-600 text-white p-3 rounded-lg hover:bg-pink-700 transition duration-300">
                <i class="fab fa-instagram"></i>
              </a>
            </div>
          </div>
        </div>
        
        <!-- Quick Contact Form -->
        <div class="bg-gray-50 rounded-2xl p-8">
          <h3 class="text-2xl font-bold text-gray-800 mb-6">Send us a Message</h3>
          <?php if ($inq_success): ?>
            <div class="mb-6 p-4 rounded-lg bg-green-50 border border-green-200 text-green-700 flex items-start gap-3">
              <i class="fas fa-check-circle mt-0.5"></i>
              <div>
                <p class="font-semibold">Message sent!</p>
                <p class="text-sm">Please check your email for our response.</p>
              </div>
            </div>
          <?php elseif($inq_error): ?>
            <div class="mb-6 p-4 rounded-lg bg-red-50 border border-red-200 text-red-700 flex items-start gap-3">
              <i class="fas fa-exclamation-triangle mt-0.5"></i>
              <div>
                <p class="font-semibold">Submission failed</p>
                <p class="text-sm"><?php echo htmlspecialchars($inq_error); ?></p>
              </div>
            </div>
          <?php endif; ?>
          <form method="post" class="space-y-6" novalidate>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">First Name</label>
                <input name="first_name" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" required type="text" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Your first name">
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Last Name</label>
                <input name="last_name" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" required type="text" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Your last name">
              </div>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
              <input name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required type="email" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="your.email@example.com">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Subject</label>
              <input name="subject" value="<?php echo isset($_POST['subject']) ? htmlspecialchars($_POST['subject']) : ''; ?>" required type="text" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="How can we help you?">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Message</label>
              <textarea name="message" required rows="4" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Tell us about your project..."><?php echo isset($_POST['message']) ? htmlspecialchars($_POST['message']) : ''; ?></textarea>
            </div>
            
            <button type="submit" name="public_inquiry_submit" value="1" class="w-full bg-blue-600 text-white py-3 px-6 rounded-lg hover:bg-blue-700 transition duration-300 font-semibold">
              <i class="fas fa-paper-plane mr-2"></i>Send Message
            </button>
          </form>
        </div>
      </div>
    </div>
  </section>
</main>

<script>
// Design Fee Calculator Logic (Updated)
(function(){
  const el = (id) => document.getElementById(id);
  const fmt = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 });

  const STANDARD_COST = 35000; // ₱ per sqm
  const GROUPS = {
    g1: { rate: 0.06, label: 'Group 1 – Simple Structures' },
    g2: { rate: 0.07, label: 'Group 2 – Moderate Complexity' },
    g3: { rate: 0.08, label: 'Group 3 – Exceptional Complexity' },
    g4: { rate: 0.10, label: 'Group 4 – Residences' },
    g5: { rate: 0.12, label: 'Group 5 – Monumental Buildings' }
  };

  function toSqm(value, unit){
    const v = isFinite(value) ? value : 0;
    return unit === 'sqft' ? v * 0.092903 : v;
  }

  function calc(){
    const unit = el('bc-unit')?.value || 'sqm';
    const group = el('bc-group')?.value || 'g1';
    const rate = GROUPS[group]?.rate ?? GROUPS.g1.rate;
    const label = GROUPS[group]?.label ?? GROUPS.g1.label;

    // Manual area (user override) in selected unit
    const manualAreaRaw = parseFloat(el('bc-area')?.value || '0');
    // Auto area (sum of up to two rectangles) in selected unit
    const autoAreaRaw = computeAutoArea(unit);
    // Pick effective area: manual overrides if provided (>0)
    const effectiveRaw = manualAreaRaw > 0 ? manualAreaRaw : autoAreaRaw;
    const sqm = toSqm(effectiveRaw > 0 ? effectiveRaw : 0, unit);
    const projectCost = sqm * STANDARD_COST;
    const designFee = projectCost * rate;

    // Update auto area display (always show raw + unit; if unit is sqft also show sqm equivalent for clarity)
    const autoLabelExtra = unit === 'sqft' && autoAreaRaw > 0 ? ` (${toSqm(autoAreaRaw,'sqft').toFixed(1)} sqm)` : '';
    el('bc-auto-area').textContent = `Auto area: ${autoAreaRaw.toFixed(1)} ${unit}${autoLabelExtra}`;

    el('bc-design-fee').textContent = fmt.format(designFee || 0);
    el('bc-area-out').textContent = (sqm || 0).toFixed(1) + ' sqm';
    el('bc-project').textContent = fmt.format(projectCost || 0);
    el('bc-rate').textContent = ((rate * 100).toFixed(0)) + '%';
    el('bc-note').textContent = sqm > 0
      ? `Using ${label} at ${(rate*100).toFixed(0)}% of project cost.`
      : 'Enter area or dimensions, then select a fee group.';
    el('bc-updated').textContent = new Date().toLocaleTimeString();
  }

  function computeAutoArea(unit){
    const lx1 = parseFloat(el('bc-l1')?.value || '0');
    const wx1 = parseFloat(el('bc-w1')?.value || '0');
    const lx2 = parseFloat(el('bc-l2')?.value || '0');
    const wx2 = parseFloat(el('bc-w2')?.value || '0');
    let total = 0;
    if (lx1 > 0 && wx1 > 0) total += lx1 * wx1;
    if (lx2 > 0 && wx2 > 0) total += lx2 * wx2;
    return total; // in current unit
  }

  function reset(){
    el('bc-area').value = '';
    el('bc-unit').value = 'sqm';
    el('bc-group').value = 'g1';
    calc();
  }

  function prefillContact(){
    const group = el('bc-group')?.value || 'g1';
    const label = GROUPS[group]?.label ?? GROUPS.g1.label;
    const unit = el('bc-unit')?.value || 'sqm';
    const manualRaw = parseFloat(el('bc-area')?.value || '');
    const autoRaw = computeAutoArea(unit);
    const effectiveRaw = manualRaw > 0 ? manualRaw : autoRaw;
    const areaDisplay = effectiveRaw > 0 ? effectiveRaw.toFixed(1).replace(/\.0$/,'') : '0';
    const mode = manualRaw > 0 ? 'manual' : 'auto';
    const projectCost = el('bc-project')?.textContent || '₱0';
    const designFee = el('bc-design-fee')?.textContent || '₱0';
    const msg = `Design fee estimate: ${label}. Area (${mode}): ${areaDisplay} ${unit}. Project Cost: ${projectCost}. Design Fee: ${designFee}. Please contact me for a detailed proposal.`;
    const subject = document.querySelector('input[name="subject"]');
    const message = document.querySelector('textarea[name="message"]');
    if (subject && message) {
      subject.value = `Quote Request: ${label} (${areaDisplay} ${unit}, ${mode})`;
      message.value = msg;
      const contact = document.getElementById('contact');
      if (contact) contact.scrollIntoView({ behavior: 'smooth' });
    } else {
      window.location.href = `mailto:info@archiflow.com?subject=${encodeURIComponent('Quote Request')}&body=${encodeURIComponent(msg)}`;
    }
  }

  ['bc-area','bc-unit','bc-group'].forEach(id => {
    const node = el(id);
    if (node) node.addEventListener('input', calc);
  });
  // Dimension inputs trigger recalculation (auto area + totals) only if manual is empty or for display
  ['bc-l1','bc-w1','bc-l2','bc-w2'].forEach(id => {
    const node = el(id);
    if (node) node.addEventListener('input', calc);
  });
  // Use Auto button copies auto area into manual field then recalculates
  el('bc-use-auto')?.addEventListener('click', () => {
    const unit = el('bc-unit')?.value || 'sqm';
    const autoRaw = computeAutoArea(unit);
    if (autoRaw > 0) {
      el('bc-area').value = autoRaw.toFixed(2).replace(/\.00$/,'');
      calc();
    }
  });
  // Clear Manual empties manual area letting auto take over
  el('bc-clear-area')?.addEventListener('click', () => {
    const f = el('bc-area');
    if (f){ f.value = ''; f.removeAttribute('value'); }
    calc();
  });
  el('bc-calc')?.addEventListener('click', calc);
  el('bc-reset')?.addEventListener('click', reset);
  el('bc-cta')?.addEventListener('click', prefillContact);

  calc();
})();
</script>

<?php if ($inq_success): ?>
<script>
  (function(){
    const toast = document.getElementById('inq-toast');
    if (!toast) return;
    const close = toast.querySelector('[data-close]');
    const hide = () => { toast.style.opacity = '0'; toast.style.transform = 'translateY(-6px)'; setTimeout(()=>toast.remove(), 300); };
    setTimeout(hide, 4000);
    if (close) close.addEventListener('click', hide);
  })();
</script>
<?php endif; ?>

<?php include 'backend/core/footer.php'; ?>
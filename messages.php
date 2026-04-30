<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: login.php'); exit; }
require_once __DIR__ . '/backend/connection/connect.php';
$db = getDB();
if (!$db) { die('DB connection failed'); }
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = (int)($_SESSION['user_id'] ?? 0);
$userType = strtolower((string)($_SESSION['user_type'] ?? ''));
$position = strtolower(str_replace(' ', '_', trim((string)($_SESSION['position'] ?? ''))));

// Early AJAX handlers (fetch/send) must run before any HTML output
if (($_GET['action'] ?? '') === 'fetch') {
  header('Content-Type: application/json');
  try {
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    $peerId = (int)($_GET['peer_id'] ?? 0);
    $afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : 0;
    if (!$peerId) { echo json_encode(['ok'=>false,'error'=>'bad-peer']); exit; }
    $chk = $db->prepare("SELECT 1 FROM users WHERE user_id = ? AND is_active = 1");
    $chk->execute([$peerId]);
    if (!$chk->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'peer-inactive']); exit; }
    // Ensure dm tables exist before querying
    try {
      $db->exec("CREATE TABLE IF NOT EXISTS dm (
        dm_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_one_id INT UNSIGNED NOT NULL,
        user_two_id INT UNSIGNED NOT NULL,
        last_message_at DATETIME NULL,
        PRIMARY KEY (dm_id),
        UNIQUE KEY uniq_pair (user_one_id, user_two_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $db->exec("CREATE TABLE IF NOT EXISTS dm_messages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        dm_id INT UNSIGNED NOT NULL,
        sender_id INT UNSIGNED NOT NULL,
        body TEXT NOT NULL,
        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_dm (dm_id),
        KEY idx_dm_time (dm_id, sent_at),
        CONSTRAINT fk_dm_messages_dm FOREIGN KEY (dm_id) REFERENCES dm (dm_id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
    $a = min($userId, $peerId); $b = max($userId, $peerId);
    $s = $db->prepare("SELECT dm_id FROM dm WHERE user_one_id = ? AND user_two_id = ? LIMIT 1");
    $s->execute([$a, $b]);
    $dmId = $s->fetchColumn();
    if (!$dmId) { echo json_encode(['ok'=>true,'messages'=>[]]); exit; }
    $st = $db->prepare("SELECT id, sender_id, body, sent_at FROM dm_messages WHERE dm_id = ? AND id > ? ORDER BY id ASC LIMIT 200");
    $st->execute([$dmId, $afterId]);
    echo json_encode(['ok'=>true,'messages'=>$st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
  } catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>'server']); }
  exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'send') {
  header('Content-Type: application/json');
  try {
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    $peerId = (int)($_POST['peer_id'] ?? 0);
    $text = trim((string)($_POST['message'] ?? ''));
    if (!$peerId || $text === '') { echo json_encode(['ok'=>false,'error'=>'bad-input']); exit; }
    $chk = $db->prepare("SELECT 1 FROM users WHERE user_id = ? AND is_active = 1");
    $chk->execute([$peerId]);
    if (!$chk->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'peer-inactive']); exit; }
    // Ensure dm tables exist before insert
    try {
      $db->exec("CREATE TABLE IF NOT EXISTS dm (
        dm_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_one_id INT UNSIGNED NOT NULL,
        user_two_id INT UNSIGNED NOT NULL,
        last_message_at DATETIME NULL,
        PRIMARY KEY (dm_id),
        UNIQUE KEY uniq_pair (user_one_id, user_two_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $db->exec("CREATE TABLE IF NOT EXISTS dm_messages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        dm_id INT UNSIGNED NOT NULL,
        sender_id INT UNSIGNED NOT NULL,
        body TEXT NOT NULL,
        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_dm (dm_id),
        KEY idx_dm_time (dm_id, sent_at),
        CONSTRAINT fk_dm_messages_dm FOREIGN KEY (dm_id) REFERENCES dm (dm_id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
    $a = min($userId, $peerId); $b = max($userId, $peerId);
    $sel = $db->prepare("SELECT dm_id FROM dm WHERE user_one_id = ? AND user_two_id = ? LIMIT 1");
    $sel->execute([$a, $b]);
    $dmId = $sel->fetchColumn();
    if (!$dmId) {
      $ins = $db->prepare("INSERT INTO dm (user_one_id, user_two_id, last_message_at) VALUES (?,?,NOW())");
      $ins->execute([$a, $b]);
      $dmId = (int)$db->lastInsertId();
    }
    $mi = $db->prepare("INSERT INTO dm_messages (dm_id, sender_id, body) VALUES (?,?,?)");
    $ok = $mi->execute([$dmId, $userId, $text]);
    if ($ok) { $db->prepare("UPDATE dm SET last_message_at = NOW() WHERE dm_id = ?")->execute([$dmId]); }
    echo json_encode(['ok'=>true]);
  } catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>'server']); }
  exit;
}

// Redirect specialized roles to their message hubs if they exist
if ($userType === 'client') { header('Location: client/messages.php'); exit; }
if ($userType === 'employee' && $position === 'senior_architect') { header('Location: employees/senior_architects/messages.php'); exit; }
if ($userType === 'employee' && $position === 'architect') { header('Location: employees/architects/messages.php'); exit; }

// Ensure generic DM tables
try {
  $db->exec("CREATE TABLE IF NOT EXISTS dm (
    dm_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_one_id INT UNSIGNED NOT NULL,
    user_two_id INT UNSIGNED NOT NULL,
    last_message_at DATETIME NULL,
    PRIMARY KEY (dm_id),
    UNIQUE KEY uniq_pair (user_one_id, user_two_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $db->exec("CREATE TABLE IF NOT EXISTS dm_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dm_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_dm (dm_id),
    KEY idx_dm_time (dm_id, sent_at),
    CONSTRAINT fk_dm_messages_dm FOREIGN KEY (dm_id) REFERENCES dm (dm_id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Build categorized people list
$cats = [
  'Project Managers' => [],
  'Senior Architects' => [],
  'Architects' => [],
  'HR' => [],
  'Admin' => [],
  'Clients' => [],
  'Others' => [],
];
$mkLabel = function(array $row): string {
  $name = trim(((string)($row['first_name'] ?? '')) . ' ' . ((string)($row['last_name'] ?? '')));
  if ($name !== '' && stripos($name,'system') === false) return $name;
  $email = trim((string)($row['email'] ?? ''));
  if ($email !== '') return $email;
  return 'User #' . (int)($row['user_id'] ?? 0);
};
try {
  $stmt = $db->prepare("SELECT u.user_id, u.user_type, u.first_name, u.last_name, u.email,
                               LOWER(COALESCE(u.position, '')) AS position,
                               d.dm_id, d.last_message_at
                        FROM users u
                        LEFT JOIN dm d ON (
                           (d.user_one_id = u.user_id AND d.user_two_id = ?) OR
                           (d.user_two_id = u.user_id AND d.user_one_id = ?)
                        )
                        WHERE u.is_active = 1 AND u.user_id <> ?
                        ORDER BY (d.dm_id IS NULL) ASC, d.last_message_at DESC, u.first_name, u.last_name");
  $stmt->execute([$userId, $userId, $userId]);
  foreach ($stmt as $row) {
    $pos = (string)($row['position'] ?? '');
    $ut = strtolower((string)($row['user_type'] ?? ''));
    $label = $mkLabel($row);
    $id = (int)$row['user_id'];
    if ($ut === 'client') { $cats['Clients'][$id] = $label; continue; }
    if (strpos($pos, 'project manager') !== false || $ut === 'project_manager') { $cats['Project Managers'][$id] = $label; continue; }
    if ((strpos($pos, 'senior') !== false && strpos($pos, 'architect') !== false) || $ut === 'senior_architect') { $cats['Senior Architects'][$id] = $label; continue; }
    if ($ut === 'hr' || strpos($pos, 'hr') !== false || strpos($pos, 'human resources') !== false) { $cats['HR'][$id] = $label; continue; }
    if ($ut === 'admin' || $ut === 'administrator') { $cats['Admin'][$id] = $label; continue; }
    if (strpos($pos, 'architect') !== false) { $cats['Architects'][$id] = $label; continue; }
    $cats['Others'][$id] = $label;
  }
} catch (Throwable $e) {}

include __DIR__ . '/backend/core/header.php';
?>
<main class="max-w-full px-4 py-6">
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 md:h-[70vh]">
      <h3 class="text-sm font-semibold text-gray-700 mb-2">People</h3>
      <div id="contacts" class="overflow-y-auto md:h-[62vh] space-y-3 pr-1">
        <?php foreach ($cats as $group => $list): $count = count($list); ?>
          <div class="role-card border border-gray-100 rounded-lg overflow-hidden">
            <button type="button" class="role-card-header w-full flex items-center justify-between px-3 py-2 bg-gray-50 hover:bg-gray-100">
              <div class="flex items-center gap-2">
                <span class="caret" aria-hidden="true"><?php echo ($group === 'Senior Architects') ? '▾' : '▸'; ?></span>
                <span class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($group); ?></span>
              </div>
              <span class="text-xs text-gray-500 bg-gray-200 rounded-full px-2 py-0.5"><?php echo (int)$count; ?></span>
            </button>
            <div class="role-card-body" style="<?php echo ($group === 'Senior Architects') ? '' : 'display:none;'; ?>">
              <?php if (!$list): ?>
                <div class="px-3 py-2 text-xs text-gray-400">No entries</div>
              <?php else: foreach ($list as $uid => $label): ?>
                <button data-peer="<?php echo (int)$uid; ?>" class="contact-btn w-full text-left py-3 px-3 hover:bg-gray-50 flex items-center justify-between border-t">
                  <span class="truncate pr-2"><?php echo htmlspecialchars($label); ?></span>
                  <span class="hidden md:inline text-xs text-gray-400">Chat</span>
                </button>
              <?php endforeach; endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-0 md:col-span-2 md:h-[70vh] flex flex-col">
      <div id="chatHeader" class="px-4 py-3 border-b text-sm text-gray-600">Select a person to start chatting</div>
      <div id="chatBox" class="flex-1 overflow-y-auto p-4 space-y-3 bg-gray-50"></div>
      <form id="chatForm" class="border-t p-3 flex items-center gap-2">
        <input type="text" id="chatInput" name="message" class="flex-1 border rounded-lg px-3 py-2" placeholder="Type a message..." autocomplete="off" />
        <button class="bg-indigo-600 text-white rounded-lg px-4 py-2" type="submit">Send</button>
      </form>
    </div>
  </div>
</main>
<?php include __DIR__ . '/backend/core/footer.php'; ?>
<script>
  let currentPeer = null;
  let lastId = 0;
  const chatBox = document.getElementById('chatBox');
  const chatHeader = document.getElementById('chatHeader');
  const chatForm = document.getElementById('chatForm');
  const chatInput = document.getElementById('chatInput');

  const renderMsg = (m, meId) => {
    const isMe = (parseInt(m.sender_id,10) === meId);
    const wrap = document.createElement('div');
    wrap.className = `flex ${isMe ? 'justify-end' : 'justify-start'}`;
    const bubble = document.createElement('div');
    bubble.className = `msg-bubble max-w-[80%] px-3 py-2 rounded-lg ${isMe ? 'bg-indigo-600 text-white' : 'bg-white border'}`;
    bubble.textContent = m.body || '';
    if (m.temp === true) { bubble.dataset.temp = '1'; bubble.dataset.body = m.body || ''; }
    wrap.appendChild(bubble);
    chatBox.appendChild(wrap);
  };
  const meId = <?php echo (int)$userId; ?>;
  const endpoint = window.location.pathname;
  let isFetching = false;
  let pendingBody = null;
  const fetchNew = async () => {
    if (!currentPeer) return;
    if (isFetching) return; isFetching = true;
    try {
      const res = await fetch(`${endpoint}?action=fetch&peer_id=${currentPeer}&after_id=${lastId}`);
      const data = await res.json();
      if (!data.ok) return;
      for (const m of data.messages) {
        const mid = parseInt(m.id,10);
        if (!Number.isNaN(mid)) lastId = Math.max(lastId, mid);
        if (parseInt(m.sender_id,10) === meId && pendingBody && (m.body || '') === pendingBody) {
          const temps = chatBox.querySelectorAll('.msg-bubble[data-temp="1"]');
          for (const el of temps) { if ((el.dataset.body || '') === pendingBody) { el.parentElement?.remove(); break; } }
          pendingBody = null;
        }
        renderMsg(m, meId);
      }
      if (data.messages && data.messages.length) chatBox.scrollTop = chatBox.scrollHeight;
    } catch (e) {}
    finally { isFetching = false; }
  };
  setInterval(fetchNew, 2500);
  document.getElementById('contacts').addEventListener('click', (e) => {
    const headerBtn = e.target.closest('.role-card-header');
    if (headerBtn) {
      const card = headerBtn.closest('.role-card');
      const body = card.querySelector('.role-card-body');
      const caret = card.querySelector('.caret');
      const visible = body.style.display !== 'none';
      body.style.display = visible ? 'none' : '';
      if (caret) caret.textContent = visible ? '▸' : '▾';
      return;
    }
    const contact = e.target.closest('.contact-btn');
    if (contact) {
      const labelEl = contact.querySelector('span');
      currentPeer = parseInt(contact.getAttribute('data-peer'),10);
      lastId = 0;
      chatBox.innerHTML='';
      chatHeader.textContent = labelEl ? labelEl.textContent.trim() : 'Chat';
      fetchNew();
    }
  });
  chatForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!currentPeer) return;
    const text = chatInput.value.trim();
    if (!text) return;
    try {
      const form = new FormData();
      form.append('action','send');
      form.append('peer_id', String(currentPeer));
      form.append('message', text);
      // optimistic render
      renderMsg({ sender_id: meId, body: text, temp: true }, meId);
      pendingBody = text; chatInput.value=''; chatBox.scrollTop = chatBox.scrollHeight;
      const res = await fetch(endpoint, { method: 'POST', body: form });
      const data = await res.json();
      if (data.ok) { chatInput.value=''; fetchNew(); }
    } catch (e) {}
  });
</script>
<?php /* endpoints moved to top to avoid HTML output before JSON */ ?>

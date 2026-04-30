<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$allowed_roles = ['senior_architect'];
include __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../backend/connection/connect.php';
$db = getDB();
if (!$db) { die('DB connection failed'); }
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = (int)($_SESSION['user_id'] ?? 0);
$firstName = $_SESSION['first_name'] ?? 'User';

// Ensure generic DM tables exist for role-agnostic chats
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

// Build categorized people list across roles; show ALL active users (except self), prioritize existing DMs/Chats
$cats = [
  'Clients' => [],
  'Project Managers' => [],
  'Senior Architects' => [],
  'Architects' => [],
  'HR' => [],
  'Admin' => [],
  'Others' => [],
];
// Helper to produce a friendly label
$mkClientLabel = function(array $row): string {
  $name = trim(((string)($row['first_name'] ?? '')) . ' ' . ((string)($row['last_name'] ?? '')));
  // Treat system-like placeholders as not a real client name
  $isSystemy = $name === '' || strcasecmp($name, 'System Administration') === 0 || stripos($name, 'system') !== false;
  if (!$isSystemy) return $name;
  $email = trim((string)($row['email'] ?? ''));
  if ($email !== '') return $email;
  return 'Client #' . (int)($row['user_id'] ?? 0);
};
try {
  $stmt = $db->prepare("SELECT u.user_id, u.user_type, u.first_name, u.last_name, u.email,
                               LOWER(COALESCE(u.position, '')) AS position,
                               d.dm_id, d.last_message_at AS dm_last,
                               c.chat_id, c.last_message_at AS chat_last
                        FROM users u
                        LEFT JOIN dm d ON (
                           (d.user_one_id = u.user_id AND d.user_two_id = ?) OR
                           (d.user_two_id = u.user_id AND d.user_one_id = ?)
                        )
                        LEFT JOIN chat c ON (u.user_type = 'client' AND c.client_id = u.user_id AND c.senior_architect_id = ?)
                        WHERE u.is_active = 1 AND u.user_id <> ?
                        ORDER BY (CASE WHEN (d.dm_id IS NULL AND c.chat_id IS NULL) THEN 1 ELSE 0 END) ASC,
                                 COALESCE(d.last_message_at, c.last_message_at) DESC,
                                 u.first_name, u.last_name");
  $stmt->execute([$userId, $userId, $userId, $userId]);
  foreach ($stmt as $row) {
    $pos = (string)($row['position'] ?? '');
    $ut = strtolower((string)($row['user_type'] ?? ''));
    $label = $mkClientLabel($row);
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

// JSON: fetch messages with any peer (clients use chat table; others use dm)
if (($_GET['action'] ?? '') === 'fetch_messages') {
  header('Content-Type: application/json');
  try {
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    $peerId = (int)($_GET['peer_id'] ?? 0);
    $afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : 0;
    if (!$peerId) { echo json_encode(['ok'=>false,'error'=>'bad-peer']); exit; }
    // Validate peer and get type
    $usr = $db->prepare("SELECT user_type FROM users WHERE user_id = ? AND is_active = 1");
    $usr->execute([$peerId]);
    $peerType = $usr->fetchColumn();
    if (!$peerType) { echo json_encode(['ok'=>false,'error'=>'peer-inactive']); exit; }
    $messages = [];
    if (strtolower((string)$peerType) === 'client') {
      // Use existing client<->SA chat
      $s = $db->prepare("SELECT chat_id FROM chat WHERE client_id = ? AND senior_architect_id = ? LIMIT 1");
      $s->execute([$peerId, $userId]);
      $chatId = $s->fetchColumn();
      // Optional legacy migration
      if (!$chatId) {
        try {
          $alt = $db->prepare("SELECT user_id FROM clients WHERE client_id = ? LIMIT 1");
          $alt->execute([$peerId]);
          $mappedUser = $alt->fetchColumn();
          if ($mappedUser && (int)$mappedUser !== (int)$peerId) {
            $s2 = $db->prepare("SELECT chat_id FROM chat WHERE client_id = ? AND senior_architect_id = ? LIMIT 1");
            $s2->execute([$mappedUser, $userId]);
            $legacy = $s2->fetchColumn();
            if ($legacy) {
              $db->prepare("UPDATE chat SET client_id = ? WHERE chat_id = ?")->execute([$peerId, $legacy]);
              $chatId = $legacy;
            }
          }
        } catch (Throwable $e) {}
      }
      if ($chatId) {
        $st = $db->prepare("SELECT message_id AS id, sender_id, body, sent_at FROM chat_messages WHERE chat_id = ? AND message_id > ? ORDER BY message_id ASC LIMIT 200");
        $st->execute([$chatId, $afterId]);
        $messages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      }
    } else {
      // Use generic DM for other roles
      $a = min($userId, $peerId); $b = max($userId, $peerId);
      $s = $db->prepare("SELECT dm_id FROM dm WHERE user_one_id = ? AND user_two_id = ? LIMIT 1");
      $s->execute([$a, $b]);
      $dmId = $s->fetchColumn();
      if ($dmId) {
        $st = $db->prepare("SELECT id, sender_id, body, sent_at FROM dm_messages WHERE dm_id = ? AND id > ? ORDER BY id ASC LIMIT 200");
        $st->execute([$dmId, $afterId]);
        $messages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      }
    }
    echo json_encode(['ok'=>true,'messages'=>$messages]);
  } catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>'server']); }
  exit;
}

// POST: send message to any peer (clients use chat table; others use dm)
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='send_message') {
  header('Content-Type: application/json');
  if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
  // backwards compatibility: accept client_id or peer_id
  $peerId = (int)($_POST['peer_id'] ?? 0);
  if (!$peerId) { $peerId = (int)($_POST['client_id'] ?? 0); }
  $text = trim($_POST['message'] ?? '');
  if (!$peerId || $text==='') { echo json_encode(['ok'=>false,'error'=>'bad-input']); exit; }
  // Validate peer and fetch type
  $usr = $db->prepare("SELECT user_type FROM users WHERE user_id = ? AND is_active = 1");
  $usr->execute([$peerId]);
  $peerType = $usr->fetchColumn();
  if (!$peerType) { echo json_encode(['ok'=>false,'error'=>'peer-inactive']); exit; }
  try {
    $ok = false;
    if (strtolower((string)$peerType) === 'client') {
      // Upsert chat for client<->SA
      $sel = $db->prepare("SELECT chat_id FROM chat WHERE client_id = ? AND senior_architect_id = ? LIMIT 1");
      $sel->execute([$peerId, $userId]);
      $chatId = $sel->fetchColumn();
      if (!$chatId) {
        $ins = $db->prepare("INSERT INTO chat (client_id, senior_architect_id, last_message_at) VALUES (?,?,NOW())");
        $ins->execute([$peerId, $userId]);
        $chatId = (int)$db->lastInsertId();
      }
      $mi = $db->prepare("INSERT INTO chat_messages (chat_id, sender_id, body) VALUES (?,?,?)");
      $ok = $mi->execute([$chatId, $userId, $text]);
      if ($ok) { $db->prepare("UPDATE chat SET last_message_at = NOW() WHERE chat_id = ?")->execute([$chatId]); }
    } else {
      // Upsert generic DM
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
    }
    echo json_encode(['ok'=>(bool)$ok]);
  } catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>'server']); }
  exit;
}

include __DIR__ . '/../../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-indigo-900 to-purple-800 text-white py-8">
  <div class="max-w-full px-4 flex items-center justify-between">
    <div>
  <h1 class="text-2xl font-semibold">Messages</h1>
  <p class="text-white/70">Chat by role (Clients, PMs, Architects, HR, etc.)</p>
    </div>
  </div>
</section>
<main class="max-w-full px-4 -mt-6">
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 md:h-[70vh]">
      <h3 class="text-sm font-semibold text-gray-700 mb-2">People</h3>
      <div id="contacts" class="overflow-y-auto md:h-[62vh] space-y-3 pr-1">
        <?php foreach ($cats as $group => $list): $count = count($list); ?>
          <div class="role-card border border-gray-100 rounded-lg overflow-hidden">
            <button type="button" class="role-card-header w-full flex items-center justify-between px-3 py-2 bg-gray-50 hover:bg-gray-100">
              <div class="flex items-center gap-2">
                <span class="caret" aria-hidden="true"><?php echo ($group === 'Clients') ? '▾' : '▸'; ?></span>
                <span class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($group); ?></span>
              </div>
              <span class="text-xs text-gray-500 bg-gray-200 rounded-full px-2 py-0.5"><?php echo (int)$count; ?></span>
            </button>
            <div class="role-card-body" style="<?php echo ($group === 'Clients') ? '' : 'display:none;'; ?>">
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
<?php include __DIR__ . '/../../backend/core/footer.php'; ?>
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
    bubble.className = `msg-bubble max-w-[80%] px-3 py-2 rounded-lg ${isMe ? 'bg-purple-600 text-white' : 'bg-white border'}`;
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
      const res = await fetch(`${endpoint}?action=fetch_messages&peer_id=${currentPeer}&after_id=${lastId}`);
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
  // Delegate clicks for dynamic elements
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
      form.append('action','send_message');
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

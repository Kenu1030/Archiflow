<?php
require_once __DIR__ . '/_client_common.php';

// Build recipient list: Senior Architects only (active)
$recipients = [];
try {
  // Prefer users.position when available; fallback to employees.position; also accept user_type 'senior_architect'
  $sql = "SELECT u.user_id, u.first_name, u.last_name,
                 COALESCE(u.position, e.position) AS position
          FROM users u
          LEFT JOIN employees e ON e.user_id = u.user_id
          WHERE u.is_active = 1
            AND (
              LOWER(COALESCE(u.position, e.position, '')) LIKE '%senior%architect%'
              OR LOWER(COALESCE(u.user_type, '')) = 'senior_architect'
            )
          ORDER BY u.first_name, u.last_name";
  foreach ($pdo->query($sql) as $row) {
    $recipients[(int)$row['user_id']] = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? '')).' (Senior Architect)';
  }
} catch (Throwable $e) {}

// Using dedicated chat tables: chat, chat_messages

// Client projects for optional association
$clientProjects = [];
try {
  $cols = ['project_id'];
  if ($hasColumn($pdo,'projects','project_name')) { $cols[] = 'project_name'; }
  if ($hasColumn($pdo,'projects','project_code')) { $cols[] = 'project_code'; }
  $sql = 'SELECT '.implode(',', $cols).' FROM projects WHERE client_id = ? ORDER BY created_at DESC';
  $st = $pdo->prepare($sql);
  $st->execute([$clientId]);
  $clientProjects = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Realtime chat fetch endpoint (JSON)
if (($_GET['action'] ?? '') === 'chat_fetch') {
  header('Content-Type: application/json');
  try {
    $me = (int)($_SESSION['user_id'] ?? 0);
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    $peerId = (int)($_GET['peer_id'] ?? 0);
    $afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : 0;
  if (!$me || !$peerId) { echo json_encode(['ok'=>false,'error'=>'bad-ids']); exit; }
    // Find or create chat_id for (client, SA). Current user is client (users.user_id)
    $chatId = null;
    try {
      $s = $pdo->prepare("SELECT chat_id FROM chat WHERE client_id = ? AND senior_architect_id = ? LIMIT 1");
      $s->execute([$userId, $peerId]);
      $chatId = $s->fetchColumn();
    } catch (Throwable $e) { $chatId = null; }
    // Legacy fix: if not found and we have a clients.client_id mapping, migrate chat row to users.user_id
    if (!$chatId && !empty($clientId) && (int)$clientId !== (int)$userId) {
      try {
        $s2 = $pdo->prepare("SELECT chat_id FROM chat WHERE client_id = ? AND senior_architect_id = ? LIMIT 1");
        $s2->execute([$clientId, $peerId]);
        $legacy = $s2->fetchColumn();
        if ($legacy) {
          $pdo->prepare("UPDATE chat SET client_id = ? WHERE chat_id = ?")->execute([$userId, $legacy]);
          $chatId = $legacy;
        }
      } catch (Throwable $e) {}
    }
    if (!$chatId) { echo json_encode(['ok'=>true,'messages'=>[]]); exit; }
    // Fetch chat messages after cursor
    $sql = "SELECT message_id AS id, sender_id, body, sent_at
            FROM chat_messages
            WHERE chat_id = ? AND message_id > ?
            ORDER BY message_id ASC
            LIMIT 200";
    $st = $pdo->prepare($sql);
    $st->execute([$chatId, $afterId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['ok'=>true,'messages'=>$rows]);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>'server']);
  }
  exit;
}

// Send message modal handler
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='send_message') {
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    if (($_POST['ajax'] ?? '') === '1') { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'bad-token']); exit; }
    http_response_code(400); exit('Bad token');
  }
  if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
  $text = trim($_POST['message'] ?? '');
  $chosenRecipient = (int)($_POST['recipient_id'] ?? 0);
  $chosenProject = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : null;
  $subjectVal = trim($_POST['subject'] ?? '');
  $resp = ['ok'=>false, 'error'=>null];
  if ($text !== '') {
    try {
      // Enforce recipient is a Senior Architect (defense-in-depth)
      $isSA = false;
      if ($chosenRecipient) {
        try {
          $check = $pdo->prepare("SELECT 1
                                  FROM users u
                                  LEFT JOIN employees e ON e.user_id = u.user_id
                                  WHERE u.user_id = ? AND u.is_active = 1 AND (
                                    LOWER(COALESCE(u.position, e.position, '')) LIKE '%senior%architect%'
                                    OR LOWER(COALESCE(u.user_type, '')) = 'senior_architect'
                                  ) LIMIT 1");
          $check->execute([$chosenRecipient]);
          $isSA = (bool)$check->fetchColumn();
        } catch (Throwable $e) { $isSA = false; }
      }
      if (!$isSA) { throw new Exception('Invalid recipient'); }

      // UI list may be stale; DB check above (isSA) is authoritative

      // Find or create chat
      $chatId = null;
      $sel = $pdo->prepare("SELECT chat_id FROM chat WHERE client_id = ? AND senior_architect_id = ? LIMIT 1");
      $sel->execute([$userId, $chosenRecipient]);
      $chatId = $sel->fetchColumn();
      if (!$chatId) {
        $ins = $pdo->prepare("INSERT INTO chat (client_id, senior_architect_id, last_message_at) VALUES (?,?,NOW())");
        $ins->execute([$userId, $chosenRecipient]);
        $chatId = (int)$pdo->lastInsertId();
      }
      // Legacy fix: migrate any existing chat rows using clients.client_id to users.user_id
      if (!empty($clientId) && (int)$clientId !== (int)$userId) {
        try {
          $m = $pdo->prepare("UPDATE chat SET client_id = ? WHERE client_id = ? AND senior_architect_id = ?");
          $m->execute([$userId, $clientId, $chosenRecipient]);
        } catch (Throwable $e) {}
      }

      // Insert message
      $mi = $pdo->prepare("INSERT INTO chat_messages (chat_id, sender_id, body) VALUES (?,?,?)");
      $ok = $mi->execute([$chatId, (int)$_SESSION['user_id'], $text]);
      // Update chat last_message_at
      if ($ok) { $pdo->prepare("UPDATE chat SET last_message_at = NOW() WHERE chat_id = ?")->execute([$chatId]); }
      $resp['ok'] = (bool)$ok;
      if (!$ok) { $resp['error'] = 'insert-failed'; }
    } catch (Throwable $e) { $resp['ok']=false; $resp['error']='server'; }
  }
  // If AJAX flag is present, return JSON to avoid redirect
  if (($_POST['ajax'] ?? '') === '1') { header('Content-Type: application/json'); echo json_encode($resp); exit; }
  header('Location: messages.php'); exit;
}
// Suppress footer on this page
$HIDE_FOOTER = true;
include_once __DIR__ . '/../backend/core/header.php';
?>
<section class="bg-gradient-to-br from-blue-900 to-indigo-800 text-white py-8">
  <div class="max-w-full px-4 flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-semibold">Messages</h1>
  <p class="text-white/70">Your inbox</p>
    </div>
    <button id="openSend" class="bg-white text-blue-700 px-4 py-2 rounded-lg">New Message</button>
  </div>
</section>
<main class="max-w-full px-4 -mt-6">
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <!-- Contacts -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 md:h-[70vh]">
      <h3 class="text-sm font-semibold text-gray-700 mb-2">Senior Architects</h3>
      <div id="contacts" class="overflow-y-auto md:h-[62vh] divide-y">
        <?php foreach ($recipients as $rid => $label): ?>
          <button data-peer="<?php echo (int)$rid; ?>" class="contact-btn w-full text-left py-3 px-2 hover:bg-gray-50 flex items-center justify-between">
            <span class="truncate pr-2"><?php echo htmlspecialchars($label); ?></span>
            <span class="hidden md:inline text-xs text-gray-400">Chat</span>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
    <!-- Chat Window -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-0 md:col-span-2 md:h-[70vh] flex flex-col">
      <div id="chatHeader" class="px-4 py-3 border-b text-sm text-gray-600">Select a Senior Architect to start chatting</div>
      <div id="chatBox" class="flex-1 overflow-y-auto p-4 space-y-3 bg-gray-50"></div>
      <form id="chatForm" class="border-t p-3 flex items-center gap-2">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>" />
        <input type="hidden" name="action" value="send_message" />
        <input type="hidden" name="recipient_id" id="chatRecipient" value="" />
        <input type="hidden" name="ajax" value="1" />
        <input type="text" id="chatInput" name="message" class="flex-1 border rounded-lg px-3 py-2" placeholder="Type a message..." autocomplete="off" />
        <button id="chatSend" class="bg-blue-600 text-white rounded-lg px-4 py-2 disabled:opacity-50" type="submit" disabled>Send</button>
      </form>
    </div>
  </div>
</main>

<!-- Send Message Modal -->
<div id="sendModal" class="fixed inset-0 hidden items-center justify-center z-50">
  <div class="absolute inset-0 bg-black/50"></div>
  <div class="relative bg-white rounded-xl shadow-xl w-full max-w-lg p-6">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold">New Message</h3>
      <button id="closeSend" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times"></i></button>
    </div>
    <form method="post" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>" />
      <input type="hidden" name="action" value="send_message" />
      <div>
        <label class="block text-sm font-medium text-gray-700">Send to Senior Architect</label>
        <select name="recipient_id" class="mt-1 w-full border rounded-lg p-2" required>
          <option value="">Select recipient</option>
          <?php foreach ($recipients as $rid => $label): ?>
            <option value="<?php echo (int)$rid; ?>"><?php echo htmlspecialchars($label); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($clientProjects): ?>
      <div>
        <label class="block text-sm font-medium text-gray-700">Project (optional)</label>
        <select name="project_id" class="mt-1 w-full border rounded-lg p-2">
          <option value="">—</option>
          <?php foreach ($clientProjects as $pr): $nm = ($pr['project_name'] ?? null) ?: (($pr['project_code'] ?? null) ?: ('Project #'.$pr['project_id'])); ?>
            <option value="<?php echo (int)$pr['project_id']; ?>"><?php echo htmlspecialchars($nm); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($hasColumn($pdo,'messages','subject')): ?>
      <div>
        <label class="block text-sm font-medium text-gray-700">Subject</label>
        <input type="text" name="subject" class="mt-1 w-full border rounded-lg p-2" maxlength="200" />
      </div>
      <?php endif; ?>
      <div>
        <label class="block text-sm font-medium text-gray-700">Message</label>
        <textarea name="message" rows="4" class="mt-1 w-full border rounded-lg p-2" required></textarea>
      </div>
      <div class="flex justify-end space-x-3">
        <button type="button" id="cancelSend" class="px-4 py-2 bg-gray-200 rounded-lg">Cancel</button>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg">Send</button>
      </div>
    </form>
  </div>
</div>

<?php include_once __DIR__ . '/../backend/core/footer.php'; ?>
<script>
  // Modal controls (kept for optional new-message flow)
  const open=()=>{const m=document.getElementById('sendModal');m.classList.remove('hidden');m.classList.add('flex');};
  const close=()=>{const m=document.getElementById('sendModal');m.classList.add('hidden');m.classList.remove('flex');};
  document.getElementById('openSend').addEventListener('click', open);
  document.getElementById('closeSend').addEventListener('click', close);
  document.getElementById('cancelSend').addEventListener('click', close);

  // Chat state
  let currentPeer = null;
  let lastId = 0;
  const chatBox = document.getElementById('chatBox');
  const chatHeader = document.getElementById('chatHeader');
  const chatForm = document.getElementById('chatForm');
  const chatRecipient = document.getElementById('chatRecipient');
  const chatInput = document.getElementById('chatInput');
  const chatSend = document.getElementById('chatSend');

  const renderMsg = (m, meId) => {
    const isMe = (parseInt(m.sender_id,10) === meId);
    const wrap = document.createElement('div');
    wrap.className = `flex ${isMe ? 'justify-end' : 'justify-start'}`;
    const bubble = document.createElement('div');
    bubble.className = `msg-bubble max-w-[80%] px-3 py-2 rounded-lg ${isMe ? 'bg-blue-600 text-white' : 'bg-white border'}`;
    bubble.textContent = m.body || '';
    if (m.temp === true) {
      bubble.dataset.temp = '1';
      bubble.dataset.body = m.body || '';
    }
    wrap.appendChild(bubble);
    chatBox.appendChild(wrap);
  };

  const meId = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;

  let isFetching = false;
  const endpoint = window.location.pathname;
  let pendingBody = null;
  const fetchNew = async () => {
    if (!currentPeer) return;
    if (isFetching) return; isFetching = true;
    try {
      const res = await fetch(`${endpoint}?action=chat_fetch&peer_id=${currentPeer}&after_id=${lastId}`);
      const data = await res.json();
      if (!data.ok) return;
      for (const m of data.messages) {
        const mid = parseInt(m.id, 10);
        if (!Number.isNaN(mid)) lastId = Math.max(lastId, mid);
        // Replace optimistic bubble if this is my echo with same body
        if (parseInt(m.sender_id,10) === meId && pendingBody && (m.body || '') === pendingBody) {
          const temps = chatBox.querySelectorAll('.msg-bubble[data-temp="1"]');
          for (const el of temps) { if ((el.dataset.body || '') === pendingBody) { el.parentElement?.remove(); break; } }
          pendingBody = null;
        }
        renderMsg(m, meId);
      }
      if (data.messages && data.messages.length) {
        chatBox.scrollTop = chatBox.scrollHeight;
      }
    } catch (e) {}
    finally { isFetching = false; }
  };

  // Polling
  setInterval(fetchNew, 2500);

  // Open conversation
  document.querySelectorAll('.contact-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      currentPeer = parseInt(btn.getAttribute('data-peer'), 10);
      lastId = 0;
      chatRecipient.value = currentPeer;
      chatBox.innerHTML = '';
      chatHeader.textContent = btn.textContent.trim();
      chatSend.disabled = false;
      fetchNew();
    });
  });

  // Send message via AJAX
  chatForm.addEventListener('submit', async (e) => {
    e.preventDefault();
  if (!currentPeer) { alert('Select a Senior Architect first.'); return; }
    const text = chatInput.value.trim();
    if (!text) return;
    const formData = new FormData(chatForm);
    // optimistic UI: render my bubble immediately
  const temp = { sender_id: meId, body: text, temp: true };
    renderMsg(temp, meId);
    chatBox.scrollTop = chatBox.scrollHeight;
    chatInput.value = '';
  pendingBody = text;
    try {
      // Ensure ajax=1 flag present
      formData.set('ajax','1');
      const controller = new AbortController();
      const to = setTimeout(()=>controller.abort(), 10000);
  const res = await fetch(endpoint, { method: 'POST', body: formData, signal: controller.signal });
      clearTimeout(to);
      let data = null;
      try { data = await res.json(); } catch (parseErr) { data = null; }
      if (data && data.ok) {
        fetchNew();
      } else {
        alert('Message not sent. ' + (data && data.error ? data.error : 'Please try again.'));
        // fallback: reload chat to remove optimistic bubble if necessary
        lastId = 0; chatBox.innerHTML = ''; fetchNew();
      }
    } catch (e) { alert('Network error. Please try again.'); }
  });
</script>

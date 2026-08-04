<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../test_sqlsrv.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
auth_check();

// ── RBAC Gate ─────────────────────────────────────────────────
rbac_gate($pdo, 'message_user');

$currentUser     = (int) $_SESSION['UserID'];
$currentUserName = trim(($_SESSION['FirstName'] ?? '') . ' ' . ($_SESSION['LastName'] ?? ''));

// ── Load conversations (most-recent first, with last-message snippet) ──
$sqlConversations = "
    SELECT
        u.id,
        e.FirstName + ' ' + e.LastName AS DisplayName,
        e.Department,
        MAX(m.DateSent) AS LastActivity,
        (
            SELECT TOP 1 MessageText
            FROM Messages
            WHERE (SenderID = u.id   AND ReceiverID = ?)
               OR (SenderID = ?      AND ReceiverID = u.id)
            ORDER BY DateSent DESC
        ) AS LastMessage
    FROM users u
    INNER JOIN TBL_HREmployeeList e ON u.EmployeeID = e.EmployeeID
    INNER JOIN Messages m
           ON (m.SenderID = u.id AND m.ReceiverID = ?)
           OR (m.SenderID = ?   AND m.ReceiverID = u.id)
    WHERE e.Active = 1
      AND u.id != ?
    GROUP BY u.id, e.FirstName, e.LastName, e.Department
    ORDER BY LastActivity DESC
";
$stmtConv = sqlsrv_query($conn, $sqlConversations, [
    $currentUser, $currentUser,
    $currentUser, $currentUser,
    $currentUser,
]);
if (!$stmtConv) {
    $errs = sqlsrv_errors();
    die("Failed to load conversations: " . ($errs[0]['message'] ?? 'Unknown error'));
}

$avatarPalette = ['#7c3aed','#2563eb','#0d9488','#ea580c','#16a34a','#dc2626','#0891b2','#9333ea'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Messages — Tradewell</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
:root {
  --tw-accent   : #7c3aed;
  --tw-accent2  : #a78bfa;
  --tw-blue     : #2563eb;
  --tw-green    : #16a34a;
  --tw-red      : #dc2626;
  --tw-border   : #e5e7eb;
  --tw-bg       : #f5f3ff;
  --tw-card     : #fff;
  --tw-text     : #111827;
  --tw-dim      : #6b7280;
  --tw-sidebar  : 300px;
}

*, *::before, *::after { box-sizing: border-box; }

body {
  background: var(--tw-bg);
  font-family: 'Segoe UI', system-ui, sans-serif;
  overflow: hidden;
  margin: 0;
}

/* ── Layout ── */
.msg-page {
  display: flex;
  height: calc(100vh - var(--topbar-height, 56px));
  margin-top: var(--topbar-height, 56px);
  background: var(--tw-card);
  overflow: hidden;
}

/* ══════════════════════════════════════
   SIDEBAR
══════════════════════════════════════ */
.msg-sidebar {
  width: var(--tw-sidebar);
  min-width: var(--tw-sidebar);
  display: flex;
  flex-direction: column;
  border-right: 1px solid var(--tw-border);
  background: var(--tw-card);
  transition: width .2s;
}

.sidebar-head {
  padding: 1.1rem 1rem .75rem;
  border-bottom: 1px solid var(--tw-border);
  background: #fafafa;
}

.sidebar-head h2 {
  font-size: 1rem;
  font-weight: 800;
  color: var(--tw-text);
  margin: 0 0 .65rem;
  display: flex;
  align-items: center;
  gap: .5rem;
}

.sidebar-head h2 .bi { color: var(--tw-accent); font-size: 1.1rem; }

.search-wrap { position: relative; }

.search-wrap i.bi-search {
  position: absolute;
  left: .65rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--tw-dim);
  font-size: .8rem;
  pointer-events: none;
}

.search-wrap .search-clear {
  position: absolute;
  right: .65rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--tw-dim);
  font-size: .8rem;
  cursor: pointer;
  display: none;
  border: none;
  background: none;
  padding: 0;
  line-height: 1;
}

.search-wrap input {
  width: 100%;
  background: #f0f0f5;
  border: 1.5px solid transparent;
  border-radius: 999px;
  padding: .45rem 1.8rem .45rem 2rem;
  font-size: .82rem;
  outline: none;
  color: var(--tw-text);
  transition: border-color .15s, background .15s;
}

.search-wrap input:focus {
  background: #fff;
  border-color: var(--tw-accent2);
  box-shadow: 0 0 0 3px rgba(124,58,237,.08);
}

/* ── User list ── */
.user-list {
  flex: 1;
  overflow-y: auto;
  padding: .4rem 0;
  position: relative;
}

.user-list::-webkit-scrollbar { width: 4px; }
.user-list::-webkit-scrollbar-thumb { background: #ddd6fe; border-radius: 4px; }

.sidebar-empty {
  text-align: center;
  padding: 2rem 1rem;
  color: var(--tw-dim);
  font-size: .82rem;
  line-height: 1.6;
}
.sidebar-empty .bi {
  font-size: 1.8rem;
  display: block;
  margin-bottom: .5rem;
  opacity: .3;
}

/* Search results overlay */
#searchResults {
  display: none;
  position: absolute;
  top: 0; left: 0; right: 0; bottom: 0;
  background: var(--tw-card);
  z-index: 10;
  overflow-y: auto;
  padding: .4rem 0;
}

#searchResults .search-section-label {
  font-size: .68rem;
  font-weight: 700;
  color: var(--tw-dim);
  letter-spacing: .06em;
  text-transform: uppercase;
  padding: .5rem .85rem .25rem;
}

#searchResults .search-spinner,
#searchResults .search-none {
  text-align: center;
  padding: 1.5rem;
  color: var(--tw-dim);
  font-size: .82rem;
}

.chat-user {
  display: flex;
  align-items: center;
  gap: .75rem;
  padding: .6rem .85rem;
  cursor: pointer;
  border-radius: 10px;
  margin: 2px .5rem;
  transition: background .12s;
  position: relative;
  border: 1.5px solid transparent;
}

.chat-user:hover  { background: #f5f3ff; }
.chat-user.active { background: #ede9fe; border-color: #ddd6fe; }

.avatar {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: .88rem;
  flex-shrink: 0;
  letter-spacing: .5px;
}

.user-meta { flex: 1; min-width: 0; }
.user-meta strong {
  display: block;
  font-size: .87rem;
  font-weight: 600;
  color: var(--tw-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.user-meta small {
  font-size: .73rem;
  color: var(--tw-dim);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  display: block;
}

.user-meta .last-msg {
  font-size: .72rem;
  color: var(--tw-dim);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  display: block;
  margin-top: 1px;
  font-style: italic;
}

.unread-badge {
  background: var(--tw-accent);
  color: #fff;
  font-size: .65rem;
  font-weight: 700;
  border-radius: 999px;
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  display: none;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

/* ══════════════════════════════════════
   CHAT MAIN
══════════════════════════════════════ */
.chat-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
  overflow: hidden;
  background: var(--tw-bg);
}

#noChatSelected {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: var(--tw-dim);
  text-align: center;
  gap: 1rem;
  padding: 2.5rem;
}

.welcome-icon {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  background: #ede9fe;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  margin-bottom: .5rem;
}

#noChatSelected h3 {
  font-size: 1.1rem;
  font-weight: 700;
  color: var(--tw-text);
  margin: 0;
}

#noChatSelected p {
  font-size: .875rem;
  margin: 0;
  max-width: 240px;
  line-height: 1.5;
}

/* ── Active chat area ── */
.chat-area {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
  overflow: hidden;
  background: var(--tw-bg);
}

.chat-header {
  display: flex;
  align-items: center;
  gap: .85rem;
  padding: .85rem 1.25rem;
  border-bottom: 1px solid var(--tw-border);
  background: var(--tw-card);
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
  flex-shrink: 0;
}

.chat-header .avatar { width: 40px; height: 40px; font-size: .85rem; }

.chat-header-info { flex: 1; min-width: 0; }
.chat-header-info strong {
  display: block;
  font-size: .95rem;
  font-weight: 700;
  color: var(--tw-text);
}
.chat-header-info small { font-size: .75rem; color: var(--tw-dim); }

.header-actions { display: flex; align-items: center; gap: .35rem; }

.icon-btn {
  width: 34px; height: 34px;
  border-radius: 8px;
  border: 1.5px solid var(--tw-border);
  background: var(--tw-card);
  color: var(--tw-dim);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: .9rem;
  transition: all .15s;
}

.icon-btn:hover {
  border-color: var(--tw-accent2);
  color: var(--tw-accent);
  background: #f5f3ff;
}

.chat-messages {
  flex: 1;
  overflow-y: auto;
  padding: 1.1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 3px;
  scroll-behavior: smooth;
}

.chat-messages::-webkit-scrollbar { width: 5px; }
.chat-messages::-webkit-scrollbar-thumb { background: #c4b5fd; border-radius: 4px; }

.date-divider {
  display: flex;
  align-items: center;
  gap: .6rem;
  margin: .85rem 0 .6rem;
  color: var(--tw-dim);
  font-size: .72rem;
  font-weight: 600;
  letter-spacing: .04em;
  text-transform: uppercase;
}

.date-divider::before, .date-divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--tw-border);
}

/* Message rows & groups */
.msg-row {
  display: flex;
  align-items: flex-end;
  gap: 6px;
}
.msg-row.sent     { justify-content: flex-end; }
.msg-row.received { justify-content: flex-start; }

.msg-group {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-width: 68%;
  position: relative;
}
.msg-group.sent     { align-self: flex-end;   align-items: flex-end; }
.msg-group.received { align-self: flex-start; align-items: flex-start; }

/* ── Single definition of .msg-avatar ── */
.msg-avatar {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: #7c3aed;
  color: #fff;
  font-size: 11px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  flex-shrink: 0;
}

.msg {
  padding: .55rem .95rem;
  border-radius: 18px;
  font-size: .88rem;
  line-height: 1.45;
  word-break: break-word;
  max-width: 100%;
  position: relative;
  cursor: pointer;
  transition: opacity .1s;
}

.sent .msg {
  background: var(--tw-accent);
  color: #fff;
}
.sent .msg:first-child                            { border-top-right-radius: 18px; border-bottom-right-radius: 5px; }
.sent .msg:last-child                             { border-bottom-right-radius: 18px; }
.sent .msg:not(:first-child):not(:last-child)     { border-radius: 5px 18px 18px 5px; }

.received .msg {
  background: var(--tw-card);
  color: var(--tw-text);
  border: 1px solid var(--tw-border);
  box-shadow: 0 1px 2px rgba(0,0,0,.04);
}
.received .msg:first-child                        { border-top-left-radius: 18px; border-bottom-left-radius: 5px; }
.received .msg:last-child                         { border-bottom-left-radius: 18px; }
.received .msg:not(:first-child):not(:last-child) { border-radius: 18px 5px 5px 18px; }

.msg-time {
  font-size: .68rem;
  color: var(--tw-dim);
  padding: 2px 4px;
  margin-top: 2px;
}

.typing-indicator {
  display: none;
  align-items: center;
  gap: 4px;
  padding: .6rem .85rem;
  background: var(--tw-card);
  border: 1px solid var(--tw-border);
  border-radius: 18px;
  width: fit-content;
  box-shadow: 0 1px 2px rgba(0,0,0,.04);
}

.typing-indicator span {
  width: 6px; height: 6px;
  border-radius: 50%;
  background: var(--tw-accent2);
  animation: tybounce 1.2s infinite;
}
.typing-indicator span:nth-child(2) { animation-delay: .2s; }
.typing-indicator span:nth-child(3) { animation-delay: .4s; }

@keyframes tybounce {
  0%, 80%, 100% { transform: scale(1); opacity: .5; }
  40%           { transform: scale(1.35); opacity: 1; }
}

.chat-send {
  padding: .8rem 1rem;
  border-top: 1px solid var(--tw-border);
  background: var(--tw-card);
  display: flex;
  align-items: flex-end;
  gap: .6rem;
  flex-shrink: 0;
}

.send-input-wrap {
  flex: 1;
  background: #f5f3ff;
  border: 1.5px solid #ddd6fe;
  border-radius: 20px;
  padding: .55rem 1rem;
  display: flex;
  align-items: flex-end;
  gap: .5rem;
  transition: border-color .15s, box-shadow .15s;
}

.send-input-wrap:focus-within {
  border-color: var(--tw-accent);
  box-shadow: 0 0 0 3px rgba(124,58,237,.1);
  background: #fff;
}

#msgInput {
  flex: 1;
  border: none;
  background: transparent;
  outline: none;
  font-size: .88rem;
  resize: none;
  max-height: 120px;
  line-height: 1.45;
  color: var(--tw-text);
  font-family: inherit;
  overflow-y: auto;
}

#msgInput::placeholder { color: var(--tw-dim); }

#charCount {
  font-size: .68rem;
  color: var(--tw-dim);
  white-space: nowrap;
  align-self: flex-end;
  padding-bottom: 1px;
  display: none;
  font-family: 'JetBrains Mono', monospace;
}
#charCount.warn   { color: #ea580c; }
#charCount.danger { color: var(--tw-red); font-weight: 700; }

.btn-send {
  width: 40px; height: 40px;
  border-radius: 50%;
  border: none;
  background: var(--tw-accent);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background .15s, transform .1s, box-shadow .15s;
  flex-shrink: 0;
  box-shadow: 0 2px 6px rgba(124,58,237,.35);
}
.btn-send:hover:not(:disabled)  { background: #6d28d9; box-shadow: 0 4px 12px rgba(124,58,237,.4); }
.btn-send:active:not(:disabled) { transform: scale(.93); }
.btn-send:disabled               { background: #d1d5db; cursor: not-allowed; box-shadow: none; }

.toast-wrap {
  position: fixed;
  bottom: 1.5rem; right: 1.5rem;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  gap: .5rem;
  pointer-events: none;
}

.toast {
  background: var(--tw-text);
  color: #fff;
  padding: .6rem 1rem;
  border-radius: 10px;
  font-size: .82rem;
  font-weight: 500;
  box-shadow: 0 4px 16px rgba(0,0,0,.18);
  display: flex;
  align-items: center;
  gap: .5rem;
  animation: toastIn .25s ease;
  pointer-events: auto;
  max-width: 320px;
}
.toast.error   { background: var(--tw-red); }
.toast.success { background: var(--tw-green); }

@keyframes toastIn {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}

.no-messages {
  text-align: center;
  padding: 2.5rem 1rem;
  color: var(--tw-dim);
  font-size: .85rem;
  line-height: 1.6;
}
.no-messages .bi { font-size: 2rem; display: block; margin-bottom: .5rem; opacity: .3; }

.msg.sending { opacity: .55; }

.scroll-btn {
  position: absolute;
  bottom: 80px; right: 18px;
  width: 34px; height: 34px;
  border-radius: 50%;
  background: var(--tw-accent);
  color: #fff;
  border: none;
  cursor: pointer;
  display: none;            /* toggled via JS */
  align-items: center;
  justify-content: center;
  font-size: .85rem;
  box-shadow: 0 2px 8px rgba(124,58,237,.35);
  transition: opacity .2s;
  z-index: 10;
}

.hidden { display: none !important; }

@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 600px) {
  :root { --tw-sidebar: 100%; }
  .msg-sidebar { position: fixed; inset: 0; z-index: 100; }
  .msg-sidebar.collapsed { transform: translateX(-100%); }
}
</style>
</head>
<body>

<?php $topbar_page = 'messages'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="msg-page">

  <!-- ══ SIDEBAR ══════════════════════════════════════════ -->
  <div class="msg-sidebar" id="msgSidebar">
    <div class="sidebar-head">
      <h2><i class="bi bi-chat-dots-fill"></i> Messages</h2>
      <div class="search-wrap">
        <i class="bi bi-search"></i>
        <input type="text" id="searchInput" placeholder="Search all employees…" autocomplete="off">
        <button class="search-clear" id="searchClear" title="Clear search">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
    </div>

    <div class="user-list" id="userList">

      <!-- Conversation list (default view) -->
      <div id="convList">
        <?php
        $ci = 0;
        $hasConversations = false;
        while ($u = sqlsrv_fetch_array($stmtConv, SQLSRV_FETCH_ASSOC)):
            $hasConversations = true;
            $initial = strtoupper(substr($u['DisplayName'], 0, 1));
            $color   = $avatarPalette[$ci % count($avatarPalette)];
            $dept    = htmlspecialchars($u['Department'] ?? '');
            $lastMsg = htmlspecialchars(mb_strimwidth((string)($u['LastMessage'] ?? ''), 0, 40, '…'));
            $ci++;
        ?>
        <div class="chat-user"
             data-id="<?= (int)$u['id'] ?>"
             data-name="<?= htmlspecialchars($u['DisplayName'], ENT_QUOTES) ?>"
             data-dept="<?= $dept ?>"
             data-initial="<?= $initial ?>"
             data-color="<?= $color ?>">

          <div class="avatar" style="background:<?= $color ?>"><?= $initial ?></div>

          <div class="user-meta">
            <strong><?= htmlspecialchars($u['DisplayName']) ?></strong>
            <small><?= $dept ?></small>
            <span class="last-msg" id="lastmsg-<?= (int)$u['id'] ?>"><?= $lastMsg ?></span>
          </div>

          <div class="unread-badge" id="badge-<?= (int)$u['id'] ?>"></div>
        </div>
        <?php endwhile; sqlsrv_free_stmt($stmtConv); ?>

        <?php if (!$hasConversations): ?>
        <div class="sidebar-empty">
          <i class="bi bi-chat-square-dots"></i>
          No conversations yet.<br>Search for someone to start chatting.
        </div>
        <?php endif; ?>
      </div>

      <!-- Search results overlay -->
      <div id="searchResults"></div>

    </div>
  </div>

  <!-- ══ MAIN ══════════════════════════════════════════════ -->
  <div class="chat-main">

    <!-- Welcome / empty state -->
    <div id="noChatSelected" style="display:flex;">
      <div class="welcome-icon">💬</div>
      <h3>Your Messages</h3>
      <p>Select a conversation or search for someone to start chatting.</p>
    </div>

    <!-- Active chat -->
    <div class="chat-area hidden" id="chatArea">

      <div class="chat-header" id="chatHeader">
        <div class="avatar" id="headerAvatar" style="width:40px;height:40px;font-size:.85rem;"></div>
        <div class="chat-header-info">
          <strong id="headerName"></strong>
          <small id="headerDept"></small>
        </div>
        <div class="header-actions">
          <button class="icon-btn" title="Refresh" onclick="loadMessages(true)">
            <i class="bi bi-arrow-clockwise"></i>
          </button>
        </div>
      </div>

      <div style="flex:1;position:relative;display:flex;flex-direction:column;overflow:hidden;min-height:0;">
        <div class="chat-messages" id="messages">
          <div class="typing-indicator" id="typingIndicator">
            <span></span><span></span><span></span>
          </div>
        </div>
        <button class="scroll-btn" id="scrollBtn" title="Scroll to bottom" onclick="scrollToBottom()">
          <i class="bi bi-chevron-down"></i>
        </button>
      </div>

      <div class="chat-send" style="flex-shrink:0;">
        <div class="send-input-wrap">
          <textarea id="msgInput" rows="1" placeholder="Type a message… (Enter to send, Shift+Enter for new line)" maxlength="2000"></textarea>
          <span id="charCount"></span>
        </div>
        <button class="btn-send" id="sendBtn" disabled aria-label="Send message">
          <i class="bi bi-send-fill"></i>
        </button>
      </div>

    </div>

  </div>
</div>

<!-- Toast container -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
const MAX_CHARS   = 2000;
const CURRENT_UID = <?= $currentUser ?>;
const PALETTE     = ['#7c3aed','#2563eb','#0d9488','#ea580c','#16a34a','#dc2626','#0891b2','#9333ea'];

let currentReceiver     = null;
let currentReceiverName = '';
let pollTimer           = null;
let isSending           = false;
let lastMsgId           = 0;     // tracks highest MessageID seen for incremental loading
let searchDebounce      = null;

const msgInput      = document.getElementById('msgInput');
const sendBtn       = document.getElementById('sendBtn');
const messagesBox   = document.getElementById('messages');
const chatArea      = document.getElementById('chatArea');
const noChatSel     = document.getElementById('noChatSelected');
const charCount     = document.getElementById('charCount');
const searchInput   = document.getElementById('searchInput');
const searchClear   = document.getElementById('searchClear');
const searchResults = document.getElementById('searchResults');
const convList      = document.getElementById('convList');
const headerAvatar  = document.getElementById('headerAvatar');
const headerName    = document.getElementById('headerName');
const headerDept    = document.getElementById('headerDept');
const scrollBtn     = document.getElementById('scrollBtn');

// ── Toast helper ──────────────────────────────────────────────
function showToast(msg, type = '') {
    const wrap  = document.getElementById('toastWrap');
    const toast = document.createElement('div');
    toast.className = 'toast' + (type ? ' ' + type : '');
    toast.innerHTML = (type === 'error' ? '⚠️ ' : type === 'success' ? '✔ ' : 'ℹ️ ') + escHtml(msg);
    wrap.appendChild(toast);
    setTimeout(() => toast.remove(), 3500);
}

// ── HTML escape ───────────────────────────────────────────────
function escHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ── Open a chat ───────────────────────────────────────────────
function openChat(id, name, dept, initial, color) {
    const idStr = String(id);
    if (currentReceiver === idStr) { clearSearch(); return; }

    document.querySelectorAll('.chat-user').forEach(x => x.classList.remove('active'));

    const existing = convList.querySelector(`.chat-user[data-id="${id}"]`);
    if (existing) existing.classList.add('active');

    currentReceiver     = idStr;
    currentReceiverName = name;
    lastMsgId           = 0;   // reset so we do a full load for new conversation

    headerAvatar.textContent      = initial;
    headerAvatar.style.background = color;
    headerName.textContent        = name;
    headerDept.textContent        = dept;

    noChatSel.style.display = 'none';
    chatArea.classList.remove('hidden');

    // Clear messages, keeping the typing indicator in the DOM
    const typerEl = document.getElementById('typingIndicator');
    while (messagesBox.firstChild) messagesBox.removeChild(messagesBox.firstChild);
    messagesBox.appendChild(typerEl);

    loadMessages(true);
    startPolling();
    msgInput.focus();

    if (!existing) addToConvList(id, name, dept, initial, color);
    clearSearch();
}

// ── Add a new conversation to the sidebar ────────────────────
function addToConvList(id, name, dept, initial, color) {
    const empty = convList.querySelector('.sidebar-empty');
    if (empty) empty.remove();

    const div = document.createElement('div');
    div.className = 'chat-user active';
    div.dataset.id      = id;
    div.dataset.name    = name;
    div.dataset.dept    = dept;
    div.dataset.initial = initial;
    div.dataset.color   = color;
    div.innerHTML = `
        <div class="avatar" style="background:${color}">${escHtml(initial)}</div>
        <div class="user-meta">
            <strong>${escHtml(name)}</strong>
            <small>${escHtml(dept)}</small>
            <span class="last-msg" id="lastmsg-${id}"></span>
        </div>
        <div class="unread-badge" id="badge-${id}"></div>
    `;
    div.addEventListener('click', () => openChat(id, name, dept, initial, color));
    convList.prepend(div);
}

// ── Bind conversation list items ──────────────────────────────
convList.querySelectorAll('.chat-user').forEach(u => {
    u.addEventListener('click', function () {
        openChat(this.dataset.id, this.dataset.name, this.dataset.dept,
                 this.dataset.initial, this.dataset.color);
    });
});

// ── Search ────────────────────────────────────────────────────
searchInput.addEventListener('input', () => {
    const q = searchInput.value.trim();
    searchClear.style.display = q ? 'block' : 'none';

    if (!q) { clearSearch(); return; }

    searchResults.style.display = 'block';
    convList.style.display      = 'none';
    searchResults.innerHTML     = '<div class="search-spinner"><i class="bi bi-arrow-repeat"></i> Searching…</div>';

    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => doSearch(q), 280);
});

function doSearch(q) {
    fetch('search_users.php?q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                searchResults.innerHTML = `<div class="search-none">${escHtml(data.error)}</div>`;
                return;
            }
            renderSearchResults(data.users, q);
        })
        .catch(() => {
            searchResults.innerHTML = '<div class="search-none">Search failed. Try again.</div>';
        });
}

function renderSearchResults(users, q) {
    if (!users.length) {
        searchResults.innerHTML = `<div class="search-none">No employees found for "<b>${escHtml(q)}</b>"</div>`;
        return;
    }

    let html = '<div class="search-section-label">All Employees</div>';
    users.forEach(u => {
        const color   = PALETTE[u.id % PALETTE.length];
        const initial = u.name.charAt(0).toUpperCase();
        html += `
        <div class="chat-user"
             data-id="${u.id}"
             data-name="${escHtml(u.name)}"
             data-dept="${escHtml(u.dept)}"
             data-initial="${escHtml(initial)}"
             data-color="${color}">
          <div class="avatar" style="background:${color}">${escHtml(initial)}</div>
          <div class="user-meta">
            <strong>${escHtml(u.name)}</strong>
            <small>${escHtml(u.dept)}</small>
          </div>
        </div>`;
    });

    searchResults.innerHTML = html;
}

// Delegated click for search results
searchResults.addEventListener('click', e => {
    const userDiv = e.target.closest('.chat-user');
    if (!userDiv) return;
    const { id, name, dept, initial, color } = userDiv.dataset;
    openChat(id, name, dept, initial, color);
});

function clearSearch() {
    searchInput.value           = '';
    searchClear.style.display   = 'none';
    searchResults.style.display = 'none';
    searchResults.innerHTML     = '';
    convList.style.display      = 'block';
}

searchClear.addEventListener('click', clearSearch);

// ── Scroll-to-bottom button ───────────────────────────────────
messagesBox.addEventListener('scroll', () => {
    const dist = messagesBox.scrollHeight - messagesBox.scrollTop - messagesBox.clientHeight;
    scrollBtn.style.display = dist > 120 ? 'flex' : 'none';
});

function scrollToBottom() {
    messagesBox.scrollTo({ top: messagesBox.scrollHeight, behavior: 'smooth' });
}

// ── Textarea auto-resize & char count ────────────────────────
msgInput.addEventListener('input', () => {
    msgInput.style.height = 'auto';
    msgInput.style.height = Math.min(msgInput.scrollHeight, 120) + 'px';

    const len = msgInput.value.trim().length;
    const raw = msgInput.value.length;
    sendBtn.disabled = len === 0 || isSending;

    if (raw > 1600) {
        charCount.style.display = 'block';
        charCount.textContent   = raw + '/' + MAX_CHARS;
        charCount.className     = raw > 1900 ? 'danger' : 'warn';
    } else {
        charCount.style.display = 'none';
        charCount.className     = '';
    }
});

// Enter = send, Shift+Enter = newline
msgInput.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (!sendBtn.disabled) sendMessage();
    }
});

sendBtn.addEventListener('click', sendMessage);

// ── Send message ──────────────────────────────────────────────
function sendMessage() {
    const text = msgInput.value.trim();
    if (!text || !currentReceiver || isSending) return;

    isSending        = true;
    sendBtn.disabled = true;
    sendBtn.innerHTML = '<i class="bi bi-hourglass-split" style="animation:spin .7s linear infinite"></i>';

    const fd = new FormData();
    fd.append('receiver_id', currentReceiver);
    fd.append('message', text);

    const savedText = text;
    msgInput.value          = '';
    msgInput.style.height   = 'auto';
    charCount.style.display = 'none';
    charCount.className     = '';

    fetch('send_message.php', { method: 'POST', body: fd })
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => {
            if (data.error) throw new Error(data.error);

            // Force a full reload after sending so the new message gets
            // a proper MessageID and we can resume incremental polling.
            lastMsgId = 0;
            loadMessages(true);

            // Update sidebar preview
            const preview = convList.querySelector(`#lastmsg-${currentReceiver}`);
            if (preview) preview.textContent = savedText.length > 40 ? savedText.slice(0, 40) + '…' : savedText;
        })
        .catch(err => {
            msgInput.value = savedText;
            msgInput.dispatchEvent(new Event('input'));
            showToast('Failed to send message. Please try again.', 'error');
            console.error('sendMessage error:', err);
        })
        .finally(() => {
            isSending         = false;
            sendBtn.innerHTML = '<i class="bi bi-send-fill"></i>';
            sendBtn.disabled  = msgInput.value.trim().length === 0;
            msgInput.focus();
        });
}

// ── Load messages (incremental when lastMsgId > 0) ───────────
function loadMessages(doScroll = false) {
    if (!currentReceiver) return;

    // Use incremental loading after the initial fetch
    const url = 'fetch_messages.php?user_id=' + encodeURIComponent(currentReceiver)
              + (lastMsgId > 0 ? '&last_id=' + lastMsgId : '');

    fetch(url)
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => {
            if (data.error) { showToast(data.error, 'error'); return; }

            const atBottom = messagesBox.scrollHeight - messagesBox.scrollTop - messagesBox.clientHeight < 80;

            if (lastMsgId === 0) {
                // Full render on first load or after a send
                renderMessages(data.messages);
            } else if (data.messages && data.messages.length > 0) {
                // Append only new messages
                appendMessages(data.messages);
            }

            if (data.lastId && data.lastId > lastMsgId) {
                lastMsgId = data.lastId;
            }

            if (doScroll || atBottom) messagesBox.scrollTop = messagesBox.scrollHeight;
        })
        .catch(err => {
            console.error('loadMessages error:', err);
        });
}

// ── Toggle timestamp on bubble click ─────────────────────────
function toggleMsgTime(el) {
    const existing = el.querySelector('.msg-time');
    if (existing) { existing.remove(); return; }

    const time = document.createElement('div');
    time.className   = 'msg-time';
    time.textContent = el.dataset.time;
    el.appendChild(time);
}

// ── Full render (clears existing messages) ────────────────────
function renderMessages(messages) {
    const typer = document.getElementById('typingIndicator');
    while (messagesBox.firstChild) messagesBox.removeChild(messagesBox.firstChild);
    messagesBox.appendChild(typer);

    if (!messages || messages.length === 0) {
        const el = document.createElement('div');
        el.className = 'no-messages';
        el.innerHTML = '<i class="bi bi-chat-square-dots"></i>No messages yet — say hello! 👋';
        messagesBox.appendChild(el);
        return;
    }

    let lastDate   = null;
    let lastSender = null;
    let curGroup   = null;

    messages.forEach(msg => {
        const rawDate   = (msg.date || '').replace(' ', 'T');
        const dateLabel = formatDate(rawDate);

        if (dateLabel !== lastDate) {
            const div = document.createElement('div');
            div.className   = 'date-divider';
            div.textContent = dateLabel;
            messagesBox.appendChild(div);
            lastDate   = dateLabel;
            lastSender = null;
            curGroup   = null;
        }

        if (String(msg.sender) !== lastSender) {
            const row = document.createElement('div');
            row.className = 'msg-row ' + msg.type;

            if (msg.type === 'received') {
                const avatar = document.createElement('div');
                avatar.className   = 'msg-avatar';
                avatar.textContent = (msg.sender_name || '?').charAt(0).toUpperCase();
                row.appendChild(avatar);
            }

            curGroup = document.createElement('div');
            curGroup.className = 'msg-group ' + msg.type;
            row.appendChild(curGroup);
            messagesBox.appendChild(row);

            lastSender = String(msg.sender);
        }

        curGroup.appendChild(makeBubble(msg));
    });

    // Pin a timestamp under the last group
    if (curGroup && messages.length) {
        const timeEl = document.createElement('div');
        timeEl.className   = 'msg-time';
        timeEl.textContent = formatTime((messages[messages.length - 1].date || '').replace(' ', 'T'));
        curGroup.appendChild(timeEl);
    }
}

// ── Append new messages without re-rendering everything ───────
function appendMessages(messages) {
    let lastSender = null;
    let curGroup   = null;

    // Try to continue the last existing group if the sender matches
    const lastRow = messagesBox.querySelector('.msg-row:last-of-type');
    if (lastRow) {
        curGroup   = lastRow.querySelector('.msg-group');
        lastSender = String(messages[0]?.sender) === lastRow.className.includes('sent')
                     ? String(CURRENT_UID) : null;
    }

    messages.forEach(msg => {
        if (String(msg.sender) !== lastSender) {
            const row = document.createElement('div');
            row.className = 'msg-row ' + msg.type;

            if (msg.type === 'received') {
                const avatar = document.createElement('div');
                avatar.className   = 'msg-avatar';
                avatar.textContent = (msg.sender_name || '?').charAt(0).toUpperCase();
                row.appendChild(avatar);
            }

            curGroup = document.createElement('div');
            curGroup.className = 'msg-group ' + msg.type;
            row.appendChild(curGroup);
            messagesBox.appendChild(row);
            lastSender = String(msg.sender);
        }

        curGroup.appendChild(makeBubble(msg));
    });
}

// ── Build a single message bubble ────────────────────────────
function makeBubble(msg) {
    const rawDate = (msg.date || '').replace(' ', 'T');
    const bubble  = document.createElement('div');
    bubble.className   = 'msg';
    bubble.textContent = msg.text;
    bubble.dataset.time = formatTime(rawDate);
    bubble.addEventListener('click', function () { toggleMsgTime(this); });
    return bubble;
}

// ── Date/time helpers ─────────────────────────────────────────
function parseDate(dateStr) {
    if (!dateStr) return new Date();
    const s = String(dateStr).replace(' ', 'T').replace(/\.\d+$/, '');
    const d = new Date(s);
    return isNaN(d.getTime()) ? new Date() : d;
}

function formatDate(dateStr) {
    const d         = parseDate(dateStr);
    const today     = new Date();
    const yesterday = new Date(); yesterday.setDate(today.getDate() - 1);
    if (d.toDateString() === today.toDateString())     return 'Today';
    if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
    return d.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
}

function formatTime(dateStr) {
    return parseDate(dateStr).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
}

// ── Smart polling (incremental) ───────────────────────────────
function startPolling() {
    clearInterval(pollTimer);
    pollTimer = setInterval(() => {
        if (!document.hidden && currentReceiver) {
            loadMessages(false);
            updateUnread();
        }
    }, 3000);
}

startPolling();

document.addEventListener('visibilitychange', () => {
    if (!document.hidden && currentReceiver) {
        loadMessages(false);
        updateUnread();
    }
});

// ── Unread badge refresh ──────────────────────────────────────
function updateUnread() {
    fetch('get_unread_counts.php')
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => {
            document.querySelectorAll('.chat-user[data-id]').forEach(user => {
                const id    = user.dataset.id;
                const badge = document.getElementById('badge-' + id);
                if (!badge) return;

                const count = data[id] || 0;
                if (count > 0) {
                    badge.textContent  = count;
                    badge.style.display = 'flex';
                } else {
                    badge.textContent  = '';
                    badge.style.display = 'none';
                }
            });
        })
        .catch(err => console.error('updateUnread error:', err));
}
</script>

</body>
</html>
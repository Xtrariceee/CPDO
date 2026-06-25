<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/message_templates.php';
$user = require_role([ROLE_LANDLORD]);
verify_csrf();

global $config;
$baseUrl = rtrim($config['app']['base_url'], '/');

// Fetch landlord's properties for new-thread composer
$propStmt = db()->prepare('SELECT id, title FROM properties WHERE landlord_id = ? AND status = "ACTIVE" ORDER BY title');
$propStmt->execute([(int)$user['id']]);
$myProperties = $propStmt->fetchAll();

// Pre-select thread if coming from a link
$openThreadId = (int)($_GET['thread'] ?? 0);

// Unread totals for badge
$unreadStmt = db()->prepare('SELECT COALESCE(SUM(unread_landlord),0) FROM message_threads WHERE landlord_id = ?');
$unreadStmt->execute([(int)$user['id']]);
$totalUnread = (int)$unreadStmt->fetchColumn();

$templates = get_message_templates_grouped();

require __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Messaging Hub Styles ─────────────────────────────────────────────── */
.msg-hub { display:flex; height:calc(100vh - 80px); gap:0; overflow:hidden; border-radius:16px; border:1px solid #f0dfad; background:#fff; box-shadow:0 4px 24px rgba(36,27,11,.07); }

/* Thread List Pane */
.thread-pane { width:340px; min-width:300px; display:flex; flex-direction:column; border-right:1px solid #f0dfad; background:#faf8f5; flex-shrink:0; }
.thread-pane-header { padding:16px; border-bottom:1px solid #f0dfad; background:#241b0b; border-radius:16px 0 0 0; }
.thread-pane-header h1 { font-size:1rem; font-weight:800; color:#f6cf4a; margin:0 0 10px; }
.thread-filter-tabs { display:flex; gap:4px; flex-wrap:wrap; }
.filter-tab { padding:4px 10px; border-radius:20px; font-size:.72rem; font-weight:700; cursor:pointer; border:1px solid rgba(246,207,74,.3); color:rgba(246,207,74,.7); background:transparent; transition:all .15s; }
.filter-tab.active, .filter-tab:hover { background:#f6cf4a; color:#241b0b; border-color:#f6cf4a; }
.thread-search { padding:10px; border-bottom:1px solid #f0dfad; }
.thread-search input { width:100%; padding:8px 12px; border:1px solid #d4c89a; border-radius:8px; font-size:.85rem; background:#fff; }
.thread-search input:focus { outline:none; border-color:#f6cf4a; box-shadow:0 0 0 3px rgba(246,207,74,.2); }
.thread-list { flex:1; overflow-y:auto; }
.thread-item { padding:12px 16px; border-bottom:1px solid #f0dfad; cursor:pointer; transition:background .15s; position:relative; }
.thread-item:hover { background:#fff; }
.thread-item.active { background:linear-gradient(135deg,rgba(197,144,0,.1),rgba(246,207,74,.04)); border-left:3px solid #f6cf4a; }
.thread-item-name { font-size:.85rem; font-weight:800; color:#241b0b; }
.thread-item-sub { font-size:.75rem; color:#a89562; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.thread-item-preview { font-size:.78rem; color:#76684b; margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.thread-item-meta { display:flex; align-items:center; justify-content:space-between; margin-top:4px; }
.thread-item-time { font-size:.68rem; color:#a89562; }
.unread-dot { background:#f6cf4a; color:#241b0b; font-size:.65rem; font-weight:800; padding:2px 7px; border-radius:999px; }
.ctx-pill { font-size:.62rem; font-weight:700; padding:2px 7px; border-radius:999px; text-transform:uppercase; }
.ctx-inquiry    { background:#dbeafe; color:#1e40af; }
.ctx-lease      { background:#d1fae5; color:#065f46; }
.ctx-maintenance{ background:#fef3c7; color:#92400e; }
.ctx-broadcast  { background:#fee2e2; color:#991b1b; }
.ctx-general    { background:#f3f4f6; color:#374151; }

/* Conversation Pane */
.convo-pane { flex:1; display:flex; flex-direction:column; min-width:0; }
.convo-header { padding:14px 20px; border-bottom:1px solid #f0dfad; background:#fff; display:flex; align-items:center; justify-content:space-between; }
.convo-header-info h2 { font-size:1rem; font-weight:800; color:#241b0b; margin:0; }
.convo-header-info p  { font-size:.78rem; color:#a89562; margin:0; }
.convo-header-actions { display:flex; gap:8px; }
.convo-messages { flex:1; overflow-y:auto; padding:20px; display:flex; flex-direction:column; gap:12px; background:#fcfbfa; }
.msg-bubble-wrap { display:flex; align-items:flex-end; gap:8px; }
.msg-bubble-wrap.mine { flex-direction:row-reverse; }
.msg-avatar { width:30px; height:30px; border-radius:50%; background:#f0dfad; display:flex; align-items:center; justify-content:center; font-size:.7rem; font-weight:800; color:#241b0b; flex-shrink:0; }
.msg-bubble { max-width:68%; padding:10px 14px; border-radius:16px; font-size:.88rem; line-height:1.55; word-break:break-word; }
.msg-bubble.theirs { background:#fff; border:1px solid #f0dfad; color:#241b0b; border-radius:4px 16px 16px 16px; }
.msg-bubble.mine { background:linear-gradient(135deg,#f6cf4a,#e8b800); color:#241b0b; border-radius:16px 4px 16px 16px; }
.msg-meta { font-size:.65rem; color:#a89562; margin-top:4px; display:flex; align-items:center; gap:4px; }
.msg-meta.mine { justify-content:flex-end; }
.read-tick { font-size:.75rem; }
.read-tick.read { color:#c59000; }
.msg-img-attach { max-width:220px; border-radius:10px; margin-top:6px; cursor:pointer; border:1px solid #f0dfad; }
.msg-pdf-attach { display:inline-flex; align-items:center; gap:8px; background:rgba(36,27,11,.06); border:1px solid #d4c89a; border-radius:8px; padding:8px 12px; margin-top:6px; font-size:.8rem; font-weight:700; color:#241b0b; text-decoration:none; }
.msg-pdf-attach:hover { background:rgba(36,27,11,.12); }
.system-msg { text-align:center; font-size:.75rem; color:#a89562; padding:4px 0; }

/* Compose Bar */
.compose-bar { border-top:1px solid #f0dfad; background:#fff; padding:12px 16px; }
.compose-textarea { width:100%; padding:10px 14px; border:1px solid #d4c89a; border-radius:10px; font-size:.9rem; resize:none; min-height:72px; max-height:160px; background:#fffdf5; transition:border-color .15s; }
.compose-textarea:focus { outline:none; border-color:#f6cf4a; box-shadow:0 0 0 3px rgba(246,207,74,.2); }
.compose-actions { display:flex; align-items:center; gap:8px; margin-top:8px; flex-wrap:wrap; }
.btn-icon { background:none; border:1px solid #d4c89a; border-radius:8px; padding:7px 11px; cursor:pointer; font-size:1rem; color:#241b0b; transition:all .15s; }
.btn-icon:hover { background:#f6cf4a; border-color:#f6cf4a; }
.btn-send { margin-left:auto; background:#241b0b; color:#f6cf4a; border:none; border-radius:10px; padding:9px 22px; font-size:.88rem; font-weight:800; cursor:pointer; transition:all .15s; display:flex; align-items:center; gap:6px; }
.btn-send:hover { background:#3a2c12; }
.btn-send:disabled { opacity:.5; cursor:not-allowed; }
.attach-preview { display:flex; align-items:center; gap:8px; background:#fffdf5; border:1px solid #f0dfad; border-radius:8px; padding:6px 10px; font-size:.8rem; color:#241b0b; margin-top:6px; }
.attach-preview button { background:none; border:none; cursor:pointer; color:#ef4444; font-weight:800; padding:0; font-size:.9rem; }

/* Template Picker */
.template-drawer { position:absolute; bottom:100%; left:0; right:0; background:#fff; border:1px solid #f0dfad; border-radius:12px 12px 0 0; box-shadow:0 -8px 30px rgba(36,27,11,.1); max-height:380px; overflow-y:auto; z-index:200; display:none; }
.template-drawer.open { display:block; }
.template-drawer-header { padding:12px 16px; border-bottom:1px solid #f0dfad; font-weight:800; font-size:.88rem; color:#241b0b; display:flex; justify-content:space-between; align-items:center; }
.template-category { padding:8px 16px; font-size:.72rem; font-weight:800; color:#a89562; text-transform:uppercase; letter-spacing:.05em; background:#faf8f5; }
.template-item { padding:10px 16px; cursor:pointer; transition:background .12s; border-bottom:1px solid #faf8f5; }
.template-item:hover { background:#fffdf5; }
.template-item-label { font-size:.85rem; font-weight:700; color:#241b0b; }
.template-item-preview { font-size:.75rem; color:#a89562; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* Empty state */
.inbox-empty { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#a89562; text-align:center; padding:40px; gap:12px; }
.inbox-empty svg { opacity:.3; }
.inbox-empty h3 { font-size:1rem; font-weight:800; color:#241b0b; margin:0; }
.inbox-empty p  { font-size:.85rem; margin:0; }

/* New Thread Modal */
.modal-overlay { position:fixed; inset:0; background:rgba(36,27,11,.5); backdrop-filter:blur(4px); z-index:2000; display:none; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-card { background:#fff; border-radius:16px; padding:28px; width:100%; max-width:520px; box-shadow:0 20px 60px rgba(36,27,11,.2); }
.modal-card h2 { font-size:1.1rem; font-weight:800; color:#241b0b; margin:0 0 20px; }
.modal-label { font-size:.82rem; font-weight:700; color:#241b0b; margin-bottom:5px; display:block; }
.modal-input, .modal-select, .modal-textarea { width:100%; padding:9px 12px; border:1px solid #d4c89a; border-radius:8px; font-size:.88rem; margin-bottom:12px; }
.modal-input:focus, .modal-select:focus, .modal-textarea:focus { outline:none; border-color:#f6cf4a; box-shadow:0 0 0 3px rgba(246,207,74,.2); }

@media (max-width:767px) {
    .msg-hub { flex-direction:column; height:auto; border-radius:0; }
    .thread-pane { width:100%; min-width:0; border-right:none; border-bottom:1px solid #f0dfad; max-height:240px; }
    .convo-pane { min-height:400px; }
}
</style>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0">💬 Unified Inbox</h1>
        <p class="text-secondary small mb-0">All tenant communications in one place</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= e($baseUrl) ?>/landlord/broadcast.php" class="btn btn-sm fw-bold" style="background:#ef4444;color:#fff;border-radius:8px;">
            📢 Mass Broadcast
        </a>
        <button class="btn btn-sm fw-bold" onclick="openNewThread()" style="background:#241b0b;color:#f6cf4a;border-radius:8px;">
            ✉️ New Message
        </button>
    </div>
</div>

<div class="msg-hub" id="msgHub">

    <!-- ── Thread List Pane ─────────────────────────────────────────── -->
    <div class="thread-pane">
        <div class="thread-pane-header">
            <h1>Inbox <?php if ($totalUnread > 0): ?><span style="background:#ef4444;color:#fff;font-size:.65rem;padding:2px 8px;border-radius:999px;margin-left:6px;"><?= $totalUnread ?></span><?php endif; ?></h1>
            <div class="thread-filter-tabs">
                <button class="filter-tab active" data-filter="all">All</button>
                <button class="filter-tab" data-filter="inquiry">Inquiries</button>
                <button class="filter-tab" data-filter="lease">Lease</button>
                <button class="filter-tab" data-filter="maintenance">Maintenance</button>
                <button class="filter-tab" data-filter="broadcast">Broadcasts</button>
            </div>
        </div>
        <div class="thread-search">
            <input type="text" id="threadSearchInput" placeholder="Search conversations…">
        </div>
        <div class="thread-list" id="threadList">
            <div style="padding:24px;text-align:center;color:#a89562;font-size:.82rem;">Loading…</div>
        </div>
    </div>

    <!-- ── Conversation Pane ────────────────────────────────────────── -->
    <div class="convo-pane" id="convoPaneWrap">
        <div class="inbox-empty" id="emptyState">
            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="none" stroke="#241b0b" stroke-width="1.5" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <h3>Select a conversation</h3>
            <p>Choose a thread on the left, or start a new message with any tenant.</p>
        </div>

        <div id="convoPane" style="display:none;flex-direction:column;height:100%;">
            <div class="convo-header">
                <div class="convo-header-info">
                    <h2 id="convoName">—</h2>
                    <p id="convoSub">—</p>
                </div>
                <div class="convo-header-actions">
                    <span id="convoPropTag" class="ctx-pill ctx-general" style="display:none;"></span>
                    <button class="btn-icon" onclick="closeThread()" title="Close">✕</button>
                </div>
            </div>

            <div class="convo-messages" id="convoMessages"></div>

            <!-- Compose -->
            <div class="compose-bar" style="position:relative;">
                <div id="templateDrawer" class="template-drawer">
                    <div class="template-drawer-header">
                        Quick-Reply Templates
                        <button onclick="closeTemplates()" style="background:none;border:none;cursor:pointer;font-size:1rem;">✕</button>
                    </div>
                    <?php foreach ($templates as $cat => $tpls): ?>
                        <div class="template-category"><?= e($cat) ?></div>
                        <?php foreach ($tpls as $tpl): ?>
                            <div class="template-item" onclick="useTemplate(<?= json_encode($tpl['body']) ?>)">
                                <div class="template-item-label"><?= e($tpl['icon']) ?> <?= e($tpl['label']) ?></div>
                                <div class="template-item-preview"><?= e(mb_substr($tpl['body'], 0, 80)) ?>…</div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>

                <div id="attachPreviewBar" class="attach-preview" style="display:none;">
                    <span>📎</span>
                    <span id="attachPreviewName" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
                    <button onclick="clearAttach()" title="Remove">✕</button>
                </div>
                <textarea class="compose-textarea" id="composeArea" placeholder="Type a message…" rows="3"></textarea>
                <div class="compose-actions">
                    <button class="btn-icon" onclick="toggleTemplates()" title="Quick-Reply Templates">⚡ Templates</button>
                    <button class="btn-icon" onclick="document.getElementById('fileAttach').click()" title="Attach file">📎</button>
                    <input type="file" id="fileAttach" class="d-none" accept="image/*,.pdf" onchange="handleAttach(this)">
                    <button class="btn-send" id="sendBtn" onclick="sendMessage()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M15.964.686a.5.5 0 0 0-.65-.65L.767 5.855H.766l-.452.18a.5.5 0 0 0-.082.887l.41.26.001.002 4.995 3.178 3.178 4.995.002.002.26.41a.5.5 0 0 0 .886-.083l6-15Zm-1.833 1.89L6.637 10.07l-.215-.338a.5.5 0 0 0-.154-.154l-.338-.215 7.494-7.494 1.178-.471-.47 1.178Z"/></svg>
                        Send
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Thread Modal -->
<div class="modal-overlay" id="newThreadModal">
    <div class="modal-card">
        <h2>✉️ New Message</h2>
        <label class="modal-label">Tenant (search by name/email)</label>
        <input type="text" class="modal-input" id="tenantSearch" placeholder="Search tenant…" oninput="searchTenants(this.value)">
        <div id="tenantResults" style="margin-bottom:12px;"></div>
        <input type="hidden" id="selectedTenantId">

        <label class="modal-label">Property (optional)</label>
        <select class="modal-select" id="newThreadProp">
            <option value="">— No specific property —</option>
            <?php foreach ($myProperties as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= e($p['title']) ?></option>
            <?php endforeach; ?>
        </select>

        <label class="modal-label">Category</label>
        <select class="modal-select" id="newThreadCtx">
            <option value="general">General</option>
            <option value="inquiry">Inquiry</option>
            <option value="lease">Lease</option>
            <option value="maintenance">Maintenance</option>
        </select>

        <label class="modal-label">Subject</label>
        <input type="text" class="modal-input" id="newThreadSubject" placeholder="e.g. Lease renewal discussion">

        <label class="modal-label">Message</label>
        <textarea class="modal-textarea" id="newThreadBody" rows="4" placeholder="Type your message…"></textarea>

        <div class="d-flex gap-2 justify-content-end mt-2">
            <button class="btn btn-sm btn-outline-secondary" onclick="closeNewThread()">Cancel</button>
            <button class="btn btn-sm fw-bold" onclick="submitNewThread()" style="background:#241b0b;color:#f6cf4a;border-radius:8px;">Send Message</button>
        </div>
    </div>
</div>

<script>
const BASE_URL     = <?= json_encode($baseUrl) ?>;
const CSRF_TOKEN   = <?= json_encode(csrf_token()) ?>;
const MY_ID        = <?= (int)$user['id'] ?>;
const MY_ROLE      = <?= json_encode($user['role']) ?>;

let currentThreadId   = <?= $openThreadId ?>;
let currentLastMsgId  = 0;
let pollTimer         = null;
let currentFilter     = 'all';
let pendingFile       = null;
let currentThreadData = null;

// ── Thread List ───────────────────────────────────────────────────────────────
async function loadThreads(filter = 'all') {
    const res  = await fetch(`${BASE_URL}/api/messages_threads.php?filter=${filter}`);
    const data = await res.json();
    renderThreadList(data.threads);
    // Update global badge
    document.querySelectorAll('.msg-total-unread').forEach(el => {
        el.textContent = data.total_unread > 0 ? data.total_unread : '';
        el.style.display = data.total_unread > 0 ? '' : 'none';
    });
}

function relativeTime(dateStr) {
    const d = new Date(dateStr);
    const now = new Date();
    const diff = Math.floor((now - d) / 1000);
    if (diff < 60)     return 'just now';
    if (diff < 3600)   return Math.floor(diff/60) + 'm ago';
    if (diff < 86400)  return Math.floor(diff/3600) + 'h ago';
    return d.toLocaleDateString('en-PH', {month:'short', day:'numeric'});
}

function ctxPill(ctx) {
    const labels = {inquiry:'Inquiry',lease:'Lease',maintenance:'Maintenance',broadcast:'Broadcast',general:'General'};
    return `<span class="ctx-pill ctx-${ctx}">${labels[ctx] || ctx}</span>`;
}

function renderThreadList(threads) {
    const el = document.getElementById('threadList');
    if (!threads.length) {
        el.innerHTML = `<div style="padding:28px;text-align:center;color:#a89562;font-size:.82rem;">No conversations yet.</div>`;
        return;
    }
    el.innerHTML = threads.map(t => `
        <div class="thread-item ${t.id === currentThreadId ? 'active' : ''}" onclick="openThread(${t.id})" data-thread-id="${t.id}" data-search="${(t.other_name + ' ' + t.subject).toLowerCase()}">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px;">
                <span class="thread-item-name">${escHtml(t.other_name)}</span>
                ${ctxPill(t.context_type)}
            </div>
            <div class="thread-item-sub">${escHtml(t.subject)}${t.property_title ? ' · ' + escHtml(t.property_title) : ''}</div>
            <div class="thread-item-preview">${escHtml(t.last_preview || '—')}</div>
            <div class="thread-item-meta">
                <span class="thread-item-time">${relativeTime(t.last_msg_at)}</span>
                ${t.my_unread > 0 ? `<span class="unread-dot">${t.my_unread}</span>` : ''}
            </div>
        </div>
    `).join('');
}

// ── Open Thread ───────────────────────────────────────────────────────────────
async function openThread(threadId) {
    clearInterval(pollTimer);
    currentThreadId  = threadId;
    currentLastMsgId = 0;
    currentThreadData = null;

    // Mark active
    document.querySelectorAll('.thread-item').forEach(el => {
        el.classList.toggle('active', parseInt(el.dataset.threadId) === threadId);
    });

    document.getElementById('emptyState').style.display = 'none';
    const cp = document.getElementById('convoPane');
    cp.style.display = 'flex';
    document.getElementById('convoMessages').innerHTML = `<div style="text-align:center;color:#a89562;font-size:.82rem;padding:20px;">Loading…</div>`;

    // Fetch all messages
    const res  = await fetch(`${BASE_URL}/api/messages_poll.php?thread_id=${threadId}&after_id=0`);
    const data = await res.json();
    renderMessages(data.messages, true);
    if (data.messages.length) {
        currentLastMsgId = data.messages[data.messages.length - 1].id;
    }

    // Fetch thread header info via threads list
    const tRes = await fetch(`${BASE_URL}/api/messages_threads.php?filter=all`);
    const tData = await tRes.json();
    const thread = tData.threads.find(t => t.id === threadId);
    if (thread) {
        currentThreadData = thread;
        document.getElementById('convoName').textContent = thread.other_name;
        document.getElementById('convoSub').textContent  = thread.subject + (thread.property_title ? ' · ' + thread.property_title : '');
        const pillEl = document.getElementById('convoPropTag');
        pillEl.textContent  = thread.context_type.charAt(0).toUpperCase() + thread.context_type.slice(1);
        pillEl.className    = `ctx-pill ctx-${thread.context_type}`;
        pillEl.style.display = '';
    }

    // Start polling
    pollTimer = setInterval(pollNewMessages, 4000);
    // Reload thread list to clear badge
    loadThreads(currentFilter);
}

async function pollNewMessages() {
    if (!currentThreadId) return;
    const res  = await fetch(`${BASE_URL}/api/messages_poll.php?thread_id=${currentThreadId}&after_id=${currentLastMsgId}`);
    const data = await res.json();
    if (data.messages && data.messages.length) {
        renderMessages(data.messages, false);
        currentLastMsgId = data.messages[data.messages.length - 1].id;
        loadThreads(currentFilter);
    }
}

function renderMessages(messages, replace) {
    const el = document.getElementById('convoMessages');
    if (replace) el.innerHTML = '';

    messages.forEach(msg => {
        const isMine = msg.sender_id === MY_ID;
        const initials = msg.sender_name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0,2);
        const tick = isMine
            ? `<span class="read-tick ${msg.is_read_by_recipient ? 'read' : ''}" title="${msg.is_read_by_recipient ? 'Read' : 'Delivered'}">${msg.is_read_by_recipient ? '✓✓' : '✓'}</span>`
            : '';

        let content = '';
        if (msg.body) content += `<div>${escHtml(msg.body).replace(/\n/g,'<br>')}</div>`;
        if (msg.message_type === 'image' && msg.attachment_url) {
            content += `<img class="msg-img-attach" src="${escHtml(msg.attachment_url)}" alt="${escHtml(msg.attachment_name||'Image')}" onclick="window.open(this.src)">`;
        } else if (msg.message_type === 'pdf' && msg.attachment_url) {
            content += `<a class="msg-pdf-attach" href="${escHtml(msg.attachment_url)}" target="_blank">📄 ${escHtml(msg.attachment_name||'PDF Document')}</a>`;
        }
        if (msg.message_type === 'system') {
            el.insertAdjacentHTML('beforeend', `<div class="system-msg">${escHtml(msg.body)}</div>`);
            return;
        }

        el.insertAdjacentHTML('beforeend', `
            <div class="msg-bubble-wrap ${isMine ? 'mine' : ''}">
                ${!isMine ? `<div class="msg-avatar">${initials}</div>` : ''}
                <div>
                    <div class="msg-bubble ${isMine ? 'mine' : 'theirs'}">${content}</div>
                    <div class="msg-meta ${isMine ? 'mine' : ''}">
                        ${isMine ? '' : `<span>${escHtml(msg.sender_name)}</span> ·`}
                        <span>${new Date(msg.created_at).toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'})}</span>
                        ${tick}
                    </div>
                </div>
                ${isMine ? `<div class="msg-avatar" style="background:#241b0b;color:#f6cf4a;">${initials}</div>` : ''}
            </div>
        `);
    });

    // Scroll to bottom
    el.scrollTop = el.scrollHeight;
}

// ── Send Message ──────────────────────────────────────────────────────────────
async function sendMessage() {
    const body = document.getElementById('composeArea').value.trim();
    if (!body && !pendingFile) return;
    if (!currentThreadId) return;

    const btn = document.getElementById('sendBtn');
    btn.disabled = true;

    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('thread_id',  currentThreadId);
    fd.append('body',       body);
    if (pendingFile) fd.append('attachment', pendingFile);

    const res  = await fetch(`${BASE_URL}/api/messages_send.php`, { method:'POST', body: fd });
    const data = await res.json();

    if (data.success) {
        document.getElementById('composeArea').value = '';
        clearAttach();
        await pollNewMessages();
    } else {
        alert(data.error || 'Failed to send message.');
    }
    btn.disabled = false;
}

// ── Attachment ────────────────────────────────────────────────────────────────
function handleAttach(input) {
    if (!input.files[0]) return;
    pendingFile = input.files[0];
    document.getElementById('attachPreviewName').textContent = pendingFile.name;
    document.getElementById('attachPreviewBar').style.display = 'flex';
}
function clearAttach() {
    pendingFile = null;
    document.getElementById('attachPreviewBar').style.display = 'none';
    document.getElementById('attachPreviewName').textContent = '';
    document.getElementById('fileAttach').value = '';
}

// ── Templates ─────────────────────────────────────────────────────────────────
function toggleTemplates() {
    document.getElementById('templateDrawer').classList.toggle('open');
}
function closeTemplates() {
    document.getElementById('templateDrawer').classList.remove('open');
}
function useTemplate(body) {
    document.getElementById('composeArea').value = body;
    closeTemplates();
    document.getElementById('composeArea').focus();
}

// ── Close Thread ──────────────────────────────────────────────────────────────
function closeThread() {
    clearInterval(pollTimer);
    currentThreadId = 0;
    document.getElementById('convoPane').style.display = 'none';
    document.getElementById('emptyState').style.display = '';
    document.querySelectorAll('.thread-item').forEach(el => el.classList.remove('active'));
}

// ── Filter Tabs ───────────────────────────────────────────────────────────────
document.querySelectorAll('.filter-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.filter-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentFilter = btn.dataset.filter;
        loadThreads(currentFilter);
    });
});

// ── Search ────────────────────────────────────────────────────────────────────
document.getElementById('threadSearchInput').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.thread-item').forEach(el => {
        el.style.display = el.dataset.search.includes(q) ? '' : 'none';
    });
});

// ── New Thread Modal ──────────────────────────────────────────────────────────
function openNewThread() {
    document.getElementById('newThreadModal').classList.add('open');
}
function closeNewThread() {
    document.getElementById('newThreadModal').classList.remove('open');
}
async function searchTenants(q) {
    if (q.length < 2) { document.getElementById('tenantResults').innerHTML = ''; return; }
    const res  = await fetch(`${BASE_URL}/api/messages_threads.php?search_tenant=${encodeURIComponent(q)}`);
    // Fallback: search inline from existing threads
    const tRes = await fetch(`${BASE_URL}/api/messages_threads.php?filter=all`);
    const tData = await tRes.json();
    const matches = [];
    const seen = new Set();
    tData.threads.forEach(t => {
        if (!seen.has(t.other_role === 'tenant' ? t.other_name : '') && t.other_name.toLowerCase().includes(q.toLowerCase())) {
            seen.add(t.other_name);
            matches.push(t);
        }
    });
    const html = matches.map(t => `
        <div onclick="selectTenant(${JSON.stringify(t.other_name)}, 0)"
             style="padding:8px 12px;cursor:pointer;border:1px solid #f0dfad;border-radius:8px;margin-bottom:6px;background:#fffdf5;font-size:.85rem;font-weight:700;">
            👤 ${escHtml(t.other_name)}
        </div>
    `).join('') || '<div style="color:#a89562;font-size:.8rem;">No existing tenants found. Enter tenant ID manually.</div>';
    document.getElementById('tenantResults').innerHTML = html;
}
async function submitNewThread() {
    const body    = document.getElementById('newThreadBody').value.trim();
    const subject = document.getElementById('newThreadSubject').value.trim() || 'New Message';
    const prop    = document.getElementById('newThreadProp').value;
    const ctx     = document.getElementById('newThreadCtx').value;
    const tenId   = document.getElementById('selectedTenantId').value;

    if (!tenId || !body) { alert('Please select a tenant and write a message.'); return; }

    const fd = new FormData();
    fd.append('csrf_token',  CSRF_TOKEN);
    fd.append('tenant_id',   tenId);
    fd.append('property_id', prop);
    fd.append('context_type',ctx);
    fd.append('subject',     subject);
    fd.append('body',        body);

    const res  = await fetch(`${BASE_URL}/api/messages_send.php`, { method:'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        closeNewThread();
        loadThreads('all');
        openThread(data.thread_id);
    } else {
        alert(data.error || 'Failed.');
    }
}
function selectTenant(name, id) {
    document.getElementById('tenantSearch').value = name;
    document.getElementById('selectedTenantId').value = id;
    document.getElementById('tenantResults').innerHTML = '';
}

// ── Keyboard shortcuts ─────────────────────────────────────────────────────────
document.getElementById('composeArea').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});

// ── Escape to close modal ──────────────────────────────────────────────────────
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeNewThread();
        closeTemplates();
    }
});
document.getElementById('newThreadModal').addEventListener('click', function(e) {
    if (e.target === this) closeNewThread();
});

function escHtml(str) {
    if (!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Init ──────────────────────────────────────────────────────────────────────
loadThreads('all');
if (currentThreadId) {
    setTimeout(() => openThread(currentThreadId), 300);
}
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>

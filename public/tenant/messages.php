<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/message_templates.php';
$user = require_role([ROLE_TENANT]);
verify_csrf();

global $config;
$baseUrl = rtrim($config['app']['base_url'], '/');

// Pre-select thread
$openThreadId = (int)($_GET['thread'] ?? 0);

// Unread totals
$unreadStmt = db()->prepare('SELECT COALESCE(SUM(unread_tenant),0) FROM message_threads WHERE tenant_id = ?');
$unreadStmt->execute([(int)$user['id']]);
$totalUnread = (int)$unreadStmt->fetchColumn();

$templates = get_message_templates_grouped();

require __DIR__ . '/../partials/header.php';
?>
<style>
/* Messaging Hub — reuses landlord stylesheet structure */
.msg-hub { display:flex; height:calc(100vh - 80px); gap:0; overflow:hidden; border-radius:16px; border:1px solid #f0dfad; background:#fff; box-shadow:0 4px 24px rgba(36,27,11,.07); }
.thread-pane { width:320px; min-width:280px; display:flex; flex-direction:column; border-right:1px solid #f0dfad; background:#faf8f5; flex-shrink:0; }
.thread-pane-header { padding:16px; border-bottom:1px solid #f0dfad; background:#241b0b; border-radius:16px 0 0 0; }
.thread-pane-header h1 { font-size:1rem; font-weight:800; color:#f6cf4a; margin:0; }
.thread-search { padding:10px; border-bottom:1px solid #f0dfad; }
.thread-search input { width:100%; padding:8px 12px; border:1px solid #d4c89a; border-radius:8px; font-size:.85rem; background:#fff; }
.thread-search input:focus { outline:none; border-color:#f6cf4a; }
.thread-list { flex:1; overflow-y:auto; }
.thread-item { padding:12px 16px; border-bottom:1px solid #f0dfad; cursor:pointer; transition:background .15s; }
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
.convo-pane { flex:1; display:flex; flex-direction:column; min-width:0; }
.convo-header { padding:14px 20px; border-bottom:1px solid #f0dfad; background:#fff; display:flex; align-items:center; justify-content:space-between; }
.convo-header-info h2 { font-size:1rem; font-weight:800; color:#241b0b; margin:0; }
.convo-header-info p  { font-size:.78rem; color:#a89562; margin:0; }
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
.compose-bar { border-top:1px solid #f0dfad; background:#fff; padding:12px 16px; position:relative; }
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
.template-drawer { position:absolute; bottom:100%; left:0; right:0; background:#fff; border:1px solid #f0dfad; border-radius:12px 12px 0 0; box-shadow:0 -8px 30px rgba(36,27,11,.1); max-height:340px; overflow-y:auto; z-index:200; display:none; }
.template-drawer.open { display:block; }
.template-drawer-header { padding:12px 16px; border-bottom:1px solid #f0dfad; font-weight:800; font-size:.88rem; color:#241b0b; display:flex; justify-content:space-between; align-items:center; }
.template-category { padding:8px 16px; font-size:.72rem; font-weight:800; color:#a89562; text-transform:uppercase; letter-spacing:.05em; background:#faf8f5; }
.template-item { padding:10px 16px; cursor:pointer; transition:background .12s; border-bottom:1px solid #faf8f5; }
.template-item:hover { background:#fffdf5; }
.template-item-label { font-size:.85rem; font-weight:700; color:#241b0b; }
.template-item-preview { font-size:.75rem; color:#a89562; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.inbox-empty { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#a89562; text-align:center; padding:40px; gap:12px; }
.inbox-empty h3 { font-size:1rem; font-weight:800; color:#241b0b; margin:0; }
.inbox-empty p  { font-size:.85rem; margin:0; }
.broadcast-banner { background:linear-gradient(135deg,#fef2f2,#fee2e2); border:1px solid #fecaca; border-radius:10px; padding:10px 14px; margin-bottom:8px; font-size:.82rem; color:#991b1b; font-weight:700; display:flex; align-items:center; gap:8px; }
@media (max-width:767px) {
    .msg-hub { flex-direction:column; height:auto; border-radius:0; }
    .thread-pane { width:100%; min-width:0; border-right:none; border-bottom:1px solid #f0dfad; max-height:220px; }
    .convo-pane { min-height:400px; }
}
</style>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0">💬 Messages</h1>
        <p class="text-secondary small mb-0">Chat with your landlord and view notices</p>
    </div>
    <?php if ($totalUnread > 0): ?>
        <span class="badge rounded-pill" style="background:#ef4444;color:#fff;font-size:.8rem;padding:6px 14px;"><?= $totalUnread ?> unread</span>
    <?php endif; ?>
</div>

<div class="msg-hub" id="msgHub">
    <!-- Thread List -->
    <div class="thread-pane">
        <div class="thread-pane-header">
            <h1>Messages <?php if ($totalUnread > 0): ?><span style="background:#ef4444;color:#fff;font-size:.65rem;padding:2px 8px;border-radius:999px;margin-left:6px;"><?= $totalUnread ?></span><?php endif; ?></h1>
        </div>
        <div class="thread-search">
            <input type="text" id="threadSearchInput" placeholder="Search…">
        </div>
        <div class="thread-list" id="threadList">
            <div style="padding:24px;text-align:center;color:#a89562;font-size:.82rem;">Loading…</div>
        </div>
    </div>

    <!-- Conversation Pane -->
    <div class="convo-pane">
        <div class="inbox-empty" id="emptyState">
            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="none" stroke="#241b0b" stroke-width="1.5" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <h3>No conversation selected</h3>
            <p>Select a message from the list, or wait for your landlord to reach out.</p>
        </div>

        <div id="convoPane" style="display:none;flex-direction:column;height:100%;">
            <div class="convo-header">
                <div class="convo-header-info">
                    <h2 id="convoName">—</h2>
                    <p id="convoSub">—</p>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <span id="convoPropTag" class="ctx-pill ctx-general" style="display:none;"></span>
                </div>
            </div>

            <div class="convo-messages" id="convoMessages"></div>

            <div class="compose-bar">
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
                    <button onclick="clearAttach()">✕</button>
                </div>
                <div id="broadcastBanner" class="broadcast-banner" style="display:none;">
                    📢 This is a broadcast from your landlord. Replies go directly to them.
                </div>
                <textarea class="compose-textarea" id="composeArea" placeholder="Type a message… (Enter to send, Shift+Enter for newline)" rows="3"></textarea>
                <div class="compose-actions">
                    <button class="btn-icon" onclick="toggleTemplates()" title="Templates">⚡ Templates</button>
                    <button class="btn-icon" onclick="document.getElementById('fileAttach').click()" title="Attach">📎</button>
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

<script>
const BASE_URL   = <?= json_encode($baseUrl) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const MY_ID      = <?= (int)$user['id'] ?>;
let currentThreadId  = <?= $openThreadId ?>;
let currentLastMsgId = 0;
let pollTimer        = null;
let pendingFile      = null;

async function loadThreads() {
    const res  = await fetch(`${BASE_URL}/api/messages_threads.php?filter=all`);
    const data = await res.json();
    const el   = document.getElementById('threadList');
    if (!data.threads.length) {
        el.innerHTML = `<div style="padding:28px;text-align:center;color:#a89562;font-size:.82rem;">No messages yet. Your landlord will reach out here.</div>`;
        return;
    }
    el.innerHTML = data.threads.map(t => `
        <div class="thread-item ${t.id === currentThreadId ? 'active' : ''}" onclick="openThread(${t.id})" data-thread-id="${t.id}" data-search="${(t.other_name + ' ' + t.subject).toLowerCase()}">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px;">
                <span class="thread-item-name">${escHtml(t.other_name)}</span>
                <span class="ctx-pill ctx-${t.context_type}">${t.context_type}</span>
            </div>
            <div class="thread-item-sub">${escHtml(t.subject)}</div>
            <div class="thread-item-preview">${escHtml(t.last_preview || '—')}</div>
            <div class="thread-item-meta">
                <span class="thread-item-time">${relativeTime(t.last_msg_at)}</span>
                ${t.my_unread > 0 ? `<span class="unread-dot">${t.my_unread}</span>` : ''}
            </div>
        </div>
    `).join('');
}

function relativeTime(dateStr) {
    const d = new Date(dateStr), now = new Date();
    const diff = Math.floor((now - d) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return d.toLocaleDateString('en-PH',{month:'short',day:'numeric'});
}

async function openThread(threadId) {
    clearInterval(pollTimer);
    currentThreadId  = threadId;
    currentLastMsgId = 0;
    document.querySelectorAll('.thread-item').forEach(el => el.classList.toggle('active', parseInt(el.dataset.threadId) === threadId));
    document.getElementById('emptyState').style.display = 'none';
    const cp = document.getElementById('convoPane');
    cp.style.display = 'flex';
    document.getElementById('convoMessages').innerHTML = `<div style="text-align:center;color:#a89562;font-size:.82rem;padding:20px;">Loading…</div>`;

    const res  = await fetch(`${BASE_URL}/api/messages_poll.php?thread_id=${threadId}&after_id=0`);
    const data = await res.json();
    renderMessages(data.messages, true);
    if (data.messages.length) currentLastMsgId = data.messages[data.messages.length-1].id;

    const tRes = await fetch(`${BASE_URL}/api/messages_threads.php?filter=all`);
    const tData = await tRes.json();
    const thread = tData.threads.find(t => t.id === threadId);
    if (thread) {
        document.getElementById('convoName').textContent = thread.other_name;
        document.getElementById('convoSub').textContent  = thread.subject;
        const pillEl = document.getElementById('convoPropTag');
        pillEl.textContent  = thread.context_type.charAt(0).toUpperCase() + thread.context_type.slice(1);
        pillEl.className    = `ctx-pill ctx-${thread.context_type}`;
        pillEl.style.display = '';
        document.getElementById('broadcastBanner').style.display = thread.context_type === 'broadcast' ? 'flex' : 'none';
    }

    pollTimer = setInterval(pollNewMessages, 4000);
    loadThreads();
}

async function pollNewMessages() {
    if (!currentThreadId) return;
    const res  = await fetch(`${BASE_URL}/api/messages_poll.php?thread_id=${currentThreadId}&after_id=${currentLastMsgId}`);
    const data = await res.json();
    if (data.messages && data.messages.length) {
        renderMessages(data.messages, false);
        currentLastMsgId = data.messages[data.messages.length-1].id;
        loadThreads();
    }
}

function renderMessages(messages, replace) {
    const el = document.getElementById('convoMessages');
    if (replace) el.innerHTML = '';
    messages.forEach(msg => {
        const isMine = msg.sender_id === MY_ID;
        const initials = msg.sender_name.split(' ').map(w=>w[0]).join('').toUpperCase().slice(0,2);
        const tick = isMine ? `<span class="read-tick ${msg.is_read_by_recipient?'read':''}">${msg.is_read_by_recipient?'✓✓':'✓'}</span>` : '';
        let content = '';
        if (msg.body) content += `<div>${escHtml(msg.body).replace(/\n/g,'<br>')}</div>`;
        if (msg.message_type === 'image' && msg.attachment_url) content += `<img class="msg-img-attach" src="${escHtml(msg.attachment_url)}" alt="" onclick="window.open(this.src)">`;
        else if (msg.message_type === 'pdf' && msg.attachment_url) content += `<a class="msg-pdf-attach" href="${escHtml(msg.attachment_url)}" target="_blank">📄 ${escHtml(msg.attachment_name||'PDF')}</a>`;
        if (msg.message_type === 'system') { el.insertAdjacentHTML('beforeend',`<div class="system-msg">${escHtml(msg.body)}</div>`); return; }
        el.insertAdjacentHTML('beforeend', `
            <div class="msg-bubble-wrap ${isMine?'mine':''}">
                ${!isMine?`<div class="msg-avatar">${initials}</div>`:''}
                <div>
                    <div class="msg-bubble ${isMine?'mine':'theirs'}">${content}</div>
                    <div class="msg-meta ${isMine?'mine':''}">
                        ${isMine?'':'<span>'+escHtml(msg.sender_name)+'</span> ·'}
                        <span>${new Date(msg.created_at).toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'})}</span>
                        ${tick}
                    </div>
                </div>
                ${isMine?`<div class="msg-avatar" style="background:#241b0b;color:#f6cf4a;">${initials}</div>`:''}
            </div>
        `);
    });
    el.scrollTop = el.scrollHeight;
}

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
    const res  = await fetch(`${BASE_URL}/api/messages_send.php`, {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) { document.getElementById('composeArea').value=''; clearAttach(); await pollNewMessages(); }
    else alert(data.error||'Failed.');
    btn.disabled = false;
}

function handleAttach(input) {
    if (!input.files[0]) return;
    pendingFile = input.files[0];
    document.getElementById('attachPreviewName').textContent = pendingFile.name;
    document.getElementById('attachPreviewBar').style.display = 'flex';
}
function clearAttach() {
    pendingFile = null;
    document.getElementById('attachPreviewBar').style.display = 'none';
    document.getElementById('fileAttach').value = '';
}
function toggleTemplates() { document.getElementById('templateDrawer').classList.toggle('open'); }
function closeTemplates()  { document.getElementById('templateDrawer').classList.remove('open'); }
function useTemplate(body) { document.getElementById('composeArea').value=body; closeTemplates(); document.getElementById('composeArea').focus(); }

document.getElementById('composeArea').addEventListener('keydown', function(e) {
    if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});
document.getElementById('threadSearchInput').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.thread-item').forEach(el => { el.style.display = el.dataset.search.includes(q) ? '' : 'none'; });
});

function escHtml(str) {
    if (!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

loadThreads();
if (currentThreadId) setTimeout(() => openThread(currentThreadId), 300);
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>

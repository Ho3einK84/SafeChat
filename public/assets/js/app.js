/* ============================================================
   SafeChat v1.0 – Application Script
   ============================================================ */
'use strict';

const boot = window.SAFECHAT_BOOT || {};
let ME = boot.me || '';
let CSRF = boot.csrf || '';
const CSRF_FIELD = boot.csrfField || '_csrf';
const LS_KEY = boot.lsKey || 'safechat_device_id';
const LS_THEME = boot.lsTheme || 'safechat_theme';
let IS_ADMIN = !!boot.isAdmin;

function syncCsrf(data) {
  if (data && typeof data.csrf === 'string' && data.csrf.length === 64) CSRF = data.csrf;
}

function syncCsrfFromResponse(r) {
  const h = r.headers.get('X-CSRF-Token');
  if (h && h.length === 64) CSRF = h;
}

function msgHasPassword(msg) {
  const v = msg?.has_password;
  return v === true || v === 1 || v === '1';
}

function getMsgGroupId(msg) {
  if (msg?.group_id != null && msg.group_id !== '') return Number(msg.group_id);
  if (chat?.type === 'group' && chat.id != null) return Number(chat.id);
  return null;
}

function apiPath(action) {
  return '/api/' + String(action).replace(/_/g, '-');
}

async function postApi(fd) {
  const action = fd.get('action');
  if (action) fd.delete('action');
  const url = action ? apiPath(action) : '/api';
  const r = await fetch(url, { method: 'POST', body: fd });
  syncCsrfFromResponse(r);
  let d;
  try { d = await r.json(); } catch { d = { error: 'پاسخ نامعتبر از سرور' }; }
  syncCsrf(d);
  return d;
}

const CRYPTO_STORE = 'safechat_keys';
let KEY_PAIR = null;
let GROUP_KEYS = new Map();

// ---- Chat state ----
let chat = null, lastId = 0, pollTmr = null, locked = false, unlock_ = null, toastTmr = null, replyTo = null, editMsgId = null;
let fetchInFlight = false, searchActive = false, searchDebounceTmr = null;
let pollActive = true, pollActivityBound = false;
let blockStatus = { i_blocked: false, blocked_me: false, is_blocked: false };
const messageCache = new Map();
const pubKeyCache = new Map();

// ---- IndexedDB helpers ----
function openIDB() {
  return new Promise((res, rej) => {
    const req = indexedDB.open('safechat', 1);
    req.onupgradeneeded = () => { req.result.createObjectStore(CRYPTO_STORE); };
    req.onsuccess = () => res(req.result);
    req.onerror = () => rej(req.error);
  });
}
async function saveToIDB(store, key, value) {
  const db = await openIDB();
  return new Promise((res, rej) => {
    const tx = db.transaction(store, 'readwrite');
    tx.objectStore(store).put(value, key);
    tx.oncomplete = () => res();
    tx.onerror = () => rej(tx.error);
  });
}
async function getFromIDB(store, key) {
  const db = await openIDB();
  return new Promise((res, rej) => {
    const tx = db.transaction(store, 'readonly');
    const req = tx.objectStore(store).get(key);
    req.onsuccess = () => res(req.result);
    req.onerror = () => rej(req.error);
  });
}
async function deleteFromIDB(store, key) {
  const db = await openIDB();
  return new Promise((res, rej) => {
    const tx = db.transaction(store, 'readwrite');
    tx.objectStore(store).delete(key);
    tx.oncomplete = () => res();
    tx.onerror = () => rej(tx.error);
  });
}

// ---- Web Crypto ----
async function loadOrGenerateKeyPair() {
  try {
    const stored = await getFromIDB(CRYPTO_STORE, 'keypair');
    if (stored) {
      try {
        KEY_PAIR = {
          publicKey:  await crypto.subtle.importKey('jwk', stored.publicJwk, { name: 'RSA-OAEP', hash: 'SHA-256' }, true, ['encrypt']),
          privateKey: await crypto.subtle.importKey('jwk', stored.privateJwk, { name: 'RSA-OAEP', hash: 'SHA-256' }, true, ['decrypt']),
        };
      } catch {
        // Corrupted stored keys — delete and regenerate immediately
        await deleteFromIDB(CRYPTO_STORE, 'keypair');
        KEY_PAIR = null;
        await generateNewKeyPair();
      }
    } else {
      await generateNewKeyPair();
    }
  } catch (e) { console.error('Key init error', e); }
  await syncPublicKeyToServer();
}

async function syncPublicKeyToServer() {
  if (!KEY_PAIR?.publicKey || !CSRF) return;
  try {
    const publicJwk = await crypto.subtle.exportKey('jwk', KEY_PAIR.publicKey);
    const fd = new FormData();
    fd.append('action', 'store_pubkey');
    fd.append('public_key', JSON.stringify(publicJwk));
    fd.append(CSRF_FIELD, CSRF);
    const d = await postApi(fd);
    if (d.error) console.warn('Public key sync failed:', d.error);
  } catch (e) {
    console.warn('Public key sync failed', e);
  }
}

async function generateNewKeyPair() {
  const kp = await crypto.subtle.generateKey(
    { name: 'RSA-OAEP', modulusLength: 2048, publicExponent: new Uint8Array([1,0,1]), hash: 'SHA-256' },
    true, ['encrypt', 'decrypt']
  );
  KEY_PAIR = kp;
  const publicJwk  = await crypto.subtle.exportKey('jwk', kp.publicKey);
  const privateJwk = await crypto.subtle.exportKey('jwk', kp.privateKey);
  await saveToIDB(CRYPTO_STORE, 'keypair', { publicJwk, privateJwk });
}

// ---- Safe binary <-> base64 helpers (handles all byte values) ----
function bufferToBase64(buf) {
  const bytes = buf instanceof Uint8Array ? buf : new Uint8Array(buf instanceof ArrayBuffer ? buf : buf.buffer);
  let bin = '';
  for (let i = 0; i < bytes.byteLength; i++) bin += String.fromCharCode(bytes[i]);
  return btoa(bin);
}

function base64ToBuffer(b64) {
  const bin = atob(b64);
  const buf = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
  return buf.buffer;
}

async function encryptForRecipient(plaintext, recipientPublicKeyJwk) {
  const recPub = await crypto.subtle.importKey('jwk', JSON.parse(recipientPublicKeyJwk), { name: 'RSA-OAEP', hash: 'SHA-256' }, true, ['encrypt']);
  const symKey = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, symKey, new TextEncoder().encode(plaintext));
  const rawSymKey = await crypto.subtle.exportKey('raw', symKey);
  const encSymForRec = await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, recPub, rawSymKey);
  const encSymForSelf = await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, KEY_PAIR.publicKey, rawSymKey);
  return JSON.stringify({
    keys: { sender: bufferToBase64(encSymForSelf), recipient: bufferToBase64(encSymForRec) },
    ct: bufferToBase64(iv) + '.' + bufferToBase64(new Uint8Array(ct)),
  });
}

async function decryptPrivateMessage(payloadJson, isMine) {
  let payload;
  try {
    payload = JSON.parse(payloadJson);
  } catch {
    throw new Error('Invalid private message format');
  }
  if (!payload?.keys?.sender || !payload?.keys?.recipient || !payload?.ct) {
    throw new Error('Invalid private message payload');
  }
  const encSymKeyB64 = isMine ? payload.keys.sender : payload.keys.recipient;
  const encSymKey = base64ToBuffer(encSymKeyB64);
  const rawSymKey = await crypto.subtle.decrypt({ name: 'RSA-OAEP' }, KEY_PAIR.privateKey, encSymKey);
  const symKey = await crypto.subtle.importKey('raw', rawSymKey, { name: 'AES-GCM' }, false, ['decrypt']);
  const [ivB64, ctB64] = payload.ct.split('.');
  const decBuf = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: base64ToBuffer(ivB64) }, symKey, base64ToBuffer(ctB64));
  return new TextDecoder().decode(decBuf);
}

// ---- Group Encryption ----
async function getGroupSymmetricKey(groupId) {
  groupId = Number(groupId);
  if (GROUP_KEYS.has(groupId)) return GROUP_KEYS.get(groupId);
  if (!KEY_PAIR?.privateKey) return null;
  try {
    const r = await fetch(`/api/get-group-key?group_id=${groupId}`);
    const d = await r.json();
    if (d.error || !d.encrypted_key) return null;
    const encKeyBuf = base64ToBuffer(d.encrypted_key);
    const rawKey = await crypto.subtle.decrypt({ name: 'RSA-OAEP' }, KEY_PAIR.privateKey, encKeyBuf);
    const symKey = await crypto.subtle.importKey('raw', rawKey, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
    GROUP_KEYS.set(groupId, symKey);
    return symKey;
  } catch (e) {
    console.error('Error getting group key', e);
    return null;
  }
}

async function generateGroupSymmetricKey() {
  return await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
}

async function encryptGroupSymmetricKeyForUser(symKey, userPublicKeyJwk) {
  const pub = await crypto.subtle.importKey('jwk', JSON.parse(userPublicKeyJwk), { name: 'RSA-OAEP', hash: 'SHA-256' }, true, ['encrypt']);
  const rawKey = await crypto.subtle.exportKey('raw', symKey);
  const encKey = await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, pub, rawKey);
  return bufferToBase64(new Uint8Array(encKey));
}

async function encryptGroupMessage(plaintext, groupId) {
  const symKey = await getGroupSymmetricKey(Number(groupId));
  if (!symKey) throw new Error('Group key not available');
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, symKey, new TextEncoder().encode(plaintext));
  return bufferToBase64(iv) + '.' + bufferToBase64(new Uint8Array(ct));
}

async function decryptGroupMessage(ciphertext, groupId) {
  if (!ciphertext || typeof ciphertext !== 'string') throw new Error('Invalid ciphertext');
  if (ciphertext === '[decryption error]') throw new Error('Corrupted message');
  const parts = ciphertext.split('.');
  if (parts.length !== 2) throw new Error('Invalid group message format');
  const symKey = await getGroupSymmetricKey(Number(groupId));
  if (!symKey) throw new Error('Group key not available');
  const [ivB64, ctB64] = parts;
  const decBuf = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: base64ToBuffer(ivB64) }, symKey, base64ToBuffer(ctB64));
  return new TextDecoder().decode(decBuf);
}

// ---- Connection status ----
function showConnectionStatus(ok, msg) {
  const el = document.getElementById('conn-status') || (() => {
    const e = document.createElement('div');
    e.id = 'conn-status';
    e.className = 'connection-status';
    document.body.prepend(e);
    return e;
  })();
  el.className = 'connection-status on ' + (ok ? 'ok' : 'err');
  el.textContent = msg || (ok ? 'متصل' : 'قطع اتصال');
  clearTimeout(el._timer);
  if (ok) el._timer = setTimeout(() => el.classList.remove('on'), 2000);
}

// ---- DOM ready ----
document.addEventListener('DOMContentLoaded', async () => {
  await initDeviceId();
  await loadOrGenerateKeyPair();
  initTextarea();
  initTheme();
  document.getElementById('inp-connect').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); connect(); } });
  document.getElementById('btn-add-member')?.addEventListener('click', () => showAddMemberModal());
  document.getElementById('member-id-inp')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); addMember(); }
  });
  document.getElementById('block-action-label')?.addEventListener('click', () => toggleBlockCurrentUser());
  document.getElementById('btn-actions')?.addEventListener('click', (e) => toggleSettings(e));
  openPublic();
  if ('serviceWorker' in navigator) {
    try { await navigator.serviceWorker.register('/sw.js'); } catch {}
  }
});

const openSb  = () => { document.getElementById('sidebar').classList.add('on'); document.getElementById('overlay').classList.add('on'); };
const closeSb = () => { document.getElementById('sidebar').classList.remove('on'); document.getElementById('overlay').classList.remove('on'); };

function switchTab(t) {
  document.querySelectorAll('.tab').forEach(b => b.classList.toggle('on', b.dataset.t === t));
  ['pub', 'priv', 'group'].forEach(id => {
    document.getElementById('tab-' + id).style.display = t === id ? 'block' : 'none';
  });
  if (t === 'priv') loadConvs();
  if (t === 'group') loadGroups();
}

function copyId() {
  navigator.clipboard.writeText(ME).then(() => {
    const b = document.getElementById('btn-copy');
    b.textContent = 'کپی شد';
    b.classList.add('ok');
    showToast('شناسه کپی شد');
    setTimeout(() => { b.textContent = 'کپی'; b.classList.remove('ok'); }, 2200);
  }).catch(() => showToast('کپی انجام نشد'));
}

async function connect() {
  const id = document.getElementById('inp-connect').value.trim();
  if (!/^[a-zA-Z0-9]{8}$/.test(id)) { showToast('شناسه باید ۸ کاراکتر حرف/عدد باشد'); return; }
  if (id === ME) { showToast('نمی‌توانید به خودتان وصل شوید'); return; }
  try {
    const r = await fetch(`/api/check-user?id=${encodeURIComponent(id)}`);
    const d = await r.json();
    if (!d.exists) { showToast('کاربر یافت نشد'); return; }
    if (d.blocked_me) {
      showToast('این کاربر شما را بلاک کرده است');
      return;
    }
    document.getElementById('inp-connect').value = '';
    openPrivate(id);
    switchTab('priv');
    closeSb();
  } catch { showToast('خطا در ارتباط با سرور'); }
}

function openPublic() {
  exitSearch(false);
  blockStatus = { i_blocked: false, blocked_me: false, is_blocked: false };
  updateBlockUI();
  chat = { type: 'public' }; lastId = 0;
  setHeader('', 'چت عمومی', 'همه کاربران', true);
  initChatUI(); fetchMessages(); startPoll();
  markActive('pub');
  const bar = document.getElementById('search-bar');
  if (bar) { bar.classList.add('on'); bar.classList.remove('searching'); }
}
function openPrivate(id) {
  exitSearch(false);
  chat = { type: 'private', id }; lastId = 0;
  setHeader(id.slice(0, 2).toUpperCase(), id, 'مکالمه خصوصی', false);
  initChatUI(); fetchMessages(); startPoll();
  markActive('priv:' + id);
  document.getElementById('search-bar')?.classList.remove('on', 'searching');
  loadProfile(id);
  refreshBlockStatus(id);
}
async function openGroup(groupId, groupName) {
  exitSearch(false);
  blockStatus = { i_blocked: false, blocked_me: false, is_blocked: false };
  updateBlockUI();
  chat = { type: 'group', id: Number(groupId), name: groupName }; lastId = 0;
  setHeader('', groupName, 'گروه', false);
  initChatUI();
  const gKey = await getGroupSymmetricKey(groupId);
  if (!gKey) showToast('کلید گروه در دسترس نیست. از ادمین بخواهید شما را اضافه کند.');
  fetchMessages(); startPoll();
  markActive('group:' + groupId);
  document.getElementById('search-bar')?.classList.remove('on', 'searching');
}

async function loadProfile(userId) {
  try {
    const r = await fetch(`/api/get-profile?id=${encodeURIComponent(userId)}`);
    const d = await r.json();
    if (d.display_name) {
      document.getElementById('hdr-name').textContent = d.display_name;
    }
    const sub = document.getElementById('hdr-sub');
    if (d.is_online) sub.textContent = 'آنلاین';
    else sub.textContent = 'آخرین بازدید: ' + (d.last_seen ? new Date(d.last_seen.replace(' ', 'T')).toLocaleString('fa-IR') : 'نامشخص');
  } catch {}
}

function setHeader(av, name, sub, isEmoji) {
  const avEl = document.getElementById('hdr-av');
  avEl.textContent = av;
  avEl.style.fontSize = isEmoji ? '21px' : '13px';
  document.getElementById('hdr-name').textContent = name;
  document.getElementById('hdr-sub').textContent  = sub;
  const addBtn = document.getElementById('btn-add-member');
  if (addBtn) addBtn.style.display = (chat && chat.type === 'group' && IS_ADMIN) ? 'inline-block' : 'none';
}

function initChatUI() {
  searchActive = false;
  stopPoll();
  const searchInp = document.getElementById('search-inp');
  if (searchInp) searchInp.value = '';
  document.getElementById('search-bar')?.classList.remove('searching');

  document.getElementById('welcome').style.display   = 'none';
  document.getElementById('chat-hdr').style.display  = 'flex';
  document.getElementById('msgs').style.display      = 'flex';
  document.getElementById('input-bar').style.display = 'block';
  document.getElementById('typing-indicator')?.classList.remove('on');
  const msgs = document.getElementById('msgs');
  msgs.innerHTML = '';
  const empty = document.createElement('div');
  empty.id = 'empty';
  const emptyIcon = document.createElement('span');
  emptyIcon.setAttribute('aria-hidden', 'true');
  emptyIcon.textContent = String.fromCodePoint(0x1F4AC);
  const emptyText = document.createElement('p');
  emptyText.textContent = 'هنوز پیامی وجود ندارد';
  empty.appendChild(emptyIcon);
  empty.appendChild(emptyText);
  msgs.appendChild(empty);
  messageCache.clear(); replyTo = null; editMsgId = null; updateReplyPreview(); cancelEdit();
}

function markActive(key) {
  document.querySelectorAll('.row').forEach(e => e.classList.remove('on'));
  if (key === 'pub') document.querySelector('#tab-pub .row')?.classList.add('on');
  else document.querySelector(`[data-conv="${key}"]`)?.classList.add('on');
}

async function fetchMessages(append = false) {
  if (!chat || searchActive) return;
  if (fetchInFlight) return;
  fetchInFlight = true;

  let url = '';
  if (chat.type === 'public') url = `/api/get-public?after=${lastId}`;
  else if (chat.type === 'private') url = `/api/get-private?other=${encodeURIComponent(chat.id)}&after=${lastId}`;
  else if (chat.type === 'group') url = `/api/get-group-messages?group_id=${chat.id}&after=${lastId}`;

  try {
    const r = await fetch(url);
    const d = await r.json();
    if (d.error) {
      if (!append) showToast(d.error);
      return;
    }
    if (!Array.isArray(d.messages) || !d.messages.length) return;

    const box = document.getElementById('msgs');
    const emptyEl = document.getElementById('empty');
    if (emptyEl) emptyEl.remove();
    const wasBottom = !append || isNearBottom();
    let added = 0;

    for (const msg of d.messages) {
      if (messageCache.has(msg.id) || box.querySelector(`[data-msg-id="${msg.id}"]`)) {
        if (msg.id > lastId) lastId = msg.id;
        continue;
      }
      if (msg.id > lastId) lastId = msg.id;
      const bubble = await makeBubble(msg);
      bubble.dataset.msgId = String(msg.id);
      box.appendChild(bubble);
      messageCache.set(msg.id, { element: bubble, msgObj: msg });
      added++;
    }
    if (added && wasBottom) box.scrollTop = box.scrollHeight;

    if (chat.type === 'private' && d.messages.length) {
      const lastUnseen = d.messages
        .filter(m => m.sender_id !== ME && !m.seen && !m.deleted)
        .pop();
      if (lastUnseen) markSeen(lastUnseen.id);
    }
  } catch { /* network error — poll will retry */ }
  finally { fetchInFlight = false; }
}

// All DOM construction uses textContent/createElement — never innerHTML with user data
async function makeBubble(msg) {
  const mine = msg.sender_id === ME;
  const w = createElement('div', `bw ${mine ? 'mine' : 'them'}`);

  if (!mine && (chat?.type === 'public' || chat?.type === 'group')) {
    w.appendChild(createElement('div', 'bsender', msg.sender_id));
  }

  if (msg.reply_to) {
    const replyPreview = createElement('div', 'reply-to-badge');
    const replied = messageCache.get(msg.reply_to);
    const repliedText = replied?.msgObj?.content?.substring(0, 30) || '...';
    replyPreview.textContent = 'در پاسخ به «' + repliedText + '»';
    w.appendChild(replyPreview);
  }

  if (msg.deleted) {
    w.appendChild(createElement('div', 'bubble deleted', msg.content));
  } else if (msgHasPassword(msg) && !mine && msg.content === null) {
    const lk = createElement('div', 'locked');
    lk.appendChild(createElement('span', 'lic', String.fromCodePoint(0x1F512)));
    lk.appendChild(document.createTextNode(' پیام قفل‌دار — برای باز کردن کلیک کنید'));
    lk.onclick = () => openModal(msg.id, lk);
    w.appendChild(lk);
  } else {
    let text = msg.content || '';
    const groupId = getMsgGroupId(msg);
    if (groupId && !msgHasPassword(msg) && msg.content) {
      try { text = await decryptGroupMessage(msg.content, groupId); }
      catch { text = 'خطا در رمزگشایی گروه'; }
    } else if (chat?.type === 'private' && !msgHasPassword(msg) && msg.content) {
      try { text = await decryptPrivateMessage(msg.content, mine); }
      catch { text = 'خطا در رمزگشایی'; }
    }
    text = (text || '').replace(/\n{3,}/g, '\n\n').trim();
    w.appendChild(createElement('div', 'bubble' + (msg.edited_at ? ' edited' : ''), text));
  }

  const actions = createElement('div', 'message-actions');
  if (mine && !msg.deleted) {
    const editBtn = createElement('button', '', String.fromCodePoint(0x270F, 0xFE0F));
    editBtn.title = 'ویرایش';
    editBtn.onclick = e => { e.stopPropagation(); startEdit(msg); };
    actions.appendChild(editBtn);
  }
  if (IS_ADMIN || mine) {
    const delBtn = createElement('button', '', String.fromCodePoint(0x1F5D1));
    delBtn.title = 'حذف';
    delBtn.onclick = e => { e.stopPropagation(); deleteMessage(msg.id); };
    actions.appendChild(delBtn);
  }
  const repBtn = createElement('button', '', String.fromCodePoint(0x21A9));
  repBtn.title = 'پاسخ';
  repBtn.onclick = e => { e.stopPropagation(); setReply(msg).catch(()=>{}); };
  actions.appendChild(repBtn);
  w.appendChild(actions);

  const timeLine = createElement('div', 'btime', fmtTime(msg.created_at));
  if (mine && chat.type === 'private' && msg.seen) {
    const check = createElement('span', 'seen-check');
    check.setAttribute('aria-label', 'خوانده شد');
    check.textContent = ' ' + String.fromCodePoint(0x2714) + String.fromCodePoint(0x2714);
    timeLine.appendChild(check);
  }
  w.appendChild(timeLine);

  w.dataset.msgId = String(msg.id);
  return w;
}

// Renamed from 'el' to avoid shadowing DOM element variable names in closures
function createElement(tag, cls, txt) {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (txt !== undefined) e.textContent = txt;
  return e;
}

// Keep 'el' as alias for backward compat
const el = createElement;

function fmtTime(ts) {
  try { return new Date(ts.replace(' ', 'T')).toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' }); }
  catch { return ts; }
}

function isNearBottom() {
  const c = document.getElementById('msgs');
  return c.scrollHeight - c.scrollTop - c.clientHeight < 100;
}

function stopPoll() {
  if (pollTmr) {
    clearTimeout(pollTmr);
    pollTmr = null;
  }
}

// ---- Poll activity tracking ----
let pollActivityInterval = null;

function bindPollActivity() {
  if (pollActivityBound) return;
  pollActivityBound = true;
  document.addEventListener('mousemove', () => { pollActive = true; }, { passive: true });
  document.addEventListener('touchstart', () => { pollActive = true; }, { passive: true });
  pollActivityInterval = setInterval(() => { pollActive = false; }, 8000);
}

function startPoll() {
  stopPoll();
  if (searchActive) return;
  bindPollActivity();
  pollActive = true;
  const poll = () => {
    pollTmr = null;
    if (chat && !searchActive) fetchMessages(true);
    pollTmr = setTimeout(poll, pollActive ? 2000 : 5000);
  };
  pollTmr = setTimeout(poll, 2000);
}

// ---- Send Message ----
async function send() {
  const inp = document.getElementById('inp-msg');
  const rawText = inp.value;
  const pass = locked ? document.getElementById('inp-pass').value.trim() : '';
  if (!rawText.trim()) return;
  if (locked && !pass) { showToast('رمز پیام را وارد کنید'); return; }
  if (chat?.type === 'private' && blockStatus.is_blocked) {
    showToast(blockStatus.i_blocked ? 'ابتدا کاربر را آنبلاک کنید' : 'این کاربر شما را بلاک کرده است');
    return;
  }

  const btn = document.getElementById('btn-send');
  btn.disabled = true;

  try {
    let content = rawText.trim();

    // Handle edit mode
    if (editMsgId) {
      if (chat.type === 'private') {
        const pubKey = await getRecipientPublicKey(chat.id);
        if (!pubKey) { showToast('مخاطب هنوز وارد چت نشده. از او بخواهید یک‌بار SafeChat را باز کند، بعد دوباره ارسال کنید.'); return; }
        try { content = await encryptForRecipient(content, pubKey); }
        catch { showToast('خطا در رمزنگاری'); return; }
      } else if (chat.type === 'group') {
        try { content = await encryptGroupMessage(content, chat.id); }
        catch { showToast('خطا در رمزنگاری گروه'); return; }
      }
      const fd = new FormData();
      fd.append('action', 'edit_message');
      fd.append('msg_id', editMsgId);
      fd.append('content', content);
      fd.append(CSRF_FIELD, CSRF);
      try {
        const d = await postApi(fd);
        if (d.success) {
          inp.value = ''; inp.style.height = ''; charCount(0);
          cancelEdit();
          lastId = 0; document.getElementById('msgs').innerHTML = ''; messageCache.clear();
          await fetchMessages();
        } else showToast(d.error || 'خطا در ویرایش');
      } catch { showToast('خطا در ارتباط'); }
      inp.focus();
      return;
    }

    // Encrypt for private/group
    if (chat.type === 'private') {
      const pubKey = await getRecipientPublicKey(chat.id);
      if (!pubKey) { showToast('مخاطب هنوز وارد چت نشده. از او بخواهید یک‌بار SafeChat را باز کند، بعد دوباره ارسال کنید.'); return; }
      try { content = await encryptForRecipient(content, pubKey); }
      catch { showToast('خطا در رمزنگاری'); return; }
    } else if (chat.type === 'group') {
      try { content = await encryptGroupMessage(content, chat.id); }
      catch { showToast('خطا در رمزنگاری گروه'); return; }
    }

    const fd = new FormData();
    fd.append('action', chat.type === 'public' ? 'send_public' : (chat.type === 'private' ? 'send_private' : 'send_group_message'));
    fd.append('content', content);
    fd.append(CSRF_FIELD, CSRF);
    if (pass) fd.append('password', pass);
    if (chat.type === 'private') fd.append('recipient_id', chat.id);
    if (chat.type === 'group') fd.append('group_id', chat.id);
    if (replyTo) fd.append('reply_to', replyTo.msgId);

    try {
      const d = await postApi(fd);
      if (d.success) {
        inp.value = ''; inp.style.height = ''; charCount(0);
        if (locked) { document.getElementById('inp-pass').value = ''; toggleLock(); }
        cancelReply();
        await fetchMessages(true);
      } else showToast(d.error || 'خطا در ارسال');
    } catch { showToast('خطای ارتباط با سرور'); }
    inp.focus();
  } finally {
    btn.disabled = false;
  }
}

// ---- Edit Message ----
function startEdit(msg) {
  if (!messageCache.has(msg.id)) return;
  if (msgHasPassword(msg)) { showToast('پیام قفل‌دار قابل ویرایش نیست'); return; }
  if (!msg.content) { showToast('محتوای پیام قابل بازیابی نیست'); return; }

  editMsgId = msg.id;
  replyTo = null;
  updateReplyPreview();
  document.getElementById('edit-preview').classList.add('on');
  document.getElementById('edit-text').textContent = 'ویرایش پیام';

  if (chat.type === 'private' && !msgHasPassword(msg)) {
    decryptPrivateMessage(msg.content, true).then(t => {
      document.getElementById('inp-msg').value = t;
      document.getElementById('inp-msg').style.height = 'auto';
      charCount(t.length);
    }).catch(() => {
      showToast('رمزگشایی پیام ممکن نیست');
      cancelEdit();
    });
  } else if (getMsgGroupId(msg) && !msgHasPassword(msg)) {
    decryptGroupMessage(msg.content, getMsgGroupId(msg)).then(t => {
      document.getElementById('inp-msg').value = t;
      document.getElementById('inp-msg').style.height = 'auto';
      charCount(t.length);
    }).catch(() => {
      showToast('رمزگشایی پیام ممکن نیست');
      cancelEdit();
    });
  } else {
    document.getElementById('inp-msg').value = msg.content;
    charCount(msg.content.length);
  }
  document.getElementById('inp-msg').focus();
}

function cancelEdit() {
  editMsgId = null;
  document.getElementById('edit-preview')?.classList.remove('on');
}

// ---- Search ----
function onSearchInput(value) {
  clearTimeout(searchDebounceTmr);
  const q = value.trim();
  if (!q) {
    if (searchActive) exitSearch();
    return;
  }
  if (q.length < 2) return;
  searchDebounceTmr = setTimeout(() => runSearch(q), 450);
}

function exitSearch(reload = true) {
  clearTimeout(searchDebounceTmr);
  searchActive = false;
  const inp = document.getElementById('search-inp');
  if (inp) inp.value = '';
  const bar = document.getElementById('search-bar');
  if (bar) bar.classList.remove('searching');

  if (!reload || !chat) return;

  lastId = 0;
  messageCache.clear();
  const msgs = document.getElementById('msgs');
  msgs.innerHTML = '';
  const empty = document.createElement('div');
  empty.id = 'empty';
  const emptyIcon = document.createElement('span');
  emptyIcon.setAttribute('aria-hidden', 'true');
  emptyIcon.textContent = String.fromCodePoint(0x1F4AC);
  const emptyText = document.createElement('p');
  emptyText.textContent = 'هنوز پیامی وجود ندارد';
  empty.appendChild(emptyIcon);
  empty.appendChild(emptyText);
  msgs.appendChild(empty);
  fetchMessages();
  startPoll();
}

async function runSearch(query) {
  query = query.trim();
  if (!query) { exitSearch(); return; }
  if (!chat || chat.type !== 'public') {
    showToast('جستجو فقط در چت عمومی');
    return;
  }
  if (query.length < 2) {
    showToast('حداقل ۲ کاراکتر وارد کنید');
    return;
  }

  searchActive = true;
  stopPoll();
  document.getElementById('search-bar')?.classList.add('searching');

  const box = document.getElementById('msgs');
  box.innerHTML = '';

  // Safe DOM construction — no innerHTML with user content
  const banner = document.createElement('div');
  banner.className = 'search-banner';
  banner.id = 'search-banner';
  const bannerLabel = document.createElement('span');
  bannerLabel.textContent = 'نتایج برای «' + query + '»';
  const bannerBtn = document.createElement('button');
  bannerBtn.type = 'button';
  bannerBtn.textContent = 'بازگشت به چت';
  bannerBtn.onclick = () => exitSearch();
  banner.appendChild(bannerLabel);
  banner.appendChild(bannerBtn);
  box.appendChild(banner);

  try {
    const r = await fetch(`/api/search-messages?q=${encodeURIComponent(query)}`);
    const d = await r.json();
    const results = Array.isArray(d.messages) ? d.messages : [];
    document.getElementById('search-bar')?.classList.remove('searching');
    if (!results.length) {
      box.appendChild(createElement('div', 'search-empty', 'نتیجه‌ای یافت نشد'));
      return;
    }
    for (const msg of results) {
      const bubble = await makeBubble(msg);
      bubble.classList.add('search-hit');
      bubble.dataset.msgId = String(msg.id);
      box.appendChild(bubble);
    }
    box.scrollTop = 0;
    showToast(`${results.length} نتیجه`);
  } catch {
    document.getElementById('search-bar')?.classList.remove('searching');
    showToast('خطا در جستجو');
  }
}

// ---- Profile ----
async function showProfileModal() {
  document.getElementById('profile-inp').value = '';
  try {
    const r = await fetch(`/api/get-profile?id=${encodeURIComponent(ME)}`);
    const d = await r.json();
    if (d.display_name) document.getElementById('profile-inp').value = d.display_name;
  } catch {}
  document.getElementById('profile-modal').classList.add('on');
}

function closeProfileModal() { document.getElementById('profile-modal').classList.remove('on'); }

async function saveProfile() {
  const name = document.getElementById('profile-inp').value.trim();
  if (!name) { showToast('نام نمی‌تواند خالی باشد'); return; }
  const fd = new FormData();
  fd.append('action', 'update_profile');
  fd.append('display_name', name);
  fd.append(CSRF_FIELD, CSRF);
  try {
    const d = await postApi(fd);
    if (d.success) {
      closeProfileModal();
      showToast('پروفایل ذخیره شد');
      if (chat?.type === 'private') loadProfile(chat.id);
    } else showToast(d.error || 'خطا');
  } catch { showToast('خطا در ارتباط'); }
}

// ---- Export ----
async function exportChat(format) {
  format = format || 'json';
  if (!chat || chat.type !== 'private') { showToast('فقط برای چت خصوصی'); return; }
  try {
    const fd = new FormData();
    fd.append('action', 'export_chat');
    fd.append('other_id', chat.id);
    fd.append('format', format);
    fd.append(CSRF_FIELD, CSRF);
    const r = await fetch(apiPath('export_chat'), { method: 'POST', body: fd });
    syncCsrfFromResponse(r);
    const blob = await r.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `safechat_${chat.id}_${new Date().toISOString().slice(0,10)}.${format}`;
    a.click();
    URL.revokeObjectURL(url);
    showToast('خروجی گرفته شد');
  } catch { showToast('خطا'); }
}

// ---- Block / Unblock ----
async function refreshBlockStatus(otherId) {
  if (!otherId || !/^[a-zA-Z0-9]{8}$/.test(otherId)) return;
  try {
    const r = await fetch(`/api/get-block-status?other=${encodeURIComponent(otherId)}`);
    const d = await r.json();
    if (!d.error) {
      blockStatus = {
        i_blocked: !!d.i_blocked,
        blocked_me: !!d.blocked_me,
        is_blocked: !!d.is_blocked,
      };
      updateBlockUI();
    }
  } catch { /* ignore */ }
}

function updateBlockUI() {
  const label = document.getElementById('block-action-label');
  const banner = document.getElementById('block-banner');
  const bannerText = document.getElementById('block-banner-text');
  const bannerBtn = document.getElementById('block-banner-btn');
  const inputBar = document.getElementById('input-bar');
  const inPrivate = chat?.type === 'private';

  if (label) {
    label.style.display = inPrivate ? 'flex' : 'none';
    if (inPrivate) {
      label.textContent = blockStatus.i_blocked ? 'آنبلاک کاربر' : 'بلاک کاربر';
      label.style.color = blockStatus.i_blocked ? 'var(--g2)' : '#b91c1c';
    }
  }

  if (banner && bannerText) {
    if (inPrivate && blockStatus.is_blocked) {
      banner.style.display = 'flex';
      if (blockStatus.i_blocked) {
        bannerText.textContent = 'شما این کاربر را بلاک کرده‌اید. پیام جدید رد می‌شود.';
        if (bannerBtn) bannerBtn.style.display = 'inline-block';
      } else {
        bannerText.textContent = 'این کاربر شما را بلاک کرده است. نمی‌توانید پیام بفرستید.';
        if (bannerBtn) bannerBtn.style.display = 'none';
      }
      if (inputBar) inputBar.style.display = 'none';
    } else {
      banner.style.display = 'none';
      if (inputBar && chat) inputBar.style.display = 'block';
    }
  }
}

async function toggleBlockCurrentUser() {
  if (!chat || chat.type !== 'private') {
    showToast('بلاک فقط در چت خصوصی');
    return;
  }
  if (blockStatus.i_blocked) await unblockUserById(chat.id);
  else await blockUser();
  document.getElementById('settings-panel')?.classList.remove('on');
}

async function blockUser() {
  if (!chat || chat.type !== 'private') {
    showToast('بلاک فقط در چت خصوصی');
    return;
  }
  if (!confirm(`کاربر ${chat.id} بلاک شود?\nتا آنبلاک نکنید، نمی‌توانید به او پیام بدهید.`)) return;
  const fd = new FormData();
  fd.append('action', 'block_user');
  fd.append('blocked_id', chat.id);
  fd.append(CSRF_FIELD, CSRF);
  try {
    const d = await postApi(fd);
    if (d.success) {
      showToast('کاربر بلاک شد');
      await refreshBlockStatus(chat.id);
      loadConvs();
    } else showToast(d.error || 'خطا');
  } catch { showToast('خطا'); }
}

async function unblockUserById(deviceId) {
  const fd = new FormData();
  fd.append('action', 'unblock_user');
  fd.append('blocked_id', deviceId);
  fd.append(CSRF_FIELD, CSRF);
  try {
    const d = await postApi(fd);
    if (d.success) {
      showToast('آنبلاک شد');
      if (chat?.type === 'private' && chat.id === deviceId) {
        await refreshBlockStatus(deviceId);
      }
      loadConvs();
    } else showToast(d.error || 'خطا');
  } catch { showToast('خطا'); }
}

async function unblockCurrentUser() {
  if (chat?.type === 'private') await unblockUserById(chat.id);
}

async function showBlockedModal() {
  document.getElementById('settings-panel')?.classList.remove('on');
  const list = document.getElementById('blocked-list');
  if (!list) return;
  list.innerHTML = '';
  list.appendChild(createElement('div', 'blocked-empty', 'در حال بارگذاری...'));
  document.getElementById('blocked-modal')?.classList.add('on');
  try {
    const r = await fetch('/api/get-blocked-users');
    const d = await r.json();
    const blocked = Array.isArray(d.blocked) ? d.blocked : [];
    list.innerHTML = '';
    if (!blocked.length) {
      list.appendChild(createElement('div', 'blocked-empty', 'لیست بلاک خالی است'));
      return;
    }
    blocked.forEach(row => {
      const item = document.createElement('div');
      item.className = 'blocked-item';
      const info = document.createElement('div');
      info.appendChild(createElement('div', 'bi-id', row.blocked_id));
      info.appendChild(createElement('div', 'bi-name', row.display_name || 'بدون نام'));
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = 'آنبلاک';
      btn.onclick = () => unblockUserById(row.blocked_id).then(() => showBlockedModal());
      item.appendChild(info);
      item.appendChild(btn);
      list.appendChild(item);
    });
  } catch {
    list.innerHTML = '';
    list.appendChild(createElement('div', 'blocked-empty', 'خطا در بارگذاری'));
  }
}

function closeBlockedModal() {
  document.getElementById('blocked-modal')?.classList.remove('on');
}

// ---- Online Users ----
async function loadOnlineUsers() {
  try {
    const r = await fetch('/api/get-online-users');
    const d = await r.json();
    const list = document.getElementById('online-list');
    if (!list) return;
    list.innerHTML = '';
    if (d.users && d.users.length) {
      d.users.forEach(u => {
        const name = u.display_name || u.device_id;
        const item = createElement('div', 'row');
        const av = createElement('div', 'av');
        av.style.background = 'var(--g3)';
        av.style.color = '#fff';
        av.textContent = String.fromCodePoint(0x1F7E2);
        const ri = createElement('div', 'ri');
        ri.appendChild(createElement('div', 'rn', name));
        ri.appendChild(createElement('div', 'rs', 'آنلاین'));
        item.appendChild(av);
        item.appendChild(ri);
        list.appendChild(item);
      });
    } else {
      list.appendChild(createElement('div', 'no-convs', 'کاربر آنلاینی نیست'));
    }
  } catch {}
}

// ---- Settings panel ----
function toggleSettings(e) {
  e?.stopPropagation();
  e?.preventDefault();
  const panel = document.getElementById('settings-panel');
  const btn = document.getElementById('btn-actions');
  if (!panel) return;
  const open = !panel.classList.contains('on');
  panel.classList.toggle('on', open);
  if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}

document.addEventListener('click', (e) => {
  const panel = document.getElementById('settings-panel');
  const btn = document.getElementById('btn-actions');
  if (!panel?.classList.contains('on')) return;
  if (panel.contains(e.target) || btn?.contains(e.target)) return;
  panel.classList.remove('on');
  btn?.setAttribute('aria-expanded', 'false');
});

// ---- Theme ----
function initTheme() {
  const saved = localStorage.getItem(LS_THEME) || 'light';
  const isDark = saved === 'dark';
  document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
  const checkbox = document.querySelector('.settings-panel input[type="checkbox"]');
  if (checkbox) checkbox.checked = isDark;
}

function toggleTheme() {
  const current = document.documentElement.getAttribute('data-theme');
  const next = current === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem(LS_THEME, next);
  const checkbox = document.querySelector('.settings-panel input[type="checkbox"]');
  if (checkbox) checkbox.checked = next === 'dark';
}

// ---- Recipient Public Key ----
async function getRecipientPublicKey(deviceId) {
  if (pubKeyCache.has(deviceId)) return pubKeyCache.get(deviceId);
  try {
    const r = await fetch(`/api/get-pubkey?id=${encodeURIComponent(deviceId)}`);
    const d = await r.json();
    if (d.public_key) { pubKeyCache.set(deviceId, d.public_key); return d.public_key; }
  } catch {}
  return null;
}

// ---- Reply ----
async function setReply(msg) {
  let text = msg.content ? msg.content.substring(0, 60) : '...';
  const gid = getMsgGroupId(msg);
  if (gid && msg.content && !msgHasPassword(msg)) {
    try { text = (await decryptGroupMessage(msg.content, gid)).substring(0, 60); }
    catch { text = '[encrypted]'; }
  } else if (chat && chat.type === 'private' && msg.content && !msgHasPassword(msg)) {
    try { text = (await decryptPrivateMessage(msg.content, msg.sender_id === ME)).substring(0, 60); }
    catch { text = '[encrypted]'; }
  }
  replyTo = { msgId: msg.id, text };
  editMsgId = null;
  updateReplyPreview();
  document.getElementById('inp-msg').focus();
}

function cancelReply() { replyTo = null; updateReplyPreview(); }

function updateReplyPreview() {
  const rp = document.getElementById('reply-preview');
  const txt = document.getElementById('reply-text');
  if (replyTo) { txt.textContent = replyTo.text; rp.classList.add('on'); }
  else rp.classList.remove('on');
}

// ---- Delete Message ----
async function deleteMessage(msgId) {
  if (!confirm('آیا مطمئن هستید که می‌خواهید این پیام را حذف کنید؟')) return;
  const fd = new FormData();
  fd.append('action', 'delete_message');
  fd.append('msg_id', msgId);
  fd.append(CSRF_FIELD, CSRF);
  try {
    const d = await postApi(fd);
    if (d.success) {
      lastId = 0;
      document.getElementById('msgs').innerHTML = '';
      messageCache.clear();
      fetchMessages();
    } else showToast(d.error || 'خطا در حذف');
  } catch { showToast('خطا در ارتباط با سرور'); }
}

// ---- Seen ----
async function markSeen(msgId) {
  const fd = new FormData();
  fd.append('action', 'mark_seen');
  fd.append('msg_id', msgId);
  fd.append(CSRF_FIELD, CSRF);
  await postApi(fd);
}

// ---- Groups ----
async function loadGroups() {
  try {
    const r = await fetch('/api/get-groups');
    const d = await r.json();
    const list = document.getElementById('group-list');
    const noEl = document.getElementById('no-groups');
    if (!list) return;
    list.innerHTML = '';
    const groups = Array.isArray(d.groups) ? d.groups : [];
    if (noEl) noEl.style.display = groups.length ? 'none' : 'block';
    groups.forEach(g => {
      const item = createElement('div', 'row group-row');
      item.dataset.conv = 'group:' + g.id;
      const av = createElement('div', 'av');
      av.textContent = String.fromCodePoint(0x1F465);
      const ri = createElement('div', 'ri');
      ri.appendChild(createElement('div', 'rn', g.name));
      ri.appendChild(createElement('div', 'rs', 'گروه \u00B7 ' + (g.created_at?.slice(0,10) || '')));
      item.appendChild(av);
      item.appendChild(ri);
      item.onclick = () => { openGroup(g.id, g.name); closeSb(); };
      list.appendChild(item);
    });
  } catch {}
}

async function showCreateGroupModal() {
  document.getElementById('group-name-inp').value = '';
  document.getElementById('group-modal').classList.add('on');
}
function closeGroupModal() { document.getElementById('group-modal').classList.remove('on'); }

async function createGroup() {
  const name = document.getElementById('group-name-inp').value.trim();
  if (!name) return;
  const symKey = await generateGroupSymmetricKey();
  const pubJwk = await crypto.subtle.exportKey('jwk', KEY_PAIR.publicKey);
  const encKey = await encryptGroupSymmetricKeyForUser(symKey, JSON.stringify(pubJwk));

  const fd = new FormData();
  fd.append('action', 'create_group');
  fd.append('name', name);
  fd.append('encrypted_key', encKey);
  fd.append(CSRF_FIELD, CSRF);
  const d = await postApi(fd);
  if (d.success) {
    closeGroupModal();
    GROUP_KEYS.set(Number(d.id), symKey);
    loadGroups(); showToast('گروه ایجاد شد');
  } else showToast(d.error || 'خطا');
}

function showAddMemberModal(groupId) {
  if (!IS_ADMIN) {
    showToast('فقط ادمین می‌تواند عضو اضافه کند');
    return;
  }
  const gid = groupId ?? chat?.id;
  if (!gid) {
    showToast('ابتدا یک گروه را باز کنید');
    return;
  }
  document.getElementById('member-group-id').value = String(gid);
  document.getElementById('member-id-inp').value = '';
  document.getElementById('member-modal').classList.add('on');
  requestAnimationFrame(() => document.getElementById('member-id-inp')?.focus());
}
function closeMemberModal() { document.getElementById('member-modal').classList.remove('on'); }

async function addMember() {
  if (!IS_ADMIN) {
    showToast('فقط ادمین می‌تواند عضو اضافه کند');
    return;
  }
  const groupId = parseInt(document.getElementById('member-group-id').value, 10);
  const userId  = document.getElementById('member-id-inp').value.trim();
  if (!groupId) { showToast('گروه نامعتبر است'); return; }
  if (!/^[a-zA-Z0-9]{8}$/.test(userId)) { showToast('شناسه باید ۸ کاراکتر حرف/عدد باشد'); return; }
  if (userId === ME) { showToast('خودتان را نمی‌توانید دوباره اضافه کنید'); return; }

  const okBtn = document.querySelector('#member-modal .btn-ok');
  if (okBtn) okBtn.disabled = true;

  try {
    const checkR = await fetch(`/api/check-user?id=${encodeURIComponent(userId)}`);
    const checkD = await checkR.json();
    if (!checkD.exists) { showToast('کاربر یافت نشد'); return; }

    const pubKeyJwk = await getRecipientPublicKey(userId);
    if (!pubKeyJwk) {
      showToast('کاربر هنوز وارد SafeChat نشده. از او بخواهید یک‌بار چت را باز کند.');
      return;
    }

    const symKey = await getGroupSymmetricKey(groupId);
    if (!symKey) { showToast('کلید گروه در دسترس نیست'); return; }

    const encKey = await encryptGroupSymmetricKeyForUser(symKey, pubKeyJwk);
    const fd = new FormData();
    fd.append('action', 'add_member');
    fd.append('group_id', groupId);
    fd.append('user_id', userId);
    fd.append('encrypted_key', encKey);
    fd.append(CSRF_FIELD, CSRF);
    const d = await postApi(fd);
    if (d.success) {
      closeMemberModal();
      showToast('عضو اضافه شد');
      loadGroups();
    } else {
      showToast(d.error || 'خطا در افزودن عضو');
    }
  } catch {
    showToast('خطا در افزودن عضو');
  } finally {
    if (okBtn) okBtn.disabled = false;
  }
}

// ---- Conversations ----
async function loadConvs() {
  try {
    const r = await fetch('/api/get-conversations');
    const d = await r.json();
    const list = document.getElementById('priv-list');
    const noEl = document.getElementById('no-convs');
    list.innerHTML = '';
    const convs = d.conversations ?? [];
    noEl.style.display = convs.length ? 'none' : 'block';
    convs.forEach(c => {
      const item = createElement('div', 'row');
      item.dataset.conv = 'priv:' + c.other_id;
      const av = createElement('div', 'av', c.other_id.slice(0, 2).toUpperCase());
      const ri = createElement('div', 'ri');
      ri.appendChild(createElement('div', 'rn', c.other_id));
      ri.appendChild(createElement('div', 'rs', 'مکالمه خصوصی'));
      item.appendChild(av);
      item.appendChild(ri);
      item.onclick = () => { openPrivate(c.other_id); closeSb(); };
      list.appendChild(item);
    });
  } catch {}
}

// ---- Reset ----
async function resetDatabase() {
  if (!confirm('کل دیتابیس ریست می‌شود! مطمئن هستید؟')) return;
  const fd = new FormData();
  fd.append('action', 'reset_db');
  fd.append(CSRF_FIELD, CSRF);
  const d = await postApi(fd);
  if (d.success) { ME = d.new_device_id; localStorage.setItem(LS_KEY, ME); location.reload(); }
  else showToast(d.error || 'خطا');
}

// ---- Init & misc ----
async function initDeviceId() {
  const stored = localStorage.getItem(LS_KEY);
  try {
    const fd = new FormData();
    fd.append('action', 'init');
    if (stored) fd.append('device_id', stored);
    const res = await postApi(fd);
    if (res.id) {
      ME = res.id;
      syncCsrf(res);
      IS_ADMIN = res.is_admin || false;
      localStorage.setItem(LS_KEY, res.id);
      const valEl = document.querySelector('.id-val');
      if (valEl) valEl.textContent = res.id;
      const adminArea = document.querySelector('.admin-area');
      if (adminArea) adminArea.style.display = IS_ADMIN ? 'block' : 'none';
      const createGroupBtn = document.querySelector('#tab-group .btn-go');
      if (createGroupBtn) createGroupBtn.style.display = IS_ADMIN ? 'inline-block' : 'none';
      if (chat?.type === 'group') {
        const addBtn = document.getElementById('btn-add-member');
        if (addBtn) addBtn.style.display = IS_ADMIN ? 'inline-block' : 'none';
      }
    }
  } catch { if (!stored) localStorage.setItem(LS_KEY, ME); }
}

function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('on');
  clearTimeout(toastTmr);
  toastTmr = setTimeout(() => t.classList.remove('on'), 2600);
}

function initTextarea() {
  const inp = document.getElementById('inp-msg');
  inp.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey && !isMobile()) { e.preventDefault(); send(); }
  });
  inp.addEventListener('input', () => {
    inp.style.height = 'auto';
    inp.style.height = Math.min(inp.scrollHeight, 120) + 'px';
    charCount(inp.value.length);
  });
}

function isMobile() { return window.matchMedia('(max-width:680px)').matches || navigator.maxTouchPoints > 1; }

function charCount(n) {
  const e = document.getElementById('char-ct');
  e.textContent = n > 0 ? `${n}/2000` : '';
  e.className = n > 1900 ? 'e' : n > 1600 ? 'w' : '';
}

function toggleLock() {
  locked = !locked;
  const lockBtn = document.getElementById('btn-lock');
  lockBtn.textContent = locked ? String.fromCodePoint(0x1F512) : String.fromCodePoint(0x1F513);
  lockBtn.classList.toggle('on', locked);
  document.getElementById('pass-row').classList.toggle('on', locked);
  if (locked) document.getElementById('inp-pass').focus();
}

function openModal(msgId, lockEl) {
  unlock_ = { msgId, el: lockEl };
  document.getElementById('modal-err').classList.remove('on');
  document.getElementById('modal-inp').value = '';
  document.getElementById('modal').classList.add('on');
  requestAnimationFrame(() => document.getElementById('modal-inp').focus());
}

function closeModal() { document.getElementById('modal').classList.remove('on'); unlock_ = null; }

async function unlock() {
  const pass = document.getElementById('modal-inp').value.trim();
  if (!pass) return;
  const errEl = document.getElementById('modal-err');
  errEl.classList.remove('on');
  const fd = new FormData();
  fd.append('action', 'unlock_message');
  fd.append('msg_id', unlock_.msgId);
  fd.append('password', pass);
  fd.append(CSRF_FIELD, CSRF);
  try {
    const d = await postApi(fd);
    if (d.error) { errEl.textContent = d.error; errEl.classList.add('on'); document.getElementById('modal-inp').select(); return; }
    let content = d.content;
    if (chat && chat.type === 'private') {
      try { content = await decryptPrivateMessage(content, false); } catch { content = 'خطا در رمزگشایی'; }
    } else if (chat && chat.type === 'group') {
      try { content = await decryptGroupMessage(content, chat.id); } catch { content = 'خطا در رمزگشایی'; }
    }
    unlock_.el.replaceWith(createElement('div', 'bubble', content));
    closeModal();
  } catch { showToast('خطا در ارتباط'); }
}

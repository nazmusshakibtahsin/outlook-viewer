<?php require 'lib.php'; $email = strtolower(trim($_GET['email'] ?? ''));
shell_start("Inbox", 'inbox', '<span id="conn" class="badge b-muted"><span class="dot"></span><span>Connecting</span></span>'); ?>
<?php crumbs(['Inbox']); ?>
<div class="card" style="margin-bottom:16px">
  <div class="mbox-head"><div class="avatar"><i class="fa-solid fa-inbox"></i></div>
    <div class="meta"><h1 style="font-size:20px">Inbox</h1><p class="muted small">Manage and view your incoming messages.</p>
      <p class="small mono truncate" style="margin-top:2px"><?= h($email) ?></p></div>
    <div class="row actions">
      <label for="mode" class="sr-only">Auto refresh</label>
      <select id="mode" class="select" style="width:auto;height:40px">
        <option value="0" selected>Auto refresh: Off</option><option value="smart">Smart (10s → 30s → 1m → 10m)</option><option value="10">Every 10 seconds</option><option value="30">Every 30 seconds</option><option value="60">Every 60 seconds</option>
      </select>
      <button id="refresh" class="btn btn-primary"><i class="fa-solid fa-rotate"></i><span>Refresh</span></button>
    </div></div>
  <div class="mbox-foot"><span id="upd"><i class="fa-regular fa-clock"></i> Last updated: —</span><span id="count"></span><span id="next"></span></div>
</div>
<div class="stack">
    <div id="recent" class="alert a-success hidden" role="status"><i class="fa-solid fa-circle-check"></i><div><b>New mail received — auto refresh stopped</b><div class="small">Use Refresh anytime to check again.</div></div></div>
  <div id="warn" class="alert a-warning hidden" role="status"></div>
  <div class="field-wrap"><i class="fa-solid fa-magnifying-glass"></i><label for="q" class="sr-only">Search emails</label><input id="q" class="input" placeholder="Search emails…"></div>
  <div id="inbox" class="inbox">
    <div class="card mail-list"><div class="list-head"><span>Messages</span><span id="lcount"></span></div><div id="list" aria-label="Messages"></div></div>
    <div class="card mail-view" id="view"></div>
  </div>
</div>
<script>
const EMAIL = <?= json_encode($email) ?>, STEPS = [10, 30, 60, 600], RECENT_MS = 10 * 60 * 1000;
let step = 0, left = 0, busy = false, loaded = false, gotRecent = false, msgs = [], sel = null, lastAt = null;
const seen = new Set(JSON.parse(localStorage.getItem('seen:' + EMAIL) || '[]'));
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const isHtml = s => /<\/?[a-z][\s\S]*>/i.test(s);
const plain = s => isHtml(s) ? s.replace(/<(style|script|head)[\s\S]*?<\/\1>/gi, '').replace(/<br\s*\/?>|<\/p>|<\/div>|<\/tr>/gi, '\n').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/[ \t]+/g, ' ').replace(/\n\s*\n+/g, '\n\n').trim() : s;
const COLORS = ['#6366F1','#0EA5E9','#10B981','#F43F5E','#F59E0B','#8B5CF6'];
const color = s => COLORS[[...s].reduce((a, c) => a + c.charCodeAt(0), 0) % 6];
const initial = s => (s.replace(/[^A-Za-z0-9]/g, '')[0] || '?').toUpperCase();
const addr = s => (s.match(/<([^>]+)>/) || [, s])[1];
const name = s => s.replace(/<[^>]+>/, '').replace(/"/g, '').trim() || s;
function ago(d) { const t = new Date(d).getTime(); if (isNaN(t)) return d || ''; const s = (Date.now() - t) / 1000;
  if (s < 60) return 'just now'; if (s < 3600) return Math.floor(s / 60) + 'm ago'; if (s < 86400) return Math.floor(s / 3600) + 'h ago'; return new Date(t).toLocaleDateString(); }
function skeleton() { $('list').innerHTML = Array(5).fill('<div class="mail-item"><div class="skel" style="width:40%"></div><div class="skel" style="width:80%;margin-top:10px"></div><div class="skel" style="width:60%;margin-top:8px"></div></div>').join('');
  $('view').innerHTML = '<div class="card-pad"><div class="skel" style="width:50%;height:18px"></div><div class="skel" style="width:30%;margin-top:12px"></div><div class="skel" style="height:240px;margin-top:20px"></div></div>'; }
function setConn(t, cls) { $('conn').className = 'badge ' + cls; $('conn').lastElementChild.textContent = t; }
function filtered() { const n = $('q').value.trim().toLowerCase(); return n ? msgs.filter(m => [m.from, m.subject, plain(m.message), m.code || ''].join(' ').toLowerCase().includes(n)) : msgs; }
function renderList() {
  const list = filtered(); $('count').innerHTML = msgs.length ? `<i class="fa-regular fa-envelope"></i> ${msgs.length} message${msgs.length > 1 ? 's' : ''}` : '';
  if (!msgs.length) { $('list').innerHTML = `<div class="empty"><div class="icon-box"><i class="fa-solid fa-inbox"></i></div><h2>No emails yet</h2><p class="muted small">New messages will appear here automatically.</p></div>`; $('view').innerHTML = ''; $('view').classList.add('hidden'); return; }
  $('view').classList.remove('hidden');
  if (!list.length) { $('list').innerHTML = `<div class="empty"><div class="icon-box"><i class="fa-solid fa-magnifying-glass"></i></div><h2>No results</h2><p class="muted small">Try a different search.</p></div>`; return; }
  $('lcount').textContent = list.length + ' shown'; $('list').innerHTML = list.map(m => { const un = !seen.has(m.uid);
    return `<button class="mail-item ${m.uid === sel ? 'sel' : ''} ${un ? 'unread' : ''}" data-uid="${esc(m.uid)}" aria-label="${un ? 'Unread: ' : ''}${esc(m.subject)}">
      <div class="top">${un ? '<span class="udot" aria-hidden="true"></span>' : ''}<span class="from truncate">${esc(name(m.from))}</span><span class="small muted">${esc(ago(m.date))}</span></div>
      <div class="subj truncate">${esc(m.subject)}</div><div class="prev truncate">${m.code ? `<span class="badge b-primary" style="height:20px;margin-right:6px"><i class="fa-solid fa-key"></i> ${esc(m.code)}</span>` : ''}${esc(plain(m.message).slice(0, 140))}</div></button>`; }).join('');
}
function renderView() {
  const m = msgs.find(x => x.uid === sel); if (!m) return;
  const html = isHtml(m.message);
  $('view').innerHTML = `<div class="card-head" style="align-items:flex-start"><div class="row" style="min-width:0;flex-wrap:nowrap;align-items:flex-start">
      <button class="btn btn-ghost btn-icon btn-sm back-mobile" id="back" aria-label="Back to list"><i class="fa-solid fa-arrow-left"></i></button>
      <div class="avatar" style="background:${color(m.from)}">${esc(initial(m.from))}</div>
      <div style="min-width:0"><h2 style="font-size:17px">${esc(m.subject)}</h2><div class="small"><b>${esc(name(m.from))}</b> <span class="muted">&lt;${esc(addr(m.from))}&gt;</span></div>
      <div class="small muted"><i class="fa-regular fa-calendar"></i> ${esc(isNaN(new Date(m.date)) ? m.date : new Date(m.date).toLocaleString())}</div></div></div></div>
    <div class="card-pad stack">
      ${m.code ? `<div class="code-box"><div><div class="small muted">Verification code</div><div class="code">${esc(m.code)}</div></div><button class="btn btn-primary btn-sm" data-copy="${esc(m.code)}" style="margin-left:auto"><i class="fa-regular fa-copy"></i> Copy Code</button></div>` : `<p class="small muted"><i class="fa-regular fa-circle-question"></i> No verification code detected.</p>`}
      <div class="row between"><div class="tabs" role="tablist">
        ${html ? `<button class="tab" role="tab" aria-selected="true" data-tab="html"><i class="fa-solid fa-eye"></i> HTML View</button>` : ''}
        <button class="tab" role="tab" aria-selected="${!html}" data-tab="text"><i class="fa-solid fa-align-left"></i> Text</button></div>
        <button class="btn btn-secondary btn-sm" data-copy-src><i class="fa-solid fa-code"></i> Copy Source</button></div>
      ${html ? '<iframe id="frame" class="mail-frame" title="Email content" sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe>' : ''}
      <div id="txt" class="mail-text ${html ? 'hidden' : ''}"></div></div>`;
  if (html) $('frame').srcdoc = '<base target="_blank"><style>body{font-family:Inter,system-ui,sans-serif;margin:16px}</style>' + m.message;
  $('txt').textContent = plain(m.message);
}
function select(uid) { sel = uid; seen.add(uid); localStorage.setItem('seen:' + EMAIL, JSON.stringify([...seen].slice(-300))); $('inbox').classList.add('viewing'); renderList(); renderView(); }
document.addEventListener('click', e => {
  const it = e.target.closest('.mail-item[data-uid]'); if (it) return select(it.dataset.uid);
  if (e.target.closest('#back')) { $('inbox').classList.remove('viewing'); return; }
  const tb = e.target.closest('.tab'); if (tb) { document.querySelectorAll('.tab').forEach(t => t.setAttribute('aria-selected', t === tb)); const h = tb.dataset.tab === 'html'; $('frame')?.classList.toggle('hidden', !h); $('txt').classList.toggle('hidden', h); }
  const c = e.target.closest('[data-copy]'); if (c) navigator.clipboard.writeText(c.dataset.copy).then(() => toast('Copied to clipboard'));
  if (e.target.closest('[data-copy-src]')) { const m = msgs.find(x => x.uid === sel); navigator.clipboard.writeText(m.message).then(() => toast('Copied to clipboard')); }
  if (e.target.closest('#retry')) manual();
});
function setBusy(b) { busy = b; const r = $('refresh'); r.disabled = b; r.innerHTML = b ? '<i class="fa-solid fa-spinner fa-spin"></i><span>Refreshing…</span>' : '<i class="fa-solid fa-rotate"></i><span>Refresh</span>'; }
async function load(manualCall) {
  if (busy) return; setBusy(true);
  try {
    const d = await (await fetch('api.php?email=' + encodeURIComponent(EMAIL))).json();
    if (d.rate && loaded) return;
    if (!d.ok) { setConn('Offline', 'b-danger');
      if (!loaded) { $('list').innerHTML = `<div class="empty"><div class="icon-box" style="background:var(--danger-soft);color:var(--danger)"><i class="fa-solid fa-triangle-exclamation"></i></div><h2>Connection failed</h2><p class="muted small">${esc(d.reason || 'Unable to connect to the mail server.')}</p><button id="retry" class="btn btn-secondary" style="margin-top:14px"><i class="fa-solid fa-rotate"></i> Retry</button></div>`; $('view').classList.add('hidden'); }
      else if (manualCall) toast(d.reason || 'Refresh failed', 'err'); return; }
    const first = !loaded; loaded = true; msgs = d.messages || []; lastAt = Date.now();
    $('warn').classList.toggle('hidden', !d.stale); $('warn').innerHTML = d.stale ? `<i class="fa-solid fa-triangle-exclamation"></i><div>${esc(d.warning)}</div>` : '';
    setConn(d.stale ? 'Warning' : 'Connected', d.stale ? 'b-warning' : 'b-success');
    if (!d.stale && msgs.some(m => { const t = new Date(m.date).getTime(); return !isNaN(t) && Date.now() - t < RECENT_MS; })) { if (!gotRecent && !first) toast('New mail received'); const wasAuto = $('mode').value !== '0'; gotRecent = true; $('recent').querySelector('b').textContent = wasAuto ? 'New mail received — auto refresh stopped' : 'New mail received in the last 10 minutes'; $('recent').classList.remove('hidden'); }
    if (!msgs.find(m => m.uid === sel)) sel = msgs[0]?.uid || null;
    renderList(); if (sel) renderView();
    if (manualCall) toast('Refresh complete');
  } catch (e) { setConn('Offline', 'b-danger'); if (!loaded) $('list').innerHTML = `<div class="empty"><h2>Connection failed</h2><p class="muted small">Unable to connect to the mail server.</p><button id="retry" class="btn btn-secondary" style="margin-top:14px"><i class="fa-solid fa-rotate"></i> Retry</button></div>`; else if (manualCall) toast('Network error', 'err'); }
  finally { setBusy(false); }
}
function interval() { if (gotRecent) return 0; const v = $('mode').value; return v === 'smart' ? STEPS[Math.min(step, 3)] : +v; }
function reset() { step = 0; left = interval(); }
function manual() { load(true); reset(); }
setInterval(() => {
  if (lastAt) $('upd').innerHTML = '<i class="fa-regular fa-clock"></i> Last updated: ' + ago(new Date(lastAt).toISOString());
  const iv = interval(); if (!iv) { $('next').textContent = ''; return; }
  if (--left <= 0) { load(); step++; left = interval(); }
  $('next').innerHTML = `<i class="fa-solid fa-clock-rotate-left"></i> Next refresh in ${left >= 60 ? Math.floor(left / 60) + 'm ' + (left % 60) + 's' : left + 's'}`;
}, 1000);
$('refresh').onclick = manual; $('mode').onchange = reset; $('q').oninput = renderList;
skeleton(); load(); reset();
</script>
<?php shell_end();

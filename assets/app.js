function toast(msg, type = 'ok') {
  let box = document.querySelector('.toasts');
  if (!box) { box = document.createElement('div'); box.className = 'toasts'; box.setAttribute('aria-live', 'polite'); document.body.appendChild(box); }
  const t = document.createElement('div'); t.className = 'toast ' + type;
  t.innerHTML = `<i class="fa-solid ${type === 'err' ? 'fa-circle-exclamation' : 'fa-circle-check'}"></i><span></span>`; t.querySelector('span').textContent = msg;
  box.appendChild(t); setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .2s'; setTimeout(() => t.remove(), 200); }, 2400);
}
document.addEventListener('click', e => {
  if (e.target.closest('[data-drawer]')) document.body.classList.toggle('drawer');
  if (e.target.closest('.overlay')) document.body.classList.remove('drawer');
  const pw = e.target.closest('[data-toggle-pw]');
  if (pw) { const i = document.getElementById(pw.dataset.togglePw); i.type = i.type === 'password' ? 'text' : 'password'; pw.querySelector('i').className = 'fa-regular ' + (i.type === 'password' ? 'fa-eye' : 'fa-eye-slash'); }
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.body.classList.remove('drawer'); });

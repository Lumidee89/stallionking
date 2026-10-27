/* Small behaviours shared by every staff page. Pages work without it; this only adds convenience. */
(() => {
  'use strict';

  const toastEl = document.getElementById('toast');
  window.skToast = (msg) => {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(window.skToast.t);
    window.skToast.t = setTimeout(() => toastEl.classList.remove('show'), 2200);
  };

  // Copy tracking numbers with one click.
  async function copy(text) {
    try { await navigator.clipboard.writeText(text); return true; } catch (_) {}
    const ta = Object.assign(document.createElement('textarea'), { value: text });
    ta.style.cssText = 'position:fixed;opacity:0';
    document.body.appendChild(ta); ta.select();
    const ok = document.execCommand('copy');
    ta.remove();
    return ok;
  }

  document.addEventListener('click', async (e) => {
    const c = e.target.closest('[data-copy]');
    if (c) {
      e.preventDefault(); e.stopPropagation();
      window.skToast(await copy(c.dataset.copy) ? `Copied ${c.dataset.copy}` : 'Could not copy. Select the number manually.');
      return;
    }
    const pw = e.target.closest('[data-toggle-pw]');
    if (pw) {
      const input = pw.parentElement.querySelector('input');
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      pw.textContent = show ? 'Hide' : 'Show';
      return;
    }
    // Whole table row opens the parcel, except when clicking a control inside it.
    const row = e.target.closest('tr[data-href]');
    if (row && !e.target.closest('a,button,input,select,form')) location.href = row.dataset.href;
  }, true);

  // Ask before destructive form submissions.
  document.addEventListener('submit', (e) => {
    const msg = e.target.dataset.confirm;
    if (msg && !confirm(msg)) { e.preventDefault(); return; }
    const btn = e.submitter;
    if (btn && !e.defaultPrevented) setTimeout(() => { btn.disabled = true; }, 0); // stop double submits
  });
  window.addEventListener('pageshow', (e) => {
    if (e.persisted) document.querySelectorAll('form button:disabled').forEach((b) => { b.disabled = false; });
  });

  // Dispatch: select-all, select-a-route, and the selected count.
  const countEl = document.querySelector('[data-selected-count]');
  const boxes = () => [...document.querySelectorAll('input[name="tns[]"]')];
  const recount = () => {
    if (!countEl) return;
    const n = boxes().filter((b) => b.checked).length;
    countEl.textContent = `${n} parcel${n === 1 ? '' : 's'} selected`;
  };
  document.addEventListener('change', (e) => {
    const t = e.target;
    if (t.matches('[data-check-all]')) {
      boxes().forEach((b) => { b.checked = t.checked; });
      document.querySelectorAll('[data-check-group]').forEach((g) => { g.checked = t.checked; });
    } else if (t.matches('[data-check-group]')) {
      boxes().filter((b) => b.dataset.group === t.dataset.checkGroup).forEach((b) => { b.checked = t.checked; });
    }
    if (t.matches('[data-check-all],[data-check-group],input[name="tns[]"]')) recount();
  });

  // Dispatch: "Report issue" dialog.
  const dlg = document.getElementById('reportDialog');
  if (dlg) {
    document.querySelectorAll('[data-report]').forEach((b) => b.addEventListener('click', () => {
      document.getElementById('reportForm').action = b.dataset.report;
      dlg.querySelector('[data-report-tn]').textContent = `Tracking number ${b.dataset.tn}`;
      dlg.showModal();
    }));
    dlg.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => dlg.close()));
  }

  // Lagos clock in the top bar.
  const clock = document.querySelector('[data-clock]');
  if (clock) {
    const fmt = () => new Date().toLocaleString('en-GB', { timeZone: 'Africa/Lagos', weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) + ' WAT';
    clock.textContent = fmt();
    setInterval(() => { clock.textContent = fmt(); }, 30000);
  }
})();

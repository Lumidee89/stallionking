/* Stallionking warehouse: QR + document scanning and resilient booking.
 * Heavy libraries (jsQR, Tesseract.js) load only when first needed, so the
 * booking form stays fast on weak phones and slow networks. */
(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const csrf = document.querySelector('meta[name=csrf-token]').content;

  const LIBS = {
    jsqr: 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js',
    tesseract: 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js',
  };
  // Which form field each part of a scanned document goes into.
  const MAPS = {
    fields: { name: 'receiver_name', phone: 'receiver_phone', email: 'receiver_email', address: 'receiver_address', city: 'receiver_city', state: 'receiver_state', landmark: 'receiver_landmark' },
    sender: { name: 'sender_name', phone: 'sender_phone', email: 'sender_email', city: 'sender_city' },
    package: { description: 'description', weight_range: 'weight_range', service_type: 'service_type' },
  };
  const FIELDS = Object.values(MAPS).flatMap((m) => Object.values(m));

  const dlg = $('scanDialog'), video = $('scanVideo'), canvas = $('scanCanvas'), ctx = canvas.getContext('2d', { willReadFrequently: true });
  const statusEl = $('scanStatus'), progress = $('scanProgress');
  let stream = null, raf = 0, mode = null, busy = false, idemKey = crypto.randomUUID();
  const loaded = {};

  // ---------- helpers ----------
  const say = (m) => { statusEl.textContent = m; };
  function notice(kind, msg) {
    const n = $('formNotice');
    n.innerHTML = '';
    if (!msg) return;
    const d = document.createElement('div');
    d.className = 'notice ' + kind;
    d.textContent = msg; // textContent: scanned/server text is never injected as HTML
    n.appendChild(d);
    n.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }
  function loadScript(key) {
    if (loaded[key]) return loaded[key];
    return (loaded[key] = new Promise((res, rej) => {
      const s = document.createElement('script');
      s.src = LIBS[key]; s.crossOrigin = 'anonymous'; s.referrerPolicy = 'no-referrer';
      s.onload = res; s.onerror = () => { delete loaded[key]; rej(new Error('lib')); };
      document.head.appendChild(s);
    }));
  }
  async function post(url, body, signalMs = 20000) {
    const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), signalMs);
    try {
      return await fetch(url, {
        method: 'POST', signal: ctl.signal, credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', ...(body.__headers || {}) },
        body: JSON.stringify(body.data),
      });
    } finally { clearTimeout(t); }
  }
  function httpMessage(res, json) {
    if (res.status === 419) return 'Your session expired. Refresh the page and try again.';
    if (res.status === 401) return 'You were signed out. Sign in again to continue.';
    if (res.status === 403) return 'Your account is not allowed to do this.';
    if (res.status === 429) return 'Too many attempts. Wait a moment and try again.';
    if (res.status >= 500) return 'Something went wrong on our side. Try again' + (json?.reference ? ` (reference ${json.reference}).` : '.');
    return json?.message || 'Request failed.';
  }

  // ---------- camera lifecycle (always released; prevents stuck camera + battery drain) ----------
  function stopCamera() {
    cancelAnimationFrame(raf); raf = 0;
    if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; }
    video.srcObject = null;
  }
  function closeDialog() { stopCamera(); mode = null; busy = false; progress.hidden = true; if (dlg.open) dlg.close(); }
  async function startCamera() {
    if (!window.isSecureContext) throw new Error('insecure');
    if (!navigator.mediaDevices?.getUserMedia) throw new Error('nocamera');
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
    video.srcObject = stream; video.hidden = false;
    await video.play();
  }
  function cameraError(e) {
    const m = {
      insecure: 'Camera scanning needs a secure (HTTPS) connection.',
      nocamera: 'This device has no usable camera.',
      NotAllowedError: 'Camera permission was blocked. Allow camera access in your browser settings, or use a photo instead.',
      NotFoundError: 'No camera found on this device.',
      NotReadableError: 'The camera is being used by another app. Close it and try again.',
    };
    return m[e.message] || m[e.name] || 'The camera could not be started.';
  }

  // ---------- QR scanning ----------
  async function openQr() {
    mode = 'qr'; $('scanTitle').textContent = 'Scan receiver QR code';
    $('btnCapture').hidden = true; $('btnUploadWrap').hidden = true; $('ocrPreview').hidden = true;
    dlg.showModal(); say('Starting camera…');
    try {
      await startCamera();
      let detector = null;
      if ('BarcodeDetector' in window) {
        try { const f = await BarcodeDetector.getSupportedFormats(); if (f.includes('qr_code')) detector = new BarcodeDetector({ formats: ['qr_code'] }); } catch (_) {}
      }
      if (!detector) { say('Loading scanner…'); await loadScript('jsqr'); }
      say('Point the camera at the QR code.');
      let last = 0;
      const tick = async (now) => {
        if (mode !== 'qr' || !stream) return;
        raf = requestAnimationFrame(tick);
        if (now - last < 120 || busy || video.readyState < 2) return; // ~8 scans/sec max
        last = now;
        let text = null;
        try {
          if (detector) { const r = await detector.detect(video); text = r[0]?.rawValue || null; }
          else {
            const w = Math.min(video.videoWidth, 640), h = Math.round(w * video.videoHeight / video.videoWidth);
            canvas.width = w; canvas.height = h; ctx.drawImage(video, 0, 0, w, h);
            text = window.jsQR(ctx.getImageData(0, 0, w, h).data, w, h, { inversionAttempts: 'dontInvert' })?.data || null;
          }
        } catch (_) { /* a failed frame must never crash the loop */ }
        if (text) { busy = true; stopCamera(); await submitScan(text, 'qr'); }
      };
      raf = requestAnimationFrame(tick);
    } catch (e) {
      say(e.message === 'lib' ? 'The scanner could not load (check your connection). Type the details instead.' : cameraError(e));
    }
  }

  // ---------- Label text (OCR) ----------
  async function openOcr() {
    mode = 'ocr'; $('scanTitle').textContent = 'Scan document';
    $('ocrPreview').hidden = true; $('btnUploadWrap').hidden = false; $('btnCapture').hidden = true; $('btnCapture').disabled = false;
    dlg.showModal(); say('Starting camera…');
    try {
      await startCamera(); $('btnCapture').hidden = false;
      say('Fill the frame with the document, hold steady, then press Capture.');
    } catch (e) { stopCamera(); say(cameraError(e) + ' You can take or choose a photo below.'); }
  }
  function toCanvas(source, sw, sh) {
    // Text must be big enough to read: enlarge small images, but cap big phone photos (speed, memory).
    const longest = Math.max(sw, sh);
    const scale = longest < 1400 ? Math.min(2.5, 1800 / longest) : Math.min(1, 2400 / longest);
    canvas.width = Math.round(sw * scale); canvas.height = Math.round(sh * scale);
    ctx.filter = 'grayscale(1) contrast(1.4)';
    ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
    ctx.filter = 'none';
  }
  // fillOnly: a QR code on the same photo already filled some fields; only add what is still empty.
  async function runOcr({ fillOnly = false, prior = 0 } = {}) {
    if (busy) return; busy = true;
    $('btnCapture').disabled = true; progress.hidden = false; progress.value = 0;
    let worker;
    try {
      say('Loading text reader…'); await loadScript('tesseract');
      worker = await Tesseract.createWorker('eng', 1, { logger: (m) => { if (m.status === 'recognizing text') { progress.value = m.progress; say('Reading document…'); } } });
      await worker.setParameters({ preserve_interword_spaces: '1' }); // keeps form columns apart
      const result = await Promise.race([
        worker.recognize(canvas),
        new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), 45000)),
      ]);
      const text = (result.data.text || '').trim();
      if (text.length < 8) throw new Error('empty');
      await submitScan(text, 'ocr', { fillOnly, prior });
    } catch (e) {
      busy = false;
      if (prior) { // the QR code already filled some fields; keep those
        closeDialog();
        notice('warn', `Filled ${prior} field${prior > 1 ? 's' : ''} from the QR code, but the printed text could not be read. Check and complete the rest.`);
        return;
      }
      say(e.message === 'timeout' ? 'Reading took too long. Retake the photo closer and in better light.'
        : e.message === 'empty' ? 'No readable text found. Retake the photo closer and in better light.'
        : e.message === 'lib' ? 'The text reader could not load (check your connection). Type the details instead.'
        : 'We could not read that document. Try again or type the details.');
      $('btnCapture').disabled = false; progress.hidden = true;
    } finally { if (worker) worker.terminate().catch(() => {}); }
  }

  // ---------- send scanned TEXT to the server for parsing, then fill the form ----------
  // thenReadText: on a photo, also read the printed text when the QR code lacks the receiver's name or address.
  async function submitScan(text, source, { fillOnly = false, prior = 0, thenReadText = false } = {}) {
    say('Reading details…');
    try {
      const res = await post(SK.parseUrl, { data: { text: text.slice(0, 20000), source } });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) {
        busy = false;
        if (thenReadText && res.status === 422) { say('The QR code had no receiver details. Reading the printed text…'); return runOcr(); }
        if (prior) { closeDialog(); notice('warn', `Filled ${prior} field${prior > 1 ? 's' : ''} from the QR code. Check and complete the rest.`); return; }
        say(res.status === 422 ? 'No receiver details were found. Make sure the name and address are sharp and well lit, then try again, or type them in.' : httpMessage(res, json));
        $('btnCapture').disabled = false; if (mode === 'qr') openQr(); return;
      }
      const filled = prior + applyFields(json, source, fillOnly);
      if (thenReadText && (!json.fields?.name || !json.fields?.address)) {
        busy = false; say('QR code read. Now reading the printed text for the rest…');
        return runOcr({ fillOnly: true, prior: filled });
      }
      closeDialog();
      const missing = ['receiver_name', 'receiver_address'].filter((id) => !$(id).value.trim()).map((id) => $(id).previousElementSibling.textContent.toLowerCase());
      notice(filled && !missing.length ? 'ok' : 'warn', (filled ? `Filled ${filled} field${filled > 1 ? 's' : ''} from the scan.` : 'The scan had no new details to add.')
        + (missing.length ? ` We could not read the receiver's ${missing.join(' or ')}; please type ${missing.length > 1 ? 'them' : 'it'} in.` : ' Check them before booking.'));
    } catch (e) {
      busy = false;
      say(e.name === 'AbortError' ? 'The server took too long. Try again.' : 'You appear to be offline. Check your connection and try again.');
    }
  }
  function applyFields(json, source, fillOnly = false) {
    let incoming = []; // [field id, value]
    for (const [group, map] of Object.entries(MAPS)) {
      for (const [k, id] of Object.entries(map)) if (json[group]?.[k]) incoming.push([id, json[group][k]]);
    }
    if (fillOnly) incoming = incoming.filter(([id]) => !$(id).classList.contains('scanned') && ($(id).tagName === 'SELECT' || !$(id).value.trim()));
    const conflicts = incoming.filter(([id, val]) => $(id).tagName !== 'SELECT' && $(id).value.trim() && $(id).value.trim() !== val);
    if (conflicts.length && !fillOnly && !confirm('Replace the details you already typed with the scanned ones?')) return 0;
    for (const [id, val] of incoming) { const el = $(id); el.value = val; el.classList.add('scanned'); el.classList.remove('invalid'); }
    if (incoming.some(([id]) => id.startsWith('receiver_'))) $('receiver_source').value = source;
    if (incoming.some(([id]) => id.startsWith('sender_'))) $('client_id').value = ''; // scanned sender replaces a picked client
    return incoming.length;
  }
  FIELDS.forEach((id) => ['input', 'change'].forEach((ev) => $(id).addEventListener(ev, (e) => e.target.classList.remove('scanned'))));

  // ---------- booking submit (idempotent, double-click safe) ----------
  $('client_id').addEventListener('change', (e) => {
    const o = e.target.selectedOptions[0]; if (!o?.value) return;
    $('sender_name').value = o.dataset.name || ''; $('sender_phone').value = o.dataset.phone || '';
    $('sender_city').value = o.dataset.city || ''; $('sender_email').value = o.dataset.email || '';
  });
  if ($('client_id').value) $('client_id').dispatchEvent(new Event('change')); // arrived via "Use for new parcel"

  // "+ Save as frequent sender"
  $('btnSaveSender').addEventListener('click', async (e) => {
    const btn = e.currentTarget, v = (id) => $(id).value.trim();
    if (!v('sender_name') || !v('sender_phone') || !v('sender_city')) { notice('warn', "Fill in the sender's name, phone and city first."); return; }
    btn.disabled = true;
    try {
      const res = await post(SK.clientUrl, { data: { name: v('sender_name'), phone: v('sender_phone'), email: v('sender_email') || null, city: v('sender_city') } });
      const json = await res.json().catch(() => ({}));
      if (res.ok) {
        const o = new Option(`${json.name} — ${v('sender_city')}`, json.id, true, true);
        Object.assign(o.dataset, { name: v('sender_name'), phone: v('sender_phone'), email: v('sender_email'), city: v('sender_city') });
        $('client_id').add(o);
        notice('ok', `Saved ${json.name} as a frequent sender.`);
      } else notice(res.status === 409 ? 'warn' : 'bad', json.errors ? Object.values(json.errors)[0][0] : httpMessage(res, json));
    } catch (_) { notice('bad', 'You appear to be offline. Try again.'); }
    finally { btn.disabled = false; }
  });

  // Result panel shown after a parcel is booked.
  function showResult(json) {
    const box = $('bookingResult'); box.innerHTML = '';
    const card = document.createElement('div'); card.className = 'card result';
    const h = document.createElement('p'); h.className = 'eyebrow'; h.textContent = 'Parcel booked';
    const tn = document.createElement('button'); tn.type = 'button'; tn.className = 'tn copy'; tn.dataset.copy = json.tracking_number; tn.textContent = json.tracking_number; tn.title = 'Copy tracking number';
    const open = document.createElement('a'); open.className = 'btn small'; open.href = `${SK.parcelUrl}/${encodeURIComponent(json.tracking_number)}`; open.textContent = 'Open parcel'; open.style.marginLeft = '12px';
    const line = document.createElement('div'); line.append(tn, open);
    const p = document.createElement('p'); p.className = 'hint small'; p.style.margin = '10px 0 0';
    const list = document.createElement('ul');
    if (!json.notifications.length) p.textContent = json.sender_skipped
      ? 'No receiver email was entered, so nobody was emailed. You can email the tracking ID later from the parcel page.'
      : 'No sender or receiver email was entered, so nobody was emailed.';
    else {
      p.textContent = 'Emails:' + (json.sender_skipped ? ' (the sender was not emailed)' : '');
      for (const n of json.notifications) {
        const li = document.createElement('li');
        li.textContent = `${n.recipient_role === 'Receiver' ? 'Receiver (parcel details)' : 'Sender (tracking ID)'} · ${n.to} — ${n.delivered ? 'sent' : 'failed: ' + n.error}`;
        list.appendChild(li);
      }
    }
    card.append(h, line, p, list); box.appendChild(card);
    box.scrollIntoView({ block: 'start', behavior: 'smooth' });
  }
  $('bookForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.submitter || $('bookForm').querySelector('[data-send="1"]'); if (btn.disabled) return;
    const label = btn.textContent, wantsSend = btn.dataset.send !== '0';
    const buttons = $('bookForm').querySelectorAll('button[type=submit]');
    document.querySelectorAll('[data-err]').forEach((x) => (x.textContent = ''));
    document.querySelectorAll('.invalid').forEach((x) => x.classList.remove('invalid'));
    notice('', ''); buttons.forEach((b) => { b.disabled = true; }); btn.textContent = wantsSend ? 'Saving & sending…' : 'Saving…';
    const v = (id) => $(id).value.trim();
    const data = {
      sender: { name: v('sender_name'), phone: v('sender_phone'), email: v('sender_email') || null, city: v('sender_city') },
      client_id: v('client_id') || null,
      receiver_name: v('receiver_name'), receiver_phone: v('receiver_phone'), receiver_email: v('receiver_email') || null,
      receiver_address: v('receiver_address'), receiver_city: v('receiver_city'), receiver_state: v('receiver_state') || null,
      receiver_landmark: v('receiver_landmark') || null, weight_range: v('weight_range'), receiver_source: v('receiver_source'),
      description: v('description'), service_type: v('service_type'), send_notification: wantsSend,
    };
    try {
      const res = await post(SK.storeUrl, { data, __headers: { 'Idempotency-Key': idemKey } });
      const json = await res.json().catch(() => ({}));
      if (res.status === 422 && json.errors) {
        for (const [k, msgs] of Object.entries(json.errors)) {
          const slot = document.querySelector(`[data-err="${k}"]`); if (slot) slot.textContent = msgs[0];
          const field = slot?.previousElementSibling; if (field) field.classList.add('invalid');
        }
        notice('bad', 'Fix the highlighted fields and try again.');
      } else if (!res.ok) { notice('bad', httpMessage(res, json)); }
      else {
        showResult(json);
        $('bookForm').reset(); $('receiver_source').value = 'manual';
        document.querySelectorAll('.scanned').forEach((x) => x.classList.remove('scanned'));
        idemKey = crypto.randomUUID(); // a fresh key only after a confirmed success
      }
    } catch (err) {
      // Network failure: keep the SAME key so a retry can never create a duplicate parcel.
      notice('bad', err.name === 'AbortError' ? 'The server took too long. Press save again; it will not create a duplicate.' : 'You appear to be offline. Press save again when you are back online; it will not create a duplicate.');
    } finally { buttons.forEach((b) => { b.disabled = false; }); btn.textContent = label; }
  });

  // ---------- wiring ----------
  $('btnQr').addEventListener('click', openQr);
  $('btnOcr').addEventListener('click', openOcr);
  $('scanClose').addEventListener('click', closeDialog);
  dlg.addEventListener('cancel', (e) => { e.preventDefault(); closeDialog(); });
  $('btnCapture').addEventListener('click', () => {
    if (!video.videoWidth) return;
    toCanvas(video, video.videoWidth, video.videoHeight);
    $('ocrPreview').src = canvas.toDataURL('image/jpeg', 0.7); $('ocrPreview').hidden = false; video.hidden = true;
    stopCamera(); runOcr();
  });
  // A photo of a document: if it carries a QR code use that, otherwise read its text.
  async function readPhoto(f) {
    if (f.size > 12 * 1024 * 1024) { say('That photo is too large. Choose one under 12 MB.'); return; }
    let bmp;
    try { bmp = await createImageBitmap(f); } catch (_) { say('That file could not be read as an image. Use a JPG or PNG photo.'); return; }
    stopCamera(); video.hidden = true;
    let qr = null;
    try {
      if ('BarcodeDetector' in window) qr = (await new BarcodeDetector({ formats: ['qr_code'] }).detect(bmp))[0]?.rawValue || null;
      if (!qr) {
        await loadScript('jsqr');
        const scale = Math.min(1, 1200 / Math.max(bmp.width, bmp.height));
        canvas.width = Math.round(bmp.width * scale); canvas.height = Math.round(bmp.height * scale);
        ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height);
        qr = window.jsQR(ctx.getImageData(0, 0, canvas.width, canvas.height).data, canvas.width, canvas.height)?.data || null;
      }
    } catch (_) { /* no QR reader available: fall through to text */ }
    toCanvas(bmp, bmp.width, bmp.height); bmp.close?.();
    $('ocrPreview').src = canvas.toDataURL('image/jpeg', 0.7); $('ocrPreview').hidden = false;
    if (qr) { busy = true; await submitScan(qr, 'qr', { thenReadText: true }); } else runOcr();
  }
  $('fileInput').addEventListener('change', (e) => {
    const f = e.target.files[0]; e.target.value = '';
    if (f) readPhoto(f);
  });
  $('docUpload').addEventListener('change', (e) => {
    const f = e.target.files[0]; e.target.value = '';
    if (!f) return;
    mode = 'ocr'; $('scanTitle').textContent = 'Read document photo';
    $('btnCapture').hidden = true; $('btnUploadWrap').hidden = false; $('btnCapture').disabled = false;
    video.hidden = true; dlg.showModal(); say('Reading photo…');
    readPhoto(f);
  });
  // Release the camera whenever the page is hidden or left.
  document.addEventListener('visibilitychange', () => { if (document.hidden && dlg.open) closeDialog(); });
  window.addEventListener('pagehide', stopCamera);
})();

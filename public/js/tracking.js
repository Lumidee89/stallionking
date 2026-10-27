// Homepage shipment tracker: talks to the Laravel /track endpoint (public, rate-limited, non-sensitive data only).
(function () {
  var form = document.getElementById('tracking-form');
  var input = document.getElementById('track-num');
  var out = document.getElementById('track-res');
  var btn = document.getElementById('track-submit');
  if (!form || !input || !out) return;

  var url = (window.SK_TRACK && window.SK_TRACK.lookupUrl) || '/track';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function box(html) { out.innerHTML = '<div class="track-res">' + html + '</div>'; }
  function busy(on) {
    out.setAttribute('aria-busy', on ? 'true' : 'false');
    if (btn) btn.disabled = on;
  }

  function render(d) {
    var steps = (d.steps || []).map(function (s) {
      var cls = s.state === 'done' ? 'done' : s.state === 'active' ? 'active' : '';
      return '<li class="' + cls + '"><span class="dot"></span><span>' + esc(s.label) +
        (s.at ? ' <small style="color:var(--text-3);font-weight:400">&middot; ' + esc(s.at) + '</small>' : '') +
        '</span></li>';
    }).join('');
    var head = '<p class="track-id">Tracking number <b>' + esc(d.tracking_number) + '</b> &middot; ' +
      '<b style="color:var(--o)">' + esc(d.status_label) + '</b>' +
      (d.destination ? '<br>Destination: <b>' + esc(d.destination) + '</b>' : '') +
      (d.service ? ' &middot; ' + esc(d.service) + ' service' : '') + '</p>';
    var warn = d.exception
      ? '<p class="quote-note" style="margin-bottom:var(--s4)">There was a problem delivering this shipment' +
        (d.exception_reason ? ': <b>' + esc(d.exception_reason) + '</b>' : '') +
        '. Our team will contact the receiver, or call 08101233538 for help.</p>'
      : '';
    box(head + warn + '<ol class="track-steps">' + steps + '</ol>');
  }

  function track(tn) {
    tn = String(tn || '').trim().toUpperCase();
    if (!tn) return;
    busy(true);
    box('<p class="track-id">Looking up <b>' + esc(tn) + '</b>&hellip;</p>');
    fetch(url + '?tn=' + encodeURIComponent(tn), { headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, body: j }; });
      })
      .then(function (r) {
        if (r.status === 200 && r.body.found) return render(r.body);
        if (r.status === 404) return box('<p class="track-id">No shipment found for <b>' + esc(tn) + '</b>. Check the number on your receipt (e.g. STK-7KQ2M9XD) and try again.</p>');
        if (r.status === 422) return box('<p class="track-id">Please enter a valid tracking number.</p>');
        if (r.status === 429) return box('<p class="track-id">Too many lookups. Wait a minute and try again.</p>');
        box('<p class="track-id">' + esc(r.body.message || 'Tracking is unavailable right now. Please try again shortly.') + '</p>');
      })
      .catch(function () { box('<p class="track-id">You appear to be offline. Check your connection and try again.</p>'); })
      .then(function () { busy(false); });
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); track(input.value); });

  // Shared links like /?tn=STK-XXXX#track look the parcel up straight away.
  var q = new URLSearchParams(location.search).get('tn');
  if (q) { input.value = q; track(q); }
})();

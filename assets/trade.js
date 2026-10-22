(function () {
  const root = document.querySelector('[data-hbc-trade-active]');
  if (!root || !window.HBC_LAUGH) {
    return;
  }

  const out = document.querySelector('#hbc-trade-result');
  const btn = document.querySelector('#hbc-trade-offer');
  if (!out || !btn) {
    return;
  }

  const eventUuid = root.getAttribute('data-event-uuid') || '';

  btn.addEventListener('click', async function () {
    out.className = '';
    out.innerHTML = '<p>Starting Trade encounter…</p>';
    btn.disabled = true;

    if (!eventUuid) {
      out.innerHTML = '<p class="hbc-error">No community event is configured for Trade offers. Set it in WP Human Gold admin.</p>';
      btn.disabled = false;
      return;
    }

    let token = '';
    try {
      token = localStorage.getItem('hbc_device_token') || '';
    } catch (e) {
      token = '';
    }
    if (!token) {
      out.innerHTML = '<p class="hbc-error">This browser is not a recognized Human Gold device. Open <a href="/r">/r</a>, register or restore this device, then return here to offer Trade.</p>';
      btn.disabled = false;
      return;
    }

    try {
      const res = await fetch(HBC_LAUGH.rest + 'public/trade/offer', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': HBC_LAUGH.nonce,
          'X-HBC-Device-Token': token,
        },
        body: JSON.stringify({ event_uuid: eventUuid }),
      });
      const json = await res.json().catch(function () {
        return {};
      });
      if (!res.ok) {
        const msg =
          json.error && json.error.message
            ? json.error.message
            : 'Trade offer denied.';
        out.innerHTML = '<p class="hbc-error">' + msg + '</p>';
        btn.disabled = false;
        return;
      }
      const d = json.data || {};
      out.innerHTML =
        '<p><strong>Trade encounter issued.</strong></p>' +
        (d.identity_qr_image_url
          ? '<p class="hbc-qr-box"><img alt="Encounter QR" src="' +
            d.identity_qr_image_url +
            '"/></p>'
          : '') +
        '<p>Show this QR to the attendee. Expires: ' +
        (d.expires_at || '') +
        '</p>' +
        (d.encounter_url
          ? '<p><a class="hbc-button hbc-secondary" href="' +
            d.encounter_url +
            '">Open encounter link</a></p>'
          : '');
    } catch (e) {
      out.innerHTML = '<p class="hbc-error">Could not start Trade.</p>';
      btn.disabled = false;
    }
  });
})();

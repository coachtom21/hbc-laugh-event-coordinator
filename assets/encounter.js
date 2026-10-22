(() => {
  'use strict';

  const root = document.querySelector('[data-hbc-encounter]');
  if (!root) return;

  const content = document.querySelector('#hbc-content');
  const status = document.querySelector('#hbc-status');
  const token = root.dataset.encounterToken;

  // Same device-token storage convention as hbc.js. This page is opened by
  // the ATTENDEE's device (they scanned the operator's Identity QR), and they
  // must already be a registered device from this event's RSVP flow —
  // accepting XP requires the server to know who is confirming.
  const DEVICE_TOKEN_KEY = 'hbc_device_token';
  const RETURN_TO_ENCOUNTER_KEY = 'hbc_return_to_encounter';
  const getDeviceToken = () => {
    try { return localStorage.getItem(DEVICE_TOKEN_KEY) || ''; } catch (e) { return ''; }
  };

  const api = async (path, options = {}) => {
    const headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': HBC_LAUGH.nonce, ...(options.headers || {}) };
    const deviceToken = getDeviceToken();
    if (deviceToken) headers['X-HBC-Device-Token'] = deviceToken;

    const response = await fetch(HBC_LAUGH.rest + path, { credentials: 'same-origin', ...options, headers });
    let body = null;
    if (response.status !== 204) {
      const text = await response.text();
      if (text) { try { body = JSON.parse(text); } catch (e) { body = null; } }
    }
    if (!response.ok) {
      const message = body && body.error && body.error.message ? body.error.message : `Please try again. (${response.status})`;
      const error = new Error(message);
      error.code = body && body.error ? body.error.code : null;
      error.status = response.status;
      throw error;
    }
    return body;
  };

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const formatDateTime = (isoString) => {
    if (!isoString) return '';
    try { return new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(new Date(isoString)); }
    catch (e) { return isoString; }
  };

  const showError = (message) => {
    const existing = content.querySelector('.hbc-error');
    if (existing) existing.remove();
    const p = document.createElement('p');
    p.className = 'hbc-error';
    p.textContent = message;
    content.prepend(p);
  };

  // Pilot-grade proof of presence: accepting requires a fresh reading with browser-
  // reported accuracy of 50 meters or better. The server performs the final,
  // conservative two-device distance check; the client never declares proximity.
  const tryGetLocation = () => new Promise((resolve) => {
    if (!('geolocation' in navigator)) { resolve(null); return; }
    navigator.geolocation.getCurrentPosition(
      (position) => resolve({
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy_meters: position.coords.accuracy,
        captured_at: new Date(position.timestamp || Date.now()).toISOString(),
      }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
    );
  });

  // --- terminal / non-terminal state messaging -------------------------------

  const STATE_MESSAGES = {
    declined: { title: 'Encounter Declined', body: 'This encounter was closed. Nothing negative is recorded against you for declining.' },
    walked_away: { title: 'You Walked Away', body: 'No record was created. Walking away never creates a negative reputation or XP entry.' },
    expired: { title: 'This Encounter Expired', body: 'The five-minute window closed before both devices confirmed. Ask the operator to initiate a new encounter.' },
    proximity_failed: { title: 'Proximity Could Not Be Verified', body: 'Both devices need to be near each other (about 50 meters) to complete this encounter. Please stay together and try again.' },
    same_device_rejected: { title: 'This Device Can’t Confirm Its Own Encounter', body: 'The device that initiated this encounter can’t also be the one confirming it. Please scan from the attendee’s own device.' },
    disputed: { title: 'This Encounter Is Disputed', body: 'The organizer will review this encounter before it is finalized.' },
    extinguished: { title: 'Pending XP Extinguished', body: 'You walked away from this event’s RSVP. Pending XP for this encounter was voided. Matured XP is unchanged.' },
  };

  const renderTerminal = (state) => {
    const info = STATE_MESSAGES[state] || { title: 'This Encounter Is Unavailable', body: 'Please ask the operator to initiate a new encounter.' };
    content.innerHTML = `<h2>${esc(info.title)}</h2><p>${esc(info.body)}</p>`;
  };

  const confirmationDetails = (info) => {
    const id = info.confirmation_id ? `<p><strong>Confirmation:</strong> ${esc(info.confirmation_id)}</p>` : '';
    const when = info.confirmed_at ? `<p><strong>Confirmed:</strong> ${esc(formatDateTime(info.confirmed_at))}</p>` : '';
    const proximity = info.proximity_assurance ? `<p><strong>Proximity assurance:</strong> ${esc(info.proximity_assurance)}</p>` : '';
    const touchstone = info.touchstone_word ? `<p><strong>Touchstone:</strong> ${esc(info.touchstone_word)}</p>` : '';
    const branch = info.peace_pentagon_branch ? `<p><strong>Peace Pentagon branch:</strong> ${esc(info.peace_pentagon_branch)}</p>` : '';
    const rsvp = info.rsvp_response ? `<p><strong>RSVP:</strong> ${esc(info.rsvp_response === 'participate' ? 'Participate' : 'Observe')}</p>` : '';
    return id + when + rsvp + touchstone + branch + proximity;
  };

  const renderConfirmed = (info = {}) => {
    const xp = info.xp_play_dough_status === 'issued'
      ? '<p><strong>1 sextillion XP Play Dough received.</strong></p>'
      : '<p><strong>1 sextillion XP Play Dough approved—ledger posting pending.</strong></p>';
    content.innerHTML = '<h2>LAUGH Event Confirmed</h2>' + xp + '<p>XP Play Dough is Experience Presence recognition. It is not money, cryptocurrency, wages, credit, or a measure of your worth.</p>' + confirmationDetails(info);
  };

  const renderWaitingOnOperator = (info) => {
    status.textContent = info.organization_name || info.event_name || 'Human Gold';
    content.innerHTML = `<h2>Your Part Is Complete</h2><p>Your Y/Y/Y acceptance is recorded. The Host or Greeter can finish the encounter from their device.</p><p><strong>You may close this page.</strong> Keeping it open will show the result automatically. If you close it, Human Gold will send the confirmed result—or tell you if the five-minute window expires without completion—through an eligible contact channel.</p>`;
    pollForFinalState();
  };

  // The attendee's confirm on its own only ever reaches attendee_confirmed —
  // it can never complete the encounter by itself. Poll the same read-only
  // GET /encounters/{token} (never a mutating call) until the operator's own
  // confirm resolves it one way or the other, or the five-minute window closes.
  let pollTimer = null;
  const pollForFinalState = () => {
    if (pollTimer) return;
    pollTimer = setInterval(async () => {
      try {
        const r = await api('encounters/' + encodeURIComponent(token));
        const state = r.data.state;
        if (state === 'confirmed' || state === 'pending_xp') {
          clearInterval(pollTimer); pollTimer = null;
          renderConfirmed(r.data);
        } else if (state && state !== 'attendee_confirmed' && state !== 'operator_confirmed' && state !== 'proximity_verified') {
          clearInterval(pollTimer); pollTimer = null;
          renderTerminal(state);
        }
      } catch (err) {
        // A transient network error should not interrupt polling; a hard
        // expiry (410) will naturally stop mattering once the window closes.
      }
    }, 3000);
  };

  const gatewayLabel = (type) => {
    if (type === 'trade') return 'Trade';
    if (type === 'presence') return 'Presence Acknowledged';
    return 'Gratitude';
  };

  const renderYesNoQuestions = (info) => {
    status.textContent = info.organization_name || info.event_name || 'Human Gold';
    const gateway = gatewayLabel(info.encounter_type);
    content.innerHTML = `
      <h2>${esc(info.event_name || 'Confirm This Encounter')}</h2>
      <p>Answer honestly. This expires at ${esc(formatDateTime(info.expires_at))}.</p>
      <form id="hbc-encounter-form">
        <fieldset class="hbc-grid">
          <label class="hbc-choice"><input type="checkbox" name="encounter_happened" required> Yes — this encounter happened.</label>
          <label class="hbc-choice"><input type="checkbox" name="gateway_correct" required> Yes — ${esc(gateway)} is the correct gateway for this LAUGH Event.</label>
          <label class="hbc-choice"><input type="checkbox" name="accept_presence_record" required> Yes — I accept the Experience Presence record and eligible XP Play Dough.</label>
        </fieldset>
        <div class="hbc-actions">
          <button class="hbc-button" type="submit" id="hbc-accept">Accept</button>
          <button class="hbc-button hbc-secondary" type="button" id="hbc-decline">Decline</button>
          <button class="hbc-button hbc-secondary" type="button" id="hbc-walk-away">Walk Away</button>
        </div>
      </form>`;

    const submit = async (action, answers) => {
      try {
        const location = action === 'accept' ? await tryGetLocation() : null;
        if (action === 'accept' && (!location || location.accuracy_meters > 50)) {
          throw new Error('To accept, enable precise location and stay near the operator. A fresh reading accurate to 50 meters or better is required.');
        }
        const result = await api(`public/encounters/${encodeURIComponent(token)}/attendee-confirm`, {
          method: 'POST',
          body: JSON.stringify({ action, answers, location }),
        });
        const state = result.data.state;
        if (state === 'attendee_confirmed') {
          renderWaitingOnOperator({ ...info, ...result.data });
        } else {
          renderTerminal(state);
        }
      } catch (err) {
        showError(err.message);
      }
    };

    document.querySelector('#hbc-encounter-form').onsubmit = (e) => {
      e.preventDefault();
      const form = new FormData(e.currentTarget);
      submit('accept', {
        encounter_happened: form.get('encounter_happened') === 'on',
        gateway_correct: form.get('gateway_correct') === 'on',
        accept_presence_record: form.get('accept_presence_record') === 'on',
      });
    };
    document.querySelector('#hbc-decline').onclick = () => submit('decline', null);
    document.querySelector('#hbc-walk-away').onclick = () => submit('walk_away', null);
  };

  const renderRegisterFirst = (info) => {
    status.textContent = info.organization_name || info.event_name || 'Human Gold';
    try { sessionStorage.setItem(RETURN_TO_ENCOUNTER_KEY, token); } catch (e) { /* storage unavailable: fall back to manual return */ }
    content.innerHTML = `<h2>${esc(info.event_name || 'Human Gold')}</h2><p>This device isn’t recognized yet. Register through this event’s Human Gold QR first, then you’ll be brought back here to confirm.</p>${info.respond_url ? `<p><a class="hbc-button" href="${esc(info.respond_url)}">Go to Event RSVP</a></p>` : ''}`;
  };

  const render = (info) => {
    if (STATE_MESSAGES[info.state] || info.state === 'confirmed' || info.state === 'pending_xp') {
      status.textContent = info.organization_name || info.event_name || 'Human Gold';
      if (info.state === 'confirmed' || info.state === 'pending_xp') { renderConfirmed(info); return; }
      renderTerminal(info.state);
      return;
    }
    if (info.state === 'attendee_confirmed' || info.state === 'operator_confirmed' || info.state === 'proximity_verified') {
      renderWaitingOnOperator(info);
      return;
    }
    // state === 'pending': this device has not yet answered.
    if (!getDeviceToken()) {
      renderRegisterFirst(info);
      return;
    }
    renderYesNoQuestions(info);
  };

  status.textContent = 'Loading…';
  api('encounters/' + encodeURIComponent(token))
    .then((r) => render(r.data))
    .catch((err) => {
      status.textContent = 'This encounter is unavailable';
      showError(err.message);
    });
})();

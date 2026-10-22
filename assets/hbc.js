(() => {
  'use strict';

  const root = document.querySelector('[data-hbc-respondent]');
  if (!root) return;

  const content = document.querySelector('#hbc-content');
  const status = document.querySelector('#hbc-status');

  // --- device token storage -------------------------------------------------
  // The ONLY name used for this identifier across the client is "device token"
  // (server field: device_token on the registration/verify response, sent back
  // as the X-HBC-Device-Token header). Stored per-browser; never in the URL.
  const DEVICE_TOKEN_KEY = 'hbc_device_token';
  const RETURN_TO_ENCOUNTER_KEY = 'hbc_return_to_encounter';
  const getDeviceToken = () => {
    try { return localStorage.getItem(DEVICE_TOKEN_KEY) || ''; } catch (e) { return ''; }
  };
  const setDeviceToken = (token) => {
    try { if (token) localStorage.setItem(DEVICE_TOKEN_KEY, token); } catch (e) { /* storage unavailable: device will re-register next visit */ }
  };

  // If this registration was reached via "register first, then come back" from
  // an /e/{token} encounter page, send the now-registered device straight back
  // to that same encounter instead of leaving it stranded on the RSVP page.
  const redirectToPendingEncounterIfAny = () => {
    let pendingToken = '';
    try { pendingToken = sessionStorage.getItem(RETURN_TO_ENCOUNTER_KEY) || ''; } catch (e) { /* storage unavailable */ }
    if (!pendingToken) return false;
    try { sessionStorage.removeItem(RETURN_TO_ENCOUNTER_KEY); } catch (e) { /* ignore */ }
    window.location.assign('/e/' + encodeURIComponent(pendingToken));
    return true;
  };

  // --- fetch helper, tolerant of empty / JSON / non-JSON responses ----------
  const api = async (path, options = {}) => {
    const headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': HBC_LAUGH.nonce, ...(options.headers || {}) };
    const deviceToken = getDeviceToken();
    if (deviceToken) headers['X-HBC-Device-Token'] = deviceToken;

    const response = await fetch(HBC_LAUGH.rest + path, { credentials: 'same-origin', ...options, headers });

    let body = null;
    if (response.status !== 204) {
      const text = await response.text();
      if (text) {
        try { body = JSON.parse(text); }
        catch (e) { body = null; /* non-JSON body: server error page, proxy timeout, etc. */ }
      }
    }

    if (!response.ok) {
      const message = body && body.error && body.error.message
        ? body.error.message
        : `Please try again. (${response.status})`;
      const error = new Error(message);
      error.code = body && body.error ? body.error.code : null;
      error.status = response.status;
      throw error;
    }
    return body;
  };

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const formatEventDateTime = (isoString) => {
    if (!isoString) return '';
    try {
      return new Intl.DateTimeFormat(undefined, { weekday: 'long', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(isoString));
    } catch (e) {
      return isoString;
    }
  };

  const showError = (message) => {
    const existing = content.querySelector('.hbc-error');
    if (existing) existing.remove();
    const p = document.createElement('p');
    p.className = 'hbc-error';
    p.textContent = message;
    content.prepend(p);
  };

  const practiceFaithExploreUrl = (offer = {}) => {
    // Prefer server-issued mega_start_url that already carries opaque hbc_ctx (no open redirect).
    if (offer && offer.explore_url && String(offer.explore_url).indexOf('hbc_ctx=') !== -1) {
      return String(offer.explore_url);
    }
    if (offer && offer.hbc_ctx) {
      try {
        const u = new URL((offer && offer.explore_url) ? offer.explore_url : 'https://humangold.org/start/');
        u.searchParams.set('hbc_ctx', String(offer.hbc_ctx));
        return u.toString();
      } catch (e) { /* fall through */ }
    }
    return (offer && offer.explore_url) ? String(offer.explore_url) : 'https://humangold.org/start/';
  };

  const ensureReturnContext = async (eventUuid) => {
    if (!eventUuid) return null;
    try {
      const params = new URLSearchParams(window.location.search);
      const org = params.get('org') || '';
      const res = await api('public/return-context', {
        method: 'POST',
        body: JSON.stringify({ event_uuid: eventUuid, org }),
      });
      return res.data || null;
    } catch (e) {
      return null;
    }
  };

  const renderRsvpComplete = async (result = {}) => {
    const touchstone = result.touchstone_word ? `<p><strong>Touchstone:</strong> ${esc(result.touchstone_word)}</p>` : '';
    const branch = result.peace_pentagon_branch ? `<p><strong>Peace Pentagon branch:</strong> ${esc(result.peace_pentagon_branch)}</p>` : '';
    let offer = result.practice_faith_offer || { explore_url: 'https://www.megavoters.com/start/' };
    if ((!offer.hbc_ctx || String(offer.explore_url || '').indexOf('hbc_ctx=') === -1) && result.event_uuid) {
      const ctx = await ensureReturnContext(result.event_uuid);
      if (ctx && ctx.hbc_ctx) {
        offer = { ...offer, ...ctx, explore_url: ctx.hgo_start_url || ctx.mega_start_url || offer.explore_url };
      }
    }
    const faithUrl = practiceFaithExploreUrl(offer);
    const llbUrl = offer.llb_welcome_url ? String(offer.llb_welcome_url) : '';
    const llbLink = llbUrl
      ? `<a class="hbc-button hbc-secondary" href="${esc(llbUrl)}" target="_blank" rel="noopener noreferrer">Continue to Legacy to Live By</a>`
      : '';
    const returnHint = offer.hbc_return_url
      ? `<p class="hbc-trust">Practice FAITH and Legacy to Live By keep a return link to this same event. Looking around there does not change this RSVP. <a href="${esc(offer.hbc_return_url)}">Return to this event</a>.</p>`
      : '';
    if (result.withdrawn || result.response === 'walk_away') {
      content.innerHTML = `
        <h2>RSVP withdrawn</h2>
        <p>${esc(result.message || 'Your previous RSVP for this event was withdrawn.')}</p>
        <p>You may close this page.</p>`;
      return;
    }
    content.innerHTML = `
      <p><strong>Device registered</strong></p>
      <h2>Your RSVP Is Complete</h2>
      <p>${esc(result.message || 'We look forward to seeing you.')}</p>
      ${touchstone}${branch}
      <p>Your RSVP is recorded. When you arrive, an authorized organizer can confirm you showed up.</p>
      <p>Your registered-device welcome and email-confirmation link will be sent to your saved email address.</p>
      <aside class="hbc-email-prompt">
        <strong>Optional next steps</strong>
        <p>Your completed Host event RSVP stands on its own. Practice FAITH and Legacy to Live By are optional. Touchstone and Discord are invitations, not required steps. A return link brings you back to this same event.</p>
        ${returnHint}
        <div class="hbc-actions">
          <button class="hbc-button hbc-secondary" type="button" id="hbc-done">Done — Close</button>
          <a class="hbc-button hbc-secondary" id="hbc-practice-faith" href="${esc(faithUrl)}" target="_blank" rel="noopener noreferrer">Explore Practice FAITH</a>
          ${llbLink}
          <button class="hbc-button" type="button" id="hbc-membership-cta">Continue to $12 Miner membership</button>
        </div>
      </aside>`;
    document.querySelector('#hbc-done').onclick = () => {
      content.innerHTML = '<h2>All Done</h2><p>Your device is registered and your RSVP is recorded. You may close this page.</p>';
    };
    document.querySelector('#hbc-membership-cta').onclick = async () => {
      const button = document.querySelector('#hbc-membership-cta');
      button.disabled = true;
      button.textContent = 'Preparing secure checkout…';
      try {
        const response = await api('public/membership/checkout', { method: 'POST', body: '{}' });
        window.location.assign(response.data.redirect_url);
      } catch (err) {
        button.disabled = false;
        button.textContent = 'Continue to $12 Miner membership';
        showError(err.message);
      }
    };
  };

  // Optional ?org=PUBLICCODE scopes the directory to one registered organization.
  const orgCode = (() => {
    try { return new URLSearchParams(window.location.search).get('org') || ''; } catch (e) { return ''; }
  })();
  const withOrg = (path) => {
    if (!orgCode) return path;
    return path + (path.indexOf('?') >= 0 ? '&' : '?') + 'org=' + encodeURIComponent(orgCode);
  };

  // The QR itself carries no Host or event identifier. The first response is a
  // platform-wide public directory; choosing an event creates the context that
  // the server then uses for device and assignment routing.
  const renderUniversalDirectory = (initialEntry) => {
    status.textContent = (initialEntry && initialEntry.organization && initialEntry.organization.name)
      ? initialEntry.organization.name
      : 'Human Gold';
    let entry = initialEntry;
    let events = Array.isArray(entry.events) ? entry.events : [];
    let query = entry.query || '';

    const selectByPath = async (path) => {
      content.innerHTML = '<p>Loading the selected event…</p>';
      try {
        const result = await api(withOrg(path));
        render(result.data);
      } catch (err) {
        draw();
        showError(err.message);
      }
    };

    const draw = () => {
      const results = events.length
        ? `<div class="hbc-grid hbc-grid--events">${events.map((event, index) => {
            const meta = [
              event.organization_name ? esc(event.organization_name) : '',
              esc(formatEventDateTime(event.starts_at)),
              event.location ? esc(event.location) : '',
            ].filter(Boolean).join(' · ');
            return `
            <button class="hbc-choice hbc-platform-event" type="button" data-event-index="${index}">
              <strong>${esc(event.name)}</strong>
              <span class="hbc-choice-meta">${meta}</span>
            </button>`;
          }).join('')}</div>`
        : '<p>No matching public events are currently available. You can still register this device and RSVP later when an event is published.</p>';
      const orgBanner = entry.organization && entry.organization.name
        ? `<aside class="hbc-email-prompt"><strong>${esc(entry.organization.name)}</strong><p>Organization code <code>${esc(entry.organization.public_code || orgCode)}</code>. Observe, Participate, or Walk Away when you select an event.</p></aside>`
        : `<aside class="hbc-email-prompt"><strong>Human Gold</strong><p>Select your organization event. Observe to attend, Participate to volunteer interest, or Walk Away with no record.</p></aside>`;
      const faithUrl = practiceFaithExploreUrl();
      content.innerHTML = `
        ${orgBanner}
        <h2>Choose your current event</h2>
        <p>Organization name appears with each event. Search, select, or enter the short code printed beside the QR.</p>
        <form id="hbc-event-search" class="hbc-field">
          <label>Search by Host or event name<input type="search" name="q" maxlength="100" value="${esc(query)}"></label>
          <button class="hbc-button hbc-secondary" type="submit">Search</button>
        </form>
        <form id="hbc-event-code" class="hbc-field">
          <label>Short event code<input type="text" name="event_code" minlength="4" maxlength="16" pattern="[A-Za-z0-9-]{4,16}" autocomplete="off"></label>
          <button class="hbc-button hbc-secondary" type="submit">Open Event</button>
        </form>
        ${results}
        <div class="hbc-actions">
          ${entry.has_more && entry.next_cursor ? '<button class="hbc-button hbc-secondary" type="button" id="hbc-load-more">Load More</button>' : ''}
          <button class="hbc-button hbc-secondary" type="button" id="hbc-platform-walk">Walk Away</button>
          <a class="hbc-button hbc-secondary" href="${esc(faithUrl)}" target="_blank" rel="noopener noreferrer">Explore Practice FAITH</a>
        </div>`;

      content.querySelectorAll('.hbc-platform-event').forEach((button) => {
        button.onclick = () => {
          const selected = events[Number(button.dataset.eventIndex)];
          if (selected && selected.event_uuid) selectByPath('public/entry?event=' + encodeURIComponent(selected.event_uuid));
        };
      });
      document.querySelector('#hbc-event-search').onsubmit = async (event) => {
        event.preventDefault();
        query = String(new FormData(event.currentTarget).get('q') || '').trim();
        try {
          const result = await api(withOrg('public/entry?limit=20&q=' + encodeURIComponent(query)));
          entry = result.data;
          events = Array.isArray(entry.events) ? entry.events : [];
          draw();
        } catch (err) { showError(err.message); }
      };
      document.querySelector('#hbc-event-code').onsubmit = (event) => {
        event.preventDefault();
        const code = String(new FormData(event.currentTarget).get('event_code') || '').trim().toUpperCase();
        selectByPath('public/entry?event_code=' + encodeURIComponent(code));
      };
      const loadMore = document.querySelector('#hbc-load-more');
      if (loadMore) loadMore.onclick = async () => {
        loadMore.disabled = true;
        try {
          const result = await api(withOrg('public/entry?limit=20&q=' + encodeURIComponent(query) + '&cursor=' + encodeURIComponent(entry.next_cursor)));
          entry = result.data;
          events = events.concat(Array.isArray(entry.events) ? entry.events : []);
          draw();
        } catch (err) { loadMore.disabled = false; showError(err.message); }
      };
      document.querySelector('#hbc-platform-walk').onclick = () => {
        content.innerHTML = '<h2>No event was selected.</h2><p>You may close this page.</p>';
      };
    };
    draw();
  };

  // --- rendering, branched strictly on route_view ----------------------------
  // device_registration and respondent_rsvp render the IDENTICAL event/calendar
  // picker. device_state only decides what happens once a choice is submitted:
  // unregistered -> registration/OTP; registered -> immediate save, no OTP.
  // operator_router NEVER renders this picker directly; it shows the
  // event-specific Initiate Encounter prompt, per V2_DECISIONS.md.
  const render = (route) => {
    if (route.organization && route.organization.name) {
      status.textContent = route.organization.name;
    } else if (route.event) {
      status.textContent = route.event.name;
    } else {
      status.textContent = 'Human Gold';
    }

    if (route.route_view === 'operator_router') {
      renderOperatorPrompt(route);
      return;
    }
    // device_registration or respondent_rsvp: same event/calendar surface.
    renderRsvpSurface(route);
  };

  const renderRsvpSurface = (route) => {
    if (route.device_state === 'registered' && route.requires_otp === true) {
      // v2.4 has no registered-device reverification endpoint. Fail closed
      // instead of silently creating a second registration or bypassing a
      // server-requested reverification step.
      content.innerHTML = '<h2>Verification Required</h2><p>This device needs secure recovery or reverification before continuing. Please contact the event organizer.</p>';
      return;
    }

    if (route.event && route.event.event_uuid) {
      renderRsvpPicker(route);
      return;
    }

    if (Array.isArray(route.calendar) && route.calendar.length) {
      content.innerHTML = `
        <h2>Choose a LAUGH Event</h2>
        <div class="hbc-grid hbc-grid--events">${route.calendar.map((event, index) => {
          const meta = [
            esc(formatEventDateTime(event.starts_at)),
            event.location ? esc(event.location) : '',
          ].filter(Boolean).join(' · ');
          return `
          <button class="hbc-choice hbc-event-choice" type="button" data-event-index="${index}">
            <strong>${esc(event.name)}</strong>
            <span class="hbc-choice-meta">${meta}</span>
          </button>`;
        }).join('')}</div>
        <div class="hbc-actions"><button class="hbc-button hbc-secondary" type="button" id="hbc-walk-calendar">Walk Away</button></div>`;

      content.querySelectorAll('.hbc-event-choice').forEach((button) => {
        button.onclick = () => {
          const event = route.calendar[Number(button.dataset.eventIndex)];
          renderRsvpPicker({ ...route, event });
        };
      });
      document.querySelector('#hbc-walk-calendar').onclick = () => {
        content.innerHTML = '<h2>No RSVP was recorded.</h2><p>You may close this page.</p>';
      };
      return;
    }

    content.innerHTML = '<h2>No event is currently available.</h2><p>Please check this Human Gold QR again later.</p>';
  };

  const renderRsvpPicker = (route) => {
    const event = route.event;
    const allowed = Array.isArray(route.allowed_actions) ? route.allowed_actions : ['observe', 'participate', 'walk_away'];
    const emailPrompt = route.device_state === 'registered' && route.email_status && route.email_status !== 'verified'
      ? `<aside class="hbc-email-prompt"><strong>Please confirm your email.</strong><p>Confirmation was sent to ${esc(route.masked_email || 'your saved address')}. You may continue this RSVP now.</p>${route.email_manage_url ? `<a class="hbc-button hbc-secondary" href="${esc(route.email_manage_url)}">Resend or Re-enter Email</a>` : '<p>Check your inbox or ask the organizer to resend the confirmation.</p>'}</aside>`
      : '';
    const responseChoices = [
      allowed.includes('observe') ? '<label class="hbc-choice"><input type="radio" name="response" value="observe" required> <strong>Observe</strong>I plan to attend.</label>' : '',
      allowed.includes('participate') ? '<label class="hbc-choice"><input type="radio" name="response" value="participate" required> <strong>Participate</strong>I plan to attend and would like to volunteer.</label>' : '',
    ].join('');
    const isPracticeFaith = event.event_type === 'practice_faith_touchstone';
    const touchstones = isPracticeFaith && Array.isArray(event.available_touchstones) ? event.available_touchstones : [];
    const branches = isPracticeFaith && Array.isArray(event.peace_pentagon_branches) ? event.peace_pentagon_branches : [];
    const practiceFaithFields = isPracticeFaith && touchstones.length && branches.length ? `
      <div class="hbc-field"><label>Select one Touchstone
        <select name="touchstone_word" required><option value="">Choose a Touchstone</option>${touchstones.map((word) => `<option value="${esc(word)}">${esc(word)}</option>`).join('')}</select>
      </label></div>
      <div class="hbc-field"><label>Select a Peace Pentagon branch
        <select name="peace_pentagon_branch" required><option value="">Choose a branch</option>${branches.map((branch) => `<option value="${esc(branch)}">${esc(branch)}</option>`).join('')}</select>
      </label></div>` : '';
    if (isPracticeFaith && (!touchstones.length || !branches.length)) {
      content.innerHTML = '<h2>Touchstone Event Unavailable</h2><p>This Practice FAITH event is missing its Touchstone or Peace Pentagon choices. Please contact the Host.</p>';
      return;
    }
    content.innerHTML = `
      ${emailPrompt}
      <p><strong>Device status: ${route.device_state === 'registered' ? 'Registered' : 'Registration required'}</strong></p>
      <h2>${esc(event.name)}</h2>
      <p>${esc(formatEventDateTime(event.starts_at))}${event.location ? ' · ' + esc(event.location) : ''}</p>
      <form id="hbc-rsvp">
        <div class="hbc-grid hbc-grid--choices">
          ${responseChoices}
        </div>
        ${practiceFaithFields}
        <div class="hbc-actions">
          <button class="hbc-button" type="submit">${route.device_state === 'registered' ? 'Save RSVP' : 'Continue'}</button>
          ${allowed.includes('walk_away') ? '<button class="hbc-button hbc-secondary" type="button" id="hbc-walk">Walk Away</button>' : ''}
        </div>
      </form>`;

    const walkButton = document.querySelector('#hbc-walk');
    if (walkButton) walkButton.onclick = async () => {
      // Registered device with an existing RSVP: second-scan Walk Away withdraws it.
      if (route.device_state === 'registered') {
        try {
          const result = await api(`public/events/${encodeURIComponent(route.event.event_uuid)}/rsvps`, {
            method: 'POST',
            body: JSON.stringify({ response: 'walk_away' }),
          });
          renderRsvpComplete(result.data || { response: 'walk_away', withdrawn: true });
        } catch (err) {
          showError(err.message);
        }
        return;
      }
      // No registration session / no prior RSVP — local discard only.
      content.innerHTML = '<h2>No RSVP was recorded.</h2><p>You may close this page.</p>';
    };

    document.querySelector('#hbc-rsvp').onsubmit = async (e) => {
      e.preventDefault();
      const form = new FormData(e.currentTarget);
      const chosenResponse = form.get('response');
      const practiceFaith = {
        touchstone_word: form.get('touchstone_word') || '',
        peace_pentagon_branch: form.get('peace_pentagon_branch') || '',
      };
      if (route.device_state === 'registered') {
        await submitRegisteredRsvp(route, chosenResponse, practiceFaith);
      } else {
        await beginUnregisteredRegistration(route, chosenResponse, practiceFaith);
      }
    };
  };

  // respondent_rsvp, device_state=registered: direct save, no OTP.
  const submitRegisteredRsvp = async (route, chosenResponse, practiceFaith) => {
    try {
      const result = await api(`public/events/${encodeURIComponent(route.event.event_uuid)}/rsvps`, {
        method: 'POST',
        body: JSON.stringify({ response: chosenResponse, ...practiceFaith }),
      });
      renderRsvpComplete(result.data || {});
    } catch (err) {
      showError(err.message);
    }
  };

  // device_registration, device_state=unregistered: event choice already made
  // (chosenResponse), now collect contact info and start OTP.
  const beginUnregisteredRegistration = async (route, chosenResponse, practiceFaith) => {
    content.innerHTML = `
      <h2>${esc(route.event.name)}</h2>
      <p>One more step: verify your contact information to complete your ${esc(chosenResponse)} RSVP.</p>
      <p class="hbc-trust">Email and mobile are required. Name and mailing address are optional.</p>
      <form id="hbc-contact">
        <div class="hbc-field"><label>Email address<input type="email" name="email" autocomplete="email" required></label></div>
        <div class="hbc-field">
          <label for="hbc-phone-country">Mobile number</label>
          <div class="hbc-phone-combo" role="group" aria-label="Mobile number">
            <select id="hbc-phone-country" name="phone_country" autocomplete="country" required>
              <option value="US" data-dial="1" selected>United States (+1)</option>
              <option value="CA" data-dial="1">Canada (+1)</option>
              <option value="NP" data-dial="977">Nepal (+977)</option>
              <option value="GB" data-dial="44">United Kingdom (+44)</option>
              <option value="IN" data-dial="91">India (+91)</option>
              <option value="AU" data-dial="61">Australia (+61)</option>
            </select>
            <input id="hbc-phone-national" type="tel" name="phone" autocomplete="tel-national" inputmode="numeric" placeholder="Local number, digits only" required>
          </div>
          <p class="hbc-field-help">Same SMS OTP as My Account login. Choose country, then enter the number without the country code (US example: 4045551234).</p>
        </div>
        <div class="hbc-field"><label>Name <em>(optional)</em><input type="text" name="display_name" autocomplete="name" maxlength="80"></label></div>
        <div class="hbc-field"><label>Mailing address <em>(optional)</em><textarea name="mailing_address" rows="2" maxlength="240"></textarea></label></div>
        <label class="hbc-consent"><input type="checkbox" name="verification_consent" required> I agree to receive the one-time verification message needed to complete this RSVP.</label>
        <div class="hbc-actions">
          <button class="hbc-button" type="submit">Send verification code</button>
          <button class="hbc-button hbc-secondary" type="button" id="hbc-walk-contact">Walk Away</button>
        </div>
      </form>`;

    document.querySelector('#hbc-walk-contact').onclick = () => {
      // Still no registration_session_id yet (registration/start has not been
      // called), so this remains a local-only discard.
      content.innerHTML = '<h2>No RSVP was recorded.</h2><p>You may close this page.</p>';
    };

    document.querySelector('#hbc-contact').onsubmit = async (e) => {
      e.preventDefault();
      const form = new FormData(e.currentTarget);
      const submit = e.currentTarget.querySelector('button[type="submit"]');
      if (submit) submit.disabled = true;
      try {
        const result = await api('public/registration/start', {
          method: 'POST',
          body: JSON.stringify({
            event_uuid: route.event.event_uuid,
            response: chosenResponse,
            ...practiceFaith,
            email: form.get('email'),
            phone: form.get('phone'),
            phone_country: form.get('phone_country') || 'US',
            display_name: form.get('display_name') || '',
            mailing_address: form.get('mailing_address') || '',
            verification_consent: true,
          }),
        });
        renderOtp(route, result.data);
      } catch (err) {
        if (submit) submit.disabled = false;
        showError(err.message);
      }
    };
  };

  const renderOtp = (route, registrationStart) => {
    const registrationSessionId = registrationStart.registration_session_id;
    const masked = registrationStart.phone_masked ? esc(registrationStart.phone_masked) : '';
    const note = registrationStart.message
      ? `<p class="hbc-otp-note">${esc(registrationStart.message)}</p>`
      : (masked ? `<p class="hbc-otp-note">We sent a 6-digit code to ${masked}.</p>` : '<p class="hbc-otp-note">Enter the 6-digit code from your SMS.</p>');
    const debug = registrationStart.debug_otp
      ? `<p class="hbc-otp-debug"><strong>Local debug code:</strong> ${esc(String(registrationStart.debug_otp))}</p>`
      : '';
    content.innerHTML = `
      <h2>Enter Your Verification Code</h2>
      ${note}
      ${debug}
      <form id="hbc-otp">
        <div class="hbc-field"><label>6-digit code<input inputmode="numeric" pattern="[0-9]{6}" name="code" autocomplete="one-time-code" required></label></div>
        <div class="hbc-actions">
          <button class="hbc-button" type="submit">Verify and Complete RSVP</button>
          <button class="hbc-button hbc-secondary" type="button" id="hbc-walk-otp">Walk Away</button>
        </div>
      </form>`;

    // A registration_session_id now exists server-side, so Walk Away MUST tell
    // the server to discard it — unlike the earlier, session-less steps.
    document.querySelector('#hbc-walk-otp').onclick = async () => {
      try {
        await api('public/walk-away', { method: 'POST', body: JSON.stringify({ registration_session_id: registrationSessionId }) });
      } catch (err) {
        // Walk Away still succeeds locally even if the discard call fails;
        // we never block a person from leaving on a network error.
      }
      content.innerHTML = '<h2>No RSVP was recorded.</h2><p>You may close this page.</p>';
    };

    document.querySelector('#hbc-otp').onsubmit = async (e) => {
      e.preventDefault();
      try {
        const result = await api('public/registration/verify', {
          method: 'POST',
          body: JSON.stringify({ registration_session_id: registrationSessionId, code: new FormData(e.currentTarget).get('code') }),
        });
        if (result.data && result.data.device_token) setDeviceToken(result.data.device_token);
        if (redirectToPendingEncounterIfAny()) return;
        renderRsvpComplete(result.data || {});
      } catch (err) {
        showError(err.message);
      }
    };
  };

  // operator_router: event-specific prompt only. Never the organizer dashboard,
  // which lives separately at /organize.
  const renderOperatorPrompt = (route) => {
    const authorizations = Array.isArray(route.authorizations) && route.authorizations.length
      ? route.authorizations.filter((a) => a && (a.role === 'greeter' || a.role === 'host') && a.assignment_uuid)
      : (route.authorization && route.authorization.assignment_uuid ? [route.authorization] : []);
    const greeterDefault = authorizations.find((a) => a.role === 'greeter');
    route.acting_as = route.acting_as && route.acting_as.assignment_uuid ? route.acting_as : (greeterDefault || authorizations[0] || null);
    if (!route.acting_as) {
      content.innerHTML = '<h2>Assignment unavailable</h2><p>No active Host or Greeter assignment was returned for this event.</p>';
      return;
    }
    const capacityControl = authorizations.length > 1
      ? `<label>Acting as for this event<select id="hbc-acting-as">${authorizations.map((a) => `<option value="${esc(a.assignment_uuid)}"${a.assignment_uuid === route.acting_as.assignment_uuid ? ' selected' : ''}>${esc(a.role === 'host' ? 'Host — manage event and encounters' : 'Greeter — initiate encounters')}</option>`).join('')}</select></label>`
      : `<p><strong>Acting as:</strong> ${esc(route.acting_as.role === 'host' ? 'Host' : 'Greeter')}</p>`;
    const hostAction = route.acting_as.role === 'host' && route.acting_as.dashboard_url
      ? `<a class="hbc-button hbc-secondary" href="${esc(route.acting_as.dashboard_url)}">Proceed to Dashboard</a>`
      : '';
    content.innerHTML = `
      <h2>${esc(route.event.name)}</h2>
      ${capacityControl}
      <p><strong>Initiate an attendance encounter for this event?</strong></p>
      <div class="hbc-actions">
        <button class="hbc-button" id="hbc-initiate">Initiate Encounter</button>
        ${hostAction}
        <button class="hbc-button hbc-secondary" id="hbc-not-now">Not Now</button>
      </div>`;

    const actingAsSelect = document.querySelector('#hbc-acting-as');
    if (actingAsSelect) {
      actingAsSelect.onchange = () => {
        const selected = authorizations.find((a) => a.assignment_uuid === actingAsSelect.value);
        if (selected) { route.acting_as = selected; renderOperatorPrompt(route); }
      };
    }

    document.querySelector('#hbc-not-now').onclick = () => {
      // Render the fallback ordinary view locally — re-resolving the route would
      // just return operator_router again since the assignment is still active.
      if (route.fallback_rsvp) {
        renderRsvpPicker({ event: route.fallback_rsvp.event, allowed_actions: route.fallback_rsvp.allowed_actions, device_state: 'registered', route_view: 'respondent_rsvp', requires_otp: false });
      } else {
        content.innerHTML = '<p>Loading…</p>';
        api('public/entry?event=' + encodeURIComponent(route.event.event_uuid)).then((r) => render(r.data)).catch((err) => showError(err.message));
      }
    };

    document.querySelector('#hbc-initiate').onclick = () => {
      renderEncounterType(route);
    };
  };

  const encounterTypeLabel = (type) => {
    if (type === 'trade') return 'Trade';
    if (type === 'presence') return 'Presence Acknowledged';
    return 'Gratitude';
  };

  const issueEncounter = async (route, encounterType) => {
    const result = await api(`events/${encodeURIComponent(route.event.event_uuid)}/encounters`, {
      method: 'POST',
      body: JSON.stringify({
        encounter_type: encounterType,
        assignment_uuid: route.acting_as.assignment_uuid,
        operator_role: route.acting_as.role,
      }),
    });
    renderOperatorEncounterSession(result.data);
  };

  const renderEncounterType = (route) => {
    const tradeOn = !!route.trade_available;
    content.innerHTML = `
      <h2>Choose the Encounter Gateway</h2>
      <div class="hbc-grid hbc-grid--choices">
        <button class="hbc-choice" type="button" id="hbc-presence"><strong>Presence Acknowledged</strong><span class="hbc-choice-meta">Recognize that someone showed up — short-lived Identity QR.</span></button>
        <button class="hbc-choice" type="button" id="hbc-trade" ${tradeOn ? '' : 'disabled aria-disabled="true"'}><strong>Trade</strong><span class="hbc-choice-meta">${tradeOn ? '$30 encounter — seller authorized, platform ON.' : 'Unavailable until Super Admin unlocks Trade and authorizes this seller.'}</span></button>
        <button class="hbc-choice" type="button" id="hbc-gratitude"><strong>Gratitude</strong><span class="hbc-choice-meta">Create the short-lived Gratitude Identity QR.</span></button>
      </div>
      <div class="hbc-actions"><button class="hbc-button hbc-secondary" type="button" id="hbc-encounter-back">Back</button></div>`;

    document.querySelector('#hbc-encounter-back').onclick = () => renderOperatorPrompt(route);
    document.querySelector('#hbc-presence').onclick = async () => {
      try { await issueEncounter(route, 'presence'); } catch (err) { showError(err.message); }
    };
    document.querySelector('#hbc-gratitude').onclick = async () => {
      try { await issueEncounter(route, 'gratitude'); } catch (err) { showError(err.message); }
    };
    const tradeBtn = document.querySelector('#hbc-trade');
    if (tradeBtn && tradeOn) {
      tradeBtn.onclick = async () => {
        try { await issueEncounter(route, 'trade'); } catch (err) { showError(err.message); }
      };
    }
  };

  const OPERATOR_TERMINAL_MESSAGES = {
    declined: 'The attendee declined this encounter.',
    walked_away: 'The attendee walked away. No record was created.',
    expired: 'This encounter expired before both sides confirmed.',
    proximity_failed: 'Both devices need to be near each other (about 50 meters) to complete this encounter.',
    same_device_rejected: 'This device already initiated the encounter and can’t also confirm it as the attendee.',
    disputed: 'This encounter is disputed and will be reviewed by the organizer.',
    extinguished: 'Pending XP for this encounter was extinguished after RSVP Walk Away.',
  };

  // Displays the Identity QR and polls the attendee's progress. Per Coach Tom's rule,
  // the operator's own Initiate click is never sufficient proof — the operator must
  // separately click Confirm Encounter once the attendee has answered, and the server
  // still checks the five-minute window, ~50-meter proximity (when available), and
  // that this isn't the same identity confirming both sides before it will mark the
  // encounter confirmed.
  const renderOperatorEncounterSession = (session) => {
    let pollTimer = null;
    const stopPolling = () => { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } };

    const typeLabel = encounterTypeLabel(session.encounter_type);
    const renderWaitingForAttendee = () => {
      content.innerHTML = `
        <h2>${esc(typeLabel)} Identity QR</h2>
        <p>Show this QR to the attendee to scan. It expires ${esc(formatEventDateTime(session.expires_at))}.</p>
        <div class="hbc-qr-box"><img src="${esc(session.identity_qr_image_url)}" alt="Short-lived Human Gold Identity QR"></div>
        <p id="hbc-operator-status">Waiting for the attendee to confirm…</p>`;
    };

    const renderReadyToConfirm = () => {
      content.innerHTML = `
        <h2>Attendee Confirmed</h2>
        <p>Now confirm this encounter yourself — your Initiate click alone does not complete it. Both devices must obtain a fresh, accurate location.</p>
        <label class="hbc-choice"><input type="checkbox" id="hbc-visual-attestation"> I can see the attendee and their device here.</label>
        <div class="hbc-actions"><button class="hbc-button" id="hbc-operator-confirm">Confirm Encounter</button></div>`;
      document.querySelector('#hbc-operator-confirm').onclick = async () => {
        stopPolling();
        try {
          const visualAttestation = document.querySelector('#hbc-visual-attestation').checked;
          if (!visualAttestation) throw new Error('Confirm that you can see the attendee and their device here.');
          if (!('geolocation' in navigator)) throw new Error('This device cannot provide location. The encounter cannot be confirmed.');
          const location = await new Promise((resolve) => {
            navigator.geolocation.getCurrentPosition(
              (p) => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy_meters: p.coords.accuracy, captured_at: new Date(p.timestamp || Date.now()).toISOString() }),
              () => resolve(null),
              { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
            );
          });
          if (!location || location.accuracy_meters > 50) throw new Error('Enable precise location and remain near the attendee. A fresh reading accurate to 50 meters or better is required.');
          const result = await api(`operator/encounters/${encodeURIComponent(session.session_uuid)}/confirm`, {
            method: 'POST',
            body: JSON.stringify({ location, visual_attestation: true }),
          });
          renderOutcome(result.data);
        } catch (err) {
          showError(err.message);
        }
      };
    };

    const renderOutcome = (info) => {
      const state = info.state;
      if (state === 'confirmed' || state === 'pending_xp') {
        const id = info.confirmation_id ? `<p><strong>Confirmation:</strong> ${esc(info.confirmation_id)}</p>` : '';
        const when = info.confirmed_at ? `<p><strong>Confirmed:</strong> ${esc(formatEventDateTime(info.confirmed_at))}</p>` : '';
        const proximity = info.proximity_assurance ? `<p><strong>Proximity assurance:</strong> ${esc(info.proximity_assurance)}</p>` : '';
        const touchstone = info.touchstone_word ? `<p><strong>Touchstone:</strong> ${esc(info.touchstone_word)}</p>` : '';
        const branch = info.peace_pentagon_branch ? `<p><strong>Peace Pentagon branch:</strong> ${esc(info.peace_pentagon_branch)}</p>` : '';
        const rsvp = info.rsvp_response ? `<p><strong>RSVP:</strong> ${esc(info.rsvp_response === 'participate' ? 'Participate' : 'Observe')}</p>` : '';
        const xp = info.xp_play_dough_status === 'issued'
          ? '<p><strong>1 sextillion XP Play Dough provided to the recipient.</strong></p>'
          : '<p><strong>1 sextillion XP Play Dough approved—ledger posting pending.</strong></p>';
        content.innerHTML = '<h2>LAUGH Event Confirmed</h2>' + xp + '<p>XP Play Dough is Experience Presence recognition—not money, cryptocurrency, wages, credit, or a measure of personal worth.</p>' + id + when + rsvp + touchstone + branch + proximity;
        return;
      }
      const message = OPERATOR_TERMINAL_MESSAGES[state] || 'This encounter could not be confirmed.';
      content.innerHTML = `<h2>Not Confirmed</h2><p>${esc(message)}</p>`;
    };

    renderWaitingForAttendee();
    pollTimer = setInterval(async () => {
      try {
        const result = await api(`operator/encounters/${encodeURIComponent(session.session_uuid)}/status`);
        const state = result.data.state;
        if (state === 'attendee_confirmed') {
          stopPolling();
          renderReadyToConfirm();
        } else if (state && state !== 'pending') {
          stopPolling();
          renderOutcome(result.data);
        }
      } catch (err) {
        // Transient network errors should not interrupt polling.
      }
    }, 3000);
  };

  // --- entry point -------------------------------------------------------
  try {
    if (new URLSearchParams(window.location.search).get('return_error')) {
      showError('That closed-loop return link expired or was invalid. Select your event again — your RSVP was not changed.');
    }
  } catch (e) { /* ignore */ }
  status.textContent = 'Loading current Human Gold events…';
  const deepEvent = (() => {
    try { return new URLSearchParams(window.location.search).get('event') || ''; } catch (e) { return ''; }
  })();
  const entryPath = deepEvent
    ? withOrg('public/entry?event=' + encodeURIComponent(deepEvent))
    : withOrg('public/entry');
  api(entryPath)
    .then((r) => {
      if (deepEvent && r.data && r.data.event) {
        render(r.data);
        return;
      }
      renderUniversalDirectory(r.data);
    })
    .catch((err) => {
      status.textContent = 'Human Gold entry unavailable';
      showError(err.message);
    });
})();

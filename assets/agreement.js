(() => {
  'use strict';

  const root = document.querySelector('[data-hbc-agreement]');
  if (!root) return;
  root.hidden = true;

  const statusEl = document.querySelector('#hbc-agreement-status');
  const contentEl = document.querySelector('#hbc-agreement-content');
  const hostUuid = root.dataset.hostUuid || '';
  const agreementKey = root.dataset.agreementKey || '';
  const agreementVersion = root.dataset.agreementVersion || '';
  const documentChecksum = root.dataset.documentChecksum || '';

  // Same device-token storage convention as hbc.js/encounter.js. Signing is tied to the
  // recognized device already registered through the Universal Human Gold QR — never a
  // separate WP login. Host authority is verified separately for the exact organization.
  const DEVICE_TOKEN_KEY = 'hbc_device_token';
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

  const esc = (value) => String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));

  const formatDateTime = (value) => {
    if (!value) return '';
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString();
  };

  const renderNeedsHost = () => {
    statusEl.textContent = '';
    contentEl.innerHTML = '<p>Open this page from your organization’s Host signing link (it includes your Host ID) to sign this agreement.</p>';
  };

  const renderNeedsDevice = () => {
    statusEl.textContent = '';
    contentEl.innerHTML = '<p>Register this device through the Universal Human Gold QR first, then come back to this page to sign.</p>';
  };

  const renderSigned = (info) => {
    statusEl.textContent = 'Signed';
    const who = esc(info.typed_name || '');
    const when = esc(formatDateTime(info.accepted_at));
    contentEl.innerHTML = `<p>Signed by <strong>${who}</strong>${when ? ` on ${when}` : ''}. No further action is needed unless a new version of this agreement is published.</p>`;
  };

  const renderSignForm = () => {
    statusEl.textContent = 'Not yet signed';
    contentEl.innerHTML = `
      <form id="hbc-agreement-form">
        <label for="hbc-agreement-name">Your full name</label>
        <input id="hbc-agreement-name" name="typed_name" type="text" autocomplete="name" required>
        <label class="hbc-checkbox-row">
          <input id="hbc-agreement-consent" name="consent_checked" type="checkbox" required>
          I have read and agree to this agreement, on behalf of my organization.
        </label>
        <button type="submit" class="hbc-button">Sign</button>
        <p id="hbc-agreement-error" role="alert"></p>
      </form>
    `;
    const form = document.querySelector('#hbc-agreement-form');
    const errorEl = document.querySelector('#hbc-agreement-error');
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      errorEl.textContent = '';
      const typedName = document.querySelector('#hbc-agreement-name').value.trim();
      const consentChecked = document.querySelector('#hbc-agreement-consent').checked;
      try {
        const result = await api(`hosts/${encodeURIComponent(hostUuid)}/agreements/${encodeURIComponent(agreementKey)}/accept`, {
          method: 'POST',
          body: JSON.stringify({ agreement_version: agreementVersion, typed_name: typedName, consent_checked: consentChecked, document_checksum: documentChecksum }),
        });
        renderSigned(result && result.data ? result.data : { typed_name: typedName });
      } catch (error) {
        errorEl.textContent = error.message || 'Please try again.';
      }
    });
  };

  const init = async () => {
    // Attendees and operators scoped to another Host see no agreement option at all.
    // The card remains hidden unless the tenant-scoped status call succeeds.
    if (!hostUuid || !getDeviceToken()) return;
    if (!/^[0-9a-f]{64}$/.test(documentChecksum)) {
      statusEl.textContent = '';
      contentEl.innerHTML = '<p>The current agreement signing copy is unavailable. Please contact the Host administrator.</p>';
      return;
    }

    statusEl.textContent = 'Loading…';
    try {
      const result = await api(`hosts/${encodeURIComponent(hostUuid)}/agreements/${encodeURIComponent(agreementKey)}/status?agreement_version=${encodeURIComponent(agreementVersion)}`);
      const data = result && result.data ? result.data : { accepted: false };
      root.hidden = false;
      if (data.accepted) {
        renderSigned(data);
      } else {
        renderSignForm();
      }
    } catch (error) {
      // Fail closed without revealing a signing surface. Host/Admin users receive
      // authorization or integration diagnostics through their protected dashboard.
      root.hidden = true;
    }
  };

  init();
})();

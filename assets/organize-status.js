(() => {
  'use strict';
  const target = document.querySelector('#hbc-membership-return-status');
  if (!target) return;
  let token = '';
  try { token = localStorage.getItem('hbc_device_token') || ''; } catch (e) { /* use sign-in path */ }
  if (!token) {
    target.innerHTML = '<p>Sign in or use your registered device to confirm membership status.</p>';
    return;
  }
  fetch(HBC_LAUGH.rest + 'public/membership/status', {
    credentials: 'same-origin',
    headers: { 'X-HBC-Device-Token': token, 'X-WP-Nonce': HBC_LAUGH.nonce },
  }).then(async (response) => {
    const body = await response.json().catch(() => null);
    if (!response.ok) throw new Error(body?.error?.message || 'Membership status could not be confirmed.');
    return body.data;
  }).then((membership) => {
    if (membership.state === 'active') {
      target.innerHTML = '<p><strong>Your Miner membership is active.</strong> Continue through secure sign-in to open authorized organizer tools.</p>';
    } else if (membership.state === 'payment_pending') {
      target.innerHTML = '<p><strong>Confirming your membership.</strong> Payment has not yet been confirmed by the server. Refresh after the WooCommerce webhook is processed.</p>';
    } else {
      target.innerHTML = '<p>Your membership is not active. You may start or confirm membership below.</p>';
    }
  }).catch((error) => {
    target.innerHTML = '<p>' + String(error.message).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])) + '</p>';
  });
})();

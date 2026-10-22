<?php
defined('ABSPATH') || exit;

/**
 * Server-rendered only — no JS, no REST call from the browser. The link's
 * token IS the credential; opening it confirms the email for that identity
 * without registering or recognizing whatever device/browser opens it, per
 * the authoritative schema's email-confirmation rules.
 */

$token = sanitize_text_field((string) get_query_var('hbc_email_confirm_token'));
$result = null;
$adapter_connected = has_filter('hbc_confirm_email_token');
if ($adapter_connected && $token !== '') {
    $result = apply_filters('hbc_confirm_email_token', null, $token);
}
$success = $adapter_connected && $result && empty($result['error']);
$return_to_event_url = $success && !empty($result['return_to_event_url']) ? (string) $result['return_to_event_url'] : '';
?>
<!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo $success ? 'Email Confirmed' : 'Confirmation Link Unavailable'; ?> | Human Gold</title><?php wp_head(); ?></head>
<body class="hbc-page"><main class="hbc-shell">
<header><span class="hbc-kicker">Human Gold</span>
<?php if (!$adapter_connected) : ?>
    <h1>Email confirmation is not yet connected</h1><p>This site's email-confirmation adapter must be connected before this link can be processed. No change was made.</p>
<?php elseif ($success) : ?>
    <h1>Your email is confirmed</h1><p>It can now be used for Human Gold notices and secure device recovery.</p>
    <?php if ($return_to_event_url) : ?><p><a class="hbc-button" href="<?php echo esc_url($return_to_event_url); ?>">Return to Human Gold</a></p><?php endif; ?>
<?php else : ?>
    <h1>This link is invalid or has expired</h1><p>Confirmation links are single-use and expire after a short time. Ask the organizer to resend your confirmation, or continue using Human Gold without it — an unconfirmed email only limits notices, device recovery, organizer dashboard access, protected operating roles and data exports; it never undoes your device registration or RSVP.</p>
<?php endif; ?>
</header>
</main><?php wp_footer(); ?></body></html>

<?php
defined('ABSPATH') || exit;

if (!is_user_logged_in() || !current_user_can('hbc_super_admin')) {
    status_header(403);
    wp_die(esc_html__('Protected Super Admin access is required.', 'hbc-laugh'));
}

$mfa_verified = (bool) apply_filters('hbc_privileged_mfa_verified', false, get_current_user_id());
if (!$mfa_verified) {
    status_header(403);
    wp_die(esc_html__('MFA verification is required before opening the Super Admin Console.', 'hbc-laugh'));
}

/*
 * Utsav/Cursor integration:
 * 1. Register /super-admin through the plugin rewrite/controller layer.
 * 2. Enqueue the production-split CSS/JS derived from frontend/super-admin-dashboard.html.
 * 3. Localize a fresh wp_rest nonce as HBC_SUPER_NONCE.
 * 4. Keep all permission, tenant, reason and audit checks server-side.
 * 5. Do not add this route to ordinary organization Admin navigation.
 */
wp_enqueue_style('hbc-super-admin');
wp_enqueue_script('hbc-super-admin');
wp_add_inline_script('hbc-super-admin', 'window.HBC_SUPER_NONCE=' . wp_json_encode(wp_create_nonce('wp_rest')) . ';', 'before');

get_header();
?>
<main id="hbc-super-admin-root" class="hbc-super-admin-root" aria-live="polite">
    <noscript><?php esc_html_e('JavaScript is required to use the Human Gold Super Admin Console.', 'hbc-laugh'); ?></noscript>
</main>
<?php get_footer(); ?>


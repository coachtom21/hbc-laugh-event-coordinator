<?php
/**
 * Plugin Name: Human Gold LAUGH Event Coordinator
 * Description: Unified RSVP, device registration, organizer and aggregate attendance pilot.
 * Version: 2.4.13
 * Requires PHP: 8.1
 */

defined('ABSPATH') || exit;

define('HBC_LAUGH_VERSION', '2.4.13');
define('HBC_LAUGH_FILE', __FILE__);
define('HBC_LAUGH_DIR', plugin_dir_path(__FILE__));

require_once HBC_LAUGH_DIR . 'includes/class-hbc-schema.php';
require_once HBC_LAUGH_DIR . 'includes/class-hbc-activator.php';
require_once HBC_LAUGH_DIR . 'includes/class-hbc-readiness.php';
require_once HBC_LAUGH_DIR . 'includes/class-hbc-auth.php';
require_once HBC_LAUGH_DIR . 'includes/class-hbc-rest.php';

register_activation_hook(__FILE__, array('HBC_Laugh_Activator', 'activate'));

// Idempotent upgrade path: if the plugin file is updated to a newer version
// while already active, bring the schema forward without requiring a manual
// deactivate/reactivate. HBC_Laugh_Schema::install() is a no-op when the
// installed version already matches CURRENT_VERSION.
add_action('plugins_loaded', function () {
    if (HBC_Laugh_Schema::installed_version() < HBC_Laugh_Schema::CURRENT_VERSION) {
        HBC_Laugh_Schema::install();
    }
});

add_action('init', function () {
    $stored = (string) get_option('hbc_laugh_plugin_version', '');
    $rules  = get_option('rewrite_rules');
    $has_return = is_array($rules) && in_array('index.php?hbc_return_entry=1', $rules, true);
    if ($stored === HBC_LAUGH_VERSION && $has_return) {
        return;
    }
    if (get_transient('hbc_laugh_return_flush')) {
        return;
    }
    set_transient('hbc_laugh_return_flush', 1, MINUTE_IN_SECONDS);
    update_option('hbc_laugh_plugin_version', HBC_LAUGH_VERSION, false);
    flush_rewrite_rules(false);
}, 99);

add_action('init', function () {
    // One platform-wide QR, permanently encoded as https://humanblockchain.info/r.
    // Host/event context is selected only after this page opens.
    add_rewrite_rule('^r/?$', 'index.php?hbc_universal_entry=1', 'top');
    // Old organization-coded links are never a second canonical entry. Redirect them
    // to /r so printed legacy material cannot silently preserve tenant-owned QR routing.
    add_rewrite_rule('^r/([A-Za-z0-9_-]+)/?$', 'index.php?hbc_legacy_public_code=$matches[1]', 'top');
    // Reserved canonical Trade QR. Publicly reachable, but intentionally has no
    // transaction client or mutation route while YAM-is-On is inactive.
    add_rewrite_rule('^trade/?$', 'index.php?hbc_trade_entry=1', 'top');
    add_rewrite_rule('^organize/?$', 'index.php?hbc_organize=1', 'top');
    // Closed-loop redeem: Megavoters/LLB return with opaque hbc_ctx → /r?org=&event=
    add_rewrite_rule('^return/?$', 'index.php?hbc_return_entry=1', 'top');
    // Scanned by the ATTENDEE's device from the operator's displayed Identity
    // QR. The token is 1:1 bound to one event_uuid + session_uuid server-side
    // (hbc_attendance_sessions.token_hash) — there is no client-supplied
    // event_uuid anywhere in this flow to spoof. Opening this page never by
    // itself confirms anything: full confirmation requires BOTH this device's
    // explicit Y/Y/Y answer (POST .../attendee-confirm) AND the operator's own
    // separate, explicit confirm (POST /operator/encounters/{session_uuid}/confirm).
    add_rewrite_rule('^e/([A-Za-z0-9_-]+)/?$', 'index.php?hbc_encounter_token=$matches[1]', 'top');
    // Opened from the Welcome Letter's asynchronous email-confirmation link.
    // Server-rendered only; confirms the email for the identity without
    // registering or recognizing whatever device/browser opens the link.
    add_rewrite_rule('^confirm-email/([A-Za-z0-9_-]+)/?$', 'index.php?hbc_email_confirm_token=$matches[1]', 'top');
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'hbc_universal_entry';
    $vars[] = 'hbc_legacy_public_code';
    $vars[] = 'hbc_trade_entry';
    $vars[] = 'hbc_organize';
    $vars[] = 'hbc_return_entry';
    $vars[] = 'hbc_encounter_token';
    $vars[] = 'hbc_email_confirm_token';
    return $vars;
});

add_filter('template_include', function ($template) {
    if (get_query_var('hbc_universal_entry')) {
        return HBC_LAUGH_DIR . 'templates/respondent.php';
    }
    if (get_query_var('hbc_organize')) {
        return HBC_LAUGH_DIR . 'templates/organize.php';
    }
    if (get_query_var('hbc_trade_entry')) {
        $unlocked = class_exists('HBC_Prod_Store') && HBC_Prod_Store::trade_is_unlocked();
        return HBC_LAUGH_DIR . ( $unlocked ? 'templates/trade-active.php' : 'templates/trade-inactive.php' );
    }
    if (get_query_var('hbc_encounter_token')) {
        return HBC_LAUGH_DIR . 'templates/encounter.php';
    }
    if (get_query_var('hbc_email_confirm_token')) {
        return HBC_LAUGH_DIR . 'templates/confirm-email.php';
    }
    return $template;
});

/**
 * Path of the current request relative to the site home.
 */
function hbc_laugh_request_path() {
    $uri  = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '';
    $path = trim((string) wp_parse_url($uri, PHP_URL_PATH), '/');
    $home = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($home !== '' && str_starts_with($path . '/', $home . '/')) {
        $path = trim(substr($path, strlen($home)), '/');
    }
    return $path;
}

/**
 * Redeem hbc_ctx and send the visitor back to the same event.
 * A missing or expired token returns to /r without changing an RSVP.
 */
function hbc_laugh_redeem_return() {
    $ctx = isset($_GET['hbc_ctx']) ? sanitize_text_field(wp_unslash((string) $_GET['hbc_ctx'])) : '';
    if ($ctx === '' || !class_exists('HBC_Prod_Store')) {
        wp_safe_redirect(home_url('/r'));
        exit;
    }
    $result = HBC_Prod_Store::redeem_return_context($ctx);
    if (!empty($result['ok']) && !empty($result['redirect'])) {
        wp_safe_redirect((string) $result['redirect']);
        exit;
    }
    wp_safe_redirect(add_query_arg(array(
        'return_error' => 1,
    ), home_url('/r')));
    exit;
}

add_action('template_redirect', function () {
    if (get_query_var('hbc_legacy_public_code')) {
        wp_safe_redirect(home_url('/r'), 301);
        exit;
    }
    $is_return = (bool) get_query_var('hbc_return_entry')
        || (is_404() && hbc_laugh_request_path() === 'return');
    if ($is_return) {
        hbc_laugh_redeem_return();
    }
});

add_action('wp_enqueue_scripts', function () {
    $css_ver = (string) filemtime(HBC_LAUGH_DIR . 'assets/hbc.css');
    $js_ver  = (string) filemtime(HBC_LAUGH_DIR . 'assets/hbc.js');
    if (get_query_var('hbc_universal_entry') || get_query_var('hbc_organize') || get_query_var('hbc_trade_entry')) {
        wp_enqueue_style('hbc-laugh', plugins_url('assets/hbc.css', __FILE__), array(), $css_ver);
        wp_enqueue_script('hbc-laugh', plugins_url('assets/hbc.js', __FILE__), array(), $js_ver, true);
        wp_localize_script('hbc-laugh', 'HBC_LAUGH', array(
            'rest' => esc_url_raw(rest_url('hbc-laugh/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ));
    }
    if (get_query_var('hbc_trade_entry') && class_exists('HBC_Prod_Store') && HBC_Prod_Store::trade_is_unlocked()) {
        $trade_ver = (string) filemtime(HBC_LAUGH_DIR . 'assets/trade.js');
        wp_enqueue_script(
            'hbc-laugh-trade',
            plugins_url('assets/trade.js', __FILE__),
            array('hbc-laugh'),
            $trade_ver,
            true
        );
    }
    if (get_query_var('hbc_organize')) {
        wp_enqueue_script('hbc-laugh-agreement', plugins_url('assets/agreement.js', __FILE__), array(), HBC_LAUGH_VERSION, true);
        wp_enqueue_script('hbc-laugh-organize-status', plugins_url('assets/organize-status.js', __FILE__), array(), HBC_LAUGH_VERSION, true);
        wp_localize_script('hbc-laugh-agreement', 'HBC_LAUGH', array(
            'rest' => esc_url_raw(rest_url('hbc-laugh/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ));
    }
    if (get_query_var('hbc_encounter_token')) {
        wp_enqueue_style('hbc-laugh', plugins_url('assets/hbc.css', __FILE__), array(), $css_ver);
        wp_enqueue_script('hbc-laugh-encounter', plugins_url('assets/encounter.js', __FILE__), array(), HBC_LAUGH_VERSION, true);
        wp_localize_script('hbc-laugh-encounter', 'HBC_LAUGH', array(
            'rest' => esc_url_raw(rest_url('hbc-laugh/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ));
    }
    // confirm-email.php is server-rendered only; no script needed.
});

add_action('rest_api_init', array('HBC_Laugh_REST', 'register_routes'));

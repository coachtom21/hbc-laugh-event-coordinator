<?php
defined('ABSPATH') || exit;

final class HBC_Laugh_REST {
    private const NS = 'hbc-laugh/v1';
    private const HOST_AGREEMENT_KEY = 'host_communications_platform_terms';
    private const HOST_AGREEMENT_VERSION = '2026.09-v2.4.8';

    // Maps adapter-reported encounter error codes to HTTP status. Kept as one table so every
    // encounter endpoint reports the same status for the same structural failure. These are
    // HARD failures only (bad/expired/wrong-state token, or a caller with no standing to act
    // on this session) — legitimate business outcomes of a confirm attempt (declined,
    // walked_away, proximity_failed, same_device_rejected, disputed) are NOT errors: they are
    // returned as a 200 with that state in the body, because the call itself succeeded even
    // though the encounter did not end in confirmation. See ENCOUNTER_SESSION_STATES below.
    private const ENCOUNTER_ERROR_STATUS = array(
        'encounter_not_found' => 404,
        'encounter_expired' => 410,
        'encounter_not_pending' => 409,
        'operator_forbidden' => 403,
    );

    // Maps adapter-reported agreement-acceptance error codes to HTTP status, same "one table"
    // convention as ENCOUNTER_ERROR_STATUS above.
    private const AGREEMENT_ERROR_STATUS = array(
        'agreement_not_found' => 404,
        'agreement_content_mismatch' => 409,
        'host_forbidden' => 403,
    );

    // The full two-device confirmation state machine, per Coach Tom's non-negotiable rules.
    // Scanning/loading the QR (GET /encounters/{token}) must NEVER by itself move a session
    // past "pending" — only an explicit attendee-confirm and a separate, explicit
    // operator-confirm can advance it, and only the server (never the client) decides when
    // proximity/time/token conditions are satisfied.
    private const ENCOUNTER_SESSION_STATES = array(
        'pending', 'attendee_confirmed', 'operator_confirmed', 'proximity_verified', 'confirmed', 'pending_xp',
        'declined', 'walked_away', 'expired', 'proximity_failed', 'same_device_rejected', 'disputed', 'extinguished',
    );

    // Fail-closed dual-GPS proximity, enforced by THIS open-source contract, not left to the
    // private integration adapter (architecture/V2_DECISIONS.md, "Two-scan proof" — combined-
    // accuracy formula). This project is meant to run as an open-source testnet ahead of a
    // future mainnet execution: an outside reviewer of this repository must be able to verify
    // the 50-meter rule from the code and test suite here, without trusting an unpublished
    // adapter to have implemented it correctly. sanitize_location() below already rejects a
    // reading that is individually stale/inaccurate; enforce_fail_closed_proximity() below is
    // what actually compares the two devices' readings to each other and can override an
    // adapter's own reported outcome.
    private const PROXIMITY_MAX_COMBINED_METERS = 50.0;
    private const PROXIMITY_CONFIRMING_STATES = array('proximity_verified', 'confirmed', 'pending_xp');

    public static function register_routes(): void {
        register_rest_route(self::NS, '/public/entry', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'resolve_universal_entry'), 'permission_callback' => '__return_true'));

        register_rest_route(self::NS, '/public/registration/start', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'registration_start'), 'permission_callback' => '__return_true'));

        register_rest_route(self::NS, '/public/registration/verify', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'registration_verify'), 'permission_callback' => '__return_true'));

        register_rest_route(self::NS, '/public/events/(?P<event_uuid>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12})/rsvps', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'registered_device_rsvp'), 'permission_callback' => '__return_true'));

        // Creates a signed, identity-bound Miner membership checkout handoff after a completed RSVP.
        // The return URL is fixed server-side; returning from checkout never proves payment.
        register_rest_route(self::NS, '/public/membership/checkout', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'membership_checkout'), 'permission_callback' => '__return_true'));

        register_rest_route(self::NS, '/public/membership/status', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'membership_status'), 'permission_callback' => '__return_true'));

        register_rest_route(self::NS, '/public/walk-away', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'walk_away'), 'permission_callback' => '__return_true'));

        register_rest_route(self::NS, '/public/return-context', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'issue_return_context'), 'permission_callback' => '__return_true'));

        register_rest_route(self::NS, '/events/(?P<event_uuid>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12})/encounters', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'initiate_encounter'), 'permission_callback' => '__return_true'));

        // Scanned by the ATTENDEE's device from the operator's displayed Identity QR (the
        // /e/{token} page), and by scanners/link-preview bots that open it automatically.
        // MUST NOT consume the token or advance session state — read-only, idempotent, and
        // intentionally public so an unregistered device can be told to register first
        // instead of getting a bare 401.
        register_rest_route(self::NS, '/encounters/(?P<token>[A-Za-z0-9_-]{1,128})', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'resolve_encounter'), 'permission_callback' => '__return_true'));

        // The attendee's explicit Y/Y/Y answer plus Accept/Decline/Walk Away. Requires a
        // recognized device: if unregistered, the client must complete OTP registration
        // first and return to this same encounter, never send this request unauthenticated.
        register_rest_route(self::NS, '/public/encounters/(?P<token>[A-Za-z0-9_-]{1,128})/attendee-confirm', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'attendee_confirm_encounter'), 'permission_callback' => '__return_true'));

        // The operator's OWN explicit confirmation — operator_router's Initiate Encounter
        // click alone (POST /events/{event_uuid}/encounters) is never sufficient proof.
        // Addressed by session_uuid, not the attendee-facing token: the operator's device
        // never learns or needs the token it displayed as a QR.
        register_rest_route(self::NS, '/operator/encounters/(?P<session_uuid>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12})/confirm', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'operator_confirm_encounter'), 'permission_callback' => '__return_true'));

        register_rest_route(self::NS, '/operator/encounters/(?P<session_uuid>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12})/status', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'operator_encounter_status'), 'permission_callback' => '__return_true'));

        register_rest_route(self::NS, '/public/email-confirmation/resend', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'resend_email_confirmation'), 'permission_callback' => '__return_true'));

        // Digital-signature acceptance of a Host-facing agreement (e.g. the Host Communications
        // and Platform Terms of Service Agreement). permission_callback is __return_true because
        // the real gate is require_recognized_device() inside the callback, same pattern as
        // resend_email_confirmation above — this must never require a separate WP login.
        register_rest_route(self::NS, '/hosts/(?P<host_uuid>[0-9a-f-]{36})/agreements/(?P<agreement_key>[a-z0-9_-]{1,64})/accept', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'accept_host_agreement'), 'permission_callback' => '__return_true'));

        register_rest_route(self::NS, '/hosts/(?P<host_uuid>[0-9a-f-]{36})/agreements/(?P<agreement_key>[a-z0-9_-]{1,64})/status', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'host_agreement_status'), 'permission_callback' => '__return_true'));

        register_rest_route(self::NS, '/admin/hosts/(?P<host_uuid>[0-9a-f-]{36})/events', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'admin_list_events'), 'permission_callback' => array('HBC_Laugh_Auth', 'require_admin')),
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'admin_create_event'), 'permission_callback' => array('HBC_Laugh_Auth', 'require_admin'))));
        register_rest_route(self::NS, '/admin/hosts/(?P<host_uuid>[0-9a-f-]{36})/events/(?P<event_uuid>[0-9a-f-]{36})', array(
            'methods' => 'PATCH', 'callback' => array(__CLASS__, 'admin_update_event'), 'permission_callback' => array('HBC_Laugh_Auth', 'require_admin')));
        register_rest_route(self::NS, '/admin/hosts/(?P<host_uuid>[0-9a-f-]{36})/volunteers', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'admin_list_volunteers'), 'permission_callback' => array('HBC_Laugh_Auth', 'require_admin')));
        register_rest_route(self::NS, '/admin/hosts/(?P<host_uuid>[0-9a-f-]{36})/volunteers/(?P<uuid>[0-9a-f-]{36})/(?P<decision>accept|decline)', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'admin_review_volunteer'), 'permission_callback' => array('HBC_Laugh_Auth', 'require_admin')));
        register_rest_route(self::NS, '/admin/hosts/(?P<host_uuid>[0-9a-f-]{36})/reports', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'admin_host_reports'), 'permission_callback' => array('HBC_Laugh_Auth', 'require_admin')));
        register_rest_route(self::NS, '/super/overview', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'super_overview'), 'permission_callback' => array('HBC_Laugh_Auth', 'require_super_admin')));
        register_rest_route(self::NS, '/super/trade', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'super_trade_get'), 'permission_callback' => array('HBC_Laugh_Auth', 'require_super_admin')),
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'super_trade_post'), 'permission_callback' => array('HBC_Laugh_Auth', 'require_super_admin'))));
        register_rest_route(self::NS, '/super/volunteers', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'super_list_volunteers'), 'permission_callback' => array('HBC_Laugh_Auth', 'require_super_admin')));
        register_rest_route(self::NS, '/super/volunteers/(?P<uuid>[0-9a-f-]{36})/(?P<decision>accept|decline)', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'super_review_volunteer'), 'permission_callback' => array('HBC_Laugh_Auth', 'require_super_admin')));
        register_rest_route(self::NS, '/public/trade/offer', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'public_trade_offer'), 'permission_callback' => array('HBC_Laugh_Auth', 'require_recognized_device')));
    }

    public static function super_list_volunteers(): WP_REST_Response {
        if (!class_exists('HBC_Prod_Store')) {
            return self::not_connected('Production store');
        }
        return new WP_REST_Response(array('data' => HBC_Prod_Store::list_all_volunteers()), 200);
    }

    public static function super_review_volunteer(WP_REST_Request $request): WP_REST_Response {
        if (!class_exists('HBC_Prod_Store')) {
            return self::not_connected('Production store');
        }
        $uuid = sanitize_text_field((string) $request->get_param('uuid'));
        $decision = sanitize_key((string) $request->get_param('decision'));
        $decision = $decision === 'accept' ? 'accepted' : ($decision === 'decline' ? 'declined' : '');
        if (!self::is_uuid($uuid) || $decision === '') {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'Valid volunteer UUID and decision are required.')), 422);
        }
        $reviewer = is_user_logged_in() ? (string) get_user_meta(get_current_user_id(), 'hbc_identity_uuid', true) : '';
        $result = HBC_Prod_Store::review_volunteer($uuid, $decision, $reviewer);
        if (!empty($result['error'])) {
            return new WP_REST_Response(array('error' => $result['error']), 422);
        }
        return new WP_REST_Response(array('data' => $result), 200);
    }

    public static function admin_update_event(WP_REST_Request $request): WP_REST_Response {
        $host_uuid = sanitize_text_field((string) $request->get_param('host_uuid'));
        $event_uuid = sanitize_text_field((string) $request->get_param('event_uuid'));
        if (!self::is_uuid($host_uuid) || !self::is_uuid($event_uuid)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'Valid host and event UUIDs are required.')), 422);
        }
        if (!has_filter('hbc_admin_update_event')) {
            return self::not_connected('Admin event-update adapter');
        }
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = $request->get_params();
        }
        $result = apply_filters('hbc_admin_update_event', null, $host_uuid, $event_uuid, is_array($body) ? $body : array());
        if (is_array($result) && !empty($result['error']) && is_array($result['error'])) {
            return new WP_REST_Response(array('error' => $result['error']), 422);
        }
        if (!is_array($result)) {
            return new WP_REST_Response(array('error' => array('code' => 'update_failed', 'message' => 'Could not update the event.')), 502);
        }
        return new WP_REST_Response(array('data' => $result), 200);
    }

    public static function admin_list_volunteers(WP_REST_Request $request): WP_REST_Response {
        $host_uuid = sanitize_text_field((string) $request->get_param('host_uuid'));
        if (!self::is_uuid($host_uuid)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'A valid host UUID is required.')), 422);
        }
        if (!has_filter('hbc_admin_list_volunteers')) {
            return self::not_connected('Admin volunteer-list adapter');
        }
        $result = apply_filters('hbc_admin_list_volunteers', null, $host_uuid);
        if (!is_array($result)) {
            return self::not_connected('Admin volunteer-list adapter');
        }
        return new WP_REST_Response(array('data' => $result), 200);
    }

    public static function admin_review_volunteer(WP_REST_Request $request): WP_REST_Response {
        $uuid = sanitize_text_field((string) $request->get_param('uuid'));
        $host_uuid = sanitize_text_field((string) $request->get_param('host_uuid'));
        $decision = sanitize_key((string) $request->get_param('decision'));
        $decision = $decision === 'accept' ? 'accepted' : ($decision === 'decline' ? 'declined' : '');
        if (!self::is_uuid($uuid) || !self::is_uuid($host_uuid) || $decision === '') {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'Valid host, volunteer UUID and decision are required.')), 422);
        }
        if (!has_filter('hbc_admin_review_volunteer')) {
            return self::not_connected('Admin volunteer-review adapter');
        }
        if (class_exists('HBC_Prod_Store')) {
            $vols = HBC_Prod_Store::list_volunteers($host_uuid);
            $found = false;
            foreach (($vols['volunteers'] ?? array()) as $row) {
                if (($row['volunteer_request_uuid'] ?? '') === $uuid) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return new WP_REST_Response(array('error' => array('code' => 'not_found', 'message' => 'Volunteer request not found for this host.')), 404);
            }
        }
        $reviewer = '';
        if (is_user_logged_in()) {
            $reviewer = (string) get_user_meta(get_current_user_id(), 'hbc_identity_uuid', true);
        }
        $result = apply_filters('hbc_admin_review_volunteer', null, $uuid, $decision, $reviewer);
        if (is_array($result) && !empty($result['error'])) {
            return new WP_REST_Response(array('error' => $result['error']), 422);
        }
        return new WP_REST_Response(array('data' => is_array($result) ? $result : array()), 200);
    }

    public static function super_overview(): WP_REST_Response {
        if (!class_exists('HBC_Prod_Store')) {
            return self::not_connected('Production store');
        }
        $report = class_exists('HBC_Laugh_Readiness') ? HBC_Laugh_Readiness::report() : array();
        return new WP_REST_Response(array('data' => array(
            'trade' => HBC_Prod_Store::trade_status(),
            'registration' => class_exists('HBC_Laugh_Readiness') && HBC_Laugh_Readiness::registration_is_open() ? 'open' : 'closed',
            'readiness' => $report,
            'smallstreet_mirror' => HBC_Prod_Store::smallstreet_mirror_tail(10),
        )), 200);
    }

    public static function super_trade_get(): WP_REST_Response {
        if (!class_exists('HBC_Prod_Store')) {
            return self::not_connected('Production store');
        }
        return new WP_REST_Response(array('data' => HBC_Prod_Store::trade_status()), 200);
    }

    public static function super_trade_post(WP_REST_Request $request): WP_REST_Response {
        if (!class_exists('HBC_Prod_Store')) {
            return self::not_connected('Production store');
        }
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = $request->get_params();
        }
        $action = sanitize_key((string) ($body['action'] ?? ''));
        $reason = sanitize_text_field((string) ($body['reason'] ?? ''));
        $actor = wp_get_current_user()->user_login ?: 'super_admin';
        if ($action === 'authorize_seller' || $action === 'revoke_seller') {
            $identity = sanitize_text_field((string) ($body['identity_uuid'] ?? ''));
            $result = HBC_Prod_Store::set_seller_trade_authorization($identity, $action === 'authorize_seller', $reason, $actor);
        } elseif ($action === 'emergency') {
            $result = HBC_Prod_Store::trade_emergency_shutdown($reason, $actor);
        } elseif ($action === 'on' || $action === 'off') {
            $result = HBC_Prod_Store::set_trade_unlocked($action === 'on', $reason, $actor);
        } else {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'action must be on, off, emergency, authorize_seller, or revoke_seller.')), 422);
        }
        if (!empty($result['error'])) {
            $code = sanitize_key((string) ($result['error']['code'] ?? 'trade_error'));
            $status = $code === 'trade_emergency_locked' ? 423 : 422;
            return new WP_REST_Response(array('error' => $result['error']), $status);
        }
        return new WP_REST_Response(array('data' => $result), 200);
    }

    public static function public_trade_offer(WP_REST_Request $request) {
        $identity = HBC_Laugh_Auth::require_recognized_device($request);
        if (is_wp_error($identity)) {
            $code = $identity->get_error_code() === 'hbc_integration_required' ? 'integration_required' : 'device_token_invalid';
            return new WP_REST_Response(array('error' => array('code' => $code, 'message' => $identity->get_error_message())), (int) ($identity->get_error_data()['status'] ?? 401));
        }
        if (!class_exists('HBC_Prod_Store')) {
            return self::not_connected('Production store');
        }
        $body = $request->get_json_params();
        $event_uuid = sanitize_text_field((string) (is_array($body) ? ($body['event_uuid'] ?? '') : ''));
        if (!self::is_uuid($event_uuid)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'A valid event UUID is required.')), 422);
        }
        $result = HBC_Prod_Store::begin_trade_offer($event_uuid, (string) $identity);
        if (!empty($result['error'])) {
            return new WP_REST_Response(array('error' => $result['error']), 403);
        }
        return new WP_REST_Response(array('data' => $result), 201);
    }

    /**
     * Tenant-scoped event directory for organizers (draft + published).
     */
    public static function admin_list_events(WP_REST_Request $request): WP_REST_Response {
        $host_uuid = sanitize_text_field((string) $request->get_param('host_uuid'));
        if (!self::is_uuid($host_uuid)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'A valid host UUID is required.')), 422);
        }
        if (!has_filter('hbc_admin_list_events')) {
            return self::not_connected('Admin event-list adapter');
        }
        $result = apply_filters('hbc_admin_list_events', null, $host_uuid);
        if (!is_array($result) || !isset($result['events']) || !is_array($result['events'])) {
            return self::not_connected('Admin event-list adapter');
        }
        return new WP_REST_Response(array('data' => $result), 200);
    }

    /**
     * Create a community or Practice FAITH event under an assigned host.
     * Optional short event_code makes it discoverable on /r.
     */
    public static function admin_create_event(WP_REST_Request $request): WP_REST_Response {
        $host_uuid = sanitize_text_field((string) $request->get_param('host_uuid'));
        if (!self::is_uuid($host_uuid)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'A valid host UUID is required.')), 422);
        }
        if (class_exists('HBC_Prod_Store') && !HBC_Prod_Store::user_is_active_miner(get_current_user_id())) {
            return new WP_REST_Response(array('error' => array(
                'code' => 'miner_required',
                'message' => 'An active Miner membership is required to create an event. An expired Miner can still view history.',
            )), 403);
        }
        if (!has_filter('hbc_admin_create_event')) {
            return self::not_connected('Admin event-create adapter');
        }
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = $request->get_params();
        }
        $result = apply_filters('hbc_admin_create_event', null, $host_uuid, is_array($body) ? $body : array());
        if (is_array($result) && !empty($result['error']) && is_array($result['error'])) {
            $code = sanitize_key((string) ($result['error']['code'] ?? 'validation_error'));
            $message = sanitize_text_field((string) ($result['error']['message'] ?? 'Could not create the event.'));
            $status = in_array($code, array('validation_error', 'invalid_event_code', 'duplicate_event_code'), true) ? 422 : 502;
            return new WP_REST_Response(array('error' => array('code' => $code, 'message' => $message)), $status);
        }
        if (!is_array($result) || empty($result['event_uuid'])) {
            return new WP_REST_Response(array('error' => array('code' => 'create_failed', 'message' => 'Could not create the event.')), 502);
        }
        return new WP_REST_Response(array('data' => $result), 201);
    }

    /**
     * Canonical platform-wide QR entry. With no event parameter, returns the current public
     * event directory. With an event UUID, resolves device and assignment state for that
     * selected event. No Host can own, replace or configure this entry route.
     */
    public static function resolve_universal_entry(WP_REST_Request $request): WP_REST_Response {
        $event_uuid = sanitize_text_field((string) $request->get_param('event'));
        $event_code = strtoupper(sanitize_text_field((string) $request->get_param('event_code')));
        $device_token = sanitize_text_field((string) $request->get_header('x-hbc-device-token'));

        if ($event_uuid === '' && $event_code !== '') {
            if (!preg_match('/^[A-Z0-9-]{4,16}$/', $event_code)) {
                return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'Enter a valid event code.')), 422);
            }
            if (!has_filter('hbc_resolve_event_code')) {
                return self::not_connected('Event-code resolver adapter');
            }
            $event_uuid = (string) apply_filters('hbc_resolve_event_code', '', $event_code);
            if (!self::is_uuid($event_uuid)) {
                return new WP_REST_Response(array('error' => array('code' => 'event_unavailable', 'message' => 'That event code is unavailable.')), 404);
            }
        }

        if ($event_uuid === '') {
            $limit = (int) $request->get_param('limit');
            $limit = $limit > 0 ? min($limit, 20) : 20;
            $cursor = sanitize_text_field((string) $request->get_param('cursor'));
            $query = sanitize_text_field((string) $request->get_param('q'));
            $org = sanitize_text_field((string) $request->get_param('org'));
            if (($cursor !== '' && !preg_match('/^[A-Za-z0-9_-]{1,256}$/', $cursor)) || mb_strlen($query) > 100) {
                return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'Invalid directory query or cursor.')), 422);
            }
            if (!has_filter('hbc_list_public_events')) {
                return self::not_connected('Universal event-directory adapter');
            }
            $page = apply_filters('hbc_list_public_events', null, array(
                'device_token' => $device_token,
                'limit' => $limit,
                'cursor' => $cursor !== '' ? $cursor : null,
                'query' => $query,
                'org' => $org,
            ));
            if (!is_array($page) || !isset($page['events']) || !is_array($page['events'])) {
                return self::not_connected('Universal event-directory adapter');
            }
            if (!empty($page['org_invalid'])) {
                return new WP_REST_Response(array('error' => array(
                    'code' => 'organization_unavailable',
                    'message' => 'That organization code is not registered. Check the postcard QR or ask the host for their public code.',
                )), 404);
            }
            $data = array(
                'canonical_entry_url' => home_url('/r'),
                'selection_required' => true,
                'presence_status' => 'not_confirmed',
                'events' => array_values(array_slice($page['events'], 0, $limit)),
                'has_more' => !empty($page['has_more']),
                'next_cursor' => !empty($page['next_cursor']) ? sanitize_text_field((string) $page['next_cursor']) : null,
                'query' => $query,
            );
            if (!empty($page['organization']) && is_array($page['organization'])) {
                $data['organization'] = $page['organization'];
            }
            return new WP_REST_Response(array('data' => $data), 200);
        }
        if (!self::is_uuid($event_uuid)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'A valid event UUID is required.')), 422);
        }
        if (!has_filter('hbc_resolve_universal_entry')) {
            return self::not_connected('Universal event resolver adapter');
        }
        $resolved = apply_filters('hbc_resolve_universal_entry', null, $event_uuid, $device_token);
        if (!$resolved) {
            return new WP_REST_Response(array('error' => array(
                'code' => 'event_unavailable',
                'message' => 'This Human Gold event is unavailable.',
            )), 404);
        }
        return new WP_REST_Response(array('data' => array_merge($resolved, array(
            'canonical_entry_url' => 'https://humanblockchain.info/r',
            'selected_event_uuid' => $event_uuid,
            'presence_status' => 'not_confirmed',
        ))), 200);
    }

    /**
     * device_registration only. Called after the unregistered device has already submitted
     * its Observe/Participate choice (carried in $body['response']) from the same event/
     * calendar picker a registered device sees. The adapter is expected to return an array
     * containing 'registration_session_id' — that is the ONLY session-identifier name this
     * contract recognizes; do not accept or emit challenge_id / session_token / registration_session.
     */
    public static function registration_start(WP_REST_Request $request): WP_REST_Response {
        if (!HBC_Laugh_Readiness::registration_is_open()) {
            return new WP_REST_Response(array('error' => array(
                'code' => 'registration_paused',
                'message' => 'New-device registration is temporarily paused. Existing registered devices and completed records are unaffected.',
            )), 503);
        }
        $body = $request->get_json_params();
        $email = sanitize_email($body['email'] ?? '');
        $phone = preg_replace('/[^0-9+]/', '', (string) ($body['phone'] ?? ''));
        $event_uuid = sanitize_text_field((string) ($body['event_uuid'] ?? ''));
        $response = in_array($body['response'] ?? '', array('observe', 'participate'), true) ? $body['response'] : '';
        $touchstone_word = sanitize_text_field((string) ($body['touchstone_word'] ?? ''));
        $peace_pentagon_branch = sanitize_text_field((string) ($body['peace_pentagon_branch'] ?? ''));
        $display_name = sanitize_text_field((string) ($body['display_name'] ?? $body['name'] ?? ''));
        $mailing_address = sanitize_textarea_field((string) ($body['mailing_address'] ?? ''));
        if (!$email || strlen($phone) < 10 || empty($body['verification_consent']) || !self::is_uuid($event_uuid) || !$response) {
            return new WP_REST_Response(array('error' => array('code' => 'invalid_registration', 'message' => 'Valid email, phone and verification consent are required. Name and mailing address are optional.')), 422);
        }
        if (($touchstone_word === '') !== ($peace_pentagon_branch === '')) {
            return new WP_REST_Response(array('error' => array('code' => 'invalid_registration', 'message' => 'Touchstone and Peace Pentagon branch must be submitted together.')), 422);
        }
        if (!has_filter('hbc_begin_registration')) {
            return self::not_connected('OTP registration adapter');
        }
        $result = apply_filters('hbc_begin_registration', null, array_merge($body, array(
            'email' => $email,
            'phone' => $phone,
            'display_name' => $display_name,
            'mailing_address' => $mailing_address,
            'touchstone_word' => $touchstone_word,
            'peace_pentagon_branch' => $peace_pentagon_branch,
            'entry_source' => 'universal_human_gold_qr',
        )));
        if (is_array($result) && !empty($result['error']) && is_array($result['error'])) {
            $code = sanitize_key((string) ($result['error']['code'] ?? 'otp_send_failed'));
            $message = sanitize_text_field((string) ($result['error']['message'] ?? 'Could not start verification.'));
            $status = in_array($code, array('invalid_registration', 'invalid_phone'), true) ? 422 : 502;
            return new WP_REST_Response(array('error' => array('code' => $code, 'message' => $message)), $status);
        }
        if (!$result || empty($result['registration_session_id'])) {
            return self::not_connected('OTP registration adapter');
        }
        return new WP_REST_Response(array('data' => $result), 202);
    }

    public static function registration_verify(WP_REST_Request $request): WP_REST_Response {
        $body = $request->get_json_params();
        if (empty($body['registration_session_id'])) {
            return new WP_REST_Response(array('error' => array('code' => 'invalid_registration', 'message' => 'A registration_session_id is required.')), 422);
        }
        if (!preg_match('/^[0-9]{6}$/', (string) ($body['code'] ?? ''))) {
            return new WP_REST_Response(array('error' => array('code' => 'invalid_code', 'message' => 'Enter the six-digit verification code.')), 422);
        }
        if (!has_filter('hbc_complete_registration')) {
            return self::not_connected('OTP verification and RSVP transaction adapter');
        }
        $result = apply_filters('hbc_complete_registration', null, $body);
        if (!$result) {
            return new WP_REST_Response(array('error' => array('code' => 'invalid_code', 'message' => 'That code is invalid or has expired.')), 422);
        }
        return new WP_REST_Response(array('data' => $result), 201);
    }

    /**
     * respondent_rsvp only. A REGISTERED device saves its RSVP directly here, with no OTP
     * step. Authorization comes ONLY from the server-side device-token resolution in
     * HBC_Laugh_Auth::require_recognized_device — the client's device_state is advisory UI
     * state and is never trusted to authorize this write.
     */
    public static function registered_device_rsvp(WP_REST_Request $request): WP_REST_Response {
        $identity_uuid = HBC_Laugh_Auth::require_recognized_device($request);
        if (is_wp_error($identity_uuid)) {
            $err = $identity_uuid;
            $code = $err->get_error_code() === 'hbc_integration_required' ? 'integration_required' : 'device_token_invalid';
            return new WP_REST_Response(array('error' => array('code' => $code, 'message' => $err->get_error_message())), (int) $err->get_error_data()['status']);
        }

        $event_uuid = sanitize_text_field((string) $request->get_param('event_uuid'));
        if (!self::is_uuid($event_uuid)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'A valid event_uuid is required.')), 422);
        }
        $body = $request->get_json_params();
        $response = in_array($body['response'] ?? '', array('observe', 'participate', 'walk_away'), true) ? $body['response'] : null;
        if (!$response) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'response must be observe, participate, or walk_away.')), 422);
        }

        if (!has_filter('hbc_save_registered_device_rsvp')) {
            return self::not_connected('Registered-device RSVP adapter');
        }
        $practice_faith = array(
            'touchstone_word' => sanitize_text_field((string) ($body['touchstone_word'] ?? '')),
            'peace_pentagon_branch' => sanitize_text_field((string) ($body['peace_pentagon_branch'] ?? '')),
        );
        if ($response !== 'walk_away' && ($practice_faith['touchstone_word'] === '') !== ($practice_faith['peace_pentagon_branch'] === '')) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'Touchstone and Peace Pentagon branch must be submitted together.')), 422);
        }
        $result = apply_filters('hbc_save_registered_device_rsvp', null, $event_uuid, $identity_uuid, $response, $practice_faith);
        if (!$result) {
            return self::not_connected('Registered-device RSVP adapter');
        }
        return new WP_REST_Response(array('data' => $result), 200);
    }

    /**
     * Begins the optional $12 annual Miner membership flow for the recognized identity.
     * The adapter must bind signed WooCommerce order metadata to $identity_uuid and use the
     * canonical /organize return. It may instead report already_active without making an order.
     * Membership is activated only by a later verified WooCommerce webhook.
     */
    public static function membership_checkout(WP_REST_Request $request): WP_REST_Response {
        $identity_uuid = HBC_Laugh_Auth::require_recognized_device($request);
        if (is_wp_error($identity_uuid)) {
            $err = $identity_uuid;
            $code = $err->get_error_code() === 'hbc_integration_required' ? 'integration_required' : 'device_token_invalid';
            return new WP_REST_Response(array('error' => array('code' => $code, 'message' => $err->get_error_message())), (int) $err->get_error_data()['status']);
        }

        if (!has_filter('hbc_create_membership_checkout')) {
            return self::not_connected('Miner membership-checkout adapter');
        }
        $return_url = 'https://humanblockchain.info/organize';
        $result = apply_filters('hbc_create_membership_checkout', null, $identity_uuid, null, $return_url);
        if (!$result || empty($result['status'])) {
            return self::not_connected('Miner membership-checkout adapter');
        }

        if ($result['status'] === 'already_active') {
            return new WP_REST_Response(array('data' => array(
                'status' => 'already_active',
                'redirect_url' => $return_url,
            )), 200);
        }
        if ($result['status'] !== 'checkout_required' || empty($result['checkout_url'])) {
            return self::not_connected('Miner membership-checkout adapter');
        }
        $checkout_url = esc_url_raw((string) $result['checkout_url']);
        if (!self::membership_checkout_destination_allowed($checkout_url)) {
            return self::not_connected('Miner membership-checkout adapter (invalid destination)');
        }
        return new WP_REST_Response(array('data' => array(
            'status' => 'checkout_required',
            'redirect_url' => $checkout_url,
        )), 201);
    }

    /** Reconciles /organize against server membership state after a browser return. */
    public static function membership_status(WP_REST_Request $request): WP_REST_Response {
        $identity_uuid = HBC_Laugh_Auth::require_recognized_device($request);
        if (is_wp_error($identity_uuid)) {
            $err = $identity_uuid;
            $code = $err->get_error_code() === 'hbc_integration_required' ? 'integration_required' : 'device_token_invalid';
            return new WP_REST_Response(array('error' => array('code' => $code, 'message' => $err->get_error_message())), (int) $err->get_error_data()['status']);
        }
        if (!has_filter('hbc_membership_status')) {
            return self::not_connected('Miner membership-status adapter');
        }
        $result = apply_filters('hbc_membership_status', null, $identity_uuid);
        if (!$result || empty($result['state'])) {
            return self::not_connected('Miner membership-status adapter');
        }
        return new WP_REST_Response(array('data' => $result), 200);
    }

    /**
     * Safe to call with no session (per Coach Tom: no server call is required before a
     * registration session exists). Once /public/registration/start has returned a
     * registration_session_id, the client MUST send it here so it is discarded server-side.
     */
    public static function walk_away(WP_REST_Request $request): WP_REST_Response {
        $body = $request->get_json_params();
        $session_id = sanitize_text_field((string) ($body['registration_session_id'] ?? ''));
        if ($session_id !== '') {
            do_action('hbc_discard_registration_session', $session_id);
        }
        return new WP_REST_Response(null, 204);
    }

    /**
     * Issue opaque hbc_ctx for Megavoters/LLB closed-loop return (file 33).
     * Never accepts a client-supplied return_url.
     */
    public static function issue_return_context(WP_REST_Request $request): WP_REST_Response {
        if (!class_exists('HBC_Prod_Store')) {
            return self::not_connected('Production store');
        }
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = $request->get_params();
        }
        $event_uuid = sanitize_text_field((string) ($body['event_uuid'] ?? ''));
        $org_code   = sanitize_text_field((string) ($body['org'] ?? $body['org_code'] ?? ''));
        if (!self::is_uuid($event_uuid)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'A valid event_uuid is required.')), 422);
        }
        $result = HBC_Prod_Store::issue_return_context($event_uuid, $org_code);
        if (!empty($result['error'])) {
            return new WP_REST_Response(array('error' => $result['error']), 422);
        }
        return new WP_REST_Response(array('data' => $result), 201);
    }

    /**
     * Host overview / RSVP / door QR workplace snapshot for /organize.
     */
    public static function admin_host_reports(WP_REST_Request $request): WP_REST_Response {
        if (!class_exists('HBC_Prod_Store')) {
            return self::not_connected('Production store');
        }
        $host_uuid = sanitize_text_field((string) $request->get_param('host_uuid'));
        if (!self::is_uuid($host_uuid)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'A valid host_uuid is required.')), 422);
        }
        if (is_user_logged_in() && class_exists('HBC_Prod_Store')) {
            $allowed = (bool) apply_filters('hbc_user_can_access_host', false, get_current_user_id(), $host_uuid);
            if (!$allowed && !current_user_can('manage_options')) {
                return new WP_REST_Response(array('error' => array('code' => 'forbidden', 'message' => 'You do not have access to this organization.')), 403);
            }
        }
        return new WP_REST_Response(array('data' => HBC_Prod_Store::host_dashboard_snapshot($host_uuid)), 200);
    }

    /**
     * operator_router only. Authorization is the same five-condition, server-side-only test
     * as RouteResponse.operator_authorized — never trust the client to have gated this call.
     */
    public static function initiate_encounter(WP_REST_Request $request): WP_REST_Response {
        $identity_uuid = HBC_Laugh_Auth::require_recognized_device($request);
        if (is_wp_error($identity_uuid)) {
            $err = $identity_uuid;
            $code = $err->get_error_code() === 'hbc_integration_required' ? 'integration_required' : 'device_token_invalid';
            return new WP_REST_Response(array('error' => array('code' => $code, 'message' => $err->get_error_message())), (int) $err->get_error_data()['status']);
        }

        $event_uuid = sanitize_text_field((string) $request->get_param('event_uuid'));
        if (!self::is_uuid($event_uuid)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'A valid event_uuid is required.')), 422);
        }
        $body = $request->get_json_params();
        $encounter_type = sanitize_key((string) ($body['encounter_type'] ?? 'gratitude'));
        if (!in_array($encounter_type, array('presence', 'gratitude', 'trade'), true)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'encounter_type must be presence, gratitude, or trade.')), 422);
        }
        if ($encounter_type === 'trade') {
            $trade_ok = class_exists('HBC_Prod_Store') && HBC_Prod_Store::can_offer_trade((string) $identity_uuid);
            if (!$trade_ok) {
                return new WP_REST_Response(array('error' => array('code' => 'trade_not_live', 'message' => 'Trade is not live for this seller.')), 403);
            }
        }
        $assignment_uuid = sanitize_text_field((string) ($body['assignment_uuid'] ?? ''));
        $operator_role = sanitize_key((string) ($body['operator_role'] ?? ''));
        if (!in_array($operator_role, array('greeter', 'host', 'volunteer'), true)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'operator_role must be host, greeter, or volunteer.')), 422);
        }
        if ($operator_role === 'volunteer') {
            if (!class_exists('HBC_Prod_Store')) {
                return self::not_connected('Volunteer assignment');
            }
            $assignment = HBC_Prod_Store::accepted_volunteer_for_event((string) $identity_uuid, $event_uuid);
            if (!$assignment) {
                return new WP_REST_Response(array('error' => array(
                    'code' => 'operator_forbidden',
                    'message' => 'Only a Volunteer the host has accepted for this event can start an Encounter here.',
                )), 403);
            }
            $assignment_uuid = $assignment['assignment_uuid'];
        } else {
            if (!self::is_uuid($assignment_uuid)) {
                return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'A valid assignment_uuid is required for a host or Greeter.')), 422);
            }
            $assignment = HBC_Laugh_Auth::resolve_operator_assignment($identity_uuid, $event_uuid, $assignment_uuid, $operator_role);
            if (!$assignment) {
                return new WP_REST_Response(array('error' => array('code' => 'operator_forbidden', 'message' => 'The selected event assignment is not active for this device.')), 403);
            }
        }
        if (class_exists('HBC_Prod_Store')) {
            $miner_block = HBC_Prod_Store::encounter_start_block((string) $identity_uuid, $event_uuid);
            if (is_array($miner_block)) {
                return new WP_REST_Response(array('error' => $miner_block), 403);
            }
        }

        if (!has_filter('hbc_issue_encounter_token')) {
            return self::not_connected('Encounter/XP issuance adapter');
        }
        // The adapter is responsible for: generating a random token, storing ONLY its hash
        // (never the raw token) alongside a fresh session_uuid in state=pending, and returning
        // both identifiers. encounter_token is embedded in the QR payload as /e/{encounter_token}
        // for the attendee; session_uuid is kept by the OPERATOR's own client for the two
        // operator/encounters/{session_uuid}/* calls below — the operator's device never needs
        // or learns the raw token it is displaying.
        $result = apply_filters('hbc_issue_encounter_token', null, $event_uuid, $identity_uuid, $encounter_type, $assignment_uuid, $operator_role);
        if (is_array($result) && !empty($result['error'])) {
            return new WP_REST_Response(array('error' => $result['error']), 403);
        }
        if (!$result || empty($result['encounter_token']) || empty($result['session_uuid']) || empty($result['identity_qr_image_url']) || empty($result['expires_at'])) {
            return self::not_connected('Encounter/XP issuance adapter');
        }
        $result['state'] = 'pending';
        $result['assignment_uuid'] = $assignment_uuid;
        $result['operator_role_at_time'] = $operator_role;
        return new WP_REST_Response(array('data' => $result), 201);
    }

    /**
     * The attendee's device opened /e/{token} from the operator's displayed Identity QR, or a
     * scanner/link-preview bot opened it automatically. This MUST be read-only and idempotent:
     * it never advances session state, so it is safe to call any number of times before, during,
     * and after confirmation. Intentionally public (no device-token requirement) — an
     * unregistered device needs to see "register with this event first," not a bare 401, and the
     * token itself carries no identity or contact data (a random string bound server-side to one
     * event_uuid + session_uuid). Only attendee_confirm_encounter() below can change state.
     */
    public static function resolve_encounter(WP_REST_Request $request): WP_REST_Response {
        $token = sanitize_text_field((string) $request->get_param('token'));

        if (!has_filter('hbc_resolve_encounter_token')) {
            return self::not_connected('Encounter resolution adapter');
        }

        $result = apply_filters('hbc_resolve_encounter_token', null, $token);
        return self::encounter_result_response($result, 'This encounter is unavailable.');
    }

    /**
     * The attendee's explicit Y/Y/Y answer and Accept/Decline/Walk-Away choice. Requires a
     * recognized device (401 if missing/invalid) — if this device is unregistered, the client
     * MUST complete /public/registration/start+verify against this event first and only then
     * retry this call; there is no anonymous path to accepting XP Human Gold. On its own this
     * NEVER completes the encounter — it only ever reaches attendee_confirmed (or a terminal
     * non-confirming state); operator_confirm_encounter() below is still required.
     */
    public static function attendee_confirm_encounter(WP_REST_Request $request): WP_REST_Response {
        $identity_uuid = HBC_Laugh_Auth::require_recognized_device($request);
        if (is_wp_error($identity_uuid)) {
            $err = $identity_uuid;
            $code = $err->get_error_code() === 'hbc_integration_required' ? 'integration_required' : 'device_token_invalid';
            return new WP_REST_Response(array('error' => array('code' => $code, 'message' => $err->get_error_message())), (int) $err->get_error_data()['status']);
        }

        $token = sanitize_text_field((string) $request->get_param('token'));
        $body = $request->get_json_params();
        $action = in_array($body['action'] ?? '', array('accept', 'decline', 'walk_away'), true) ? $body['action'] : null;
        if (!$action) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'action must be accept, decline, or walk_away.')), 422);
        }

        $answers = null;
        if ($action === 'accept') {
            $raw = is_array($body['answers'] ?? null) ? $body['answers'] : array();
            $answers = array(
                'encounter_happened' => !empty($raw['encounter_happened']),
                'gateway_correct' => !empty($raw['gateway_correct']),
                'accept_presence_record' => !empty($raw['accept_presence_record']),
            );
            // Accept requires a genuine Y/Y/Y — a client that has any "no" must submit
            // decline or walk_away instead of accept with a partially-false answer set.
            if (!$answers['encounter_happened'] || !$answers['gateway_correct'] || !$answers['accept_presence_record']) {
                return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'Accepting requires yes to all three questions. Choose Decline or Walk Away otherwise.')), 422);
            }
        }

        $location = self::sanitize_location($body['location'] ?? null);
        if ($action === 'accept' && $location === null) {
            return new WP_REST_Response(array('error' => array(
                'code' => 'proximity_required',
                'message' => 'A fresh device location with accuracy of 50 meters or better is required to accept this encounter.'
            )), 422);
        }

        if (!has_filter('hbc_attendee_confirm_encounter')) {
            return self::not_connected('Attendee encounter-confirmation adapter');
        }
        $result = apply_filters('hbc_attendee_confirm_encounter', null, $token, $identity_uuid, $action, $answers, $location);
        return self::encounter_result_response($result, 'This encounter could not be confirmed.');
    }

    /**
     * The operator's OWN explicit confirmation of an encounter they initiated. Rule: operator
     * initiation alone (POST /events/{event_uuid}/encounters) is never final proof — this call
     * is the second, independent confirmation the server requires before the encounter can
     * reach proximity_verified/confirmed/pending_xp. Requires a recognized device; the adapter
     * is responsible for verifying this identity actually holds the operator side of this
     * session (never assumed from the URL alone) and for rejecting a same-identity attempt on
     * both sides of one encounter via the same_device_rejected state.
     *
     * Open-source fail-closed proximity: the adapter's job is to PERSIST each side's location
     * reading and echo the attendee's stored reading back as 'attendee_location' whenever it
     * reports a confirming state — it is no longer trusted to compute the combined-accuracy
     * distance formula itself. enforce_fail_closed_proximity() below does that comparison in
     * this contract and overrides the outcome to proximity_failed if the adapter's own state
     * disagrees with what the two readings actually show. This is deliberate for a project
     * meant to run as an open-source testnet: the 50-meter guarantee needs to be verifiable by
     * reading this repository, not dependent on a private adapter implementation.
     */
    public static function operator_confirm_encounter(WP_REST_Request $request): WP_REST_Response {
        $identity_uuid = HBC_Laugh_Auth::require_recognized_device($request);
        if (is_wp_error($identity_uuid)) {
            $err = $identity_uuid;
            $code = $err->get_error_code() === 'hbc_integration_required' ? 'integration_required' : 'device_token_invalid';
            return new WP_REST_Response(array('error' => array('code' => $code, 'message' => $err->get_error_message())), (int) $err->get_error_data()['status']);
        }

        $session_uuid = sanitize_text_field((string) $request->get_param('session_uuid'));
        if (!self::is_uuid($session_uuid)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'A valid session_uuid is required.')), 422);
        }
        $body = $request->get_json_params();
        $location = self::sanitize_location($body['location'] ?? null);
        $visual_attestation = ($body['visual_attestation'] ?? false) === true;
        if ($location === null) {
            return new WP_REST_Response(array('error' => array(
                'code' => 'proximity_required',
                'message' => 'A fresh device location with accuracy of 50 meters or better is required to confirm this encounter.'
            )), 422);
        }
        if (!$visual_attestation) {
            return new WP_REST_Response(array('error' => array(
                'code' => 'visual_attestation_required',
                'message' => 'The operator must attest that the attendee and their device are visibly present.'
            )), 422);
        }

        if (!has_filter('hbc_encounter_proximity_evidence') || !has_filter('hbc_operator_confirm_encounter')) {
            return self::not_connected('Encounter proximity-evidence and operator-confirmation adapters');
        }

        // This read-only lookup MUST happen before the adapter that can confirm or issue XP.
        $evidence = apply_filters('hbc_encounter_proximity_evidence', null, $session_uuid, $identity_uuid);
        $proximity = self::verify_proximity_evidence($evidence, $session_uuid, (string) $identity_uuid, $location);
        if (!$proximity['passed']) {
            return self::encounter_result_response($proximity['result'], 'The encounter did not satisfy proof of presence.');
        }

        $result = apply_filters('hbc_operator_confirm_encounter', null, $session_uuid, $identity_uuid, $location, $visual_attestation);
        if (is_array($result) && in_array($result['state'] ?? '', self::PROXIMITY_CONFIRMING_STATES, true)) {
            $result['proximity_assurance'] = 'confirmed_within_50m';
            $result['distance_meters'] = $proximity['distance_meters'];
            $result['conservative_distance_meters'] = $proximity['conservative_distance_meters'];
        }
        return self::encounter_result_response($result, 'This encounter could not be confirmed.');
    }

    /**
     * Lets the operator's own client poll for what the attendee has done, so it can enable its
     * own Confirm button only once attendee_confirmed is reached (or show the encounter ended
     * in declined/walked_away/expired without ever bothering the operator to confirm nothing).
     */
    public static function operator_encounter_status(WP_REST_Request $request): WP_REST_Response {
        $identity_uuid = HBC_Laugh_Auth::require_recognized_device($request);
        if (is_wp_error($identity_uuid)) {
            $err = $identity_uuid;
            $code = $err->get_error_code() === 'hbc_integration_required' ? 'integration_required' : 'device_token_invalid';
            return new WP_REST_Response(array('error' => array('code' => $code, 'message' => $err->get_error_message())), (int) $err->get_error_data()['status']);
        }

        $session_uuid = sanitize_text_field((string) $request->get_param('session_uuid'));
        if (!self::is_uuid($session_uuid)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'A valid session_uuid is required.')), 422);
        }

        if (!has_filter('hbc_operator_encounter_status')) {
            return self::not_connected('Operator encounter-status adapter');
        }
        $result = apply_filters('hbc_operator_encounter_status', null, $session_uuid, $identity_uuid);
        return self::encounter_result_response($result, 'This encounter session is unavailable.');
    }

    /**
     * Shared response mapping for every encounter endpoint. A hard structural failure comes
     * back as {'error': {'code', 'message'}} from the adapter and is mapped through
     * ENCOUNTER_ERROR_STATUS (404/410/409/403/501). Anything else is a legitimate outcome —
     * including a non-confirming terminal state such as declined, walked_away,
     * same_device_rejected, proximity_failed, or disputed — and is always returned as 200 with
     * the resulting state in the body, because the API call itself succeeded even when the
     * encounter did not end in confirmation.
     */
    private static function encounter_result_response($result, string $defaultMessage): WP_REST_Response {
        if (!$result) {
            return self::not_connected('Encounter adapter');
        }
        if (!empty($result['error'])) {
            $code = $result['error']['code'] ?? 'encounter_not_found';
            $status = self::ENCOUNTER_ERROR_STATUS[$code] ?? 404;
            $message = $result['error']['message'] ?? $defaultMessage;
            return new WP_REST_Response(array('error' => array('code' => $code, 'message' => $message)), $status);
        }
        if (!empty($result['state']) && !in_array($result['state'], self::ENCOUNTER_SESSION_STATES, true)) {
            // The adapter returned a state this contract does not define — fail closed rather
            // than let an unrecognized value silently reach the client as if it were valid.
            return self::not_connected('Encounter adapter (unrecognized state)');
        }
        return new WP_REST_Response(array('data' => $result), 200);
    }

    /**
     * Pilot proof-of-presence location. A usable reading contains latitude, longitude,
     * accuracy_meters <= 50, and a captured_at timestamp no older than 30 seconds (with at
     * most five seconds of clock skew into the future). Invalid readings fail closed.
     */
    private static function sanitize_location($raw): ?array {
        if (!is_array($raw) || !isset($raw['latitude'], $raw['longitude'], $raw['accuracy_meters'], $raw['captured_at'])) {
            return null;
        }
        $lat = is_numeric($raw['latitude']) ? (float) $raw['latitude'] : null;
        $lng = is_numeric($raw['longitude']) ? (float) $raw['longitude'] : null;
        $accuracy = is_numeric($raw['accuracy_meters']) ? (float) $raw['accuracy_meters'] : null;
        $captured_at = is_string($raw['captured_at']) ? strtotime($raw['captured_at']) : false;
        $now = time();
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180
            || $accuracy === null || $accuracy < 0 || $accuracy > 50
            || $captured_at === false || $captured_at < ($now - 30) || $captured_at > ($now + 5)) {
            return null;
        }
        return array(
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy_meters' => $accuracy,
            'captured_at' => gmdate('c', $captured_at),
        );
    }

    /**
     * A lighter structural check (no "now"-relative freshness) for a location an adapter
     * echoes BACK from earlier storage — the attendee's reading, captured at accept time and
     * already proven fresh/accurate AT THAT MOMENT by sanitize_location() above. Re-running the
     * freshness check against the CURRENT time here would wrongly penalize the normal gap
     * between an attendee's accept and the operator's later confirm inside the same five-minute
     * window; what still matters is that the stored reading is a real, sufficiently accurate
     * coordinate to compare against.
     */
    /** Great-circle distance in meters between two lat/lng points (haversine formula). */
    private static function haversine_meters(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earth_radius_m = 6371000.0;
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $delta_phi = deg2rad($lat2 - $lat1);
        $delta_lambda = deg2rad($lng2 - $lng1);
        $a = sin($delta_phi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($delta_lambda / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earth_radius_m * $c;
    }

    /**
     * The open-source, contract-level fail-closed gate for the pilot proof-of-presence rule.
     * Persistence of both sides' readings stays adapter-owned, but this platform will not let
     * an adapter's own reported state decide "confirmed" on its own: hbc_operator_confirm_
     * encounter's result is REQUIRED to echo the attendee's stored reading back as
     * 'attendee_location' whenever it reports a confirming state (proximity_verified/confirmed/
     * pending_xp). This method then applies the documented conservative formula itself —
     * haversine_distance + attendee_accuracy_meters + operator_accuracy_meters <=
     * PROXIMITY_MAX_COMBINED_METERS (architecture/V2_DECISIONS.md, "Two-scan proof") — and
     * overrides the outcome to proximity_failed whenever that evidence is missing, structurally
     * invalid, or the combined figure exceeds the threshold, REGARDLESS of what the adapter
     * itself decided. A non-confirming outcome (declined, same_device_rejected, disputed, etc.)
     * is left untouched — there is nothing to enforce when nothing was confirmed.
     *
     * Sets 'proximity_assurance' to one of the values documented in api/openapi.json:
     * 'confirmed_within_50m' once the combined formula actually passes; 'insufficient_evidence'
     * when the adapter never echoed back a usable attendee reading to compare against; 'failed'
     * when both readings are usable but the combined figure exceeds the threshold. Also sets
     * 'distance_meters' (the raw point-to-point figure, before adding either side's accuracy)
     * so a client or auditor can see the actual computation, not just the pass/fail verdict.
     */
    private static function verify_proximity_evidence($evidence, string $session_uuid, string $operator_identity_uuid, array $operator_location): array {
        $failed = static function (string $reason): array {
            return array('passed' => false, 'result' => array('state' => 'proximity_failed', 'proximity_assurance' => 'insufficient_evidence', 'proximity_failure_reason' => $reason, 'xp_human_gold_accepted' => false, 'xp_play_dough_status' => 'not_issued'));
        };
        if (!is_array($evidence) || ($evidence['session_uuid'] ?? '') !== $session_uuid || ($evidence['state'] ?? '') !== 'attendee_confirmed') {
            return $failed('missing_or_wrong_session_evidence');
        }
        $attendee_identity_uuid = (string) ($evidence['attendee_identity_uuid'] ?? '');
        if ($attendee_identity_uuid === '' || hash_equals($attendee_identity_uuid, $operator_identity_uuid)) {
            return array('passed' => false, 'result' => array('state' => 'same_device_rejected', 'proximity_assurance' => 'failed_distinct_device_requirement', 'xp_human_gold_accepted' => false, 'xp_play_dough_status' => 'not_issued'));
        }
        $attendee_location = self::sanitize_location($evidence['attendee_location'] ?? null);
        if ($attendee_location === null) {
            return $failed('missing_stale_or_inaccurate_attendee_location');
        }
        $expires_at = isset($evidence['expires_at']) && is_string($evidence['expires_at']) ? strtotime($evidence['expires_at']) : false;
        if ($expires_at === false || $expires_at < time()) {
            return $failed('encounter_expired');
        }
        $distance = self::haversine_meters($attendee_location['latitude'], $attendee_location['longitude'], $operator_location['latitude'], $operator_location['longitude']);
        $combined = $distance + $attendee_location['accuracy_meters'] + $operator_location['accuracy_meters'];
        if (!is_finite($combined) || $combined > self::PROXIMITY_MAX_COMBINED_METERS) {
            return array('passed' => false, 'result' => array('state' => 'proximity_failed', 'proximity_assurance' => 'failed', 'proximity_failure_reason' => 'outside_50_meter_conservative_limit', 'distance_meters' => round($distance, 1), 'conservative_distance_meters' => round($combined, 1), 'xp_human_gold_accepted' => false, 'xp_play_dough_status' => 'not_issued'));
        }
        return array('passed' => true, 'distance_meters' => round($distance, 1), 'conservative_distance_meters' => round($combined, 1));
    }

    /**
     * Re-sends the Welcome Letter's asynchronous email-confirmation link for the calling
     * device's identity. Unrelated to the 5-minute encounter window — this is the separate,
     * longer-lived email-confirmation flow. Requires a recognized device so this can't be
     * used to spam an arbitrary email address; the adapter looks up which identity/email to
     * message from identity_uuid, never from client-supplied contact info.
     */
    public static function resend_email_confirmation(WP_REST_Request $request): WP_REST_Response {
        $identity_uuid = HBC_Laugh_Auth::require_recognized_device($request);
        if (is_wp_error($identity_uuid)) {
            $err = $identity_uuid;
            $code = $err->get_error_code() === 'hbc_integration_required' ? 'integration_required' : 'device_token_invalid';
            return new WP_REST_Response(array('error' => array('code' => $code, 'message' => $err->get_error_message())), (int) $err->get_error_data()['status']);
        }

        if (!has_filter('hbc_resend_email_confirmation')) {
            return self::not_connected('Email-confirmation adapter');
        }

        $result = apply_filters('hbc_resend_email_confirmation', null, $identity_uuid);
        if (!$result) {
            return self::not_connected('Email-confirmation adapter');
        }
        return new WP_REST_Response(array('data' => $result), 202);
    }

    /**
     * Records a digital signature (typed name + explicit consent checkbox) on a Host-facing
     * agreement, from the same recognized device used everywhere else in this contract — never
     * a separate WP login. Rejects a short-circuited attempt (empty name, unchecked box, missing
     * version) with 422 before it ever reaches the adapter, and rejects a stale or altered
     * copy by requiring the SHA-256 checksum of the exact server-controlled signing template.
     */
    public static function accept_host_agreement(WP_REST_Request $request): WP_REST_Response {
        $identity_uuid = HBC_Laugh_Auth::require_recognized_device($request);
        if (is_wp_error($identity_uuid)) {
            $err = $identity_uuid;
            $code = $err->get_error_code() === 'hbc_integration_required' ? 'integration_required' : 'device_token_invalid';
            return new WP_REST_Response(array('error' => array('code' => $code, 'message' => $err->get_error_message())), (int) $err->get_error_data()['status']);
        }

        $host_uuid = sanitize_text_field((string) $request->get_param('host_uuid'));
        $agreement_key = sanitize_text_field((string) $request->get_param('agreement_key'));
        if (!self::is_uuid($host_uuid)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'A valid host_uuid is required.')), 422);
        }
        $signatory = HBC_Laugh_Auth::require_host_signatory($identity_uuid, $host_uuid);
        if (is_wp_error($signatory)) {
            return new WP_REST_Response(array('error' => array('code' => $signatory->get_error_code() === 'hbc_integration_required' ? 'integration_required' : 'host_forbidden', 'message' => $signatory->get_error_message())), (int) $signatory->get_error_data()['status']);
        }
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : array();

        $agreement_version = isset($body['agreement_version']) ? sanitize_text_field((string) $body['agreement_version']) : '';
        $typed_name = isset($body['typed_name']) ? sanitize_text_field((string) $body['typed_name']) : '';
        $consent_checked = !empty($body['consent_checked']);
        $document_checksum = isset($body['document_checksum']) ? sanitize_text_field((string) $body['document_checksum']) : '';

        if ($agreement_version === '' || mb_strlen($typed_name) < 2 || !$consent_checked) {
            return new WP_REST_Response(array('error' => array(
                'code' => 'validation_error',
                'message' => 'agreement_version, a typed_name of at least 2 characters, and consent_checked=true are all required.',
            )), 422);
        }

        $expected_checksum = self::canonical_agreement_checksum($agreement_key, $agreement_version);
        if ($expected_checksum === '') {
            return new WP_REST_Response(array('error' => array('code' => 'agreement_not_found', 'message' => 'That agreement version is not available for signature.')), 404);
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $document_checksum) || !hash_equals($expected_checksum, $document_checksum)) {
            return new WP_REST_Response(array('error' => array(
                'code' => 'agreement_content_mismatch',
                'message' => 'The agreement text displayed does not match the current signing copy.',
            )), 409);
        }

        if (!has_filter('hbc_record_agreement_acceptance')) {
            return self::not_connected('Agreement-acceptance adapter');
        }

        $result = apply_filters('hbc_record_agreement_acceptance', null, array(
            'host_uuid' => $host_uuid,
            'agreement_key' => $agreement_key,
            'agreement_version' => $agreement_version,
            'identity_uuid' => $identity_uuid,
            'typed_name' => $typed_name,
            'document_checksum' => $expected_checksum,
            'ip_hash' => self::hash_evidence(self::client_ip($request), 'ip'),
            'user_agent_hash' => self::hash_evidence(sanitize_text_field((string) $request->get_header('user-agent')), 'user-agent'),
        ));

        if (is_string($result) && isset(self::AGREEMENT_ERROR_STATUS[$result])) {
            return new WP_REST_Response(array('error' => array('code' => $result, 'message' => 'Unable to record this acceptance.')), self::AGREEMENT_ERROR_STATUS[$result]);
        }
        if (!is_array($result) || empty($result['acceptance_uuid']) || empty($result['accepted_at'])) {
            return self::not_connected('Agreement-acceptance adapter');
        }
        return new WP_REST_Response(array('data' => $result), !empty($result['already_accepted']) ? 200 : 201);
    }

    /**
     * Lets an authorized Host/Admin check whether the Host has already accepted a given
     * agreement version before deciding whether to show the sign-here prompt at all.
     */
    public static function host_agreement_status(WP_REST_Request $request): WP_REST_Response {
        $identity_uuid = HBC_Laugh_Auth::require_recognized_device($request);
        if (is_wp_error($identity_uuid)) {
            $err = $identity_uuid;
            $code = $err->get_error_code() === 'hbc_integration_required' ? 'integration_required' : 'device_token_invalid';
            return new WP_REST_Response(array('error' => array('code' => $code, 'message' => $err->get_error_message())), (int) $err->get_error_data()['status']);
        }

        $host_uuid = sanitize_text_field((string) $request->get_param('host_uuid'));
        $agreement_key = sanitize_text_field((string) $request->get_param('agreement_key'));
        $agreement_version = sanitize_text_field((string) $request->get_param('agreement_version'));
        if (!self::is_uuid($host_uuid)) {
            return new WP_REST_Response(array('error' => array('code' => 'validation_error', 'message' => 'A valid host_uuid is required.')), 422);
        }
        $signatory = HBC_Laugh_Auth::require_host_signatory($identity_uuid, $host_uuid);
        if (is_wp_error($signatory)) {
            return new WP_REST_Response(array('error' => array('code' => $signatory->get_error_code() === 'hbc_integration_required' ? 'integration_required' : 'host_forbidden', 'message' => $signatory->get_error_message())), (int) $signatory->get_error_data()['status']);
        }

        if (!has_filter('hbc_current_agreement_acceptance')) {
            return self::not_connected('Agreement-acceptance adapter');
        }

        // Authorization was checked above. Acceptance itself belongs to the Host/version,
        // not to each individual event or each administrator who later views it.
        $result = apply_filters('hbc_current_agreement_acceptance', null, $host_uuid, $agreement_key, $agreement_version !== '' ? $agreement_version : null);
        if (is_array($result) && !empty($result['acceptance_uuid'])) {
            return new WP_REST_Response(array('data' => array_merge(array('accepted' => true), $result)), 200);
        }
        return new WP_REST_Response(array('data' => array('accepted' => false)), 200);
    }

    private static function client_ip(WP_REST_Request $request): string {
        // Do not trust caller-controlled forwarding headers. A production reverse proxy may
        // supply a verified address through this explicit filter.
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $trusted = apply_filters('hbc_trusted_client_ip', $remote, $request);
        return is_string($trusted) ? $trusted : $remote;
    }

    private static function hash_evidence(string $value, string $purpose): ?string {
        if ($value === '') return null;
        return hash_hmac('sha256', $value, wp_salt('auth') . '|hbc-agreement|' . $purpose);
    }

    private static function canonical_agreement_checksum(string $agreement_key, string $agreement_version): string {
        if ($agreement_key !== self::HOST_AGREEMENT_KEY || $agreement_version !== self::HOST_AGREEMENT_VERSION) return '';
        $path = HBC_LAUGH_DIR . 'templates/host-agreement-terms.php';
        return is_readable($path) ? hash_file('sha256', $path) : '';
    }

    public static function integration_required(): WP_REST_Response {
        return self::not_connected('Existing Human Gold service layer');
    }

    /**
     * $12 Miner checkout stays on this site. Megavoters remains allowed for an older handoff.
     * Local http is allowed only for a .local or localhost host.
     */
    private static function membership_checkout_destination_allowed(string $checkout_url): bool {
        $host = strtolower((string) wp_parse_url($checkout_url, PHP_URL_HOST));
        $scheme = strtolower((string) wp_parse_url($checkout_url, PHP_URL_SCHEME));
        $home_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $allowed = array('humanblockchain.info', 'www.humanblockchain.info', 'megavoters.com', 'www.megavoters.com');
        if ($home_host !== '') {
            $allowed[] = $home_host;
        }
        if (!in_array($host, $allowed, true)) {
            return false;
        }
        if ($scheme === 'https') {
            return true;
        }
        return $scheme === 'http' && ($host === 'localhost' || substr($host, -6) === '.local');
    }

    private static function not_connected(string $adapter): WP_REST_Response {
        return new WP_REST_Response(array('error' => array('code' => 'integration_required', 'message' => $adapter . ' must be connected before production use.')), 501);
    }

    private static function is_uuid(string $value): bool {
        return (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value);
    }
}

<?php
defined('ABSPATH') || exit;

final class HBC_Laugh_Auth {
    public static function require_admin(WP_REST_Request $request) {
        if (!is_user_logged_in() || !current_user_can('hbc_admin')) {
            return new WP_Error('hbc_forbidden', 'Active organization Admin access is required.', array('status' => 403));
        }
        $host_uuid = sanitize_text_field((string) $request->get_param('host_uuid'));
        $allowed = (bool) apply_filters('hbc_user_can_access_host', false, get_current_user_id(), $host_uuid);
        return $allowed ?: new WP_Error('hbc_tenant_forbidden', 'This account is not assigned to that organization.', array('status' => 403));
    }

    public static function require_super_admin() {
        if (!is_user_logged_in() || !current_user_can('hbc_super_admin')) {
            return new WP_Error('hbc_forbidden', 'Protected Super Admin access is required.', array('status' => 403));
        }
        return (bool) apply_filters('hbc_privileged_mfa_verified', false, get_current_user_id())
            ?: new WP_Error('hbc_mfa_required', 'MFA verification is required.', array('status' => 403));
    }

    /**
     * Resolves the X-HBC-Device-Token header to an active hbc_devices identity, server-side.
     * Never trust a client-supplied device_state or identity claim in the request body —
     * this is the ONLY source of truth for "is this device registered" on a mutating request.
     *
     * Returns the resolved identity_uuid on success, or a WP_Error on failure. The adapter
     * (hbc_resolve_device_token) is intentionally unimplemented here; until it is connected,
     * this always returns 501 integration_required rather than silently trusting the token.
     */
    public static function require_recognized_device(WP_REST_Request $request) {
        $token = $request->get_header('x-hbc-device-token');
        if (!$token) {
            return new WP_Error('hbc_device_token_missing', 'A device recognition token is required.', array('status' => 401));
        }
        if (!has_filter('hbc_resolve_device_token')) {
            return new WP_Error('hbc_integration_required', 'Device recognition adapter must be connected before production use.', array('status' => 501));
        }
        $identity_uuid = apply_filters('hbc_resolve_device_token', null, sanitize_text_field($token));
        if (!$identity_uuid) {
            return new WP_Error('hbc_device_token_invalid', 'This device is not recognized. Complete registration first.', array('status' => 401));
        }
        return $identity_uuid;
    }

    /**
     * Server-side five-condition operator authorization test described in openapi.json's
     * RouteResponse.operator_authorized. Never derived from device_state or any client claim.
     * Returns true only when all five conditions are met for this identity + event.
     */
    public static function is_authorized_operator(string $identity_uuid, string $event_uuid): bool {
        return (bool) apply_filters('hbc_identity_is_authorized_operator', false, $identity_uuid, $event_uuid);
    }

    /**
     * Resolves the exact assignment and acting capacity selected for one event.
     * Miner membership is eligibility only; it never substitutes for this assignment.
     */
    public static function resolve_operator_assignment(string $identity_uuid, string $event_uuid, string $assignment_uuid, string $operator_role): ?array {
        if (!has_filter('hbc_resolve_operator_assignment')) {
            return null;
        }
        $assignment = apply_filters('hbc_resolve_operator_assignment', null, $identity_uuid, $event_uuid, $assignment_uuid, $operator_role);
        if (!is_array($assignment) || ($assignment['assignment_uuid'] ?? '') !== $assignment_uuid || ($assignment['role'] ?? '') !== $operator_role) {
            return null;
        }
        return $assignment;
    }

    /**
     * Signing Host terms is a tenant-scoped protected action. Device recognition alone is
     * never sufficient: the adapter must verify the identity has current authority to bind
     * this Host (active Host/Admin assignment—not Greeter alone—active Miner eligibility, confirmed email,
     * valid scope and no suspension/revocation).
     */
    public static function require_host_signatory(string $identity_uuid, string $host_uuid) {
        if (!has_filter('hbc_identity_can_accept_host_agreement')) {
            return new WP_Error('hbc_integration_required', 'Host-signatory authorization adapter must be connected before production use.', array('status' => 501));
        }
        $allowed = (bool) apply_filters('hbc_identity_can_accept_host_agreement', false, $identity_uuid, $host_uuid);
        return $allowed ?: new WP_Error('hbc_host_signatory_forbidden', 'This identity is not authorized to accept agreements for that Host.', array('status' => 403));
    }
}

<?php
defined('ABSPATH') || exit;

/** Fail-closed production release gate for new-device registration. */
final class HBC_Laugh_Readiness {
    private const REGISTRATION_OPTION = 'hbc_laugh_public_registration';
    private const REPORT_OPTION = 'hbc_laugh_readiness_report';

    private const REQUIRED_FILTERS = array(
        'event_directory' => array('hbc_list_public_events', 'hbc_resolve_event_code', 'hbc_resolve_universal_entry'),
        'identity' => array('hbc_resolve_device_token', 'hbc_begin_registration', 'hbc_complete_registration', 'hbc_save_registered_device_rsvp', 'hbc_confirm_email_token', 'hbc_resend_email_confirmation'),
        'assignment' => array('hbc_identity_is_authorized_operator', 'hbc_resolve_operator_assignment'),
        'encounter' => array('hbc_issue_encounter_token', 'hbc_resolve_encounter_token', 'hbc_attendee_confirm_encounter', 'hbc_encounter_proximity_evidence', 'hbc_operator_confirm_encounter', 'hbc_operator_encounter_status'),
        'woocommerce' => array('hbc_create_membership_checkout', 'hbc_membership_status'),
        'host_agreement' => array('hbc_identity_can_accept_host_agreement', 'hbc_record_agreement_acceptance', 'hbc_current_agreement_acceptance'),
    );

    private const REQUIRED_HEALTH = array('database', 'identity', 'events', 'assignments', 'sms_otp', 'email', 'woocommerce', 'ledger', 'notifications', 'server_cron');

    public static function registration_is_open(): bool {
        return get_option(self::REGISTRATION_OPTION, 'closed') === 'open';
    }

    public static function close_registration(): void {
        update_option(self::REGISTRATION_OPTION, 'closed', false);
    }

    public static function report(): array {
        $checks = array();
        foreach (self::REQUIRED_FILTERS as $group => $filters) {
            foreach ($filters as $filter) {
                $loaded = has_filter($filter) !== false;
                $checks['adapter:' . $filter] = array('ok' => $loaded, 'group' => $group, 'message' => $loaded ? 'loaded' : 'missing');
            }
        }

        $health = apply_filters('hbc_production_health_checks', array());
        $health = is_array($health) ? $health : array();
        foreach (self::REQUIRED_HEALTH as $name) {
            $item = $health[$name] ?? null;
            $ok = is_array($item) ? !empty($item['ok']) : $item === true;
            $message = is_array($item) && isset($item['message']) ? sanitize_text_field((string) $item['message']) : ($ok ? 'healthy' : 'missing or failed');
            $checks['health:' . $name] = array('ok' => $ok, 'group' => 'health', 'message' => $message);
        }

        $checks['schema'] = array(
            'ok' => HBC_Laugh_Schema::installed_version() === HBC_Laugh_Schema::CURRENT_VERSION,
            'group' => 'database',
            'message' => sprintf('%d/%d', HBC_Laugh_Schema::installed_version(), HBC_Laugh_Schema::CURRENT_VERSION),
        );
        $checks['https'] = array(
            'ok' => wp_parse_url(home_url('/'), PHP_URL_SCHEME) === 'https',
            'group' => 'security',
            'message' => home_url('/'),
        );

        $failed = array_keys(array_filter($checks, static fn(array $check): bool => !$check['ok']));
        $report = array('ready' => empty($failed), 'registration' => self::registration_is_open() ? 'open' : 'closed', 'checked_at' => gmdate('c'), 'failed' => $failed, 'checks' => $checks);
        update_option(self::REPORT_OPTION, $report, false);
        return $report;
    }

    public static function open_registration(): array {
        $report = self::report();
        if (!$report['ready']) {
            self::close_registration();
            return $report;
        }
        update_option(self::REGISTRATION_OPTION, 'open', false);
        $report['registration'] = 'open';
        update_option(self::REPORT_OPTION, $report, false);
        return $report;
    }
}

if (defined('WP_CLI') && WP_CLI) {
    final class HBC_Laugh_Readiness_CLI {
        public function check($args, $assoc_args): void {
            $report = HBC_Laugh_Readiness::report();
            WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $report['ready'] ? WP_CLI::success('Production readiness checks passed.') : WP_CLI::error('Production readiness checks failed; registration remains closed.');
        }
        public function open($args, $assoc_args): void {
            if (($assoc_args['confirm'] ?? '') !== 'OPEN-PUBLIC-REGISTRATION') {
                WP_CLI::error('Use --confirm=OPEN-PUBLIC-REGISTRATION after staging acceptance is complete.');
            }
            $report = HBC_Laugh_Readiness::open_registration();
            WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $report['ready'] ? WP_CLI::success('Public new-device registration is open.') : WP_CLI::error('Gate failed; public registration remains closed.');
        }
        public function close($args, $assoc_args): void {
            HBC_Laugh_Readiness::close_registration();
            WP_CLI::success('Public new-device registration is closed. Existing records were not changed.');
        }
        public function status($args, $assoc_args): void {
            WP_CLI::line(HBC_Laugh_Readiness::registration_is_open() ? 'open' : 'closed');
        }
    }
    WP_CLI::add_command('hbc readiness', 'HBC_Laugh_Readiness_CLI');
}

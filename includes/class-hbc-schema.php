<?php
defined('ABSPATH') || exit;

/**
 * Single authoritative source for every hbc_* table. Supersedes database/schema.sql and
 * database/migration-v1-to-v2.sql, which are reference-only documentation as of v2.4 (see
 * database/SCHEMA_NOTICE.md) — neither file is applied directly against a live database.
 *
 * Every table uses $wpdb->prefix. JSON payload columns are LONGTEXT (not the JSON column
 * type) for portability across MySQL/MariaDB versions dbDelta must support. Migrations are
 * ordered, numbered and idempotent: each step is safe to re-run, and the installed version is
 * stored in the hbc_laugh_schema_version option so activation on an already-current site is a
 * no-op.
 */
final class HBC_Laugh_Schema {
    public const CURRENT_VERSION = 10;
    private const VERSION_OPTION = 'hbc_laugh_schema_version';

    public static function install(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $installed = (int) get_option(self::VERSION_OPTION, 0);

        for ($version = $installed + 1; $version <= self::CURRENT_VERSION; $version++) {
            $method = 'migrate_to_v' . $version;
            if (method_exists(__CLASS__, $method)) {
                self::$method();
            }
            update_option(self::VERSION_OPTION, $version, true);
        }
    }

    public static function installed_version(): int {
        return (int) get_option(self::VERSION_OPTION, 0);
    }

    // ---- migration 1: base tables (schema.sql, prefixed) ----------------------

    private static function migrate_to_v1(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}hbc_identities (
            identity_uuid CHAR(36) NOT NULL,
            email_ciphertext TEXT NOT NULL,
            email_lookup_hash CHAR(64) NOT NULL,
            phone_ciphertext TEXT NOT NULL,
            phone_lookup_hash CHAR(64) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (identity_uuid),
            UNIQUE KEY uq_email_hash (email_lookup_hash),
            UNIQUE KEY uq_phone_hash (phone_lookup_hash)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_devices (
            device_uuid CHAR(36) NOT NULL,
            identity_uuid CHAR(36) NOT NULL,
            recognition_token_hash CHAR(64) NOT NULL,
            verified_at DATETIME NOT NULL,
            last_seen_at DATETIME NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            PRIMARY KEY  (device_uuid),
            UNIQUE KEY uq_token (recognition_token_hash),
            KEY ix_identity (identity_uuid)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_consents (
            consent_uuid CHAR(36) NOT NULL,
            identity_uuid CHAR(36) NOT NULL,
            consent_type VARCHAR(40) NOT NULL,
            consent_version VARCHAR(24) NOT NULL,
            granted TINYINT(1) NOT NULL,
            source VARCHAR(40) NOT NULL,
            recorded_at DATETIME NOT NULL,
            PRIMARY KEY  (consent_uuid),
            KEY ix_identity_type (identity_uuid,consent_type)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_hosts (
            host_uuid CHAR(36) NOT NULL,
            name VARCHAR(180) NOT NULL,
            type VARCHAR(40) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            timezone VARCHAR(80) NOT NULL,
            created_by_identity_uuid CHAR(36) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (host_uuid)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_host_assignments (
            assignment_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            identity_uuid CHAR(36) NOT NULL,
            role_key VARCHAR(50) NOT NULL,
            scope_type VARCHAR(24) NOT NULL DEFAULT 'organization',
            scope_uuid CHAR(36) NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NULL,
            revoked_at DATETIME NULL,
            PRIMARY KEY  (assignment_uuid),
            UNIQUE KEY uq_assignment (host_uuid,identity_uuid,role_key,scope_type,scope_uuid)
        ) $charset;");

        // Base shape only. migrate_to_v2() below expands this to its v2.4 final
        // shape (description, capacity, allow_observe, allow_participate,
        // volunteer_message, updated_at) via a second dbDelta pass.
        dbDelta("CREATE TABLE {$p}hbc_events (
            event_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            name VARCHAR(180) NOT NULL,
            event_type VARCHAR(40) NOT NULL DEFAULT 'community',
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            location VARCHAR(255) NULL,
            route_mode VARCHAR(40) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            rsvp_opens_at DATETIME NULL,
            rsvp_closes_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (event_uuid),
            KEY ix_host_status (host_uuid,status)
        ) $charset;");

        // Base shape only; migrate_to_v2() renames response -> rsvp_choice and
        // adds the remaining v2.4 columns.
        dbDelta("CREATE TABLE {$p}hbc_rsvps (
            rsvp_uuid CHAR(36) NOT NULL,
            event_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            identity_uuid CHAR(36) NOT NULL,
            device_uuid CHAR(36) NOT NULL,
            response VARCHAR(20) NOT NULL,
            touchstone_word VARCHAR(80) NULL,
            peace_branch VARCHAR(40) NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'completed',
            completed_at DATETIME NOT NULL,
            PRIMARY KEY  (rsvp_uuid),
            UNIQUE KEY uq_event_identity (event_uuid,identity_uuid),
            KEY ix_host_event (host_uuid,event_uuid)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_volunteer_requests (
            volunteer_request_uuid CHAR(36) NOT NULL,
            rsvp_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            state VARCHAR(24) NOT NULL DEFAULT 'requested',
            protected_access_required TINYINT(1) NULL,
            reviewed_by_identity_uuid CHAR(36) NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (volunteer_request_uuid)
        ) $charset;");

        // Base shape only; migrate_to_v2() adds operator_device_uuid,
        // attendee_device_uuid, token_hash, issued_at, consumed_at, xp_accepted.
        dbDelta("CREATE TABLE {$p}hbc_attendance_sessions (
            session_uuid CHAR(36) NOT NULL,
            event_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            operator_identity_uuid CHAR(36) NOT NULL,
            attendee_identity_uuid CHAR(36) NULL,
            encounter_type VARCHAR(20) NOT NULL DEFAULT 'gratitude',
            expires_at DATETIME NOT NULL,
            accepted_at DATETIME NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'pending',
            PRIMARY KEY  (session_uuid)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_memberships (
            membership_uuid CHAR(36) NOT NULL,
            beneficiary_identity_uuid CHAR(36) NOT NULL,
            payer_identity_uuid CHAR(36) NULL,
            sponsoring_host_uuid CHAR(36) NULL,
            woo_order_id BIGINT UNSIGNED NULL,
            state VARCHAR(24) NOT NULL,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (membership_uuid),
            UNIQUE KEY uq_order (woo_order_id)
        ) $charset;");

        // LONGTEXT for metadata_json, not JSON — matches the rest of this
        // installer's portability rule. (v2.3 activator used LONGTEXT here while
        // schema.sql declared JSON for the same column; this resolves that
        // mismatch in favor of LONGTEXT everywhere.)
        dbDelta("CREATE TABLE {$p}hbc_audit_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_uuid CHAR(36) NOT NULL,
            actor_identity_uuid CHAR(36) NULL,
            host_uuid CHAR(36) NULL,
            action VARCHAR(100) NOT NULL,
            target_type VARCHAR(80) NULL,
            target_uuid CHAR(36) NULL,
            reason TEXT NULL,
            request_id VARCHAR(100) NULL,
            metadata_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_audit_uuid (event_uuid),
            KEY ix_host_action (host_uuid,action)
        ) $charset;");

        add_role('hbc_admin', 'Human Gold Admin', array('read' => true, 'hbc_admin' => true));
        add_role('hbc_super_admin', 'Human Gold Super Admin', array('read' => true, 'hbc_super_admin' => true));
    }

    // ---- migration 2: v2.4 additions (migration-v1-to-v2.sql, prefixed) -------

    private static function migrate_to_v2(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        // dbDelta can ADD columns but cannot rename one, so the response ->
        // rsvp_choice rename runs as an explicit, idempotent guarded statement
        // before dbDelta expands the rest of the table.
        $rsvps_table = "{$p}hbc_rsvps";
        $has_response = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'response'",
            $rsvps_table
        ));
        $has_rsvp_choice = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'rsvp_choice'",
            $rsvps_table
        ));
        if ((int) $has_response > 0 && (int) $has_rsvp_choice === 0) {
            $renamed = $wpdb->query("ALTER TABLE {$rsvps_table} CHANGE COLUMN response rsvp_choice ENUM('observe','participate') NOT NULL");
            if ($renamed === false) {
                throw new RuntimeException('Human Gold schema migration could not rename hbc_rsvps.response. The schema version was not advanced.');
            }
        }

        // Full v2.4 desired shape; dbDelta adds whatever columns/keys are
        // still missing on a rerun and is a no-op on an already-current site.
        dbDelta("CREATE TABLE {$p}hbc_events (
            event_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            name VARCHAR(180) NOT NULL,
            event_type VARCHAR(40) NOT NULL DEFAULT 'community',
            description TEXT NULL,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            location VARCHAR(255) NULL,
            capacity INT UNSIGNED NULL,
            allow_observe TINYINT(1) NOT NULL DEFAULT 1,
            allow_participate TINYINT(1) NOT NULL DEFAULT 1,
            volunteer_message TEXT NULL,
            route_mode VARCHAR(40) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            rsvp_opens_at DATETIME NULL,
            rsvp_closes_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY  (event_uuid),
            KEY ix_host_status (host_uuid,status)
        ) $charset;");

        dbDelta("CREATE TABLE {$rsvps_table} (
            rsvp_uuid CHAR(36) NOT NULL,
            event_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            identity_uuid CHAR(36) NOT NULL,
            device_uuid CHAR(36) NOT NULL,
            rsvp_choice ENUM('observe','participate') NOT NULL,
            touchstone_word VARCHAR(80) NULL,
            peace_branch VARCHAR(40) NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'completed',
            attendance_status ENUM('not_recorded','confirmed') NOT NULL DEFAULT 'not_recorded',
            attendance_confirmed_at DATETIME NULL,
            volunteer_interest TINYINT(1) NOT NULL DEFAULT 0,
            source_public_code VARCHAR(80) NULL,
            otp_verified_at DATETIME NULL,
            completed_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY  (rsvp_uuid),
            UNIQUE KEY uq_event_identity (event_uuid,identity_uuid),
            KEY ix_host_event (host_uuid,event_uuid),
            KEY ix_event_choice (event_uuid,rsvp_choice,status)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_attendance_sessions (
            session_uuid CHAR(36) NOT NULL,
            event_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            operator_identity_uuid CHAR(36) NOT NULL,
            operator_device_uuid CHAR(36) NOT NULL,
            attendee_identity_uuid CHAR(36) NULL,
            attendee_device_uuid CHAR(36) NULL,
            encounter_type VARCHAR(20) NOT NULL DEFAULT 'gratitude',
            token_hash CHAR(64) NOT NULL,
            issued_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            accepted_at DATETIME NULL,
            consumed_at DATETIME NULL,
            xp_accepted TINYINT(1) NOT NULL DEFAULT 0,
            state VARCHAR(20) NOT NULL DEFAULT 'pending',
            PRIMARY KEY  (session_uuid),
            UNIQUE KEY uq_attendance_token (token_hash)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_access_requests (
            request_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            identity_uuid CHAR(36) NOT NULL,
            requested_role VARCHAR(50) NOT NULL,
            requested_scope_uuid CHAR(36) NULL,
            state ENUM('pending','approved','declined','withdrawn') NOT NULL DEFAULT 'pending',
            reviewed_by_identity_uuid CHAR(36) NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (request_uuid),
            KEY ix_access_host_state (host_uuid,state)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_communication_profiles (
            profile_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            email_mode ENUM('host_provider','platform_relay') NOT NULL DEFAULT 'host_provider',
            sms_mode ENUM('host_twilio','sponsor_funded') NOT NULL DEFAULT 'host_twilio',
            sender_name VARCHAR(160) NOT NULL,
            sender_email VARCHAR(254) NULL,
            sender_phone VARCHAR(32) NULL,
            provider_secret_ref VARCHAR(255) NULL,
            status ENUM('pending','verified','suspended') NOT NULL DEFAULT 'pending',
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (profile_uuid),
            UNIQUE KEY uq_comm_host (host_uuid)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_communication_funds (
            fund_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            sponsor_identity_uuid CHAR(36) NULL,
            balance_cents BIGINT NOT NULL DEFAULT 0,
            warning_threshold_cents INT NOT NULL DEFAULT 1000,
            pause_threshold_cents INT NOT NULL DEFAULT 500,
            auto_reload_cents INT NULL DEFAULT 2000,
            monthly_cap_cents INT NULL DEFAULT 5000,
            state ENUM('active','paused','closed') NOT NULL DEFAULT 'active',
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (fund_uuid),
            KEY ix_fund_host (host_uuid,state)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_communication_ledger (
            ledger_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            fund_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            entry_type ENUM('funding','sms','otp','refund','adjustment') NOT NULL,
            amount_cents BIGINT NOT NULL,
            provider_reference VARCHAR(191) NULL,
            idempotency_key VARCHAR(191) NOT NULL,
            metadata_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (ledger_id),
            UNIQUE KEY uq_comm_idempotency (idempotency_key),
            KEY ix_comm_host_created (host_uuid,created_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_message_deliveries (
            delivery_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            identity_uuid CHAR(36) NOT NULL,
            event_uuid CHAR(36) NULL,
            template_key ENUM('nugget_rsvp','observer_rsvp_welcome','participant_rsvp_welcome','pending_access','active_miner','otp','reminder','encounter_confirmed','encounter_expired') NOT NULL,
            channel ENUM('email','sms') NOT NULL,
            provider_message_id VARCHAR(191) NULL,
            state ENUM('queued','sent','delivered','failed','suppressed') NOT NULL,
            consent_snapshot_json LONGTEXT NULL,
            failure_code VARCHAR(80) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (delivery_uuid),
            KEY ix_delivery_host_state (host_uuid,state,created_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_agreement_acceptances (
            acceptance_uuid CHAR(36) NOT NULL,
            agreement_key VARCHAR(80) NOT NULL,
            agreement_version VARCHAR(30) NOT NULL,
            identity_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NULL,
            accepted_at DATETIME NOT NULL,
            ip_hash CHAR(64) NULL,
            user_agent_hash CHAR(64) NULL,
            PRIMARY KEY  (acceptance_uuid),
            UNIQUE KEY uq_agreement_acceptance (agreement_key,agreement_version,identity_uuid,host_uuid)
        ) $charset;");
    }

    // ---- migration 3: asynchronous email confirmation -----------------------

    private static function migrate_to_v3(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}hbc_identities (
            identity_uuid CHAR(36) NOT NULL,
            email_ciphertext TEXT NOT NULL,
            email_lookup_hash CHAR(64) NOT NULL,
            email_status VARCHAR(24) NOT NULL DEFAULT 'pending',
            email_verified_at DATETIME NULL,
            phone_ciphertext TEXT NOT NULL,
            phone_lookup_hash CHAR(64) NOT NULL,
            phone_status VARCHAR(24) NOT NULL DEFAULT 'pending',
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (identity_uuid),
            UNIQUE KEY uq_email_hash (email_lookup_hash),
            UNIQUE KEY uq_phone_hash (phone_lookup_hash)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_email_confirmation_tokens (
            confirmation_uuid CHAR(36) NOT NULL,
            identity_uuid CHAR(36) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            email_lookup_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (confirmation_uuid),
            UNIQUE KEY uq_email_confirmation_token (token_hash),
            KEY ix_email_confirmation_identity (identity_uuid,expires_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_message_deliveries (
            delivery_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            identity_uuid CHAR(36) NOT NULL,
            event_uuid CHAR(36) NULL,
            template_key VARCHAR(50) NOT NULL,
            channel ENUM('email','sms') NOT NULL,
            provider_message_id VARCHAR(191) NULL,
            state ENUM('queued','sent','delivered','failed','suppressed') NOT NULL,
            consent_snapshot_json LONGTEXT NULL,
            failure_code VARCHAR(80) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (delivery_uuid),
            KEY ix_delivery_host_state (host_uuid,state,created_at),
            KEY ix_delivery_template (template_key,created_at)
        ) $charset;");
    }

    // ---- migration 4: explicit event operating capacity ---------------------

    private static function migrate_to_v4(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}hbc_host_assignments (
            assignment_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            identity_uuid CHAR(36) NOT NULL,
            role_key VARCHAR(50) NOT NULL,
            scope_type VARCHAR(24) NOT NULL DEFAULT 'organization',
            scope_uuid CHAR(36) NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NULL,
            assigned_by_identity_uuid CHAR(36) NULL,
            revoked_at DATETIME NULL,
            revoked_by_identity_uuid CHAR(36) NULL,
            PRIMARY KEY  (assignment_uuid),
            UNIQUE KEY uq_assignment (host_uuid,identity_uuid,role_key,scope_type,scope_uuid),
            KEY ix_identity_status (identity_uuid,status,starts_at,ends_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}hbc_attendance_sessions (
            session_uuid CHAR(36) NOT NULL,
            event_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            operator_identity_uuid CHAR(36) NOT NULL,
            operator_device_uuid CHAR(36) NOT NULL,
            operator_assignment_uuid CHAR(36) NULL,
            operator_role_at_time VARCHAR(24) NULL,
            attendee_identity_uuid CHAR(36) NULL,
            attendee_device_uuid CHAR(36) NULL,
            encounter_type VARCHAR(20) NOT NULL DEFAULT 'gratitude',
            token_hash CHAR(64) NOT NULL,
            issued_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            accepted_at DATETIME NULL,
            consumed_at DATETIME NULL,
            xp_accepted TINYINT(1) NOT NULL DEFAULT 0,
            state VARCHAR(20) NOT NULL DEFAULT 'pending',
            PRIMARY KEY  (session_uuid),
            UNIQUE KEY uq_attendance_token (token_hash),
            KEY ix_operator_assignment (operator_assignment_uuid,event_uuid)
        ) $charset;");
    }

    // ---- migration 5: digital-signature fields on agreement acceptances -----

    /**
     * hbc_agreement_acceptances was created in migrate_to_v2() with agreement_key,
     * agreement_version, identity_uuid, host_uuid, accepted_at, ip_hash and user_agent_hash —
     * enough to record THAT someone accepted, but not the signature itself. This adds the
     * typed name (the actual e-signature) and an optional document checksum for tamper-
     * evidence, using the same re-declare-full-desired-shape dbDelta pattern as every other
     * versioned table in this installer, so this is a safe ADD-COLUMN on any existing row.
     */
    private static function migrate_to_v5(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}hbc_agreement_acceptances (
            acceptance_uuid CHAR(36) NOT NULL,
            agreement_key VARCHAR(80) NOT NULL,
            agreement_version VARCHAR(30) NOT NULL,
            identity_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NULL,
            typed_name VARCHAR(180) NOT NULL DEFAULT '',
            document_checksum CHAR(64) NULL,
            accepted_at DATETIME NOT NULL,
            ip_hash CHAR(64) NULL,
            user_agent_hash CHAR(64) NULL,
            PRIMARY KEY  (acceptance_uuid),
            UNIQUE KEY uq_agreement_acceptance (agreement_key,agreement_version,identity_uuid,host_uuid)
        ) $charset;");
    }

    // ---- migration 6: one Host agreement acceptance per agreement version --

    private static function migrate_to_v6(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        // The signer identity is evidence of who bound the Host; it is not part of the
        // agreement's uniqueness. Events under the Host never create another signature.
        dbDelta("CREATE TABLE {$p}hbc_agreement_acceptances (
            acceptance_uuid CHAR(36) NOT NULL,
            agreement_key VARCHAR(80) NOT NULL,
            agreement_version VARCHAR(30) NOT NULL,
            identity_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            typed_name VARCHAR(180) NOT NULL DEFAULT '',
            document_checksum CHAR(64) NULL,
            accepted_at DATETIME NOT NULL,
            ip_hash CHAR(64) NULL,
            user_agent_hash CHAR(64) NULL,
            PRIMARY KEY  (acceptance_uuid),
            UNIQUE KEY uq_agreement_acceptance (agreement_key,agreement_version,identity_uuid,host_uuid),
            UNIQUE KEY uq_host_agreement_version (agreement_key,agreement_version,host_uuid)
        ) $charset;");
    }

    // ---- migration 7: optional public short code for event discovery -------

    private static function migrate_to_v7(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        // Codes are public discovery labels, never credentials. Authorization is still
        // evaluated after resolving event_uuid through the universal entry controller.
        dbDelta("CREATE TABLE {$p}hbc_event_codes (
            event_code VARCHAR(16) NOT NULL,
            event_uuid CHAR(36) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (event_code),
            UNIQUE KEY uq_event_code_event (event_uuid)
        ) $charset;");
    }

    // ---- migration 8: open-source, contract-verifiable proximity evidence ---

    /**
     * Durable storage for the fail-closed dual-GPS proximity evidence that class-hbc-rest.php's
     * enforce_fail_closed_proximity() now compares itself, rather than trusting the private
     * integration adapter to compute the combined-accuracy formula on its own (see
     * architecture/V2_DECISIONS.md, "Two-scan proof"). This project is intended to run as an
     * open-source testnet ahead of a future mainnet execution, so the evidence trail behind a
     * confirmed encounter needs to be something anyone auditing this repository's schema and
     * REST contract can see and verify — not something that only exists inside a private
     * adapter's own database design. All columns are nullable: a session that never reaches a
     * confirming state (declined, walked_away, expired, same_device_rejected) legitimately has
     * none of this filled in.
     */
    private static function migrate_to_v8(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}hbc_attendance_sessions (
            session_uuid CHAR(36) NOT NULL,
            event_uuid CHAR(36) NOT NULL,
            host_uuid CHAR(36) NOT NULL,
            operator_identity_uuid CHAR(36) NOT NULL,
            operator_device_uuid CHAR(36) NOT NULL,
            operator_assignment_uuid CHAR(36) NULL,
            operator_role_at_time VARCHAR(24) NULL,
            attendee_identity_uuid CHAR(36) NULL,
            attendee_device_uuid CHAR(36) NULL,
            encounter_type VARCHAR(20) NOT NULL DEFAULT 'gratitude',
            token_hash CHAR(64) NOT NULL,
            issued_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            accepted_at DATETIME NULL,
            consumed_at DATETIME NULL,
            xp_accepted TINYINT(1) NOT NULL DEFAULT 0,
            state VARCHAR(20) NOT NULL DEFAULT 'pending',
            attendee_latitude DECIMAL(9,6) NULL,
            attendee_longitude DECIMAL(9,6) NULL,
            attendee_location_accuracy_meters DECIMAL(6,1) NULL,
            attendee_location_captured_at DATETIME NULL,
            operator_latitude DECIMAL(9,6) NULL,
            operator_longitude DECIMAL(9,6) NULL,
            operator_location_accuracy_meters DECIMAL(6,1) NULL,
            operator_location_captured_at DATETIME NULL,
            proximity_distance_meters DECIMAL(8,1) NULL,
            proximity_assurance VARCHAR(24) NULL,
            PRIMARY KEY  (session_uuid),
            UNIQUE KEY uq_attendance_token (token_hash),
            KEY ix_operator_assignment (operator_assignment_uuid,event_uuid)
        ) $charset;");
    }

    /**
     * Allow second-scan Walk Away to withdraw an existing Observe/Participate RSVP
     * (Coach Tom 2026-09-27): store choice as walk_away on the same row.
     */
    private static function migrate_to_v9(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'hbc_rsvps';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $table
        ));
        if ((int) $exists === 0) {
            return;
        }
        // Expand ENUM; ignore failure if already applied.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN rsvp_choice ENUM('observe','participate','walk_away') NOT NULL");
    }

    /**
     * A missing email or phone confirmation stays unconfirmed.
     * Event category is a label. Fundraiser does not collect payment.
     */
    private static function migrate_to_v10(): void {
        global $wpdb;
        $identities = $wpdb->prefix . 'hbc_identities';
        $events     = $wpdb->prefix . 'hbc_events';

        if ( self::table_exists( $identities ) ) {
            if ( ! self::column_exists( $identities, 'phone_verified_at' ) ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query( "ALTER TABLE {$identities} ADD COLUMN phone_verified_at DATETIME NULL AFTER phone_status" );
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "ALTER TABLE {$identities} MODIFY COLUMN phone_status VARCHAR(24) NOT NULL DEFAULT 'pending'" );
            // Rows that only inherited the old default have no verification time.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "UPDATE {$identities} SET phone_status = 'pending' WHERE phone_status = 'verified' AND phone_verified_at IS NULL" );
        }

        if ( self::table_exists( $events ) && ! self::column_exists( $events, 'event_category' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "ALTER TABLE {$events} ADD COLUMN event_category VARCHAR(40) NULL AFTER event_type" );
        }
    }

    private static function table_exists( string $table ): bool {
        global $wpdb;
        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            )
        );
        return (int) $found > 0;
    }

    private static function column_exists( string $table, string $column ): bool {
        global $wpdb;
        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                $column
            )
        );
        return (int) $found > 0;
    }

}

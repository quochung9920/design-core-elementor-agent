<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Owner-bound API credentials for direct REST/GPT Actions access. */
class Design_Core_Elementor_API_Credential_Registry {
    const OPTION_KEY = 'design_core_elementor_api_credentials';
    const SCHEMA_VERSION = 1;
    const MAX_CREDENTIALS = 10;
    const TOKEN_PREFIX = 'dcapi';

    public function all() {
        $entries = get_option( self::OPTION_KEY, array() );
        return is_array( $entries ) ? array_values( $entries ) : array();
    }

    public function list_public() {
        return array_map( array( $this, 'to_public' ), $this->all() );
    }

    public function find( $id ) {
        $id = sanitize_key( (string) $id );
        foreach ( $this->all() as $entry ) {
            if ( $id === (string) ( $entry['id'] ?? '' ) ) { return $entry; }
        }
        return null;
    }

    public function create( array $args ) {
        $owner_guard = Design_Core_Elementor_API_Access_Settings::require_current_owner();
        if ( is_wp_error( $owner_guard ) ) { return $owner_guard; }

        $entries = $this->all();
        if ( count( $entries ) >= self::MAX_CREDENTIALS ) {
            return new WP_Error( 'design_core_api_credential_limit', 'Maximum number of Design Core API credentials reached.' );
        }

        $name = sanitize_text_field( (string) ( $args['name'] ?? '' ) );
        if ( '' === $name ) { return new WP_Error( 'design_core_api_credential_name_required', 'An API credential name is required.' ); }

        $scopes = $this->sanitize_scopes( (array) ( $args['scopes'] ?? array() ) );
        if ( ! $scopes ) { return new WP_Error( 'design_core_api_credential_scopes_required', 'At least one valid Design Core scope is required.' ); }

        $environment = in_array( $args['environment'] ?? '', Design_Core_Elementor_Remote_Settings::ENVIRONMENTS, true )
            ? $args['environment']
            : Design_Core_Elementor_Remote_Settings::environment();
        $expires_days = min( 365, max( 1, (int) ( $args['expires_days'] ?? 90 ) ) );

        $id = bin2hex( random_bytes( 8 ) );
        $secret = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
        $now_ts = time();
        $owner_id = Design_Core_Elementor_API_Access_Settings::owner_user_id();
        $entry = array(
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $id,
            'name' => $name,
            'scopes' => $scopes,
            'environment' => $environment,
            'token_hash' => hash( 'sha256', $secret ),
            'created_at' => gmdate( 'c', $now_ts ),
            'created_by' => $owner_id,
            'expires_at' => gmdate( 'c', $now_ts + ( $expires_days * DAY_IN_SECONDS ) ),
            'last_used_at' => '',
            'revoked_at' => '',
        );
        $entries[] = $entry;
        if ( false === update_option( self::OPTION_KEY, $entries, false ) ) {
            return new WP_Error( 'design_core_api_credential_write_failed', 'Unable to persist the new Design Core API credential.' );
        }

        return array(
            'credential' => $this->to_public( $entry ),
            'token' => self::TOKEN_PREFIX . '_' . $id . '_' . $secret,
        );
    }

    public function revoke( $id ) {
        $owner_guard = Design_Core_Elementor_API_Access_Settings::require_current_owner();
        if ( is_wp_error( $owner_guard ) ) { return $owner_guard; }

        $id = sanitize_key( (string) $id );
        $entries = $this->all();
        $found = false;
        foreach ( $entries as &$entry ) {
            if ( $id !== (string) ( $entry['id'] ?? '' ) ) { continue; }
            if ( (int) ( $entry['created_by'] ?? 0 ) !== Design_Core_Elementor_API_Access_Settings::owner_user_id() ) {
                return new WP_Error( 'design_core_api_credential_owner_mismatch', 'Credential does not belong to the configured API owner.' );
            }
            if ( ! empty( $entry['revoked_at'] ) ) {
                return new WP_Error( 'design_core_api_credential_already_revoked', 'This API credential is already revoked.' );
            }
            $entry['revoked_at'] = gmdate( 'c' );
            $found = true;
            break;
        }
        unset( $entry );
        if ( ! $found ) { return new WP_Error( 'design_core_api_credential_not_found', 'API credential was not found.' ); }
        update_option( self::OPTION_KEY, $entries, false );
        return true;
    }

    public function rotate( $id, $expires_days = 90 ) {
        $owner_guard = Design_Core_Elementor_API_Access_Settings::require_current_owner();
        if ( is_wp_error( $owner_guard ) ) { return $owner_guard; }
        $existing = $this->find( $id );
        if ( ! $existing ) { return new WP_Error( 'design_core_api_credential_not_found', 'API credential was not found.' ); }
        if ( (int) ( $existing['created_by'] ?? 0 ) !== Design_Core_Elementor_API_Access_Settings::owner_user_id() ) {
            return new WP_Error( 'design_core_api_credential_owner_mismatch', 'Credential does not belong to the configured API owner.' );
        }
        $revoked = $this->revoke( $id );
        if ( is_wp_error( $revoked ) && 'design_core_api_credential_already_revoked' !== $revoked->get_error_code() ) { return $revoked; }
        return $this->create( array(
            'name' => (string) ( $existing['name'] ?? 'ChatGPT Web' ),
            'scopes' => (array) ( $existing['scopes'] ?? array() ),
            'environment' => (string) ( $existing['environment'] ?? Design_Core_Elementor_Remote_Settings::environment() ),
            'expires_days' => min( 365, max( 1, (int) $expires_days ) ),
        ) );
    }

    public function record_use( $id ) {
        $id = sanitize_key( (string) $id );
        $entries = $this->all();
        foreach ( $entries as &$entry ) {
            if ( $id === (string) ( $entry['id'] ?? '' ) ) { $entry['last_used_at'] = gmdate( 'c' ); break; }
        }
        unset( $entry );
        update_option( self::OPTION_KEY, $entries, false );
    }

    public function to_public( array $entry ) {
        $expires_at = (string) ( $entry['expires_at'] ?? '' );
        $expired = $expires_at && strtotime( $expires_at ) !== false && strtotime( $expires_at ) <= time();
        return array(
            'id' => (string) ( $entry['id'] ?? '' ),
            'name' => (string) ( $entry['name'] ?? '' ),
            'scopes' => array_values( (array) ( $entry['scopes'] ?? array() ) ),
            'environment' => (string) ( $entry['environment'] ?? '' ),
            'created_at' => (string) ( $entry['created_at'] ?? '' ),
            'expires_at' => $expires_at,
            'last_used_at' => (string) ( $entry['last_used_at'] ?? '' ),
            'revoked_at' => (string) ( $entry['revoked_at'] ?? '' ),
            'expired' => (bool) $expired,
            'active' => empty( $entry['revoked_at'] ) && ! $expired,
        );
    }

    private function sanitize_scopes( array $scopes ) {
        $valid = Design_Core_Elementor_Capabilities::all();
        $sanitized = array_map( 'sanitize_key', $scopes );
        return array_values( array_unique( array_intersect( $sanitized, $valid ) ) );
    }
}

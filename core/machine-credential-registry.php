<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Design Core Machine Credential store for the rc21 MCP/remote layer.
 *
 * Follows the same versioned, bounded wp_options pattern as Change_Ledger rather than
 * a dedicated $wpdb table -- a site realistically holds a handful of machine credentials,
 * and next-steps.md is explicit that new tables should wait for measured need.
 *
 * The plain token is NEVER stored. Only hash('sha256', $secret) is persisted; the secret
 * is 32 bytes of CSPRNG output, so a fast unsalted hash is the same accepted tradeoff
 * Laravel Sanctum and GitHub/Stripe personal-access-token hashing make (the entropy lives
 * in the secret, not in a slow KDF).
 */
class Design_Core_Elementor_Machine_Credential_Registry {
    const OPTION_KEY = 'design_core_elementor_machine_credentials';
    const SCHEMA_VERSION = 1;
    const MAX_CREDENTIALS = 50;
    const TOKEN_PREFIX = 'dcmcp';

    public function all() {
        $entries = get_option( self::OPTION_KEY, array() );
        return is_array( $entries ) ? array_values( $entries ) : array();
    }

    /** Public listing: hash and any secret material are never included. */
    public function list_public() {
        return array_map( array( $this, 'to_public' ), $this->all() );
    }

    public function find( $id ) {
        $id = sanitize_key( (string) $id );
        foreach ( $this->all() as $entry ) { if ( $id === (string) ( $entry['id'] ?? '' ) ) { return $entry; } }
        return null;
    }

    public function create( array $args ) {
        $entries = $this->all();
        if ( count( $entries ) >= self::MAX_CREDENTIALS ) { return new WP_Error( 'design_core_credential_limit', 'Maximum number of Design Core machine credentials reached.' ); }

        $name = sanitize_text_field( (string) ( $args['name'] ?? '' ) );
        if ( '' === $name ) { return new WP_Error( 'design_core_credential_name_required', 'A machine credential name is required.' ); }
        $scopes = $this->sanitize_scopes( (array) ( $args['scopes'] ?? array() ) );
        if ( ! $scopes ) { return new WP_Error( 'design_core_credential_scopes_required', 'At least one valid scope is required.' ); }
        $environment = in_array( $args['environment'] ?? '', Design_Core_Elementor_Remote_Settings::ENVIRONMENTS, true ) ? $args['environment'] : Design_Core_Elementor_Remote_Settings::environment();

        $id = $this->generate_id();
        $secret = $this->generate_secret();
        $now = gmdate( 'c' );
        $entry = array(
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $id,
            'name' => $name,
            'scopes' => $scopes,
            'environment' => $environment,
            'token_hash' => hash( 'sha256', $secret ),
            'created_at' => $now,
            'created_by' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
            'last_used_at' => '',
            'revoked_at' => '',
        );
        $entries[] = $entry;
        if ( false === update_option( self::OPTION_KEY, $entries, false ) ) { return new WP_Error( 'design_core_credential_write_failed', 'Unable to persist the new machine credential.' ); }

        return array(
            'credential' => $this->to_public( $entry ),
            // Only ever returned once, at creation time -- callers must display and discard this.
            'token' => self::TOKEN_PREFIX . '_' . $id . '_' . $secret,
        );
    }

    public function revoke( $id ) {
        $id = sanitize_key( (string) $id );
        $entries = $this->all(); $found = false;
        foreach ( $entries as &$entry ) {
            if ( $id === (string) ( $entry['id'] ?? '' ) ) {
                if ( ! empty( $entry['revoked_at'] ) ) { return new WP_Error( 'design_core_credential_already_revoked', 'This machine credential is already revoked.' ); }
                $entry['revoked_at'] = gmdate( 'c' ); $found = true; break;
            }
        }
        unset( $entry );
        if ( ! $found ) { return new WP_Error( 'design_core_credential_not_found', 'Machine credential was not found.' ); }
        update_option( self::OPTION_KEY, $entries, false );
        return true;
    }

    /** Revokes the old credential and issues a brand new id/secret with the same name/scopes/environment. */
    public function rotate( $id ) {
        $existing = $this->find( $id );
        if ( ! $existing ) { return new WP_Error( 'design_core_credential_not_found', 'Machine credential was not found.' ); }
        $revoked = $this->revoke( $id );
        if ( is_wp_error( $revoked ) && 'design_core_credential_already_revoked' !== $revoked->get_error_code() ) { return $revoked; }
        return $this->create( array( 'name' => $existing['name'], 'scopes' => $existing['scopes'], 'environment' => $existing['environment'] ) );
    }

    public function record_use( $id ) {
        $id = sanitize_key( (string) $id );
        $entries = $this->all();
        foreach ( $entries as &$entry ) { if ( $id === (string) ( $entry['id'] ?? '' ) ) { $entry['last_used_at'] = gmdate( 'c' ); break; } }
        unset( $entry );
        update_option( self::OPTION_KEY, $entries, false );
    }

    public function has_scope( array $credential, $scope ) {
        return in_array( sanitize_key( (string) $scope ), (array) ( $credential['scopes'] ?? array() ), true );
    }

    public function to_public( array $entry ) {
        return array(
            'id' => (string) ( $entry['id'] ?? '' ),
            'name' => (string) ( $entry['name'] ?? '' ),
            'scopes' => (array) ( $entry['scopes'] ?? array() ),
            'environment' => (string) ( $entry['environment'] ?? '' ),
            'created_at' => (string) ( $entry['created_at'] ?? '' ),
            'last_used_at' => (string) ( $entry['last_used_at'] ?? '' ),
            'revoked_at' => (string) ( $entry['revoked_at'] ?? '' ),
            'active' => empty( $entry['revoked_at'] ),
        );
    }

    private function sanitize_scopes( array $scopes ) {
        $valid = Design_Core_Elementor_Capabilities::all();
        $sanitized = array_map( 'sanitize_key', $scopes );
        return array_values( array_unique( array_intersect( $sanitized, $valid ) ) );
    }

    private function generate_id() { return bin2hex( random_bytes( 8 ) ); }
    private function generate_secret() { return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); }
}

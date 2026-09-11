<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Idempotency-Key support for write routes, backed by bounded/auto-expiring
 * transients. Same key + same request fingerprint replays the stored result without
 * re-running the operation; same key + a different fingerprint is refused as a conflict.
 */
class Design_Core_Elementor_Idempotency_Store {
    const PREFIX = 'design_core_elementor_idem_';
    const PENDING_TTL = 300;
    const RESULT_TTL = 86400;

    public static function begin( $scope_id, $key, $fingerprint ) {
        $key = trim( (string) $key );
        if ( '' === $key ) { return array( 'replay' => false, 'enabled' => false ); }

        $storage_key = self::storage_key( $scope_id, $key );
        $existing = get_transient( $storage_key );
        if ( is_array( $existing ) ) {
            if ( ! hash_equals( (string) $existing['fingerprint'], (string) $fingerprint ) ) {
                return new WP_Error( 'design_core_idempotency_conflict', 'This Idempotency-Key was already used with a different request payload.', array( 'status' => 409 ) );
            }
            if ( 'pending' === $existing['state'] ) {
                return new WP_Error( 'design_core_idempotency_in_progress', 'A request with this Idempotency-Key is still being processed.', array( 'status' => 409 ) );
            }
            return array( 'replay' => true, 'enabled' => true, 'response' => $existing['response'] );
        }

        set_transient( $storage_key, array( 'state' => 'pending', 'fingerprint' => $fingerprint, 'response' => null ), self::PENDING_TTL );
        return array( 'replay' => false, 'enabled' => true );
    }

    public static function complete( $scope_id, $key, $fingerprint, $is_error, $status, $body ) {
        $key = trim( (string) $key );
        if ( '' === $key ) { return; }
        set_transient(
            self::storage_key( $scope_id, $key ),
            array( 'state' => 'done', 'fingerprint' => $fingerprint, 'response' => array( 'is_error' => (bool) $is_error, 'status' => (int) $status, 'body' => Design_Core_Elementor_Change_Ledger::transport_safe( $body ) ) ),
            self::RESULT_TTL
        );
    }

    public static function fingerprint( $method, $route, $body ) {
        return hash( 'sha256', strtoupper( (string) $method ) . '|' . (string) $route . '|' . (string) wp_json_encode( Design_Core_Elementor_Change_Ledger::transport_safe( (array) $body ) ) );
    }

    private static function storage_key( $scope_id, $key ) {
        return self::PREFIX . substr( hash( 'sha256', $scope_id . '|' . $key ), 0, 40 );
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Correlates MCP/remote write activity with the existing Change Ledger rather than
 * duplicating it. Every field here is safe to display: no bearer tokens, passwords,
 * Figma tokens or raw headers are ever accepted by record().
 */
class Design_Core_Elementor_Remote_Audit_Log {
    const OPTION_KEY = 'design_core_elementor_remote_audit_log';
    const SCHEMA_VERSION = 1;
    const MAX_ENTRIES = 500;

    public static function record( array $entry ) {
        $entries = get_option( self::OPTION_KEY, array() );
        $entries = is_array( $entries ) ? $entries : array();
        $entry = array_merge( array(
            'schema_version' => self::SCHEMA_VERSION,
            'timestamp' => gmdate( 'c' ),
            'request_id' => '',
            'site' => '',
            'tool' => '',
            'page_id' => 0,
            'machine_credential_id' => '',
            'actor' => 0,
            'preview_id' => '',
            'plan_hash' => '',
            'before_hash' => '',
            'after_hash' => '',
            'result' => '',
            'history_entry_id' => '',
        ), Design_Core_Elementor_Change_Ledger::transport_safe( $entry ) );

        array_unshift( $entries, $entry );
        if ( count( $entries ) > self::MAX_ENTRIES ) { $entries = array_slice( $entries, 0, self::MAX_ENTRIES ); }
        update_option( self::OPTION_KEY, $entries, false );
        return $entry;
    }

    public static function all() {
        $entries = get_option( self::OPTION_KEY, array() );
        return is_array( $entries ) ? $entries : array();
    }
}

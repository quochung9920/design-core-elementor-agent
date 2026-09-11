<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Small, bounded settings surface for the rc21 remote/MCP layer (environment + the global write kill switch). */
class Design_Core_Elementor_Remote_Settings {
    const OPTION_KEY = 'design_core_elementor_remote_settings';
    const ENVIRONMENTS = array( 'local', 'staging', 'production' );

    public static function get() {
        $stored = get_option( self::OPTION_KEY, array() );
        $stored = is_array( $stored ) ? $stored : array();
        return array(
            'write_enabled' => array_key_exists( 'write_enabled', $stored ) ? (bool) $stored['write_enabled'] : true,
            'environment' => in_array( $stored['environment'] ?? '', self::ENVIRONMENTS, true ) ? $stored['environment'] : 'local',
        );
    }

    public static function save( array $raw ) {
        $settings = array(
            'write_enabled' => ! empty( $raw['write_enabled'] ),
            'environment' => in_array( $raw['environment'] ?? '', self::ENVIRONMENTS, true ) ? $raw['environment'] : 'local',
        );
        update_option( self::OPTION_KEY, $settings, false );
        return $settings;
    }

    public static function writes_enabled() { return (bool) self::get()['write_enabled']; }
    public static function environment() { return (string) self::get()['environment']; }
    public static function is_production() { return 'production' === self::environment(); }
}

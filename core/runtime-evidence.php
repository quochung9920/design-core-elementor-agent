<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Runtime_Evidence {
    const OPTION_KEY = 'design_core_elementor_runtime_evidence';
    const SCHEMA_VERSION = 1;
    const STATUSES = array( 'pass', 'fail', 'unavailable', 'partial' );

    public function record( $key, $status = 'pass', $details = array(), $source = 'runtime' ) {
        $key = sanitize_key( (string) $key );
        if ( ! $key ) { return new WP_Error( 'design_core_invalid_evidence_key', 'Evidence key is required.' ); }
        $status = 'skip' === $status ? 'unavailable' : $status;
        $status = in_array( $status, self::STATUSES, true ) ? $status : 'fail';
        $all = $this->all_raw();
        $caps = class_exists( 'Design_Core_Elementor_Capability_Scanner' ) ? ( new Design_Core_Elementor_Capability_Scanner() )->scan() : array();
        $all[ $key ] = array(
            'schema_version' => self::SCHEMA_VERSION,
            'key' => $key,
            'status' => $status,
            'source' => sanitize_key( (string) $source ),
            'details' => $this->sanitize_details( $details ),
            'recorded_at' => gmdate( 'c' ),
            'timestamp' => gmdate( 'c' ),
            'ttl' => (int) apply_filters( 'design_core_elementor_runtime_evidence_ttl', 30 * DAY_IN_SECONDS ),
            'environment' => array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'elementor' => $caps['elementor']['version'] ?? 'unknown', 'elementor_pro' => $caps['elementor']['pro_version'] ?? 'not-installed', 'editor_mode' => $caps['elementor']['editor_mode'] ?? 'unknown' ),
            'version' => defined( 'DESIGN_CORE_ELEMENTOR_VERSION' ) ? DESIGN_CORE_ELEMENTOR_VERSION : 'unknown',
            'plugin_version' => defined( 'DESIGN_CORE_ELEMENTOR_VERSION' ) ? DESIGN_CORE_ELEMENTOR_VERSION : 'unknown',
            'wordpress_version' => get_bloginfo( 'version' ),
            'php_version' => PHP_VERSION,
            'elementor_version' => $caps['elementor']['version'] ?? 'unknown',
            'elementor_pro_version' => $caps['elementor']['pro_version'] ?? 'not-installed',
            'editor_mode' => $caps['elementor']['editor_mode'] ?? 'unknown',
        );
        update_option( self::OPTION_KEY, $all, false );
        return $all[ $key ];
    }

    public function get( $key ) {
        $all = $this->all_raw();
        return $all[ sanitize_key( (string) $key ) ] ?? null;
    }

    public function all() {
        $result = array();
        foreach ( $this->all_raw() as $key => $evidence ) {
            $evidence['fresh'] = $this->is_fresh( $evidence );
            $result[ $key ] = $evidence;
        }
        return $result;
    }

    public function passed( $key ) {
        $evidence = $this->get( $key );
        return is_array( $evidence ) && 'pass' === ( $evidence['status'] ?? '' ) && $this->is_fresh( $evidence );
    }

    public function clear( $key = '' ) {
        if ( '' === (string) $key ) { delete_option( self::OPTION_KEY ); return true; }
        $all = $this->all_raw();
        unset( $all[ sanitize_key( (string) $key ) ] );
        update_option( self::OPTION_KEY, $all, false );
        return true;
    }

    public function is_fresh( $evidence ) {
        if ( ! is_array( $evidence ) || empty( $evidence['recorded_at'] ) ) { return false; }
        $recorded = strtotime( (string) $evidence['recorded_at'] );
        if ( ! $recorded ) { return false; }
        $ttl = isset( $evidence['ttl'] ) ? (int) $evidence['ttl'] : (int) apply_filters( 'design_core_elementor_runtime_evidence_ttl', 30 * DAY_IN_SECONDS );
        if ( $ttl > 0 && ( time() - $recorded ) > $ttl ) { return false; }
        $current = defined( 'DESIGN_CORE_ELEMENTOR_VERSION' ) ? DESIGN_CORE_ELEMENTOR_VERSION : 'unknown';
        if ( ( $evidence['plugin_version'] ?? '' ) !== $current ) { return false; }
        $caps = class_exists( 'Design_Core_Elementor_Capability_Scanner' ) ? ( new Design_Core_Elementor_Capability_Scanner() )->scan() : array();
        $environment = array( 'wordpress_version' => get_bloginfo( 'version' ), 'php_version' => PHP_VERSION, 'elementor_version' => $caps['elementor']['version'] ?? 'unknown', 'elementor_pro_version' => $caps['elementor']['pro_version'] ?? 'not-installed', 'editor_mode' => $caps['elementor']['editor_mode'] ?? 'unknown' );
        foreach ( $environment as $field => $value ) { if ( (string) ( $evidence[ $field ] ?? '' ) !== (string) $value ) { return false; } }
        return true;
    }

    private function all_raw() {
        $all = get_option( self::OPTION_KEY, array() );
        return is_array( $all ) ? $all : array();
    }

    private function sanitize_details( $value ) {
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $key => $item ) { $out[ sanitize_key( (string) $key ) ] = $this->sanitize_details( $item ); }
            return $out;
        }
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) { return $value; }
        return sanitize_text_field( (string) $value );
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Design_Token_Service {
    const OPTION_KEY = 'design_core_elementor_tokens_v2';
    const SCHEMA_VERSION = 2;

    public function __construct() {
        add_action( 'update_option_design_core_design_tokens', array( $this, 'sync_legacy_settings' ), 10, 2 );
        add_action( 'add_option_design_core_design_tokens', array( $this, 'sync_added_settings' ), 10, 2 );
    }

    public function all() {
        $stored = get_option( self::OPTION_KEY, array() );
        if ( empty( $stored ) ) { $stored = $this->migrate_legacy(); }
        return is_array( $stored ) ? $stored : array();
    }

    public function save( $tokens ) {
        $this->assert_import_access();
        $normalized = $this->normalize( $tokens );
        update_option( self::OPTION_KEY, $normalized, false );
        if ( get_option( self::OPTION_KEY, null ) !== $normalized ) { throw new RuntimeException( 'Token persistence verification failed.' ); }
        return $normalized;
    }

    public function sync_legacy_settings( $old_value, $new_value ) { $this->save( $new_value ); }
    public function sync_added_settings( $option, $value ) { $this->save( $value ); }

    public function compile_dictionary( array $dictionary ) {
        if ( ! class_exists( 'Design_Core_Elementor_Design_Token_Pipeline' ) ) { return new WP_Error( 'design_core_token_pipeline_unavailable', 'Design token pipeline is unavailable.' ); }
        try { return ( new Design_Core_Elementor_Design_Token_Pipeline() )->compile( $dictionary ); }
        catch ( Throwable $exception ) { return new WP_Error( 'design_core_token_compile_failed', $exception->getMessage() ); }
    }

    public function export_css_variables( array $dictionary, $prefix = 'dc' ) {
        $compiled = $this->compile_dictionary( $dictionary );
        if ( is_wp_error( $compiled ) ) { return $compiled; }
        return ( new Design_Core_Elementor_Design_Token_Pipeline() )->to_css_variables( $compiled, $prefix );
    }

    public function normalize( $tokens ) {
        $result = array( 'schema_version' => self::SCHEMA_VERSION, 'colors' => array(), 'typography' => array(), 'spacing' => array(), 'radius' => array() );
        if ( ! is_array( $tokens ) ) { return $result; }
        if ( class_exists( 'Design_Core_Elementor_Design_Token_Pipeline' ) && Design_Core_Elementor_Design_Token_Pipeline::looks_like_dictionary( $tokens ) ) {
            $compiled = $this->compile_dictionary( $tokens );
            if ( is_wp_error( $compiled ) ) { throw new InvalidArgumentException( $compiled->get_error_message() ); }
            $tokens = (array) ( $compiled['tokens'] ?? array() );
        }
        foreach ( is_array( $tokens['colors'] ?? null ) ? $tokens['colors'] : array() as $name => $value ) {
            $color = sanitize_hex_color( $value ); if ( $color ) { $result['colors'][ sanitize_key( $name ) ] = $color; }
        }
        $typography = $tokens['typography'] ?? $tokens['fonts'] ?? array();
        foreach ( is_array( $typography ) ? $typography : array() as $name => $value ) {
            $key = sanitize_key( (string) $name );
            if ( is_array( $value ) ) { $result['typography'][ $key ] = array(); foreach ( $value as $property => $setting ) { if ( is_scalar( $setting ) || null === $setting ) { $result['typography'][ $key ][ sanitize_key( (string) $property ) ] = sanitize_text_field( (string) $setting ); } } }
            elseif ( is_scalar( $value ) || null === $value ) { $result['typography'][ $key ] = sanitize_text_field( (string) $value ); }
        }
        foreach ( (array) ( $tokens['spacing'] ?? array() ) as $name => $value ) {
            $key = is_string( $name ) ? sanitize_key( $name ) : 'space-' . absint( $name );
            if ( is_numeric( $value ) ) { $result['spacing'][ $key ] = (float) $value; }
        }
        foreach ( (array) ( $tokens['radius'] ?? $tokens['border_radius'] ?? array() ) as $name => $value ) {
            $key = is_string( $name ) ? sanitize_key( $name ) : 'radius-' . absint( $name );
            if ( is_numeric( $value ) ) { $result['radius'][ $key ] = (float) $value; }
        }
        return $result;
    }

    public function export_for_elementor( $mode = 'v3' ) { return apply_filters( 'design_core_elementor_export_tokens', $this->all(), $mode ); }

    private function assert_import_access() { $owner = $GLOBALS['design_core_elementor_registry_import_token'] ?? ''; $lock = get_option( 'design_core_elementor_registry_import_lock', null ); if ( ! is_array( $lock ) ) { if ( $owner ) { throw new RuntimeException( 'design_core_registry_import_lock_lost' ); } return; } if ( (int) ( $lock['expires_at'] ?? 0 ) < time() ) { delete_option( 'design_core_elementor_registry_import_lock' ); if ( $owner ) { throw new RuntimeException( 'design_core_registry_import_lock_lost' ); } return; } if ( $owner ) { if ( ! hash_equals( (string) ( $lock['token'] ?? '' ), (string) $owner ) ) { throw new RuntimeException( 'design_core_registry_import_lock_lost' ); } return; } throw new RuntimeException( 'design_core_registry_import_locked' ); }

    private function migrate_legacy() {
        $legacy = get_option( 'design_core_design_tokens', array() );
        if ( empty( $legacy ) ) {
            $registry = get_option( 'design_core_elementor_tokens', array() ); $legacy = array();
            foreach ( is_array( $registry ) ? $registry : array() as $token ) {
                $key = (string) ( $token['key'] ?? '' );
                if ( 0 === strpos( $key, 'color.' ) ) { $legacy['colors'][ substr( $key, 6 ) ] = $token['value'] ?? ''; }
                elseif ( 0 === strpos( $key, 'spacing.' ) ) { $legacy['spacing'][ substr( $key, 8 ) ] = $token['value'] ?? 0; }
                elseif ( 0 === strpos( $key, 'typography.' ) ) { $legacy['typography'][ substr( $key, 11 ) ] = $token['value'] ?? array(); }
            }
        }
        return $this->save( $legacy );
    }
}

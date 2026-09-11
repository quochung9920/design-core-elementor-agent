<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Fingerprint_Service {
    const VERSION = 2;
    public function from_node( $node ) {
        return array(
            'version' => self::VERSION,
            'semantic' => sanitize_key( $node['semantic']['role'] ?? 'component' ),
            'structure' => sanitize_text_field( $node['component']['fingerprint']['structure'] ?? '' ),
            'content_schema' => array_values( array_map( 'sanitize_key', $node['component']['content_schema'] ?? array() ) ),
            'layout' => sanitize_text_field( $node['layout']['display'] ?? '' ),
            'interaction' => sanitize_text_field( $node['component']['fingerprint']['interaction'] ?? '' ),
        );
    }
    public function lookup_hash( $fingerprint ) { return hash( 'sha256', wp_json_encode( $fingerprint ) ); }
}

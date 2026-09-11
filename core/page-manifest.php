<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Page_Manifest {
    const SCHEMA_VERSION = 1;

    public static function from_ir( array $ir, $page_id = 0, $title = '' ) {
        if ( class_exists( 'Design_Core_Elementor_Section_Intelligence' ) ) {
            return ( new Design_Core_Elementor_Section_Intelligence() )->manifest( $ir, $page_id, $title );
        }
        return array( 'schema_version' => self::SCHEMA_VERSION, 'page_id' => (int) $page_id, 'title' => sanitize_text_field( $title ), 'sections' => array() );
    }

    /** Use after Section Registry synchronization; these actions/master IDs are authoritative for the committed page. */
    public static function from_sync( array $sync_results, $page_id = 0, $title = '' ) {
        $sections = array();
        foreach ( $sync_results as $result ) {
            if ( ! is_array( $result ) || empty( $result['root_id'] ) ) { continue; }
            $sections[] = array(
                'position' => (int) ( $result['position'] ?? count( $sections ) ),
                'root_id' => (string) $result['root_id'],
                'family' => sanitize_key( $result['family'] ?? 'section' ),
                'action' => sanitize_key( $result['action'] ?? 'new' ),
                'master_id' => (string) ( $result['master_id'] ?? '' ),
                'variant_id' => sanitize_key( $result['variant_id'] ?? '' ),
                'score' => (float) ( $result['score'] ?? 0 ),
                'reason' => sanitize_text_field( $result['reason'] ?? '' ),
            );
        }
        usort( $sections, static function ( $a, $b ) { return $a['position'] <=> $b['position']; } );
        return array( 'schema_version' => self::SCHEMA_VERSION, 'page_id' => (int) $page_id, 'title' => sanitize_text_field( $title ), 'sections' => $sections );
    }

    public static function validate( array $manifest ) {
        if ( self::SCHEMA_VERSION !== ( $manifest['schema_version'] ?? null ) || ! is_array( $manifest['sections'] ?? null ) ) { throw new InvalidArgumentException( 'Page Manifest v1 is invalid.' ); }
        $roots = array();
        foreach ( $manifest['sections'] as $section ) {
            if ( ! is_array( $section ) || ! is_string( $section['root_id'] ?? null ) || '' === trim( $section['root_id'] ) ) { throw new InvalidArgumentException( 'Page Manifest section root_id is invalid.' ); }
            if ( isset( $roots[ $section['root_id'] ] ) ) { throw new InvalidArgumentException( 'Page Manifest section root_id must be unique.' ); }
            $roots[ $section['root_id'] ] = true;
            if ( ! in_array( $section['action'] ?? 'new', array( 'new', 'reuse', 'variant' ), true ) ) { throw new InvalidArgumentException( 'Page Manifest section action is invalid.' ); }
            if ( isset( $section['position'] ) && ! is_int( $section['position'] ) ) { throw new InvalidArgumentException( 'Page Manifest section position is invalid.' ); }
            if ( isset( $section['master_id'] ) && ! is_string( $section['master_id'] ) ) { throw new InvalidArgumentException( 'Page Manifest section master_id is invalid.' ); }
            if ( isset( $section['variant_id'] ) && ! is_string( $section['variant_id'] ) ) { throw new InvalidArgumentException( 'Page Manifest section variant_id is invalid.' ); }
        }
        return true;
    }
}

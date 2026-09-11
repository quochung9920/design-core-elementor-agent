<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Keeps fonts used only by mixed Figma text runs available on the frontend.
 * Elementor can enqueue the base widget font itself, but inline accent runs do
 * not participate in Elementor's font dependency scan. The manifest is stored
 * per imported draft/page and each family is requested independently so an
 * unavailable custom family cannot break every other font.
 */
class Design_Core_Elementor_Figma_Font_Service {
    const VERSION = 1;
    const META_KEY = '_design_core_figma_fonts';

    public function boot() { add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_current_page_fonts' ), 25 ); }

    public function manifest_from_ir( array $ir ) {
        $families = array();
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            $this->collect_style( (array) ( $node['style'] ?? array() ), $families );
            foreach ( (array) ( $node['figma']['text_runs'] ?? array() ) as $run ) { if ( is_array( $run ) ) { $this->collect_style( (array) ( $run['style'] ?? array() ), $families ); } }
            foreach ( (array) ( $node['figma']['text_composition']['runs'] ?? array() ) as $run ) { if ( is_array( $run ) ) { $this->collect_style( (array) ( $run['style'] ?? array() ), $families ); } }
        }
        $out = array();
        foreach ( $families as $family => $variants ) {
            $out[] = array( 'family' => $family, 'variants' => array_values( array_unique( $variants ) ) );
        }
        return array( 'version' => self::VERSION, 'families' => $out );
    }

    public function store_manifest( $page_id, array $ir ) {
        $manifest = $this->manifest_from_ir( $ir );
        update_post_meta( (int) $page_id, self::META_KEY, $manifest );
        return $manifest;
    }

    public function enqueue_current_page_fonts() {
        if ( is_admin() || ! function_exists( 'get_queried_object_id' ) ) { return; }
        $page_id = (int) get_queried_object_id();
        if ( $page_id < 1 ) { return; }
        $manifest = get_post_meta( $page_id, self::META_KEY, true );
        if ( ! is_array( $manifest ) ) { return; }
        foreach ( (array) ( $manifest['families'] ?? array() ) as $index => $entry ) {
            if ( ! is_array( $entry ) ) { continue; }
            $url = $this->google_css_url( (string) ( $entry['family'] ?? '' ), (array) ( $entry['variants'] ?? array() ) );
            if ( ! $url ) { continue; }
            $url = apply_filters( 'design_core_elementor_figma_font_url', $url, $entry, $page_id );
            if ( is_string( $url ) && $url ) { wp_enqueue_style( 'design-core-figma-font-' . $page_id . '-' . (int) $index, $url, array(), null ); }
        }
    }

    private function collect_style( array $style, array &$families ) {
        $family = trim( (string) ( $style['font_family'] ?? $style['fontFamily'] ?? '' ) );
        $family = preg_replace( '/[^\pL\pN _-]+/u', '', $family );
        if ( ! $family || strlen( $family ) > 96 ) { return; }
        $weight = (int) ( $style['font_weight'] ?? $style['fontWeight'] ?? 400 );
        if ( $weight < 100 || $weight > 900 ) { $weight = 400; }
        $weight = (int) ( round( $weight / 100 ) * 100 );
        $italic = false !== strpos( strtolower( (string) ( $style['font_style'] ?? $style['fontStyle'] ?? '' ) ), 'italic' );
        $families[ $family ][] = ( $italic ? '1' : '0' ) . ':' . $weight;
    }

    private function google_css_url( $family, array $variants ) {
        $family = trim( preg_replace( '/[^\pL\pN _-]+/u', '', (string) $family ) );
        if ( ! $family ) { return ''; }
        $pairs = array();
        foreach ( $variants ?: array( '0:400' ) as $variant ) {
            if ( ! preg_match( '/^([01]):([1-9]00)$/', (string) $variant, $match ) ) { continue; }
            $pairs[] = array( (int) $match[1], (int) $match[2] );
        }
        if ( ! $pairs ) { $pairs[] = array( 0, 400 ); }
        usort( $pairs, static fn( $a, $b ) => $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0] );
        $has_italic = count( array_unique( array_column( $pairs, 0 ) ) ) > 1 || 1 === $pairs[0][0];
        $value = str_replace( '%20', '+', rawurlencode( $family ) );
        if ( $has_italic ) { $value .= ':ital,wght@' . implode( ';', array_map( static fn( $pair ) => $pair[0] . ',' . $pair[1], $pairs ) ); }
        else { $value .= ':wght@' . implode( ';', array_map( static fn( $pair ) => (string) $pair[1], $pairs ) ); }
        return 'https://fonts.googleapis.com/css2?family=' . $value . '&display=swap';
    }
}

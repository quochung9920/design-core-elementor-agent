<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Decides whether a source media query can be represented by a real Elementor
 * responsive suffix without changing its activation range. Nearest-breakpoint
 * approximation is forbidden: a 600px source rule must not become mobile@767.
 */
final class Design_Core_Elementor_Breakpoint_Fidelity {
    const VERSION = 1;

    public function native_device( $media, array $breakpoints ) {
        $media = $this->normalize_media( $media );
        if ( preg_match( '/^\(\s*max-width\s*:\s*(\d+(?:\.\d+)?)px\s*\)$/i', $media, $match ) ) {
            $width = (int) round( (float) $match[1] );
            foreach ( $breakpoints as $device => $value ) {
                $device = sanitize_key( (string) $device );
                if ( 'widescreen' === $device ) { continue; }
                if ( in_array( $device, array( 'laptop','tablet_extra','tablet','mobile_extra','mobile' ), true ) && abs( (int) $value - $width ) <= 1 ) { return $device; }
            }
        }
        // Elementor widescreen is the exceptional min-width responsive state.
        if ( preg_match( '/^\(\s*min-width\s*:\s*(\d+(?:\.\d+)?)px\s*\)$/i', $media, $match ) && isset( $breakpoints['widescreen'] ) ) {
            return abs( (int) $breakpoints['widescreen'] - (int) round( (float) $match[1] ) ) <= 1 ? 'widescreen' : '';
        }
        return '';
    }

    /** Bind parser-cached non-native media rules to their canonical IR node. */
    public function attach_cached_fallbacks( array $ir ) {
        $safe = 0; $unsafe = 0; $nodes_with_fallbacks = 0; $fingerprint = array();
        if ( ! class_exists( 'Design_Core_Elementor_Responsive_Style_Parser' ) ) { return $ir; }
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $index => $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $path = (string) ( $node['source']['dom_path'] ?? '' );
            $fallbacks = Design_Core_Elementor_Responsive_Style_Parser::fallbacks_for_dom_path( $path );
            if ( ! $fallbacks ) { continue; }
            $nodes_with_fallbacks++;
            $ir['nodes'][ $index ]['responsive_fallbacks'] = $fallbacks;
            foreach ( $fallbacks as $fallback ) {
                $media = (string) ( $fallback['media'] ?? '' );
                if ( $this->safe_fallback_media( $media ) ) { $safe++; } else { $unsafe++; }
                $fingerprint[] = array( 'node'=>(string)($node['id']??''), 'media'=>$this->normalize_media($media), 'declarations'=>array_keys((array)($fallback['declarations']??array())) );
            }
        }
        Design_Core_Elementor_Responsive_Style_Parser::clear_fallback_cache();
        $ir['breakpoint_fidelity'] = array(
            'version' => self::VERSION,
            'status' => $unsafe ? 'blocked' : 'pass',
            'policy' => 'exact-native-or-scoped-source-media',
            'nodes_with_fallbacks' => $nodes_with_fallbacks,
            'safe_fallback_rule_count' => $safe,
            'unsupported_fallback_rule_count' => $unsafe,
            'evidence_hash' => hash( 'sha256', wp_json_encode( $fingerprint ) ),
        );
        return $ir;
    }

    public function normalize_media( $media ) {
        $media = preg_replace( '/\s+/', ' ', trim( (string) $media ) );
        $media = preg_replace( '/\s*:\s*/', ': ', $media );
        return trim( (string) $media );
    }

    /**
     * Scoped fallback intentionally supports only width-range media conditions.
     * Other features stay blocked until a dedicated adapter exists.
     */
    public function safe_fallback_media( $media ) {
        $media = $this->normalize_media( $media );
        if ( '' === $media || strlen( $media ) > 240 || preg_match( '/[{};@<>\\\\\x00-\x1F\x7F]/', $media ) ) { return false; }
        $clause = '\(\s*(?:min|max)-width\s*:\s*\d+(?:\.\d+)?px\s*\)';
        return (bool) preg_match( '/^' . $clause . '(?:\s+and\s+' . $clause . ')*$/i', $media );
    }

    public function coverage_key( $property ) {
        return sanitize_key( str_replace( '-', '_', strtolower( trim( (string) $property ) ) ) . '_media' );
    }
}

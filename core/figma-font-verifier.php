<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Verifies that Figma-authored font families actually render in the browser. */
class Design_Core_Elementor_Figma_Font_Verifier {
    const VERSION = 1;

    public function report( array $ir, array $candidate_analysis, $viewport ) {
        $viewport = (int) $viewport;
        $elements = $this->viewport_elements( $candidate_analysis, $viewport );
        if ( ! $elements ) {
            return array( 'version' => self::VERSION, 'status' => 'unavailable', 'viewport' => $viewport, 'expected' => 0, 'verified' => 0, 'match_ratio' => 0, 'issues' => array() );
        }

        $by_figma = array();
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) { continue; }
            $class = (string) ( $element['figmaClass'] ?? $element['figma_class'] ?? '' );
            if ( $class ) { $by_figma[ $class ][] = $element; }
        }

        $expected = 0; $verified = 0; $issues = array();
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $figma_id = (string) ( $node['figma']['id'] ?? '' );
            if ( ! $figma_id ) { continue; }
            $families = $this->expected_families( $node );
            if ( ! $families ) { continue; }
            $class = $this->figma_class( $figma_id );
            $candidates = (array) ( $by_figma[ $class ] ?? array() );

            foreach ( $families as $family ) {
                $expected++;
                $matched = false; $loaded = false; $actual = array(); $elementor_id = '';
                foreach ( $candidates as $candidate ) {
                    $primary = $this->normalize_family( (string) ( $candidate['fontFamilyPrimary'] ?? $candidate['font_family_primary'] ?? $candidate['styles']['fontFamily'] ?? '' ) );
                    if ( $primary ) { $actual[] = $primary; }
                    if ( ! $elementor_id ) { $elementor_id = sanitize_key( (string) ( $candidate['elementorId'] ?? $candidate['elementor_id'] ?? '' ) ); }
                    if ( $primary !== $this->normalize_family( $family ) ) { continue; }
                    $matched = true;
                    if ( true === ( $candidate['fontLoaded'] ?? $candidate['font_loaded'] ?? null ) ) { $loaded = true; break; }
                    if ( null === ( $candidate['fontLoaded'] ?? $candidate['font_loaded'] ?? null ) ) { $loaded = true; break; }
                }
                if ( $matched && $loaded ) { $verified++; continue; }
                $issues[] = array(
                    'viewport' => $viewport,
                    'category' => 'font',
                    'severity' => 'high',
                    'message' => $matched ? 'Expected Figma font family is present in computed styles but the browser could not prove it loaded.' : 'Expected Figma font family is not rendered for this source node.',
                    'figma_id' => $figma_id,
                    'figma_class' => $class,
                    'elementor_id' => $elementor_id,
                    'expected_family' => $family,
                    'candidate_families' => array_values( array_unique( array_filter( $actual ) ) ),
                    'loaded' => $loaded,
                );
            }
        }

        $ratio = $expected ? $verified / $expected : 1;
        return array(
            'version' => self::VERSION,
            'status' => $expected > 0 && $ratio >= 0.98 && ! $issues ? 'pass' : ( $expected ? 'fail' : 'not-applicable' ),
            'viewport' => $viewport,
            'expected' => $expected,
            'verified' => $verified,
            'match_ratio' => round( $ratio, 4 ),
            'issue_count' => count( $issues ),
            'issues' => $issues,
        );
    }

    private function expected_families( array $node ) {
        $families = array();
        $base = trim( (string) ( $node['style']['font_family'] ?? '' ) );
        if ( $base ) { $families[] = $base; }
        foreach ( (array) ( $node['figma']['text_composition']['runs'] ?? array() ) as $run ) {
            if ( ! is_array( $run ) ) { continue; }
            $family = trim( (string) ( $run['style']['font_family'] ?? $run['style']['fontFamily'] ?? '' ) );
            if ( $family ) { $families[] = $family; }
        }
        foreach ( (array) ( $node['figma']['text_runs'] ?? array() ) as $run ) {
            if ( ! is_array( $run ) ) { continue; }
            $family = trim( (string) ( $run['style']['font_family'] ?? $run['style']['fontFamily'] ?? '' ) );
            if ( $family ) { $families[] = $family; }
        }
        $normalized = array();
        foreach ( $families as $family ) {
            $key = $this->normalize_family( $family );
            if ( $key ) { $normalized[ $key ] = sanitize_text_field( $family ); }
        }
        return array_values( $normalized );
    }

    private function viewport_elements( array $analysis, $viewport ) {
        $views = is_array( $analysis['data']['viewports'] ?? null ) ? $analysis['data']['viewports'] : (array) ( $analysis['viewports'] ?? array() );
        if ( isset( $views[ $viewport ] ) && is_array( $views[ $viewport ] ) ) { return $views[ $viewport ]; }
        foreach ( $views as $width => $elements ) { if ( (int) $width === $viewport && is_array( $elements ) ) { return $elements; } }
        return array();
    }

    private function normalize_family( $family ) {
        $family = trim( (string) $family );
        if ( false !== strpos( $family, ',' ) ) { $family = trim( explode( ',', $family )[0] ); }
        return strtolower( trim( $family, " \t\n\r\0\x0B\"'" ) );
    }

    private function figma_class( $id ) {
        return 'dc-figma-node-' . sanitize_html_class( trim( strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $id ) ), '-' ) );
    }
}

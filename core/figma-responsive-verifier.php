<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Verifies responsive runtime safety when the Figma source has no independent
 * tablet/mobile frames. This is deliberately labelled inferred-runtime: it can
 * prove that the compiled root adapts without gross horizontal overflow, but it
 * never claims pixel fidelity to a mobile reference that does not exist.
 */
class Design_Core_Elementor_Figma_Responsive_Verifier {
    const VERSION = 1;
    const DEFAULT_VIEWPORTS = array( 1024, 768, 390 );

    public function report( array $ir, array $candidate_analysis, array $viewports = array() ) {
        $viewports = $viewports ?: self::DEFAULT_VIEWPORTS;
        $root = $this->root_node( $ir );
        $figma_id = (string) ( $root['figma']['id'] ?? '' );
        if ( ! $figma_id ) { return array( 'version' => self::VERSION, 'status' => 'unavailable', 'mode' => 'inferred-runtime', 'issues' => array( array( 'severity' => 'high', 'message' => 'Figma root identity is unavailable.' ) ) ); }
        $root_class = $this->figma_class( $figma_id );
        $issues = array(); $verified = 0; $expected = 0; $evidence = array();

        foreach ( array_values( array_unique( array_map( 'intval', $viewports ) ) ) as $viewport ) {
            if ( $viewport < 320 || $viewport > 3840 ) { continue; }
            $expected++;
            $elements = $this->viewport_elements( $candidate_analysis, $viewport );
            $rendered_root = $this->find_direct_figma_node( $elements, $root_class );
            if ( ! $rendered_root ) {
                $issues[] = array( 'viewport' => $viewport, 'category' => 'responsive', 'severity' => 'high', 'message' => 'Compiled Figma root is missing at a responsive verification viewport.', 'figma_id' => $figma_id );
                continue;
            }
            $rect = (array) ( $rendered_root['rect'] ?? array() );
            $x = (float) ( $rect['x'] ?? 0 ); $width = (float) ( $rect['width'] ?? 0 );
            $right = $x + $width;
            $root_overflow = $width <= 0 || $x < -4 || $right > $viewport + 4;
            $gross = array();
            foreach ( $elements as $element ) {
                if ( ! is_array( $element ) ) { continue; }
                $class = (string) ( $element['figmaClass'] ?? $element['figma_class'] ?? '' );
                if ( ! $class ) { continue; }
                $r = (array) ( $element['rect'] ?? array() );
                $ew = (float) ( $r['width'] ?? 0 );
                $ex = (float) ( $r['x'] ?? 0 );
                $position = strtolower( (string) ( $element['styles']['position'] ?? '' ) );
                if ( $ew > $viewport * 1.35 && ! in_array( $position, array( 'fixed' ), true ) ) {
                    $gross[] = array( 'figma_class' => $class, 'elementor_id' => sanitize_key( (string) ( $element['elementorId'] ?? $element['elementor_id'] ?? '' ) ), 'x' => round( $ex, 2 ), 'width' => round( $ew, 2 ) );
                    if ( count( $gross ) >= 12 ) { break; }
                }
            }
            $evidence[ $viewport ] = array( 'root_x' => round( $x, 2 ), 'root_width' => round( $width, 2 ), 'root_right' => round( $right, 2 ), 'gross_overflow_nodes' => $gross );
            if ( $root_overflow || $gross ) {
                $issues[] = array(
                    'viewport' => $viewport, 'category' => 'responsive', 'severity' => 'high',
                    'message' => $root_overflow ? 'Compiled Figma root horizontally overflows the responsive viewport.' : 'One or more compiled Figma nodes remain grossly wider than the responsive viewport.',
                    'figma_id' => $figma_id, 'elementor_id' => sanitize_key( (string) ( $rendered_root['elementorId'] ?? $rendered_root['elementor_id'] ?? '' ) ),
                    'details' => $evidence[ $viewport ],
                );
                continue;
            }
            $verified++;
        }

        $ratio = $expected ? $verified / $expected : 0;
        return array(
            'version' => self::VERSION,
            'status' => $expected > 0 && $ratio >= 1 && ! $issues ? 'pass' : ( $expected ? 'fail' : 'unavailable' ),
            'mode' => 'inferred-runtime',
            'reference_fidelity' => 'not-claimed-without-independent-responsive-figma-frames',
            'expected' => $expected,
            'verified' => $verified,
            'match_ratio' => round( $ratio, 4 ),
            'viewports' => array_keys( $evidence ),
            'evidence' => $evidence,
            'issues' => $issues,
        );
    }

    private function root_node( array $ir ) {
        $root_id = (string) ( $ir['root_ids'][0] ?? '' );
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) { if ( is_array( $node ) && (string) ( $node['id'] ?? '' ) === $root_id ) { return $node; } }
        return array();
    }

    private function viewport_elements( array $analysis, $viewport ) {
        $views = is_array( $analysis['data']['viewports'] ?? null ) ? $analysis['data']['viewports'] : (array) ( $analysis['viewports'] ?? array() );
        if ( isset( $views[ $viewport ] ) && is_array( $views[ $viewport ] ) ) { return $views[ $viewport ]; }
        foreach ( $views as $width => $elements ) { if ( (int) $width === (int) $viewport && is_array( $elements ) ) { return $elements; } }
        return array();
    }

    private function find_direct_figma_node( array $elements, $class ) {
        $fallback = null;
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) || (string) ( $element['figmaClass'] ?? $element['figma_class'] ?? '' ) !== $class ) { continue; }
            if ( in_array( $class, (array) ( $element['classes'] ?? array() ), true ) ) { return $element; }
            if ( null === $fallback ) { $fallback = $element; }
        }
        return $fallback;
    }

    private function figma_class( $id ) { return 'dc-figma-node-' . sanitize_html_class( trim( strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $id ) ), '-' ) ); }
}

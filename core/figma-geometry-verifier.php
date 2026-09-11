<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact Figma-node -> rendered Elementor geometry comparison.
 * Candidate ownership is matched by the dc-figma-node-* class emitted by the
 * compiler, never by text or DOM index. Coordinates are normalized to the
 * selected root so Figma canvas offsets cannot skew the result.
 */
class Design_Core_Elementor_Figma_Geometry_Verifier {
    const VERSION = 1;

    public function compare( array $ir, array $candidate_analysis, $viewport ) {
        $viewport = (int) $viewport;
        $elements = $this->viewport_elements( $candidate_analysis, $viewport );
        if ( ! $elements ) { return array(); }
        $candidate = $this->candidate_index( $elements );
        $root = $this->root_node( $ir );
        $root_figma_id = (string) ( $root['figma']['id'] ?? '' );
        $root_class = $this->figma_class( $root_figma_id );
        $candidate_root = $candidate[ $root_class ] ?? null;
        $root_ref = (array) ( $root['figma']['geometry'] ?? array() );
        $ref_x = (float) ( $root_ref['x'] ?? 0 ); $ref_y = (float) ( $root_ref['y'] ?? 0 );
        $cand_x = is_array( $candidate_root ) ? (float) ( $candidate_root['rect']['x'] ?? 0 ) : 0;
        $cand_y = is_array( $candidate_root ) ? (float) ( $candidate_root['rect']['y'] ?? 0 ) : 0;
        $diffs = array();

        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $figma_id = (string) ( $node['figma']['id'] ?? '' );
            if ( ! $figma_id ) { continue; }
            $class = $this->figma_class( $figma_id );
            $right = $candidate[ $class ] ?? null;
            if ( ! is_array( $right ) ) { continue; }
            $geo = (array) ( $node['figma']['geometry'] ?? array() );
            if ( ! isset( $geo['width'], $geo['height'] ) ) { continue; }
            $reference_rect = array(
                'x' => (float) ( $geo['x'] ?? 0 ) - $ref_x,
                'y' => (float) ( $geo['y'] ?? 0 ) - $ref_y,
                'width' => (float) $geo['width'],
                'height' => (float) $geo['height'],
            );
            $candidate_rect = array(
                'x' => (float) ( $right['rect']['x'] ?? 0 ) - $cand_x,
                'y' => (float) ( $right['rect']['y'] ?? 0 ) - $cand_y,
                'width' => (float) ( $right['rect']['width'] ?? 0 ),
                'height' => (float) ( $right['rect']['height'] ?? 0 ),
            );
            $rect_diff = array();
            foreach ( array( 'x', 'y', 'width', 'height' ) as $field ) {
                $a = $reference_rect[ $field ]; $b = $candidate_rect[ $field ];
                if ( abs( $a - $b ) >= 1.0 ) { $rect_diff[ $field ] = array( 'reference' => round( $a, 2 ), 'candidate' => round( $b, 2 ), 'delta' => round( $b - $a, 2 ) ); }
            }
            $style_diff = $this->style_diff( $node, (array) ( $right['styles'] ?? array() ) );
            if ( ! $rect_diff && ! $style_diff ) { continue; }
            $path = '/figma-node/' . str_replace( ':', '-', $figma_id );
            $diffs[ $viewport ][ $path ] = array(
                'matched_path' => (string) ( $right['domPath'] ?? $right['dom_path'] ?? '' ),
                'match_method' => 'figma-node-class',
                'rect' => $rect_diff,
                'styles' => $style_diff,
                'reference_meta' => array( 'figma_id' => $figma_id, 'figma_class' => $class, 'tag' => (string) ( $node['source']['tag'] ?? '' ) ),
                'candidate_meta' => array(
                    'figma_id' => $figma_id,
                    'figma_class' => $class,
                    'tag' => (string) ( $right['tag'] ?? '' ),
                    'id' => (string) ( $right['id'] ?? '' ),
                    'elementor_id' => sanitize_key( (string) ( $right['elementorId'] ?? $right['elementor_id'] ?? '' ) ),
                    'elementor_type' => sanitize_key( (string) ( $right['elementorType'] ?? $right['elementor_type'] ?? '' ) ),
                    'widget_type' => sanitize_key( (string) ( $right['widgetType'] ?? $right['widget_type'] ?? '' ) ),
                ),
            );
        }
        return $diffs;
    }

    private function candidate_index( array $elements ) {
        $out = array();
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) { continue; }
            $class = (string) ( $element['figmaClass'] ?? $element['figma_class'] ?? '' );
            if ( ! $class ) { continue; }
            $direct = in_array( $class, (array) ( $element['classes'] ?? array() ), true );
            if ( ! isset( $out[ $class ] ) || $direct ) { $out[ $class ] = $element; }
        }
        return $out;
    }

    private function viewport_elements( array $analysis, $viewport ) {
        $views = is_array( $analysis['data']['viewports'] ?? null ) ? $analysis['data']['viewports'] : (array) ( $analysis['viewports'] ?? array() );
        if ( isset( $views[ $viewport ] ) && is_array( $views[ $viewport ] ) ) { return $views[ $viewport ]; }
        foreach ( $views as $width => $elements ) { if ( (int) $width === (int) $viewport && is_array( $elements ) ) { return $elements; } }
        return array();
    }

    private function root_node( array $ir ) {
        $root_id = (string) ( $ir['root_ids'][0] ?? '' );
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) { if ( (string) ( $node['id'] ?? '' ) === $root_id ) { return $node; } }
        return array();
    }

    private function style_diff( array $node, array $candidate ) {
        $expected = array();
        $style = (array) ( $node['style'] ?? array() );
        if ( isset( $style['font_size']['value'] ) ) { $expected['fontSize'] = $this->dimension( $style['font_size'] ); }
        if ( isset( $style['line_height']['value'] ) ) { $expected['lineHeight'] = $this->dimension( $style['line_height'] ); }
        if ( isset( $style['letter_spacing']['value'] ) ) { $expected['letterSpacing'] = $this->dimension( $style['letter_spacing'] ); }
        if ( isset( $style['font_weight'] ) ) { $expected['fontWeight'] = (string) $style['font_weight']; }
        if ( isset( $style['align'] ) ) { $expected['textAlign'] = strtolower( (string) $style['align'] ); }
        if ( isset( $style['background_size'] ) ) { $expected['backgroundSize'] = (string) $style['background_size']; }
        if ( isset( $style['background_position'] ) ) { $expected['backgroundPosition'] = (string) $style['background_position']; }
        $diff = array();
        foreach ( $expected as $field => $reference ) {
            $actual = trim( (string) ( $candidate[ $field ] ?? '' ) );
            if ( '' !== $actual && strtolower( $actual ) !== strtolower( trim( (string) $reference ) ) ) { $diff[ $field ] = array( 'reference' => (string) $reference, 'candidate' => $actual ); }
        }
        return $diff;
    }

    private function dimension( array $value ) { return (string) ( $value['value'] ?? 0 ) . (string) ( $value['unit'] ?? 'px' ); }
    private function figma_class( $id ) { return 'dc-figma-node-' . sanitize_html_class( trim( strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $id ) ), '-' ) ); }
}

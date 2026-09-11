<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Exact Figma-node -> rendered Elementor geometry comparison. */
class Design_Core_Elementor_Figma_Geometry_Verifier {
    const VERSION = 2;

    public function compare( array $ir, array $candidate_analysis, $viewport ) {
        $report = $this->report( $ir, $candidate_analysis, $viewport );
        return (array) ( $report['differences'] ?? array() );
    }

    public function report( array $ir, array $candidate_analysis, $viewport ) {
        $viewport = (int) $viewport;
        $elements = $this->viewport_elements( $candidate_analysis, $viewport );
        if ( ! $elements ) { return array( 'version' => self::VERSION, 'status' => 'unavailable', 'viewport' => $viewport, 'expected' => 0, 'matched' => 0, 'missing' => array(), 'match_ratio' => 0, 'differences' => array() ); }
        $candidate = $this->candidate_index( $elements );
        $root = $this->root_node( $ir );
        $root_figma_id = (string) ( $root['figma']['id'] ?? '' );
        $root_class = $this->figma_class( $root_figma_id );
        $candidate_root = $candidate[ $root_class ] ?? null;
        if ( ! is_array( $candidate_root ) ) {
            return array( 'version' => self::VERSION, 'status' => 'fail', 'viewport' => $viewport, 'expected' => 1, 'matched' => 0, 'missing' => array( $root_figma_id ), 'match_ratio' => 0, 'differences' => array() );
        }

        $root_ref = (array) ( $root['figma']['geometry'] ?? array() );
        $ref_x = (float) ( $root_ref['x'] ?? 0 ); $ref_y = (float) ( $root_ref['y'] ?? 0 );
        $cand_x = (float) ( $candidate_root['rect']['x'] ?? 0 ); $cand_y = (float) ( $candidate_root['rect']['y'] ?? 0 );
        $diffs = array(); $missing = array(); $expected = 0; $matched = 0;

        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $figma_id = (string) ( $node['figma']['id'] ?? '' );
            $geo = (array) ( $node['figma']['geometry'] ?? array() );
            if ( ! $figma_id || ! isset( $geo['width'], $geo['height'] ) ) { continue; }
            $expected++;
            $class = $this->figma_class( $figma_id );
            $right = $candidate[ $class ] ?? null;
            $reference_rect = array(
                'x' => (float) ( $geo['x'] ?? 0 ) - $ref_x,
                'y' => (float) ( $geo['y'] ?? 0 ) - $ref_y,
                'width' => (float) $geo['width'],
                'height' => (float) $geo['height'],
            );
            $path = '/figma-node/' . str_replace( ':', '-', $figma_id );
            if ( ! is_array( $right ) ) {
                $missing[] = $figma_id;
                $rect = array();
                foreach ( $reference_rect as $field => $reference_value ) { $rect[ $field ] = array( 'reference' => round( $reference_value, 2 ), 'candidate' => 0, 'delta' => round( -$reference_value, 2 ) ); }
                $diffs[ $viewport ][ $path ] = array(
                    'matched_path' => '', 'match_method' => 'figma-node-class-missing', 'rect' => $rect, 'styles' => array(),
                    'reference_meta' => array( 'figma_id' => $figma_id, 'figma_class' => $class, 'tag' => (string) ( $node['source']['tag'] ?? '' ) ),
                    'candidate_meta' => array( 'figma_id' => $figma_id, 'figma_class' => $class, 'tag' => '', 'id' => '', 'elementor_id' => '', 'elementor_type' => '', 'widget_type' => '' ),
                );
                continue;
            }
            $matched++;
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
            $diffs[ $viewport ][ $path ] = array(
                'matched_path' => (string) ( $right['domPath'] ?? $right['dom_path'] ?? '' ), 'match_method' => 'figma-node-class', 'rect' => $rect_diff, 'styles' => $style_diff,
                'reference_meta' => array( 'figma_id' => $figma_id, 'figma_class' => $class, 'tag' => (string) ( $node['source']['tag'] ?? '' ) ),
                'candidate_meta' => array(
                    'figma_id' => $figma_id, 'figma_class' => $class, 'tag' => (string) ( $right['tag'] ?? '' ), 'id' => (string) ( $right['id'] ?? '' ),
                    'elementor_id' => sanitize_key( (string) ( $right['elementorId'] ?? $right['elementor_id'] ?? '' ) ),
                    'elementor_type' => sanitize_key( (string) ( $right['elementorType'] ?? $right['elementor_type'] ?? '' ) ),
                    'widget_type' => sanitize_key( (string) ( $right['widgetType'] ?? $right['widget_type'] ?? '' ) ),
                ),
            );
        }
        $ratio = $expected > 0 ? $matched / $expected : 0;
        $status = $expected > 0 && $ratio >= 0.98 && ! $missing ? 'pass' : 'fail';
        return array(
            'version' => self::VERSION, 'status' => $status, 'viewport' => $viewport, 'expected' => $expected, 'matched' => $matched,
            'missing' => $missing, 'match_ratio' => round( $ratio, 4 ), 'difference_count' => count( (array) ( $diffs[ $viewport ] ?? array() ) ), 'differences' => $diffs,
        );
    }

    private function candidate_index( array $elements ) {
        $out = array();
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) { continue; }
            $class = (string) ( $element['figmaClass'] ?? $element['figma_class'] ?? '' ); if ( ! $class ) { continue; }
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
        $expected = array(); $style = (array) ( $node['style'] ?? array() );
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

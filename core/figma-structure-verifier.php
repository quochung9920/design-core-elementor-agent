<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Verifies that rendered Elementor ownership preserves the Figma/IR parent tree.
 * Geometry equality alone is insufficient: overlays and icon wrappers can land
 * under the wrong parent while keeping similar rectangles.
 */
class Design_Core_Elementor_Figma_Structure_Verifier {
    const VERSION = 1;

    public function report( array $ir, array $candidate_analysis, $viewport ) {
        $viewport = (int) $viewport;
        $elements = $this->viewport_elements( $candidate_analysis, $viewport );
        if ( ! $elements ) {
            return array( 'version' => self::VERSION, 'status' => 'unavailable', 'viewport' => $viewport, 'expected' => 0, 'matched' => 0, 'parent_matches' => 0, 'match_ratio' => 0, 'parent_match_ratio' => 0, 'issues' => array() );
        }

        $candidate = $this->candidate_index( $elements );
        $nodes = array();
        $figma_by_ir = array();
        $parent_by_ir = array();
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $ir_id = (string) ( $node['id'] ?? '' );
            $figma_id = (string) ( $node['figma']['id'] ?? '' );
            if ( ! $ir_id || ! $figma_id ) { continue; }
            $nodes[ $ir_id ] = $node;
            $figma_by_ir[ $ir_id ] = $figma_id;
            foreach ( (array) ( $node['children'] ?? array() ) as $child_id ) {
                $child_id = (string) $child_id;
                if ( $child_id ) { $parent_by_ir[ $child_id ] = $ir_id; }
            }
        }

        $expected = 0; $matched = 0; $parent_expected = 0; $parent_matches = 0; $issues = array();
        foreach ( $nodes as $ir_id => $node ) {
            $figma_id = $figma_by_ir[ $ir_id ];
            $class = $this->figma_class( $figma_id );
            $expected++;
            if ( empty( $candidate[ $class ] ) ) {
                $issues[] = array(
                    'viewport' => $viewport, 'category' => 'structure', 'severity' => 'high',
                    'message' => 'Rendered Figma node is missing from the candidate DOM.',
                    'figma_id' => $figma_id, 'figma_class' => $class, 'elementor_id' => '',
                    'expected_parent' => isset( $parent_by_ir[ $ir_id ] ) ? $this->figma_class( (string) ( $figma_by_ir[ $parent_by_ir[ $ir_id ] ] ?? '' ) ) : '',
                    'candidate_parent' => '',
                );
                continue;
            }

            $matched++;
            $rendered = $candidate[ $class ];
            if ( ! isset( $parent_by_ir[ $ir_id ] ) ) { continue; }
            $parent_ir = (string) $parent_by_ir[ $ir_id ];
            $parent_figma = (string) ( $figma_by_ir[ $parent_ir ] ?? '' );
            if ( ! $parent_figma ) { continue; }
            $parent_expected++;
            $expected_parent = $this->figma_class( $parent_figma );
            $actual_parent = (string) ( $rendered['figmaParentClass'] ?? $rendered['figma_parent_class'] ?? '' );
            if ( $actual_parent === $expected_parent ) { $parent_matches++; continue; }

            $issues[] = array(
                'viewport' => $viewport, 'category' => 'structure', 'severity' => 'high',
                'message' => 'Rendered Figma node is owned by the wrong composition parent.',
                'figma_id' => $figma_id, 'figma_class' => $class,
                'elementor_id' => sanitize_key( (string) ( $rendered['elementorId'] ?? $rendered['elementor_id'] ?? '' ) ),
                'expected_parent' => $expected_parent,
                'candidate_parent' => $actual_parent,
                'parent_elementor_id' => sanitize_key( (string) ( $rendered['parentElementorId'] ?? $rendered['parent_elementor_id'] ?? '' ) ),
            );
        }

        $match_ratio = $expected ? $matched / $expected : 0;
        $parent_ratio = $parent_expected ? $parent_matches / $parent_expected : 1;
        $status = $expected > 0 && $match_ratio >= 0.98 && $parent_ratio >= 0.98 && ! $issues ? 'pass' : 'fail';
        return array(
            'version' => self::VERSION,
            'status' => $status,
            'viewport' => $viewport,
            'expected' => $expected,
            'matched' => $matched,
            'parent_expected' => $parent_expected,
            'parent_matches' => $parent_matches,
            'match_ratio' => round( $match_ratio, 4 ),
            'parent_match_ratio' => round( $parent_ratio, 4 ),
            'issue_count' => count( $issues ),
            'issues' => $issues,
        );
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
        foreach ( $views as $width => $elements ) { if ( (int) $width === $viewport && is_array( $elements ) ) { return $elements; } }
        return array();
    }

    private function figma_class( $id ) {
        return 'dc-figma-node-' . sanitize_html_class( trim( strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $id ) ), '-' ) );
    }
}

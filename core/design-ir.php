<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Builds the canonical, platform-neutral Design IR contract. */
class Design_Core_Elementor_Design_IR {
    const SCHEMA_VERSION = 4;

    public function build_from_analysis( $analysis ) {
        $nodes = array_values( array_filter( $analysis['nodes'] ?? array(), 'is_array' ) );
        $root_ids = array();
        $child_ids = array();
        foreach ( $nodes as $node ) {
            foreach ( $node['children'] ?? array() as $child_id ) { $child_ids[ $child_id ] = true; }
        }
        foreach ( $nodes as $node ) {
            if ( ! isset( $child_ids[ $node['id'] ?? '' ] ) ) { $root_ids[] = $node['id']; }
        }
        $browser = is_array( $analysis['browser_analysis'] ?? null ) ? $analysis['browser_analysis'] : array();
        $ir = array(
            'schema_version' => self::SCHEMA_VERSION,
            'type' => 'design-ir',
            'source_name' => sanitize_text_field( $analysis['source_name'] ?? 'source' ),
            'nodes' => $nodes,
            'root_ids' => array_values( array_filter( $root_ids ) ),
            'analysis_quality' => array(
                'browser_runtime' => 'success' === ( $browser['status'] ?? '' ) ? 'pass' : ( $browser['status'] ?? 'unavailable' ),
                'computed_styles' => 'success' === ( $browser['status'] ?? '' ) ? 'pass' : 'partial',
                'geometry' => 'success' === ( $browser['status'] ?? '' ) ? 'pass' : 'partial',
                'css_static' => 'pass',
                'interaction' => 'partial',
            ),
            'tokens' => is_array( $analysis['tokens'] ?? null ) ? $analysis['tokens'] : array(),
            'breakpoints' => is_array( $analysis['breakpoints'] ?? null ) ? $analysis['breakpoints'] : array(),
            'diagnostics' => array_merge( $analysis['diagnostics'] ?? array(), array( 'source_html_available' => ! empty( $analysis['source_html'] ) ) ),
        );
        if ( class_exists( 'Design_Core_Elementor_Breakpoint_Fidelity' ) ) {
            $ir = ( new Design_Core_Elementor_Breakpoint_Fidelity() )->attach_cached_fallbacks( $ir );
            $ir['diagnostics']['breakpoint_fidelity'] = $ir['breakpoint_fidelity']['evidence_hash'] ?? '';
        }
        if ( class_exists( 'Design_Core_Elementor_Computed_Style_Hydrator' ) && 'success' === ( $browser['status'] ?? '' ) ) {
            $ir = ( new Design_Core_Elementor_Computed_Style_Hydrator() )->hydrate( $ir, $browser );
            $ir['diagnostics']['computed_style_hydration'] = $ir['computed_style_hydration']['evidence_hash'] ?? '';
        }
        if ( class_exists( 'Design_Core_Elementor_Interaction_Intelligence' ) ) {
            $ir = ( new Design_Core_Elementor_Interaction_Intelligence() )->enrich( $ir );
        }
        if ( class_exists( 'Design_Core_Elementor_Source_Fidelity_Engine' ) ) {
            $ir = ( new Design_Core_Elementor_Source_Fidelity_Engine() )->enrich( $ir, $browser );
            $ir['diagnostics']['source_fidelity_contract'] = $ir['source_fidelity']['contract_hash'] ?? '';
            $ir['diagnostics']['component_graph'] = $ir['component_graph']['graph_hash'] ?? '';
        }
        return $ir;
    }

    /** Backward-compatible validation facade. */
    public function validate( $ir ) {
        try {
            ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
            return array( 'valid' => true, 'errors' => array() );
        } catch ( InvalidArgumentException $exception ) {
            return array( 'valid' => false, 'errors' => array( $exception->getMessage() ) );
        }
    }
}

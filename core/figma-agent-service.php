<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical Figma entrypoint for local AI agents. Unlike the legacy IR-only
 * path, this always uses the strict fidelity service so vectors and reference
 * renders are present before compilation.
 */
class Design_Core_Elementor_Figma_Agent_Service {
    const VERSION = 1;

    public function prepare( $figma_url, array $options = array() ) {
        $options['resolve_image_fills'] = true;
        $options['resolve_vector_assets'] = true;
        $options['export_reference'] = true;
        $prepared = ( new Design_Core_Elementor_Figma_Fidelity_Service() )->prepare( $figma_url, $options );
        if ( is_wp_error( $prepared ) ) { return $prepared; }
        $prepared['memory_preflight'] = $this->memory_preflight( (array) ( $prepared['design_ir'] ?? array() ) );
        $prepared['agent_policy'] = array(
            'figma_is_authoritative' => true,
            'use_strict_fidelity_path' => true,
            'require_rendered_verification_before_completion' => true,
            'learn_only_from_verified_corrections' => true,
        );
        return $prepared;
    }

    public function compile( $figma_url, array $options = array() ) {
        $options['resolve_image_fills'] = true;
        $options['resolve_vector_assets'] = true;
        $options['export_reference'] = true;
        $compiled = ( new Design_Core_Elementor_Figma_Fidelity_Service() )->compile( $figma_url, $options );
        if ( is_wp_error( $compiled ) ) { return $compiled; }
        $compiled['memory_preflight'] = $this->memory_preflight( (array) ( $compiled['design_ir'] ?? array() ) );
        return $compiled;
    }

    public function build_and_verify( $figma_url, $page_id = 0, array $options = array() ) {
        $options['resolve_image_fills'] = true;
        $options['resolve_vector_assets'] = true;
        $options['export_reference'] = true;
        $options['verify'] = true;
        $result = ( new Design_Core_Elementor_Figma_Fidelity_Service() )->build_draft( $figma_url, (int) $page_id, $options );
        if ( is_wp_error( $result ) ) { return $result; }
        $result['completion_allowed'] = ! empty( $result['publishable'] );
        if ( empty( $result['publishable'] ) ) {
            $result['completion_block_reason'] = 'Rendered Figma fidelity gate did not pass.';
        }
        return $result;
    }

    public function verify( $figma_url, $candidate_target, array $options = array() ) {
        $options['resolve_image_fills'] = true;
        $options['resolve_vector_assets'] = true;
        $options['export_reference'] = true;
        return ( new Design_Core_Elementor_Figma_Fidelity_Service() )->verify( $figma_url, $candidate_target, $options );
    }

    private function memory_preflight( array $ir ) {
        if ( ! class_exists( 'Design_Core_Elementor_Design_Memory_Service' ) ) { return array(); }
        $service = new Design_Core_Elementor_Design_Memory_Service();
        $recommendations = array();
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $context = $this->node_context( $node );
            foreach ( $this->candidate_contexts( $context, $node ) as $candidate ) {
                $memory = $service->recommend( $candidate, 5 );
                if ( empty( $memory['rules'] ) && empty( $memory['lessons'] ) ) { continue; }
                $recommendations[] = array(
                    'figma_node_id' => sanitize_text_field( (string) ( $node['figma']['id'] ?? '' ) ),
                    'ir_node_id' => sanitize_text_field( (string) ( $node['id'] ?? '' ) ),
                    'context' => $candidate,
                    'memory' => $memory,
                );
            }
        }
        return array_slice( $recommendations, 0, 100 );
    }

    private function node_context( array $node ) {
        $geometry = (array) ( $node['figma']['geometry'] ?? array() );
        $images = (array) ( $node['assets']['images'] ?? array() );
        $asset_type = '';
        foreach ( $images as $image ) {
            if ( 'figma-vector-export' === ( $image['source'] ?? '' ) ) { $asset_type = 'svg'; break; }
        }
        return array(
            'source_type' => 'figma',
            'source_node_id' => (string) ( $node['figma']['id'] ?? '' ),
            'node_type' => strtolower( (string) ( $node['figma']['type'] ?? $node['source']['tag'] ?? '' ) ),
            'component_role' => sanitize_key( (string) ( $node['semantic']['role'] ?? '' ) ),
            'asset_type' => $asset_type,
            'reference_width' => (float) ( $geometry['width'] ?? 0 ),
            'reference_height' => (float) ( $geometry['height'] ?? 0 ),
            'figma_sizing' => strtoupper( (string) ( $node['figma']['sizing']['horizontal'] ?? '' ) ),
            'position' => sanitize_key( (string) ( $node['layout']['position'] ?? '' ) ),
            'mixed_typography' => ! empty( $node['figma']['text_composition'] ) || count( (array) ( $node['figma']['text_runs'] ?? array() ) ) > 1,
        );
    }

    private function candidate_contexts( array $base, array $node ) {
        $contexts = array();
        if ( 'svg' === ( $base['asset_type'] ?? '' ) ) { $contexts[] = array_merge( $base, array( 'category' => 'asset-loss' ) ); }
        if ( ! empty( $base['mixed_typography'] ) ) { $contexts[] = array_merge( $base, array( 'category' => 'mixed-text' ) ); }
        if ( 'absolute' === ( $base['position'] ?? '' ) ) { $contexts[] = array_merge( $base, array( 'category' => 'anchor-position' ) ); }
        if ( 'FILL' === ( $base['figma_sizing'] ?? '' ) ) { $contexts[] = array_merge( $base, array( 'category' => 'layout-width' ) ); }
        if ( (float) ( $base['reference_width'] ?? 0 ) > 0 && (float) ( $base['reference_width'] ?? 0 ) <= 32 && (float) ( $base['reference_height'] ?? 0 ) > 0 && (float) ( $base['reference_height'] ?? 0 ) <= 32 ) {
            $contexts[] = array_merge( $base, array( 'category' => 'primitive-size' ) );
        }
        return $contexts;
    }
}

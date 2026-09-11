<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Formal Elementor Pro Loop integration contract, kept out of the generic Loop_Executor so
 * Pro-specific staging (template, query, grid) never leaks into the strategy layer.
 *
 * Design Core does not know, and does not guess, Elementor Pro's private Loop storage
 * format. Each stage below is a governed WordPress filter that a site-specific integration
 * must supply from real, licensed Elementor Pro / public Elementor Loop APIs; with none
 * registered, supports() is honestly false and the strategy layer's own fallback policy
 * (native-compose, with diagnostics) takes over -- this class never fabricates a result.
 */
class Design_Core_Elementor_Pro_Loop_Adapter {
    public function supports() {
        if ( ! class_exists( '\ElementorPro\Modules\LoopBuilder\Module' ) && ! class_exists( '\Elementor\Modules\Loop\Module' ) ) { return false; }
        return has_filter( 'design_core_elementor_pro_loop_create_template' )
            && has_filter( 'design_core_elementor_pro_loop_create_query_binding' )
            && has_filter( 'design_core_elementor_pro_loop_create_loop_grid' );
    }

    public function preflight( $component, $capabilities ) {
        if ( ! $this->supports() ) { return array( 'status' => 'unsupported', 'reason' => 'pro-loop-governed-integration-unavailable' ); }
        return array( 'status' => 'ready' );
    }

    /** @return array{template_id:mixed}|null */
    public function create_loop_template( $component, $capabilities ) {
        $result = apply_filters( 'design_core_elementor_pro_loop_create_template', null, $component, $capabilities );
        return ( is_array( $result ) && ! empty( $result['template_id'] ) ) ? $result : null;
    }

    /** @return array{query_type:string}|null */
    public function create_query_binding( $template, $component, $capabilities ) {
        $result = apply_filters( 'design_core_elementor_pro_loop_create_query_binding', null, $template, $component, $capabilities );
        return ( is_array( $result ) && ! empty( $result['query_type'] ) ) ? $result : null;
    }

    /** @return array{elements:array}|null */
    public function create_loop_grid( $template, $query_binding, $component, $capabilities ) {
        $result = apply_filters( 'design_core_elementor_pro_loop_create_loop_grid', null, $template, $query_binding, $component, $capabilities );
        return ( is_array( $result ) && ! empty( $result['elements'] ) && is_array( $result['elements'] ) ) ? $result : null;
    }

    public function validate( $grid ) {
        return is_array( $grid ) && ! empty( $grid['elements'] ) && is_array( $grid['elements'] );
    }

    public function rollback( $template, $query_binding ) {
        do_action( 'design_core_elementor_pro_loop_rollback', $template, $query_binding );
        return true;
    }

    /** Orchestrates template -> query binding -> grid, rolling back on any stage failure. Returns the same {status, elements, reason} shape Loop_Executor already produces, so it stays a thin, generic delegator. */
    public function execute( $component, $capabilities ) {
        $preflight = $this->preflight( $component, $capabilities );
        if ( 'ready' !== ( $preflight['status'] ?? '' ) ) { return array( 'status' => 'unsupported', 'elements' => array(), 'reason' => $preflight['reason'] ?? 'pro-loop-unavailable' ); }

        $template = $this->create_loop_template( $component, $capabilities );
        if ( ! $template ) { return array( 'status' => 'deferred', 'elements' => array(), 'reason' => 'pro-loop-template-creation-unavailable' ); }

        $query_binding = $this->create_query_binding( $template, $component, $capabilities );
        if ( ! $query_binding ) { $this->rollback( $template, null ); return array( 'status' => 'deferred', 'elements' => array(), 'reason' => 'pro-loop-query-binding-unavailable' ); }

        $grid = $this->create_loop_grid( $template, $query_binding, $component, $capabilities );
        if ( ! $this->validate( $grid ) ) { $this->rollback( $template, $query_binding ); return array( 'status' => 'deferred', 'elements' => array(), 'reason' => 'pro-loop-grid-creation-unavailable' ); }

        return array( 'status' => 'success', 'elements' => $grid['elements'], 'loop_template_id' => $template['template_id'] ?? null, 'query_type' => $query_binding['query_type'] ?? null );
    }
}

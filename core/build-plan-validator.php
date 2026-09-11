<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Build_Plan_Validator {
    const STRATEGIES = array( 'native-widget', 'native-compose', 'reuse-component', 'reuse-widget', 'component', 'variant', 'loop', 'custom-widget', 'css-fallback', 'raw-html-fallback' );
    public function validate( $plan, $ir = null ) {
        if ( ! is_array( $plan ) || Design_Core_Elementor_Build_Plan::SCHEMA_VERSION !== ( $plan['build_plan_schema_version'] ?? null ) || ! isset( $plan['items'] ) || ! is_array( $plan['items'] ) ) { throw new InvalidArgumentException( 'BuildPlan contract is invalid.' ); }
        $node_ids = array(); $ir_ids = null; $ir_nodes = array();
        if ( null !== $ir ) {
            if ( class_exists( 'Design_Core_Elementor_Source_Asset_Contract' ) ) {
                $asset_contract = new Design_Core_Elementor_Source_Asset_Contract();
                $asset_audit = $asset_contract->audit( (array) $ir );
                if ( 'blocked' === ( $asset_audit['status'] ?? '' ) ) { throw new InvalidArgumentException( $asset_contract->blocking_message( $asset_audit ) ); }
            }
            $ir_ids = array();
            foreach ( $ir['nodes'] ?? array() as $node ) {
                if ( is_array( $node ) && isset( $node['id'] ) ) { $ir_ids[ $node['id'] ] = true; $ir_nodes[ $node['id'] ] = $node; }
            }
        }
        foreach ( $plan['items'] as $item ) {
            $this->validate_item( $item );
            if ( isset( $node_ids[ $item['node_id'] ] ) ) { throw new InvalidArgumentException( 'BuildPlan node IDs must be unique.' ); }
            $node_ids[ $item['node_id'] ] = true;
            if ( is_array( $ir_ids ) && ! isset( $ir_ids[ $item['node_id'] ] ) ) { throw new InvalidArgumentException( 'BuildPlan node does not exist in DesignIR.' ); }
            if ( isset( $ir_nodes[ $item['node_id'] ] ) ) { $this->validate_fidelity_strategy( $item, $ir_nodes[ $item['node_id'] ] ); }
        }
        return true;
    }
    private function validate_item( $item ) {
        if ( ! is_array( $item ) || ! is_string( $item['node_id'] ?? null ) || '' === trim( $item['node_id'] ) ) { throw new InvalidArgumentException( 'BuildPlanItem node_id is required.' ); }
        if ( ! in_array( $item['strategy'] ?? null, self::STRATEGIES, true ) ) { throw new InvalidArgumentException( 'BuildPlanItem strategy is unsupported.' ); }
        if ( ! in_array( $item['adapter_target'] ?? null, array( 'elementor-v3', 'elementor-v4' ), true ) ) { throw new InvalidArgumentException( 'BuildPlanItem adapter_target is unsupported.' ); }
        foreach ( array( 'reuse', 'variant', 'content_bindings', 'style_bindings', 'responsive_bindings', 'asset_bindings', 'fallback', 'diagnostics' ) as $field ) { if ( ! isset( $item[ $field ] ) || ! is_array( $item[ $field ] ) ) { throw new InvalidArgumentException( 'BuildPlanItem ' . $field . ' must be an array.' ); } }
        if ( ! is_bool( $item['fallback']['allowed'] ?? null ) ) { throw new InvalidArgumentException( 'BuildPlanItem fallback.allowed must be boolean.' ); }
        if ( ! empty( $item['fallback']['strategy'] ) && ! in_array( $item['fallback']['strategy'], self::STRATEGIES, true ) ) { throw new InvalidArgumentException( 'BuildPlanItem fallback strategy is unsupported.' ); }
        return true;
    }
    private function validate_fidelity_strategy( array $item, array $node ) {
        if ( 'preserve-children' !== ( $node['semantic']['composition_policy'] ?? '' ) ) { return; }
        $strategy = sanitize_key( (string) ( $item['strategy'] ?? '' ) );
        if ( in_array( $strategy, array( 'native-widget', 'reuse-widget', 'custom-widget' ), true ) ) {
            throw new InvalidArgumentException( 'BuildPlan source-fidelity contract forbids collapsing preserve-children node ' . (string) $item['node_id'] . ' into ' . $strategy . '.' );
        }
    }
}

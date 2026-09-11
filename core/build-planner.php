<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Canonical Build Planner: one executable item per Design IR root. */
class Design_Core_Elementor_Build_Planner {
    public function plan( $ir, $adapter_target = 'elementor-v3' ) {
        ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
        $by_id = array(); foreach ( $ir['nodes'] as $node ) { $by_id[ $node['id'] ] = $node; }
        $capabilities = ( new Design_Core_Elementor_Capability_Scanner() )->scan();
        $decision_engine = new Design_Core_Elementor_Decision_Engine();
        $reuse_engine = new Design_Core_Elementor_Reuse_Engine();
        $variant_engine = new Design_Core_Elementor_Variant_Engine();
        $component_registry = new Design_Core_Elementor_Component_Registry();
        $widget_registry = new Design_Core_Elementor_Widget_Registry();
        $items = array();

        foreach ( $ir['root_ids'] as $root_id ) {
            $root = $by_id[ $root_id ];
            $aggregate = $this->aggregate_direct_children( $root_id, $by_id );
            $section_reuse = is_array( $root['section']['reuse'] ?? null ) ? $root['section']['reuse'] : array();
            $section_owned = ! empty( $root['section']['reusable'] );
            $owned_master = $this->owned_master( $root_id, $by_id );

            if ( 'reuse' === ( $section_reuse['action'] ?? '' ) && ! empty( $section_reuse['master_id'] ) ) {
                $registry_match = array( 'registry_type' => 'section', 'registry_id' => $section_reuse['master_id'], 'score' => (float) ( $section_reuse['score'] ?? 1.0 ), 'reasons' => array( $section_reuse['reason'] ?? 'section-registry-match' ) );
                $variant_match = array( 'action' => 'reuse', 'score' => (float) ( $section_reuse['score'] ?? 1.0 ) );
            } elseif ( 'variant' === ( $section_reuse['action'] ?? '' ) && ! empty( $section_reuse['master_id'] ) ) {
                $registry_match = array( 'registry_type' => 'section', 'registry_id' => $section_reuse['master_id'], 'score' => (float) ( $section_reuse['score'] ?? 0.85 ), 'reasons' => array( $section_reuse['reason'] ?? 'section-family-variant' ) );
                $variant_match = array( 'action' => 'variant', 'score' => (float) ( $section_reuse['score'] ?? 0.85 ) );
            } elseif ( $section_owned ) {
                // A root classified by Section Intelligence owns its own lifecycle. NEW sections must not
                // accidentally match a legacy component/widget master with a similar shallow structure.
                $registry_match = array( 'registry_type' => 'section', 'registry_id' => '', 'score' => 0.0, 'reasons' => array( 'section-registry-new' ) );
                $variant_match = array( 'action' => 'new', 'score' => 0.0, 'reason' => 'section-registry-new' );
            } elseif ( $owned_master ) {
                $registry_match = array( 'registry_type' => 'component', 'registry_id' => $owned_master, 'score' => 1.0, 'reasons' => array( 'owned-master-match' ) );
                $variant_match = array( 'action' => 'reuse', 'score' => 1.0 );
            } else {
                $candidate = array(
                    'type' => sanitize_key( $root['semantic']['role'] ?? '' ),
                    'content_schema' => $aggregate['content_schema'],
                    'signature' => $root['component']['fingerprint']['structure'] ?? '',
                    'fingerprint' => $root['component']['fingerprint'] ?? array(),
                    'layout' => $aggregate['layout'] ?: ( $root['layout'] ?? array() ),
                    'style' => $aggregate['style'] ?: ( $root['style'] ?? array() ),
                    'spacing' => $aggregate['spacing'] ?: ( $root['spacing'] ?? array() ),
                    'responsive' => $root['responsive'] ?? array(),
                );
                $component_best = $reuse_engine->find_best_match( $candidate, $component_registry->all() );
                $widget_best = $reuse_engine->find_best_match( $candidate, $widget_registry->all() );
                if ( (float) $widget_best['score'] > (float) $component_best['score'] ) { $best = $widget_best; $registry_type = 'widget'; }
                else { $best = $component_best; $registry_type = 'component'; }
                $variant_match = $variant_engine->classify( $candidate, $best );
                $registry_match = array( 'registry_type' => $registry_type, 'registry_id' => $best['item']['id'] ?? ( $best['item']['slug'] ?? '' ), 'score' => (float) $best['score'], 'reasons' => $best['reasons'] );
            }

            $decision = $decision_engine->decide_node( array(
                'semantic' => $root['semantic'],
                'component' => array_merge( $root['component'], array( 'repeated' => $root['component']['repeated'] || $aggregate['repeated'], 'dynamic' => $root['component']['dynamic'] || $aggregate['dynamic'] ) ),
                'interaction' => $root['interaction'], 'content' => $root['content'], 'assets' => $root['assets'],
                'registry_match' => $registry_match, 'variant_match' => $variant_match, 'capabilities' => $capabilities,
            ) );

            $strategy = $this->map_strategy( $decision['strategy'], $registry_match['registry_type'] );
            $overrides = array( 'diagnostics' => array( 'decision_reason' => $decision['reason'], 'decision_confidence' => $decision['confidence'], 'registry_type' => $registry_match['registry_type'] ) );
            if ( in_array( $strategy, array( 'reuse-component', 'reuse-widget' ), true ) ) {
                $overrides['reuse'] = $registry_match;
                if ( 'reuse-component' === $strategy && $registry_match['registry_id'] ) { $overrides['component_id'] = $registry_match['registry_id']; }
            } elseif ( 'variant' === $strategy ) {
                $overrides['variant'] = array( 'action' => 'variant', 'id' => $registry_match['registry_id'] ); $overrides['reuse'] = $registry_match;
            } elseif ( 'loop' === $strategy ) {
                $overrides['fallback'] = array( 'allowed' => true, 'strategy' => 'native-compose', 'reason' => 'loop-runtime-integration-unavailable' );
            } elseif ( 'native-widget' === $strategy ) {
                $overrides['fallback'] = array( 'allowed' => true, 'strategy' => 'native-compose', 'reason' => 'native-widget-unavailable' );
            }
            $items[] = Design_Core_Elementor_Build_Plan_Item::create( $root_id, $strategy, $adapter_target, $overrides );
        }
        $plan = Design_Core_Elementor_Build_Plan::create( $items );
        ( new Design_Core_Elementor_Build_Plan_Validator() )->validate( $plan, $ir );
        return $plan;
    }

    private function map_strategy( $decision_strategy, $registry_type ) {
        if ( 'reuse' === $decision_strategy ) { return 'widget' === $registry_type ? 'reuse-widget' : 'reuse-component'; }
        if ( in_array( $decision_strategy, array( 'native-widget', 'component', 'variant', 'loop', 'custom-widget', 'native-compose' ), true ) ) { return $decision_strategy; }
        throw new RuntimeException( 'Unknown Decision Engine strategy: ' . sanitize_key( (string) $decision_strategy ) );
    }

    /**
     * Master ownership never bubbles from an arbitrary descendant. A root may reuse its own master,
     * or a single direct child master when the root is only a thin structural wrapper. This preserves
     * Page A -> Page B wrapper reuse without allowing one card inside a mixed section to commandeer the root.
     */
    private function owned_master( $root_id, $nodes ) {
        if ( ! isset( $nodes[ $root_id ] ) ) { return ''; }
        $root = $nodes[ $root_id ];
        if ( ! empty( $root['section']['master_id'] ) ) { return (string) $root['section']['master_id']; }
        if ( ! empty( $root['component']['master_id'] ) ) { return (string) $root['component']['master_id']; }
        $children = (array) ( $root['children'] ?? array() );
        if ( 1 !== count( $children ) || ! isset( $nodes[ $children[0] ] ) ) { return ''; }
        $child = $nodes[ $children[0] ];
        return ! empty( $child['component']['master_id'] ) ? (string) $child['component']['master_id'] : '';
    }

    private function aggregate_direct_children( $root_id, $nodes ) {
        $children = $nodes[ $root_id ]['children'] ?? array();
        $total = 0; $repeated_dynamic = 0; $content_schema = array();
        foreach ( $children as $child_id ) {
            if ( ! isset( $nodes[ $child_id ] ) ) { continue; }
            $child = $nodes[ $child_id ]; $total++;
            if ( ! empty( $child['component']['repeated'] ) && ! empty( $child['component']['dynamic'] ) ) { $repeated_dynamic++; }
            $content_schema = array_values( array_unique( array_merge( $content_schema, (array) ( $child['component']['content_schema'] ?? array() ) ) ) );
        }
        $majority = $total >= 2 && $repeated_dynamic >= 2 && ( $repeated_dynamic / $total ) >= 0.5;
        $layout = array(); $style = array(); $spacing = array();
        if ( 1 === $total ) {
            $only_child = $nodes[ reset( $children ) ] ?? array();
            $layout = (array) ( $only_child['layout'] ?? array() ); $style = (array) ( $only_child['style'] ?? array() ); $spacing = (array) ( $only_child['spacing'] ?? array() );
        }
        return array( 'repeated' => $majority, 'dynamic' => $majority, 'content_schema' => $content_schema, 'layout' => $layout, 'style' => $style, 'spacing' => $spacing );
    }
}

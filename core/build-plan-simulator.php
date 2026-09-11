<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mutation-free execution estimator for BuildPlan Preview.
 *
 * This class never calls a strategy executor and never writes a registry/post. It
 * predicts the Elementor surface from the selected strategy, IR subtree and any
 * already-persisted blueprint that can be read safely.
 */
class Design_Core_Elementor_Build_Plan_Simulator {
    const VERSION = 1;

    public function simulate( array $ir, array $plan, array $preview_items = array() ) {
        $nodes = array();
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            if ( is_array( $node ) && ! empty( $node['id'] ) ) { $nodes[ (string) $node['id'] ] = $node; }
        }
        $preview_by_node = array();
        foreach ( $preview_items as $item ) { if ( ! empty( $item['node_id'] ) ) { $preview_by_node[ (string) $item['node_id'] ] = $item; } }

        $summary = array( 'containers' => 0, 'widgets' => 0, 'widget_types' => array(), 'unknown' => 0 );
        $items = array(); $total = 0;
        foreach ( (array) ( $plan['items'] ?? array() ) as $item ) {
            $node_id = (string) ( $item['node_id'] ?? '' );
            $strategy = sanitize_key( (string) ( $item['strategy'] ?? '' ) );
            $preview = (array) ( $preview_by_node[ $node_id ] ?? array() );
            $simulation = $this->simulate_item( $node_id, $strategy, $item, $nodes, $preview );
            $items[] = $simulation;
            $total += (int) ( $simulation['estimated_elements'] ?? 0 );
            foreach ( (array) ( $simulation['element_types'] ?? array() ) as $type => $count ) {
                if ( 'container' === $type ) { $summary['containers'] += (int) $count; }
                elseif ( 0 === strpos( (string) $type, 'widget:' ) ) {
                    $widget = substr( (string) $type, 7 );
                    $summary['widgets'] += (int) $count;
                    $summary['widget_types'][ $widget ] = ( $summary['widget_types'][ $widget ] ?? 0 ) + (int) $count;
                } else { $summary['unknown'] += (int) $count; }
            }
        }
        ksort( $summary['widget_types'] );
        return array(
            'version' => self::VERSION,
            'mutation_performed' => false,
            'estimated_element_count' => $total,
            'summary' => $summary,
            'items' => $items,
        );
    }

    private function simulate_item( $node_id, $strategy, array $plan_item, array $nodes, array $preview ) {
        $subtree = $this->subtree_nodes( $node_id, $nodes );
        $count = count( $subtree );
        $confidence = 'medium'; $reason = 'ir-subtree-estimate'; $types = $this->types_from_ir( $subtree );
        $widget_type = '';
        if ( ! empty( $preview['widget_candidates'][0]['name'] ) ) { $widget_type = sanitize_key( (string) $preview['widget_candidates'][0]['name'] ); }

        if ( in_array( $strategy, array( 'native-widget', 'reuse-widget', 'custom-widget', 'loop' ), true ) ) {
            $count = 1; $confidence = $widget_type || 'custom-widget' === $strategy || 'loop' === $strategy ? 'high' : 'medium';
            if ( ! $widget_type ) { $widget_type = 'loop' === $strategy ? 'loop-grid' : ( 'custom-widget' === $strategy ? 'design-core-runtime' : 'runtime-selected' ); }
            $types = array( 'widget:' . $widget_type => 1 );
            $reason = 'strategy-collapses-subtree-to-widget';
        } elseif ( in_array( $strategy, array( 'reuse-component', 'variant' ), true ) ) {
            $blueprint = $this->read_blueprint( $plan_item );
            if ( is_array( $blueprint ) ) {
                $types = array(); $count = $this->count_elementor_tree( $blueprint, $types );
                $confidence = 'high'; $reason = 'persisted-blueprint';
            } else {
                $confidence = 'medium'; $reason = 'registry-blueprint-unavailable-fallback-to-ir';
            }
        } elseif ( 'native-compose' === $strategy || 'component' === $strategy ) {
            $confidence = 'medium'; $reason = 'native-compose-ir-shape';
        } else {
            $confidence = 'low'; $reason = 'unknown-strategy';
        }

        return array(
            'node_id' => $node_id,
            'strategy' => $strategy,
            'estimated_elements' => max( 0, (int) $count ),
            'element_types' => $types,
            'confidence' => $confidence,
            'reason' => $reason,
        );
    }

    private function subtree_nodes( $root_id, array $nodes ) {
        if ( ! isset( $nodes[ $root_id ] ) ) { return array(); }
        $out = array(); $queue = array( $root_id ); $seen = array();
        while ( $queue ) {
            $id = array_shift( $queue );
            if ( isset( $seen[ $id ] ) || ! isset( $nodes[ $id ] ) ) { continue; }
            $seen[ $id ] = true; $out[] = $nodes[ $id ];
            foreach ( (array) ( $nodes[ $id ]['children'] ?? array() ) as $child ) { $queue[] = (string) $child; }
        }
        return $out;
    }

    private function types_from_ir( array $nodes ) {
        $types = array();
        foreach ( $nodes as $node ) {
            $tag = strtolower( (string) ( $node['source']['tag'] ?? 'div' ) );
            if ( preg_match( '/^h[1-6]$/', $tag ) ) { $type = 'widget:heading'; }
            elseif ( in_array( $tag, array( 'p', 'blockquote' ), true ) ) { $type = 'widget:text-editor'; }
            elseif ( in_array( $tag, array( 'a', 'button' ), true ) ) { $type = 'widget:button'; }
            elseif ( 'img' === $tag ) { $type = 'widget:image'; }
            elseif ( 'form' === $tag ) { $type = 'widget:form'; }
            elseif ( in_array( $tag, array( 'ul', 'ol' ), true ) ) { $type = 'widget:icon-list'; }
            elseif ( in_array( $tag, array( 'section', 'div', 'article', 'header', 'footer', 'main', 'nav', 'aside' ), true ) || ! empty( $node['children'] ) ) { $type = 'container'; }
            else { $type = 'widget:text-editor'; }
            $types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;
        }
        ksort( $types ); return $types;
    }

    private function read_blueprint( array $item ) {
        $reuse = (array) ( $item['reuse'] ?? array() );
        $registry_type = sanitize_key( (string) ( $reuse['registry_type'] ?? '' ) );
        $registry_id = (string) ( $reuse['registry_id'] ?? ( $item['component_id'] ?? '' ) );
        if ( ! $registry_id ) { return null; }
        $all = array();
        if ( 'section' === $registry_type && class_exists( 'Design_Core_Elementor_Section_Registry' ) ) { $all = ( new Design_Core_Elementor_Section_Registry() )->all(); }
        elseif ( class_exists( 'Design_Core_Elementor_Component_Registry' ) ) { $all = ( new Design_Core_Elementor_Component_Registry() )->all(); }
        foreach ( (array) $all as $entry ) {
            if ( (string) ( $entry['id'] ?? $entry['slug'] ?? '' ) !== $registry_id ) { continue; }
            $blueprint = $entry['master']['structure']['elementor_blueprint'] ?? $entry['master']['structure']['blueprint'] ?? null;
            if ( is_array( $blueprint ) ) { return $blueprint; }
        }
        return null;
    }

    private function count_elementor_tree( array $element, array &$types ) {
        if ( isset( $element[0] ) && is_array( $element[0] ) ) {
            $count = 0; foreach ( $element as $child ) { if ( is_array( $child ) ) { $count += $this->count_elementor_tree( $child, $types ); } } return $count;
        }
        $el_type = sanitize_key( (string) ( $element['elType'] ?? '' ) );
        if ( 'widget' === $el_type ) { $type = 'widget:' . sanitize_key( (string) ( $element['widgetType'] ?? 'unknown' ) ); }
        elseif ( in_array( $el_type, array( 'container', 'section', 'column' ), true ) ) { $type = 'container'; }
        else { $type = 'unknown'; }
        $types[ $type ] = ( $types[ $type ] ?? 0 ) + 1; $count = 1;
        foreach ( (array) ( $element['elements'] ?? array() ) as $child ) { if ( is_array( $child ) ) { $count += $this->count_elementor_tree( $child, $types ); } }
        return $count;
    }
}

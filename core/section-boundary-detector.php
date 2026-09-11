<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Finds non-overlapping page-level section boundaries inside Design IR.
 * BuildPlan roots remain unchanged; section_root_ids are a separate reuse/manifest concern.
 */
class Design_Core_Elementor_Section_Boundary_Detector {
    public function detect( array $ir ) {
        $nodes = array();
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            if ( is_array( $node ) && ! empty( $node['id'] ) ) { $nodes[ $node['id'] ] = $node; }
        }

        $boundaries = array();
        foreach ( (array) ( $ir['root_ids'] ?? array() ) as $root_id ) {
            if ( ! isset( $nodes[ $root_id ] ) ) { continue; }
            $before = count( $boundaries );
            $this->collect( $nodes[ $root_id ], $nodes, $boundaries, true );
            // If a body-level root contains no recognizable section child, keep that root as the
            // section boundary so simple single-section documents still participate in reuse.
            if ( $before === count( $boundaries ) && $this->fallback_root( $nodes[ $root_id ] ) ) { $boundaries[] = $root_id; }
        }
        return array_values( array_unique( $boundaries ) );
    }

    private function collect( array $node, array $nodes, array &$boundaries, $is_document_root = false ) {
        if ( $this->is_boundary( $node, $is_document_root ) ) {
            $boundaries[] = $node['id'];
            // Boundaries are deliberately non-overlapping. Nested cards/subsections stay owned by
            // the nearest page-level section master instead of receiving competing blueprints.
            return;
        }
        foreach ( (array) ( $node['children'] ?? array() ) as $child_id ) {
            if ( isset( $nodes[ $child_id ] ) ) { $this->collect( $nodes[ $child_id ], $nodes, $boundaries, false ); }
        }
    }

    private function is_boundary( array $node, $is_document_root ) {
        $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );
        $classes = strtolower( implode( ' ', (array) ( $node['source']['classes'] ?? array() ) ) );
        $role = sanitize_key( $node['semantic']['role'] ?? '' );

        if ( 'section' === $tag ) { return true; }
        if ( in_array( $tag, array( 'article', 'nav', 'aside' ), true ) && ! $this->looks_like_component( $node ) ) { return true; }

        $explicit_section_class = (bool) preg_match(
            '/(?:^|[\s_-])(?:hero|masthead|banner|cta|faq|comparison|locations?|resources?|guide|process|steps|why|benefits?|services|solutions|contact|enquiry|inquiry|stats|metrics|logo-cloud|partners|testimonials?|pricing)(?:$|[\s_-])/',
            $classes
        );
        if ( $explicit_section_class && ! $this->looks_like_component( $node ) ) { return true; }

        if ( in_array( $role, array( 'hero', 'cta', 'faq', 'pricing', 'pricing-calculator', 'product-comparison', 'navigation' ), true ) && ! $this->looks_like_component( $node ) ) { return true; }

        // <main> and generic document wrappers are containers for boundaries, not boundaries when
        // they have children. They fall back to a section only if traversal finds nothing below.
        if ( $is_document_root && in_array( $tag, array( 'main', 'div', 'body' ), true ) && ! empty( $node['children'] ) ) { return false; }
        return false;
    }

    private function looks_like_component( array $node ) {
        $role = sanitize_key( $node['semantic']['role'] ?? '' );
        $component_type = sanitize_key( $node['semantic']['component_type'] ?? '' );
        $classes = strtolower( implode( ' ', (array) ( $node['source']['classes'] ?? array() ) ) );
        if ( in_array( $component_type, array( 'feature-card', 'testimonial', 'product' ), true ) ) {
            // A section with plural section-level classes wins over the broad analyzer's component role.
            if ( preg_match( '/(?:^|[\s_-])(?:services|benefits|testimonials|products)(?:$|[\s_-])/', $classes ) ) { return false; }
            return true;
        }
        if ( in_array( $role, array( 'feature-card', 'product', 'post' ), true ) && preg_match( '/(?:^|[\s_-])(?:card|item|tile|entry)(?:$|[\s_-])/', $classes ) ) { return true; }
        return false;
    }

    private function fallback_root( array $node ) {
        $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );
        if ( in_array( $tag, array( 'header', 'footer' ), true ) ) { return false; }
        return ! empty( $node['children'] ) || in_array( $tag, array( 'section', 'article', 'main', 'nav', 'aside' ), true );
    }
}

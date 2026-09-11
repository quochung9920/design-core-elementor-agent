<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Converts source/QA evidence into stable, semantic failure-pattern signatures. */
class Design_Core_Elementor_Failure_Signature_Engine {
    const VERSION = 2;

    public function signals_from_ir( array $ir ) {
        $signals = array();
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $figma = (array) ( $node['figma'] ?? array() );
            if ( ! $figma ) { continue; }
            $type = strtoupper( (string) ( $figma['type'] ?? '' ) );
            $geo = (array) ( $figma['geometry'] ?? array() );
            $sizing = (array) ( $figma['sizing'] ?? array() );
            $relative = (array) ( $figma['relative_geometry'] ?? array() );
            $role = sanitize_key( (string) ( $node['semantic']['role'] ?? '' ) );

            if ( ! empty( $figma['text_composition'] ) || 'rich-heading' === sanitize_key( (string) ( $node['semantic']['component_type'] ?? '' ) ) ) { $signals[] = 'figma.mixed-text.composition'; }
            if ( in_array( strtoupper( (string) ( $sizing['horizontal'] ?? $sizing['horizontal_mode'] ?? '' ) ), array( 'FILL', 'FILL_CONTAINER' ), true ) ) { $signals[] = 'figma.auto-layout.fill'; }
            if ( $this->has_bottom_anchor( $relative, $figma ) ) { $signals[] = 'figma.absolute.bottom-anchor'; }
            if ( $this->is_small_primitive( $type, $geo, $node ) ) { $signals[] = 'figma.small-primitive.fixed-size'; }
            if ( $this->has_vector_asset( $node, $type ) ) { $signals[] = 'figma.vector-component.asset'; }
            if ( in_array( $role, array( 'button', 'button-composition' ), true ) && $this->subtree_has_media_signal( $node, $ir ) ) { $signals[] = 'figma.button.icon-composition'; }
            if ( $this->is_fixed_media( $node, $sizing, $geo ) ) { $signals[] = 'figma.media.fixed-height'; }
            if ( $this->has_font_evidence( $node ) ) { $signals[] = 'figma.rendered-font.family'; }
        }
        if ( 'figma' === sanitize_key( (string) ( $ir['source_name'] ?? '' ) ) ) {
            $signals[] = 'figma.rendered-node.geometry';
            $signals[] = 'figma.rendered-node.parent';
        }
        return array_values( array_unique( array_filter( array_map( array( 'Design_Core_Elementor_Design_Memory_Store', 'signature_key' ), $signals ) ) ) );
    }

    public function signature_from_issue( array $issue, array $context = array() ) {
        $category = sanitize_key( (string) ( $issue['category'] ?? 'visual' ) );
        $figma_id = sanitize_text_field( (string) ( $issue['figma_id'] ?? '' ) );
        if ( ! $figma_id && preg_match( '#/figma-node/(\d+)-(\d+)#', (string) ( $issue['path'] ?? '' ), $match ) ) { $figma_id = $match[1] . ':' . $match[2]; }
        $source_node = $figma_id && ! empty( $context['figma_nodes'][ $figma_id ] ) ? (array) $context['figma_nodes'][ $figma_id ] : array();
        if ( $source_node ) {
            $type = strtoupper( (string) ( $source_node['figma']['type'] ?? '' ) );
            $geo = (array) ( $source_node['figma']['geometry'] ?? array() );
            $role = sanitize_key( (string) ( $source_node['semantic']['role'] ?? '' ) );
            if ( $this->is_small_primitive( $type, $geo, $source_node ) ) { return 'figma.small-primitive.fixed-size'; }
            if ( in_array( $role, array( 'button', 'button-composition' ), true ) && $this->has_vector_asset( $source_node, $type ) ) { return 'figma.button.icon-composition'; }
            if ( $this->has_vector_asset( $source_node, $type ) ) { return 'figma.vector-component.asset'; }
            if ( $this->has_bottom_anchor( (array) ( $source_node['figma']['relative_geometry'] ?? array() ), (array) ( $source_node['figma'] ?? array() ) ) ) { return 'figma.absolute.bottom-anchor'; }
            if ( 'rich-heading' === sanitize_key( (string) ( $source_node['semantic']['component_type'] ?? '' ) ) ) { return 'figma.mixed-text.composition'; }
        }
        if ( 'geometry' === $category ) { return 'figma.rendered-node.geometry'; }
        if ( in_array( $category, array( 'structure', 'figma-parent-mismatch', 'figma-node-missing' ), true ) ) { return 'figma.rendered-node.parent'; }
        if ( 'font' === $category ) { return 'figma.rendered-font.family'; }
        if ( 'typography' === $category ) { return 'figma.typography.metrics'; }
        if ( 'media' === $category ) { return 'figma.media.crop-position'; }
        if ( 'surface' === $category ) { return 'figma.surface.style'; }
        if ( 'render' === $category ) { return 'figma.render.failure'; }
        return Design_Core_Elementor_Design_Memory_Store::signature_key( 'visual.' . ( $category ?: 'mismatch' ) );
    }

    public function strategy_for_signature( $signature ) {
        $map = array(
            'figma.vector-component.asset' => 'preserve-vector-asset',
            'figma.button.icon-composition' => 'preserve-icon-composition',
            'figma.small-primitive.fixed-size' => 'fixed-small-primitive',
            'figma.absolute.bottom-anchor' => 'preserve-bottom-anchor',
            'figma.mixed-text.composition' => 'preserve-rich-text-composition',
            'figma.auto-layout.fill' => 'preserve-fill-flex',
            'figma.media.fixed-height' => 'preserve-fixed-media-height',
            'figma.rendered-node.geometry' => 'verify-figma-node-geometry',
            'figma.rendered-node.parent' => 'verify-figma-parent-structure',
            'figma.rendered-font.family' => 'verify-font-fidelity',
        );
        return $map[ Design_Core_Elementor_Design_Memory_Store::signature_key( $signature ) ] ?? '';
    }

    public function figma_node_map( array $ir ) {
        $out = array();
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            $id = sanitize_text_field( (string) ( $node['figma']['id'] ?? '' ) );
            if ( $id ) { $out[ $id ] = $node; }
        }
        return $out;
    }

    private function has_bottom_anchor( array $relative, array $figma ) {
        $constraints = (array) ( $figma['constraints'] ?? array() );
        if ( 'BOTTOM' === strtoupper( (string) ( $constraints['vertical'] ?? '' ) ) ) { return true; }
        foreach ( array( 'bottom', 'bottom_offset', 'offset_bottom' ) as $key ) { if ( array_key_exists( $key, $relative ) ) { return true; } }
        return false;
    }

    private function is_small_primitive( $type, array $geo, array $node ) {
        if ( ! in_array( $type, array( 'ELLIPSE', 'RECTANGLE', 'VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'STAR', 'POLYGON', 'INSTANCE', 'FRAME' ), true ) ) { return false; }
        if ( '' !== trim( (string) ( $node['content']['text'] ?? '' ) ) ) { return false; }
        $width = (float) ( $geo['width'] ?? 0 ); $height = (float) ( $geo['height'] ?? 0 );
        return $width > 0 && $height > 0 && $width <= 32 && $height <= 32;
    }

    private function has_vector_asset( array $node, $type ) {
        foreach ( (array) ( $node['assets']['images'] ?? array() ) as $asset ) { if ( 'figma-vector-export' === (string) ( $asset['source'] ?? '' ) ) { return true; } }
        return in_array( $type, array( 'VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'STAR', 'POLYGON' ), true );
    }

    private function has_font_evidence( array $node ) {
        if ( ! empty( $node['style']['font_family'] ) ) { return true; }
        foreach ( array( 'text_runs', 'text_composition' ) as $key ) {
            $runs = 'text_composition' === $key ? (array) ( $node['figma'][ $key ]['runs'] ?? array() ) : (array) ( $node['figma'][ $key ] ?? array() );
            foreach ( $runs as $run ) { if ( is_array( $run ) && ! empty( $run['style']['font_family'] ) ) { return true; } }
        }
        return false;
    }

    private function is_fixed_media( array $node, array $sizing, array $geo ) {
        $has_media = ! empty( $node['assets']['images'] ) || ! empty( $node['style']['background_image'] );
        $vertical = strtoupper( (string) ( $sizing['vertical'] ?? $sizing['vertical_mode'] ?? '' ) );
        return $has_media && in_array( $vertical, array( 'FIXED', 'FILL', 'FILL_CONTAINER' ), true ) && (float) ( $geo['height'] ?? 0 ) >= 40;
    }

    private function subtree_has_media_signal( array $node, array $ir ) {
        $index = array();
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $item ) { if ( is_array( $item ) && ! empty( $item['id'] ) ) { $index[ $item['id'] ] = $item; } }
        $queue = (array) ( $node['children'] ?? array() );
        while ( $queue ) {
            $id = array_shift( $queue ); $child = $index[ $id ] ?? null;
            if ( ! is_array( $child ) ) { continue; }
            if ( ! empty( $child['assets']['images'] ) || 'img' === strtolower( (string) ( $child['source']['tag'] ?? '' ) ) ) { return true; }
            foreach ( (array) ( $child['children'] ?? array() ) as $nested ) { $queue[] = $nested; }
        }
        return false;
    }
}

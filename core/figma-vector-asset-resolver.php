<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Finds Figma nodes that should be exported as exact SVG assets instead of
 * lowered into generic Elementor containers. Generic, geometry-driven rules
 * intentionally avoid layer-name or PawCare-specific hacks.
 */
class Design_Core_Elementor_Figma_Vector_Asset_Resolver {
    const VERSION = 1;

    public function collect( array $root, $limit = 48 ) {
        $limit = max( 1, min( 50, (int) $limit ) );
        $ids = array();
        $queue = array( array( 'node' => $root, 'ancestor_selected' => false ) );
        while ( $queue && count( $ids ) < $limit ) {
            $entry = array_shift( $queue );
            $node = is_array( $entry['node'] ?? null ) ? $entry['node'] : array();
            if ( ! $node || false === ( $node['visible'] ?? true ) ) { continue; }
            $selected = $this->is_export_candidate( $node );
            if ( $selected && ! empty( $node['id'] ) ) {
                $ids[] = sanitize_text_field( (string) $node['id'] );
                // Exporting the composite preserves exact icon geometry and
                // avoids also exporting every child vector redundantly.
                continue;
            }
            foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
                if ( is_array( $child ) ) { $queue[] = array( 'node' => $child, 'ancestor_selected' => false ); }
            }
        }
        return array_values( array_unique( $ids ) );
    }

    public function is_export_candidate( array $node ) {
        $type = strtoupper( (string) ( $node['type'] ?? '' ) );
        $children = array_values( array_filter( (array) ( $node['children'] ?? array() ), 'is_array' ) );
        $box = (array) ( $node['absoluteBoundingBox'] ?? array() );
        $width = (float) ( $box['width'] ?? 0 );
        $height = (float) ( $box['height'] ?? 0 );

        // Native vector leaves are exact source assets.
        if ( in_array( $type, array( 'VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'STAR', 'POLYGON' ), true ) && ! $children ) { return true; }

        // Small shape primitives used as glyphs/dots should keep authored
        // geometry rather than becoming flex containers.
        if ( in_array( $type, array( 'ELLIPSE', 'RECTANGLE' ), true ) && ! $children && $this->small_asset_box( $width, $height ) ) { return true; }

        // Small vector-only composites (instance/component/frame/group) are
        // icons in practice. Export the whole node so strokes, masks, shadows
        // and variant geometry survive exactly.
        if ( in_array( $type, array( 'INSTANCE', 'COMPONENT', 'GROUP', 'FRAME' ), true ) && $children && $this->small_asset_box( $width, $height ) ) {
            $stats = $this->descendant_stats( $node );
            if ( 0 === $stats['text'] && $stats['vectorish'] > 0 && $stats['nodes'] <= 32 ) { return true; }
        }
        return false;
    }

    private function small_asset_box( $width, $height ) {
        return $width > 0 && $height > 0 && $width <= 96 && $height <= 96;
    }

    private function descendant_stats( array $root ) {
        $stats = array( 'nodes' => 0, 'text' => 0, 'vectorish' => 0 );
        $queue = (array) ( $root['children'] ?? array() );
        while ( $queue && $stats['nodes'] <= 64 ) {
            $node = array_shift( $queue );
            if ( ! is_array( $node ) || false === ( $node['visible'] ?? true ) ) { continue; }
            $stats['nodes']++;
            $type = strtoupper( (string) ( $node['type'] ?? '' ) );
            if ( 'TEXT' === $type ) { $stats['text']++; }
            if ( in_array( $type, array( 'VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'STAR', 'POLYGON', 'ELLIPSE', 'RECTANGLE' ), true ) ) { $stats['vectorish']++; }
            foreach ( (array) ( $node['children'] ?? array() ) as $child ) { if ( is_array( $child ) ) { $queue[] = $child; } }
        }
        return $stats;
    }
}

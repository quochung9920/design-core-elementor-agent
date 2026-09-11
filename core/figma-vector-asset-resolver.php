<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Finds source nodes that are visually atomic assets and should be exported as
 * one exact SVG instead of lowered into a tree of generic Elementor containers.
 * Decisions are geometry/type driven and never depend on project-specific names.
 */
class Design_Core_Elementor_Figma_Vector_Asset_Resolver {
    const VERSION = 1;
    const MAX_ICON_SIZE = 96;
    const MAX_DESCENDANTS = 32;

    public function collect( array $root, $limit = 48 ) {
        $limit = max( 1, min( 50, (int) $limit ) );
        $ids = array();
        $queue = array( $root );

        while ( $queue && count( $ids ) < $limit ) {
            $node = array_shift( $queue );
            if ( ! is_array( $node ) || false === ( $node['visible'] ?? true ) ) { continue; }

            if ( $this->is_export_candidate( $node ) ) {
                if ( ! empty( $node['id'] ) ) { $ids[] = sanitize_text_field( (string) $node['id'] ); }
                // The composite itself is the visual asset. Do not also export
                // descendants or the compiler would duplicate the glyph.
                continue;
            }

            foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
                if ( is_array( $child ) ) { $queue[] = $child; }
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

        // Exact native vector leaves.
        if ( in_array( $type, array( 'VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'STAR', 'POLYGON' ), true ) && ! $children ) { return true; }

        // Small authored shape primitives often represent dots/badges/icons.
        if ( in_array( $type, array( 'ELLIPSE', 'RECTANGLE' ), true ) && ! $children && $this->small_box( $width, $height ) ) { return true; }

        // Figma commonly wraps an icon in INSTANCE/COMPONENT/FRAME/GROUP. Export
        // the complete visual atom when it is small and contains no text/media.
        if ( in_array( $type, array( 'INSTANCE', 'COMPONENT', 'FRAME', 'GROUP' ), true ) && $children && $this->small_box( $width, $height ) ) {
            $stats = $this->descendant_stats( $node );
            return 0 === $stats['text'] && 0 === $stats['image'] && $stats['vectorish'] > 0 && $stats['nodes'] <= self::MAX_DESCENDANTS;
        }
        return false;
    }

    private function small_box( $width, $height ) {
        return $width > 0 && $height > 0 && $width <= self::MAX_ICON_SIZE && $height <= self::MAX_ICON_SIZE;
    }

    private function descendant_stats( array $root ) {
        $stats = array( 'nodes' => 0, 'text' => 0, 'image' => 0, 'vectorish' => 0 );
        $queue = (array) ( $root['children'] ?? array() );
        while ( $queue && $stats['nodes'] <= self::MAX_DESCENDANTS + 1 ) {
            $node = array_shift( $queue );
            if ( ! is_array( $node ) || false === ( $node['visible'] ?? true ) ) { continue; }
            $stats['nodes']++;
            $type = strtoupper( (string) ( $node['type'] ?? '' ) );
            if ( 'TEXT' === $type ) { $stats['text']++; }
            if ( in_array( $type, array( 'VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'STAR', 'POLYGON', 'ELLIPSE', 'RECTANGLE' ), true ) ) { $stats['vectorish']++; }
            foreach ( (array) ( $node['fills'] ?? array() ) as $fill ) {
                if ( is_array( $fill ) && false !== ( $fill['visible'] ?? true ) && 'IMAGE' === strtoupper( (string) ( $fill['type'] ?? '' ) ) ) { $stats['image']++; }
            }
            foreach ( (array) ( $node['children'] ?? array() ) as $child ) { if ( is_array( $child ) ) { $queue[] = $child; } }
        }
        return $stats;
    }
}

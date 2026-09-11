<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Finds leaf image-paint nodes whose visual result depends on Figma paint
 * transforms/filters/rotation. Those atoms are exported as rendered PNGs so the
 * browser receives Figma's exact crop instead of guessing CSS object-position.
 */
class Design_Core_Elementor_Figma_Raster_Asset_Resolver {
    const VERSION = 1;
    const MAX_AREA = 16777216; // 16 MP guard for a single authored atom.

    public function collect( array $root, $limit = 32 ) {
        $limit = max( 1, min( 50, (int) $limit ) );
        $ids = array(); $queue = array( $root );
        while ( $queue && count( $ids ) < $limit ) {
            $node = array_shift( $queue );
            if ( ! is_array( $node ) || false === ( $node['visible'] ?? true ) ) { continue; }
            if ( $this->requires_exact_render( $node ) ) {
                if ( ! empty( $node['id'] ) ) { $ids[] = sanitize_text_field( (string) $node['id'] ); }
                continue;
            }
            foreach ( (array) ( $node['children'] ?? array() ) as $child ) { if ( is_array( $child ) ) { $queue[] = $child; } }
        }
        return array_values( array_unique( $ids ) );
    }

    public function requires_exact_render( array $node ) {
        if ( ! empty( $node['children'] ) ) { return false; }
        $box = (array) ( $node['absoluteBoundingBox'] ?? array() );
        $width = (float) ( $box['width'] ?? 0 ); $height = (float) ( $box['height'] ?? 0 );
        if ( $width <= 0 || $height <= 0 || ( $width * $height ) > self::MAX_AREA ) { return false; }
        foreach ( (array) ( $node['fills'] ?? array() ) as $fill ) {
            if ( ! is_array( $fill ) || false === ( $fill['visible'] ?? true ) || 'IMAGE' !== strtoupper( (string) ( $fill['type'] ?? '' ) ) ) { continue; }
            $scale = strtoupper( (string) ( $fill['scaleMode'] ?? 'FILL' ) );
            $has_transform = is_array( $fill['imageTransform'] ?? null ) && ! $this->identity_transform( $fill['imageTransform'] );
            $has_filters = $this->nonzero_filters( (array) ( $fill['filters'] ?? array() ) );
            $rotation = abs( (float) ( $fill['rotation'] ?? 0 ) ) > 0.01;
            // REST exposes imageTransform for STRETCH. Preserve any transformed,
            // filtered or rotated image atom through Figma's own renderer.
            return 'STRETCH' === $scale || $has_transform || $has_filters || $rotation;
        }
        return false;
    }

    public function synthetic_ref( $node_id ) {
        return 'dc-rendered-' . substr( hash( 'sha256', (string) $node_id ), 0, 32 );
    }

    private function identity_transform( array $matrix ) {
        if ( 2 !== count( $matrix ) || ! is_array( $matrix[0] ?? null ) || ! is_array( $matrix[1] ?? null ) ) { return false; }
        $expected = array( array( 1, 0, 0 ), array( 0, 1, 0 ) );
        for ( $row = 0; $row < 2; $row++ ) {
            for ( $col = 0; $col < 3; $col++ ) {
                if ( ! isset( $matrix[ $row ][ $col ] ) || abs( (float) $matrix[ $row ][ $col ] - (float) $expected[ $row ][ $col ] ) > 0.00001 ) { return false; }
            }
        }
        return true;
    }

    private function nonzero_filters( array $filters ) {
        foreach ( array( 'exposure', 'contrast', 'saturation', 'temperature', 'tint', 'highlights', 'shadows' ) as $key ) {
            if ( isset( $filters[ $key ] ) && abs( (float) $filters[ $key ] ) > 0.00001 ) { return true; }
        }
        return false;
    }
}

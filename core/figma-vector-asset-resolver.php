<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Finds Figma nodes that are visually atomic vector assets and should be
 * exported as one exact SVG rather than lowered into generic containers.
 * Detection is geometry/type/paint driven and contains no project-specific IDs.
 */
class Design_Core_Elementor_Figma_Vector_Asset_Resolver {
    const VERSION = 2;
    const MAX_ICON_SIZE = 128;
    const MAX_ICON_AREA = 12288;
    const MAX_DESCENDANTS = 48;

    public function collect( array $root, $limit = 48 ) {
        $limit = max( 1, min( 50, (int) $limit ) );
        $ids = array();
        $queue = array( $root );

        while ( $queue && count( $ids ) < $limit ) {
            $node = array_shift( $queue );
            if ( ! is_array( $node ) || false === ( $node['visible'] ?? true ) ) { continue; }

            if ( $this->is_export_candidate( $node ) ) {
                if ( ! empty( $node['id'] ) ) { $ids[] = sanitize_text_field( (string) $node['id'] ); }
                // The selected composite is the visual atom. Exporting descendants
                // as well would duplicate the icon after IR lowering.
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

        if ( in_array( $type, array( 'VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'STAR', 'POLYGON' ), true ) && ! $children ) {
            return $this->has_visual_paint( $node ) || in_array( $type, array( 'VECTOR', 'BOOLEAN_OPERATION', 'LINE' ), true );
        }

        // Tiny authored primitives are commonly status dots, rules and glyph parts.
        if ( in_array( $type, array( 'ELLIPSE', 'RECTANGLE' ), true ) && ! $children && $this->small_box( $width, $height ) ) {
            return $this->has_visual_paint( $node );
        }

        // Icons are often COMPONENT/INSTANCE/FRAME/GROUP wrappers around multiple
        // vector leaves. Export the complete atom when it contains no text/raster.
        if ( in_array( $type, array( 'INSTANCE', 'COMPONENT', 'FRAME', 'GROUP' ), true ) && $children && $this->small_box( $width, $height ) ) {
            $stats = $this->descendant_stats( $node );
            return 0 === $stats['text']
                && 0 === $stats['image']
                && $stats['vectorish'] > 0
                && $stats['painted'] > 0
                && $stats['nodes'] <= self::MAX_DESCENDANTS;
        }

        return false;
    }

    private function small_box( $width, $height ) {
        return $width > 0 && $height > 0
            && $width <= self::MAX_ICON_SIZE
            && $height <= self::MAX_ICON_SIZE
            && ( $width * $height ) <= self::MAX_ICON_AREA;
    }

    private function descendant_stats( array $root ) {
        $stats = array( 'nodes' => 0, 'text' => 0, 'image' => 0, 'vectorish' => 0, 'painted' => 0 );
        $queue = (array) ( $root['children'] ?? array() );

        while ( $queue && $stats['nodes'] <= self::MAX_DESCENDANTS + 1 ) {
            $node = array_shift( $queue );
            if ( ! is_array( $node ) || false === ( $node['visible'] ?? true ) ) { continue; }
            $stats['nodes']++;
            $type = strtoupper( (string) ( $node['type'] ?? '' ) );
            if ( 'TEXT' === $type ) { $stats['text']++; }
            if ( in_array( $type, array( 'VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'STAR', 'POLYGON', 'ELLIPSE', 'RECTANGLE' ), true ) ) { $stats['vectorish']++; }
            if ( $this->has_visual_paint( $node ) ) { $stats['painted']++; }

            foreach ( (array) ( $node['fills'] ?? array() ) as $fill ) {
                if ( is_array( $fill ) && false !== ( $fill['visible'] ?? true ) && 'IMAGE' === strtoupper( (string) ( $fill['type'] ?? '' ) ) ) { $stats['image']++; }
            }
            foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
                if ( is_array( $child ) ) { $queue[] = $child; }
            }
        }

        return $stats;
    }

    private function has_visual_paint( array $node ) {
        foreach ( array( 'fills', 'strokes' ) as $key ) {
            foreach ( (array) ( $node[ $key ] ?? array() ) as $paint ) {
                if ( is_array( $paint ) && false !== ( $paint['visible'] ?? true ) && (float) ( $paint['opacity'] ?? 1 ) > 0 ) { return true; }
            }
        }
        foreach ( (array) ( $node['effects'] ?? array() ) as $effect ) {
            if ( is_array( $effect ) && false !== ( $effect['visible'] ?? true ) ) { return true; }
        }
        return false;
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Figma pre-IR normalization v2.
 *
 * Keeps authored geometry, FILL/HUG/FIXED sizing semantics, relative absolute
 * offsets, clipping and variable references so later target mapping does not
 * have to guess from bounding boxes alone.
 */
class Design_Core_Elementor_Figma_Normalization_Service {
    const VERSION = 2;
    const MAX_NODES = 3000;
    const MAX_DEPTH = 96;

    private $nodes = 0;
    private $warnings = array();
    private $variable_refs = array();

    public function normalize( array $root ) {
        $this->nodes = 0;
        $this->warnings = array();
        $this->variable_refs = array();
        $normalized = $this->node( $root, null, 0 );
        return array(
            'node' => $normalized,
            'report' => array(
                'version' => self::VERSION,
                'nodes' => $this->nodes,
                'warning_count' => count( $this->warnings ),
                'warnings' => array_values( array_unique( $this->warnings ) ),
                'variable_refs' => array_values( $this->variable_refs ),
                'fidelity_contract' => array(
                    'sizing_semantics' => 'preserved',
                    'absolute_offsets' => 'relative-to-parent',
                    'clipping' => 'preserved',
                    'hidden_nodes' => 'removed',
                ),
            ),
        );
    }

    private function node( array $node, $parent, $depth ) {
        if ( $depth > self::MAX_DEPTH ) { throw new OverflowException( 'Figma normalization exceeds the bounded depth limit.' ); }
        if ( ++$this->nodes > self::MAX_NODES ) { throw new OverflowException( 'Figma normalization exceeds the bounded node limit.' ); }

        $box = is_array( $node['absoluteBoundingBox'] ?? null ) ? $node['absoluteBoundingBox'] : array();
        $parent_box = is_array( $parent['absoluteBoundingBox'] ?? null ) ? $parent['absoluteBoundingBox'] : array();
        $parent_mode = strtoupper( (string) ( $parent['layoutMode'] ?? 'NONE' ) );
        $layout = array();
        $relative = array();

        if ( isset( $box['x'], $box['y'], $box['width'], $box['height'] ) ) {
            $relative = array(
                'x' => (float) $box['x'], 'y' => (float) $box['y'],
                'width' => (float) $box['width'], 'height' => (float) $box['height'],
            );
            if ( isset( $parent_box['x'], $parent_box['y'], $parent_box['width'], $parent_box['height'] ) ) {
                $relative['left'] = (float) $box['x'] - (float) $parent_box['x'];
                $relative['top'] = (float) $box['y'] - (float) $parent_box['y'];
                $relative['right'] = (float) $parent_box['x'] + (float) $parent_box['width'] - ( (float) $box['x'] + (float) $box['width'] );
                $relative['bottom'] = (float) $parent_box['y'] + (float) $parent_box['height'] - ( (float) $box['y'] + (float) $box['height'] );
            }
        }

        if ( 'ABSOLUTE' === strtoupper( (string) ( $node['layoutPositioning'] ?? '' ) ) ) {
            $layout['position'] = 'absolute';
            $constraints = is_array( $node['constraints'] ?? null ) ? $node['constraints'] : array();
            $horizontal = strtoupper( (string) ( $constraints['horizontal'] ?? 'LEFT' ) );
            $vertical = strtoupper( (string) ( $constraints['vertical'] ?? 'TOP' ) );

            if ( isset( $relative['left'], $relative['right'] ) ) {
                if ( 'RIGHT' === $horizontal ) { $layout['right'] = $this->px( $relative['right'] ); }
                elseif ( 'LEFT_RIGHT' === $horizontal ) { $layout['left'] = $this->px( $relative['left'] ); $layout['right'] = $this->px( $relative['right'] ); }
                else { $layout['left'] = $this->px( $relative['left'] ); }
            }
            if ( isset( $relative['top'], $relative['bottom'] ) ) {
                if ( 'BOTTOM' === $vertical ) { $layout['bottom'] = $this->px( $relative['bottom'] ); }
                elseif ( 'TOP_BOTTOM' === $vertical ) { $layout['top'] = $this->px( $relative['top'] ); $layout['bottom'] = $this->px( $relative['bottom'] ); }
                else { $layout['top'] = $this->px( $relative['top'] ); }
            }
        }

        if ( ! empty( $node['clipsContent'] ) ) { $layout['overflow'] = 'hidden'; }

        $sizing = array(
            'horizontal' => strtoupper( (string) ( $node['layoutSizingHorizontal'] ?? '' ) ),
            'vertical' => strtoupper( (string) ( $node['layoutSizingVertical'] ?? '' ) ),
            'parent_layout_mode' => in_array( $parent_mode, array( 'HORIZONTAL', 'VERTICAL' ), true ) ? $parent_mode : 'NONE',
            'layout_positioning' => strtoupper( (string) ( $node['layoutPositioning'] ?? 'AUTO' ) ),
        );

        $refs = $this->collect_variable_refs( $node );
        foreach ( $refs as $ref ) { $this->variable_refs[ $ref['id'] . '|' . $ref['path'] ] = $ref; }
        $warnings = $this->node_warnings( $node, $sizing );
        foreach ( $warnings as $warning ) { $this->warnings[] = $warning; }

        $node['_design_core_layout'] = $layout;
        $node['_design_core_sizing'] = $sizing;
        $node['_design_core_relative_geometry'] = $relative;
        $node['_design_core_parent_geometry'] = $this->safe_box( $parent_box );
        $node['_design_core_variable_refs'] = $refs;
        $node['_design_core_warnings'] = $warnings;
        $node['_design_core_normalization'] = array( 'version' => self::VERSION, 'visibility' => 'included' );

        $children = array();
        foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
            if ( ! is_array( $child ) || false === ( $child['visible'] ?? true ) ) { continue; }
            $children[] = $this->node( $child, $node, $depth + 1 );
        }
        if ( ! empty( $node['itemReverseZIndex'] ) && 'NONE' !== strtoupper( (string) ( $node['layoutMode'] ?? 'NONE' ) ) ) {
            $absolute = array(); $flow = array();
            foreach ( $children as $child ) {
                if ( 'ABSOLUTE' === strtoupper( (string) ( $child['layoutPositioning'] ?? '' ) ) ) { $absolute[] = $child; }
                else { $flow[] = $child; }
            }
            $children = array_merge( array_reverse( $absolute ), $flow );
        }
        $node['children'] = $children;
        return $node;
    }

    private function collect_variable_refs( array $node ) {
        $refs = array();
        $this->walk_bound_variables( (array) ( $node['boundVariables'] ?? array() ), 'node.boundVariables', $refs );
        foreach ( array( 'fills', 'strokes', 'effects' ) as $field ) {
            foreach ( (array) ( $node[ $field ] ?? array() ) as $index => $item ) {
                if ( ! is_array( $item ) ) { continue; }
                $this->walk_bound_variables( (array) ( $item['boundVariables'] ?? array() ), $field . '.' . $index . '.boundVariables', $refs );
                foreach ( (array) ( $item['gradientStops'] ?? array() ) as $stop_index => $stop ) {
                    if ( is_array( $stop ) ) { $this->walk_bound_variables( (array) ( $stop['boundVariables'] ?? array() ), $field . '.' . $index . '.gradientStops.' . $stop_index, $refs ); }
                }
            }
        }
        $unique = array();
        foreach ( $refs as $ref ) { $unique[ $ref['id'] . '|' . $ref['path'] ] = $ref; }
        return array_values( $unique );
    }

    private function walk_bound_variables( array $bound, $path, array &$refs ) {
        foreach ( $bound as $name => $value ) {
            if ( is_array( $value ) && isset( $value['id'] ) ) {
                $refs[] = array( 'id' => sanitize_text_field( (string) $value['id'] ), 'name' => sanitize_text_field( (string) ( $value['name'] ?? $name ) ), 'path' => sanitize_text_field( $path . '.' . $name ) );
                continue;
            }
            if ( is_array( $value ) ) { $this->walk_bound_variables( $value, $path . '.' . $name, $refs ); }
        }
    }

    private function node_warnings( array $node, array $sizing ) {
        $warnings = array();
        $type = strtoupper( (string) ( $node['type'] ?? '' ) );
        $name = sanitize_text_field( (string) ( $node['name'] ?? $type ) );
        if ( in_array( $type, array( 'STAR', 'POLYGON', 'BOOLEAN_OPERATION', 'LINE', 'VECTOR' ), true ) ) {
            $warnings[] = $name . ': vector geometry requires exported asset preservation or explicit target verification.';
        }
        foreach ( (array) ( $node['fills'] ?? array() ) as $fill ) {
            if ( ! is_array( $fill ) ) { continue; }
            $paint = strtoupper( (string) ( $fill['type'] ?? '' ) );
            if ( in_array( $paint, array( 'GRADIENT_ANGULAR', 'GRADIENT_DIAMOND' ), true ) ) {
                $warnings[] = $name . ': ' . $paint . ' has no guaranteed native Elementor equivalent; preserve as governed fallback and verify render.';
            }
        }
        foreach ( (array) ( $node['effects'] ?? array() ) as $effect ) {
            if ( ! is_array( $effect ) ) { continue; }
            $effect_type = strtoupper( (string) ( $effect['type'] ?? '' ) );
            if ( $effect_type && ! in_array( $effect_type, array( 'DROP_SHADOW', 'INNER_SHADOW', 'LAYER_BLUR', 'BACKGROUND_BLUR' ), true ) ) {
                $warnings[] = $name . ': effect ' . $effect_type . ' requires target capability verification.';
            }
        }
        if ( 'ABSOLUTE' === ( $sizing['layout_positioning'] ?? '' ) ) { $warnings[] = $name . ': absolute positioning preserved relative to the authored parent.'; }
        if ( in_array( $sizing['horizontal'] ?? '', array( 'FILL', 'HUG', 'FIXED' ), true ) || in_array( $sizing['vertical'] ?? '', array( 'FILL', 'HUG', 'FIXED' ), true ) ) {
            $warnings[] = $name . ': Figma sizing semantics preserved for target lowering.';
        }
        return $warnings;
    }

    private function safe_box( array $box ) {
        $out = array();
        foreach ( array( 'x', 'y', 'width', 'height' ) as $key ) { if ( isset( $box[ $key ] ) && is_numeric( $box[ $key ] ) ) { $out[ $key ] = (float) $box[ $key ]; } }
        return $out;
    }

    private function px( $value ) { return array( 'value' => round( (float) $value, 3 ), 'unit' => 'px' ); }
}

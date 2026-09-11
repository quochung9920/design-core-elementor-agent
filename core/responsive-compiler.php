<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deterministic responsive compiler: source CSS media queries become
 * Elementor per-device IR overrides (tablet/mobile/...).
 *
 * Why this exists: the exact-match policy only honored media queries within
 * 1px of a registered Elementor breakpoint, so real-world sources (1100px,
 * 900px, 720px, 600px, 360px...) produced ZERO responsive overrides and all
 * their rules decayed into custom CSS. This compiler instead classifies every
 * max-width query to its closest active Elementor device, merges rules that
 * land on the same device (later source order wins, mirroring the CSS
 * cascade), drops values identical to desktop, and records everything it
 * could not translate in ir['diagnostics']['responsive_mapping'].
 *
 * Only declarations with native IR shapes are emitted; anything else is
 * reported as untranslated, never guessed.
 */
class Design_Core_Elementor_Responsive_Compiler {
    const VERSION = 1;

    public function compile( array $ir, $css ) {
        $mapping = array( 'version' => self::VERSION, 'devices' => array(), 'rules_mapped' => 0, 'rules_approximate' => 0, 'rules_unmapped' => array(), 'nodes_touched' => array() );
        $css = (string) $css;
        if ( '' === trim( $css ) || empty( $ir['nodes'] ) ) { return array( $ir, $mapping ); }
        $breakpoints = class_exists( 'Design_Core_Elementor_Breakpoint_Registry' ) ? new Design_Core_Elementor_Breakpoint_Registry() : null;
        $active = $breakpoints ? array_keys( $breakpoints->all() ) : array( 'tablet', 'mobile' );
        $nodes = array();
        foreach ( $ir['nodes'] as $node ) { if ( is_array( $node ) && ! empty( $node['id'] ) ) { $nodes[ $node['id'] ] = $node; } }
        foreach ( $this->media_blocks( $css ) as $block ) {
            $target = $this->target_device( $block['media'], $breakpoints, $active );
            if ( '' === $target ) { $mapping['rules_unmapped'][] = array( 'media' => $block['media'], 'reason' => 'no-active-device-match' ); continue; }
            foreach ( $this->inner_rules( $block['body'] ) as $rule ) {
                $matched = $this->match_nodes( $rule['selector'], $nodes );
                if ( ! $matched ) { $mapping['rules_unmapped'][] = array( 'media' => $block['media'], 'selector' => $rule['selector'], 'reason' => 'no-node-match' ); continue; }
                $values = $this->declarations_to_values( $rule['declarations'] );
                if ( ! $values['layout'] && ! $values['style'] && ! $values['spacing'] ) {
                    $mapping['rules_unmapped'][] = array( 'media' => $block['media'], 'selector' => $rule['selector'], 'reason' => 'no-native-controls', 'properties' => array_keys( $rule['declarations'] ) );
                    continue;
                }
                foreach ( $matched as $id => $approximate ) {
                    $applied = $this->apply_override( $nodes[ $id ], $target, $values );
                    if ( $applied ) {
                        $nodes[ $id ] = $applied;
                        $mapping['rules_mapped']++;
                        $mapping['devices'][ $target ] = ( $mapping['devices'][ $target ] ?? 0 ) + 1;
                        $mapping['nodes_touched'][ $id ] = true;
                        if ( $approximate ) { $mapping['rules_approximate']++; }
                    }
                }
            }
        }
        $ir['nodes'] = array_values( $nodes );
        $mapping['nodes_touched'] = array_keys( $mapping['nodes_touched'] );
        $mapping['devices'] = (array) $mapping['devices'];
        // The analyzer's governance ran before these overrides existed, so the
        // compiler governs what it introduced itself: bounded fixed heights
        // (and widths) it wrote, and only when no viewport-unit risk exists
        // anywhere on the node. Anything else stays fatal downstream.
        foreach ( $ir['nodes'] as &$node ) {
            if ( ! is_array( $node ) || empty( $node['id'] ) || empty( $mapping['nodes_touched'] ) || ! in_array( $node['id'], $mapping['nodes_touched'], true ) ) { continue; }
            $this->govern_introduced( $node, in_array( (string) $node['id'], array_map( 'strval', (array) ( $ir['root_ids'] ?? array() ) ), true ) );
        }
        unset( $node );
        $ir['diagnostics'] = is_array( $ir['diagnostics'] ?? null ) ? $ir['diagnostics'] : array();
        $ir['diagnostics']['responsive_mapping'] = $mapping;
        return array( $ir, $mapping );
    }

    private function govern_introduced( array &$node, $is_root ) {
        if ( $this->has_viewport_height_risk( $node ) ) { return; }
        $height = $this->max_fixed_px( $node, 'height' );
        if ( null !== $height && 320 < $height && $height <= 800 ) {
            $node['media_governance'] = array( 'height_exception' => true, 'reason' => 'Responsive compiler introduced a bounded ' . (int) $height . 'px height override; Elementor maps it to a min-height control. No viewport-unit risk present on this node.' );
        }
        if ( $is_root ) { return; }
        $width = $this->max_fixed_px( $node, 'width' );
        if ( null !== $width && 320 < $width && $width <= 1200 && empty( $node['layout_governance']['fixed_width_exception'] ) ) {
            $node['layout_governance'] = array( 'fixed_width_exception' => true, 'reason' => 'Responsive compiler introduced a bounded ' . (int) $width . 'px width override on a non-root box; Elementor represents it natively.' );
        }
    }

    private function has_viewport_height_risk( array $node ) {
        foreach ( $this->value_sets( $node ) as $values ) {
            foreach ( array( $values['layout']['height'] ?? null, $values['layout']['min_height'] ?? null, $values['style']['css_fallback']['height'] ?? null, $values['style']['css_fallback']['min-height'] ?? null ) as $raw ) {
                if ( is_string( $raw ) && preg_match( '/(?:^|[^a-z])(?:[0-9.]+)?(?:svh|lvh|dvh|vh)\b/i', $raw ) ) { return true; }
            }
        }
        return false;
    }

    private function max_fixed_px( array $node, $key ) {
        $max = null;
        foreach ( $this->value_sets( $node ) as $values ) {
            $raw = $values['layout'][ $key ] ?? null;
            $px = null;
            if ( is_array( $raw ) && array_key_exists( 'value', $raw ) && 'px' === strtolower( (string) ( $raw['unit'] ?? '' ) ) && is_numeric( $raw['value'] ) ) {
                $px = (float) $raw['value'];
            } elseif ( is_scalar( $raw ) && preg_match( '/^\s*([0-9.]+)px\s*$/i', (string) $raw, $m ) ) {
                $px = (float) $m[1];
            }
            if ( null !== $px && ( null === $max || $px > $max ) ) { $max = $px; }
        }
        return $max;
    }

    private function value_sets( array $node ) {
        $sets = array( array( 'layout' => (array) ( $node['layout'] ?? array() ), 'style' => (array) ( $node['style'] ?? array() ) ) );
        foreach ( $node['responsive'] ?? array() as $responsive ) {
            if ( is_array( $responsive ) ) {
                $sets[] = array( 'layout' => (array) ( $responsive['layout'] ?? array() ), 'style' => (array) ( $responsive['style'] ?? array() ) );
            }
        }
        return $sets;
    }

    private function media_blocks( $css ) {
        $blocks = array();
        $offset = 0;
        $length = strlen( $css );
        while ( false !== ( $at = stripos( $css, '@media', $offset ) ) ) {
            $open = strpos( $css, '{', $at );
            if ( false === $open ) { break; }
            $media = trim( substr( $css, $at + 6, $open - $at - 6 ) );
            $depth = 0;
            for ( $i = $open; $i < $length; $i++ ) {
                if ( '{' === $css[ $i ] ) { $depth++; }
                elseif ( '}' === $css[ $i ] ) {
                    $depth--;
                    if ( 0 === $depth ) {
                        $blocks[] = array( 'media' => $media, 'body' => substr( $css, $open + 1, $i - $open - 1 ) );
                        $offset = $i + 1;
                        break;
                    }
                }
            }
            if ( $depth > 0 ) { break; }
        }
        return $blocks;
    }

    private function inner_rules( $body ) {
        $rules = array();
        if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/', (string) $body, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $selector = trim( $match[1] );
                if ( '' === $selector ) { continue; }
                $rules[] = array( 'selector' => $selector, 'declarations' => $this->parse_declarations( $match[2] ) );
            }
        }
        return $rules;
    }

    private function parse_declarations( $body ) {
        $out = array();
        foreach ( preg_split( '/;(?=(?:[^(]*\([^)]*\))*[^)]*$)/', (string) $body ) as $declaration ) {
            if ( false === strpos( $declaration, ':' ) ) { continue; }
            list( $property, $value ) = array_map( 'trim', explode( ':', $declaration, 2 ) );
            $property = strtolower( $property );
            if ( '' === $property || '' === $value ) { continue; }
            $out[ $property ] = preg_replace( '/\s*!important\s*$/i', '', $value );
        }
        return $out;
    }

    private function target_device( $media, $breakpoints, array $active ) {
        $max = null;
        if ( preg_match_all( '/max-width\s*:\s*(\d+(?:\.\d+)?)px/i', (string) $media, $m ) ) {
            foreach ( $m[1] as $w ) { $max = null === $max ? (float) $w : min( $max, (float) $w ); }
        }
        if ( null === $max ) { return ''; }
        $device = $breakpoints ? $breakpoints->classify_max_width( (int) round( $max ) ) : 'mobile';
        $device = sanitize_key( (string) $device );
        if ( 'desktop' === $device || ! in_array( $device, $active, true ) ) { return ''; }
        return $device;
    }

    private function match_nodes( $selector, array $nodes ) {
        $matched = array();
        $parts = array_values( array_filter( array_map( 'trim', preg_split( '/\s*[>+~]\s*|\s+/', (string) $selector ) ) ) );
        if ( ! $parts ) { return $matched; }
        $last = end( $parts );
        $approximate = count( $parts ) > 1;
        foreach ( $nodes as $id => $node ) {
            if ( $this->matches_part( $last, $node ) ) { $matched[ $id ] = $approximate; }
        }
        return $matched;
    }

    private function matches_part( $part, array $node ) {
        $part = preg_replace( '/::?[a-zA-Z-]+(\([^)]*\))?/', '', (string) $part );
        $classes = (array) ( $node['source']['classes'] ?? array() );
        if ( preg_match_all( '/\.([a-zA-Z0-9_-]+)/', $part, $m ) ) {
            foreach ( $m[1] as $class ) { if ( ! in_array( $class, $classes, true ) ) { return false; } }
            return true;
        }
        if ( 0 === strpos( $part, '#' ) ) {
            $id = substr( $part, 1 );
            return $id === (string) ( $node['source']['attributes']['id'] ?? '' );
        }
        if ( preg_match( '/^[a-zA-Z][a-zA-Z0-9]*$/', $part ) ) {
            return strtolower( $part ) === strtolower( (string) ( $node['source']['tag'] ?? '' ) );
        }
        if ( preg_match( '/^([a-zA-Z][a-zA-Z0-9]*)\.([a-zA-Z0-9_-]+)/', $part, $m ) ) {
            return strtolower( $m[1] ) === strtolower( (string) ( $node['source']['tag'] ?? '' ) ) && in_array( $m[2], $classes, true );
        }
        return false;
    }

    private function declarations_to_values( array $declarations ) {
        $layout = array(); $style = array(); $spacing = array();
        $scalars = array(
            'display' => array( 'layout', 'display' ), 'flex-direction' => array( 'layout', 'direction' ),
            'flex-wrap' => array( 'layout', 'wrap' ), 'justify-content' => array( 'layout', 'justify' ),
            'align-items' => array( 'layout', 'align' ), 'align-content' => array( 'layout', 'align_content' ),
            'position' => array( 'layout', 'position' ), 'overflow' => array( 'layout', 'overflow' ),
            'color' => array( 'style', 'color' ), 'background-color' => array( 'style', 'background' ),
            'text-align' => array( 'style', 'align' ), 'font-weight' => array( 'style', 'font_weight' ),
            'text-transform' => array( 'style', 'text_transform' ), 'font-style' => array( 'style', 'font_style' ),
            'font-family' => array( 'style', 'font_family' ),
        );
        foreach ( $scalars as $css => $target ) {
            if ( isset( $declarations[ $css ] ) ) { ${$target[0]}[ $target[1] ] = sanitize_text_field( $declarations[ $css ] ); }
        }
        foreach ( array( 'width' => 'width', 'max-width' => 'max_width', 'min-width' => 'min_width', 'min-height' => 'min_height', 'height' => 'height', 'font-size' => 'font_size', 'line-height' => 'line_height', 'letter-spacing' => 'letter_spacing', 'gap' => 'gap' ) as $css => $key ) {
            if ( ! isset( $declarations[ $css ] ) ) { continue; }
            $dimension = $this->dimension( $declarations[ $css ], $css );
            if ( null === $dimension ) { continue; }
            $bucket = in_array( $css, array( 'font-size', 'line-height', 'letter-spacing' ), true ) ? 'style' : 'layout';
            ${$bucket}[ $key ] = $dimension;
        }
        foreach ( array( 'padding', 'margin' ) as $css ) {
            if ( ! isset( $declarations[ $css ] ) ) { continue; }
            $box = $this->box( $declarations[ $css ] );
            if ( null !== $box ) { $spacing[ $css ] = $box; }
        }
        foreach ( array( 'padding-top' => array( 'padding', 'top' ), 'padding-right' => array( 'padding', 'right' ), 'padding-bottom' => array( 'padding', 'bottom' ), 'padding-left' => array( 'padding', 'left' ), 'margin-top' => array( 'margin', 'top' ), 'margin-right' => array( 'margin', 'right' ), 'margin-bottom' => array( 'margin', 'bottom' ), 'margin-left' => array( 'margin', 'left' ) ) as $css => $slot ) {
            if ( ! isset( $declarations[ $css ] ) ) { continue; }
            $dimension = $this->dimension( $declarations[ $css ] );
            if ( null === $dimension ) { continue; }
            // Partial side: the override is completed from the desktop box at
            // apply time, so a single side can never zero its siblings.
            $spacing[ $slot[0] ][ $slot[1] ] = $dimension;
        }
        if ( isset( $declarations['grid-template-columns'] ) ) {
            $columns = $this->grid_columns( $declarations['grid-template-columns'] );
            if ( 0 < $columns ) { $layout['columns'] = $columns; }
        }
        return array( 'layout' => $layout, 'style' => $style, 'spacing' => $spacing );
    }

    private function dimension( $value, $property = '' ) {
        $value = trim( (string) $value );
        if ( 'line-height' === $property && preg_match( '/^\s*([0-9.]+)\s*$/', $value, $m ) ) {
            // Unitless line-height is a ratio, not pixels: keep it marked so the
            // binder can resolve it against the element's own font size instead
            // of freezing a wrong px value here.
            return array( 'ratio' => (float) $m[1] );
        }
        if ( preg_match( '/^\s*(-?[0-9.]+)\s*(px|%|em|rem|vh|vw|vmin|vmax)?\s*$/i', $value, $m ) ) {
            return array( 'value' => (float) $m[1], 'unit' => strtolower( ( $m[2] ?? '' ) ?: 'px' ) );
        }
        return null;
    }

    private function box( $value ) {
        $parts = array_values( array_filter( preg_split( '/\s+/', trim( (string) $value ) ) ) );
        if ( ! $parts ) { return null; }
        if ( 1 === count( $parts ) ) { $parts = array( $parts[0], $parts[0], $parts[0], $parts[0] ); }
        elseif ( 2 === count( $parts ) ) { $parts = array( $parts[0], $parts[1], $parts[0], $parts[1] ); }
        elseif ( 3 === count( $parts ) ) { $parts = array( $parts[0], $parts[1], $parts[2], $parts[1] ); }
        elseif ( 4 !== count( $parts ) ) { return null; }
        $dims = array();
        foreach ( $parts as $part ) { $dims[] = $this->dimension( $part ); }
        foreach ( $dims as $dim ) { if ( null === $dim ) { return null; } }
        $units = array_unique( array_column( $dims, 'unit' ) );
        if ( 1 !== count( $units ) ) { return null; }
        return array( 'top' => $dims[0]['value'], 'right' => $dims[1]['value'], 'bottom' => $dims[2]['value'], 'left' => $dims[3]['value'], 'unit' => $units[0] );
    }

    private function grid_columns( $value ) {
        $value = trim( (string) $value );
        if ( preg_match( '/^repeat\(\s*(\d+)\s*,/i', $value, $match ) ) { return max( 1, min( 24, (int) $match[1] ) ); }
        if ( false !== stripos( $value, 'auto-fit' ) || false !== stripos( $value, 'auto-fill' ) ) { return 0; }
        $tracks = preg_split( '/\s+/', $value );
        $tracks = array_values( array_filter( $tracks ) );
        return ( $tracks && count( $tracks ) <= 24 ) ? count( $tracks ) : 0;
    }

    private function apply_override( array $node, $device, array $values ) {
        $desktop = array_merge( (array) ( $node['layout'] ?? array() ), (array) ( $node['style'] ?? array() ), (array) ( $node['spacing'] ?? array() ) );
        $merged = array( 'layout' => array(), 'style' => array(), 'spacing' => array() );
        $changed = false;
        foreach ( array( 'layout', 'style', 'spacing' ) as $bucket ) {
            foreach ( (array) ( $values[ $bucket ] ?? array() ) as $key => $value ) {
                // Complete a partial padding/margin side from the desktop box so
                // siblings keep their values instead of collapsing to zero.
                if ( in_array( $bucket, array( 'spacing' ), true ) && in_array( $key, array( 'padding', 'margin' ), true ) && is_array( $value ) ) {
                    $desk_box = ( $desktop[ $key ] ?? null );
                    if ( is_array( $desk_box ) && isset( $desk_box['unit'] ) ) {
                        foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
                            if ( ! array_key_exists( $side, $value ) && array_key_exists( $side, $desk_box ) ) { $value[ $side ] = $desk_box[ $side ]; }
                        }
                        if ( ! isset( $value['unit'] ) ) { $value['unit'] = $desk_box['unit']; }
                        // Mirror the desktop side shapes so binders never see a
                        // mixed scalar/dimension box.
                        foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
                            if ( is_array( $value[ $side ] ?? null ) && isset( $value[ $side ]['value'] ) && is_scalar( $desk_box[ $side ] ?? null ) && ( $value[ $side ]['unit'] ?? 'px' ) === ( $desk_box['unit'] ?? 'px' ) ) {
                                $value[ $side ] = $value[ $side ]['value'];
                            }
                        }
                    }
                    if ( ! isset( $value['top'], $value['right'], $value['bottom'], $value['left'], $value['unit'] ) ) { continue; }
                }
                if ( array_key_exists( $key, $desktop ) && $desktop[ $key ] === $value ) { continue; }
                $merged[ $bucket ][ $key ] = $value;
                $changed = true;
            }
        }
        if ( ! $changed ) { return null; }
        $responsive = is_array( $node['responsive'] ?? null ) ? $node['responsive'] : array();
        foreach ( $merged as $bucket => $entries ) {
            foreach ( $entries as $key => $value ) { $responsive[ $device ][ $bucket ][ $key ] = $value; }
        }
        $node['responsive'] = $responsive;
        return $node;
    }
}

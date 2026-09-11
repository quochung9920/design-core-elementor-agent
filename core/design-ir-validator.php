<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Design_IR_Validator {
    private $units = array( 'px', '%', 'em', 'rem', 'vh', 'svh', 'dvh', 'lvh', 'vw', 'vmin', 'vmax', 'ch', 'ex', 'auto', '' );
    private $responsive_states = array( 'desktop', 'widescreen', 'laptop', 'tablet_extra', 'tablet', 'mobile_extra', 'mobile' );

    public function validate( $ir ) {
        if ( ! is_array( $ir ) ) { throw new InvalidArgumentException( 'DesignIR must be an array.' ); }
        if ( Design_Core_Elementor_Design_IR::SCHEMA_VERSION !== ( $ir['schema_version'] ?? null ) ) { throw new InvalidArgumentException( 'DesignIR schema version is unsupported.' ); }
        if ( 'design-ir' !== ( $ir['type'] ?? null ) ) { throw new InvalidArgumentException( 'DesignIR type is invalid.' ); }
        if ( ! isset( $ir['nodes'] ) || ! is_array( $ir['nodes'] ) || ! isset( $ir['root_ids'] ) || ! is_array( $ir['root_ids'] ) ) { throw new InvalidArgumentException( 'DesignIR nodes and root_ids are required.' ); }
        $by_id = array();
        foreach ( $ir['nodes'] as $node ) {
            $this->validate_node( $node );
            $id = $node['id'];
            if ( isset( $by_id[ $id ] ) ) { throw new InvalidArgumentException( 'DesignIR node IDs must be unique.' ); }
            $by_id[ $id ] = $node;
        }
        if ( count( $ir['root_ids'] ) !== count( array_unique( $ir['root_ids'] ) ) ) { throw new InvalidArgumentException( 'DesignIR root IDs must be unique.' ); }
        foreach ( $ir['root_ids'] as $root_id ) { if ( ! is_string( $root_id ) || ! isset( $by_id[ $root_id ] ) ) { throw new InvalidArgumentException( 'DesignIR root reference is invalid.' ); } }
        $parents = array_fill_keys( array_keys( $by_id ), 0 ); $parent_ids = array();
        foreach ( $by_id as $node ) { foreach ( $node['children'] as $child_id ) { if ( ! is_string( $child_id ) || ! isset( $by_id[ $child_id ] ) ) { throw new InvalidArgumentException( 'DesignIR child reference is invalid.' ); } $parents[ $child_id ]++; $parent_ids[ $child_id ] = $node['id']; if ( 1 < $parents[ $child_id ] ) { throw new InvalidArgumentException( 'DesignIR nodes cannot have multiple parents.' ); } } }
        $roots = array_fill_keys( $ir['root_ids'], true );
        foreach ( $parents as $id => $count ) { if ( isset( $roots[ $id ] ) ? 0 !== $count : 1 !== $count ) { throw new InvalidArgumentException( 'DesignIR hierarchy contains an orphan or invalid root.' ); } }
        $this->validate_gap_first_spacing( $by_id, $parent_ids );
        $this->validate_layout_ownership( $by_id, $parent_ids );
        $visiting = array(); $visited = array();
        foreach ( array_keys( $by_id ) as $id ) { $this->visit( $id, $by_id, $visiting, $visited ); }
        return true;
    }

    private function validate_node( $node ) {
        if ( ! is_array( $node ) || ! isset( $node['id'] ) || ! is_string( $node['id'] ) || '' === trim( $node['id'] ) ) { throw new InvalidArgumentException( 'DesignIR node ID is invalid.' ); }
        foreach ( array( 'source', 'semantic', 'content', 'layout', 'style', 'spacing', 'responsive', 'assets', 'interaction', 'component' ) as $field ) {
            if ( ! isset( $node[ $field ] ) || ! is_array( $node[ $field ] ) ) { throw new InvalidArgumentException( 'DesignIR node ' . $field . ' must be an array.' ); }
        }
        if ( ! isset( $node['children'] ) || ! is_array( $node['children'] ) ) { throw new InvalidArgumentException( 'DesignIR node children must be an array.' ); }
        if ( isset( $node['spacing_ownership'] ) ) {
            if ( ! is_array( $node['spacing_ownership'] ) ) { throw new InvalidArgumentException( 'DesignIR spacing_ownership must be an array.' ); }
            if ( ! empty( $node['spacing_ownership']['margin_exception'] ) && '' === trim( (string) ( $node['spacing_ownership']['reason'] ?? '' ) ) ) { throw new InvalidArgumentException( 'DesignIR margin exception requires a non-empty reason.' ); }
        }
        $layout_governance = is_array( $node['layout_governance'] ?? null ) ? $node['layout_governance'] : array();
        if ( ( ! empty( $node['semantic']['fixed_width_violation'] ) || $this->has_raw_fixed_width_risk( $node ) ) && ( empty( $layout_governance['fixed_width_exception'] ) || '' === trim( (string) ( $layout_governance['reason'] ?? '' ) ) ) ) {
            throw new InvalidArgumentException( 'DesignIR fixed-width policy forbids unexplained large structural width on node ' . $node['id'] . '.' );
        }
        if ( isset( $node['media'] ) && ! is_array( $node['media'] ) ) { throw new InvalidArgumentException( 'DesignIR media must be an array.' ); }
        $critical_media_warnings = array_intersect(
            array_unique( array_merge( (array) ( $node['media']['warnings'] ?? array() ), $this->derive_raw_height_risks( $node ) ) ),
            array( 'fixed-height-100vh-content-clipping-risk', 'fixed-height-100vh-content-growth-risk', 'fixed-pixel-height-responsive-risk', 'dynamic-viewport-height-layout-shift-risk' )
        );
        if ( $critical_media_warnings ) {
            $media_governance = is_array( $node['media_governance'] ?? null ) ? $node['media_governance'] : array();
            if ( empty( $media_governance['height_exception'] ) || '' === trim( (string) ( $media_governance['reason'] ?? '' ) ) ) { throw new InvalidArgumentException( 'DesignIR media-height policy rejects unsafe viewport height on node ' . $node['id'] . '.' ); }
        }
        $this->assert_platform_neutral( $node );
        $this->validate_values( $node['layout'] ); $this->validate_values( $node['style'] ); $this->validate_values( $node['spacing'] );
        foreach ( $node['responsive'] as $state => $values ) {
            if ( ! in_array( $state, $this->responsive_states, true ) || ! is_array( $values ) ) { throw new InvalidArgumentException( 'DesignIR responsive state is invalid.' ); }
            $this->validate_values( $values );
        }
        $fingerprint = $node['component']['fingerprint'] ?? array();
        if ( ! is_array( $fingerprint ) || 2 !== ( $fingerprint['version'] ?? null ) ) { throw new InvalidArgumentException( 'DesignIR component fingerprint v2 is required.' ); }
        foreach ( array( 'semantic', 'structure', 'layout', 'interaction' ) as $field ) { if ( ! isset( $fingerprint[ $field ] ) || ! is_string( $fingerprint[ $field ] ) ) { throw new InvalidArgumentException( 'DesignIR fingerprint field is invalid: ' . $field ); } }
        if ( ! isset( $fingerprint['content_schema'] ) || ! is_array( $fingerprint['content_schema'] ) ) { throw new InvalidArgumentException( 'DesignIR fingerprint content_schema is invalid.' ); }
    }

    private function assert_platform_neutral( $value ) {
        if ( ! is_array( $value ) ) { return; }
        foreach ( $value as $key => $nested ) {
            if ( is_string( $key ) && in_array( $key, array( 'elType', 'widgetType', 'settings', '__globals__' ), true ) ) { throw new InvalidArgumentException( 'Elementor platform fields are forbidden in DesignIR.' ); }
            $this->assert_platform_neutral( $nested );
        }
    }

    private function validate_values( $values ) {
        foreach ( $values as $value ) {
            if ( is_array( $value ) && array_key_exists( 'value', $value ) ) {
                if ( ! is_numeric( $value['value'] ) || ! isset( $value['unit'] ) || ! is_string( $value['unit'] ) || ! in_array( strtolower( $value['unit'] ), $this->units, true ) ) { throw new InvalidArgumentException( 'DesignIR numeric/unit value is invalid.' ); }
            } elseif ( is_array( $value ) ) { $this->validate_values( $value ); }
        }
    }

    private function has_raw_fixed_width_risk( array $node ) {
        $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );
        if ( ! in_array( $tag, array( 'section', 'div', 'main', 'header', 'footer', 'article', 'aside', 'nav' ), true ) ) { return false; }
        foreach ( $this->responsive_value_sets( $node ) as $values ) {
            foreach ( array( $values['layout']['width'] ?? null, $values['style']['css_fallback']['width'] ?? null ) as $candidate ) {
                $dimension = $this->normalize_dimension( $candidate );
                if ( in_array( $dimension['unit'], array( 'px', 'px-expression' ), true ) && 320 < $dimension['value'] ) { return true; }
            }
        }
        return false;
    }

    private function derive_raw_height_risks( array $node ) {
        $warnings = array();
        foreach ( $this->responsive_value_sets( $node ) as $values ) {
            $height = $values['layout']['height'] ?? ( $values['style']['css_fallback']['height'] ?? null );
            $dimension = $this->normalize_dimension( $height );
            $raw = strtolower( trim( $dimension['raw'] ) );
            if ( '' === $raw || 'auto' === $raw ) { continue; }
            $overflow = strtolower( (string) ( $values['style']['css_fallback']['overflow'] ?? ( $values['layout']['overflow'] ?? '' ) ) );
            if ( preg_match( '/(?:^|[^a-z])(?:[0-9.]+)?dvh\b/i', $raw ) ) {
                $warnings[] = 'dynamic-viewport-height-layout-shift-risk';
            } elseif ( preg_match( '/(?:^|[^a-z])(?:[0-9.]+)?(?:svh|lvh|vh)\b/i', $raw ) ) {
                $warnings[] = 'hidden' === $overflow ? 'fixed-height-100vh-content-clipping-risk' : 'fixed-height-100vh-content-growth-risk';
            } elseif ( in_array( $dimension['unit'], array( 'px', 'px-expression' ), true ) && 320 < $dimension['value'] ) {
                $warnings[] = 'fixed-pixel-height-responsive-risk';
            }
        }
        return array_values( array_unique( $warnings ) );
    }

    private function responsive_value_sets( array $node ) {
        $sets = array( array( 'layout' => (array) ( $node['layout'] ?? array() ), 'style' => (array) ( $node['style'] ?? array() ) ) );
        foreach ( $node['responsive'] ?? array() as $responsive ) {
            if ( ! is_array( $responsive ) ) { continue; }
            $sets[] = array( 'layout' => (array) ( $responsive['layout'] ?? array() ), 'style' => (array) ( $responsive['style'] ?? array() ) );
        }
        return $sets;
    }

    private function normalize_dimension( $value ) {
        if ( is_array( $value ) && array_key_exists( 'value', $value ) ) {
            return array( 'value' => (float) $value['value'], 'unit' => strtolower( (string) ( $value['unit'] ?? '' ) ), 'raw' => (string) $value['value'] . (string) ( $value['unit'] ?? '' ) );
        }
        $raw = is_scalar( $value ) ? trim( (string) $value ) : '';
        if ( preg_match( '/^(-?[0-9.]+)([a-z%]*)$/i', $raw, $match ) ) { return array( 'value' => (float) $match[1], 'unit' => strtolower( $match[2] ), 'raw' => $raw ); }
        $constant_pixels = self::constant_pixel_value( $raw );
        if ( null !== $constant_pixels ) { return array( 'value' => $constant_pixels, 'unit' => 'px-expression', 'raw' => $raw ); }
        return array( 'value' => 0.0, 'unit' => '', 'raw' => $raw );
    }

    public static function constant_pixel_value( $raw ) {
        $raw = trim( (string) $raw );
        if ( preg_match( '/^(-?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+))px$/i', $raw, $plain ) ) { return (float) $plain[1]; }
        if ( ! preg_match( '/^(calc|min|max|clamp)\((.*)\)$/is', $raw, $function ) ) { return null; }
        $name = strtolower( $function[1] ); $inner = trim( $function[2] );
        if ( 'calc' === $name ) {
            $nested = self::constant_pixel_value( $inner );
            return null !== $nested ? $nested : self::evaluate_pixel_arithmetic( $inner );
        }
        $parts = self::split_css_arguments( $inner ); $values = array();
        foreach ( $parts as $part ) { $value = self::constant_pixel_value( $part ); if ( null === $value ) { return null; } $values[] = $value; }
        if ( 'min' === $name && $values ) { return min( $values ); }
        if ( 'max' === $name && $values ) { return max( $values ); }
        if ( 'clamp' === $name && 3 === count( $values ) ) { return max( $values[0], min( $values[1], $values[2] ) ); }
        return null;
    }

    private static function split_css_arguments( $source ) {
        $parts = array(); $depth = 0; $start = 0; $length = strlen( $source );
        for ( $index = 0; $index < $length; $index++ ) {
            if ( '(' === $source[ $index ] ) { $depth++; }
            elseif ( ')' === $source[ $index ] ) { $depth--; if ( 0 > $depth ) { return array(); } }
            elseif ( ',' === $source[ $index ] && 0 === $depth ) { $parts[] = trim( substr( $source, $start, $index - $start ) ); $start = $index + 1; }
        }
        if ( 0 !== $depth ) { return array(); }
        $parts[] = trim( substr( $source, $start ) ); return array_values( array_filter( $parts, static function ( $part ) { return '' !== $part; } ) );
    }

    private static function evaluate_pixel_arithmetic( $source ) {
        if ( false === stripos( $source, 'px' ) ) { return null; }
        $expression = preg_replace( '/(?<=\d)px\b/i', '', trim( $source ) );
        if ( ! is_string( $expression ) || preg_match( '/[^0-9.\s()+\-*\/]/', $expression ) ) { return null; }
        $index = 0; $ok = true; $value = self::parse_pixel_expression( $expression, $index, $ok ); self::skip_pixel_space( $expression, $index );
        return $ok && $index === strlen( $expression ) && is_finite( $value ) ? $value : null;
    }

    private static function parse_pixel_expression( $source, &$index, &$ok ) {
        $value = self::parse_pixel_term( $source, $index, $ok );
        while ( $ok ) { self::skip_pixel_space( $source, $index ); $operator = $source[ $index ] ?? ''; if ( ! in_array( $operator, array( '+', '-' ), true ) ) { break; } $index++; $right = self::parse_pixel_term( $source, $index, $ok ); $value = '+' === $operator ? $value + $right : $value - $right; }
        return $value;
    }

    private static function parse_pixel_term( $source, &$index, &$ok ) {
        $value = self::parse_pixel_factor( $source, $index, $ok );
        while ( $ok ) { self::skip_pixel_space( $source, $index ); $operator = $source[ $index ] ?? ''; if ( ! in_array( $operator, array( '*', '/' ), true ) ) { break; } $index++; $right = self::parse_pixel_factor( $source, $index, $ok ); if ( '/' === $operator && 0.0 === (float) $right ) { $ok = false; return 0.0; } $value = '*' === $operator ? $value * $right : $value / $right; }
        return $value;
    }

    private static function parse_pixel_factor( $source, &$index, &$ok ) {
        self::skip_pixel_space( $source, $index ); $character = $source[ $index ] ?? '';
        if ( '+' === $character || '-' === $character ) { $index++; $value = self::parse_pixel_factor( $source, $index, $ok ); return '-' === $character ? -$value : $value; }
        if ( '(' === $character ) { $index++; $value = self::parse_pixel_expression( $source, $index, $ok ); self::skip_pixel_space( $source, $index ); if ( ')' !== ( $source[ $index ] ?? '' ) ) { $ok = false; return 0.0; } $index++; return $value; }
        if ( ! preg_match( '/^(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)/', substr( $source, $index ), $number ) ) { $ok = false; return 0.0; }
        $index += strlen( $number[0] ); return (float) $number[0];
    }

    private static function skip_pixel_space( $source, &$index ) { $length = strlen( $source ); while ( $index < $length && ctype_space( $source[ $index ] ) ) { $index++; } }

    private function validate_layout_ownership( array $nodes, array $parent_ids ) {
        foreach ( $nodes as $id => $node ) {
            $semantic = is_array( $node['semantic'] ?? null ) ? $node['semantic'] : array();
            $mode = (string) ( $semantic['layout_mode'] ?? '' ); $role = (string) ( $semantic['container_role'] ?? '' );
            if ( 'content' === $role ) {
                $parent_id = $parent_ids[ $id ] ?? '';
                $parent_semantic = $parent_id && isset( $nodes[ $parent_id ] ) ? (array) ( $nodes[ $parent_id ]['semantic'] ?? array() ) : array();
                if ( ! $parent_id || ! in_array( $parent_semantic['layout_mode'] ?? '', array( 'fullwidth', 'mixed' ), true ) || ! in_array( $parent_semantic['container_role'] ?? '', array( 'surface', 'structural' ), true ) ) {
                    throw new InvalidArgumentException( 'DesignIR global container ownership requires a governed parent surface on node ' . $id . '.' );
                }
            }
            if ( 'fullwidth' === $mode && 'surface' !== $role ) { throw new InvalidArgumentException( 'DesignIR container ownership is inconsistent for fullwidth node ' . $id . '.' ); }
            if ( 'fullwidth-content' === $mode && 'surface-content' !== $role ) { throw new InvalidArgumentException( 'DesignIR container ownership is inconsistent for fullwidth-content node ' . $id . '.' ); }
            if ( 'boxed' === $mode && ! in_array( $role, array( 'surface-content', 'content' ), true ) ) { throw new InvalidArgumentException( 'DesignIR container ownership is inconsistent for boxed node ' . $id . '.' ); }

            $content_width = (string) ( $node['layout']['content_width'] ?? '' );
            $boxed_owner = 'content' === $role || ( 'boxed' === $mode && 'surface-content' === $role );
            $full_owner = ( 'fullwidth' === $mode && 'surface' === $role ) || ( 'fullwidth-content' === $mode && 'surface-content' === $role );
            if ( ( $boxed_owner && 'full' === $content_width ) || ( $full_owner && 'boxed' === $content_width ) ) {
                throw new InvalidArgumentException( 'DesignIR content width semantics conflict with layout ownership on node ' . $id . '.' );
            }
        }
    }

    private function validate_gap_first_spacing( array $nodes, array $parent_ids ) {
        foreach ( $parent_ids as $child_id => $parent_id ) {
            $node = $nodes[ $child_id ]; $parent = $nodes[ $parent_id ];
            if ( count( $parent['children'] ?? array() ) < 2 || ! in_array( $parent['layout']['direction'] ?? '', array( 'row', 'column' ), true ) ) { continue; }
            if ( ! $this->has_nonzero_margin( $node ) ) { continue; }
            $ownership = is_array( $node['spacing_ownership'] ?? null ) ? $node['spacing_ownership'] : array();
            if ( ! empty( $ownership['margin_exception'] ) && '' !== trim( (string) ( $ownership['reason'] ?? '' ) ) ) { continue; }
            throw new InvalidArgumentException( 'Gap-first spacing policy forbids unexplained non-zero child margin on node ' . $child_id . '; use parent Gap or provide spacing_ownership.margin_exception with a reason.' );
        }
    }

    private function has_nonzero_margin( array $node ) {
        $states = array( is_array( $node['spacing'] ?? null ) ? $node['spacing'] : array() );
        foreach ( $node['responsive'] ?? array() as $responsive ) {
            if ( is_array( $responsive ) ) { $states[] = is_array( $responsive['spacing'] ?? null ) ? $responsive['spacing'] : array(); }
        }
        foreach ( $states as $spacing ) {
            if ( ! is_array( $spacing['margin'] ?? null ) ) { continue; }
            foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
                if ( 0.0 !== (float) ( $spacing['margin'][ $side ] ?? 0 ) ) { return true; }
            }
        }
        return false;
    }

    private function visit( $id, $nodes, &$visiting, &$visited ) {
        if ( isset( $visiting[ $id ] ) ) { throw new InvalidArgumentException( 'DesignIR hierarchy contains a cycle.' ); }
        if ( isset( $visited[ $id ] ) ) { return; }
        $visiting[ $id ] = true;
        foreach ( $nodes[ $id ]['children'] as $child_id ) { $this->visit( $child_id, $nodes, $visiting, $visited ); }
        unset( $visiting[ $id ] ); $visited[ $id ] = true;
    }
}

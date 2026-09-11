<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Hierarchical token compiler inspired by the transform/reference model used by
 * Style Dictionary. This is an original PHP implementation: WordPress has no
 * Node.js runtime dependency and the upstream project is not bundled.
 */
class Design_Core_Elementor_Design_Token_Pipeline {
    const VERSION = 1;
    const MAX_TOKENS = 4096;
    const MAX_DEPTH = 32;

    private $flat = array();
    private $resolved = array();
    private $resolving = array();
    private $warnings = array();

    public static function looks_like_dictionary( $source ) {
        if ( ! is_array( $source ) ) { return false; }
        if ( isset( $source['$schema'] ) || isset( $source['$type'] ) ) { return true; }
        return self::contains_token_leaf( $source, 0 );
    }

    public function compile( array $source ) {
        $this->flat = array(); $this->resolved = array(); $this->resolving = array(); $this->warnings = array();
        $this->flatten( $source, array(), '', 0 );
        if ( count( $this->flat ) > self::MAX_TOKENS ) { throw new OverflowException( 'Design token dictionary exceeds the bounded token limit.' ); }
        foreach ( array_keys( $this->flat ) as $path ) { $this->resolve( $path ); }

        $tokens = array( 'colors'=>array(), 'typography'=>array(), 'spacing'=>array(), 'radius'=>array() );
        foreach ( $this->resolved as $path => $entry ) {
            $this->map_to_design_core( $path, $entry, $tokens );
        }
        return array(
            'schema_version' => self::VERSION,
            'engine' => 'design-core-token-pipeline',
            'dictionary' => $this->resolved,
            'tokens' => $tokens,
            'warnings' => array_values( array_unique( $this->warnings ) ),
        );
    }

    public function to_css_variables( array $compiled, $prefix = 'dc' ) {
        $prefix = sanitize_key( (string) $prefix ) ?: 'dc';
        $lines = array();
        foreach ( (array) ( $compiled['dictionary'] ?? array() ) as $path => $entry ) {
            $value = $entry['value'] ?? null;
            if ( ! is_scalar( $value ) && null !== $value ) { continue; }
            $name = trim( preg_replace( '/[^a-z0-9_-]+/i', '-', str_replace( '.', '-', (string) $path ) ), '-' );
            if ( '' === $name ) { continue; }
            $lines[] = '--' . $prefix . '-' . strtolower( $name ) . ': ' . (string) $value . ';';
        }
        return $lines ? ":root {\n  " . implode( "\n  ", $lines ) . "\n}" : ':root {}';
    }

    private static function contains_token_leaf( array $value, $depth ) {
        if ( $depth > 8 ) { return false; }
        if ( array_key_exists( '$value', $value ) || ( array_key_exists( 'value', $value ) && ( isset( $value['type'] ) || isset( $value['$type'] ) ) ) ) { return true; }
        foreach ( $value as $key => $child ) {
            if ( is_string( $key ) && 0 === strpos( $key, '$' ) ) { continue; }
            if ( is_array( $child ) && self::contains_token_leaf( $child, $depth + 1 ) ) { return true; }
        }
        return false;
    }

    private function flatten( array $node, array $segments, $inherited_type, $depth ) {
        if ( $depth > self::MAX_DEPTH ) { throw new OverflowException( 'Design token nesting exceeds the bounded depth limit.' ); }
        $group_type = sanitize_key( (string) ( $node['$type'] ?? $node['type'] ?? $inherited_type ) );
        if ( array_key_exists( '$value', $node ) || ( array_key_exists( 'value', $node ) && ( isset( $node['type'] ) || isset( $node['$type'] ) ) ) ) {
            $path = implode( '.', array_map( 'sanitize_key', $segments ) );
            if ( '' === $path ) { throw new InvalidArgumentException( 'A design token leaf requires a named path.' ); }
            $this->flat[ $path ] = array(
                'type' => $group_type,
                'raw' => array_key_exists( '$value', $node ) ? $node['$value'] : $node['value'],
                'description' => sanitize_text_field( (string) ( $node['$description'] ?? $node['description'] ?? '' ) ),
            );
            return;
        }
        foreach ( $node as $name => $child ) {
            if ( ! is_string( $name ) || 0 === strpos( $name, '$' ) || in_array( $name, array( 'type', 'description' ), true ) || ! is_array( $child ) ) { continue; }
            $next = $segments; $next[] = $name;
            $this->flatten( $child, $next, $group_type, $depth + 1 );
        }
    }

    private function resolve( $path ) {
        if ( isset( $this->resolved[ $path ] ) ) { return $this->resolved[ $path ]['value']; }
        if ( isset( $this->resolving[ $path ] ) ) { throw new RuntimeException( 'Circular design token reference detected at ' . $path . '.' ); }
        if ( ! isset( $this->flat[ $path ] ) ) { throw new InvalidArgumentException( 'Unknown design token reference: ' . $path ); }
        $this->resolving[ $path ] = true;
        $entry = $this->flat[ $path ];
        $value = $this->resolve_value( $entry['raw'], $path );
        unset( $this->resolving[ $path ] );
        $this->resolved[ $path ] = array( 'type'=>$entry['type'], 'value'=>$value, 'description'=>$entry['description'] );
        return $value;
    }

    private function resolve_value( $value, $owner ) {
        if ( is_string( $value ) && preg_match( '/^\{([a-zA-Z0-9_.-]+)\}$/', trim( $value ), $match ) ) { return $this->resolve( sanitize_text_field( $match[1] ) ); }
        if ( is_string( $value ) && false !== strpos( $value, '{' ) ) {
            return preg_replace_callback( '/\{([a-zA-Z0-9_.-]+)\}/', function ( $match ) use ( $owner ) {
                $resolved = $this->resolve( sanitize_text_field( $match[1] ) );
                if ( is_scalar( $resolved ) || null === $resolved ) { return (string) $resolved; }
                $this->warnings[] = 'Token ' . $owner . ' contains a non-scalar interpolated reference that was not expanded.';
                return $match[0];
            }, $value );
        }
        if ( is_array( $value ) ) { $out = array(); foreach ( $value as $key => $item ) { $out[ $key ] = $this->resolve_value( $item, $owner ); } return $out; }
        return $value;
    }

    private function map_to_design_core( $path, array $entry, array &$tokens ) {
        $parts = explode( '.', $path );
        $root = sanitize_key( $parts[0] ?? '' );
        $name = sanitize_key( implode( '-', array_slice( $parts, 1 ) ) ?: basename( str_replace( '.', '/', $path ) ) );
        $type = sanitize_key( (string) ( $entry['type'] ?? '' ) );
        $value = $entry['value'] ?? null;

        // The authored semantic namespace wins over generic DTCG types. For example,
        // radius.md and typography.size.hero may both carry type=dimension but must not
        // be reclassified as spacing merely because all three are dimensions.
        if ( in_array( $root, array( 'color', 'colors' ), true ) ) {
            $this->map_color( $path, $name, $value, $tokens ); return;
        }
        if ( in_array( $root, array( 'radius', 'border-radius' ), true ) ) {
            $number = $this->dimension_number( $value, $path ); if ( null !== $number ) { $tokens['radius'][ $name ] = $number; } return;
        }
        if ( in_array( $root, array( 'space', 'spacing' ), true ) ) {
            $number = $this->dimension_number( $value, $path ); if ( null !== $number ) { $tokens['spacing'][ $name ] = $number; } return;
        }
        if ( in_array( $root, array( 'font', 'typography', 'type' ), true ) ) {
            $tokens['typography'][ $name ] = is_array( $value ) ? $value : sanitize_text_field( (string) $value ); return;
        }

        // Type-only fallbacks are used only when the dictionary has no recognized
        // Design Core semantic namespace.
        if ( 'color' === $type ) { $this->map_color( $path, $name, $value, $tokens ); return; }
        if ( in_array( $type, array( 'radius', 'border-radius' ), true ) ) {
            $number = $this->dimension_number( $value, $path ); if ( null !== $number ) { $tokens['radius'][ $name ] = $number; } return;
        }
        if ( 'spacing' === $type ) {
            $number = $this->dimension_number( $value, $path ); if ( null !== $number ) { $tokens['spacing'][ $name ] = $number; } return;
        }
        if ( 'typography' === $type ) {
            $tokens['typography'][ $name ] = is_array( $value ) ? $value : sanitize_text_field( (string) $value ); return;
        }
        if ( 'dimension' === $type ) {
            $this->warnings[] = 'Dimension token ' . $path . ' has no recognized semantic namespace; it was preserved in the compiled dictionary but not guessed into spacing/radius/typography.';
        }
    }

    private function map_color( $path, $name, $value, array &$tokens ) {
        if ( is_string( $value ) && sanitize_hex_color( $value ) ) { $tokens['colors'][ $name ] = sanitize_hex_color( $value ); }
        else { $this->warnings[] = 'Color token ' . $path . ' is not a supported hex color for Elementor globals.'; }
    }

    private function dimension_number( $value, $path ) {
        if ( is_numeric( $value ) ) { return (float) $value; }
        if ( is_array( $value ) && isset( $value['value'] ) && is_numeric( $value['value'] ) ) {
            $unit = strtolower( (string) ( $value['unit'] ?? 'px' ) );
            if ( 'px' !== $unit && '' !== $unit ) { $this->warnings[] = 'Token ' . $path . ' uses ' . $unit . '; Design Core preserves no implicit unit conversion.'; return null; }
            return (float) $value['value'];
        }
        if ( is_string( $value ) && preg_match( '/^(-?\d+(?:\.\d+)?)px$/i', trim( $value ), $match ) ) { return (float) $match[1]; }
        $this->warnings[] = 'Dimension token ' . $path . ' could not be normalized to an Elementor numeric token.';
        return null;
    }
}

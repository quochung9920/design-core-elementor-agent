<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Responsive_Normalizer {
    public function normalize( $ir ) {
        foreach ( $ir['nodes'] as $index => $node ) {
            $normalized = array();
            $previous = array_merge( (array) $node['layout'], (array) $node['style'], (array) $node['spacing'] );
            foreach ( array( 'widescreen', 'laptop', 'tablet_extra', 'tablet', 'mobile_extra', 'mobile' ) as $state ) {
                if ( ! isset( $node['responsive'][ $state ] ) || ! is_array( $node['responsive'][ $state ] ) ) { continue; }
                $current = $this->flatten( $node['responsive'][ $state ] );
                $delta = $this->delta( $current, $previous );
                if ( $delta ) { $normalized[ $state ] = $delta; $previous = array_replace( $previous, $delta ); }
            }
            $ir['nodes'][ $index ]['responsive'] = $normalized;
        }
        return $ir;
    }

    /** Accept flat or partial {layout,style,spacing} responsive groups. */
    private function flatten( $values ) {
        if ( ! is_array( $values ) ) { return array(); }
        $grouped = false; $flat = array();
        foreach ( array( 'layout', 'style', 'spacing' ) as $group ) {
            if ( ! array_key_exists( $group, $values ) ) { continue; }
            $grouped = true;
            if ( is_array( $values[ $group ] ) ) { $flat = array_merge( $flat, $values[ $group ] ); }
        }
        if ( ! $grouped ) { return $values; }
        foreach ( $values as $key => $value ) {
            if ( in_array( $key, array( 'layout', 'style', 'spacing' ), true ) ) { continue; }
            $flat[ $key ] = $value;
        }
        return $flat;
    }

    private function delta( $current, $inherited ) {
        $delta = array();
        foreach ( $current as $key => $value ) { if ( ! array_key_exists( $key, $inherited ) || $value !== $inherited[ $key ] ) { $delta[ $key ] = $value; } }
        return $delta;
    }
}

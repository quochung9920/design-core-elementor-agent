<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Turns visual/compiler failures into stable generic signatures. */
class Design_Core_Elementor_Failure_Signature_Engine {
    const VERSION = 1;

    public function signature( array $context ) {
        $source = sanitize_key( (string) ( $context['source_type'] ?? $context['source'] ?? 'generic' ) );
        $category = sanitize_key( (string) ( $context['category'] ?? $context['failure'] ?? $context['issue'] ?? 'unknown' ) );
        $node_type = sanitize_key( (string) ( $context['node_type'] ?? '' ) );
        $parent_role = sanitize_key( (string) ( $context['parent_role'] ?? $context['component_role'] ?? '' ) );
        $asset = sanitize_key( (string) ( $context['asset_type'] ?? '' ) );
        $property = sanitize_key( (string) ( $context['property'] ?? '' ) );

        if ( $this->is_vector_asset_loss( $context ) ) { return $source . '.vector-component.asset-loss'; }
        if ( $this->is_small_primitive_stretch( $context ) ) { return $source . '.small-primitive.flex-stretch'; }
        if ( $this->is_mixed_text_failure( $context ) ) { return $source . '.mixed-text.composition'; }
        if ( $this->is_absolute_anchor_failure( $context ) ) { return $source . '.absolute.anchor-loss'; }
        if ( $this->is_fill_sizing_failure( $context ) ) { return $source . '.fill-sizing.flex-loss'; }
        if ( $this->is_image_crop_failure( $context ) ) { return $source . '.image.crop-position'; }
        if ( $this->is_font_failure( $context ) ) { return $source . '.font.fidelity'; }

        $parts = array_filter( array( $source, $category, $node_type, $parent_role, $asset, $property ) );
        return implode( '.', array_slice( $parts, 0, 6 ) ) ?: 'generic.unknown';
    }

    public function from_directive( array $directive, array $context = array() ) {
        $merged = array_merge( $context, array(
            'category' => $directive['category'] ?? $directive['type'] ?? $context['category'] ?? '',
            'property' => $directive['property'] ?? $directive['target_property'] ?? $context['property'] ?? '',
            'node_type' => $directive['node_type'] ?? $context['node_type'] ?? '',
            'component_role' => $directive['component_role'] ?? $context['component_role'] ?? '',
        ) );
        return $this->signature( $merged );
    }

    public function tags( array $context ) {
        $tags = array();
        foreach ( array( 'source_type', 'category', 'node_type', 'parent_role', 'component_role', 'asset_type', 'property', 'widget_type' ) as $key ) {
            $value = sanitize_key( (string) ( $context[ $key ] ?? '' ) );
            if ( $value ) { $tags[] = $key . ':' . $value; }
        }
        return array_values( array_unique( $tags ) );
    }

    private function is_vector_asset_loss( array $c ) {
        $asset = strtolower( (string) ( $c['asset_type'] ?? '' ) );
        $failure = strtolower( (string) ( $c['category'] ?? $c['failure'] ?? '' ) );
        return in_array( $asset, array( 'svg', 'vector', 'icon' ), true ) && ( false !== strpos( $failure, 'asset' ) || false !== strpos( $failure, 'container' ) || false !== strpos( $failure, 'missing' ) );
    }

    private function is_small_primitive_stretch( array $c ) {
        $w = (float) ( $c['reference_width'] ?? $c['width'] ?? 0 );
        $h = (float) ( $c['reference_height'] ?? $c['height'] ?? 0 );
        $candidate_w = (float) ( $c['candidate_width'] ?? 0 );
        $candidate_h = (float) ( $c['candidate_height'] ?? 0 );
        $small = $w > 0 && $h > 0 && $w <= 32 && $h <= 32;
        $stretched = $candidate_w > max( 64, $w * 2.5 ) || $candidate_h > max( 64, $h * 2.5 );
        return $small && $stretched;
    }

    private function is_mixed_text_failure( array $c ) {
        $category = strtolower( (string) ( $c['category'] ?? '' ) );
        return ! empty( $c['mixed_typography'] ) || false !== strpos( $category, 'mixed-text' ) || false !== strpos( $category, 'text-composition' );
    }

    private function is_absolute_anchor_failure( array $c ) {
        $position = strtolower( (string) ( $c['position'] ?? '' ) );
        $category = strtolower( (string) ( $c['category'] ?? '' ) );
        return 'absolute' === $position && ( ! empty( $c['anchor_mismatch'] ) || false !== strpos( $category, 'position' ) || false !== strpos( $category, 'anchor' ) );
    }

    private function is_fill_sizing_failure( array $c ) {
        $mode = strtoupper( (string) ( $c['figma_sizing'] ?? $c['sizing'] ?? '' ) );
        $category = strtolower( (string) ( $c['category'] ?? '' ) );
        return 'FILL' === $mode && ( false !== strpos( $category, 'width' ) || false !== strpos( $category, 'layout' ) || false !== strpos( $category, 'flex' ) );
    }

    private function is_image_crop_failure( array $c ) {
        $category = strtolower( (string) ( $c['category'] ?? '' ) );
        return false !== strpos( $category, 'crop' ) || false !== strpos( $category, 'object-position' ) || false !== strpos( $category, 'background-position' );
    }

    private function is_font_failure( array $c ) {
        $category = strtolower( (string) ( $c['category'] ?? '' ) );
        return ! empty( $c['font_missing'] ) || false !== strpos( $category, 'font' );
    }
}

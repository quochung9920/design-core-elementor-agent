<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Variant_Engine {
    public function classify( $component, $match ) {
        $score = (float) ( $match['score'] ?? 0 );
        $item = $match['item'] ?? array();
        $reasons = $match['reasons'] ?? array();
        if ( ! $item ) { return array( 'action' => 'new', 'score' => $score, 'reason' => 'no-compatible-registry-item' ); }

        $component = is_array( $component ) ? $component : array();
        $item = is_array( $item ) ? $item : array();
        $presentation_hash = $this->presentation_hash( $component );
        foreach ( (array) ( $item['variants'] ?? array() ) as $variant_id => $definition ) {
            if ( ! is_array( $definition ) || '' === $presentation_hash || $presentation_hash !== (string) ( $definition['presentation_hash'] ?? '' ) ) { continue; }
            $matched = $item;
            if ( ! empty( $definition['blueprint'] ) && is_array( $definition['blueprint'] ) ) {
                if ( ! isset( $matched['master']['structure'] ) || ! is_array( $matched['master']['structure'] ) ) { $matched['master']['structure'] = array(); }
                $matched['master']['structure']['blueprint'] = $definition['blueprint'];
            }
            return array(
                'action' => 'reuse', 'score' => max( $score, 0.95 ), 'item' => $matched,
                'variant' => sanitize_key( $variant_id ), 'reason' => 'registered-component-variant-match', 'delta' => array(),
                'presentation_hash' => $presentation_hash,
            );
        }

        $delta = $this->delta( $component, $item );
        $structural_change = ! empty( $delta['semantic_changed'] ) || ! empty( $delta['structure_changed'] ) || ! empty( $delta['content_schema_changed'] );
        $variant_change = ! empty( $delta['layout_changed'] ) || ! empty( $delta['style_changed'] ) || ! empty( $delta['spacing_changed'] ) || ! empty( $delta['responsive_changed'] ) || ! empty( $delta['media_changed'] );

        if ( $score >= 0.90 && ! $structural_change && ! $variant_change ) {
            return array( 'action' => 'reuse', 'score' => $score, 'item' => $item, 'reason' => implode( ',', $reasons ), 'delta' => $delta, 'presentation_hash' => $presentation_hash );
        }
        if ( $score >= 0.75 && ! $structural_change ) {
            return array( 'action' => 'variant', 'score' => $score, 'item' => $item, 'variant' => $this->suggest_variant( $delta, $item ), 'reason' => implode( ',', $reasons ), 'delta' => $delta, 'presentation_hash' => $presentation_hash );
        }
        return array( 'action' => 'new', 'score' => $score, 'item' => $item, 'reason' => $structural_change ? 'component-family-contract-changed' : 'similarity-below-reuse-threshold', 'delta' => $delta, 'presentation_hash' => $presentation_hash );
    }

    public function presentation_hash( array $component ) {
        $payload = array(
            'layout' => (array) ( $component['layout'] ?? array() ),
            'style' => $this->presentation_style( (array) ( $component['style'] ?? array() ) ),
            'spacing' => (array) ( $component['spacing'] ?? array() ),
            'responsive' => $this->presentation_responsive( (array) ( $component['responsive'] ?? array() ) ),
            'media' => $this->media_signature( $component ),
        );
        return hash( 'sha256', $this->stable_json( $payload ) );
    }

    private function delta( array $component, array $item ) {
        $fingerprint = is_array( $item['fingerprint'] ?? null ) ? $item['fingerprint'] : array();
        $candidate_fingerprint = is_array( $component['fingerprint'] ?? null ) ? $component['fingerprint'] : array();
        $candidate_semantic = sanitize_key( $component['type'] ?? ( $candidate_fingerprint['semantic'] ?? '' ) );
        $candidate_structure = (string) ( $component['signature'] ?? ( $candidate_fingerprint['structure'] ?? '' ) );
        $candidate_schema = $this->normalized_keys( $component['content_schema'] ?? ( $candidate_fingerprint['content_schema'] ?? array() ) );
        $item_schema = $this->normalized_keys( $fingerprint['content_schema'] ?? array() );

        $master = is_array( $item['master'] ?? null ) ? $item['master'] : array();
        $candidate_layout = (array) ( $component['layout'] ?? array() );
        $candidate_style = $this->presentation_style( (array) ( $component['style'] ?? array() ) );
        $candidate_spacing = (array) ( $component['spacing'] ?? array() );
        $candidate_responsive = $this->presentation_responsive( (array) ( $component['responsive'] ?? array() ) );
        $master_layout = (array) ( $master['layout'] ?? array() );
        $master_style = $this->presentation_style( (array) ( $master['shared_styles'] ?? array() ) );
        $master_spacing = (array) ( $master['spacing'] ?? array() );
        $master_responsive = $this->presentation_responsive( (array) ( $master['responsive_rules'] ?? array() ) );

        $candidate_media = $this->media_signature( $component );
        $master_media = $this->media_signature( array( 'style' => (array) ( $master['shared_styles'] ?? array() ), 'content_schema' => $fingerprint['content_schema'] ?? array() ) );

        return array(
            'semantic_changed' => $candidate_semantic && sanitize_key( $fingerprint['semantic'] ?? '' ) && $candidate_semantic !== sanitize_key( $fingerprint['semantic'] ),
            'structure_changed' => $candidate_structure && (string) ( $fingerprint['structure'] ?? '' ) && $candidate_structure !== (string) $fingerprint['structure'],
            'content_schema_changed' => $candidate_schema !== $item_schema,
            'layout_changed' => $this->stable_json( $candidate_layout ) !== $this->stable_json( $master_layout ),
            'style_changed' => $this->stable_json( $candidate_style ) !== $this->stable_json( $master_style ),
            'spacing_changed' => $this->stable_json( $candidate_spacing ) !== $this->stable_json( $master_spacing ),
            'responsive_changed' => $this->stable_json( $candidate_responsive ) !== $this->stable_json( $master_responsive ),
            'media_changed' => $candidate_media !== $master_media,
        );
    }

    private function suggest_variant( array $delta, array $item ) {
        $parts = array();
        if ( ! empty( $delta['media_changed'] ) ) { $parts[] = 'media'; }
        if ( ! empty( $delta['layout_changed'] ) ) { $parts[] = 'layout'; }
        if ( ! empty( $delta['style_changed'] ) ) { $parts[] = 'style'; }
        if ( ! empty( $delta['spacing_changed'] ) ) { $parts[] = 'spacing'; }
        if ( ! empty( $delta['responsive_changed'] ) ) { $parts[] = 'responsive'; }
        if ( ! $parts ) { $parts[] = 'default'; }
        $base = sanitize_key( implode( '-', $parts ) );
        $variants = array_keys( (array) ( $item['variants'] ?? array() ) );
        if ( ! in_array( $base, $variants, true ) ) { return $base; }
        $suffix = 2;
        while ( in_array( $base . '-' . $suffix, $variants, true ) ) { $suffix++; }
        return $base . '-' . $suffix;
    }

    private function presentation_style( array $style ) { return $this->asset_neutral_value( $style ); }
    private function presentation_responsive( array $responsive ) { return $this->asset_neutral_value( $responsive ); }

    private function asset_neutral_value( $value, $key = '' ) {
        $normalized_key = strtolower( str_replace( '-', '_', (string) $key ) );
        if ( in_array( $normalized_key, array( 'background_image', 'image', 'src', 'url', 'attachment_id' ), true ) ) { return empty( $value ) ? '' : '__asset__'; }
        if ( ! is_array( $value ) ) {
            if ( is_string( $value ) && preg_match( '/url\s*\(/i', $value ) ) {
                // Strip only asset identity; preserve gradients, positions and other presentation CSS.
                return preg_replace( '/url\s*\(\s*(?:["\'][^"\']*["\']|[^\)]*)\s*\)/i', 'url(__asset__)', $value );
            }
            return $value;
        }
        $out = array();
        foreach ( $value as $nested_key => $nested ) { $out[ $nested_key ] = $this->asset_neutral_value( $nested, $nested_key ); }
        return $out;
    }

    private function normalized_keys( $values ) { $values = array_values( array_unique( array_map( 'sanitize_key', (array) $values ) ) ); sort( $values ); return $values; }

    private function media_signature( array $value ) {
        $schema = $this->normalized_keys( $value['content_schema'] ?? ( $value['fingerprint']['content_schema'] ?? array() ) );
        $style = (array) ( $value['style'] ?? array() );
        $fallback = (array) ( $style['css_fallback'] ?? array() );
        return array(
            'has_media_slot' => in_array( 'media', $schema, true ),
            'has_background' => ! empty( $style['background_image'] ) || ! empty( $fallback['background-image'] ) || ! empty( $fallback['background_image'] ),
        );
    }

    private function stable_json( $value ) { return wp_json_encode( $this->stable_value( $value ) ); }
    private function stable_value( $value ) { if ( ! is_array( $value ) ) { return $value; } if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { ksort( $value ); } foreach ( $value as $key => $nested ) { $value[ $key ] = $this->stable_value( $nested ); } return $value; }
}

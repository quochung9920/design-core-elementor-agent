<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Measures how much of a source node's authorable contract was actually mapped
 * to live Elementor controls. This is evidence, not a widget selector.
 */
final class Design_Core_Elementor_Control_Coverage_Intelligence {
    const VERSION = 1;

    public function evaluate( array $node, $element_type, $widget_type, array $mapping ) {
        $requirements = $this->requirements( $node );
        $native = array_values( array_unique( array_map( 'strval', (array) ( $mapping['native'] ?? array() ) ) ) );
        $unsupported = array_values( array_unique( array_map( 'strval', (array) ( $mapping['unsupported'] ?? array() ) ) ) );
        $fallback = array_values( array_unique( array_map( 'strval', (array) ( $mapping['custom_css_properties'] ?? array() ) ) ) );

        $categories = array(); $weighted_total = 0.0; $weighted_covered = 0.0;
        foreach ( $requirements as $category => $items ) {
            $weight = $this->weight( $category );
            $required = count( $items );
            if ( 0 === $required ) { continue; }
            $missing = array(); $covered = 0;
            foreach ( $items as $item ) {
                if ( $this->is_covered( $item, $native, $fallback, $unsupported ) ) { $covered++; } else { $missing[] = $item; }
            }
            $ratio = $required ? $covered / $required : 1.0;
            $categories[ $category ] = array(
                'required' => $required,
                'covered' => $covered,
                'coverage' => round( $ratio, 4 ),
                'missing' => $missing,
                'weight' => $weight,
            );
            $weighted_total += $weight;
            $weighted_covered += $weight * $ratio;
        }
        $score = $weighted_total > 0 ? $weighted_covered / $weighted_total : 1.0;
        $critical_missing = array();
        foreach ( array( 'content', 'media', 'interaction', 'responsive' ) as $category ) {
            if ( ! empty( $categories[ $category ]['missing'] ) ) { $critical_missing = array_merge( $critical_missing, $categories[ $category ]['missing'] ); }
        }
        return array(
            'version' => self::VERSION,
            'node_id' => (string) ( $node['id'] ?? '' ),
            'target' => sanitize_key( (string) $element_type . '-' . (string) $widget_type ),
            'score' => round( $score, 4 ),
            'status' => $critical_missing ? 'warning' : ( $score >= 0.9 ? 'pass' : 'partial' ),
            'categories' => $categories,
            'critical_missing' => array_values( array_unique( $critical_missing ) ),
            'native_controls' => $native,
            'fallback_properties' => $fallback,
            'unsupported' => $unsupported,
        );
    }

    private function requirements( array $node ) {
        $layout = (array) ( $node['layout'] ?? array() );
        $style = (array) ( $node['style'] ?? array() );
        $spacing = (array) ( $node['spacing'] ?? array() );
        $responsive = (array) ( $node['responsive'] ?? array() );
        $interaction = (array) ( $node['interaction'] ?? array() );
        $content = (array) ( $node['content'] ?? array() );
        $tag = strtolower( (string) ( $node['source']['tag'] ?? '' ) );

        $requirements = array(
            'layout' => array_keys( $layout ),
            'style' => array_values( array_diff( array_keys( $style ), array( 'css_fallback' ) ) ),
            'spacing' => array_keys( $spacing ),
            'responsive' => array(),
            'interaction' => array_keys( $interaction ),
            'content' => array(),
            'media' => array(),
            'editability' => array(),
        );
        foreach ( $responsive as $device => $sets ) {
            foreach ( array( 'layout', 'style', 'spacing' ) as $group ) {
                foreach ( array_keys( (array) ( $sets[ $group ] ?? array() ) ) as $key ) { $requirements['responsive'][] = $key . '_' . sanitize_key( (string) $device ); }
            }
        }
        if ( preg_match( '/^h[1-6]$/', $tag ) ) { $requirements['content'][] = 'title'; $requirements['editability'][] = 'heading'; }
        elseif ( in_array( $tag, array( 'p', 'blockquote' ), true ) ) { $requirements['content'][] = 'editor'; $requirements['editability'][] = 'rich_text'; }
        elseif ( in_array( $tag, array( 'a', 'button' ), true ) ) { $requirements['content'] = array( 'text', 'link' ); $requirements['interaction'][] = 'link'; }
        elseif ( 'form' === $tag ) { $requirements['content'] = array( 'form_fields', 'button_text' ); $requirements['interaction'][] = 'submit'; $requirements['editability'][] = 'repeater'; }
        elseif ( 'img' === $tag || ! empty( $content['image']['url'] ) ) { $requirements['media'][] = 'image'; $requirements['editability'][] = 'media'; }
        if ( ! empty( $node['component']['repeated'] ) ) { $requirements['editability'][] = 'repeater-or-composition'; }
        foreach ( $requirements as $category => $items ) { $requirements[ $category ] = array_values( array_unique( array_filter( array_map( 'sanitize_key', $items ) ) ) ); }
        return $requirements;
    }

    private function is_covered( $requirement, array $native, array $fallback, array $unsupported ) {
        $requirement = sanitize_key( (string) $requirement );
        foreach ( $unsupported as $item ) {
            $normalized = sanitize_key( (string) $item );
            if ( $normalized === $requirement || 0 === strpos( $normalized, $requirement . '_' ) ) { return false; }
        }
        $aliases = $this->aliases( $requirement );
        foreach ( array_merge( $native, $fallback ) as $mapped ) {
            $mapped = sanitize_key( (string) $mapped );
            foreach ( $aliases as $alias ) {
                if ( $mapped === $alias || false !== strpos( $mapped, $alias ) ) { return true; }
            }
        }
        // Content controls are attached after style mapping by the mapper itself.
        if ( in_array( $requirement, array( 'title','editor','text','link','image','form_fields','button_text','submit','heading','rich_text','media','repeater','repeater-or-composition' ), true ) ) { return true; }
        return false;
    }

    private function aliases( $requirement ) {
        $map = array(
            'background' => array( 'background_color', 'background_background' ),
            'radius' => array( 'border_radius', 'image_border_radius' ),
            'direction' => array( 'flex_direction' ),
            'justify' => array( 'flex_justify_content', 'justify_content' ),
            'align' => array( 'flex_align_items', 'align', 'text_align' ),
            'gap' => array( 'flex_gap', 'grid_gaps', 'gap' ),
            'font_family' => array( 'typography_font_family' ),
            'font_size' => array( 'typography_font_size' ),
            'font_weight' => array( 'typography_font_weight' ),
            'line_height' => array( 'typography_line_height' ),
            'letter_spacing' => array( 'typography_letter_spacing' ),
            'image' => array( 'image', 'background_image' ),
        );
        return array_values( array_unique( array_merge( array( $requirement ), $map[ $requirement ] ?? array() ) ) );
    }

    private function weight( $category ) {
        $weights = array( 'content'=>2.0, 'media'=>2.0, 'interaction'=>2.0, 'layout'=>1.5, 'responsive'=>1.5, 'style'=>1.0, 'spacing'=>1.0, 'editability'=>1.25 );
        return (float) ( $weights[ $category ] ?? 1.0 );
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Reuse_Engine {
    private $semantic_matcher;
    public function __construct() { $this->semantic_matcher = class_exists( 'Design_Core_Elementor_Semantic_Matcher' ) ? new Design_Core_Elementor_Semantic_Matcher() : null; }

    public function find_best_match( $component, $registry ) {
        $best = array( 'item'=>null, 'score'=>0.0, 'reasons'=>array() );
        $component = is_array( $component ) ? $component : array( 'html'=>(string)$component );
        foreach ( is_array( $registry ) ? $registry : array() as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $score = 0.0; $reasons = array();
            $type = sanitize_key( $component['type'] ?? '' );
            $purposes = array_map( 'sanitize_key', (array) ( $item['source']['purpose'] ?? $item['purpose'] ?? $item['capabilities'] ?? array() ) );
            if ( ! empty( $item['fingerprint']['semantic'] ) ) { $purposes[] = sanitize_key( $item['fingerprint']['semantic'] ); }
            if ( $type && in_array( $type, $purposes, true ) ) { $score += 0.45; $reasons[] = 'semantic-purpose'; }

            $signature = (string) ( $component['signature'] ?? '' );
            $fingerprint = is_array( $item['fingerprint'] ?? null ) ? $item['fingerprint'] : array();
            if ( $signature && $signature === ( $fingerprint['structure'] ?? '' ) ) { $score += 0.25; $reasons[] = 'structure-signature'; }
            elseif ( $type && $type === sanitize_key( $fingerprint['semantic'] ?? '' ) ) { $score += 0.10; $reasons[] = 'fingerprint-semantic'; }

            $component_schema = array_map( 'sanitize_key', (array) ( $component['content_schema'] ?? $component['fingerprint']['content_schema'] ?? array() ) );
            $item_schema = array_map( 'sanitize_key', (array) ( $item['content_schema'] ?? $fingerprint['content_schema'] ?? array() ) );
            if ( $component_schema && $item_schema ) {
                $union = array_unique( array_merge( $component_schema, $item_schema ) );
                $intersection = array_intersect( $component_schema, $item_schema );
                $schema_score = count( $union ) ? count( $intersection ) / count( $union ) : 0;
                $score += $schema_score * 0.15; $reasons[] = 'content-schema:' . round( $schema_score, 3 );
            }

            $sample_html = $item['sample_html'] ?? $item['html'] ?? '';
            $component_html = $component['html'] ?? '';
            if ( $this->semantic_matcher && $component_html && $sample_html ) {
                $semantic = $this->semantic_matcher->score_similarity( $component_html, $sample_html );
                $score += $semantic * 0.25; $reasons[] = 'html-similarity:' . round( $semantic, 3 );
            }

            // Canonical fallback for the (normal, HTML-free) registry: compare layout/style/spacing closeness instead of source HTML.
            $candidate_style = array_merge( (array) ( $component['layout'] ?? array() ), (array) ( $component['style'] ?? array() ), (array) ( $component['spacing'] ?? array() ) );
            $item_style = array_merge( (array) ( $item['master']['layout'] ?? array() ), (array) ( $item['master']['shared_styles'] ?? array() ), (array) ( $item['master']['spacing'] ?? array() ) );
            if ( ! $component_html && $candidate_style && $item_style ) {
                $keys = array_unique( array_merge( array_keys( $candidate_style ), array_keys( $item_style ) ) );
                $matching = 0;
                foreach ( $keys as $key ) { if ( array_key_exists( $key, $candidate_style ) && array_key_exists( $key, $item_style ) && wp_json_encode( $candidate_style[ $key ] ) === wp_json_encode( $item_style[ $key ] ) ) { $matching++; } }
                $style_score = $keys ? $matching / count( $keys ) : 0;
                $score += $style_score * 0.25; $reasons[] = 'style-similarity:' . round( $style_score, 3 );
            }
            $score = min( 1.0, $score );
            if ( $score > $best['score'] ) { $best = array( 'item'=>$item, 'score'=>$score, 'reasons'=>$reasons ); }
        }
        return $best;
    }
}

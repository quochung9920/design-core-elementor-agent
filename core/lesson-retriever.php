<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Retrieves verified learned lessons relevant to a new design/compiler context. */
class Design_Core_Elementor_Lesson_Retriever {
    private $store;

    public function __construct( Design_Core_Elementor_Design_Memory_Store $store = null ) {
        $this->store = $store ?: new Design_Core_Elementor_Design_Memory_Store();
    }

    public function retrieve( array $context, $limit = 8 ) {
        $engine = new Design_Core_Elementor_Failure_Signature_Engine();
        $signature = $engine->signature( $context );
        $tags = $engine->tags( $context );
        $scope = sanitize_key( (string) ( $context['scope'] ?? 'global' ) );
        $source_type = sanitize_key( (string) ( $context['source_type'] ?? $context['source'] ?? 'generic' ) );
        $scored = array();

        foreach ( $this->store->lessons() as $lesson ) {
            if ( empty( $lesson['verified'] ) ) { continue; }
            $score = $this->score( $signature, $tags, $scope, $source_type, $lesson );
            if ( $score < 0.35 ) { continue; }
            $lesson['match_score'] = round( $score, 4 );
            $scored[] = $lesson;
        }
        usort( $scored, static function ( $a, $b ) {
            $score = (float) ( $b['match_score'] ?? 0 ) <=> (float) ( $a['match_score'] ?? 0 );
            if ( 0 !== $score ) { return $score; }
            return (float) ( $b['confidence'] ?? 0 ) <=> (float) ( $a['confidence'] ?? 0 );
        } );
        return array_slice( $scored, 0, max( 1, min( 25, (int) $limit ) ) );
    }

    private function score( $signature, array $tags, $scope, $source_type, array $lesson ) {
        $lesson_signature = strtolower( (string) ( $lesson['signature'] ?? '' ) );
        if ( '' === $lesson_signature ) { return 0; }
        $score = 0;
        if ( $lesson_signature === $signature ) { $score += 0.58; }
        else {
            $a = explode( '.', $signature ); $b = explode( '.', $lesson_signature );
            $common = 0; $max = min( count( $a ), count( $b ) );
            for ( $i = 0; $i < $max; $i++ ) { if ( $a[ $i ] !== $b[ $i ] ) { break; } $common++; }
            if ( $common ) { $score += min( 0.42, 0.11 * $common ); }
        }
        $lesson_tags = array_map( 'strval', (array) ( $lesson['tags'] ?? array() ) );
        if ( $tags && $lesson_tags ) {
            $overlap = count( array_intersect( $tags, $lesson_tags ) );
            $score += min( 0.18, $overlap * 0.045 );
        }
        if ( (string) ( $lesson['source_type'] ?? '' ) === $source_type ) { $score += 0.08; }
        $lesson_scope = (string) ( $lesson['scope'] ?? 'global' );
        if ( 'global' === $lesson_scope ) { $score += 0.03; }
        elseif ( $lesson_scope === $scope ) { $score += 0.07; }
        $score *= 0.75 + 0.25 * max( 0, min( 1, (float) ( $lesson['confidence'] ?? 0 ) ) );
        return min( 1, $score );
    }
}

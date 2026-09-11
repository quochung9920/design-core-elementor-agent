<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Converts a completed correction run into a durable lesson only when the final
 * evidence proves improvement. Failed/partial runs remain incidents, never rules.
 */
class Design_Core_Elementor_Correction_Learning_Engine {
    const VERSION = 1;
    const MIN_VISUAL_SCORE = 0.95;
    private $store;
    private $signatures;

    public function __construct( Design_Core_Elementor_Design_Memory_Store $store = null ) {
        $this->store = $store ?: new Design_Core_Elementor_Design_Memory_Store();
        $this->signatures = new Design_Core_Elementor_Failure_Signature_Engine();
    }

    public function learn( array $run ) {
        $context = is_array( $run['context'] ?? null ) ? $run['context'] : array();
        $signature = (string) ( $run['signature'] ?? $this->signatures->signature( $context ) );
        $before = $this->score( $run['before'] ?? $run['before_score'] ?? 0 );
        $after = $this->score( $run['after'] ?? $run['after_score'] ?? $run['similarity'] ?? 0 );
        $eligible = $this->eligible( $run, $after );

        $incident = $this->store->record_incident( array(
            'signature' => $signature,
            'status' => $eligible ? 'verified-fix' : ( sanitize_key( (string) ( $run['status'] ?? 'observed' ) ) ?: 'observed' ),
            'source_type' => sanitize_key( (string) ( $context['source_type'] ?? $context['source'] ?? 'generic' ) ),
            'page_id' => (int) ( $context['page_id'] ?? $run['page_id'] ?? 0 ),
            'source_node_id' => sanitize_text_field( (string) ( $context['source_node_id'] ?? $context['figma_node_id'] ?? '' ) ),
            'before_score' => $before,
            'after_score' => $after,
            'delta' => round( $after - $before, 4 ),
            'context' => $context,
            'correction' => $this->correction( $run ),
            'quality_gate' => is_array( $run['quality_gate'] ?? null ) ? $run['quality_gate'] : array(),
            'reason_not_learned' => $eligible ? '' : $this->ineligible_reason( $run, $after ),
        ) );

        if ( ! $eligible ) {
            return array( 'status' => 'recorded-incident', 'learned' => false, 'incident' => $incident );
        }

        $correction = $this->correction( $run );
        if ( ! $correction ) {
            return array( 'status' => 'recorded-incident', 'learned' => false, 'incident' => $incident, 'reason' => 'No bounded correction evidence was supplied.' );
        }

        $scope = sanitize_key( (string) ( $run['scope'] ?? $context['scope'] ?? 'global' ) );
        if ( ! in_array( $scope, array( 'global', 'project', 'source' ), true ) ) { $scope = 'global'; }
        $confidence = $this->confidence( $run, $before, $after );
        $lesson = $this->store->upsert_lesson( array(
            'signature' => $signature,
            'scope' => $scope,
            'source_type' => sanitize_key( (string) ( $context['source_type'] ?? $context['source'] ?? 'generic' ) ),
            'verified' => true,
            'confidence' => $confidence,
            'verified_runs' => max( 1, (int) ( $run['verified_runs'] ?? 1 ) ),
            'tags' => $this->signatures->tags( $context ),
            'conditions' => is_array( $run['conditions'] ?? null ) ? $run['conditions'] : $this->conditions( $context ),
            'correction' => $correction,
            'evidence' => array(
                'before_score' => $before,
                'after_score' => $after,
                'delta' => round( $after - $before, 4 ),
                'quality_gate' => is_array( $run['quality_gate'] ?? null ) ? $run['quality_gate'] : array(),
            ),
            'provenance' => array(
                'source_node_id' => sanitize_text_field( (string) ( $context['source_node_id'] ?? $context['figma_node_id'] ?? '' ) ),
                'page_id' => (int) ( $context['page_id'] ?? $run['page_id'] ?? 0 ),
                'incident_id' => (string) ( $incident['id'] ?? '' ),
                'engine_version' => self::VERSION,
            ),
        ) );
        if ( is_wp_error( $lesson ) ) { return $lesson; }

        $benchmark = array();
        if ( class_exists( 'Design_Core_Elementor_Benchmark_Promoter' ) ) {
            $proposed = ( new Design_Core_Elementor_Benchmark_Promoter() )->propose( $lesson, $incident );
            if ( ! is_wp_error( $proposed ) ) { $benchmark = $proposed; }
        }
        return array( 'status' => 'learned', 'learned' => true, 'lesson' => $lesson, 'incident' => $incident, 'benchmark_candidate' => $benchmark );
    }

    public function eligible( array $run, $after = null ) {
        $after = null === $after ? $this->score( $run['after'] ?? $run['after_score'] ?? $run['similarity'] ?? 0 ) : (float) $after;
        $target = max( self::MIN_VISUAL_SCORE, min( 0.999, (float) ( $run['target_similarity'] ?? self::MIN_VISUAL_SCORE ) ) );
        if ( $after < $target ) { return false; }
        $status = sanitize_key( (string) ( $run['status'] ?? '' ) );
        if ( ! in_array( $status, array( 'pass', 'verified', 'publishable' ), true ) ) { return false; }
        $gate = is_array( $run['quality_gate'] ?? null ) ? $run['quality_gate'] : array();
        if ( $gate && empty( $gate['publishable'] ) ) { return false; }
        foreach ( (array) ( $run['structural_issues'] ?? array() ) as $issue ) {
            if ( 'high' === sanitize_key( (string) ( $issue['severity'] ?? '' ) ) ) { return false; }
        }
        return true;
    }

    private function ineligible_reason( array $run, $after ) {
        $target = max( self::MIN_VISUAL_SCORE, min( 0.999, (float) ( $run['target_similarity'] ?? self::MIN_VISUAL_SCORE ) ) );
        if ( $after < $target ) { return 'visual-score-below-target'; }
        if ( ! in_array( sanitize_key( (string) ( $run['status'] ?? '' ) ), array( 'pass', 'verified', 'publishable' ), true ) ) { return 'final-status-not-verified'; }
        $gate = is_array( $run['quality_gate'] ?? null ) ? $run['quality_gate'] : array();
        if ( $gate && empty( $gate['publishable'] ) ) { return 'quality-gate-not-publishable'; }
        return 'unverified-or-unsafe';
    }

    private function correction( array $run ) {
        $correction = $run['correction'] ?? $run['applied_correction'] ?? $run['directives'] ?? array();
        if ( ! is_array( $correction ) || ! $correction ) { return array(); }
        return array_slice( $correction, 0, 50, true );
    }

    private function conditions( array $context ) {
        $out = array();
        foreach ( array( 'node_type', 'parent_role', 'component_role', 'asset_type', 'property', 'figma_sizing', 'position' ) as $key ) {
            if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) && '' !== (string) $context[ $key ] ) { $out[ $key ] = $context[ $key ]; }
        }
        return $out;
    }

    private function confidence( array $run, $before, $after ) {
        $delta = max( 0, $after - $before );
        $repeat = max( 1, min( 10, (int) ( $run['verified_runs'] ?? 1 ) ) );
        return round( min( 0.995, 0.80 + min( 0.12, $delta * 0.35 ) + min( 0.075, ( $repeat - 1 ) * 0.015 ) ), 4 );
    }

    private function score( $value ) {
        if ( is_array( $value ) ) { $value = $value['similarity'] ?? $value['score'] ?? 0; }
        $value = (float) $value;
        if ( $value > 1 ) { $value /= 100; }
        return max( 0, min( 1, $value ) );
    }
}

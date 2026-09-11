<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Learns only from measured outcomes.
 *
 * Failed attempts become incidents. A lesson is written only after a rendered
 * verification passes, and its strategy must already exist in the governed
 * Fidelity Rule Registry. This prevents Design Core from training itself on an
 * incorrect AI guess.
 */
class Design_Core_Elementor_Correction_Learning_Engine {
    const VERSION = 1;

    private $store;
    private $signatures;
    private $promoter;

    public function __construct( Design_Core_Elementor_Design_Memory_Store $store = null ) {
        $this->store = $store ?: new Design_Core_Elementor_Design_Memory_Store();
        $this->signatures = new Design_Core_Elementor_Failure_Signature_Engine();
        $this->promoter = new Design_Core_Elementor_Benchmark_Promoter( $this->store );
    }

    public function observe_verification( array $visual, array $context = array() ) {
        $this->store->ensure_seeded();
        if ( 'pass' === (string) ( $visual['status'] ?? '' ) ) {
            return array( 'status' => 'verified', 'lessons' => $this->learn_verified_source_patterns( $visual, $context ) );
        }
        $incident_ids = array();
        $figma_nodes = ! empty( $context['design_ir'] ) ? $this->signatures->figma_node_map( (array) $context['design_ir'] ) : array();
        foreach ( (array) ( $visual['issues'] ?? array() ) as $issue ) {
            if ( ! is_array( $issue ) ) { continue; }
            $signature = $this->signatures->signature_from_issue( $issue, array( 'figma_nodes' => $figma_nodes ) );
            $incident = $this->store->record_incident( array(
                'signature' => $signature,
                'scope' => $this->learning_scope( $context ),
                'scope_key' => $this->scope_key( $context ),
                'source_kind' => sanitize_key( (string) ( $context['source_kind'] ?? '' ) ),
                'source_fingerprint' => sanitize_key( (string) ( $context['source_fingerprint'] ?? '' ) ),
                'page_id' => (int) ( $context['page_id'] ?? 0 ),
                'severity' => (string) ( $issue['severity'] ?? 'medium' ),
                'category' => (string) ( $issue['category'] ?? '' ),
                'figma_id' => (string) ( $issue['figma_id'] ?? '' ),
                'elementor_id' => (string) ( $issue['elementor_id'] ?? '' ),
                'summary' => (string) ( $issue['message'] ?? 'Rendered verification mismatch.' ),
                'evidence' => array(
                    'visual_score' => (float) ( $visual['similarity'] ?? 0 ),
                    'target_similarity' => (float) ( $visual['target_similarity'] ?? 0.95 ),
                    'viewport' => (int) ( $issue['viewport'] ?? 0 ),
                    'issue_count' => count( (array) ( $visual['issues'] ?? array() ) ),
                    'rule_version' => self::VERSION,
                ),
            ) );
            if ( ! is_wp_error( $incident ) ) { $incident_ids[] = (string) ( $incident['id'] ?? '' ); }
        }
        return array( 'status' => 'incident-recorded', 'incident_ids' => array_values( array_filter( $incident_ids ) ) );
    }

    /** Learn from a correction history only when the final rendered state passed. */
    public function record_verified_recovery( array $history, array $context = array() ) {
        if ( ! $history ) { return array(); }
        $last = end( $history );
        $final = (array) ( $last['feedback'] ?? array() );
        if ( 'pass' !== (string) ( $final['status'] ?? '' ) ) { return array(); }
        reset( $history );
        $first = (array) ( $history[0]['feedback'] ?? array() );
        $before = (float) ( $first['similarity'] ?? 0 );
        $after = (float) ( $final['similarity'] ?? 0 );
        $improvement = max( 0, $after - $before );
        $figma_nodes = ! empty( $context['design_ir'] ) ? $this->signatures->figma_node_map( (array) $context['design_ir'] ) : array();
        $seen = array(); $learned = array();

        foreach ( $history as $entry ) {
            $feedback = (array) ( $entry['feedback'] ?? array() );
            if ( 'pass' === (string) ( $feedback['status'] ?? '' ) ) { continue; }
            foreach ( (array) ( $feedback['issues'] ?? array() ) as $issue ) {
                if ( ! is_array( $issue ) ) { continue; }
                $signature = $this->signatures->signature_from_issue( $issue, array( 'figma_nodes' => $figma_nodes ) );
                $strategy = $this->signatures->strategy_for_signature( $signature );
                if ( ! $strategy || isset( $seen[ $signature . '|' . $strategy ] ) ) { continue; }
                $seen[ $signature . '|' . $strategy ] = true;
                $lesson = $this->store->upsert_lesson( array(
                    'signature' => $signature,
                    'strategy' => $strategy,
                    'scope' => $this->learning_scope( $context ),
                    'scope_key' => $this->scope_key( $context ),
                    'verified' => true,
                    'confidence' => min( 0.99, 0.82 + min( 0.15, $improvement * 0.5 ) ),
                    'verified_hits' => 1,
                    'origin' => 'verified-correction',
                    'source_kind' => sanitize_key( (string) ( $context['source_kind'] ?? '' ) ),
                    'source_fingerprint' => sanitize_key( (string) ( $context['source_fingerprint'] ?? '' ) ),
                    'reason' => 'A measured rendered mismatch was corrected and the final verification passed.',
                    'evidence' => array(
                        'similarity_before' => $before,
                        'similarity_after' => $after,
                        'target_similarity' => (float) ( $final['target_similarity'] ?? 0.95 ),
                        'iteration_count' => count( $history ),
                        'issue_count' => count( (array) ( $feedback['issues'] ?? array() ) ),
                        'rule_version' => self::VERSION,
                    ),
                ) );
                if ( ! is_wp_error( $lesson ) && is_array( $lesson ) ) {
                    $learned[] = $lesson;
                    $this->promoter->observe_lesson( $lesson );
                }
            }
        }
        return $learned;
    }

    /**
     * A clean Figma render proves that the safe handling rules required by the
     * source were effective. Record source/project-scoped reinforcement only;
     * runtime observations never create a new global compiler rule by themselves.
     */
    private function learn_verified_source_patterns( array $visual, array $context ) {
        $ir = is_array( $context['design_ir'] ?? null ) ? $context['design_ir'] : array();
        if ( ! $ir ) { return array(); }
        $lessons = array();
        foreach ( $this->signatures->signals_from_ir( $ir ) as $signature ) {
            $strategy = $this->signatures->strategy_for_signature( $signature );
            if ( ! $strategy ) { continue; }
            $lesson = $this->store->upsert_lesson( array(
                'signature' => $signature,
                'strategy' => $strategy,
                'scope' => $this->learning_scope( $context ),
                'scope_key' => $this->scope_key( $context ),
                'verified' => true,
                'confidence' => 0.90,
                'verified_hits' => 1,
                'origin' => 'verified-render',
                'source_kind' => sanitize_key( (string) ( $context['source_kind'] ?? '' ) ),
                'source_fingerprint' => sanitize_key( (string) ( $context['source_fingerprint'] ?? '' ) ),
                'reason' => 'This source pattern rendered above the configured fidelity threshold.',
                'evidence' => array(
                    'visual_score' => (float) ( $visual['similarity'] ?? 0 ),
                    'target_similarity' => (float) ( $visual['target_similarity'] ?? 0.95 ),
                    'issue_count' => 0,
                    'rule_version' => self::VERSION,
                ),
            ) );
            if ( ! is_wp_error( $lesson ) && is_array( $lesson ) ) {
                $lessons[] = $lesson;
                $this->promoter->observe_lesson( $lesson );
            }
        }
        return $lessons;
    }

    private function learning_scope( array $context ) {
        if ( ! empty( $context['source_fingerprint'] ) ) { return 'source'; }
        return 'project';
    }

    private function scope_key( array $context ) {
        if ( ! empty( $context['source_fingerprint'] ) ) { return sanitize_key( (string) $context['source_fingerprint'] ); }
        $project = sanitize_key( (string) ( $context['project_scope_key'] ?? Design_Core_Elementor_Design_Memory_Store::project_scope_key() ) );
        return $project ?: 'standalone-project';
    }
}

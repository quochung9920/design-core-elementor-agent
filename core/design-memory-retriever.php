<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Finds verified lessons relevant to the current source before compilation. */
class Design_Core_Elementor_Design_Memory_Retriever {
    const VERSION = 1;
    const MIN_CONFIDENCE = 0.72;

    private $store;
    private $signatures;

    public function __construct( Design_Core_Elementor_Design_Memory_Store $store = null ) {
        $this->store = $store ?: new Design_Core_Elementor_Design_Memory_Store();
        $this->signatures = new Design_Core_Elementor_Failure_Signature_Engine();
    }

    public function retrieve_for_ir( array $ir, array $context = array(), $limit = 16 ) {
        $this->store->ensure_seeded();
        $signals = $this->signatures->signals_from_ir( $ir );
        $project_key = sanitize_key( (string) ( $context['project_scope_key'] ?? Design_Core_Elementor_Design_Memory_Store::project_scope_key() ) );
        $source_fingerprint = sanitize_key( (string) ( $context['source_fingerprint'] ?? '' ) );
        $matches = array();

        foreach ( $this->store->lessons() as $lesson ) {
            if ( empty( $lesson['verified'] ) || (float) ( $lesson['confidence'] ?? 0 ) < self::MIN_CONFIDENCE ) { continue; }
            if ( ! $this->scope_matches( $lesson, $project_key, $source_fingerprint ) ) { continue; }
            $signature = Design_Core_Elementor_Design_Memory_Store::signature_key( $lesson['signature'] ?? '' );
            $signal_score = $this->signal_score( $signature, $signals );
            if ( $signal_score <= 0 ) { continue; }
            $confidence = (float) ( $lesson['confidence'] ?? 0 );
            $hits = max( 1, (int) ( $lesson['verified_hits'] ?? 1 ) );
            $scope_bonus = 'source' === ( $lesson['scope'] ?? '' ) ? 0.14 : ( 'project' === ( $lesson['scope'] ?? '' ) ? 0.08 : 0 );
            $score = min( 1.0, 0.58 * $signal_score + 0.34 * $confidence + min( 0.06, log( 1 + $hits ) / 50 ) + $scope_bonus );
            $matches[] = array_merge( $lesson, array( 'retrieval_score' => round( $score, 4 ) ) );
        }

        usort( $matches, static fn( $a, $b ) => (float) ( $b['retrieval_score'] ?? 0 ) <=> (float) ( $a['retrieval_score'] ?? 0 ) );
        $matches = array_slice( $matches, 0, max( 1, min( 50, (int) $limit ) ) );
        return array(
            'version' => self::VERSION,
            'signals' => $signals,
            'project_scope_key' => $project_key,
            'source_fingerprint' => $source_fingerprint,
            'lessons' => $matches,
            'strategies' => array_values( array_unique( array_filter( array_map( static fn( $lesson ) => sanitize_key( (string) ( $lesson['strategy'] ?? '' ) ), $matches ) ) ) ),
        );
    }

    public function prepare_ir( array $ir, array $context = array() ) {
        $retrieval = $this->retrieve_for_ir( $ir, $context );
        $ir = ( new Design_Core_Elementor_Fidelity_Rule_Registry() )->apply_to_ir( $ir, $retrieval );
        $ir['diagnostics']['design_memory']['retrieval_version'] = self::VERSION;
        $ir['diagnostics']['design_memory']['source_fingerprint'] = (string) ( $retrieval['source_fingerprint'] ?? '' );
        return array( 'design_ir' => $ir, 'memory' => $retrieval );
    }

    private function scope_matches( array $lesson, $project_key, $source_fingerprint ) {
        $scope = sanitize_key( (string) ( $lesson['scope'] ?? 'global' ) );
        if ( 'global' === $scope ) { return true; }
        $scope_key = sanitize_key( (string) ( $lesson['scope_key'] ?? '' ) );
        if ( 'project' === $scope ) { return $project_key && hash_equals( $scope_key, $project_key ); }
        if ( 'source' === $scope ) { return $source_fingerprint && hash_equals( $scope_key, $source_fingerprint ); }
        return false;
    }

    private function signal_score( $signature, array $signals ) {
        if ( in_array( $signature, $signals, true ) ) { return 1.0; }
        $parts = explode( '.', $signature );
        if ( count( $parts ) < 2 ) { return 0.0; }
        foreach ( $signals as $signal ) {
            $signal = Design_Core_Elementor_Design_Memory_Store::signature_key( $signal );
            if ( 0 === strpos( $signal, $parts[0] . '.' . $parts[1] . '.' ) || 0 === strpos( $signature, implode( '.', array_slice( explode( '.', $signal ), 0, 2 ) ) . '.' ) ) { return 0.55; }
        }
        return 0.0;
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Stores candidates for future deterministic regression fixtures. Promotion is
 * deliberately review-gated: runtime learning may propose a benchmark but never
 * writes executable PHP/JS test code from untrusted design data.
 */
class Design_Core_Elementor_Benchmark_Promoter {
    const VERSION = 1;
    const OPTION = 'design_core_elementor_benchmark_candidates_v1';
    const MAX_CANDIDATES = 250;

    public function propose( array $lesson, array $incident = array() ) {
        if ( empty( $lesson['verified'] ) || empty( $lesson['signature'] ) ) {
            return new WP_Error( 'design_core_benchmark_unverified', 'Only verified lessons can become benchmark candidates.' );
        }
        $candidate = array(
            'id' => 'benchmark_' . substr( hash( 'sha256', (string) $lesson['signature'] . '|' . (string) ( $lesson['scope'] ?? 'global' ) ), 0, 20 ),
            'version' => self::VERSION,
            'signature' => sanitize_text_field( (string) $lesson['signature'] ),
            'scope' => sanitize_key( (string) ( $lesson['scope'] ?? 'global' ) ),
            'source_type' => sanitize_key( (string) ( $lesson['source_type'] ?? 'generic' ) ),
            'confidence' => max( 0, min( 1, (float) ( $lesson['confidence'] ?? 0 ) ) ),
            'conditions' => is_array( $lesson['conditions'] ?? null ) ? $lesson['conditions'] : array(),
            'correction' => is_array( $lesson['correction'] ?? null ) ? $lesson['correction'] : array(),
            'evidence' => is_array( $lesson['evidence'] ?? null ) ? $lesson['evidence'] : array(),
            'incident_id' => sanitize_key( (string) ( $incident['id'] ?? '' ) ),
            'status' => 'review-required',
            'created_at' => gmdate( 'c' ),
        );
        $stored = get_option( self::OPTION, array() );
        $records = is_array( $stored ) && (int) ( $stored['version'] ?? 0 ) === self::VERSION ? (array) ( $stored['candidates'] ?? array() ) : array();
        $replaced = false;
        foreach ( $records as $index => $existing ) {
            if ( (string) ( $existing['id'] ?? '' ) !== $candidate['id'] ) { continue; }
            $candidate['created_at'] = (string) ( $existing['created_at'] ?? $candidate['created_at'] );
            $candidate['updated_at'] = gmdate( 'c' );
            $records[ $index ] = array_replace_recursive( $existing, $candidate );
            $replaced = true;
            break;
        }
        if ( ! $replaced ) { $records[] = $candidate; }
        usort( $records, static fn( $a, $b ) => (float) ( $b['confidence'] ?? 0 ) <=> (float) ( $a['confidence'] ?? 0 ) );
        $records = array_slice( $records, 0, self::MAX_CANDIDATES );
        update_option( self::OPTION, array( 'version' => self::VERSION, 'candidates' => $records, 'updated_at' => gmdate( 'c' ) ), false );
        return $candidate;
    }

    public function all() {
        $stored = get_option( self::OPTION, array() );
        return is_array( $stored ) && (int) ( $stored['version'] ?? 0 ) === self::VERSION ? array_values( array_filter( (array) ( $stored['candidates'] ?? array() ), 'is_array' ) ) : array();
    }
}

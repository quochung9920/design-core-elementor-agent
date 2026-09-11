<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Persistent, bounded Design Memory.
 *
 * Design Memory stores only compact signatures, strategies and verification
 * summaries. It deliberately does not persist raw prompts, screenshots or
 * customer source documents. Lessons are immutable in meaning: repeated
 * observations only strengthen/refresh an existing lesson keyed by scope,
 * signature and strategy.
 */
class Design_Core_Elementor_Design_Memory_Store {
    const OPTION_KEY = 'design_core_elementor_design_memory_v1';
    const SCHEMA_VERSION = 1;
    const SEED_VERSION = 1;
    const MAX_LESSONS = 400;
    const MAX_INCIDENTS = 600;
    const MAX_BENCHMARK_CANDIDATES = 250;

    public function snapshot() {
        $state = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $state ) ) { $state = array(); }
        return $this->normalize_state( $state );
    }

    public function ensure_seeded() {
        $state = $this->snapshot();
        if ( (int) ( $state['seed_version'] ?? 0 ) >= self::SEED_VERSION ) { return $state; }
        foreach ( Design_Core_Elementor_Fidelity_Rule_Registry::seed_lessons() as $lesson ) {
            $state = $this->upsert_lesson_into_state( $state, $lesson );
        }
        $state['seed_version'] = self::SEED_VERSION;
        $state['updated_at'] = gmdate( 'c' );
        $state['generation'] = (int) ( $state['generation'] ?? 0 ) + 1;
        update_option( self::OPTION_KEY, $this->bounded( $state ), false );
        return $this->snapshot();
    }

    public function lessons( $scope = '' ) {
        $state = $this->snapshot();
        $scope = sanitize_key( (string) $scope );
        $lessons = array_values( array_filter( (array) ( $state['lessons'] ?? array() ), static function ( $lesson ) use ( $scope ) {
            return ! $scope || $scope === sanitize_key( (string) ( $lesson['scope'] ?? '' ) );
        } ) );
        usort( $lessons, static function ( $a, $b ) {
            $score_a = (float) ( $a['confidence'] ?? 0 ) + min( 0.15, log( 1 + max( 0, (int) ( $a['verified_hits'] ?? 0 ) ) ) / 20 );
            $score_b = (float) ( $b['confidence'] ?? 0 ) + min( 0.15, log( 1 + max( 0, (int) ( $b['verified_hits'] ?? 0 ) ) ) / 20 );
            return $score_a === $score_b ? strcmp( (string) ( $b['last_verified_at'] ?? '' ), (string) ( $a['last_verified_at'] ?? '' ) ) : ( $score_a < $score_b ? 1 : -1 );
        } );
        return $lessons;
    }

    public function incidents() {
        return array_values( (array) ( $this->snapshot()['incidents'] ?? array() ) );
    }

    public function benchmark_candidates() {
        return array_values( (array) ( $this->snapshot()['benchmark_candidates'] ?? array() ) );
    }

    public function upsert_lesson( array $lesson ) {
        $lesson = $this->sanitize_lesson( $lesson );
        if ( is_wp_error( $lesson ) ) { return $lesson; }
        $state = $this->upsert_lesson_into_state( $this->snapshot(), $lesson );
        $state['updated_at'] = gmdate( 'c' );
        $state['generation'] = (int) ( $state['generation'] ?? 0 ) + 1;
        if ( false === update_option( self::OPTION_KEY, $this->bounded( $state ), false ) ) {
            return new WP_Error( 'design_core_memory_write_failed', 'Unable to persist Design Memory lesson.' );
        }
        return $this->find_lesson( $lesson['id'], $state );
    }

    public function record_incident( array $incident ) {
        $signature = sanitize_key( (string) ( $incident['signature'] ?? '' ) );
        if ( '' === $signature ) { return new WP_Error( 'design_core_memory_incident_signature_required', 'A failure signature is required.' ); }
        $state = $this->snapshot();
        $id = 'incident-' . substr( hash( 'sha256', $signature . '|' . microtime( true ) . '|' . wp_generate_uuid4() ), 0, 20 );
        $entry = array(
            'id' => $id,
            'signature' => $signature,
            'scope' => $this->scope( $incident['scope'] ?? 'project' ),
            'scope_key' => $this->scope_key( $incident['scope_key'] ?? '' ),
            'source_kind' => sanitize_key( (string) ( $incident['source_kind'] ?? '' ) ),
            'source_fingerprint' => $this->fingerprint( $incident['source_fingerprint'] ?? '' ),
            'page_id' => max( 0, (int) ( $incident['page_id'] ?? 0 ) ),
            'status' => in_array( (string) ( $incident['status'] ?? 'open' ), array( 'open', 'resolved', 'superseded' ), true ) ? (string) $incident['status'] : 'open',
            'severity' => in_array( (string) ( $incident['severity'] ?? 'medium' ), array( 'low', 'medium', 'high', 'critical' ), true ) ? (string) $incident['severity'] : 'medium',
            'category' => sanitize_key( (string) ( $incident['category'] ?? '' ) ),
            'figma_id' => sanitize_text_field( (string) ( $incident['figma_id'] ?? '' ) ),
            'elementor_id' => sanitize_key( (string) ( $incident['elementor_id'] ?? '' ) ),
            'summary' => sanitize_text_field( (string) ( $incident['summary'] ?? '' ) ),
            'evidence' => $this->safe_evidence( (array) ( $incident['evidence'] ?? array() ) ),
            'created_at' => gmdate( 'c' ),
            'resolved_at' => '',
            'resolution' => array(),
        );
        array_unshift( $state['incidents'], $entry );
        $state['updated_at'] = gmdate( 'c' );
        $state['generation'] = (int) ( $state['generation'] ?? 0 ) + 1;
        if ( false === update_option( self::OPTION_KEY, $this->bounded( $state ), false ) ) {
            return new WP_Error( 'design_core_memory_write_failed', 'Unable to persist Design Memory incident.' );
        }
        return $entry;
    }

    public function resolve_incidents( array $incident_ids, array $resolution ) {
        $ids = array_values( array_unique( array_filter( array_map( 'sanitize_key', $incident_ids ) ) ) );
        if ( ! $ids ) { return 0; }
        $state = $this->snapshot();
        $resolved = 0;
        foreach ( $state['incidents'] as &$incident ) {
            if ( ! in_array( (string) ( $incident['id'] ?? '' ), $ids, true ) ) { continue; }
            $incident['status'] = 'resolved';
            $incident['resolved_at'] = gmdate( 'c' );
            $incident['resolution'] = $this->safe_evidence( $resolution );
            $resolved++;
        }
        unset( $incident );
        if ( $resolved ) {
            $state['updated_at'] = gmdate( 'c' );
            $state['generation'] = (int) ( $state['generation'] ?? 0 ) + 1;
            update_option( self::OPTION_KEY, $this->bounded( $state ), false );
        }
        return $resolved;
    }

    public function queue_benchmark_candidate( array $candidate ) {
        $signature = sanitize_key( (string) ( $candidate['signature'] ?? '' ) );
        if ( ! $signature ) { return new WP_Error( 'design_core_memory_benchmark_signature_required', 'A benchmark signature is required.' ); }
        $state = $this->snapshot();
        $fingerprint = substr( hash( 'sha256', $signature . '|' . (string) ( $candidate['source_fingerprint'] ?? '' ) . '|' . (string) ( $candidate['strategy'] ?? '' ) ), 0, 24 );
        foreach ( $state['benchmark_candidates'] as &$existing ) {
            if ( (string) ( $existing['fingerprint'] ?? '' ) !== $fingerprint ) { continue; }
            $existing['verified_hits'] = (int) ( $existing['verified_hits'] ?? 0 ) + max( 1, (int) ( $candidate['verified_hits'] ?? 1 ) );
            $existing['confidence'] = max( (float) ( $existing['confidence'] ?? 0 ), (float) ( $candidate['confidence'] ?? 0 ) );
            $existing['last_verified_at'] = gmdate( 'c' );
            $state['updated_at'] = gmdate( 'c' );
            update_option( self::OPTION_KEY, $this->bounded( $state ), false );
            return $existing;
        }
        unset( $existing );
        $entry = array(
            'fingerprint' => $fingerprint,
            'signature' => $signature,
            'strategy' => sanitize_key( (string) ( $candidate['strategy'] ?? '' ) ),
            'source_kind' => sanitize_key( (string) ( $candidate['source_kind'] ?? '' ) ),
            'source_fingerprint' => $this->fingerprint( $candidate['source_fingerprint'] ?? '' ),
            'confidence' => max( 0, min( 1, (float) ( $candidate['confidence'] ?? 0 ) ) ),
            'verified_hits' => max( 1, (int) ( $candidate['verified_hits'] ?? 1 ) ),
            'status' => 'candidate',
            'first_verified_at' => gmdate( 'c' ),
            'last_verified_at' => gmdate( 'c' ),
        );
        array_unshift( $state['benchmark_candidates'], $entry );
        $state['updated_at'] = gmdate( 'c' );
        $state['generation'] = (int) ( $state['generation'] ?? 0 ) + 1;
        update_option( self::OPTION_KEY, $this->bounded( $state ), false );
        return $entry;
    }

    public static function project_scope_key() {
        $url = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
        return $url ? substr( hash( 'sha256', strtolower( rtrim( $url, '/' ) ) ), 0, 24 ) : '';
    }

    public static function source_fingerprint( array $source ) {
        $file = sanitize_text_field( (string) ( $source['file_key'] ?? '' ) );
        $node = sanitize_text_field( (string) ( $source['node_id'] ?? '' ) );
        $kind = sanitize_key( (string) ( $source['kind'] ?? 'figma' ) );
        return ( $file || $node ) ? substr( hash( 'sha256', $kind . '|' . $file . '|' . $node ), 0, 24 ) : '';
    }

    private function sanitize_lesson( array $lesson ) {
        $signature = sanitize_key( (string) ( $lesson['signature'] ?? '' ) );
        $strategy = sanitize_key( (string) ( $lesson['strategy'] ?? '' ) );
        if ( ! $signature || ! $strategy ) { return new WP_Error( 'design_core_memory_lesson_invalid', 'Lesson signature and strategy are required.' ); }
        if ( ! Design_Core_Elementor_Fidelity_Rule_Registry::supports_strategy( $strategy ) ) {
            return new WP_Error( 'design_core_memory_strategy_not_allowed', 'The lesson strategy is not in the governed Fidelity Rule Registry.' );
        }
        if ( empty( $lesson['verified'] ) ) { return new WP_Error( 'design_core_memory_unverified_lesson', 'Design Memory accepts only verified lessons.' ); }
        $scope = $this->scope( $lesson['scope'] ?? 'global' );
        $scope_key = $this->scope_key( $lesson['scope_key'] ?? '' );
        if ( 'global' !== $scope && ! $scope_key ) { return new WP_Error( 'design_core_memory_scope_key_required', 'Project/source lessons require a scope key.' ); }
        $id = 'lesson-' . substr( hash( 'sha256', $scope . '|' . $scope_key . '|' . $signature . '|' . $strategy ), 0, 20 );
        return array(
            'id' => $id,
            'signature' => $signature,
            'strategy' => $strategy,
            'scope' => $scope,
            'scope_key' => $scope_key,
            'verified' => true,
            'confidence' => max( 0.5, min( 1, (float) ( $lesson['confidence'] ?? 0.8 ) ) ),
            'verified_hits' => max( 1, (int) ( $lesson['verified_hits'] ?? 1 ) ),
            'origin' => sanitize_key( (string) ( $lesson['origin'] ?? 'runtime' ) ),
            'source_kind' => sanitize_key( (string) ( $lesson['source_kind'] ?? '' ) ),
            'source_fingerprint' => $this->fingerprint( $lesson['source_fingerprint'] ?? '' ),
            'reason' => sanitize_text_field( (string) ( $lesson['reason'] ?? '' ) ),
            'evidence' => $this->safe_evidence( (array) ( $lesson['evidence'] ?? array() ) ),
            'first_verified_at' => sanitize_text_field( (string) ( $lesson['first_verified_at'] ?? gmdate( 'c' ) ) ),
            'last_verified_at' => gmdate( 'c' ),
            'min_core_version' => sanitize_text_field( (string) ( $lesson['min_core_version'] ?? '' ) ),
        );
    }

    private function upsert_lesson_into_state( array $state, array $lesson ) {
        if ( ! isset( $lesson['id'] ) ) {
            $lesson = $this->sanitize_lesson( $lesson );
            if ( is_wp_error( $lesson ) ) { return $state; }
        }
        foreach ( $state['lessons'] as &$existing ) {
            if ( (string) ( $existing['id'] ?? '' ) !== (string) $lesson['id'] ) { continue; }
            $existing['confidence'] = max( (float) ( $existing['confidence'] ?? 0 ), (float) ( $lesson['confidence'] ?? 0 ) );
            $existing['verified_hits'] = (int) ( $existing['verified_hits'] ?? 0 ) + max( 1, (int) ( $lesson['verified_hits'] ?? 1 ) );
            $existing['last_verified_at'] = gmdate( 'c' );
            $existing['reason'] = $lesson['reason'] ?: (string) ( $existing['reason'] ?? '' );
            $existing['evidence'] = array_merge( (array) ( $existing['evidence'] ?? array() ), (array) ( $lesson['evidence'] ?? array() ) );
            return $state;
        }
        unset( $existing );
        array_unshift( $state['lessons'], $lesson );
        return $state;
    }

    private function find_lesson( $id, array $state ) {
        foreach ( (array) ( $state['lessons'] ?? array() ) as $lesson ) { if ( (string) ( $lesson['id'] ?? '' ) === (string) $id ) { return $lesson; } }
        return null;
    }

    private function normalize_state( array $state ) {
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'seed_version' => (int) ( $state['seed_version'] ?? 0 ),
            'generation' => max( 0, (int) ( $state['generation'] ?? 0 ) ),
            'lessons' => array_values( array_filter( (array) ( $state['lessons'] ?? array() ), 'is_array' ) ),
            'incidents' => array_values( array_filter( (array) ( $state['incidents'] ?? array() ), 'is_array' ) ),
            'benchmark_candidates' => array_values( array_filter( (array) ( $state['benchmark_candidates'] ?? array() ), 'is_array' ) ),
            'updated_at' => sanitize_text_field( (string) ( $state['updated_at'] ?? '' ) ),
        );
    }

    private function bounded( array $state ) {
        $state = $this->normalize_state( $state );
        $state['lessons'] = array_slice( $state['lessons'], 0, self::MAX_LESSONS );
        $state['incidents'] = array_slice( $state['incidents'], 0, self::MAX_INCIDENTS );
        $state['benchmark_candidates'] = array_slice( $state['benchmark_candidates'], 0, self::MAX_BENCHMARK_CANDIDATES );
        return $state;
    }

    private function scope( $scope ) {
        $scope = sanitize_key( (string) $scope );
        return in_array( $scope, array( 'global', 'project', 'source' ), true ) ? $scope : 'project';
    }

    private function scope_key( $value ) {
        return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
    }

    private function fingerprint( $value ) {
        $value = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
        return substr( $value, 0, 64 );
    }

    private function safe_evidence( array $evidence ) {
        $safe = array();
        $allowed = array( 'similarity_before', 'similarity_after', 'target_similarity', 'viewport', 'delta_px', 'delta_ratio', 'issue_count', 'iteration_count', 'architecture_score', 'visual_score', 'responsive_status', 'interaction_status', 'ux_status', 'rule_version', 'note' );
        foreach ( $allowed as $key ) {
            if ( ! array_key_exists( $key, $evidence ) ) { continue; }
            $value = $evidence[ $key ];
            if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) { $safe[ $key ] = $value; }
            elseif ( is_scalar( $value ) ) { $safe[ $key ] = sanitize_text_field( (string) $value ); }
        }
        return $safe;
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Keeps architecture, responsive, visual, interaction and UX verification independent. */
class Design_Core_Elementor_Visual_Quality_Gate {
    const VERSION = 2;

    public function evaluate( array $dimensions, array $options = array() ) {
        $ref = ! empty( $options['reference_exists'] );
        $interactive = ! empty( $options['interactive'] );
        $n = array(
            'architecture' => $this->d( $dimensions['architecture'] ?? array(), true ),
            'responsive' => $this->d( $dimensions['responsive'] ?? array(), true ),
            'visual' => $this->d( $dimensions['visual'] ?? array(), $ref ),
            'interaction' => $this->d( $dimensions['interaction'] ?? array(), $interactive ),
            'ux' => $this->d( $dimensions['ux'] ?? array(), false ),
        );

        // Strict Figma verification may attach a browser-analysis failure to an
        // otherwise high screenshot score. A screenshot alone must never turn
        // that state into PASS because exact source-node ownership was not proven.
        $visual_details = is_array( $n['visual']['details'] ?? null ) ? $n['visual']['details'] : array();
        if ( $ref && ! empty( $visual_details['figma_geometry_analysis_error'] ) ) {
            $n['visual']['status'] = 'unavailable';
            $n['visual']['details']['strict_figma_block'] = 'Exact rendered Figma-node geometry analysis is unavailable.';
        }

        $blocking = array(); $scores = array();
        foreach ( $n as $name => $dimension ) {
            if ( $dimension['required'] && 'pass' !== $dimension['status'] ) { $blocking[] = $name . ':' . $dimension['status']; }
            if ( is_numeric( $dimension['score'] ) ) { $scores[] = (float) $dimension['score']; }
        }
        return array(
            'version' => self::VERSION,
            'status' => $blocking ? 'blocked' : 'pass',
            'publishable' => ! $blocking,
            'score' => $scores ? round( array_sum( $scores ) / count( $scores ), 2 ) : null,
            'dimensions' => $n,
            'blocking_reasons' => $blocking,
            'rule' => 'A required dimension must be verified and pass. Unavailable or unverified is never promoted to pass; strict Figma verification also requires rendered node-analysis availability.',
        );
    }

    private function d( $value, $required ) {
        $value = is_array( $value ) ? $value : array();
        $status = strtolower( (string) ( $value['status'] ?? 'unverified' ) );
        if ( in_array( $status, array( 'success', 'ok' ), true ) ) { $status = 'pass'; }
        if ( in_array( $status, array( 'needs-correction', 'needs-rebuild', 'warning' ), true ) ) { $status = 'fail'; }
        if ( ! in_array( $status, array( 'pass', 'fail', 'unverified', 'unavailable', 'not-applicable' ), true ) ) { $status = 'unverified'; }
        return array(
            'status' => $status,
            'required' => (bool) $required,
            'score' => isset( $value['score'] ) ? (float) $value['score'] : ( isset( $value['similarity'] ) ? round( (float) $value['similarity'] * 100, 2 ) : null ),
            'details' => $value,
        );
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Canonical quality corpus for measuring planning and visual fidelity over time. */
class Design_Core_Elementor_Design_Benchmark_Corpus {
    const VERSION = 1;

    public function catalog() {
        $items = array();
        foreach ( self::definitions() as $id => $definition ) {
            $file = DESIGN_CORE_ELEMENTOR_PATH . ltrim( (string) $definition['fixture'], '/' );
            $items[] = array_merge( array( 'id' => $id, 'available' => is_readable( $file ) ), $definition );
        }
        return array( 'version' => self::VERSION, 'benchmarks' => $items );
    }

    public function planning( $id ) {
        $id = sanitize_key( (string) $id ); $definitions = self::definitions();
        if ( ! isset( $definitions[ $id ] ) ) { return new WP_Error( 'design_core_benchmark_unknown', 'Unknown Design Core benchmark.' ); }
        $file = DESIGN_CORE_ELEMENTOR_PATH . ltrim( (string) $definitions[ $id ]['fixture'], '/' );
        if ( ! is_readable( $file ) ) { return new WP_Error( 'design_core_benchmark_fixture_missing', 'Benchmark fixture is unavailable.' ); }
        $source = (string) file_get_contents( $file );
        $css = ''; $html = $source;
        if ( preg_match( '/<style[^>]*>(.*?)<\/style>/is', $source, $match ) ) { $css = (string) $match[1]; }
        if ( preg_match( '/<body[^>]*>(.*?)<\/body>/is', $source, $match ) ) { $html = (string) $match[1]; }
        $preview = ( new Design_Core_Elementor_Build_Plan_Preview() )->preview_source( $html, $css, 'benchmark-' . $id, 'auto' );
        if ( is_wp_error( $preview ) ) { return $preview; }
        $expect = (array) ( $definitions[ $id ]['expect'] ?? array() ); $checks = array();
        if ( ! empty( $expect['layout_patterns'] ) ) {
            $actual = array_keys( (array) ( $preview['layout_intelligence']['patterns'] ?? array() ) );
            foreach ( (array) $expect['layout_patterns'] as $pattern ) { $checks['layout:' . $pattern] = in_array( $pattern, $actual, true ); }
        }
        $checks['safe-plan'] = ! empty( $preview['can_execute_safely'] );
        $passed = count( array_filter( $checks ) );
        return array( 'version' => self::VERSION, 'id' => $id, 'status' => $passed === count( $checks ) ? 'pass' : 'fail', 'checks' => $checks, 'preview' => $preview );
    }

    public function visual( $id, $candidate_target, array $context = array() ) {
        $id = sanitize_key( (string) $id ); $definitions = self::definitions();
        if ( ! isset( $definitions[ $id ] ) ) { return new WP_Error( 'design_core_benchmark_unknown', 'Unknown Design Core benchmark.' ); }
        $reference = DESIGN_CORE_ELEMENTOR_PATH . ltrim( (string) $definitions[ $id ]['fixture'], '/' );
        $context['target_similarity'] = (float) ( $definitions[ $id ]['target_similarity'] ?? 0.95 );
        $result = ( new Design_Core_Elementor_Visual_Feedback_Engine() )->evaluate_targets( $reference, $candidate_target, $context );
        if ( is_wp_error( $result ) ) { return $result; }
        return array( 'version' => self::VERSION, 'id' => $id, 'reference' => $definitions[ $id ]['fixture'], 'result' => $result );
    }

    public function summarize( array $results ) {
        $planning_pass = 0; $planning_total = 0; $visual_scores = array();
        foreach ( $results as $result ) {
            if ( isset( $result['checks'] ) ) { $planning_total++; if ( 'pass' === ( $result['status'] ?? '' ) ) { $planning_pass++; } }
            $visual = $result['result']['similarity'] ?? $result['similarity'] ?? null; if ( is_numeric( $visual ) ) { $visual_scores[] = (float) $visual; }
        }
        return array(
            'version' => self::VERSION,
            'planning' => array( 'passed' => $planning_pass, 'total' => $planning_total ),
            'visual' => array( 'samples' => count( $visual_scores ), 'average_similarity' => $visual_scores ? array_sum( $visual_scores ) / count( $visual_scores ) : null, 'minimum_similarity' => $visual_scores ? min( $visual_scores ) : null ),
        );
    }

    public static function definitions() {
        return array(
            'responsive-foundation' => array( 'label' => 'Responsive foundation', 'fixture' => 'tests/fixtures/responsive.html', 'target_similarity' => 0.95, 'expect' => array( 'layout_patterns' => array() ) ),
            'marketing-page' => array( 'label' => 'Marketing composition', 'fixture' => 'tests/fixtures/marketing.html', 'target_similarity' => 0.95, 'expect' => array( 'layout_patterns' => array() ) ),
            'hero-split' => array( 'label' => 'Hero 40/60 split', 'fixture' => 'tests/fixtures/benchmark-hero-split.html', 'target_similarity' => 0.97, 'expect' => array( 'layout_patterns' => array( 'split' ) ) ),
            'card-grid' => array( 'label' => 'Responsive card grid', 'fixture' => 'tests/fixtures/benchmark-card-grid.html', 'target_similarity' => 0.96, 'expect' => array( 'layout_patterns' => array( 'grid' ) ) ),
            'overlap-media' => array( 'label' => 'Overlapping media composition', 'fixture' => 'tests/fixtures/benchmark-overlap.html', 'target_similarity' => 0.95, 'expect' => array( 'layout_patterns' => array( 'overlap' ) ) ),
        );
    }
}

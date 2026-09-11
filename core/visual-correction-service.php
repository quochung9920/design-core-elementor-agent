<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Governed compare -> correct -> verify loop. */
class Design_Core_Elementor_Visual_Correction_Service {
    const VERSION = 2;

    public function run( $reference_target, $candidate_target, $max_iterations = 3, $target_similarity = 0.95, array $context = array() ) {
        $history = array();
        $max_iterations = max( 1, min( 5, (int) $max_iterations ) );
        $engine = new Design_Core_Elementor_Visual_Feedback_Engine();
        $page_id = (int) ( $context['page_id'] ?? 0 );
        if ( $page_id <= 0 && function_exists( 'url_to_postid' ) && preg_match( '#^https?://#i', (string) $candidate_target ) ) { $page_id = (int) url_to_postid( (string) $candidate_target ); }
        if ( $page_id > 0 && ! $this->candidate_matches_page( (string) $candidate_target, $page_id ) ) {
            return new WP_Error( 'design_core_visual_correction_candidate_mismatch', 'Automatic correction refused because the candidate target could not be proven to represent the requested Elementor page.' );
        }

        for ( $iteration = 1; $iteration <= $max_iterations; $iteration++ ) {
            $feedback_context = array_merge( $context, array( 'page_id' => $page_id, 'target_similarity' => (float) $target_similarity ) );
            $feedback = $engine->evaluate_targets( $reference_target, $candidate_target, $feedback_context );
            if ( is_wp_error( $feedback ) ) { return $feedback; }
            $entry = array( 'iteration' => $iteration, 'feedback' => $feedback, 'application' => array() );
            if ( 'pass' === ( $feedback['status'] ?? '' ) ) { $history[] = $entry; return array( 'version' => self::VERSION, 'status' => 'pass', 'iterations' => $history, 'similarity' => (float) ( $feedback['similarity'] ?? 0 ) ); }

            $directives = (array) ( $feedback['correction_plan'] ?? array() );
            $application = null;
            if ( $page_id > 0 && class_exists( 'Design_Core_Elementor_Visual_Correction_Applier' ) ) {
                $application = ( new Design_Core_Elementor_Visual_Correction_Applier() )->apply( $page_id, $directives, array( 'iteration' => $iteration ) );
                if ( is_wp_error( $application ) ) { $entry['application'] = array( 'status' => 'error', 'message' => $application->get_error_message() ); }
                else { $entry['application'] = $application; }
            }

            $applied = is_array( $application ) && 'applied' === ( $application['status'] ?? '' );
            if ( ! $applied ) {
                $external = apply_filters( 'design_core_elementor_apply_visual_corrections', false, $directives, $candidate_target, $iteration, $feedback );
                $applied = (bool) $external;
                if ( $external ) { $entry['application']['external_hook'] = true; }
            }
            $history[] = $entry;
            if ( ! $applied ) {
                return array(
                    'version' => self::VERSION,
                    'status' => 'needs-correction',
                    'iterations' => $history,
                    'similarity' => (float) ( $feedback['similarity'] ?? 0 ),
                    'directives' => $directives,
                    'reason' => $page_id <= 0 ? 'Candidate URL could not be resolved to an Elementor page for governed correction.' : 'No runtime-verified control changes could be applied.',
                );
            }
        }
        $last = end( $history ); $feedback = (array) ( $last['feedback'] ?? array() );
        return array( 'version' => self::VERSION, 'status' => 'max-iterations', 'iterations' => $history, 'similarity' => (float) ( $feedback['similarity'] ?? 0 ), 'directives' => (array) ( $feedback['correction_plan'] ?? array() ) );
    }

    private function candidate_matches_page( $candidate_target, $page_id ) {
        if ( ! preg_match( '#^https?://#i', $candidate_target ) ) { return false; }
        if ( function_exists( 'url_to_postid' ) ) {
            $resolved = (int) url_to_postid( $candidate_target );
            if ( $resolved > 0 ) { return $resolved === (int) $page_id; }
        }
        if ( ! function_exists( 'get_permalink' ) ) { return false; }
        $permalink = (string) get_permalink( (int) $page_id );
        if ( '' === $permalink ) { return false; }
        $candidate = function_exists( 'wp_parse_url' ) ? wp_parse_url( $candidate_target ) : parse_url( $candidate_target );
        $expected = function_exists( 'wp_parse_url' ) ? wp_parse_url( $permalink ) : parse_url( $permalink );
        if ( ! is_array( $candidate ) || ! is_array( $expected ) ) { return false; }
        $candidate_scheme = strtolower( (string) ( $candidate['scheme'] ?? '' ) ); $expected_scheme = strtolower( (string) ( $expected['scheme'] ?? '' ) );
        $candidate_host = strtolower( (string) ( $candidate['host'] ?? '' ) ); $expected_host = strtolower( (string) ( $expected['host'] ?? '' ) );
        $candidate_port = (int) ( $candidate['port'] ?? ( 'https' === $candidate_scheme ? 443 : 80 ) ); $expected_port = (int) ( $expected['port'] ?? ( 'https' === $expected_scheme ? 443 : 80 ) );
        $candidate_path = rtrim( (string) ( $candidate['path'] ?? '/' ), '/' ) ?: '/'; $expected_path = rtrim( (string) ( $expected['path'] ?? '/' ), '/' ) ?: '/';
        return $candidate_scheme === $expected_scheme && $candidate_host === $expected_host && $candidate_port === $expected_port && $candidate_path === $expected_path;
    }
}

<?php
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' ) ) { return; }

/** Strict Figma commands for Hermes/OpenCode/WSL agents. */
class Design_Core_Elementor_Figma_Fidelity_CLI {
    public function prepare( $args, $assoc_args ) {
        $url = (string) ( $args[0] ?? '' );
        if ( '' === $url ) { WP_CLI::error( 'Provide a Figma URL.' ); }
        $result = ( new Design_Core_Elementor_Figma_Agent_Service() )->prepare( $url );
        $this->print_result( $result );
    }

    public function compile( $args, $assoc_args ) {
        $url = (string) ( $args[0] ?? '' );
        if ( '' === $url ) { WP_CLI::error( 'Provide a Figma URL.' ); }
        $result = ( new Design_Core_Elementor_Figma_Agent_Service() )->compile( $url );
        $this->print_result( $result );
    }

    public function build( $args, $assoc_args ) {
        $url = (string) ( $args[0] ?? '' );
        if ( '' === $url ) { WP_CLI::error( 'Provide a Figma URL.' ); }
        $page_id = (int) ( $assoc_args['page-id'] ?? 0 );
        $options = array(
            'title' => sanitize_text_field( (string) ( $assoc_args['title'] ?? 'Figma Import' ) ),
            'target_similarity' => (float) ( $assoc_args['target-similarity'] ?? 0.95 ),
        );
        if ( ! empty( $assoc_args['candidate-target'] ) ) { $options['candidate_target'] = (string) $assoc_args['candidate-target']; }
        $result = ( new Design_Core_Elementor_Figma_Agent_Service() )->build_and_verify( $url, $page_id, $options );
        $this->print_result( $result );
        if ( is_array( $result ) && empty( $result['completion_allowed'] ) ) { WP_CLI::halt( 2 ); }
    }

    public function verify( $args, $assoc_args ) {
        $url = (string) ( $args[0] ?? '' );
        $candidate = (string) ( $args[1] ?? '' );
        if ( '' === $url || '' === $candidate ) { WP_CLI::error( 'Usage: wp design-core-figma verify <figma-url> <candidate-url>' ); }
        $result = ( new Design_Core_Elementor_Figma_Agent_Service() )->verify( $url, $candidate, array(
            'page_id' => (int) ( $assoc_args['page-id'] ?? 0 ),
            'target_similarity' => (float) ( $assoc_args['target-similarity'] ?? 0.95 ),
        ) );
        $this->print_result( $result );
        if ( is_array( $result ) && 'verified' !== ( $result['status'] ?? '' ) ) { WP_CLI::halt( 2 ); }
    }

    private function print_result( $result ) {
        if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); }
        WP_CLI::print_value( $result );
    }
}

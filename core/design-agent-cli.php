<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Dedicated local agent CLI for Hermes/OpenCode and operator verification. */
class Design_Core_Elementor_Design_Agent_CLI {
    public function status() { WP_CLI::print_value( ( new Design_Core_Elementor_Design_Agent_Service() )->capabilities() ); }

    public function plan( $args, $assoc ) {
        $brief = $assoc['brief'] ?? implode( ' ', $args );
        if ( ! $brief ) { WP_CLI::error( 'Provide --brief="...".' ); }
        $result = ( new Design_Core_Elementor_Design_Agent_Service() )->plan_design( $brief, array( 'product_type' => $assoc['product-type'] ?? '', 'mode' => $assoc['mode'] ?? 'light', 'reference_target' => $assoc['reference'] ?? '' ) );
        $this->print_result( $result );
    }

    /** `wp design-core-agent figma-prepare --url="https://figma.com/design/...?...node-id=..."` */
    public function figma_prepare( $args, $assoc ) {
        $url = (string) ( $assoc['url'] ?? $args[0] ?? '' );
        if ( ! $url ) { WP_CLI::error( 'Provide --url=<node-specific-figma-url>.' ); }
        $result = ( new Design_Core_Elementor_Design_Agent_Service() )->figma_prepare( $url, array( 'export_reference' => true, 'resolve_image_fills' => true, 'resolve_vector_assets' => true ) );
        $this->print_result( $result );
    }

    /** Compile the node to an exact native Elementor tree without writing. */
    public function figma_compile( $args, $assoc ) {
        $url = (string) ( $assoc['url'] ?? $args[0] ?? '' );
        if ( ! $url ) { WP_CLI::error( 'Provide --url=<node-specific-figma-url>.' ); }
        $result = ( new Design_Core_Elementor_Design_Agent_Service() )->figma_compile( $url, array( 'export_reference' => true, 'resolve_image_fills' => true, 'resolve_vector_assets' => true ) );
        $this->print_result( $result );
    }

    /** Build only to a draft. --confirm=1 is mandatory. */
    public function figma_build( $args, $assoc ) {
        if ( empty( $assoc['confirm'] ) ) { WP_CLI::error( 'Figma writes are draft-only and require --confirm=1.' ); }
        $url = (string) ( $assoc['url'] ?? $args[0] ?? '' );
        if ( ! $url ) { WP_CLI::error( 'Provide --url=<node-specific-figma-url>.' ); }
        $page_id = (int) ( $assoc['page-id'] ?? 0 );
        $options = array(
            'title' => (string) ( $assoc['title'] ?? 'Figma Import' ),
            'verify' => ! empty( $assoc['verify'] ),
            'target_similarity' => (float) ( $assoc['target'] ?? .95 ),
            'candidate_allowed_hosts' => $this->hosts( $assoc['asset-hosts'] ?? '' ),
            'candidate_target' => (string) ( $assoc['candidate'] ?? '' ),
        );
        $result = ( new Design_Core_Elementor_Design_Agent_Service() )->figma_build( $url, $page_id, $options );
        $this->print_result( $result );
    }

    /** Verify a rendered candidate against Figma's own exported PNG. */
    public function figma_verify( $args, $assoc ) {
        $url = (string) ( $assoc['url'] ?? $args[0] ?? '' );
        $candidate = (string) ( $assoc['candidate'] ?? $args[1] ?? '' );
        $page_id = (int) ( $assoc['page-id'] ?? 0 );
        if ( ! $url ) { WP_CLI::error( 'Provide --url=<node-specific-figma-url>.' ); }
        if ( ! $candidate && $page_id > 0 ) { $candidate = (string) get_permalink( $page_id ); }
        if ( ! $candidate ) { WP_CLI::error( 'Provide --candidate=<url-or-file> or --page-id=<draft-id>.' ); }
        $result = ( new Design_Core_Elementor_Design_Agent_Service() )->figma_verify( $url, $candidate, array( 'page_id' => $page_id, 'target_similarity' => (float) ( $assoc['target'] ?? .95 ), 'candidate_allowed_hosts' => $this->hosts( $assoc['asset-hosts'] ?? '' ) ) );
        $this->print_result( $result );
    }

    public function quality( $args, $assoc ) {
        $id = (int) ( $assoc['page-id'] ?? $args[0] ?? 0 );
        if ( $id < 1 ) { WP_CLI::error( 'Provide --page-id=<id>.' ); }
        $result = ( new Design_Core_Elementor_Design_Agent_Service() )->quality( $id, $assoc['reference'] ?? '', $assoc['candidate'] ?? '', array( 'target_similarity' => (float) ( $assoc['target'] ?? .95 ), 'interactive' => ! empty( $assoc['interactive'] ) ) );
        $this->print_result( $result );
    }

    public function visual_feedback( $args, $assoc ) {
        if ( count( $args ) < 2 ) { WP_CLI::error( 'Provide reference target and candidate target.' ); }
        $result = ( new Design_Core_Elementor_Visual_Feedback_Engine() )->evaluate_targets( $args[0], $args[1], array( 'page_id' => (int) ( $assoc['page-id'] ?? 0 ), 'target_similarity' => (float) ( $assoc['target'] ?? .95 ) ) );
        $this->print_result( $result );
    }

    public function visual_correct( $args, $assoc ) {
        if ( count( $args ) < 2 || empty( $assoc['confirm'] ) ) { WP_CLI::error( 'Provide reference, candidate and --confirm=1.' ); }
        $id = (int) ( $assoc['page-id'] ?? 0 );
        if ( $id < 1 ) { WP_CLI::error( 'Provide --page-id=<id>.' ); }
        $result = ( new Design_Core_Elementor_Visual_Correction_Service() )->run( $args[0], $args[1], max( 1, min( 5, (int) ( $assoc['iterations'] ?? 4 ) ) ), (float) ( $assoc['target'] ?? .95 ), array( 'page_id' => $id ) );
        $this->print_result( $result );
    }

    private function hosts( $raw ) { return array_values( array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) ) ); }
    private function print_result( $result ) { if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); } WP_CLI::print_value( $result ); }
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) { WP_CLI::add_command( 'design-core-agent', 'Design_Core_Elementor_Design_Agent_CLI' ); }

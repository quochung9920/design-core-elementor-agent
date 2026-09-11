<?php
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' ) ) { return; }

/** Local agent/WSL access to persistent Design Memory. */
class Design_Core_Elementor_Design_Memory_CLI {
    public function status() {
        WP_CLI::print_value( ( new Design_Core_Elementor_Design_Memory_Service() )->snapshot() );
    }

    public function lessons() {
        WP_CLI::print_value( ( new Design_Core_Elementor_Design_Memory_Store() )->lessons() );
    }

    public function incidents() {
        WP_CLI::print_value( ( new Design_Core_Elementor_Design_Memory_Store() )->incidents() );
    }

    public function recommend( $args, $assoc_args ) {
        $context = json_decode( (string) ( $assoc_args['json'] ?? '{}' ), true );
        if ( ! is_array( $context ) ) { WP_CLI::error( 'Context JSON is invalid.' ); }
        WP_CLI::print_value( ( new Design_Core_Elementor_Design_Memory_Service() )->recommend( $context, (int) ( $assoc_args['limit'] ?? 8 ) ) );
    }

    public function learn( $args, $assoc_args ) {
        $run = json_decode( (string) ( $assoc_args['json'] ?? '{}' ), true );
        if ( ! is_array( $run ) ) { WP_CLI::error( 'Run JSON is invalid.' ); }
        $result = ( new Design_Core_Elementor_Correction_Learning_Engine() )->learn( $run );
        if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); }
        WP_CLI::print_value( $result );
    }

    public function clear( $args, $assoc_args ) {
        if ( empty( $assoc_args['confirm'] ) ) { WP_CLI::error( 'Refusing to clear Design Memory without --confirm=1.' ); }
        $kind = sanitize_key( (string) ( $args[0] ?? 'all' ) );
        if ( ! in_array( $kind, array( 'all', 'lessons', 'incidents' ), true ) ) { WP_CLI::error( 'Kind must be all, lessons, or incidents.' ); }
        ( new Design_Core_Elementor_Design_Memory_Store() )->clear( $kind );
        WP_CLI::success( 'Design Memory cleared: ' . $kind );
    }
}

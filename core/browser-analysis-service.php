<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Browser_Analysis_Service {
    private const PROCESS_TIMEOUT_SECONDS = 60;
    private const MAX_OUTPUT_BYTES = 16777216;

    public function is_available() {
        if ( ! function_exists( 'proc_open' ) || ! function_exists( 'shell_exec' ) ) { return false; }
        $node = $this->find_binary( array( 'node', 'nodejs' ) );
        $script = DESIGN_CORE_ELEMENTOR_PATH . 'scripts/browser-analyze.mjs';
        $probe_script = DESIGN_CORE_ELEMENTOR_PATH . 'scripts/browser-probe.mjs';
        if ( ! $node || ! is_readable( $script ) || ! is_readable( $probe_script ) ) { return false; }
        // Probe from a real module file inside the plugin. `node -e import("playwright")`
        // resolves modules from the process CWD (often the WordPress root in Docker),
        // which can report a false negative even when plugin/node_modules is valid.
        $probe = $this->run_bounded_process( array( $node, $probe_script ), 10, 4096 );
        return ! empty( $probe['ok'] );
    }

    public function analyze_html( $html, $css = '', $viewports = array() ) {
        if ( ! $this->is_available() ) { return new WP_Error( 'design_core_browser_unavailable', 'Node/Playwright browser analysis is unavailable.' ); }
        if ( empty( $viewports ) ) { $viewports = $this->default_viewports(); }
        $viewports = $this->bounded_viewports( $viewports );
        $cache_key = 'dc_browser_' . substr( hash( 'sha256', (string) $html . "\n" . (string) $css . wp_json_encode( $viewports ) ), 0, 32 );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) { $cached['cache'] = 'hit'; return $cached; }

        $tmp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'design-core-browser.html' ) : tempnam( sys_get_temp_dir(), 'design-core-browser-' );
        if ( ! $tmp ) { return new WP_Error( 'design_core_temp_failed', 'Unable to create browser analysis file.' ); }
        file_put_contents( $tmp, '<!doctype html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>' . $html . '</body></html>', LOCK_EX );
        $result = $this->analyze_file( $tmp, $viewports ); @unlink( $tmp );
        if ( ! is_wp_error( $result ) ) { $result['cache'] = 'miss'; set_transient( $cache_key, $result, defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 ); }
        return $result;
    }

    public function analyze_file( $html_file, $viewports = array() ) {
        if ( ! $this->is_available() || ! is_readable( $html_file ) ) { return new WP_Error( 'design_core_browser_unavailable', 'Browser analyzer unavailable or HTML file unreadable.' ); }
        if ( empty( $viewports ) ) { $viewports = $this->default_viewports(); }
        $node = $this->find_binary( array( 'node', 'nodejs' ) ); $script = DESIGN_CORE_ELEMENTOR_PATH . 'scripts/browser-analyze.mjs';
        $viewports = $this->bounded_viewports( $viewports );
        $process = $this->run_bounded_process( array( $node, $script, realpath( $html_file ), implode( ',', $viewports ) ), self::PROCESS_TIMEOUT_SECONDS, self::MAX_OUTPUT_BYTES );
        if ( empty( $process['ok'] ) ) { return new WP_Error( 'design_core_browser_failed', (string) ( $process['error'] ?? 'Browser analyzer failed.' ) ); }
        $decoded = json_decode( $process['stdout'], true ); return is_array( $decoded ) ? $decoded : new WP_Error( 'design_core_browser_invalid', 'Browser analyzer returned invalid JSON.' );
    }

    public function analyze_target( $target, $viewports = array(), array $allowed_hosts = array() ) {
        if ( ! $this->is_available() ) { return new WP_Error( 'design_core_browser_unavailable', 'Node/Playwright browser analysis is unavailable.' ); }
        $script = DESIGN_CORE_ELEMENTOR_PATH . 'scripts/browser-analyze-target.mjs';
        if ( ! is_readable( $script ) ) { return new WP_Error( 'design_core_target_analyzer_missing', 'Rendered-target browser analyzer is unavailable.' ); }
        $target = $this->validate_target( $target );
        if ( is_wp_error( $target ) ) { return $target; }
        if ( empty( $viewports ) ) { $viewports = $this->default_viewports(); }
        $viewports = $this->bounded_viewports( $viewports );
        $hosts = array();
        foreach ( $allowed_hosts as $host ) { $host = strtolower( trim( (string) $host ) ); if ( preg_match( '/^[a-z0-9.-]+$/', $host ) ) { $hosts[] = $host; } }
        $node = $this->find_binary( array( 'node', 'nodejs' ) );
        $process = $this->run_bounded_process( array( $node, $script, (string) $target, implode( ',', $viewports ), implode( ',', array_values( array_unique( $hosts ) ) ) ), self::PROCESS_TIMEOUT_SECONDS, self::MAX_OUTPUT_BYTES );
        if ( empty( $process['ok'] ) ) { return new WP_Error( 'design_core_target_browser_failed', (string) ( $process['error'] ?? 'Rendered-target browser analyzer failed.' ) ); }
        $decoded = json_decode( $process['stdout'], true );
        return is_array( $decoded ) ? $decoded : new WP_Error( 'design_core_target_browser_invalid', 'Rendered-target browser analyzer returned invalid JSON.' );
    }

    private function validate_target( $target ) {
        $target = trim( (string) $target );
        if ( preg_match( '#^https?://#i', $target ) ) {
            // The site's own origin is intentionally allowed even when WordPress is
            // running on localhost/private IP in CI or local development. This does
            // not widen the remote SSRF policy to any other private target.
            if ( function_exists( 'home_url' ) ) {
                $target_parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $target ) : parse_url( $target );
                $home_parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( home_url( '/' ) ) : parse_url( home_url( '/' ) );
                if ( is_array( $target_parts ) && is_array( $home_parts ) ) {
                    $target_scheme = strtolower( (string) ( $target_parts['scheme'] ?? '' ) ); $home_scheme = strtolower( (string) ( $home_parts['scheme'] ?? '' ) );
                    $target_host = strtolower( (string) ( $target_parts['host'] ?? '' ) ); $home_host = strtolower( (string) ( $home_parts['host'] ?? '' ) );
                    $target_port = (int) ( $target_parts['port'] ?? ( 'https' === $target_scheme ? 443 : 80 ) ); $home_port = (int) ( $home_parts['port'] ?? ( 'https' === $home_scheme ? 443 : 80 ) );
                    if ( $target_scheme === $home_scheme && $target_host && $target_host === $home_host && $target_port === $home_port ) { return $target; }
                }
            }
            if ( class_exists( 'Design_Core_Elementor_Security_Policy' ) ) {
                $validated = ( new Design_Core_Elementor_Security_Policy() )->validate_remote_url( $target );
                return is_wp_error( $validated ) ? $validated : $validated;
            }
            return function_exists( 'esc_url_raw' ) ? esc_url_raw( $target ) : $target;
        }
        $path = 0 === strpos( $target, 'file://' ) ? substr( $target, 7 ) : $target;
        $real = realpath( $path );
        if ( ! $real || ! is_readable( $real ) ) { return new WP_Error( 'design_core_browser_target_invalid', 'Browser target must be a readable local file or an allowed HTTP(S) URL.' ); }
        $roots = array_filter( array( defined( 'ABSPATH' ) ? realpath( ABSPATH ) : '', defined( 'DESIGN_CORE_ELEMENTOR_PATH' ) ? realpath( DESIGN_CORE_ELEMENTOR_PATH ) : '', realpath( sys_get_temp_dir() ) ) );
        if ( function_exists( 'wp_upload_dir' ) ) { $upload = wp_upload_dir(); if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) { $roots[] = realpath( $upload['basedir'] ); } }
        foreach ( array_filter( array_unique( $roots ) ) as $root ) {
            $root = rtrim( (string) $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
            if ( 0 === strpos( $real . ( is_dir( $real ) ? DIRECTORY_SEPARATOR : '' ), $root ) || rtrim( $root, DIRECTORY_SEPARATOR ) === $real ) { return $real; }
        }
        return new WP_Error( 'design_core_browser_target_forbidden', 'Local browser target is outside the allowed WordPress/temp directories.' );
    }

    private function bounded_viewports( $viewports ) {
        $requested = array_values( array_unique( array_filter( array_map( 'intval', (array) $viewports ), static function ( $width ) { return 240 <= $width && 7680 >= $width; } ) ) );
        $ordered = array(); foreach ( array( 1440, 1366, 1024, 767, 390 ) as $governed ) { if ( in_array( $governed, $requested, true ) ) { $ordered[] = $governed; } }
        foreach ( $requested as $width ) { if ( ! in_array( $width, $ordered, true ) ) { $ordered[] = $width; } }
        return array_slice( $ordered, 0, 8 );
    }

    private function default_viewports() {
        $viewports = array_values( ( new Design_Core_Elementor_Breakpoint_Registry() )->viewport_matrix() );
        if ( class_exists( 'Design_Core_Elementor_Global_Layout_Standard' ) ) { foreach ( ( new Design_Core_Elementor_Global_Layout_Standard() )->devices() as $device ) { $viewports[] = (int) $device['container_width']; } }
        $viewports[] = 390; $viewports = array_values( array_unique( array_filter( array_map( 'intval', $viewports ) ) ) ); rsort( $viewports, SORT_NUMERIC ); return $viewports;
    }

    private function find_binary( $names ) {
        if ( ! function_exists( 'shell_exec' ) ) { return ''; }
        foreach ( $names as $name ) { $path = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' ) ); if ( $path ) { return $path; } }
        return '';
    }

    private function run_bounded_process( $command, $timeout_seconds, $max_output_bytes ) {
        $descriptors = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ); $pipes = array();
        $process = @proc_open( $command, $descriptors, $pipes );
        if ( ! is_resource( $process ) ) { return array( 'ok' => false, 'error' => 'Unable to start browser analyzer process.' ); }
        stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false );
        $stdout = ''; $stderr = ''; $started = microtime( true ); $exit_code = -1; $error = '';
        while ( true ) {
            $stdout .= (string) stream_get_contents( $pipes[1] ); $stderr .= (string) stream_get_contents( $pipes[2] );
            if ( strlen( $stdout ) + strlen( $stderr ) > $max_output_bytes ) { proc_terminate( $process, 9 ); $error = 'Browser analyzer output exceeded the allowed limit.'; break; }
            if ( microtime( true ) - $started > $timeout_seconds ) { proc_terminate( $process, 9 ); $error = 'Browser analyzer exceeded the allowed execution time.'; break; }
            $status = proc_get_status( $process ); if ( ! $status['running'] ) { $exit_code = (int) $status['exitcode']; break; } usleep( 10000 );
        }
        $stdout .= (string) stream_get_contents( $pipes[1] ); $stderr .= (string) stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); proc_close( $process );
        if ( '' !== $error ) { return array( 'ok' => false, 'error' => $error ); }
        if ( 0 !== $exit_code ) { return array( 'ok' => false, 'error' => substr( trim( $stderr . "\n" . $stdout ), 0, 4096 ) ); }
        return array( 'ok' => true, 'stdout' => $stdout );
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Deterministic browser/reference capture for rendered visual fidelity. */
class Design_Core_Elementor_Screenshot_Service {
    const VERSION = 2;
    const MAX_REFERENCE_BYTES = 20971520;

    public function capture( $target, $width, $output, array $options = array() ) {
        if ( ! empty( $options['direct_image'] ) ) { return $this->capture_direct_image( $target, $output, $width ); }
        if ( ! function_exists( 'exec' ) || ! function_exists( 'shell_exec' ) ) { return new WP_Error( 'design_core_capture_unavailable', 'Process execution is disabled.' ); }
        $node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
        $script = DESIGN_CORE_ELEMENTOR_PATH . 'scripts/capture-page.mjs';
        if ( ! $node || ! is_readable( $script ) ) { return new WP_Error( 'design_core_capture_unavailable', 'Node/Playwright screenshot capture is unavailable.' ); }
        $width = max( 320, min( 3840, absint( $width ) ) );
        $selector = trim( (string) ( $options['selector'] ?? '' ) );
        if ( strlen( $selector ) > 512 ) { return new WP_Error( 'design_core_capture_selector_invalid', 'Capture selector is too long.' ); }
        $hosts = array();
        foreach ( (array) ( $options['allowed_asset_hosts'] ?? array() ) as $host ) {
            $host = strtolower( trim( (string) $host ) );
            if ( preg_match( '/^[a-z0-9.-]+$/', $host ) ) { $hosts[] = $host; }
        }
        $command = escapeshellarg( $node ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $target ) . ' ' . $width . ' ' . escapeshellarg( $output ) . ' ' . escapeshellarg( $selector ) . ' ' . escapeshellarg( implode( ',', array_values( array_unique( $hosts ) ) ) );
        $lines = array(); $code = 1; exec( $command . ' 2>&1', $lines, $code );
        if ( 0 !== $code || ! is_readable( $output ) ) { return new WP_Error( 'design_core_capture_failed', implode( "\n", $lines ) ?: 'Browser capture failed.' ); }
        $meta = json_decode( implode( "\n", $lines ), true );
        return array( 'output' => $output, 'width' => $width, 'selector' => $selector, 'meta' => is_array( $meta ) ? $meta : array() );
    }

    /**
     * Compare targets at either the standard breakpoint matrix or an explicit
     * Figma/source viewport list. Each side may be a page or a direct PNG/JPG.
     */
    public function compare_targets( $reference_target, $candidate_target, $workdir = '', array $options = array() ) {
        $upload = wp_upload_dir();
        $workdir = $workdir ?: trailingslashit( $upload['basedir'] ) . 'design-core-visual';
        if ( ! wp_mkdir_p( $workdir ) && ! is_dir( $workdir ) ) { return new WP_Error( 'design_core_visual_dir', 'Unable to create visual QA directory.' ); }

        $viewports = array();
        if ( ! empty( $options['viewports'] ) ) {
            foreach ( (array) $options['viewports'] as $name => $value ) {
                $width = is_numeric( $value ) ? (int) $value : ( is_numeric( $name ) ? (int) $name : 0 );
                if ( 320 <= $width && 3840 >= $width ) { $viewports[ is_string( $name ) ? sanitize_key( $name ) : 'source' ] = $width; }
            }
        }
        if ( ! $viewports ) { $viewports = ( new Design_Core_Elementor_Breakpoint_Registry() )->viewport_matrix(); }

        $compare = new Design_Core_Elementor_Visual_Regression_Service(); $results = array();
        foreach ( $viewports as $name => $width ) {
            $safe = sanitize_key( (string) $name ) . '-' . (int) $width;
            $ref = trailingslashit( $workdir ) . 'reference-' . $safe . '.png';
            $cand = trailingslashit( $workdir ) . 'candidate-' . $safe . '.png';
            $a = $this->capture( $reference_target, $width, $ref, array(
                'direct_image' => ! empty( $options['reference_direct_image'] ),
                'selector' => (string) ( $options['reference_selector'] ?? '' ),
                'allowed_asset_hosts' => (array) ( $options['reference_allowed_hosts'] ?? array() ),
            ) );
            $b = $this->capture( $candidate_target, $width, $cand, array(
                'direct_image' => ! empty( $options['candidate_direct_image'] ),
                'selector' => (string) ( $options['candidate_selector'] ?? '' ),
                'allowed_asset_hosts' => (array) ( $options['candidate_allowed_hosts'] ?? array() ),
            ) );
            if ( is_wp_error( $a ) || is_wp_error( $b ) ) {
                $results[ $width ] = array( 'status' => 'failed', 'viewport' => $name, 'error' => is_wp_error( $a ) ? $a->get_error_message() : $b->get_error_message() );
                continue;
            }
            $diff = $compare->compare( $ref, $cand );
            $results[ $width ] = is_wp_error( $diff ) ? array( 'status' => 'failed', 'viewport' => $name, 'error' => $diff->get_error_message() ) : array_merge( array( 'status' => 'success', 'viewport' => $name, 'reference_capture' => $a, 'candidate_capture' => $b ), $diff );
        }
        return $results;
    }

    private function capture_direct_image( $target, $output, $width ) {
        $target = trim( (string) $target );
        $path = 0 === strpos( $target, 'file://' ) ? substr( $target, 7 ) : $target;
        if ( ! preg_match( '#^https?://#i', $target ) ) {
            $real = realpath( $path );
            if ( ! $real || ! is_readable( $real ) ) { return new WP_Error( 'design_core_reference_image_missing', 'Reference image is not readable.' ); }
            if ( ! @copy( $real, $output ) ) { return new WP_Error( 'design_core_reference_copy_failed', 'Unable to copy reference image.' ); }
        } else {
            if ( ! function_exists( 'wp_safe_remote_get' ) ) { return new WP_Error( 'design_core_reference_http_unavailable', 'WordPress HTTP API is unavailable.' ); }
            $response = wp_safe_remote_get( $target, array( 'timeout' => 30, 'redirection' => 4, 'limit_response_size' => self::MAX_REFERENCE_BYTES, 'user-agent' => 'Design-Core-Elementor/' . ( defined( 'DESIGN_CORE_ELEMENTOR_VERSION' ) ? DESIGN_CORE_ELEMENTOR_VERSION : 'dev' ) ) );
            if ( is_wp_error( $response ) ) { return $response; }
            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = (string) wp_remote_retrieve_body( $response );
            if ( $code < 200 || $code >= 300 || '' === $body || strlen( $body ) >= self::MAX_REFERENCE_BYTES ) { return new WP_Error( 'design_core_reference_download_failed', 'Unable to download the bounded reference image.' ); }
            if ( ! $this->looks_like_image( $body ) ) { return new WP_Error( 'design_core_reference_image_invalid', 'Downloaded reference is not a supported PNG/JPEG image.' ); }
            if ( false === file_put_contents( $output, $body, LOCK_EX ) ) { return new WP_Error( 'design_core_reference_write_failed', 'Unable to write reference image.' ); }
        }
        $size = function_exists( 'getimagesize' ) ? @getimagesize( $output ) : false;
        if ( false === $size ) { @unlink( $output ); return new WP_Error( 'design_core_reference_image_invalid', 'Reference image could not be decoded.' ); }
        return array( 'output' => $output, 'width' => (int) ( $size[0] ?? $width ), 'height' => (int) ( $size[1] ?? 0 ), 'direct_image' => true );
    }

    private function looks_like_image( $body ) {
        return 0 === strncmp( $body, "\x89PNG\r\n\x1a\n", 8 ) || 0 === strncmp( $body, "\xFF\xD8\xFF", 3 );
    }
}

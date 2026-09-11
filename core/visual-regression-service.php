<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Visual_Regression_Service {
    public function compare( $reference, $candidate ) {
        if ( ! is_readable( $reference ) || ! is_readable( $candidate ) ) { return new WP_Error( 'design_core_visual_missing', 'Reference or candidate image is missing.' ); }
        if ( ! function_exists( 'exec' ) || ! function_exists( 'shell_exec' ) ) { return new WP_Error( 'design_core_visual_unavailable', 'Process execution is disabled.' ); }
        $node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
        $script = DESIGN_CORE_ELEMENTOR_PATH . 'scripts/visual-compare.mjs';
        if ( ! $node || ! is_readable( $script ) ) { return new WP_Error( 'design_core_visual_unavailable', 'Node visual comparison is unavailable.' ); }
        $command = escapeshellarg( $node ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( realpath( $reference ) ) . ' ' . escapeshellarg( realpath( $candidate ) );
        $output = array(); $code = 1; exec( $command . ' 2>&1', $output, $code );
        if ( 0 !== $code ) { return new WP_Error( 'design_core_visual_failed', implode( "\n", $output ) ); }
        $result = json_decode( implode( "\n", $output ), true );
        return is_array( $result ) ? $result : new WP_Error( 'design_core_visual_invalid', 'Visual comparison returned invalid JSON.' );
    }
}

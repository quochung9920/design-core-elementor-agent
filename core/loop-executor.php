<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Loop_Executor {
    public function execute( $component, $capabilities ) {
        if ( empty( $capabilities['elementor']['loop_available'] ) ) {
            return array( 'status' => 'unsupported', 'elements' => array(), 'reason' => 'Elementor Loop capability is unavailable.' );
        }

        // Formal, staged Elementor Pro integration (Pro_Loop_Adapter) first -- a site can register
        // template/query-binding/loop-grid creation as three distinct, auditable stages.
        $adapter = new Design_Core_Elementor_Pro_Loop_Adapter();
        if ( $adapter->supports() ) {
            $result = $adapter->execute( $component, $capabilities );
            if ( 'success' === ( $result['status'] ?? '' ) ) { return $result; }
        }

        /**
         * Legacy single-filter integration path, kept for backward compatibility: a site can
         * still supply the whole result in one callback instead of the three staged ones above.
         * A real Loop Item requires runtime-specific Elementor/Pro APIs and a query source, so
         * Design Core never invents one -- absent either integration, this defers explicitly.
         */
        $result = apply_filters( 'design_core_elementor_execute_loop', null, $component, $capabilities );
        if ( is_array( $result ) && ! empty( $result['elements'] ) ) {
            return array_merge( array( 'status' => 'success' ), $result );
        }
        return array(
            'status' => 'deferred',
            'elements' => array(),
            'reason' => 'Loop was selected, but no runtime Loop integration supplied a concrete template/query. Native composition should be used instead of inventing private Elementor data.',
        );
    }
}

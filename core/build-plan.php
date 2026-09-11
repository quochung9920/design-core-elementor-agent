<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Build_Plan {
    const SCHEMA_VERSION = 1;
    public static function create( $items, $diagnostics = array() ) {
        return array( 'build_plan_schema_version' => self::SCHEMA_VERSION, 'items' => array_values( $items ), 'diagnostics' => is_array( $diagnostics ) ? $diagnostics : array() );
    }
}

class Design_Core_Elementor_Build_Plan_Item {
    public static function create( $node_id, $strategy, $adapter_target, $overrides = array() ) {
        return array_replace_recursive( array(
            'node_id' => (string) $node_id,
            'component_id' => '',
            'strategy' => (string) $strategy,
            'adapter_target' => (string) $adapter_target,
            'reuse' => array( 'registry_type' => '', 'registry_id' => '', 'score' => 0.0, 'reasons' => array() ),
            'variant' => array( 'action' => 'new', 'id' => '' ),
            'content_bindings' => array(), 'style_bindings' => array(), 'responsive_bindings' => array(), 'asset_bindings' => array(),
            'fallback' => array( 'allowed' => false, 'strategy' => '', 'reason' => '' ),
            'diagnostics' => array(),
        ), is_array( $overrides ) ? $overrides : array() );
    }
}

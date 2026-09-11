<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface Design_Core_Elementor_Global_Style_Adapter_Interface {
    public function target();
    public function supports( $capability );
    public function sync( $design_system, $context = array() );
}

class Design_Core_Elementor_V3_Global_Adapter implements Design_Core_Elementor_Global_Style_Adapter_Interface {
    public function target() { return 'elementor-v3'; }
    public function supports( $capability ) { return 'kit-globals' === $capability; }
    public function sync( $design_system, $context = array() ) { return ( new Design_Core_Elementor_Global_Style_Bridge() )->sync_v3_kit(); }
    public function apply_references( $elements, $sync ) { return ( new Design_Core_Elementor_Global_Style_Bridge() )->apply_v3_references( $elements, $sync ); }
}

class Design_Core_Elementor_V4_Global_Adapter implements Design_Core_Elementor_Global_Style_Adapter_Interface {
    public function target() { return 'elementor-v4'; }
    public function supports( $capability ) { return function_exists( 'wp_get_ability' ) && (bool) wp_get_ability( 'elementor/manage-classes' ); }
    public function sync( $design_system, $context = array() ) { return ( new Design_Core_Elementor_Global_Style_Bridge() )->sync_v4_classes(); }
}

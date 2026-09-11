<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Capability_Scanner {
    public function scan() {
        $elementor_version = 'not-installed'; $pro_version = 'not-installed'; $editor_mode = 'v3';
        $widgets = array(); $controls = array(); $components_available = false; $loop_available = false; $atomic_build_ability = false; $manage_classes_ability = false;

        if ( class_exists( '\Elementor\Plugin' ) ) {
            $plugin = \Elementor\Plugin::instance();
            $elementor_version = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : ( isset( $plugin->version ) ? (string) $plugin->version : 'unknown' );
            if ( isset( $plugin->widgets_manager ) && method_exists( $plugin->widgets_manager, 'get_widget_types' ) ) { $widgets = array_keys( $plugin->widgets_manager->get_widget_types() ); }
            if ( isset( $plugin->controls_manager ) && method_exists( $plugin->controls_manager, 'get_controls' ) ) { $controls = array_keys( $plugin->controls_manager->get_controls() ); }

            $atomic_detected = class_exists( '\Elementor\Modules\AtomicWidgets\Module' ) || class_exists( '\Elementor\Modules\AtomicWidgets\Elements\Atomic_Element_Base' );
            if ( function_exists( 'wp_get_ability' ) ) {
                $atomic_build_ability = (bool) wp_get_ability( 'elementor/build-composition' );
                $components_available = (bool) wp_get_ability( 'elementor/list-components' );
                $manage_classes_ability = (bool) wp_get_ability( 'elementor/manage-classes' );
            }
            if ( ! $components_available ) { $components_available = class_exists( '\Elementor\Modules\Components\Module' ); }
            $loop_available = class_exists( '\ElementorPro\Modules\LoopBuilder\Module' ) || class_exists( '\Elementor\Modules\Loop\Module' );
            $editor_mode = $atomic_detected && $atomic_build_ability ? 'v4' : 'v3';
            $editor_mode = apply_filters( 'design_core_elementor_detected_editor_mode', $editor_mode, $plugin );
        }
        if ( class_exists( '\ElementorPro\Plugin' ) ) {
            $pro = \ElementorPro\Plugin::instance();
            $pro_version = defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : ( isset( $pro->version ) ? (string) $pro->version : 'unknown' );
        }

        $breakpoints = ( new Design_Core_Elementor_Breakpoint_Registry() )->all();
        $kit_global_sync = false;
        if ( class_exists( '\Elementor\Plugin' ) ) {
            try {
                $kit = \Elementor\Plugin::instance()->kits_manager->get_active_kit();
                $kit_global_sync = $kit && method_exists( $kit, 'update_settings' );
            } catch ( Throwable $e ) { $kit_global_sync = false; }
        }

        return array(
            'wordpress'=>array( 'version'=>get_bloginfo( 'version' ) ),
            'php'=>array( 'version'=>PHP_VERSION ),
            'elementor'=>array(
                'version'=>$elementor_version,'pro_version'=>$pro_version,'editor_mode'=>in_array($editor_mode,array('v3','v4','mixed'),true)?$editor_mode:'v3',
                'components_available'=>$components_available,'loop_available'=>$loop_available,'atomic_build_ability'=>$atomic_build_ability,'manage_classes_ability'=>$manage_classes_ability,
                'registered_widgets'=>array_values($widgets),'registered_controls'=>array_values($controls),'breakpoints'=>$breakpoints,
            ),
            'capabilities'=>array(
                'container'=>class_exists('\Elementor\Includes\Elements\Container') || in_array('container',$widgets,true),
                'responsive'=>!empty($breakpoints),'loop'=>$loop_available,'components'=>$components_available,'atomic'=>$atomic_detected ?? false,
                'atomic_build_composition'=>$atomic_build_ability,'atomic_manage_classes'=>$manage_classes_ability,
                'global_colors'=>$kit_global_sync,'global_typography'=>$kit_global_sync,'kit_global_sync'=>$kit_global_sync,
                'browser_analysis'=>( new Design_Core_Elementor_Browser_Analysis_Service() )->is_available(),
            ),
        );
    }
}

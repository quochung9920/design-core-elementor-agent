<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Local-only core: Elementor widget/category registration plus registry defaults.
 * No wp-admin UI, no REST routes, no remote abilities. The agent operates
 * this plugin via WP-CLI (`wp design-core ...`) and direct PHP calls.
 */
class Design_Core_Elementor_Plugin {
    private static $instance = null;
    public static function instance() { if ( null === self::$instance ) { self::$instance = new self(); } return self::$instance; }

    public function __construct() { $this->register_hooks(); $this->ensure_registry_defaults(); $this->ensure_capabilities(); }

    public static function require_widget_base_classes() {
        if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) { return; }
        if ( ! class_exists( 'Design_Core_Elementor_Widget_Base' ) ) { require_once DESIGN_CORE_ELEMENTOR_PATH . 'widgets/base/class-design-core-widget-base.php'; }
        if ( ! class_exists( 'Design_Core_Elementor_Runtime_Widget' ) ) { require_once DESIGN_CORE_ELEMENTOR_PATH . 'widgets/base/class-design-core-runtime-widget.php'; }
        require_once DESIGN_CORE_ELEMENTOR_PATH . 'widgets/class-global-time-bar.php';
        foreach ( glob( DESIGN_CORE_ELEMENTOR_PATH . 'widgets/generated/*.php' ) ?: array() as $file ) { require_once $file; }
    }

    private function register_hooks() {
        add_action( 'elementor/elements/categories_registered', array( $this, 'register_elementor_category' ) );
        add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widgets' ) );
        add_action( 'elementor/frontend/after_register_scripts', array( $this, 'register_frontend_assets' ) );
        add_action( 'elementor/frontend/after_register_styles', array( $this, 'register_frontend_assets' ) );
    }

    private function ensure_registry_defaults() {
        ( new Design_Core_Elementor_Component_Registry() )->ensure_defaults();
        if ( class_exists( 'Design_Core_Elementor_Section_Registry' ) ) { ( new Design_Core_Elementor_Section_Registry() )->ensure_defaults(); }
        ( new Design_Core_Elementor_Widget_Registry() )->ensure_defaults();
        ( new Design_Core_Elementor_Token_Registry() )->ensure_defaults();
    }

    /**
     * register_activation_hook() only fires on activate/deactivate-reactivate, never on an
     * in-place file update of an already-active plugin -- so an upgrade also runs this
     * versioned, idempotent check on every load (cheap: one get_option() in the common case).
     */
    private function ensure_capabilities() {
        if ( class_exists( 'Design_Core_Elementor_Capabilities' ) ) { Design_Core_Elementor_Capabilities::migrate(); }
    }

    public function register_frontend_assets() {
        wp_register_style( 'design-core-global-time-bar', DESIGN_CORE_ELEMENTOR_URL . 'assets/global-time-bar.css', array(), DESIGN_CORE_ELEMENTOR_VERSION );
        wp_register_script( 'design-core-global-time-bar', DESIGN_CORE_ELEMENTOR_URL . 'assets/global-time-bar.js', array(), DESIGN_CORE_ELEMENTOR_VERSION, true );
    }

    public function register_elementor_category( $manager ) { $manager->add_category( 'design-core', array( 'title'=>'Design Core', 'icon'=>'eicon-kit' ) ); }

    public function register_elementor_widgets( $widgets_manager ) {
        self::require_widget_base_classes();
        foreach ( get_declared_classes() as $class ) {
            if ( 'Design_Core_Elementor_Runtime_Widget' === $class ) { continue; }
            if ( 0 !== strpos( $class, 'Design_Core_Elementor_' ) || ! is_subclass_of( $class, 'Design_Core_Elementor_Widget_Base' ) ) { continue; }
            try { $widgets_manager->register( new $class() ); } catch ( Throwable $e ) { do_action( 'design_core_elementor_widget_registration_error', $class, $e ); }
        }
        if ( class_exists( 'Design_Core_Elementor_Runtime_Widget' ) ) {
            foreach ( ( new Design_Core_Elementor_Widget_Registry() )->all() as $definition ) {
                if ( 'design-core-runtime' !== ( $definition['source']['kind'] ?? $definition['source'] ?? '' ) ) { continue; }
                try { $widgets_manager->register( new Design_Core_Elementor_Runtime_Widget( array(), array( 'widgetType' => $definition['slug'] ?? '' ) ) ); } catch ( Throwable $e ) { do_action( 'design_core_elementor_widget_registration_error', $definition['slug'] ?? 'runtime', $e ); }
            }
        }
    }

    public function get_capability_report() { return ( new Design_Core_Elementor_Capability_Scanner() )->scan(); }
}

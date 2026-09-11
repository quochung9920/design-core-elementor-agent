<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Design_Core_Elementor_Loader {
    private static $registered = false;

    public static function register() {
        if ( self::$registered ) {
            return;
        }
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
        self::$registered = true;
    }

    public static function autoload( $class ) {
        $prefix = 'Design_Core_Elementor_';
        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }

        $relative = strtolower( str_replace( '_', '-', substr( $class, strlen( $prefix ) ) ) );
        $path = DESIGN_CORE_ELEMENTOR_PATH . 'core/' . $relative . '.php';
        if ( file_exists( $path ) ) {
            require_once $path;
            return;
        }

        $fallback_path = DESIGN_CORE_ELEMENTOR_PATH . 'includes/class-' . $relative . '.php';
        if ( file_exists( $fallback_path ) ) {
            require_once $fallback_path;
        }
    }
}

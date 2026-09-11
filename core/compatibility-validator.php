<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Compatibility_Validator {
    public function validate() {
        $issues = array();
        if ( version_compare( PHP_VERSION, '8.1', '<' ) ) { $issues[] = array( 'severity'=>'critical', 'code'=>'php-version', 'message'=>'PHP 8.1 or newer is required.' ); }
        global $wp_version;
        if ( isset( $wp_version ) && version_compare( $wp_version, '6.5', '<' ) ) { $issues[] = array( 'severity'=>'critical', 'code'=>'wp-version', 'message'=>'WordPress 6.5 or newer is required.' ); }
        if ( ! class_exists( '\Elementor\Plugin' ) ) { $issues[] = array( 'severity'=>'critical', 'code'=>'elementor-missing', 'message'=>'Elementor is not active.' ); }
        $caps = class_exists( 'Design_Core_Elementor_Capability_Scanner' ) ? ( new Design_Core_Elementor_Capability_Scanner() )->scan() : array();
        $mode = $caps['elementor']['editor_mode'] ?? 'v3';
        if ( in_array( $mode, array( 'v4', 'mixed' ), true ) && empty( $caps['capabilities']['atomic_build_composition'] ) ) {
            $issues[] = array( 'severity'=>'warning', 'code'=>'atomic-api-unavailable', 'message'=>'Atomic runtime detected but public build-composition ability is unavailable; V3 fallback will be used.' );
        }
        return array( 'compatible'=>0===count(array_filter($issues,static function($i){return 'critical'===($i['severity']??'');})), 'issues'=>$issues, 'capabilities'=>$caps );
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Compact `GET /site/status` read model. Composes existing scanners; never returns secrets. */
class Design_Core_Elementor_Site_Status {
    const MCP_API_VERSION = 2; // legacy compatibility surface

    public function report() {
        $capabilities = ( new Design_Core_Elementor_Capability_Scanner() )->scan();
        $figma = new Design_Core_Elementor_Figma_Transport();
        $readiness = ( new Design_Core_Elementor_Production_Readiness() )->audit();
        $settings = Design_Core_Elementor_Remote_Settings::get();
        $design_intelligence = class_exists( 'Design_Core_Elementor_Design_Intelligence_Catalog' )
            ? ( new Design_Core_Elementor_Design_Intelligence_Catalog() )->status()
            : array( 'available'=>false, 'profiles'=>0, 'ux_rules'=>0 );
        $wordpress_mcp = class_exists( 'Design_Core_Elementor_WordPress_MCP_Compatibility' )
            ? ( new Design_Core_Elementor_WordPress_MCP_Compatibility() )->status()
            : array( 'abilities_api'=>false, 'official_mcp_adapter'=>false, 'public_abilities'=>array() );
        $css_ast = class_exists( 'Design_Core_Elementor_CSS_AST_Service' ) ? new Design_Core_Elementor_CSS_AST_Service() : null;
        $owner_api_available = class_exists( 'Design_Core_Elementor_GPT_Actions_API' );

        return array(
            'plugin_version' => DESIGN_CORE_ELEMENTOR_VERSION,
            'api' => array(
                'available' => $owner_api_available,
                'version' => $owner_api_available ? Design_Core_Elementor_GPT_Actions_API::VERSION : '',
                'transport' => 'https-rest',
                'namespace' => $owner_api_available ? Design_Core_Elementor_GPT_Actions_API::REST_NAMESPACE : '',
                'enabled' => class_exists( 'Design_Core_Elementor_API_Access_Settings' ) ? Design_Core_Elementor_API_Access_Settings::enabled() : false,
                'owner_claimed' => class_exists( 'Design_Core_Elementor_API_Access_Settings' ) ? Design_Core_Elementor_API_Access_Settings::owner_claimed() : false,
                'operation_count' => $owner_api_available ? count( Design_Core_Elementor_GPT_Actions_API::operations() ) : 0,
                'mcp_required' => false,
            ),
            'mcp_api_version' => self::MCP_API_VERSION,
            'wordpress_version' => (string) ( $capabilities['wordpress']['version'] ?? '' ),
            'elementor_version' => (string) ( $capabilities['elementor']['version'] ?? 'not-installed' ),
            'elementor_pro_version' => (string) ( $capabilities['elementor']['pro_version'] ?? 'not-installed' ),
            'editor_mode' => (string) ( $capabilities['elementor']['editor_mode'] ?? 'v3' ),
            'browser_analysis' => (bool) ( $capabilities['capabilities']['browser_analysis'] ?? false ),
            'figma_configured' => $figma->configured(),
            'figma_adapter_version' => class_exists( 'Design_Core_Elementor_Figma_Design_IR_Adapter' ) ? Design_Core_Elementor_Figma_Design_IR_Adapter::VERSION : 0,
            'figma_normalization_version' => class_exists( 'Design_Core_Elementor_Figma_Normalization_Service' ) ? Design_Core_Elementor_Figma_Normalization_Service::VERSION : 0,
            'visual_correction_supported' => class_exists( 'Design_Core_Elementor_Visual_Correction_Applier' ),
            'design_token_pipeline' => array(
                'available' => class_exists( 'Design_Core_Elementor_Design_Token_Pipeline' ),
                'version' => class_exists( 'Design_Core_Elementor_Design_Token_Pipeline' ) ? Design_Core_Elementor_Design_Token_Pipeline::VERSION : 0,
                'runtime' => 'php-native',
            ),
            'css_ast' => array(
                'available' => (bool) $css_ast,
                'version' => $css_ast ? Design_Core_Elementor_CSS_AST_Service::VERSION : 0,
                'engine' => $css_ast ? $css_ast->engine() : 'unavailable',
                'sabberworm_loaded' => class_exists( '\\Sabberworm\\CSS\\Parser' ),
            ),
            'wordpress_mcp_adapter' => $wordpress_mcp,
            'elementor_setting_governor' => array(
                'available' => class_exists( 'Design_Core_Elementor_Elementor_Setting_Governor' ),
                'version' => class_exists( 'Design_Core_Elementor_Elementor_Setting_Governor' ) ? Design_Core_Elementor_Elementor_Setting_Governor::VERSION : 0,
            ),
            'design_intelligence' => array(
                'available' => ! empty( $design_intelligence['available'] ),
                'version' => 1,
                'profiles' => (int) ( $design_intelligence['profiles'] ?? 0 ),
                'ux_rules' => (int) ( $design_intelligence['ux_rules'] ?? 0 ),
                'runtime' => 'local-json',
            ),
            'site_environment' => $settings['environment'],
            'write_enabled' => $settings['write_enabled'],
            'production_readiness_status' => (string) ( $readiness['status'] ?? 'unknown' ),
        );
    }
}

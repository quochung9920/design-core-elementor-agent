<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Compatibility surface for the official WordPress MCP Adapter / Abilities API.
 * Only bounded read/preview abilities are public here. Remote writes continue to
 * use Design Core's explicit rc21 REST/MCP tools with preview approval, confirm,
 * idempotency and audit rather than a generic ability dispatcher.
 */
class Design_Core_Elementor_WordPress_MCP_Compatibility {
    const VERSION = 1;
    const REVIEWED_ADAPTER_MINIMUM_WORDPRESS = '6.9';

    public function status() {
        $wp_version = (string) get_bloginfo( 'version' );
        return array(
            'version' => self::VERSION,
            'abilities_api' => function_exists( 'wp_register_ability' ),
            'official_mcp_adapter' => defined( 'WORDPRESS_MCP_ADAPTER_VERSION' ),
            'adapter_version' => defined( 'WORDPRESS_MCP_ADAPTER_VERSION' ) ? (string) WORDPRESS_MCP_ADAPTER_VERSION : '',
            'reviewed_adapter_minimum_wordpress' => self::REVIEWED_ADAPTER_MINIMUM_WORDPRESS,
            'reviewed_adapter_runtime_eligible' => version_compare( $wp_version, self::REVIEWED_ADAPTER_MINIMUM_WORDPRESS, '>=' ),
            'public_abilities' => self::public_ability_names(),
            'write_policy' => 'private-explicit-rest-mcp-only',
        );
    }

    public function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) { return; }
        $this->register( 'design-core/site-status', 'Design Core Site Status', 'Read Design Core, Elementor and remote-control capability status.', array( $this, 'site_status' ), array( $this, 'can_read' ), array( 'type'=>'object', 'properties'=>array() ) );
        $this->register( 'design-core/page-snapshot', 'Design Core Page Snapshot', 'Read one normalized Design Core/Elementor page digest.', array( $this, 'page_snapshot' ), array( $this, 'can_read_page' ), array( 'type'=>'object', 'required'=>array('page_id'), 'properties'=>array( 'page_id'=>array('type'=>'integer','minimum'=>1) ) ) );
        $this->register( 'design-core/build-preview', 'Design Core Build Preview', 'Run a mutation-free Design IR or HTML/CSS BuildPlan preview.', array( $this, 'build_preview' ), array( $this, 'can_preview' ), array( 'type'=>'object', 'properties'=>array( 'html'=>array('type'=>'string'), 'css'=>array('type'=>'string'), 'design_ir'=>array('type'=>'object'), 'adapter_target'=>array('type'=>'string','enum'=>array('auto','elementor-v3','elementor-v4')) ) ) );
        $this->register( 'design-core/design-system-recommend', 'Design Core Design System Recommend', 'Recommend a mutation-free Design System Profile from the local Design Intelligence catalog.', array( $this, 'design_system_recommend' ), array( $this, 'can_preview' ), array( 'type'=>'object', 'required'=>array('brief'), 'properties'=>array( 'brief'=>array('type'=>'string','maxLength'=>16384), 'product_type'=>array('type'=>'string'), 'mode'=>array('type'=>'string','enum'=>array('light','dark')), 'variance'=>array('type'=>'integer','minimum'=>1,'maximum'=>10), 'motion'=>array('type'=>'integer','minimum'=>1,'maximum'=>10), 'density'=>array('type'=>'integer','minimum'=>1,'maximum'=>10) ) ) );
        $this->register( 'design-core/history-list', 'Design Core History', 'Read conflict-aware Design Core change history summaries.', array( $this, 'history_list' ), array( $this, 'can_read' ), array( 'type'=>'object', 'properties'=>array() ) );
    }

    private function register( $name, $label, $description, $execute, $permission, array $input_schema ) {
        wp_register_ability( $name, array(
            'label' => $label,
            'description' => $description,
            'category' => 'design-core',
            'execute_callback' => $execute,
            'permission_callback' => $permission,
            'input_schema' => $input_schema,
            'meta' => array(
                'show_in_rest' => false,
                'mcp' => array( 'public'=>true, 'type'=>'tool' ),
                // Names follow the MCP ToolAnnotations shape used by the official
                // WordPress adapter (readOnlyHint/destructiveHint/idempotentHint).
                'annotations' => array( 'readOnlyHint'=>true, 'destructiveHint'=>false, 'idempotentHint'=>true, 'openWorldHint'=>false ),
            ),
        ) );
    }

    public function can_read() { return true === Design_Core_Elementor_MCP_Ability_Bridge::permission( Design_Core_Elementor_Capabilities::READ ); }
    public function can_preview() { return true === Design_Core_Elementor_MCP_Ability_Bridge::permission( Design_Core_Elementor_Capabilities::PREVIEW ); }
    public function can_read_page( $input = array() ) {
        if ( ! $this->can_read() ) { return false; }
        $page_id = (int) ( is_array($input) ? ( $input['page_id'] ?? 0 ) : 0 );
        return $page_id <= 0 || current_user_can( 'edit_post', $page_id ) || current_user_can( 'manage_options' );
    }

    public function site_status() {
        $guard = Design_Core_Elementor_MCP_Ability_Bridge::permission( Design_Core_Elementor_Capabilities::READ );
        return is_wp_error( $guard ) ? $guard : ( new Design_Core_Elementor_Site_Status() )->report();
    }
    public function page_snapshot( $input ) {
        $guard = Design_Core_Elementor_MCP_Ability_Bridge::permission( Design_Core_Elementor_Capabilities::READ );
        return is_wp_error( $guard ) ? $guard : ( new Design_Core_Elementor_Page_Snapshot() )->snapshot( (int) ( $input['page_id'] ?? 0 ) );
    }
    public function history_list() {
        $guard = Design_Core_Elementor_MCP_Ability_Bridge::permission( Design_Core_Elementor_Capabilities::READ );
        return is_wp_error( $guard ) ? $guard : ( new Design_Core_Elementor_Change_Ledger() )->summaries();
    }

    public function build_preview( $input ) {
        $guard = Design_Core_Elementor_MCP_Ability_Bridge::permission( Design_Core_Elementor_Capabilities::PREVIEW );
        if ( is_wp_error( $guard ) ) { return $guard; }
        $input = is_array( $input ) ? $input : array(); $target = sanitize_key( (string) ( $input['adapter_target'] ?? 'auto' ) );
        $service = new Design_Core_Elementor_Build_Plan_Preview();
        if ( ! empty( $input['design_ir'] ) && is_array( $input['design_ir'] ) ) { return $service->preview_ir( $input['design_ir'], $target ); }
        return $service->preview_source( (string) ( $input['html'] ?? '' ), (string) ( $input['css'] ?? '' ), 'wordpress-mcp-preview', $target );
    }

    public function design_system_recommend( $input ) {
        $guard = Design_Core_Elementor_MCP_Ability_Bridge::permission( Design_Core_Elementor_Capabilities::PREVIEW );
        if ( is_wp_error( $guard ) ) { return $guard; }
        $input = is_array( $input ) ? $input : array();
        return ( new Design_Core_Elementor_Design_Advisor() )->recommend( (string) ( $input['brief'] ?? '' ), array(
            'product_type'=>sanitize_text_field( (string) ( $input['product_type'] ?? '' ) ),
            'mode'=>sanitize_key( (string) ( $input['mode'] ?? 'light' ) ),
            'variance'=>isset($input['variance']) ? (int)$input['variance'] : null,
            'motion'=>isset($input['motion']) ? (int)$input['motion'] : null,
            'density'=>isset($input['density']) ? (int)$input['density'] : null,
        ) );
    }

    public static function public_ability_names() {
        return array( 'design-core/site-status', 'design-core/page-snapshot', 'design-core/build-preview', 'design-core/design-system-recommend', 'design-core/history-list' );
    }
}

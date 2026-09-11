<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Direct REST controller for ChatGPT GPT Actions. No MCP transport is involved. */
class Design_Core_Elementor_GPT_Actions_Rest_Controller {
    const NAMESPACE_V1 = 'design-core/v1';
    const MAX_BODY_BYTES = 2097152;

    public function register_routes() {
        register_rest_route( self::NAMESPACE_V1, '/openapi', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_openapi' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( self::NAMESPACE_V1, '/manifest', $this->route( WP_REST_Server::READABLE, 'get_manifest', 'permission_read' ) );
        register_rest_route( self::NAMESPACE_V1, '/site/status', $this->route( WP_REST_Server::READABLE, 'get_site_status', 'permission_read' ) );
        register_rest_route( self::NAMESPACE_V1, '/understand', $this->route( WP_REST_Server::CREATABLE, 'post_understand', 'permission_read' ) );
        register_rest_route( self::NAMESPACE_V1, '/site/map', $this->route( WP_REST_Server::READABLE, 'get_site_map', 'permission_read' ) );
        register_rest_route( self::NAMESPACE_V1, '/site/design-system', $this->route( WP_REST_Server::READABLE, 'get_site_design_system', 'permission_read' ) );
        register_rest_route( self::NAMESPACE_V1, '/site/search', $this->route( WP_REST_Server::READABLE, 'get_search_content', 'permission_read' ) );
        register_rest_route( self::NAMESPACE_V1, '/elementor/capabilities', $this->route( WP_REST_Server::READABLE, 'get_elementor_capabilities', 'permission_read' ) );
        register_rest_route( self::NAMESPACE_V1, '/elementor/catalog', $this->route( WP_REST_Server::READABLE, 'get_elementor_catalog', 'permission_read' ) );
        register_rest_route( self::NAMESPACE_V1, '/elementor/widgets/search', $this->route( WP_REST_Server::CREATABLE, 'post_widget_search', 'permission_read' ) );
        register_rest_route( self::NAMESPACE_V1, '/elementor/widgets/(?P<widget>[a-zA-Z0-9_-]+)', $this->route( WP_REST_Server::READABLE, 'get_widget_schema', 'permission_read', array( 'widget'=>array( 'sanitize_callback'=>'sanitize_key', 'required'=>true ) ) ) );
        register_rest_route( self::NAMESPACE_V1, '/media', $this->route( WP_REST_Server::READABLE, 'get_media_library', 'permission_read' ) );
        register_rest_route( self::NAMESPACE_V1, '/tasks/plan', $this->route( WP_REST_Server::CREATABLE, 'post_plan_task', 'permission_read' ) );

        register_rest_route( self::NAMESPACE_V1, '/design/status', $this->route( WP_REST_Server::READABLE, 'get_design_status', 'permission_read' ) );
        register_rest_route( self::NAMESPACE_V1, '/design/recommend', $this->route( WP_REST_Server::CREATABLE, 'post_design_recommend', 'permission_preview' ) );
        register_rest_route( self::NAMESPACE_V1, '/design/preview', $this->route( WP_REST_Server::CREATABLE, 'post_design_preview', 'permission_preview' ) );
        register_rest_route( self::NAMESPACE_V1, '/design/enrich-ir', $this->route( WP_REST_Server::CREATABLE, 'post_design_enrich_ir', 'permission_preview' ) );
        register_rest_route( self::NAMESPACE_V1, '/pages/(?P<id>\d+)/ux-audit', $this->route( WP_REST_Server::READABLE, 'get_ux_audit', 'permission_read', $this->id_arg() ) );

        register_rest_route( self::NAMESPACE_V1, '/figma/preview', $this->route( WP_REST_Server::CREATABLE, 'post_figma_preview', 'permission_preview' ) );
        register_rest_route( self::NAMESPACE_V1, '/build/preview', $this->route( WP_REST_Server::CREATABLE, 'post_build_preview', 'permission_preview' ) );
        register_rest_route( self::NAMESPACE_V1, '/pages', $this->route( WP_REST_Server::CREATABLE, 'post_create_page', 'permission_build' ) );
        register_rest_route( self::NAMESPACE_V1, '/pages/(?P<id>\d+)', $this->route( WP_REST_Server::READABLE, 'get_page_snapshot', 'permission_read', $this->id_arg() ) );
        register_rest_route( self::NAMESPACE_V1, '/pages/(?P<id>\d+)/apply', $this->route( WP_REST_Server::CREATABLE, 'post_apply_page', 'permission_modify', $this->id_arg() ) );
        register_rest_route( self::NAMESPACE_V1, '/pages/(?P<id>\d+)/verify', $this->route( WP_REST_Server::CREATABLE, 'post_verify_page', 'permission_read', $this->id_arg() ) );
        register_rest_route( self::NAMESPACE_V1, '/pages/(?P<id>\d+)/visual-feedback', $this->route( WP_REST_Server::CREATABLE, 'post_visual_feedback', 'permission_preview', $this->id_arg() ) );
        register_rest_route( self::NAMESPACE_V1, '/pages/(?P<id>\d+)/auto-correct', $this->route( WP_REST_Server::CREATABLE, 'post_auto_correct', 'permission_modify', $this->id_arg() ) );
        register_rest_route( self::NAMESPACE_V1, '/pages/(?P<id>\d+)/publish', $this->route( WP_REST_Server::CREATABLE, 'post_publish_page', 'permission_publish', $this->id_arg() ) );
        register_rest_route( self::NAMESPACE_V1, '/history', $this->route( WP_REST_Server::READABLE, 'get_history', 'permission_read' ) );
        register_rest_route( self::NAMESPACE_V1, '/history/(?P<entry>[a-zA-Z0-9_-]+)/rollback', $this->route( WP_REST_Server::CREATABLE, 'post_rollback', 'permission_rollback', array( 'entry'=>array( 'sanitize_callback'=>'sanitize_key', 'required'=>true ) ) ) );

        register_rest_route( self::NAMESPACE_V1, '/wordpress/content', array(
            $this->route( WP_REST_Server::READABLE, 'get_wp_content_index', 'permission_read' ),
            $this->route( WP_REST_Server::CREATABLE, 'post_wp_content_create', 'permission_build' ),
        ) );
        register_rest_route( self::NAMESPACE_V1, '/wordpress/content/(?P<id>\d+)', array(
            $this->route( WP_REST_Server::READABLE, 'get_wp_content', 'permission_read', $this->id_arg() ),
            $this->route( WP_REST_Server::CREATABLE, 'post_wp_content_update', 'permission_modify', $this->id_arg() ),
        ) );
        register_rest_route( self::NAMESPACE_V1, '/wordpress/content/(?P<id>\d+)/trash', $this->route( WP_REST_Server::CREATABLE, 'post_wp_content_trash', 'permission_modify', $this->id_arg() ) );
        register_rest_route( self::NAMESPACE_V1, '/wordpress/content/(?P<id>\d+)/restore', $this->route( WP_REST_Server::CREATABLE, 'post_wp_content_restore', 'permission_modify', $this->id_arg() ) );
        register_rest_route( self::NAMESPACE_V1, '/wordpress/settings', array(
            $this->route( WP_REST_Server::READABLE, 'get_wp_settings', 'permission_read' ),
            $this->route( WP_REST_Server::CREATABLE, 'post_wp_settings', 'permission_publish' ),
        ) );
        register_rest_route( self::NAMESPACE_V1, '/wordpress/menus', $this->route( WP_REST_Server::READABLE, 'get_wp_menus', 'permission_read' ) );
        register_rest_route( self::NAMESPACE_V1, '/wordpress/menus/(?P<id>\d+)/items', $this->route( WP_REST_Server::CREATABLE, 'post_wp_menu_item', 'permission_modify', $this->id_arg() ) );
        register_rest_route( self::NAMESPACE_V1, '/wordpress/menus/(?P<id>\d+)/items/(?P<item>\d+)', $this->route( WP_REST_Server::CREATABLE, 'post_wp_menu_item_trash', 'permission_modify', array_merge( $this->id_arg(), array( 'item'=>array( 'sanitize_callback'=>'absint', 'required'=>true ) ) ) ) );
        register_rest_route( self::NAMESPACE_V1, '/wordpress/media/import', $this->route( WP_REST_Server::CREATABLE, 'post_wp_media_import', 'permission_build' ) );
        register_rest_route( self::NAMESPACE_V1, '/wordpress/media/(?P<id>\d+)', $this->route( WP_REST_Server::CREATABLE, 'post_wp_media_update', 'permission_modify', $this->id_arg() ) );
        register_rest_route( self::NAMESPACE_V1, '/wordpress/media/(?P<id>\d+)/trash', $this->route( WP_REST_Server::CREATABLE, 'post_wp_media_trash', 'permission_modify', $this->id_arg() ) );
    }

    public function permission_read( WP_REST_Request $r ) { return $this->authorize( $r, Design_Core_Elementor_Capabilities::READ ); }
    public function permission_preview( WP_REST_Request $r ) { return $this->authorize( $r, Design_Core_Elementor_Capabilities::PREVIEW ); }
    public function permission_build( WP_REST_Request $r ) { return $this->authorize( $r, Design_Core_Elementor_Capabilities::BUILD ); }
    public function permission_modify( WP_REST_Request $r ) { return $this->authorize( $r, Design_Core_Elementor_Capabilities::MODIFY ); }
    public function permission_publish( WP_REST_Request $r ) { return $this->authorize( $r, Design_Core_Elementor_Capabilities::PUBLISH ); }
    public function permission_rollback( WP_REST_Request $r ) { return $this->authorize( $r, Design_Core_Elementor_Capabilities::ROLLBACK ); }

    public function get_openapi() { return rest_ensure_response( Design_Core_Elementor_GPT_Actions_API::openapi() ); }
    public function get_manifest() {
        $manifest = Design_Core_Elementor_GPT_Actions_API::manifest();
        $manifest['plugin_version'] = DESIGN_CORE_ELEMENTOR_VERSION;
        return rest_ensure_response( Design_Core_Elementor_Change_Ledger::transport_safe( $manifest ) );
    }

    public function get_site_status( WP_REST_Request $r ) { return $this->v2()->get_site_status( $r ); }
    public function post_understand( WP_REST_Request $r ) { return $this->api_platform()->post_understand( $r ); }
    public function get_site_map( WP_REST_Request $r ) { return $this->site_v2()->get_site_map( $r ); }
    public function get_site_design_system( WP_REST_Request $r ) { return $this->site_v2()->get_site_design_system( $r ); }
    public function get_search_content( WP_REST_Request $r ) { return $this->site_v2()->get_search_content( $r ); }
    public function get_elementor_capabilities( WP_REST_Request $r ) { return $this->site_v2()->get_elementor_capabilities( $r ); }
    public function get_elementor_catalog( WP_REST_Request $r ) { return $this->site_v2()->get_elementor_catalog( $r ); }
    public function post_widget_search( WP_REST_Request $r ) { return $this->site_v2()->post_widget_search( $r ); }
    public function get_widget_schema( WP_REST_Request $r ) { return $this->site_v2()->get_widget_schema( $r ); }
    public function get_media_library( WP_REST_Request $r ) { return $this->site_v2()->get_media_library( $r ); }
    public function post_plan_task( WP_REST_Request $r ) { return $this->site_v2()->post_plan_task( $r ); }

    public function get_design_status( WP_REST_Request $r ) { return $this->design_v2()->get_status( $r ); }
    public function post_design_recommend( WP_REST_Request $r ) { return $this->design_v2()->post_recommend( $r ); }
    public function post_design_preview( WP_REST_Request $r ) { return $this->design_v2()->post_preview( $r ); }
    public function post_design_enrich_ir( WP_REST_Request $r ) { return $this->design_v2()->post_enrich_ir( $r ); }
    public function get_ux_audit( WP_REST_Request $r ) { return $this->design_v2()->get_ux_audit( $r ); }

    public function post_figma_preview( WP_REST_Request $r ) { return $this->v2()->post_figma_preview( $r ); }
    public function post_build_preview( WP_REST_Request $r ) { return $this->v2()->post_build_preview( $r ); }
    public function post_create_page( WP_REST_Request $r ) { $this->copy_idempotency_header( $r ); return $this->site_v2()->post_create_page( $r ); }
    public function get_page_snapshot( WP_REST_Request $r ) { return $this->v2()->get_page_snapshot( $r ); }
    public function post_apply_page( WP_REST_Request $r ) { $this->copy_idempotency_header( $r ); return $this->v2()->post_pages_update( $r ); }
    public function post_verify_page( WP_REST_Request $r ) { return $this->api_platform()->post_verify_page( $r ); }
    public function post_visual_feedback( WP_REST_Request $r ) { return $this->v2()->post_visual_feedback( $r ); }
    public function post_auto_correct( WP_REST_Request $r ) { $this->copy_idempotency_header( $r ); return $this->v2()->post_auto_correct( $r ); }
    public function post_publish_page( WP_REST_Request $r ) { $this->copy_idempotency_header( $r ); return $this->v2()->post_pages_publish( $r ); }
    public function get_history( WP_REST_Request $r ) { return $this->v2()->get_history( $r ); }
    public function post_rollback( WP_REST_Request $r ) { $this->copy_idempotency_header( $r ); return $this->v2()->post_history_rollback( $r ); }

    public function get_wp_content_index( WP_REST_Request $r ) {
        return $this->response( $this->wp_service()->content_index( array(
            'post_type'=>(string) $r->get_param( 'post_type' ), 'status'=>(string) $r->get_param( 'status' ),
            'search'=>(string) $r->get_param( 'search' ), 'limit'=>(int) ( $r->get_param( 'limit' ) ?: 50 ),
        ) ) );
    }
    public function get_wp_content( WP_REST_Request $r ) { return $this->response( $this->wp_service()->content_get( (int) $r->get_param( 'id' ) ) ); }
    public function post_wp_content_create( WP_REST_Request $r ) {
        return $this->owner_write( $r, 'wp_content_create', 0, function ( $body ) use ( $r ) {
            return $this->wp_service()->content_create( $body, $this->principal_has( $r, Design_Core_Elementor_Capabilities::PUBLISH ) );
        } );
    }
    public function post_wp_content_update( WP_REST_Request $r ) {
        $id = (int) $r->get_param( 'id' );
        return $this->owner_write( $r, 'wp_content_update', $id, function ( $body ) use ( $r, $id ) {
            return $this->wp_service()->content_update( $id, $body, $this->principal_has( $r, Design_Core_Elementor_Capabilities::PUBLISH ) );
        } );
    }
    public function post_wp_content_trash( WP_REST_Request $r ) {
        $id = (int) $r->get_param( 'id' );
        return $this->owner_write( $r, 'wp_content_trash', $id, function ( $body ) use ( $id ) { return $this->wp_service()->content_trash( $id, $body ); } );
    }
    public function post_wp_content_restore( WP_REST_Request $r ) {
        $id = (int) $r->get_param( 'id' );
        return $this->owner_write( $r, 'wp_content_restore', $id, function () use ( $id ) { return $this->wp_service()->content_restore( $id ); } );
    }
    public function get_wp_settings( WP_REST_Request $r ) { return $this->response( $this->wp_service()->settings_get() ); }
    public function post_wp_settings( WP_REST_Request $r ) { return $this->owner_write( $r, 'wp_settings_update', 0, function ( $body ) { return $this->wp_service()->settings_update( $body ); } ); }
    public function get_wp_menus( WP_REST_Request $r ) { return $this->response( $this->wp_service()->menus_get() ); }
    public function post_wp_menu_item( WP_REST_Request $r ) {
        $menu_id = (int) $r->get_param( 'id' );
        return $this->owner_write( $r, 'wp_menu_item_upsert', 0, function ( $body ) use ( $menu_id ) { return $this->wp_service()->menu_item_upsert( $menu_id, $body ); } );
    }
    public function post_wp_menu_item_trash( WP_REST_Request $r ) {
        $menu_id = (int) $r->get_param( 'id' ); $item_id = (int) $r->get_param( 'item' );
        return $this->owner_write( $r, 'wp_menu_item_trash', 0, function () use ( $menu_id, $item_id ) { return $this->wp_service()->menu_item_trash( $menu_id, $item_id ); } );
    }
    public function post_wp_media_import( WP_REST_Request $r ) { return $this->owner_write( $r, 'wp_media_import', 0, function ( $body ) { return $this->wp_service()->media_import( $body ); } ); }
    public function post_wp_media_update( WP_REST_Request $r ) {
        $id = (int) $r->get_param( 'id' );
        return $this->owner_write( $r, 'wp_media_update', $id, function ( $body ) use ( $id ) { return $this->wp_service()->media_update( $id, $body ); } );
    }
    public function post_wp_media_trash( WP_REST_Request $r ) {
        $id = (int) $r->get_param( 'id' );
        return $this->owner_write( $r, 'wp_media_trash', $id, function () use ( $id ) { return $this->wp_service()->media_trash( $id ); } );
    }

    private function authorize( WP_REST_Request $r, $capability ) {
        $principal = Design_Core_Elementor_API_Credential_Auth::resolve_from_request( $r );
        if ( is_wp_error( $principal ) ) { return $principal; }
        if ( ! Design_Core_Elementor_API_Credential_Auth::principal_has_scope( $principal, $capability ) ) {
            return new WP_Error( 'design_core_api_scope_forbidden', 'Owner API credential lacks the required "' . sanitize_key( (string) $capability ) . '" scope.', array( 'status' => 403 ) );
        }
        $r->set_param( '_design_core_principal', $principal );
        return true;
    }

    private function owner_write( WP_REST_Request $r, $operation, $object_id, callable $callback ) {
        $guard = Design_Core_Elementor_Remote_Write_Guard::ensure_writes_enabled();
        if ( is_wp_error( $guard ) ) { return $guard; }
        $principal = $r->get_param( '_design_core_principal' );
        $env = Design_Core_Elementor_Remote_Write_Guard::ensure_credential_environment_match( $principal );
        if ( is_wp_error( $env ) ) { return $env; }
        $body = $this->json_body( $r );
        if ( is_wp_error( $body ) ) { return $body; }
        if ( true !== ( $body['confirm'] ?? false ) ) {
            return new WP_Error( 'design_core_confirmation_required', 'This WordPress mutation requires confirm=true.', array( 'status' => 400 ) );
        }
        $key = sanitize_text_field( (string) ( $body['idempotency_key'] ?? '' ) );
        $scope = is_array( $principal ) ? ( (string) ( $principal['type'] ?? 'credential' ) . ':' . (string) ( $principal['id'] ?? '0' ) ) : 'api:unknown';
        $fingerprint = Design_Core_Elementor_Idempotency_Store::fingerprint( $r->get_method(), $r->get_route(), $body );
        $begin = Design_Core_Elementor_Idempotency_Store::begin( $scope, $key, $fingerprint );
        if ( is_wp_error( $begin ) ) { return $begin; }
        if ( ! empty( $begin['replay'] ) ) {
            $stored = $begin['response'];
            return ! empty( $stored['is_error'] )
                ? new WP_Error( 'design_core_idempotent_replay', (string) ( $stored['body']['message'] ?? 'Replayed error.' ), array( 'status' => (int) $stored['status'] ) )
                : rest_ensure_response( $stored['body'] );
        }
        $result = $callback( $body );
        if ( is_wp_error( $result ) ) {
            $data = $result->get_error_data();
            $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
            if ( ! empty( $begin['enabled'] ) ) { Design_Core_Elementor_Idempotency_Store::complete( $scope, $key, $fingerprint, true, $status, array( 'message'=>$result->get_error_message(), 'code'=>$result->get_error_code() ) ); }
            return $result;
        }
        $safe = Design_Core_Elementor_Change_Ledger::transport_safe( $result );
        if ( ! empty( $begin['enabled'] ) ) { Design_Core_Elementor_Idempotency_Store::complete( $scope, $key, $fingerprint, false, 200, $safe ); }
        Design_Core_Elementor_Remote_Audit_Log::record( array(
            'request_id' => (string) ( $r->get_header( 'x-request-id' ) ?: wp_generate_uuid4() ),
            'site' => Design_Core_Elementor_Remote_Settings::environment(),
            'tool' => sanitize_key( (string) $operation ),
            'page_id' => (int) $object_id,
            'machine_credential_id' => is_array( $principal ) ? (string) ( $principal['id'] ?? '' ) : '',
            'actor' => is_array( $principal ) ? (int) ( $principal['owner_user_id'] ?? 0 ) : 0,
            'result' => 'success',
        ) );
        return rest_ensure_response( $safe );
    }

    private function copy_idempotency_header( WP_REST_Request $r ) {
        $body = $r->get_json_params();
        if ( is_array( $body ) && ! empty( $body['idempotency_key'] ) && '' === (string) $r->get_header( 'idempotency-key' ) ) {
            $r->set_header( 'idempotency-key', sanitize_text_field( (string) $body['idempotency_key'] ) );
        }
    }

    private function json_body( WP_REST_Request $r ) {
        $raw = (string) $r->get_body();
        if ( strlen( $raw ) > self::MAX_BODY_BYTES ) { return new WP_Error( 'design_core_api_payload_too_large', 'Owner API request exceeds 2 MB.', array( 'status'=>413 ) ); }
        $body = $r->get_json_params();
        return is_array( $body ) ? $body : array();
    }

    private function principal_has( WP_REST_Request $r, $capability ) {
        $principal = $r->get_param( '_design_core_principal' );
        return is_array( $principal ) && Design_Core_Elementor_API_Credential_Auth::principal_has_scope( $principal, $capability );
    }

    private function response( $result ) { return is_wp_error( $result ) ? $result : rest_ensure_response( Design_Core_Elementor_Change_Ledger::transport_safe( $result ) ); }
    private function v2() { return new Design_Core_Elementor_Rest_Controller_V2(); }
    private function site_v2() { return new Design_Core_Elementor_Site_Intelligence_Rest_Controller(); }
    private function design_v2() { return new Design_Core_Elementor_Design_Intelligence_Rest_Controller(); }
    private function api_platform() { return new Design_Core_Elementor_API_Platform_Rest_Controller(); }
    private function wp_service() { return new Design_Core_Elementor_WordPress_Owner_Service(); }
    private function id_arg() { return array( 'id'=>array( 'sanitize_callback'=>'absint', 'required'=>true ) ); }
    private function route( $methods, $callback, $permission, array $args = array() ) {
        $route = array( 'methods'=>$methods, 'callback'=>array( $this, $callback ), 'permission_callback'=>array( $this, $permission ) );
        if ( $args ) { $route['args'] = $args; }
        return $route;
    }
}

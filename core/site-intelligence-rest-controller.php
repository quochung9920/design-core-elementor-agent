<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Remote API v2 surface for Site Intelligence v1. */
class Design_Core_Elementor_Site_Intelligence_Rest_Controller {
    const NAMESPACE_V2 = 'design-core-elementor/v2';
    const MAX_BODY_BYTES = 2097152;

    public function register_routes() {
        register_rest_route( self::NAMESPACE_V2, '/site/map', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_site_map' ),
            'permission_callback' => array( $this, 'permission_read' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/site/design-system', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_site_design_system' ),
            'permission_callback' => array( $this, 'permission_read' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/site/search', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_search_content' ),
            'permission_callback' => array( $this, 'permission_read' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/elementor/catalog', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_elementor_catalog' ),
            'permission_callback' => array( $this, 'permission_read' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/elementor/widgets/search', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'post_widget_search' ),
            'permission_callback' => array( $this, 'permission_read' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/elementor/widgets/(?P<widget>[a-zA-Z0-9_-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_widget_schema' ),
            'permission_callback' => array( $this, 'permission_read' ),
            'args' => array( 'widget' => array( 'sanitize_callback' => 'sanitize_key', 'required' => true ) ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/elementor/capabilities', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_elementor_capabilities' ),
            'permission_callback' => array( $this, 'permission_read' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/media', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_media_library' ),
            'permission_callback' => array( $this, 'permission_read' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/tasks/plan', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'post_plan_task' ),
            'permission_callback' => array( $this, 'permission_read' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/pages/create', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'post_create_page' ),
            'permission_callback' => array( $this, 'permission_build' ),
        ) );
    }

    public function permission_read( WP_REST_Request $request ) {
        return $this->authorize( $request, Design_Core_Elementor_Capabilities::READ );
    }

    public function permission_build( WP_REST_Request $request ) {
        return $this->authorize( $request, Design_Core_Elementor_Capabilities::BUILD );
    }

    public function get_site_map( WP_REST_Request $request ) {
        $result = $this->service()->site_map( (int) ( $request->get_param( 'limit' ) ?: 100 ) );
        return $this->response( $result );
    }

    public function get_site_design_system() {
        return $this->response( $this->service()->site_design_system() );
    }

    public function get_search_content( WP_REST_Request $request ) {
        $types = $request->get_param( 'types' );
        if ( is_string( $types ) ) { $types = preg_split( '/\s*,\s*/', $types, -1, PREG_SPLIT_NO_EMPTY ); }
        if ( ! is_array( $types ) ) { $types = array(); }
        $result = $this->service()->search_content(
            (string) $request->get_param( 'q' ),
            $types,
            (int) ( $request->get_param( 'limit' ) ?: 20 )
        );
        return $this->response( $result );
    }

    public function get_elementor_catalog( WP_REST_Request $request ) {
        $result = $this->elementor_service()->elementor_catalog(
            (string) ( $request->get_param( 'source' ) ?: 'all' ),
            (string) ( $request->get_param( 'q' ) ?: '' ),
            (int) ( $request->get_param( 'limit' ) ?: 100 )
        );
        return $this->response( $result );
    }

    public function post_widget_search( WP_REST_Request $request ) {
        $body = $this->json_body( $request );
        if ( is_wp_error( $body ) ) { return $body; }
        $result = $this->elementor_service()->widget_search( $body, (int) ( $body['limit'] ?? 10 ) );
        return $this->response( $result );
    }

    public function get_widget_schema( WP_REST_Request $request ) {
        $detail = filter_var( $request->get_param( 'detail' ), FILTER_VALIDATE_BOOLEAN );
        $result = $this->elementor_service()->widget_schema( (string) $request->get_param( 'widget' ), $detail );
        return $this->response( $result );
    }

    public function get_elementor_capabilities() {
        return $this->response( $this->elementor_service()->elementor_capabilities() );
    }

    public function get_media_library( WP_REST_Request $request ) {
        $result = $this->service()->media_library(
            (string) ( $request->get_param( 'q' ) ?: '' ),
            (string) ( $request->get_param( 'mime' ) ?: '' ),
            (int) ( $request->get_param( 'limit' ) ?: 30 )
        );
        return $this->response( $result );
    }

    public function post_plan_task( WP_REST_Request $request ) {
        $body = $this->json_body( $request );
        if ( is_wp_error( $body ) ) { return $body; }
        $result = $this->task_service()->plan_task( (string) ( $body['brief'] ?? '' ), (int) ( $body['page_id'] ?? 0 ) );
        return $this->response( $result );
    }

    public function post_create_page( WP_REST_Request $request ) {
        $guard = Design_Core_Elementor_Remote_Write_Guard::ensure_writes_enabled();
        if ( is_wp_error( $guard ) ) { return $guard; }
        $principal = $request->get_param( '_design_core_principal' );
        $environment = Design_Core_Elementor_Remote_Write_Guard::ensure_credential_environment_match( $principal );
        if ( is_wp_error( $environment ) ) { return $environment; }

        $body = $this->json_body( $request );
        if ( is_wp_error( $body ) ) { return $body; }
        if ( empty( $body['confirm'] ) ) {
            return new WP_Error( 'design_core_confirmation_required', 'Creating a page requires confirm=true.', array( 'status' => 400 ) );
        }
        $production = Design_Core_Elementor_Remote_Write_Guard::ensure_production_guard( true, false );
        if ( is_wp_error( $production ) ) { return $production; }

        $title = trim( sanitize_text_field( (string) ( $body['title'] ?? '' ) ) );
        if ( '' === $title ) { return new WP_Error( 'design_core_page_title_required', 'Page title is required.', array( 'status' => 400 ) ); }
        if ( strlen( $title ) > 200 ) { return new WP_Error( 'design_core_page_title_too_long', 'Page title must be 200 characters or fewer.', array( 'status' => 400 ) ); }
        $slug = sanitize_title( (string) ( $body['slug'] ?? '' ) );
        $parent_id = max( 0, (int) ( $body['parent_id'] ?? 0 ) );
        if ( $parent_id > 0 ) {
            $parent = get_post( $parent_id );
            if ( ! $parent || 'page' !== $parent->post_type ) {
                return new WP_Error( 'design_core_page_parent_invalid', 'parent_id must reference an existing WordPress page.', array( 'status' => 400 ) );
            }
        }

        return $this->with_idempotency( $request, function () use ( $title, $slug, $parent_id, $request ) {
            $post = array(
                'post_type' => 'page',
                'post_status' => 'draft',
                'post_title' => $title,
                'post_parent' => $parent_id,
            );
            if ( $slug ) { $post['post_name'] = $slug; }
            $page_id = wp_insert_post( $post, true );
            if ( is_wp_error( $page_id ) ) { return $page_id; }
            $page_id = (int) $page_id;

            update_post_meta( $page_id, '_design_core_mcp_created', 1 );
            $marked = 1 === (int) get_post_meta( $page_id, '_design_core_mcp_created', true );
            if ( ! $marked ) {
                wp_delete_post( $page_id, true );
                return new WP_Error( 'design_core_page_create_metadata_failed', 'The draft page was created but its Design Core safety marker could not be verified; the new page was deleted.', array( 'status' => 500 ) );
            }

            $created = get_post( $page_id );
            $this->audit( $request, 'create_page', $page_id, array( 'post_status' => 'draft' ) );
            return array(
                'status' => 'success',
                'page_id' => $page_id,
                'title' => $created ? sanitize_text_field( (string) $created->post_title ) : $title,
                'slug' => $created ? sanitize_title( (string) $created->post_name ) : $slug,
                'post_status' => 'draft',
                'parent_id' => $parent_id,
                'ready_for_elementor_build' => true,
                'preview_url' => (string) get_preview_post_link( $page_id ),
                'marker' => '_design_core_mcp_created=1',
                'rollback_available' => false,
                'note' => 'Creation is idempotent at the remote route. No Elementor storage is written here; page content becomes Elementor-managed only through the normal preview-gated update pipeline.',
            );
        } );
    }

    private function service() {
        return new Design_Core_Elementor_Site_Intelligence();
    }

    private function elementor_service() {
        return new Design_Core_Elementor_Runtime_Intelligence();
    }

    private function task_service() {
        return new Design_Core_Elementor_Task_Intelligence();
    }

    private function authorize( WP_REST_Request $request, $capability ) {
        $auth = Design_Core_Elementor_Machine_Credential_Auth::resolve_from_request( $request );
        if ( is_wp_error( $auth ) ) { return $auth; }
        if ( is_array( $auth ) ) {
            if ( ! Design_Core_Elementor_Machine_Credential_Auth::principal_has_scope( $auth, $capability ) ) {
                return new WP_Error( 'design_core_scope_forbidden', 'This machine credential does not have the "' . $capability . '" scope.', array( 'status' => 403 ) );
            }
            $request->set_param( '_design_core_principal', $auth );
            return true;
        }
        if ( current_user_can( $capability ) || current_user_can( 'manage_options' ) ) {
            $request->set_param( '_design_core_principal', array( 'type' => 'user', 'id' => get_current_user_id() ) );
            return true;
        }
        return new WP_Error( 'design_core_forbidden', 'You are not allowed to perform this Design Core remote action.', array( 'status' => 403 ) );
    }

    private function response( $result ) {
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( Design_Core_Elementor_Change_Ledger::transport_safe( $result ) );
    }

    private function json_body( WP_REST_Request $request ) {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) { $body = array(); }
        $encoded = wp_json_encode( $body );
        if ( is_string( $encoded ) && strlen( $encoded ) > self::MAX_BODY_BYTES ) {
            return new WP_Error( 'design_core_rest_payload_too_large', 'Request payload exceeds the 2 MB Design Core limit.', array( 'status' => 413 ) );
        }
        return $body;
    }

    private function with_idempotency( WP_REST_Request $request, callable $operation ) {
        $key = trim( (string) $request->get_header( 'idempotency-key' ) );
        $principal = $request->get_param( '_design_core_principal' );
        $scope_id = is_array( $principal ) ? ( ( $principal['type'] ?? 'anon' ) . ':' . ( $principal['id'] ?? '0' ) ) : 'anon';
        $fingerprint = Design_Core_Elementor_Idempotency_Store::fingerprint( $request->get_method(), $request->get_route(), $request->get_json_params() );
        $begin = Design_Core_Elementor_Idempotency_Store::begin( $scope_id, $key, $fingerprint );
        if ( is_wp_error( $begin ) ) { return $begin; }
        if ( ! empty( $begin['replay'] ) ) {
            $stored = $begin['response'];
            if ( ! empty( $stored['is_error'] ) ) {
                return new WP_Error( 'design_core_idempotent_replay', (string) ( $stored['body']['message'] ?? 'Replayed error.' ), array( 'status' => $stored['status'] ) );
            }
            return rest_ensure_response( $stored['body'] );
        }

        $result = $operation();
        if ( is_wp_error( $result ) ) {
            $data = $result->get_error_data();
            $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
            if ( ! empty( $begin['enabled'] ) ) {
                Design_Core_Elementor_Idempotency_Store::complete( $scope_id, $key, $fingerprint, true, $status, array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ) );
            }
            return $result;
        }
        if ( ! empty( $begin['enabled'] ) ) {
            Design_Core_Elementor_Idempotency_Store::complete( $scope_id, $key, $fingerprint, false, 200, $result );
        }
        return rest_ensure_response( $result );
    }

    private function audit( WP_REST_Request $request, $tool, $page_id, array $extra = array() ) {
        $principal = $request->get_param( '_design_core_principal' );
        Design_Core_Elementor_Remote_Audit_Log::record( array_merge( array(
            'request_id' => (string) ( $request->get_header( 'x-request-id' ) ?: wp_generate_uuid4() ),
            'site' => Design_Core_Elementor_Remote_Settings::environment(),
            'tool' => sanitize_key( (string) $tool ),
            'page_id' => (int) $page_id,
            'machine_credential_id' => is_array( $principal ) && 'credential' === ( $principal['type'] ?? '' ) ? $principal['id'] : '',
            'actor' => is_array( $principal ) && 'user' === ( $principal['type'] ?? '' ) ? (int) $principal['id'] : 0,
            'result' => 'success',
        ), $extra ) );
    }
}

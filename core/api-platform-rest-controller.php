<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * API-first orchestration helpers layered on top of the existing stable REST v2 surface.
 *
 * This controller does not create a generic "call any PHP method" escape hatch. It exposes
 * bounded, capability-scoped operations only. The existing 22 MCP tools continue to call
 * their dedicated REST endpoints; these helpers make the same platform easier to consume
 * from non-MCP clients and future ChatGPT app backends.
 */
class Design_Core_Elementor_API_Platform_Rest_Controller {
    const NAMESPACE_V2 = 'design-core-elementor/v2';
    const MAX_BODY_BYTES = 524288;

    public function register_routes() {
        register_rest_route( self::NAMESPACE_V2, '/api', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_manifest' ),
            'permission_callback' => array( $this, 'permission_read' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/api/openapi', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_openapi' ),
            'permission_callback' => array( $this, 'permission_read' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/api/understand', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'post_understand' ),
            'permission_callback' => array( $this, 'permission_read' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/api/pages/(?P<id>\d+)/verify', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'post_verify_page' ),
            'permission_callback' => array( $this, 'permission_read' ),
            'args' => array( 'id' => array( 'sanitize_callback' => 'absint', 'required' => true ) ),
        ) );
    }

    public function permission_read( WP_REST_Request $request ) {
        return $this->authorize( $request, Design_Core_Elementor_Capabilities::READ );
    }

    public function get_manifest() {
        $manifest = Design_Core_Elementor_API_Platform::manifest();
        $manifest['plugin_version'] = defined( 'DESIGN_CORE_ELEMENTOR_VERSION' ) ? DESIGN_CORE_ELEMENTOR_VERSION : '';
        return rest_ensure_response( Design_Core_Elementor_Change_Ledger::transport_safe( $manifest ) );
    }

    public function get_openapi() {
        return rest_ensure_response( Design_Core_Elementor_API_Platform::openapi() );
    }

    /**
     * One bounded read-only call for an AI client that needs enough runtime context to plan.
     * This is intentionally not a build endpoint and never creates a preview ticket or mutation.
     */
    public function post_understand( WP_REST_Request $request ) {
        $body = $this->json_body( $request );
        if ( is_wp_error( $body ) ) { return $body; }

        $brief = trim( sanitize_textarea_field( (string) ( $body['brief'] ?? '' ) ) );
        if ( '' === $brief ) {
            return new WP_Error( 'design_core_api_brief_required', 'brief is required.', array( 'status' => 400 ) );
        }
        if ( strlen( $brief ) > 16384 ) {
            return new WP_Error( 'design_core_api_brief_too_long', 'brief must be 16 KB or fewer.', array( 'status' => 400 ) );
        }
        $page_id = max( 0, (int) ( $body['page_id'] ?? 0 ) );
        $limit = min( 200, max( 1, (int) ( $body['site_map_limit'] ?? 100 ) ) );

        $site = new Design_Core_Elementor_Site_Intelligence();
        $runtime = new Design_Core_Elementor_Runtime_Intelligence();
        $task = new Design_Core_Elementor_Task_Intelligence();

        $site_map = $site->site_map( $limit );
        if ( is_wp_error( $site_map ) ) { return $site_map; }
        $design_system = $site->site_design_system();
        if ( is_wp_error( $design_system ) ) { return $design_system; }
        $capabilities = $runtime->elementor_capabilities();
        if ( is_wp_error( $capabilities ) ) { return $capabilities; }
        $plan = $task->plan_task( $brief, $page_id );
        if ( is_wp_error( $plan ) ) { return $plan; }

        $result = array(
            'api_version' => Design_Core_Elementor_API_Platform::VERSION,
            'mutation' => false,
            'brief' => $brief,
            'page_id' => $page_id,
            'site_status' => ( new Design_Core_Elementor_Site_Status() )->report(),
            'site_map' => $site_map,
            'design_system' => $design_system,
            'elementor_capabilities' => $capabilities,
            'task_plan' => $plan,
            'recommended_next_action' => 'Review the task plan. If a build is intended, create/use a draft page and request a Design Core preview before any mutation.',
        );
        return rest_ensure_response( Design_Core_Elementor_Change_Ledger::transport_safe( $result ) );
    }

    /**
     * Post-build state verification. It deliberately does not claim visual equivalence: use
     * /pages/{id}/visual-feedback for reference-vs-candidate visual comparison.
     */
    public function post_verify_page( WP_REST_Request $request ) {
        $body = $this->json_body( $request );
        if ( is_wp_error( $body ) ) { return $body; }
        $page_id = (int) $request->get_param( 'id' );
        if ( $page_id <= 0 ) {
            return new WP_Error( 'design_core_api_page_id_invalid', 'A valid page id is required.', array( 'status' => 400 ) );
        }

        $post = get_post( $page_id );
        if ( ! $post || 'page' !== $post->post_type ) {
            return new WP_Error( 'design_core_api_page_not_found', 'The requested WordPress page was not found.', array( 'status' => 404 ) );
        }

        $snapshot = ( new Design_Core_Elementor_Page_Snapshot() )->snapshot( $page_id );
        if ( is_wp_error( $snapshot ) ) { return $snapshot; }
        $ux_audit = ( new Design_Core_Elementor_UX_Quality_Auditor() )->audit_page( $page_id );
        if ( is_wp_error( $ux_audit ) ) { return $ux_audit; }

        $history = array_values( array_filter(
            (array) ( new Design_Core_Elementor_Change_Ledger() )->summaries(),
            static function ( $entry ) use ( $page_id ) {
                return (int) ( $entry['object_id'] ?? 0 ) === $page_id;
            }
        ) );

        $result = array(
            'api_version' => Design_Core_Elementor_API_Platform::VERSION,
            'mutation' => false,
            'verification_scope' => 'state-and-quality',
            'page' => array(
                'id' => $page_id,
                'title' => sanitize_text_field( (string) $post->post_title ),
                'status' => sanitize_key( (string) $post->post_status ),
                'url' => (string) get_permalink( $page_id ),
                'preview_url' => (string) get_preview_post_link( $page_id ),
                'modified_gmt' => sanitize_text_field( (string) $post->post_modified_gmt ),
            ),
            'snapshot' => $snapshot,
            'ux_audit' => $ux_audit,
            'history' => $history,
            'visual_verification' => array(
                'performed' => false,
                'next_endpoint' => '/pages/' . $page_id . '/visual-feedback',
                'note' => 'State verification does not substitute for reference-based visual feedback.',
            ),
        );
        return rest_ensure_response( Design_Core_Elementor_Change_Ledger::transport_safe( $result ) );
    }

    private function json_body( WP_REST_Request $request ) {
        $raw = (string) $request->get_body();
        if ( strlen( $raw ) > self::MAX_BODY_BYTES ) {
            return new WP_Error( 'design_core_api_payload_too_large', 'API orchestration request exceeds 512 KB.', array( 'status' => 413 ) );
        }
        $body = $request->get_json_params();
        return is_array( $body ) ? $body : array();
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
        return new WP_Error( 'design_core_forbidden', 'You are not allowed to use the Design Core API.', array( 'status' => 403 ) );
    }
}

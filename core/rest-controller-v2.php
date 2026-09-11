<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Design Core Remote API v2 -- the MCP Bridge's only entry point into WordPress.
 *
 * Every handler here composes an existing Design Core service; none re-implement
 * Design IR/BuildPlan/Elementor/persistence logic. v1 (`design-core-elementor/v1`) is
 * untouched. Auth accepts either a normal wp-admin session with the matching
 * `design_core_*` capability, or a validated machine-credential Bearer token with the
 * matching scope (see Machine_Credential_Auth) -- never both blended into one WP_User.
 */
class Design_Core_Elementor_Rest_Controller_V2 {
    const NAMESPACE_V2 = 'design-core-elementor/v2';
    const MAX_BODY_BYTES = 2097152;

    public function register_routes() {
        register_rest_route( self::NAMESPACE_V2, '/site/status', array(
            'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_site_status' ), 'permission_callback' => array( $this, 'permission_read' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/pages/(?P<id>\d+)/snapshot', array(
            'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_page_snapshot' ), 'permission_callback' => array( $this, 'permission_read' ),
            'args' => array( 'id' => array( 'sanitize_callback' => 'absint', 'required' => true ) ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/history', array(
            'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_history' ), 'permission_callback' => array( $this, 'permission_read' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/build/preview', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'post_build_preview' ), 'permission_callback' => array( $this, 'permission_preview' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/figma/preview', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'post_figma_preview' ), 'permission_callback' => array( $this, 'permission_preview' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/pages/(?P<id>\d+)/visual-feedback', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'post_visual_feedback' ), 'permission_callback' => array( $this, 'permission_preview' ),
            'args' => array( 'id' => array( 'sanitize_callback' => 'absint', 'required' => true ) ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/pages/(?P<id>\d+)/update', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'post_pages_update' ), 'permission_callback' => array( $this, 'permission_modify' ),
            'args' => array( 'id' => array( 'sanitize_callback' => 'absint', 'required' => true ) ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/pages/(?P<id>\d+)/auto-correct', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'post_auto_correct' ), 'permission_callback' => array( $this, 'permission_modify' ),
            'args' => array( 'id' => array( 'sanitize_callback' => 'absint', 'required' => true ) ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/pages/(?P<id>\d+)/publish', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'post_pages_publish' ), 'permission_callback' => array( $this, 'permission_publish' ),
            'args' => array( 'id' => array( 'sanitize_callback' => 'absint', 'required' => true ) ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/history/(?P<entry>[a-zA-Z0-9_-]+)/rollback', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'post_history_rollback' ), 'permission_callback' => array( $this, 'permission_rollback' ),
            'args' => array( 'entry' => array( 'sanitize_callback' => 'sanitize_key', 'required' => true ) ),
        ) );
    }

    // -- Permission callbacks -------------------------------------------------------

    public function permission_read( WP_REST_Request $r ) { return $this->authorize( $r, Design_Core_Elementor_Capabilities::READ ); }
    public function permission_preview( WP_REST_Request $r ) { return $this->authorize( $r, Design_Core_Elementor_Capabilities::PREVIEW ); }
    public function permission_modify( WP_REST_Request $r ) { return $this->authorize( $r, Design_Core_Elementor_Capabilities::MODIFY ); }
    public function permission_publish( WP_REST_Request $r ) { return $this->authorize( $r, Design_Core_Elementor_Capabilities::PUBLISH ); }
    public function permission_rollback( WP_REST_Request $r ) { return $this->authorize( $r, Design_Core_Elementor_Capabilities::ROLLBACK ); }

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

    // -- Read ------------------------------------------------------------------------

    public function get_site_status() { return rest_ensure_response( ( new Design_Core_Elementor_Site_Status() )->report() ); }

    public function get_page_snapshot( WP_REST_Request $r ) {
        $x = ( new Design_Core_Elementor_Page_Snapshot() )->snapshot( (int) $r->get_param( 'id' ) );
        return is_wp_error( $x ) ? $x : rest_ensure_response( Design_Core_Elementor_Change_Ledger::transport_safe( $x ) );
    }

    public function get_history() { return rest_ensure_response( ( new Design_Core_Elementor_Change_Ledger() )->summaries() ); }

    // -- Preview (mutation-free) -------------------------------------------------------

    public function post_build_preview( WP_REST_Request $r ) {
        $b = $this->json_body( $r ); if ( is_wp_error( $b ) ) { return $b; }
        $page_id = (int) ( $b['page_id'] ?? 0 );
        $service = new Design_Core_Elementor_Build_Plan_Preview();
        $preview = ! empty( $b['design_ir'] ) && is_array( $b['design_ir'] )
            ? $service->preview_ir( $b['design_ir'], (string) ( $b['adapter_target'] ?? 'auto' ) )
            : $service->preview_source( (string) ( $b['html'] ?? '' ), (string) ( $b['css'] ?? '' ), 'remote-preview', (string) ( $b['adapter_target'] ?? 'auto' ) );
        if ( is_wp_error( $preview ) ) { return $preview; }
        return rest_ensure_response( $this->ticketize_preview( $preview, $page_id ) );
    }

    public function post_figma_preview( WP_REST_Request $r ) {
        $b = $this->json_body( $r ); if ( is_wp_error( $b ) ) { return $b; }
        $page_id = (int) ( $b['page_id'] ?? 0 );
        $transport = new Design_Core_Elementor_Figma_Transport();
        if ( ! $transport->configured() ) { return new WP_Error( 'design_core_figma_not_configured', 'A Figma access token is not configured on this site (DESIGN_CORE_FIGMA_ACCESS_TOKEN).', array( 'status' => 409 ) ); }
        if ( empty( $b['figma_url'] ) ) { return new WP_Error( 'design_core_figma_url_required', 'figma_url is required.', array( 'status' => 400 ) ); }
        $payload = $transport->read_url( (string) $b['figma_url'], array( 'resolve_image_fills' => true ) );
        if ( is_wp_error( $payload ) ) { return $payload; }
        $ir = ( new Design_Core_Elementor_Figma_Design_IR_Adapter() )->convert( $payload, (string) ( $b['node_id'] ?? '' ) );
        if ( is_wp_error( $ir ) ) { return $ir; }
        $preview = ( new Design_Core_Elementor_Build_Plan_Preview() )->preview_ir( $ir, (string) ( $b['adapter_target'] ?? 'auto' ) );
        if ( is_wp_error( $preview ) ) { return $preview; }
        $result = $this->ticketize_preview( $preview, $page_id );
        // Design Core does not run free-text instructions through any interpreter today; the
        // value is only echoed back for traceability, never used to alter planning decisions.
        $result['instructions_acknowledged'] = sanitize_text_field( (string) ( $b['instructions'] ?? '' ) );
        return rest_ensure_response( $result );
    }

    public function post_visual_feedback( WP_REST_Request $r ) {
        $page_id = (int) $r->get_param( 'id' );
        $b = $this->json_body( $r ); if ( is_wp_error( $b ) ) { return $b; }
        $reference = (string) ( $b['reference_target'] ?? $b['reference_url'] ?? '' );
        $candidate = (string) ( $b['candidate_target'] ?? '' ) ?: $this->page_render_url( $page_id );
        $x = ( new Design_Core_Elementor_Visual_Feedback_Engine() )->evaluate_targets( $reference, $candidate, array( 'page_id' => $page_id, 'target_similarity' => (float) ( $b['target_similarity'] ?? 0.95 ) ) );
        return is_wp_error( $x ) ? $x : rest_ensure_response( Design_Core_Elementor_Change_Ledger::transport_safe( $x ) );
    }

    private function ticketize_preview( array $preview, $page_id ) {
        $ticket = Design_Core_Elementor_Preview_Ticket_Store::create( (int) $page_id, $preview );
        return array(
            'preview_id' => $ticket['id'],
            'plan_hash' => $ticket['plan_hash'],
            'page_id' => $ticket['page_id'],
            'current_page_hash' => $ticket['current_page_hash'],
            'expires_at' => $ticket['expires_at'],
            'adapter_target' => $ticket['adapter_target'],
            'can_execute_safely' => $preview['can_execute_safely'] ?? false,
            'unsafe_reasons' => $preview['unsafe_reasons'] ?? array(),
            'estimated_element_count' => $preview['estimated_element_count'] ?? 0,
            'strategy_counts' => $preview['strategy_counts'] ?? array(),
            'requirements' => $preview['requirements'] ?? array(),
            'warnings' => $preview['warnings'] ?? array(),
            'items' => $preview['items'] ?? array(),
            'execution_simulation' => $preview['execution_simulation'] ?? array(),
        );
    }

    // -- Write (approval-gated) --------------------------------------------------------

    public function post_pages_update( WP_REST_Request $r ) {
        $guard = Design_Core_Elementor_Remote_Write_Guard::ensure_writes_enabled(); if ( is_wp_error( $guard ) ) { return $guard; }
        // P3: Check credential environment matches target site environment
        $principal = $r->get_param( '_design_core_principal' );
        $env_check = Design_Core_Elementor_Remote_Write_Guard::ensure_credential_environment_match( $principal );
        if ( is_wp_error( $env_check ) ) { return $env_check; }
        
        $page_id = (int) $r->get_param( 'id' );
        $b = $this->json_body( $r ); if ( is_wp_error( $b ) ) { return $b; }
        if ( empty( $b['confirm'] ) ) { return new WP_Error( 'design_core_confirmation_required', 'Updating a page requires confirm=true.', array( 'status' => 400 ) ); }
        $preview_id = sanitize_text_field( (string) ( $b['preview_id'] ?? '' ) );
        $plan_hash = sanitize_text_field( (string) ( $b['plan_hash'] ?? '' ) );
        if ( '' === $preview_id || '' === $plan_hash ) { return new WP_Error( 'design_core_preview_required', 'preview_id and plan_hash from an approved preview are required.', array( 'status' => 400 ) ); }
        $production_guard = Design_Core_Elementor_Remote_Write_Guard::ensure_production_guard( true, true ); if ( is_wp_error( $production_guard ) ) { return $production_guard; }

        return $this->with_idempotency( $r, function () use ( $page_id, $preview_id, $plan_hash, $r ) {
            $ticket = Design_Core_Elementor_Preview_Ticket_Store::validate_for_execute( $preview_id, $page_id, $plan_hash );
            if ( is_wp_error( $ticket ) ) { return $ticket; }
            $result = ( new Design_Core_Elementor_Conversion_Service() )->execute_approved_plan( $page_id, $ticket['ir'], $ticket['plan'], array( 'adapter_target' => $ticket['adapter_target'] ) );
            if ( 'success' !== ( $result['status'] ?? '' ) ) {
                return new WP_Error( 'design_core_update_failed', (string) ( $result['error'] ?? 'Page update failed.' ), array( 'status' => 500, 'details' => Design_Core_Elementor_Change_Ledger::transport_safe( $result ) ) );
            }
            Design_Core_Elementor_Preview_Ticket_Store::consume( $preview_id );
            $this->audit( $r, 'update_page', $page_id, array(
                'preview_id' => $preview_id, 'plan_hash' => $plan_hash,
                'before_hash' => $result['before_hash'] ?? '', 'after_hash' => $result['after_hash'] ?? '',
                'history_entry_id' => $result['history_entry_id'] ?? '',
            ) );
            return array(
                'status' => 'success', 'page_id' => $page_id, 'elements' => $result['elements'], 'editor_mode' => $result['editor_mode'],
                'history_entry_id' => $result['history_entry_id'] ?? '',
                'rollback_available' => $this->rollback_available( $result['history_entry_id'] ?? '' ),
            );
        } );
    }

    public function post_auto_correct( WP_REST_Request $r ) {
        $guard = Design_Core_Elementor_Remote_Write_Guard::ensure_writes_enabled(); if ( is_wp_error( $guard ) ) { return $guard; }
        // P3: Check credential environment matches target site environment
        $principal = $r->get_param( '_design_core_principal' );
        $env_check = Design_Core_Elementor_Remote_Write_Guard::ensure_credential_environment_match( $principal );
        if ( is_wp_error( $env_check ) ) { return $env_check; }
        
        $page_id = (int) $r->get_param( 'id' );
        $b = $this->json_body( $r ); if ( is_wp_error( $b ) ) { return $b; }
        if ( empty( $b['confirm'] ) ) { return new WP_Error( 'design_core_confirmation_required', 'Auto-correct requires confirm=true.', array( 'status' => 400 ) ); }
        if ( ! get_post( $page_id ) ) { return new WP_Error( 'design_core_page_missing', 'Page was not found.', array( 'status' => 404 ) ); }

        return $this->with_idempotency( $r, function () use ( $page_id, $b, $r ) {
            $reference = (string) ( $b['reference_target'] ?? $b['reference_url'] ?? '' );
            $candidate = (string) ( $b['candidate_target'] ?? '' ) ?: $this->page_render_url( $page_id );
            $x = ( new Design_Core_Elementor_Visual_Correction_Service() )->run(
                $reference, $candidate,
                (int) ( $b['max_iterations'] ?? 3 ), (float) ( $b['target_similarity'] ?? 0.97 ),
                array( 'page_id' => $page_id )
            );
            if ( is_wp_error( $x ) ) { return $x; }
            $this->audit( $r, 'auto_correct', $page_id, array() );
            return Design_Core_Elementor_Change_Ledger::transport_safe( $x );
        } );
    }

    public function post_pages_publish( WP_REST_Request $r ) {
        $guard = Design_Core_Elementor_Remote_Write_Guard::ensure_writes_enabled(); if ( is_wp_error( $guard ) ) { return $guard; }
        // P3: Check credential environment matches target site environment
        $principal = $r->get_param( '_design_core_principal' );
        $env_check = Design_Core_Elementor_Remote_Write_Guard::ensure_credential_environment_match( $principal );
        if ( is_wp_error( $env_check ) ) { return $env_check; }
        
        $page_id = (int) $r->get_param( 'id' );
        $b = $this->json_body( $r ); if ( is_wp_error( $b ) ) { return $b; }
        if ( empty( $b['confirm'] ) ) { return new WP_Error( 'design_core_confirmation_required', 'Publishing requires confirm=true.', array( 'status' => 400 ) ); }
        $post = get_post( $page_id ); if ( ! $post ) { return new WP_Error( 'design_core_page_missing', 'Page was not found.', array( 'status' => 404 ) ); }

        return $this->with_idempotency( $r, function () use ( $page_id, $post, $r ) {
            if ( 'publish' === $post->post_status ) { return array( 'status' => 'already-published', 'page_id' => $page_id ); }
            $before_status = $post->post_status;
            $updated = wp_update_post( array( 'ID' => $page_id, 'post_status' => 'publish' ), true );
            if ( is_wp_error( $updated ) ) { return $updated; }
            $entry = class_exists( 'Design_Core_Elementor_Change_Ledger' ) ? ( new Design_Core_Elementor_Change_Ledger() )->record( 'page-publish', 'post', $page_id, $before_status, 'publish', array() ) : null;
            $entry_id = is_array( $entry ) ? (string) ( $entry['id'] ?? '' ) : '';
            $this->audit( $r, 'publish_page', $page_id, array( 'history_entry_id' => $entry_id ) );
            return array( 'status' => 'success', 'page_id' => $page_id, 'previous_status' => $before_status, 'history_entry_id' => $entry_id, 'rollback_available' => $this->rollback_available( $entry_id ) );
        } );
    }

    public function post_history_rollback( WP_REST_Request $r ) {
        $guard = Design_Core_Elementor_Remote_Write_Guard::ensure_writes_enabled(); if ( is_wp_error( $guard ) ) { return $guard; }
        // P3: Check credential environment matches target site environment
        $principal = $r->get_param( '_design_core_principal' );
        $env_check = Design_Core_Elementor_Remote_Write_Guard::ensure_credential_environment_match( $principal );
        if ( is_wp_error( $env_check ) ) { return $env_check; }
        
        $b = $this->json_body( $r ); if ( is_wp_error( $b ) ) { return $b; }
        if ( empty( $b['confirm'] ) ) { return new WP_Error( 'design_core_confirmation_required', 'Rollback requires confirm=true.', array( 'status' => 400 ) ); }
        $entry_id = sanitize_key( (string) $r->get_param( 'entry' ) );

        return $this->with_idempotency( $r, function () use ( $entry_id, $r ) {
            $x = ( new Design_Core_Elementor_Change_Ledger() )->rollback( $entry_id );
            if ( is_wp_error( $x ) ) { return $x; }
            $this->audit( $r, 'rollback', $x['post_id'] ?? 0, array( 'rollback_of' => $entry_id, 'history_entry_id' => is_array( $x['rollback_entry'] ?? null ) ? ( $x['rollback_entry']['id'] ?? '' ) : '' ) );
            return $x;
        } );
    }

    // -- Shared helpers ---------------------------------------------------------------

    private function with_idempotency( WP_REST_Request $r, callable $operation ) {
        $key = trim( (string) $r->get_header( 'idempotency-key' ) );
        $principal = $r->get_param( '_design_core_principal' );
        $scope_id = is_array( $principal ) ? ( ( $principal['type'] ?? 'anon' ) . ':' . ( $principal['id'] ?? '0' ) ) : 'anon';
        $fingerprint = Design_Core_Elementor_Idempotency_Store::fingerprint( $r->get_method(), $r->get_route(), $r->get_json_params() );

        $begin = Design_Core_Elementor_Idempotency_Store::begin( $scope_id, $key, $fingerprint );
        if ( is_wp_error( $begin ) ) { return $begin; }
        if ( ! empty( $begin['replay'] ) ) {
            $stored = $begin['response'];
            if ( ! empty( $stored['is_error'] ) ) { return new WP_Error( 'design_core_idempotent_replay', (string) ( $stored['body']['message'] ?? 'Replayed error.' ), array( 'status' => $stored['status'] ) ); }
            return rest_ensure_response( $stored['body'] );
        }

        $result = $operation();
        if ( is_wp_error( $result ) ) {
            $data = $result->get_error_data(); $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
            if ( ! empty( $begin['enabled'] ) ) { Design_Core_Elementor_Idempotency_Store::complete( $scope_id, $key, $fingerprint, true, $status, array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ) ); }
            return $result;
        }
        if ( ! empty( $begin['enabled'] ) ) { Design_Core_Elementor_Idempotency_Store::complete( $scope_id, $key, $fingerprint, false, 200, $result ); }
        return rest_ensure_response( $result );
    }

    private function audit( WP_REST_Request $r, $tool, $page_id, array $extra = array() ) {
        $principal = $r->get_param( '_design_core_principal' );
        Design_Core_Elementor_Remote_Audit_Log::record( array_merge( array(
            'request_id' => (string) ( $r->get_header( 'x-request-id' ) ?: wp_generate_uuid4() ),
            'site' => Design_Core_Elementor_Remote_Settings::environment(),
            'tool' => sanitize_key( (string) $tool ),
            'page_id' => (int) $page_id,
            'machine_credential_id' => is_array( $principal ) && 'credential' === ( $principal['type'] ?? '' ) ? $principal['id'] : '',
            'actor' => is_array( $principal ) && 'user' === ( $principal['type'] ?? '' ) ? (int) $principal['id'] : 0,
            'result' => 'success',
        ), $extra ) );
    }

    private function rollback_available( $history_entry_id ) {
        if ( '' === (string) $history_entry_id ) { return false; }
        $entry = ( new Design_Core_Elementor_Change_Ledger() )->get( $history_entry_id );
        return is_array( $entry ) && Design_Core_Elementor_Change_Ledger::rollback_available_for( $entry );
    }

    private function page_render_url( $page_id ) {
        $page_id = (int) $page_id;
        if ( 'publish' === get_post_status( $page_id ) ) { return (string) get_permalink( $page_id ); }
        return (string) get_preview_post_link( $page_id );
    }

    private function json_body( WP_REST_Request $r ) {
        $b = $r->get_json_params();
        if ( ! is_array( $b ) ) { $b = array(); }
        $encoded = wp_json_encode( $b );
        if ( is_string( $encoded ) && strlen( $encoded ) > self::MAX_BODY_BYTES ) { return new WP_Error( 'design_core_rest_payload_too_large', 'Request payload exceeds the 2 MB Design Core limit.', array( 'status' => 413 ) ); }
        return $b;
    }
}

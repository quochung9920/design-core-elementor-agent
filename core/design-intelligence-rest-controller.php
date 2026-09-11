<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Additive read/preview-only REST surface for Design Intelligence. */
class Design_Core_Elementor_Design_Intelligence_Rest_Controller {
    const NAMESPACE_V2 = 'design-core-elementor/v2';
    const MAX_BODY_BYTES = 524288;

    public function register_routes() {
        register_rest_route( self::NAMESPACE_V2, '/design-intelligence/status', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_status' ),
            'permission_callback' => array( $this, 'permission_read' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/design-intelligence/recommend', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'post_recommend' ),
            'permission_callback' => array( $this, 'permission_preview' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/design-intelligence/preview', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'post_preview' ),
            'permission_callback' => array( $this, 'permission_preview' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/design-intelligence/enrich-ir', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'post_enrich_ir' ),
            'permission_callback' => array( $this, 'permission_preview' ),
        ) );
        register_rest_route( self::NAMESPACE_V2, '/design-intelligence/pages/(?P<id>\d+)/ux-audit', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_ux_audit' ),
            'permission_callback' => array( $this, 'permission_read' ),
            'args' => array( 'id' => array( 'sanitize_callback' => 'absint', 'required' => true ) ),
        ) );
    }

    public function permission_read( WP_REST_Request $request ) { return $this->authorize( $request, Design_Core_Elementor_Capabilities::READ ); }
    public function permission_preview( WP_REST_Request $request ) { return $this->authorize( $request, Design_Core_Elementor_Capabilities::PREVIEW ); }

    public function get_status() {
        return rest_ensure_response( array(
            'design_intelligence_version' => 1,
            'catalog' => ( new Design_Core_Elementor_Design_Intelligence_Catalog() )->status(),
            'runtime' => 'php-local-json',
            'python_runtime_required' => false,
            'network_required' => false,
            'source' => 'ui-ux-pro-max-normalized',
        ) );
    }

    public function post_recommend( WP_REST_Request $request ) {
        $body = $this->json_body( $request ); if ( is_wp_error( $body ) ) { return $body; }
        $options = $this->options_from_body( $body );
        $result = ( new Design_Core_Elementor_Design_Advisor() )->recommend( (string) ( $body['brief'] ?? '' ), $options );
        return is_wp_error( $result ) ? $result : rest_ensure_response( Design_Core_Elementor_Change_Ledger::transport_safe( $result ) );
    }

    /**
     * Turns a recommendation into an existing Page Shell -> Design IR -> BuildPlan preview.
     * This is still mutation-free; the returned preview_id/plan_hash goes through the normal
     * rc21 approval-gated update route if a caller later chooses to execute it.
     *
     * For a page-bound preview, real section bindings are required so approval can never
     * accidentally authorize placeholder content. Exploratory page_id=0 previews may use
     * deterministic placeholders because those tickets cannot execute against a real page.
     */
    public function post_preview( WP_REST_Request $request ) {
        $body = $this->json_body( $request ); if ( is_wp_error( $body ) ) { return $body; }
        $advisor = new Design_Core_Elementor_Design_Advisor();
        $brief = (string) ( $body['brief'] ?? '' );
        $recommendation = $advisor->recommend( $brief, $this->options_from_body( $body ) );
        if ( is_wp_error( $recommendation ) ) { return $recommendation; }
        $profile = (array) ( $recommendation['profile'] ?? array() );
        $shell = sanitize_key( (string) ( $profile['page_strategy']['recommended_shell'] ?? '' ) );
        if ( '' === $shell ) {
            return new WP_Error( 'design_core_design_shell_unavailable', 'The selected Design Intelligence profile has no suitable existing Design Core Page Shell. Use the recommendation as guidance or add a matching shell/recipe family first.', array( 'status' => 409, 'profile' => $profile['product'] ?? array() ) );
        }

        $page_id = (int) ( $body['page_id'] ?? 0 );
        $bindings = is_array( $body['bindings'] ?? null ) ? $body['bindings'] : array();
        $placeholder_bindings = false;
        if ( $page_id > 0 && ! $bindings ) {
            return new WP_Error( 'design_core_design_bindings_required', 'A page-bound Design Intelligence preview requires explicit Page Shell bindings. Request an exploratory page_id=0 preview first, then provide real approved content bindings before creating an executable preview.', array( 'status' => 400, 'shell' => $shell ) );
        }
        if ( 0 === $page_id && ! $bindings ) {
            $bindings = $this->exploratory_bindings( $shell, $profile, $brief );
            $placeholder_bindings = true;
        }

        $ir = ( new Design_Core_Elementor_Page_Shell() )->compile( $shell, $bindings );
        if ( is_wp_error( $ir ) ) { return $ir; }
        $ir = $advisor->enrich_ir( $ir, $profile );
        if ( is_wp_error( $ir ) ) { return $ir; }

        $preview = ( new Design_Core_Elementor_Build_Plan_Preview() )->preview_ir( $ir, (string) ( $body['adapter_target'] ?? 'auto' ) );
        if ( is_wp_error( $preview ) ) { return $preview; }
        $ticket = Design_Core_Elementor_Preview_Ticket_Store::create( $page_id, $preview );
        $warnings = array_values( array_merge( (array) ( $recommendation['warnings'] ?? array() ), (array) ( $preview['warnings'] ?? array() ) ) );
        if ( $placeholder_bindings ) { $warnings[] = 'Exploratory placeholder bindings were used. This page_id=0 ticket cannot be executed against a real page; create a new page-bound preview with explicit content bindings before approval.'; }
        return rest_ensure_response( Design_Core_Elementor_Change_Ledger::transport_safe( array(
            'recommendation' => $recommendation,
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
            'warnings' => $warnings,
            'items' => $preview['items'] ?? array(),
            'execution_simulation' => $preview['execution_simulation'] ?? array(),
            'placeholder_bindings' => $placeholder_bindings,
            'exploratory_only' => 0 === $page_id,
            'mutation' => false,
        ) ) );
    }

    public function post_enrich_ir( WP_REST_Request $request ) {
        $body = $this->json_body( $request ); if ( is_wp_error( $body ) ) { return $body; }
        if ( ! is_array( $body['design_ir'] ?? null ) ) { return new WP_Error( 'design_core_design_ir_required', 'design_ir is required.', array( 'status' => 400 ) ); }
        $advisor = new Design_Core_Elementor_Design_Advisor();
        $profile = is_array( $body['profile'] ?? null ) ? $body['profile'] : null;
        $recommendation = null;
        if ( ! $profile ) {
            $recommendation = $advisor->recommend( (string) ( $body['brief'] ?? '' ), $this->options_from_body( $body ) );
            if ( is_wp_error( $recommendation ) ) { return $recommendation; }
            $profile = $recommendation['profile'];
        }
        $ir = $advisor->enrich_ir( $body['design_ir'], $profile );
        if ( is_wp_error( $ir ) ) { return $ir; }
        return rest_ensure_response( Design_Core_Elementor_Change_Ledger::transport_safe( array( 'design_ir'=>$ir, 'profile'=>$profile, 'recommendation'=>$recommendation, 'mutation'=>false ) ) );
    }

    public function get_ux_audit( WP_REST_Request $request ) {
        $page_id = (int) $request->get_param( 'id' );
        return rest_ensure_response( Design_Core_Elementor_Change_Ledger::transport_safe( ( new Design_Core_Elementor_UX_Quality_Auditor() )->audit_page( $page_id ) ) );
    }

    private function exploratory_bindings( $shell, array $profile, $brief ) {
        $category = sanitize_text_field( (string) ( $profile['product']['category'] ?? 'Product' ) );
        $summary = sanitize_textarea_field( (string) $brief );
        if ( strlen( $summary ) > 280 ) { $summary = substr( $summary, 0, 277 ) . '...'; }
        $common = array(
            'hero' => array( 'eyebrow'=>$category, 'heading'=>$category . ' — exploratory headline', 'description'=>$summary, 'primary_cta'=>array( 'text'=>'Primary action' ) ),
            'intro' => array( 'heading'=>'Value proposition', 'description'=>$summary ),
            'benefits' => array( 'heading'=>'Key capabilities', 'items'=>array( 'Capability one', 'Capability two', 'Capability three' ) ),
            'process' => array( 'heading'=>'How it works', 'steps'=>array( 'Discover', 'Plan', 'Deliver' ) ),
            'cta' => array( 'heading'=>'Ready to continue?', 'description'=>'Exploratory CTA copy for layout planning only.', 'primary_cta'=>array( 'text'=>'Primary action' ) ),
            'services' => array( 'heading'=>'Services', 'services'=>array( 'Service one', 'Service two', 'Service three' ) ),
        );
        if ( 'resource-article' === $shell ) { return array( 'hero'=>$common['hero'], 'intro'=>$common['intro'] ); }
        if ( 'location-landing' === $shell ) { return array( 'hero'=>$common['hero'], 'intro'=>$common['intro'], 'services'=>$common['services'], 'cta'=>$common['cta'] ); }
        if ( 'import-guide' === $shell ) { return array( 'hero'=>$common['hero'], 'intro'=>$common['intro'], 'process'=>$common['process'], 'cta'=>$common['cta'] ); }
        if ( 'contact-about' === $shell ) { return array( 'hero'=>$common['hero'], 'intro'=>$common['intro'], 'cta'=>$common['cta'] ); }
        return array( 'hero'=>$common['hero'], 'intro'=>$common['intro'], 'benefits'=>$common['benefits'], 'process'=>$common['process'], 'cta'=>$common['cta'] );
    }

    private function options_from_body( array $body ) {
        return array(
            'product_type' => sanitize_text_field( (string) ( $body['product_type'] ?? '' ) ),
            'mode' => sanitize_key( (string) ( $body['mode'] ?? 'light' ) ),
            'variance' => (int) ( $body['variance'] ?? 0 ) ?: null,
            'motion' => (int) ( $body['motion'] ?? 0 ) ?: null,
            'density' => (int) ( $body['density'] ?? 0 ) ?: null,
            'max_rules' => (int) ( $body['max_rules'] ?? 18 ),
        );
    }

    private function json_body( WP_REST_Request $request ) {
        $raw = (string) $request->get_body();
        if ( strlen( $raw ) > self::MAX_BODY_BYTES ) { return new WP_Error( 'design_core_design_payload_too_large', 'Design Intelligence request body exceeds 512 KB.', array( 'status' => 413 ) ); }
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
            $request->set_param( '_design_core_principal', array( 'type'=>'user', 'id'=>get_current_user_id() ) );
            return true;
        }
        return new WP_Error( 'design_core_forbidden', 'You are not allowed to use Design Core Design Intelligence.', array( 'status' => 403 ) );
    }
}

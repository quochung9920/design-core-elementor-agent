<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Compact agent gateway shared by REST and optional WordPress Abilities/MCP adapters. */
class Design_Core_Elementor_Agent_Gateway {
    const VERSION = 2;
    const MAX_ARGUMENT_BYTES = 2097152;

    public function catalog( $search = '' ) {
        $search = strtolower( trim( (string) $search ) ); $tools = array();
        foreach ( self::definitions() as $name => $definition ) {
            if ( $search && false === strpos( strtolower( $name . ' ' . $definition['description'] ), $search ) ) { continue; }
            $tools[] = array( 'name' => $name, 'description' => $definition['description'], 'readonly' => ! empty( $definition['readonly'] ), 'destructive' => ! empty( $definition['destructive'] ) );
        }
        return array( 'version' => self::VERSION, 'tools' => $tools );
    }

    public function schema( $name ) {
        $name = sanitize_key( (string) $name ); $definitions = self::definitions();
        if ( ! isset( $definitions[ $name ] ) ) { return new WP_Error( 'design_core_tool_unknown', 'Unknown Design Core tool.' ); }
        $definition = $definitions[ $name ];
        return array( 'name' => $name, 'description' => $definition['description'], 'input_schema' => $definition['input_schema'], 'readonly' => $definition['readonly'], 'destructive' => $definition['destructive'] );
    }

    public function execute( $name, array $arguments = array() ) {
        $name = sanitize_key( (string) $name ); $definitions = self::definitions();
        if ( ! isset( $definitions[ $name ] ) ) { return new WP_Error( 'design_core_tool_unknown', 'Unknown Design Core tool.' ); }
        if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'design_core_tool_forbidden', 'You are not allowed to execute Design Core tools.' ); }
        $encoded = wp_json_encode( Design_Core_Elementor_Change_Ledger::transport_safe( $arguments ) );
        if ( is_string( $encoded ) && strlen( $encoded ) > self::MAX_ARGUMENT_BYTES ) { return new WP_Error( 'design_core_tool_payload_too_large', 'Agent tool arguments exceed the 2 MB Design Core limit.' ); }
        if ( ! empty( $definitions[ $name ]['destructive'] ) && empty( $arguments['confirm'] ) ) { return new WP_Error( 'design_core_tool_confirmation_required', 'This Design Core tool is destructive and requires confirm=true.' ); }
        switch ( $name ) {
            case 'build-preview':
                if ( ! empty( $arguments['design_ir'] ) && is_array( $arguments['design_ir'] ) ) { return ( new Design_Core_Elementor_Build_Plan_Preview() )->preview_ir( $arguments['design_ir'], $arguments['adapter_target'] ?? 'auto' ); }
                return ( new Design_Core_Elementor_Build_Plan_Preview() )->preview_source( (string) ( $arguments['html'] ?? '' ), (string) ( $arguments['css'] ?? '' ), 'agent-preview', $arguments['adapter_target'] ?? 'auto' );
            case 'page-snapshot': return ( new Design_Core_Elementor_Page_Snapshot() )->snapshot( (int) ( $arguments['page_id'] ?? 0 ) );
            case 'widget-candidates': return ( new Design_Core_Elementor_Widget_Intelligence() )->find_candidates( (array) ( $arguments['requirements'] ?? array() ), (int) ( $arguments['limit'] ?? 10 ) );
            case 'section-explain':
                if ( ! empty( $arguments['master_id'] ) ) { return ( new Design_Core_Elementor_Section_Explainability() )->explain_master( (string) $arguments['master_id'] ); }
                return ( new Design_Core_Elementor_Section_Explainability() )->explain_candidate( (array) ( $arguments['fingerprint'] ?? array() ) );
            case 'page-shell-compile': return ( new Design_Core_Elementor_Page_Shell() )->compile( (string) ( $arguments['shell'] ?? '' ), (array) ( $arguments['bindings'] ?? array() ) );
            case 'figma-to-ir':
                if ( ! empty( $arguments['figma_url'] ) ) {
                    $payload = ( new Design_Core_Elementor_Figma_Transport() )->read_url( (string) $arguments['figma_url'], array( 'resolve_image_fills' => true ) );
                    if ( is_wp_error( $payload ) ) { return $payload; }
                    return ( new Design_Core_Elementor_Figma_Design_IR_Adapter() )->convert( $payload, (string) ( $arguments['node_id'] ?? '' ) );
                }
                return ( new Design_Core_Elementor_Figma_Design_IR_Adapter() )->convert( (array) ( $arguments['figma'] ?? array() ), (string) ( $arguments['node_id'] ?? '' ) );
            case 'visual-feedback': return ( new Design_Core_Elementor_Visual_Feedback_Engine() )->evaluate_targets( (string) ( $arguments['reference_target'] ?? '' ), (string) ( $arguments['candidate_target'] ?? '' ), array( 'page_id' => (int) ( $arguments['page_id'] ?? 0 ), 'target_similarity' => (float) ( $arguments['target_similarity'] ?? 0.95 ) ) );
            case 'visual-correct': return ( new Design_Core_Elementor_Visual_Correction_Service() )->run( (string) ( $arguments['reference_target'] ?? '' ), (string) ( $arguments['candidate_target'] ?? '' ), (int) ( $arguments['max_iterations'] ?? 3 ), (float) ( $arguments['target_similarity'] ?? 0.95 ), array( 'page_id' => (int) ( $arguments['page_id'] ?? 0 ) ) );
            case 'quality-benchmarks':
                $corpus = new Design_Core_Elementor_Design_Benchmark_Corpus();
                return ! empty( $arguments['id'] ) ? $corpus->planning( (string) $arguments['id'] ) : $corpus->catalog();
            case 'convert-html': return ( new Design_Core_Elementor_HTML_Converter() )->convert_to_elementor( (string) ( $arguments['html'] ?? '' ), (string) ( $arguments['css'] ?? '' ), (string) ( $arguments['title'] ?? 'Imported Page' ) );
            case 'history-list': return ( new Design_Core_Elementor_Change_Ledger() )->summaries();
            case 'history-rollback': return ( new Design_Core_Elementor_Change_Ledger() )->rollback( (string) ( $arguments['entry_id'] ?? '' ) );
        }
        return new WP_Error( 'design_core_tool_unimplemented', 'Design Core tool is not implemented.' );
    }

    public function register_ability_category() {
        if ( ! function_exists( 'wp_register_ability_category' ) ) { return; }
        wp_register_ability_category( 'design-core', array( 'label' => 'Design Core', 'description' => 'Design Core design intelligence, planning and page context tools.' ) );
    }

    public function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) { return; }
        // These legacy meta-abilities intentionally remain private from the official
        // WordPress MCP Adapter. Explicit public read/preview abilities are registered
        // by Design_Core_Elementor_WordPress_MCP_Compatibility instead.
        wp_register_ability( 'design-core/list-tools', array(
            'label' => 'List Design Core Tools', 'description' => 'Discover the compact Design Core agent tool catalog.', 'category' => 'design-core',
            'execute_callback' => array( $this, 'ability_list_tools' ), 'permission_callback' => array( $this, 'ability_permission' ),
            'input_schema' => array( 'type' => 'object', 'properties' => array( 'search' => array( 'type' => 'string' ) ) ),
            'meta' => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true, 'mcp' => array( 'public' => false ) ),
        ) );
        wp_register_ability( 'design-core/get-tool-schema', array(
            'label' => 'Get Design Core Tool Schema', 'description' => 'Return the input schema and safety annotations for a Design Core tool.', 'category' => 'design-core',
            'execute_callback' => array( $this, 'ability_get_schema' ), 'permission_callback' => array( $this, 'ability_permission' ),
            'input_schema' => array( 'type' => 'object', 'required' => array( 'name' ), 'properties' => array( 'name' => array( 'type' => 'string' ) ) ),
            'meta' => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true, 'mcp' => array( 'public' => false ) ),
        ) );
        wp_register_ability( 'design-core/call-tool', array(
            'label' => 'Call Design Core Tool', 'description' => 'Execute a legacy read/preview tool. Mutations require dedicated scoped abilities.', 'category' => 'design-core',
            'execute_callback' => array( $this, 'ability_call_tool' ), 'permission_callback' => array( $this, 'ability_permission' ),
            'input_schema' => array( 'type' => 'object', 'required' => array( 'name' ), 'properties' => array( 'name' => array( 'type' => 'string' ), 'arguments' => array( 'type' => 'object' ) ) ),
            'meta' => array( 'annotations' => array( 'readonly' => false, 'destructive' => false ), 'show_in_rest' => true, 'mcp' => array( 'public' => false ) ),
        ) );
    }

    public function ability_permission() {
        return true === Design_Core_Elementor_MCP_Ability_Bridge::permission( Design_Core_Elementor_Capabilities::READ );
    }

    public function ability_list_tools( $input ) {
        $guard = Design_Core_Elementor_MCP_Ability_Bridge::permission( Design_Core_Elementor_Capabilities::READ );
        if ( is_wp_error( $guard ) ) { return $guard; }
        $catalog = $this->catalog( (string) ( $input['search'] ?? '' ) );
        $catalog['tools'] = array_values( array_filter( $catalog['tools'], static function ( $tool ) {
            return ! empty( $tool['readonly'] ) && empty( $tool['destructive'] );
        } ) );
        $catalog['legacy_mutations_disabled'] = true;
        return $catalog;
    }

    private function ability_tool_guard( $name ) {
        $definitions = self::definitions();
        if ( ! isset( $definitions[ $name ] ) ) { return new WP_Error( 'design_core_tool_unknown', 'Unknown Design Core tool.' ); }
        $capability = in_array( $name, array( 'build-preview', 'page-shell-compile', 'figma-to-ir' ), true )
            ? Design_Core_Elementor_Capabilities::PREVIEW : Design_Core_Elementor_Capabilities::READ;
        $guard = Design_Core_Elementor_MCP_Ability_Bridge::permission( $capability );
        if ( is_wp_error( $guard ) ) { return $guard; }
        if ( empty( $definitions[ $name ]['readonly'] ) || ! empty( $definitions[ $name ]['destructive'] ) ) {
            return new WP_Error( 'design_core_legacy_write_disabled', 'Use the dedicated scoped Owner API ability for this operation; the legacy dispatcher cannot mutate site state.', array( 'status' => 403 ) );
        }
        return true;
    }

    public function ability_get_schema( $input ) {
        $name = sanitize_key( (string) ( $input['name'] ?? '' ) );
        $guard = $this->ability_tool_guard( $name );
        return is_wp_error( $guard ) ? $guard : $this->schema( $name );
    }

    public function ability_call_tool( $input ) {
        $name = sanitize_key( (string) ( $input['name'] ?? '' ) );
        $guard = $this->ability_tool_guard( $name );
        if ( is_wp_error( $guard ) ) { return $guard; }
        $arguments = (array) ( $input['arguments'] ?? array() );
        $definition = self::definitions()[ $name ];
        if ( ! function_exists( 'rest_validate_value_from_schema' ) ) {
            return new WP_Error( 'design_core_ability_validator_unavailable', 'WordPress schema validation is unavailable.', array( 'status' => 503 ) );
        }
        $valid = rest_validate_value_from_schema( $arguments, $definition['input_schema'], 'arguments' );
        return is_wp_error( $valid ) ? $valid : $this->execute( $name, $arguments );
    }

    public static function definitions() {
        return array(
            'build-preview' => array( 'description' => 'Dry-run Design IR/HTML with strategy-aware Elementor execution simulation and no mutation.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'properties' => array( 'html' => array( 'type' => 'string' ), 'css' => array( 'type' => 'string' ), 'design_ir' => array( 'type' => 'object' ), 'adapter_target' => array( 'type' => 'string', 'enum' => array( 'auto', 'elementor-v3', 'elementor-v4' ) ) ) ) ),
            'page-snapshot' => array( 'description' => 'Read one normalized page digest: manifest, Elementor tree, widgets, tokens, visual QA and history.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'required' => array( 'page_id' ), 'properties' => array( 'page_id' => array( 'type' => 'integer' ) ) ) ),
            'widget-candidates' => array( 'description' => 'Rank runtime Elementor widgets against intent/control/capability requirements.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'properties' => array( 'requirements' => array( 'type' => 'object' ), 'limit' => array( 'type' => 'integer' ) ) ) ),
            'section-explain' => array( 'description' => 'Explain a Section Registry match or inspect an existing master and its usage.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'properties' => array( 'fingerprint' => array( 'type' => 'object' ), 'master_id' => array( 'type' => 'string' ) ) ) ),
            'page-shell-compile' => array( 'description' => 'Compile a platform-neutral Page Shell and Section Recipe bindings into Design IR.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'required' => array( 'shell' ), 'properties' => array( 'shell' => array( 'type' => 'string' ), 'bindings' => array( 'type' => 'object' ) ) ) ),
            'figma-to-ir' => array( 'description' => 'Read a configured Figma URL or supplied Figma payload and convert it into Design IR v4.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'properties' => array( 'figma_url' => array( 'type' => 'string' ), 'figma' => array( 'type' => 'object' ), 'node_id' => array( 'type' => 'string' ) ), 'anyOf' => array( array( 'required' => array( 'figma_url' ) ), array( 'required' => array( 'figma' ) ) ) ) ),
            'visual-feedback' => array( 'description' => 'Compare reference/candidate screenshots plus automatic rendered DOM evidence and generate Visual Feedback v3 directives.', 'readonly' => false, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'required' => array( 'reference_target', 'candidate_target' ), 'properties' => array( 'reference_target' => array( 'type' => 'string' ), 'candidate_target' => array( 'type' => 'string' ), 'page_id' => array( 'type' => 'integer' ), 'target_similarity' => array( 'type' => 'number' ) ) ) ),
            'visual-correct' => array( 'description' => 'Run the governed visual compare/correct/re-verify loop using only live runtime-verified Elementor V3 controls.', 'readonly' => false, 'destructive' => true, 'input_schema' => array( 'type' => 'object', 'required' => array( 'reference_target', 'candidate_target', 'page_id', 'confirm' ), 'properties' => array( 'reference_target' => array( 'type' => 'string' ), 'candidate_target' => array( 'type' => 'string' ), 'page_id' => array( 'type' => 'integer' ), 'max_iterations' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 ), 'target_similarity' => array( 'type' => 'number' ), 'confirm' => array( 'type' => 'boolean', 'const' => true ) ) ) ),
            'quality-benchmarks' => array( 'description' => 'List the rc20 fidelity benchmark corpus or run mutation-free planning checks for one benchmark.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'string' ) ) ) ),
            'convert-html' => array( 'description' => 'Run the governed HTML/CSS conversion pipeline and create an Elementor page.', 'readonly' => false, 'destructive' => true, 'input_schema' => array( 'type' => 'object', 'required' => array( 'html', 'confirm' ), 'properties' => array( 'html' => array( 'type' => 'string' ), 'css' => array( 'type' => 'string' ), 'title' => array( 'type' => 'string' ), 'confirm' => array( 'type' => 'boolean', 'const' => true ) ) ) ),
            'history-list' => array( 'description' => 'List persistent Design Core change ledger entries.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'properties' => array() ) ),
            'history-rollback' => array( 'description' => 'Conflict-aware rollback of a completed Elementor save.', 'readonly' => false, 'destructive' => true, 'input_schema' => array( 'type' => 'object', 'required' => array( 'entry_id', 'confirm' ), 'properties' => array( 'entry_id' => array( 'type' => 'string' ), 'confirm' => array( 'type' => 'boolean', 'const' => true ) ) ) ),
        );
    }
}

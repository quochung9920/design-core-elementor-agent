<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Compact agent gateway shared by local agents and optional WordPress ability adapters. */
class Design_Core_Elementor_Agent_Gateway {
    const VERSION = 3;
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
                    $prepared = ( new Design_Core_Elementor_Figma_Fidelity_Service() )->prepare( (string) $arguments['figma_url'] );
                    if ( is_wp_error( $prepared ) ) { return $prepared; }
                    return array(
                        'status' => 'prepared',
                        'design_ir' => $prepared['design_ir'],
                        'source' => $prepared['source'],
                        'source_fingerprint' => $prepared['source_fingerprint'] ?? '',
                        'reference_image' => $prepared['reference_image'],
                        'design_memory' => $prepared['design_memory'] ?? array(),
                        'diagnostics' => $prepared['diagnostics'],
                    );
                }
                $ir = ( new Design_Core_Elementor_Figma_Design_IR_Adapter() )->convert( (array) ( $arguments['figma'] ?? array() ), (string) ( $arguments['node_id'] ?? '' ) );
                if ( is_wp_error( $ir ) ) { return $ir; }
                if ( class_exists( 'Design_Core_Elementor_Design_Memory_Retriever' ) ) {
                    $prepared_memory = ( new Design_Core_Elementor_Design_Memory_Retriever() )->prepare_ir( $ir );
                    return array( 'status' => 'prepared', 'design_ir' => $prepared_memory['design_ir'], 'design_memory' => $prepared_memory['memory'] );
                }
                return array( 'status' => 'prepared', 'design_ir' => $ir );

            case 'figma-prepare': return ( new Design_Core_Elementor_Figma_Fidelity_Service() )->prepare( (string) ( $arguments['figma_url'] ?? '' ) );
            case 'figma-compile': return ( new Design_Core_Elementor_Figma_Fidelity_Service() )->compile( (string) ( $arguments['figma_url'] ?? '' ) );
            case 'figma-build-draft':
                return ( new Design_Core_Elementor_Figma_Fidelity_Service() )->build_draft(
                    (string) ( $arguments['figma_url'] ?? '' ),
                    (int) ( $arguments['page_id'] ?? 0 ),
                    array( 'title' => (string) ( $arguments['title'] ?? 'Figma Import' ), 'verify' => ! empty( $arguments['verify'] ), 'target_similarity' => (float) ( $arguments['target_similarity'] ?? 0.95 ) )
                );
            case 'figma-verify':
                return ( new Design_Core_Elementor_Figma_Fidelity_Service() )->verify(
                    (string) ( $arguments['figma_url'] ?? '' ),
                    (string) ( $arguments['candidate_target'] ?? '' ),
                    array( 'page_id' => (int) ( $arguments['page_id'] ?? 0 ), 'target_similarity' => (float) ( $arguments['target_similarity'] ?? 0.95 ) )
                );

            case 'design-memory':
                $store = new Design_Core_Elementor_Design_Memory_Store(); $store->ensure_seeded();
                $snapshot = $store->snapshot();
                return array(
                    'schema_version' => $snapshot['schema_version'], 'seed_version' => $snapshot['seed_version'], 'generation' => $snapshot['generation'], 'updated_at' => $snapshot['updated_at'],
                    'lessons' => $store->lessons( (string) ( $arguments['scope'] ?? '' ) ),
                    'incident_count' => count( $store->incidents() ),
                    'benchmark_candidates' => ( new Design_Core_Elementor_Benchmark_Promoter( $store ) )->catalog(),
                );
            case 'design-memory-incidents': return array( 'incidents' => ( new Design_Core_Elementor_Design_Memory_Store() )->incidents() );
            case 'design-memory-benchmarks': return ( new Design_Core_Elementor_Benchmark_Promoter() )->catalog();

            case 'visual-feedback': return ( new Design_Core_Elementor_Visual_Feedback_Engine() )->evaluate_targets( (string) ( $arguments['reference_target'] ?? '' ), (string) ( $arguments['candidate_target'] ?? '' ), array( 'page_id' => (int) ( $arguments['page_id'] ?? 0 ), 'target_similarity' => (float) ( $arguments['target_similarity'] ?? 0.95 ) ) );
            case 'visual-correct':
                return ( new Design_Core_Elementor_Visual_Correction_Service() )->run(
                    (string) ( $arguments['reference_target'] ?? '' ), (string) ( $arguments['candidate_target'] ?? '' ),
                    (int) ( $arguments['max_iterations'] ?? 3 ), (float) ( $arguments['target_similarity'] ?? 0.95 ),
                    array( 'page_id' => (int) ( $arguments['page_id'] ?? 0 ), 'source_kind' => sanitize_key( (string) ( $arguments['source_kind'] ?? '' ) ), 'source_fingerprint' => sanitize_key( (string) ( $arguments['source_fingerprint'] ?? '' ) ) )
                );
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
        if ( ! class_exists( 'Design_Core_Elementor_MCP_Ability_Bridge' ) ) { return current_user_can( 'manage_options' ); }
        return true === Design_Core_Elementor_MCP_Ability_Bridge::permission( Design_Core_Elementor_Capabilities::READ );
    }

    public function ability_list_tools( $input ) {
        $catalog = $this->catalog( (string) ( $input['search'] ?? '' ) );
        $catalog['tools'] = array_values( array_filter( $catalog['tools'], static function ( $tool ) { return ! empty( $tool['readonly'] ) && empty( $tool['destructive'] ); } ) );
        $catalog['legacy_mutations_disabled'] = true;
        return $catalog;
    }

    private function ability_tool_guard( $name ) {
        $definitions = self::definitions();
        if ( ! isset( $definitions[ $name ] ) ) { return new WP_Error( 'design_core_tool_unknown', 'Unknown Design Core tool.' ); }
        if ( class_exists( 'Design_Core_Elementor_MCP_Ability_Bridge' ) ) {
            $preview_tools = array( 'build-preview', 'page-shell-compile', 'figma-to-ir', 'figma-prepare', 'figma-compile' );
            $capability = in_array( $name, $preview_tools, true ) ? Design_Core_Elementor_Capabilities::PREVIEW : Design_Core_Elementor_Capabilities::READ;
            $guard = Design_Core_Elementor_MCP_Ability_Bridge::permission( $capability );
            if ( is_wp_error( $guard ) ) { return $guard; }
        } elseif ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'design_core_tool_forbidden', 'You are not allowed to execute Design Core tools.' ); }
        if ( empty( $definitions[ $name ]['readonly'] ) || ! empty( $definitions[ $name ]['destructive'] ) ) {
            return new WP_Error( 'design_core_legacy_write_disabled', 'Use a dedicated scoped local/Owner API operation for mutation; the legacy ability dispatcher is read/preview only.', array( 'status' => 403 ) );
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
        if ( ! function_exists( 'rest_validate_value_from_schema' ) ) { return new WP_Error( 'design_core_ability_validator_unavailable', 'WordPress schema validation is unavailable.', array( 'status' => 503 ) ); }
        $valid = rest_validate_value_from_schema( $arguments, $definition['input_schema'], 'arguments' );
        return is_wp_error( $valid ) ? $valid : $this->execute( $name, $arguments );
    }

    public static function definitions() {
        $figma_url = array( 'type' => 'string', 'minLength' => 1 );
        return array(
            'build-preview' => array( 'description' => 'Dry-run Design IR/HTML with strategy-aware Elementor execution simulation and no mutation.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'properties' => array( 'html' => array( 'type' => 'string' ), 'css' => array( 'type' => 'string' ), 'design_ir' => array( 'type' => 'object' ), 'adapter_target' => array( 'type' => 'string', 'enum' => array( 'auto', 'elementor-v3', 'elementor-v4' ) ) ) ) ),
            'page-snapshot' => array( 'description' => 'Read one normalized page digest: manifest, Elementor tree, widgets, tokens, visual QA and history.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'required' => array( 'page_id' ), 'properties' => array( 'page_id' => array( 'type' => 'integer' ) ) ) ),
            'widget-candidates' => array( 'description' => 'Rank runtime Elementor widgets against intent/control/capability requirements.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'properties' => array( 'requirements' => array( 'type' => 'object' ), 'limit' => array( 'type' => 'integer' ) ) ) ),
            'section-explain' => array( 'description' => 'Explain a Section Registry match or inspect an existing master and its usage.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'properties' => array( 'fingerprint' => array( 'type' => 'object' ), 'master_id' => array( 'type' => 'string' ) ) ) ),
            'page-shell-compile' => array( 'description' => 'Compile a platform-neutral Page Shell and Section Recipe bindings into Design IR.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'required' => array( 'shell' ), 'properties' => array( 'shell' => array( 'type' => 'string' ), 'bindings' => array( 'type' => 'object' ) ) ) ),
            'figma-to-ir' => array( 'description' => 'Strict Figma Design IR preparation. URL inputs always resolve image/vector assets, export a reference render and consult Design Memory.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'properties' => array( 'figma_url' => $figma_url, 'figma' => array( 'type' => 'object' ), 'node_id' => array( 'type' => 'string' ) ), 'anyOf' => array( array( 'required' => array( 'figma_url' ) ), array( 'required' => array( 'figma' ) ) ) ) ),
            'figma-prepare' => array( 'description' => 'Prepare strict Figma source evidence, assets, reference render, Design IR and matching Design Memory lessons.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'required' => array( 'figma_url' ), 'properties' => array( 'figma_url' => $figma_url ) ) ),
            'figma-compile' => array( 'description' => 'Compile strict Figma source into a governed native Elementor tree without mutating WordPress.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'required' => array( 'figma_url' ), 'properties' => array( 'figma_url' => $figma_url ) ) ),
            'figma-build-draft' => array( 'description' => 'Compile strict Figma source and save only to a draft page; optional verify runs the rendered fidelity gate.', 'readonly' => false, 'destructive' => true, 'input_schema' => array( 'type' => 'object', 'required' => array( 'figma_url', 'confirm' ), 'properties' => array( 'figma_url' => $figma_url, 'page_id' => array( 'type' => 'integer' ), 'title' => array( 'type' => 'string' ), 'verify' => array( 'type' => 'boolean' ), 'target_similarity' => array( 'type' => 'number' ), 'confirm' => array( 'type' => 'boolean', 'const' => true ) ) ) ),
            'figma-verify' => array( 'description' => 'Compare the Figma reference against the exact rendered Elementor node geometry and screenshot, then record verified learning/incidents.', 'readonly' => false, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'required' => array( 'figma_url', 'candidate_target' ), 'properties' => array( 'figma_url' => $figma_url, 'candidate_target' => array( 'type' => 'string' ), 'page_id' => array( 'type' => 'integer' ), 'target_similarity' => array( 'type' => 'number' ) ) ) ),
            'design-memory' => array( 'description' => 'Read verified Design Memory lessons and benchmark promotion state.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'properties' => array( 'scope' => array( 'type' => 'string', 'enum' => array( '', 'global', 'project', 'source' ) ) ) ) ),
            'design-memory-incidents' => array( 'description' => 'Read bounded Design Memory failure incidents without raw customer source content.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'properties' => array() ) ),
            'design-memory-benchmarks' => array( 'description' => 'Read verified lesson patterns promoted to regression benchmark candidates.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'properties' => array() ) ),
            'visual-feedback' => array( 'description' => 'Compare reference/candidate screenshots plus automatic rendered DOM evidence and generate element-addressable directives.', 'readonly' => false, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'required' => array( 'reference_target', 'candidate_target' ), 'properties' => array( 'reference_target' => array( 'type' => 'string' ), 'candidate_target' => array( 'type' => 'string' ), 'page_id' => array( 'type' => 'integer' ), 'target_similarity' => array( 'type' => 'number' ) ) ) ),
            'visual-correct' => array( 'description' => 'Run governed compare/correct/re-verify and learn only when the final rendered state passes.', 'readonly' => false, 'destructive' => true, 'input_schema' => array( 'type' => 'object', 'required' => array( 'reference_target', 'candidate_target', 'page_id', 'confirm' ), 'properties' => array( 'reference_target' => array( 'type' => 'string' ), 'candidate_target' => array( 'type' => 'string' ), 'page_id' => array( 'type' => 'integer' ), 'max_iterations' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 ), 'target_similarity' => array( 'type' => 'number' ), 'source_kind' => array( 'type' => 'string' ), 'source_fingerprint' => array( 'type' => 'string' ), 'confirm' => array( 'type' => 'boolean', 'const' => true ) ) ) ),
            'quality-benchmarks' => array( 'description' => 'List the fidelity benchmark corpus or run mutation-free planning checks for one benchmark.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'string' ) ) ) ),
            'convert-html' => array( 'description' => 'Run the governed HTML/CSS conversion pipeline and create an Elementor page.', 'readonly' => false, 'destructive' => true, 'input_schema' => array( 'type' => 'object', 'required' => array( 'html', 'confirm' ), 'properties' => array( 'html' => array( 'type' => 'string' ), 'css' => array( 'type' => 'string' ), 'title' => array( 'type' => 'string' ), 'confirm' => array( 'type' => 'boolean', 'const' => true ) ) ) ),
            'history-list' => array( 'description' => 'List persistent Design Core change ledger entries.', 'readonly' => true, 'destructive' => false, 'input_schema' => array( 'type' => 'object', 'properties' => array() ) ),
            'history-rollback' => array( 'description' => 'Conflict-aware rollback of a completed Elementor save.', 'readonly' => false, 'destructive' => true, 'input_schema' => array( 'type' => 'object', 'required' => array( 'entry_id', 'confirm' ), 'properties' => array( 'entry_id' => array( 'type' => 'string' ), 'confirm' => array( 'type' => 'boolean', 'const' => true ) ) ) ),
        );
    }
}

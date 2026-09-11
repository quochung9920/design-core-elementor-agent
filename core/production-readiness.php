<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Production_Readiness {
    public function audit() {
        $capabilities = ( new Design_Core_Elementor_Capability_Scanner() )->scan();
        $checks = array();
        $checks['elementor_active'] = $this->check( 'not-installed' !== ( $capabilities['elementor']['version'] ?? 'not-installed' ), 'Elementor is active.' );
        $checks['responsive_breakpoints'] = $this->check( ! empty( $capabilities['elementor']['breakpoints'] ), 'Responsive breakpoints are discoverable.' );
        $checks['design_ir_v4'] = $this->check( 4 === Design_Core_Elementor_Design_IR::SCHEMA_VERSION && class_exists( 'Design_Core_Elementor_Design_IR_Validator' ), 'Canonical validated Design IR schema v4 is active.' );
        $checks['build_plan_v1'] = $this->check( 1 === Design_Core_Elementor_Build_Plan::SCHEMA_VERSION && class_exists( 'Design_Core_Elementor_Build_Plan_Validator' ), 'Validated BuildPlan schema v1 is active.' );
        $checks['build_preview_v2'] = $this->check( class_exists( 'Design_Core_Elementor_Build_Plan_Preview' ) && 2 === Design_Core_Elementor_Build_Plan_Preview::SCHEMA_VERSION && class_exists( 'Design_Core_Elementor_Build_Plan_Simulator' ), 'Mutation-free BuildPlan Preview v2 with execution simulation is active.' );
        $checks['section_registry_v3'] = $this->check( class_exists( 'Design_Core_Elementor_Section_Registry' ) && class_exists( 'Design_Core_Elementor_Section_Intelligence' ) && class_exists( 'Design_Core_Elementor_Section_Fingerprint_Service' ) && 3 === Design_Core_Elementor_Section_Fingerprint_Service::VERSION, 'Section Registry and section fingerprint v3 are active.' );
        $checks['page_manifest_v1'] = $this->check( class_exists( 'Design_Core_Elementor_Page_Manifest' ) && 1 === Design_Core_Elementor_Page_Manifest::SCHEMA_VERSION, 'Page Manifest v1 is active.' );
        $checks['section_recipe_compiler'] = $this->check( class_exists( 'Design_Core_Elementor_Section_Recipe_Compiler' ) && class_exists( 'Design_Core_Elementor_Section_Recipe_Library' ), 'Platform-neutral Section Recipe compiler/library is active.' );
        $checks['page_shell_v1'] = $this->check( class_exists( 'Design_Core_Elementor_Page_Shell' ) && 1 === Design_Core_Elementor_Page_Shell::SCHEMA_VERSION, 'Page Shell v1 is active.' );
        $checks['page_snapshot_v1'] = $this->check( class_exists( 'Design_Core_Elementor_Page_Snapshot' ) && 1 === Design_Core_Elementor_Page_Snapshot::SCHEMA_VERSION, 'Page Snapshot v1 is active.' );
        $checks['widget_intelligence_v2'] = $this->check( class_exists( 'Design_Core_Elementor_Widget_Intelligence' ) && class_exists( 'Design_Core_Elementor_Control_Schema_Registry' ) && 2 === Design_Core_Elementor_Control_Schema_Registry::SCHEMA_VERSION, 'Runtime-first Widget Intelligence and Control Schema v2 are active.' );
        $checks['layout_intelligence_v3'] = $this->check( class_exists( 'Design_Core_Elementor_Layout_Intelligence' ) && 3 === Design_Core_Elementor_Layout_Intelligence::VERSION, 'Responsive Layout Intelligence v3 is active.' );
        $checks['persistence_service'] = $this->check( class_exists( 'Design_Core_Elementor_Persistence_Service' ), 'Governed Elementor persistence service is active.' );
        $checks['persistent_history_v1'] = $this->check( class_exists( 'Design_Core_Elementor_Change_Ledger' ) && 1 === Design_Core_Elementor_Change_Ledger::SCHEMA_VERSION, 'Conflict-aware persistent history v1 is active.' );

        $checks['figma_intelligence_v4'] = $this->check(
            class_exists( 'Design_Core_Elementor_Figma_Design_IR_Adapter' ) && 4 === Design_Core_Elementor_Figma_Design_IR_Adapter::VERSION
            && class_exists( 'Design_Core_Elementor_Figma_Transport' ) && 2 === Design_Core_Elementor_Figma_Transport::VERSION
            && class_exists( 'Design_Core_Elementor_Figma_Normalization_Service' ),
            'Figma transport v2, normalization and Design IR adapter v4 are active.'
        );
        $checks['figma_strict_fidelity_v2'] = $this->check(
            class_exists( 'Design_Core_Elementor_Figma_Fidelity_Service' ) && 2 === Design_Core_Elementor_Figma_Fidelity_Service::VERSION
            && class_exists( 'Design_Core_Elementor_Figma_Geometry_Verifier' ) && 1 === Design_Core_Elementor_Figma_Geometry_Verifier::VERSION,
            'Strict Figma fidelity v2 resolves source assets/reference evidence and verifies exact node geometry.'
        );
        $checks['design_memory_v1'] = $this->check(
            class_exists( 'Design_Core_Elementor_Design_Memory_Store' ) && 1 === Design_Core_Elementor_Design_Memory_Store::SCHEMA_VERSION
            && class_exists( 'Design_Core_Elementor_Fidelity_Rule_Registry' ) && 1 === Design_Core_Elementor_Fidelity_Rule_Registry::VERSION
            && class_exists( 'Design_Core_Elementor_Failure_Signature_Engine' )
            && class_exists( 'Design_Core_Elementor_Design_Memory_Retriever' )
            && class_exists( 'Design_Core_Elementor_Correction_Learning_Engine' )
            && class_exists( 'Design_Core_Elementor_Benchmark_Promoter' ),
            'Design Memory v1 with governed rules, failure signatures, verified learning and benchmark promotion is active.'
        );
        $checks['agent_gateway_v3'] = $this->check( class_exists( 'Design_Core_Elementor_Agent_Gateway' ) && 3 === Design_Core_Elementor_Agent_Gateway::VERSION, 'Agent Gateway v3 exposes strict Figma and Design Memory operations.' );

        $checks['token_pipeline_v1'] = $this->check( class_exists( 'Design_Core_Elementor_Design_Token_Pipeline' ) && 1 === Design_Core_Elementor_Design_Token_Pipeline::VERSION, 'Hierarchical token reference/transform pipeline v1 is active.' );
        $checks['css_ast_v1'] = $this->check( class_exists( 'Design_Core_Elementor_CSS_AST_Service' ) && 1 === Design_Core_Elementor_CSS_AST_Service::VERSION, 'Normalized CSS AST/rule service v1 is active.' );
        $checks['elementor_setting_governor_v1'] = $this->check( class_exists( 'Design_Core_Elementor_Elementor_Setting_Governor' ) && 1 === Design_Core_Elementor_Elementor_Setting_Governor::VERSION, 'Runtime-aware Elementor setting governor v1 is active.' );
        $checks['section_explainability'] = $this->check( class_exists( 'Design_Core_Elementor_Section_Explainability' ), 'Section Registry explainability is active.' );
        $checks['strategy_contract'] = $this->check( interface_exists( 'Design_Core_Elementor_Strategy_Executor_Interface' ), 'Strategy executor contract is active.' );
        $checks['global_sync'] = $this->check( ! empty( $capabilities['capabilities']['kit_global_sync'] ) || ! empty( $capabilities['capabilities']['atomic_manage_classes'] ), 'Elementor global design-system bridge is available.' );
        $checks['atomic_runtime'] = $this->check( 'v3' === ( $capabilities['elementor']['editor_mode'] ?? 'v3' ) || ! empty( $capabilities['capabilities']['atomic_build_composition'] ), 'Atomic composition API is available when V4 is active.' );
        $checks['security_policy'] = $this->check( class_exists( 'Design_Core_Elementor_Security_Policy' ), 'Security policy is loaded.' );
        $checks['transaction_rollback'] = $this->check( class_exists( 'Design_Core_Elementor_Conversion_Transaction' ), 'Conversion rollback is loaded.' );
        $checks['observability'] = $this->check( class_exists( 'Design_Core_Elementor_Observability' ), 'Conversion diagnostics are loaded.' );
        $checks['runtime_evidence_store'] = $this->check( class_exists( 'Design_Core_Elementor_Runtime_Evidence' ), 'Runtime evidence store is loaded.' );
        $checks['browser_analysis'] = $this->check( ! empty( $capabilities['capabilities']['browser_analysis'] ), 'Browser computed-style analysis is available.', false );
        $checks['figma_transport_configured'] = $this->check( class_exists( 'Design_Core_Elementor_Figma_Transport' ) && ( new Design_Core_Elementor_Figma_Transport() )->configured(), 'Figma access token is configured for direct URL reads.', false );
        $checks['loop_runtime'] = $this->check( ! empty( $capabilities['capabilities']['loop'] ), 'Loop runtime is available.', false );
        $checks['sabberworm_css_parser'] = $this->check( class_exists( '\\Sabberworm\\CSS\\Parser' ), 'sabberworm/php-css-parser is loaded; CSS AST normalization uses the external standards-aware parser.', false );

        $required_failed = 0;
        foreach ( $checks as $check ) { if ( $check['required'] && ! $check['pass'] ) { $required_failed++; } }

        $evidence_store = class_exists( 'Design_Core_Elementor_Runtime_Evidence' ) ? new Design_Core_Elementor_Runtime_Evidence() : null;
        $evidence = array();
        $required_evidence = array(
            'elementor-save-reload-render' => 'Elementor create/save/reload/render round-trip passed.',
            'page-a-page-b-reuse' => 'Page A -> Registry -> Page B reuse passed.',
            'global-design-system' => 'Global design system sync/reference/no-duplicate-on-resync passed.',
            'responsive-visual-qa' => 'Responsive visual QA passed at the configured viewport matrix.',
            'security-ssrf-source' => 'SSRF and source-size security gates passed.',
            'registry-migration-rollback' => 'Registry migration and rollback passed.',
            'custom-widget-multi-instance' => 'Custom widget registration and multi-instance render passed.',
            'native-widget-semantic-execution' => 'A native-widget decision produced an actually registered native Elementor widget, and save/reload/render passed.',
        );
        if ( 'v3' === ( $capabilities['elementor']['editor_mode'] ?? 'v3' ) && ! empty( $capabilities['capabilities']['browser_analysis'] ) ) {
            $required_evidence['visual-correction-roundtrip'] = 'Visual Feedback produced an addressable correction, a live V3 control was applied, and save/reload/render verification passed.';
        }
        if ( class_exists( 'Design_Core_Elementor_Figma_Transport' ) && ( new Design_Core_Elementor_Figma_Transport() )->configured() && ! empty( $capabilities['capabilities']['browser_analysis'] ) ) {
            $required_evidence['figma-strict-rendered-roundtrip'] = 'Strict Figma prepare/compile/draft/render verified vectors, exact node geometry and screenshot fidelity against a real Figma reference.';
            $required_evidence['design-memory-verified-learning'] = 'A failed rendered design produced an incident and a later verified PASS reinforced a governed project/source lesson.';
        }
        if ( in_array( $capabilities['elementor']['editor_mode'] ?? 'v3', array( 'v4', 'mixed' ), true ) ) {
            $required_evidence['atomic-design-core-roundtrip'] = 'Design Core V4 strategy executor -> V4 adapter save/reload/render round-trip passed.';
        }
        if ( 'not-installed' !== ( $capabilities['elementor']['pro_version'] ?? 'not-installed' ) && ! empty( $capabilities['elementor']['loop_available'] ) ) {
            $required_evidence['elementor-pro-loop'] = 'Elementor Pro Loop runtime test passed.';
        }

        $evidence_failed = 0;
        foreach ( $required_evidence as $key => $message ) {
            $entry = $evidence_store ? $evidence_store->get( $key ) : null;
            $pass = $evidence_store ? $evidence_store->passed( $key ) : false;
            if ( ! $pass ) { $evidence_failed++; }
            $evidence[ $key ] = array( 'pass' => $pass, 'message' => $message, 'record' => $entry, 'fresh' => $entry && $evidence_store ? $evidence_store->is_fresh( $entry ) : false );
        }
        if ( $required_failed > 0 ) { $status = 'not-ready'; }
        elseif ( 0 === $evidence_failed ) { $status = 'production-ready'; }
        else { $status = 'production-candidate'; }

        return array(
            'status' => $status,
            'required_failures' => $required_failed,
            'evidence_failures' => $evidence_failed,
            'checks' => $checks,
            'evidence' => $evidence,
            'capabilities' => $capabilities,
            'note' => 'Production-ready requires fresh runtime evidence for all applicable gates; architecture checks, seeded memory and unit contracts alone are never treated as proof.',
        );
    }

    private function check( $pass, $message, $required = true ) {
        return array( 'pass' => (bool) $pass, 'required' => (bool) $required, 'message' => $message );
    }
}

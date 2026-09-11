<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Design_Core_Agent_Semantic_Protocol {
    const VERSION = 2;
    private $semantic;
    private $base;

    public function __construct() {
        $registry = new Design_Core_Elementor_Control_Schema_Registry();
        $intel = new Design_Core_Elementor_Semantic_Widget_Intelligence_V2( $registry );
        $planner = new Design_Core_Elementor_Component_Planner( $intel, new Design_Core_Elementor_Widget_Fit_Engine( $registry ) );
        $this->semantic = new Design_Core_Agent_Semantic_Verifier_V2( $planner );
        $this->base = new Design_Core_Agent_Protocol();
    }

    public static function catalog() {
        $string = array( 'type' => 'string', 'maxLength' => 4096 );
        $id = array( 'type' => 'integer', 'minimum' => 1 );
        $list = array( 'type' => 'array', 'maxItems' => 50, 'items' => array( 'type' => 'string', 'maxLength' => 128 ) );
        $component = array(
            'type' => 'object',
            'properties' => array(
                'source_ref' => $string, 'purpose' => $string, 'behavior' => $string, 'rationale' => $string,
                'element_ids' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 4096, 'items' => array( 'type' => 'string', 'maxLength' => 256 ) ),
                'component_type' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ),
                'implementation_type' => array( 'type' => 'string', 'enum' => array( 'direct_widget', 'native_composition', 'custom_native_widget' ) ),
                'selected_widget' => array( 'type' => 'string', 'maxLength' => 128 ),
                'behaviors' => $list, 'required_capabilities' => $list, 'required_controls' => $list,
                'required_control_groups' => array( 'type' => 'array', 'maxItems' => 30, 'items' => $list ),
                'preferred_widgets' => $list, 'forbidden_widgets' => $list, 'forbidden_intents' => $list, 'keywords' => $list,
                'decision_hash' => array( 'type' => 'string', 'maxLength' => 128 ),
                'repeated' => array( 'type' => 'boolean' ),
                'minimum_direct_score' => array( 'type' => 'number', 'minimum' => 0, 'maximum' => 1 ),
            ),
            'required' => array( 'source_ref', 'purpose', 'behavior', 'rationale', 'element_ids', 'component_type', 'implementation_type' ),
            'additionalProperties' => false,
        );
        $plan = array(
            'component_type' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ),
            'required_capabilities' => $list, 'required_controls' => $list,
            'required_control_groups' => array( 'type' => 'array', 'maxItems' => 30, 'items' => $list ),
            'preferred_widgets' => $list, 'forbidden_widgets' => $list, 'forbidden_intents' => $list,
            'keywords' => $list, 'behaviors' => $list, 'repeated' => array( 'type' => 'boolean' ),
            'minimum_direct_score' => array( 'type' => 'number', 'minimum' => 0, 'maximum' => 1 ),
            'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20 ),
        );
        $preview = array(
            'source_id' => $string, 'page_id' => $id, 'page_revision' => $string,
            'elements' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 4096, 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
            'components' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 4096, 'items' => $component ),
        );
        return array(
            'component-ontology' => self::op( 'design_core_read', 'Read versioned component contracts.', self::obj( array( 'component_type' => array( 'type' => 'string', 'maxLength' => 80 ) ) ) ),
            'component-plan' => self::op( 'design_core_read', 'Rank live widgets with hard rejection evidence before scoring.', self::obj( $plan, array( 'component_type' ) ) ),
            'preview' => self::op( 'design_core_preview', 'Compile exact native tree and attach semantic evidence.', self::obj( $preview, array( 'source_id', 'page_id', 'page_revision', 'elements', 'components' ) ) ),
            'audit' => self::op( 'design_core_read', 'Audit semantic smells; does not replace browser QA.', self::obj( array( 'page_id' => $id ), array( 'page_id' ) ) ),
            'apply-draft' => self::op( 'design_core_modify', 'Apply only an exact artifact with semantic PASS.', self::obj( array(
                'preview_id' => $string, 'artifact_hash' => $string,
                'confirm' => array( 'type' => 'boolean', 'enum' => array( true ) ),
                'idempotency_key' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 128 ),
            ), array( 'preview_id', 'artifact_hash', 'confirm', 'idempotency_key' ) ) ),
            'promote-draft' => self::op( 'design_core_modify', 'Promote only with semantic PASS plus the existing visual/interaction QA gate.', self::obj( array(
                'preview_id' => $string, 'artifact_hash' => $string,
                'draft_idempotency_key' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 128 ),
                'target_page_id' => $id, 'target_page_revision' => $string,
                'confirm' => array( 'type' => 'boolean', 'enum' => array( true ) ),
                'idempotency_key' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 128 ),
            ), array( 'preview_id', 'artifact_hash', 'draft_idempotency_key', 'target_page_id', 'target_page_revision', 'confirm', 'idempotency_key' ) ) ),
        );
    }

    public function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) { return; }
        foreach ( self::catalog() as $slug => $op ) {
            wp_register_ability( 'design-core/agent-semantic-' . $slug, array(
                'label' => 'Design Core Semantic V2: ' . $slug,
                'description' => $op['description'],
                'category' => 'design-core',
                'input_schema' => $op['schema'],
                'output_schema' => array( 'type' => 'object', 'properties' => array( 'status' => array( 'type' => 'string' ) ), 'required' => array( 'status' ), 'additionalProperties' => true ),
                'permission_callback' => static fn() => Design_Core_Elementor_MCP_Ability_Bridge::permission( $op['scope'] ),
                'execute_callback' => function ( $input = array() ) use ( $slug ) {
                    $payload = is_array( $input ) ? $input : array();
                    return Design_Core_Elementor_Design_Run_Trace::around( 'mcp', 'agent-semantic-' . $slug, $payload, function () use ( $slug, $payload ) {
                        return $this->execute( $slug, $payload );
                    } );
                },
                'meta' => array(
                    'show_in_rest' => false,
                    'mcp' => array( 'public' => true, 'type' => 'tool' ),
                    'annotations' => array(
                        'readonly' => ! in_array( $slug, array( 'apply-draft', 'promote-draft' ), true ),
                        'destructive' => in_array( $slug, array( 'apply-draft', 'promote-draft' ), true ),
                        'openWorldHint' => false,
                    ),
                ),
            ) );
        }
    }

    public function execute( $slug, array $input ) {
        $ops = self::catalog();
        if ( ! isset( $ops[ $slug ] ) ) { return Design_Core_Agent_Contract::error( 'semantic_operation', 'Unknown Semantic V2 operation.', 404 ); }
        $permission = Design_Core_Elementor_MCP_Ability_Bridge::permission( $ops[ $slug ]['scope'] );
        if ( is_wp_error( $permission ) ) { return $permission; }
        if ( true !== $permission ) { return Design_Core_Agent_Contract::error( 'permission', 'Permission denied.', 403 ); }
        if ( function_exists( 'rest_validate_value_from_schema' ) ) {
            $valid = rest_validate_value_from_schema( $input, $ops[ $slug ]['schema'], 'input' );
            if ( is_wp_error( $valid ) ) { return $valid; }
        }
        if ( 'component-ontology' === $slug ) { return $this->semantic->component_ontology( $input ); }
        if ( 'component-plan' === $slug ) { return $this->semantic->component_plan( $input ); }
        if ( 'audit' === $slug ) { return array_merge( array( 'status' => 'ok' ), $this->semantic->audit_page( (int) $input['page_id'], new Design_Core_Agent_Knowledge() ) ); }
        if ( 'preview' === $slug ) { return $this->preview( $input ); }
        if ( 'apply-draft' === $slug ) { return $this->write( 'apply-draft', $input ); }
        if ( 'promote-draft' === $slug ) { return $this->write( 'promote-draft', $input ); }
        return Design_Core_Agent_Contract::error( 'semantic_operation', 'Unknown Semantic V2 operation.', 404 );
    }

    private function preview( array $input ) {
        $base_components = array();
        foreach ( $input['components'] as $component ) {
            $base_components[] = array(
                'source_ref' => $component['source_ref'], 'purpose' => $component['purpose'],
                'behavior' => $component['behavior'], 'rationale' => $component['rationale'],
                'element_ids' => $component['element_ids'],
            );
        }
        $base = $this->base->execute( 'preview-plan', array(
            'source_id' => $input['source_id'], 'page_id' => $input['page_id'], 'page_revision' => $input['page_revision'],
            'elements' => $input['elements'], 'components' => $base_components,
        ) );
        if ( is_wp_error( $base ) ) { return $base; }
        $report = $this->semantic->verify_preview( array( 'elements' => $input['elements'], 'components' => $input['components'] ) );
        if ( 'pass' !== ( $report['semantic_qa'] ?? '' ) ) {
            return array(
                'status' => 'invalid', 'can_apply' => false, 'semantic_qa' => $report['semantic_qa'] ?? 'fail',
                'issues' => $report['issues'] ?? array(), 'base_preview_id' => $base['preview_id'] ?? '',
                'artifact_hash' => $base['artifact_hash'] ?? '',
            );
        }
        $this->semantic->bind_preview( (string) $base['preview_id'], (string) $base['artifact_hash'], $report );
        $base['semantic_qa'] = 'pass';
        $base['semantic_evidence_hash'] = $report['evidence_hash'];
        $base['semantic_component_count'] = $report['component_count'];
        return $base;
    }

    private function write( $slug, array $input ) {
        $evidence = $this->semantic->preview_evidence( (string) $input['preview_id'], (string) $input['artifact_hash'] );
        if ( ! is_array( $evidence ) || 'pass' !== ( $evidence['semantic_qa'] ?? '' ) ) {
            return Design_Core_Agent_Contract::error( 'semantic_qa', 'Passing semantic evidence for this exact preview/artifact is required.', 409 );
        }
        $result = $this->base->execute( $slug, $input );
        if ( is_wp_error( $result ) ) { return $result; }
        $result['semantic_qa'] = 'pass';
        $result['semantic_evidence_hash'] = $evidence['evidence_hash'] ?? '';
        return $result;
    }

    private static function op( $scope, $description, $schema ) { return array( 'scope' => $scope, 'description' => $description, 'schema' => $schema ); }
    private static function obj( array $properties, array $required = array() ) { return array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false ); }
}

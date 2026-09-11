<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** MCP abilities for persistent Design Run provenance. */
final class Design_Core_Agent_Run_Protocol {
    const VERSION = 1;

    public static function catalog() {
        $string = array( 'type' => 'string', 'maxLength' => 4096 );
        $run_id = array( 'type' => 'string', 'minLength' => 12, 'maxLength' => 96 );
        $hash = array( 'type' => 'string', 'minLength' => 16, 'maxLength' => 128 );
        $id = array( 'type' => 'integer', 'minimum' => 1 );
        $status = array( 'type' => 'string', 'enum' => Design_Core_Elementor_Design_Run_Trace::STATUSES );
        $phase = array( 'type' => 'string', 'enum' => Design_Core_Elementor_Design_Run_Trace::PHASES );
        $strings = array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => 'string', 'maxLength' => 1024 ) );
        $object = array( 'type' => 'object', 'additionalProperties' => true );

        return array(
            'start' => self::op( 'design_core_preview', false, 'Start a persistent Design Run and make it active for the current authenticated principal. This records public provenance, not hidden chain-of-thought.', self::schema( array(
                'task' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 1024 ),
                'page_id' => $id,
                'page_title' => array( 'type' => 'string', 'maxLength' => 512 ),
                'source_id' => array( 'type' => 'string', 'maxLength' => 256 ),
                'source_name' => array( 'type' => 'string', 'maxLength' => 512 ),
                'source_hash' => $hash,
                'client' => array( 'type' => 'string', 'enum' => array( 'chatgpt-web', 'developer-ai', 'wordpress-admin', 'api-client', 'other' ) ),
            ), array( 'task' ) ) ),
            'activate' => self::op( 'design_core_preview', false, 'Resume tracing into an existing accessible Design Run.', self::schema( array( 'run_id' => $run_id ), array( 'run_id' ) ) ),
            'event' => self::op( 'design_core_preview', false, 'Append a concise public decision/action/evidence event. Do not send hidden chain-of-thought or credentials.', self::schema( array(
                'run_id' => $run_id,
                'event_type' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ),
                'phase' => $phase,
                'status' => $status,
                'component_id' => array( 'type' => 'string', 'maxLength' => 128 ),
                'component_type' => array( 'type' => 'string', 'maxLength' => 128 ),
                'summary' => array( 'type' => 'string', 'maxLength' => 2048 ),
                'rationale' => array( 'type' => 'string', 'maxLength' => 4096 ),
                'operation' => array( 'type' => 'string', 'maxLength' => 256 ),
                'artifact_hash' => $hash,
                'decision' => $object,
                'metrics' => $object,
                'evidence_refs' => $strings,
                'warnings' => $strings,
                'errors' => $strings,
                'metadata' => $object,
            ), array( 'event_type', 'phase', 'status', 'summary' ) ) ),
            'get' => self::op( 'design_core_read', true, 'Read one Design Run with derived phase, QA and promotion-readiness status.', self::schema( array( 'run_id' => $run_id ), array( 'run_id' ) ) ),
            'list' => self::op( 'design_core_read', true, 'List accessible Design Runs.', self::schema( array(
                'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
                'page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100000 ),
            ) ) ),
            'events' => self::op( 'design_core_read', true, 'Read paginated Design Run events with optional phase/component filters.', self::schema( array(
                'run_id' => $run_id,
                'phase' => $phase,
                'component_id' => array( 'type' => 'string', 'maxLength' => 128 ),
                'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 250 ),
                'offset' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 1000000 ),
            ), array( 'run_id' ) ) ),
            'components' => self::op( 'design_core_read', true, 'Summarize component-level provenance, including the latest selected widget/implementation where evidence exists.', self::schema( array( 'run_id' => $run_id ), array( 'run_id' ) ) ),
            'artifacts' => self::op( 'design_core_read', true, 'List artifact hashes observed in a Design Run with QA evidence bound to each artifact.', self::schema( array( 'run_id' => $run_id ), array( 'run_id' ) ) ),
            'compare' => self::op( 'design_core_read', true, 'Compare two Design Runs by QA readiness and component/widget changes.', self::schema( array( 'run_id_a' => $run_id, 'run_id_b' => $run_id ), array( 'run_id_a', 'run_id_b' ) ) ),
            'finish' => self::op( 'design_core_preview', false, 'Finish a Design Run without modifying page content.', self::schema( array(
                'run_id' => $run_id,
                'status' => array( 'type' => 'string', 'enum' => array( 'completed', 'failed', 'blocked', 'cancelled' ) ),
                'summary' => array( 'type' => 'string', 'maxLength' => 2048 ),
                'artifact_hash' => $hash,
            ), array( 'run_id', 'status' ) ) ),
        );
    }

    public function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) { return; }
        foreach ( self::catalog() as $slug => $op ) {
            wp_register_ability( 'design-core/agent-run-' . $slug, array(
                'label' => 'Design Core Design Runs: ' . $slug,
                'description' => $op['description'],
                'category' => 'design-core',
                'input_schema' => $op['schema'],
                'output_schema' => array( 'type' => 'object', 'properties' => array( 'status' => array( 'type' => 'string' ) ), 'required' => array( 'status' ), 'additionalProperties' => true ),
                'permission_callback' => static fn() => Design_Core_Elementor_MCP_Ability_Bridge::permission( $op['scope'] ),
                'execute_callback' => function ( $input = array() ) use ( $slug ) { return $this->execute( $slug, is_array( $input ) ? $input : array() ); },
                'meta' => array(
                    'show_in_rest' => false,
                    'mcp' => array( 'public' => true, 'type' => 'tool' ),
                    'annotations' => array(
                        'readonly' => $op['read_only'], 'destructive' => false, 'idempotent' => false,
                        'readOnlyHint' => $op['read_only'], 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false,
                    ),
                ),
            ) );
        }
    }

    public function execute( $slug, array $input ) {
        $ops = self::catalog();
        if ( ! isset( $ops[ $slug ] ) ) { return Design_Core_Agent_Contract::error( 'run_operation', 'Unknown Design Run operation.', 404 ); }
        $permission = Design_Core_Elementor_MCP_Ability_Bridge::permission( $ops[ $slug ]['scope'] );
        if ( is_wp_error( $permission ) ) { return $permission; }
        if ( true !== $permission ) { return Design_Core_Agent_Contract::error( 'permission', 'Permission denied.', 403 ); }
        if ( function_exists( 'rest_validate_value_from_schema' ) ) {
            $valid = rest_validate_value_from_schema( $input, $ops[ $slug ]['schema'], 'input' );
            if ( is_wp_error( $valid ) ) { return $valid; }
        }

        if ( 'start' === $slug ) { return Design_Core_Elementor_Design_Run_Trace::start( self::sanitize_event_input( $input ) ); }
        if ( 'activate' === $slug ) { return Design_Core_Elementor_Design_Run_Trace::activate( (string) $input['run_id'] ); }
        if ( 'event' === $slug ) {
            $run_id = (string) ( $input['run_id'] ?? Design_Core_Elementor_Design_Run_Trace::active_run_id() );
            if ( ! $run_id ) { return Design_Core_Agent_Contract::error( 'run_inactive', 'No active Design Run; pass run_id or start a run first.', 409 ); }
            unset( $input['run_id'] );
            $input = self::sanitize_event_input( $input );
            $input['actor'] = $input['actor'] ?? 'chatgpt-web';
            $input['channel'] = 'mcp';
            return Design_Core_Elementor_Design_Run_Trace::append_event( $run_id, $input );
        }
        if ( 'get' === $slug ) { return Design_Core_Elementor_Design_Run_Trace::get( (string) $input['run_id'] ); }
        if ( 'list' === $slug ) { return Design_Core_Elementor_Design_Run_Trace::list_runs( $input ); }
        if ( 'events' === $slug ) { $run_id = (string) $input['run_id']; unset( $input['run_id'] ); return Design_Core_Elementor_Design_Run_Trace::events( $run_id, $input ); }
        if ( 'components' === $slug ) { return Design_Core_Elementor_Design_Run_Trace::components( (string) $input['run_id'] ); }
        if ( 'artifacts' === $slug ) { return Design_Core_Elementor_Design_Run_Trace::artifacts( (string) $input['run_id'] ); }
        if ( 'compare' === $slug ) { return Design_Core_Elementor_Design_Run_Trace::compare( (string) $input['run_id_a'], (string) $input['run_id_b'] ); }
        if ( 'finish' === $slug ) {
            $run_id = (string) $input['run_id'];
            unset( $input['run_id'] );
            return Design_Core_Elementor_Design_Run_Trace::finish( $run_id, self::sanitize_event_input( $input ) );
        }
        return Design_Core_Agent_Contract::error( 'run_operation', 'Unknown Design Run operation.', 404 );
    }

    private static function sanitize_event_input( array $input ) {
        $out = array();
        foreach ( $input as $key => $value ) {
            $key_string = (string) $key;
            if ( preg_match( '/(?:authorization|bearer|token|api[_-]?key|password|passwd|secret|cookie|private[_-]?key|ssh[_-]?key|credential)/i', $key_string ) ) {
                $out[ $key ] = '[redacted]';
                continue;
            }
            if ( is_array( $value ) ) { $out[ $key ] = self::sanitize_event_input( $value ); continue; }
            if ( is_string( $value ) ) {
                $value = preg_replace( '/\bBearer\s+[A-Za-z0-9._~+\/=\-]+/i', 'Bearer [redacted]', $value );
                $value = preg_replace( '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?-----END [A-Z0-9 ]*PRIVATE KEY-----/is', '[redacted-private-key]', $value );
                $value = preg_replace( '/\b(authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|password|passwd|secret|cookie)\b\s*[:=]\s*([^\s,;]+)/i', '$1=[redacted]', $value );
            }
            $out[ $key ] = $value;
        }
        return $out;
    }

    private static function op( $scope, $read_only, $description, $schema ) { return array( 'scope' => $scope, 'read_only' => $read_only, 'description' => $description, 'schema' => $schema ); }
    private static function schema( array $properties, array $required = array() ) { return array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false ); }
}

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Machine-readable contract for the stable Design Core external API.
 *
 * This deliberately exposes capabilities, not arbitrary PHP methods. Internal services
 * remain implementation details; MCP, web clients and other agents consume this contract.
 */
final class Design_Core_Elementor_API_Platform {
    const VERSION = '1.0';
    const REST_NAMESPACE = 'design-core-elementor/v2';

    /**
     * The 22 operations currently exposed by the MCP bridge, each backed by REST v2.
     * Keeping this table declarative makes the API surface auditable and gives future
     * clients a single source of truth for permissions and mutation semantics.
     */
    public static function operations() {
        return array(
            self::op( 'site_status', 'GET', '/site/status', 'design_core_read', true, 'design_core_site_status', 'Read Design Core, WordPress and Elementor runtime status.' ),
            self::op( 'page_snapshot', 'GET', '/pages/{id}/snapshot', 'design_core_read', true, 'design_core_page_snapshot', 'Read a bounded Design Core/Elementor page snapshot.' ),
            self::op( 'preview_build', 'POST', '/build/preview', 'design_core_preview', true, 'design_core_preview_build', 'Compile source or Design IR into a mutation-free BuildPlan preview.' ),
            self::op( 'preview_figma', 'POST', '/figma/preview', 'design_core_preview', true, 'design_core_preview_figma', 'Read Figma input and produce a mutation-free Design Core preview.' ),
            self::op( 'recommend_design_system', 'POST', '/design-intelligence/recommend', 'design_core_preview', true, 'design_core_recommend_design_system', 'Recommend a governed design profile for a brief.' ),
            self::op( 'preview_design_system', 'POST', '/design-intelligence/preview', 'design_core_preview', true, 'design_core_preview_design_system', 'Compile a Design Intelligence recommendation into a mutation-free preview.' ),
            self::op( 'update_page', 'POST', '/pages/{id}/update', 'design_core_modify', false, 'design_core_update_page', 'Execute an approved preview ticket against one page.', true, true ),
            self::op( 'visual_feedback', 'POST', '/pages/{id}/visual-feedback', 'design_core_preview', true, 'design_core_visual_feedback', 'Compare a page candidate with a reference target without mutating Elementor.' ),
            self::op( 'auto_correct', 'POST', '/pages/{id}/auto-correct', 'design_core_modify', false, 'design_core_auto_correct', 'Apply approved, bounded visual corrections through Design Core.', true, true ),
            self::op( 'history', 'GET', '/history', 'design_core_read', true, 'design_core_history', 'Read the Design Core change ledger.' ),
            self::op( 'rollback', 'POST', '/history/{entry}/rollback', 'design_core_rollback', false, 'design_core_rollback', 'Rollback a rollback-eligible Design Core history entry.', true, true ),
            self::op( 'publish_page', 'POST', '/pages/{id}/publish', 'design_core_publish', false, 'design_core_publish_page', 'Publish one explicitly approved page.', true, true ),
            self::op( 'site_map', 'GET', '/site/map', 'design_core_read', true, 'design_core_site_map', 'Read the bounded WordPress/Elementor site structure.' ),
            self::op( 'site_design_system', 'GET', '/site/design-system', 'design_core_read', true, 'design_core_site_design_system', 'Read live Design Core tokens, Elementor Kit settings and breakpoints.' ),
            self::op( 'search_content', 'GET', '/site/search', 'design_core_read', true, 'design_core_search_content', 'Search public WordPress content and Design Core registries.' ),
            self::op( 'elementor_catalog', 'GET', '/elementor/catalog', 'design_core_read', true, 'design_core_elementor_catalog', 'Read the live Elementor widget inventory.' ),
            self::op( 'widget_search', 'POST', '/elementor/widgets/search', 'design_core_read', true, 'design_core_widget_search', 'Rank live Elementor widgets against structured requirements.' ),
            self::op( 'widget_schema', 'GET', '/elementor/widgets/{widget}', 'design_core_read', true, 'design_core_widget_schema', 'Read the live unified control schema for one Elementor widget.' ),
            self::op( 'elementor_capabilities', 'GET', '/elementor/capabilities', 'design_core_read', true, 'design_core_elementor_capabilities', 'Read current Elementor/Pro runtime capabilities.' ),
            self::op( 'media_library', 'GET', '/media', 'design_core_read', true, 'design_core_media_library', 'Search bounded WordPress media metadata.' ),
            self::op( 'plan_task', 'POST', '/tasks/plan', 'design_core_read', true, 'design_core_plan_task', 'Produce a mutation-free task plan grounded in the live site.' ),
            self::op( 'create_page', 'POST', '/pages/create', 'design_core_build', false, 'design_core_create_page', 'Create one Design Core-marked draft page without writing Elementor storage.', true, true ),
        );
    }

    /** Additional API-native orchestration/read helpers. */
    public static function orchestration_operations() {
        return array(
            array(
                'id' => 'api_manifest',
                'method' => 'GET',
                'path' => '/api',
                'capability' => 'design_core_read',
                'read_only' => true,
                'mutation' => false,
                'approval_required' => false,
                'idempotent' => true,
                'summary' => 'Read the machine-readable Design Core API contract.',
            ),
            array(
                'id' => 'openapi',
                'method' => 'GET',
                'path' => '/api/openapi',
                'capability' => 'design_core_read',
                'read_only' => true,
                'mutation' => false,
                'approval_required' => false,
                'idempotent' => true,
                'summary' => 'Read an OpenAPI 3.1 discovery document for the stable external surface.',
            ),
            array(
                'id' => 'understand_task',
                'method' => 'POST',
                'path' => '/api/understand',
                'capability' => 'design_core_read',
                'read_only' => true,
                'mutation' => false,
                'approval_required' => false,
                'idempotent' => true,
                'summary' => 'Get site status, site map, design system, Elementor capabilities and a task plan in one bounded read-only call.',
            ),
            array(
                'id' => 'verify_page',
                'method' => 'POST',
                'path' => '/api/pages/{id}/verify',
                'capability' => 'design_core_read',
                'read_only' => true,
                'mutation' => false,
                'approval_required' => false,
                'idempotent' => true,
                'summary' => 'Read post state, Design Core snapshot, UX audit and page history after a build.',
            ),
        );
    }

    public static function manifest() {
        $operations = self::operations();
        return array(
            'name' => 'Design Core Elementor API',
            'api_version' => self::VERSION,
            'rest_namespace' => self::REST_NAMESPACE,
            'base_path' => '/wp-json/' . self::REST_NAMESPACE,
            'authentication' => array(
                'type' => 'bearer',
                'credential' => 'Design Core machine credential',
                'authorization_header' => 'Authorization: Bearer <token>',
                'scoped' => true,
            ),
            'principles' => array(
                'api_first' => true,
                'mcp_is_transport_adapter' => true,
                'elementor_runtime_is_source_of_truth' => true,
                'no_direct_elementor_storage_mutation' => true,
                'writes_are_preview_and_confirmation_gated' => true,
                'writes_support_idempotency_where_applicable' => true,
                'existing_page_conflicts_fail_closed' => true,
            ),
            'mcp_operation_count' => count( $operations ),
            'operations' => $operations,
            'orchestration_operations' => self::orchestration_operations(),
        );
    }

    public static function openapi() {
        $paths = array();
        foreach ( array_merge( self::operations(), self::orchestration_operations() ) as $operation ) {
            $method = strtolower( $operation['method'] );
            $path = $operation['path'];
            $entry = array(
                'operationId' => 'design_core_' . $operation['id'],
                'summary' => $operation['summary'],
                'security' => array( array( 'bearerAuth' => array() ) ),
                'responses' => array(
                    '200' => array( 'description' => 'Successful Design Core response.' ),
                    '400' => array( 'description' => 'Invalid request or approval contract.' ),
                    '401' => array( 'description' => 'Missing or invalid machine credential.' ),
                    '403' => array( 'description' => 'Credential lacks the required Design Core scope.' ),
                    '409' => array( 'description' => 'Safety conflict, unavailable capability, or stale preview.' ),
                ),
                'x-design-core-capability' => $operation['capability'],
                'x-design-core-read-only' => (bool) $operation['read_only'],
                'x-design-core-approval-required' => (bool) $operation['approval_required'],
                'x-design-core-idempotent' => (bool) $operation['idempotent'],
            );
            $parameters = array();
            if ( false !== strpos( $path, '{id}' ) ) {
                $parameters[] = array( 'name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'integer', 'minimum' => 1 ) );
            }
            if ( false !== strpos( $path, '{entry}' ) ) {
                $parameters[] = array( 'name' => 'entry', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'string', 'minLength' => 1 ) );
            }
            if ( false !== strpos( $path, '{widget}' ) ) {
                $parameters[] = array( 'name' => 'widget', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'string', 'minLength' => 1 ) );
            }
            if ( $parameters ) { $entry['parameters'] = $parameters; }
            if ( 'POST' === $operation['method'] ) {
                $entry['requestBody'] = array(
                    'required' => false,
                    'content' => array(
                        'application/json' => array(
                            'schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                        ),
                    ),
                );
            }
            if ( ! isset( $paths[ $path ] ) ) { $paths[ $path ] = array(); }
            $paths[ $path ][ $method ] = $entry;
        }

        return array(
            'openapi' => '3.1.0',
            'info' => array(
                'title' => 'Design Core Elementor API',
                'version' => self::VERSION,
                'description' => 'Stable capability API for Design Core. MCP is a thin adapter over these operations; Elementor persistence remains inside Design Core.',
            ),
            'servers' => array( array( 'url' => '/wp-json/' . self::REST_NAMESPACE ) ),
            'components' => array(
                'securitySchemes' => array(
                    'bearerAuth' => array( 'type' => 'http', 'scheme' => 'bearer' ),
                ),
            ),
            'paths' => $paths,
        );
    }

    private static function op( $id, $method, $path, $capability, $read_only, $mcp_tool, $summary, $approval_required = false, $idempotent = false ) {
        return array(
            'id' => sanitize_key( (string) $id ),
            'method' => strtoupper( (string) $method ),
            'path' => (string) $path,
            'capability' => sanitize_key( (string) $capability ),
            'read_only' => (bool) $read_only,
            'mutation' => ! (bool) $read_only,
            'approval_required' => (bool) $approval_required,
            'idempotent' => (bool) $idempotent,
            'mcp_tool' => sanitize_key( (string) $mcp_tool ),
            'summary' => (string) $summary,
        );
    }
}

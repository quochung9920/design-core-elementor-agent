<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array( 'core/api-platform.php' ) );

$operations = Design_Core_Elementor_API_Platform::operations();
dc_assert( 22 === count( $operations ), 'API contract contains exactly the current 22 MCP-backed operations' );

$tools = array_values( array_filter( array_map( static function ( $operation ) {
    return $operation['mcp_tool'] ?? '';
}, $operations ) ) );
dc_assert( 22 === count( array_unique( $tools ) ), 'all 22 MCP tools map one-to-one to API operations' );

$expected = array(
    'design_core_site_status', 'design_core_page_snapshot', 'design_core_preview_build', 'design_core_preview_figma',
    'design_core_recommend_design_system', 'design_core_preview_design_system', 'design_core_update_page',
    'design_core_visual_feedback', 'design_core_auto_correct', 'design_core_history', 'design_core_rollback',
    'design_core_publish_page', 'design_core_site_map', 'design_core_site_design_system', 'design_core_search_content',
    'design_core_elementor_catalog', 'design_core_widget_search', 'design_core_widget_schema',
    'design_core_elementor_capabilities', 'design_core_media_library', 'design_core_plan_task', 'design_core_create_page',
);
sort( $expected ); sort( $tools );
dc_assert( $expected === $tools, 'API contract covers the complete documented MCP tool surface' );

foreach ( $operations as $operation ) {
    dc_assert( ! empty( $operation['id'] ) && ! empty( $operation['path'] ) && ! empty( $operation['capability'] ), 'every API operation declares id, path and capability' );
    dc_assert( in_array( $operation['method'], array( 'GET', 'POST' ), true ), 'every API operation uses a supported bounded HTTP method' );
    if ( ! $operation['read_only'] ) {
        dc_assert( true === $operation['approval_required'], 'every mutation operation is approval-gated' );
        dc_assert( true === $operation['idempotent'], 'every mutation operation is declared idempotent/replay-safe at the remote boundary' );
    }
}

$manifest = Design_Core_Elementor_API_Platform::manifest();
dc_assert( true === ( $manifest['principles']['api_first'] ?? false ), 'manifest declares API-first architecture' );
dc_assert( true === ( $manifest['principles']['mcp_is_transport_adapter'] ?? false ), 'manifest declares MCP as transport adapter' );
dc_assert( true === ( $manifest['principles']['no_direct_elementor_storage_mutation'] ?? false ), 'manifest preserves the no-direct-Elementor-storage invariant' );

$openapi = Design_Core_Elementor_API_Platform::openapi();
dc_assert( '3.1.0' === ( $openapi['openapi'] ?? '' ), 'OpenAPI discovery document is 3.1.0' );
dc_assert( isset( $openapi['components']['securitySchemes']['bearerAuth'] ), 'OpenAPI advertises Bearer authentication' );
dc_assert( isset( $openapi['paths']['/pages/{id}/update']['post'] ), 'OpenAPI includes preview-gated page update' );
dc_assert( isset( $openapi['paths']['/api/understand']['post'] ), 'OpenAPI includes the high-level read-only understand helper' );

dc_finish( 'Design Core API Platform' );

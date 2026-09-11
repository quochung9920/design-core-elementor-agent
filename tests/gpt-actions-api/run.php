<?php
require dirname( __DIR__, 2 ) . '/tests/bootstrap-standalone.php';

$GLOBALS['dc_test_current_user_id'] = 7;
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function get_current_user_id() { return (int) $GLOBALS['dc_test_current_user_id']; }
function user_can( $user_id, $capability ) { return (int) $user_id === 7 && 'manage_options' === $capability; }
function wp_set_current_user( $user_id ) { $GLOBALS['dc_test_current_user_id'] = (int) $user_id; return true; }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/' ); }
function rest_url( $path = '' ) { return 'https://staging.example.test/wp-json/' . ltrim( (string) $path, '/' ); }

class WP_REST_Request {
    private $headers = array();
    public function set_header( $key, $value ) { $this->headers[ strtolower( (string) $key ) ] = (string) $value; }
    public function get_header( $key ) { return $this->headers[ strtolower( (string) $key ) ] ?? ''; }
}

dc_require( array(
    'core/design-core-capabilities.php',
    'core/remote-settings.php',
    'core/api-access-settings.php',
    'core/api-credential-registry.php',
    'core/api-credential-auth.php',
    'core/gpt-actions-api.php',
) );

dc_assert( false === Design_Core_Elementor_API_Access_Settings::enabled(), 'owner API starts disabled' );
dc_assert( 0 === Design_Core_Elementor_API_Access_Settings::owner_user_id(), 'owner API starts unclaimed' );
$claim = Design_Core_Elementor_API_Access_Settings::claim_for_current_user();
dc_assert( ! is_wp_error( $claim ) && 7 === Design_Core_Elementor_API_Access_Settings::owner_user_id(), 'current administrator can explicitly claim API ownership' );
$enabled = Design_Core_Elementor_API_Access_Settings::set_enabled_for_current_owner( true );
dc_assert( ! is_wp_error( $enabled ) && Design_Core_Elementor_API_Access_Settings::enabled(), 'owner can enable the API' );

$registry = new Design_Core_Elementor_API_Credential_Registry();
$created = $registry->create( array(
    'name' => 'ChatGPT Web Owner',
    'scopes' => Design_Core_Elementor_Capabilities::all(),
    'environment' => 'local',
    'expires_days' => 90,
) );
dc_assert( ! is_wp_error( $created ) && 0 === strpos( $created['token'], 'dcapi_' ), 'owner can mint a dcapi credential' );
dc_assert( false === strpos( wp_json_encode( $registry->list_public() ), $created['token'] ), 'plain API token is never returned by public credential listings' );
dc_assert( false === strpos( wp_json_encode( $registry->list_public() ), 'token_hash' ), 'API credential hash is never exposed by public listings' );

$request = new WP_REST_Request();
$request->set_header( 'authorization', 'Bearer ' . $created['token'] );
$principal = Design_Core_Elementor_API_Credential_Auth::resolve_from_request( $request );
dc_assert( is_array( $principal ) && 'owner_api' === ( $principal['credential_kind'] ?? '' ), 'valid dcapi credential authenticates as owner_api' );
dc_assert( 7 === (int) ( $principal['owner_user_id'] ?? 0 ), 'authenticated principal is owner-bound' );
dc_assert( Design_Core_Elementor_API_Credential_Auth::principal_has_scope( $principal, Design_Core_Elementor_Capabilities::PUBLISH ), 'full owner credential carries publish scope when explicitly granted' );

$malformed = new WP_REST_Request(); $malformed->set_header( 'authorization', 'Bearer not-a-dcapi-token' );
$malformed_result = Design_Core_Elementor_API_Credential_Auth::resolve_from_request( $malformed );
dc_assert( is_wp_error( $malformed_result ) && 'design_core_api_credential_malformed' === $malformed_result->get_error_code(), 'malformed bearer is rejected, never downgraded to cookie auth' );

$operations = Design_Core_Elementor_GPT_Actions_API::operations();
dc_assert( 42 === count( $operations ), 'GPT Actions owner API exposes the complete 42-operation contract' );
$ids = array_column( $operations, 'operationId' );
dc_assert( count( $ids ) === count( array_unique( $ids ) ), 'every GPT Action operationId is unique' );
foreach ( $operations as $op ) {
    dc_assert( ! empty( $op['capability'] ) && ! empty( $op['path'] ), 'every owner API operation is capability-scoped and path-bounded' );
    if ( ! $op['read_only'] ) { dc_assert( true === $op['consequential'], 'every mutating GPT Action is marked consequential' ); }
}

$manifest = Design_Core_Elementor_GPT_Actions_API::manifest();
dc_assert( false === ( $manifest['mcp_required'] ?? true ), 'owner API manifest explicitly does not require MCP' );
dc_assert( true === ( $manifest['security']['no_direct_elementor_storage_mutation'] ?? false ), 'owner API preserves Elementor private-storage boundary' );
dc_assert( true === ( $manifest['authentication']['owner_locked'] ?? false ), 'owner API declares owner-locked authentication' );

$openapi = Design_Core_Elementor_GPT_Actions_API::openapi( 'https://api.example.test/v1/' );
dc_assert( '3.1.0' === ( $openapi['openapi'] ?? '' ), 'GPT Actions schema is OpenAPI 3.1' );
dc_assert( 'https://api.example.test/v1' === ( $openapi['servers'][0]['url'] ?? '' ), 'OpenAPI server URL is normalized' );
dc_assert( isset( $openapi['components']['securitySchemes']['ownerApiKey'] ), 'OpenAPI declares owner Bearer API authentication' );
dc_assert( isset( $openapi['paths']['/pages/{id}/apply']['post'] ), 'OpenAPI exposes preview-gated Elementor apply action' );
dc_assert( true === $openapi['paths']['/pages/{id}/apply']['post']['x-openai-isConsequential'], 'Elementor apply is consequential' );
dc_assert( isset( $openapi['paths']['/wordpress/media/import']['post'] ), 'OpenAPI exposes bounded WordPress media import' );

dc_finish( 'GPT Actions Owner API' );

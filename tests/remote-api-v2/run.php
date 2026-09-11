<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

// Local stubs for this suite only -- not needed by the shared bootstrap.
class WP_REST_Request {
    private $headers = array();
    public function set_header( $k, $v ) { $this->headers[ strtolower( $k ) ] = $v; }
    public function get_header( $k ) { return $this->headers[ strtolower( $k ) ] ?? ''; }
}
class DC_Test_Role { public $caps = array(); public function add_cap( $cap ) { $this->caps[ $cap ] = true; } }
function get_role( $role ) { global $dc_test_role; if ( ! isset( $dc_test_role ) ) { $dc_test_role = new DC_Test_Role(); } return $dc_test_role; }
$GLOBALS['dc_test_postmeta'] = array();
function update_post_meta( $post_id, $key, $value ) { $GLOBALS['dc_test_postmeta'][ $post_id ][ $key ] = $value; return true; }
function get_post_meta( $post_id, $key, $single = false ) { return $GLOBALS['dc_test_postmeta'][ $post_id ][ $key ] ?? ''; }

dc_require( array(
    'core/change-ledger.php',
    'core/design-core-capabilities.php',
    'core/remote-settings.php',
    'core/remote-write-guard.php',
    'core/machine-credential-registry.php',
    'core/machine-credential-auth.php',
    'core/preview-ticket-store.php',
    'core/idempotency-store.php',
) );

// -- Capabilities ---------------------------------------------------------------
dc_assert( 6 === count( Design_Core_Elementor_Capabilities::all() ), 'six scoped capabilities are defined' );
$first_migration = Design_Core_Elementor_Capabilities::migrate();
dc_assert( 'migrated' === $first_migration['status'], 'first capability migration runs' );
global $dc_test_role;
dc_assert( isset( $dc_test_role->caps['design_core_read'], $dc_test_role->caps['design_core_rollback'] ), 'administrator role receives every scoped capability' );
$second_migration = Design_Core_Elementor_Capabilities::migrate();
dc_assert( 'already-migrated' === $second_migration['status'], 'capability migration is idempotent/version-gated' );

// -- Machine credentials: hashing, verification, scopes, revocation, redaction ---
$registry = new Design_Core_Elementor_Machine_Credential_Registry();
$created = $registry->create( array( 'name' => 'test-cred', 'scopes' => array( 'design_core_read', 'design_core_preview' ) ) );
dc_assert( ! is_wp_error( $created ) && ! empty( $created['token'] ), 'credential creation returns a plain token' );
dc_assert( 0 !== strpos( wp_json_encode( $registry->list_public() ), $created['token'] ), 'the plain token never appears in the public listing' );
dc_assert( false === strpos( wp_json_encode( $registry->list_public() ), 'token_hash' ), 'to_public() never exposes the stored hash' );

$token = $created['token'];
$request = new WP_REST_Request(); $request->set_header( 'authorization', 'Bearer ' . $token );
$principal = Design_Core_Elementor_Machine_Credential_Auth::resolve_from_request( $request );
dc_assert( is_array( $principal ) && 'credential' === $principal['type'], 'a valid bearer token resolves to a credential principal' );
dc_assert( Design_Core_Elementor_Machine_Credential_Auth::principal_has_scope( $principal, 'design_core_read' ), 'principal has its granted scope' );
dc_assert( ! Design_Core_Elementor_Machine_Credential_Auth::principal_has_scope( $principal, 'design_core_modify' ), 'principal does not have an ungranted scope' );

// Parse the real token instead of regex-splitting on '_': the secret's own charset
// (A-Za-z0-9_-) can itself contain '_', so a delimiter-guessing regex can slice into
// the secret and hand back a malformed token instead of a valid-format wrong secret.
preg_match( Design_Core_Elementor_Machine_Credential_Auth::TOKEN_PATTERN, $token, $token_parts );
$known_id = $token_parts[1];
$known_secret = $token_parts[2];
$wrong_secret_value = ( 'a' === $known_secret[0] ? 'b' : 'a' ) . substr( $known_secret, 1 );
$wrong_token = 'dcmcp_' . $known_id . '_' . $wrong_secret_value;
dc_assert( 1 === preg_match( Design_Core_Elementor_Machine_Credential_Auth::TOKEN_PATTERN, $wrong_token ), 'the deliberately wrong secret is still a valid-format token' );
$bad_request = new WP_REST_Request(); $bad_request->set_header( 'authorization', 'Bearer ' . $wrong_token );
$bad = Design_Core_Elementor_Machine_Credential_Auth::resolve_from_request( $bad_request );
dc_assert( is_wp_error( $bad ) && 'design_core_credential_invalid' === $bad->get_error_code(), 'a wrong secret for a known id is rejected' );

$malformed_request = new WP_REST_Request(); $malformed_request->set_header( 'authorization', 'Bearer not-a-real-token' );
$malformed = Design_Core_Elementor_Machine_Credential_Auth::resolve_from_request( $malformed_request );
dc_assert( is_wp_error( $malformed ) && 'design_core_credential_malformed' === $malformed->get_error_code(), 'a malformed token is rejected' );

$no_header_request = new WP_REST_Request();
dc_assert( null === Design_Core_Elementor_Machine_Credential_Auth::resolve_from_request( $no_header_request ), 'no Authorization header at all falls through to null (caller uses normal session auth)' );

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
$fallback_request = new WP_REST_Request(); // WP_REST_Request's own header lookup returns nothing here
$fallback = Design_Core_Elementor_Machine_Credential_Auth::resolve_from_request( $fallback_request );
dc_assert( is_array( $fallback ) && 'credential' === $fallback['type'], '$_SERVER[HTTP_AUTHORIZATION] fallback is used when WP_REST_Request has no header (Apache/mod_php gap)' );
unset( $_SERVER['HTTP_AUTHORIZATION'] );

$revoked = $registry->revoke( $created['credential']['id'] );
dc_assert( true === $revoked, 'credential revokes successfully' );
$after_revoke = Design_Core_Elementor_Machine_Credential_Auth::resolve_from_request( $request );
dc_assert( is_wp_error( $after_revoke ) && 'design_core_credential_revoked' === $after_revoke->get_error_code(), 'a revoked credential immediately loses access' );
$double_revoke = $registry->revoke( $created['credential']['id'] );
dc_assert( is_wp_error( $double_revoke ), 'revoking an already-revoked credential is rejected, not silently accepted' );

// bootstrap-standalone.php's apply_filters() stub ignores registered filters, so this
// exercises the real default limit (120/min) directly rather than overriding it.
$rate_limited = new Design_Core_Elementor_Machine_Credential_Registry();
$rl_created = $rate_limited->create( array( 'name' => 'rate-limit-test', 'scopes' => array( 'design_core_read' ) ) );
$rl_request_ok = new WP_REST_Request(); $rl_request_ok->set_header( 'authorization', 'Bearer ' . $rl_created['token'] );
$rl_last = null;
for ( $i = 0; $i < 121; $i++ ) { $rl_last = Design_Core_Elementor_Machine_Credential_Auth::resolve_from_request( $rl_request_ok ); }
dc_assert( is_wp_error( $rl_last ) && 'design_core_credential_rate_limited' === $rl_last->get_error_code(), 'the 121st request within the same window exceeds the default 120/min rate limit' );

// -- Preview ticket store: approval gate ----------------------------------------
$plan = array( 'items' => array( array( 'node_id' => 'a', 'strategy' => 'native-compose', 'adapter_target' => 'elementor-v3' ) ) );
$GLOBALS['dc_test_postmeta'][55]['_elementor_data'] = '[{"id":"a"}]';
$ticket = Design_Core_Elementor_Preview_Ticket_Store::create( 55, array( 'build_plan' => $plan, 'normalized_ir' => array( 'nodes' => array() ), 'adapter_target' => 'elementor-v3' ) );
dc_assert( ! empty( $ticket['id'] ) && ! empty( $ticket['plan_hash'] ), 'a preview ticket is created with an id and plan_hash' );

$missing = Design_Core_Elementor_Preview_Ticket_Store::validate_for_execute( 'pv_does_not_exist', 55, $ticket['plan_hash'] );
dc_assert( is_wp_error( $missing ) && 'design_core_preview_not_found' === $missing->get_error_code(), 'an unknown/expired preview_id is rejected' );

$wrong_page = Design_Core_Elementor_Preview_Ticket_Store::validate_for_execute( $ticket['id'], 999, $ticket['plan_hash'] );
dc_assert( is_wp_error( $wrong_page ) && 'design_core_preview_page_mismatch' === $wrong_page->get_error_code(), 'a preview approved for a different page is rejected' );

$wrong_hash = Design_Core_Elementor_Preview_Ticket_Store::validate_for_execute( $ticket['id'], 55, 'not-the-real-plan-hash' );
dc_assert( is_wp_error( $wrong_hash ) && 'design_core_plan_hash_mismatch' === $wrong_hash->get_error_code(), 'a mismatched plan_hash is rejected' );

$GLOBALS['dc_test_postmeta'][55]['_elementor_data'] = '[{"id":"a","changed":true}]'; // page mutated after the preview was generated
$conflict = Design_Core_Elementor_Preview_Ticket_Store::validate_for_execute( $ticket['id'], 55, $ticket['plan_hash'] );
dc_assert( is_wp_error( $conflict ) && 'design_core_page_conflict' === $conflict->get_error_code(), 'a page changed since preview is refused as a conflict, not silently overwritten' );

$GLOBALS['dc_test_postmeta'][55]['_elementor_data'] = '[{"id":"a"}]'; // restore so the ticket becomes valid again
$valid = Design_Core_Elementor_Preview_Ticket_Store::validate_for_execute( $ticket['id'], 55, $ticket['plan_hash'] );
dc_assert( is_array( $valid ) && isset( $valid['plan'] ), 'a matching, unexpired, unconflicted preview validates and returns the locked plan' );
Design_Core_Elementor_Preview_Ticket_Store::consume( $ticket['id'] );
dc_assert( null === Design_Core_Elementor_Preview_Ticket_Store::get( $ticket['id'] ), 'a consumed preview ticket cannot be reused' );

// -- Idempotency store ------------------------------------------------------------
$begin_none = Design_Core_Elementor_Idempotency_Store::begin( 'cred:1', '', 'fp1' );
dc_assert( empty( $begin_none['enabled'] ), 'no Idempotency-Key means idempotency handling is a no-op' );

$fp = Design_Core_Elementor_Idempotency_Store::fingerprint( 'POST', '/pages/1/update', array( 'confirm' => true ) );
$begin_first = Design_Core_Elementor_Idempotency_Store::begin( 'cred:1', 'key-1', $fp );
dc_assert( false === ( $begin_first['replay'] ?? true ), 'first use of a fresh Idempotency-Key is not a replay' );
$pending = Design_Core_Elementor_Idempotency_Store::begin( 'cred:1', 'key-1', $fp );
dc_assert( is_wp_error( $pending ) && 'design_core_idempotency_in_progress' === $pending->get_error_code(), 'a concurrent duplicate request while the first is still pending is rejected' );
Design_Core_Elementor_Idempotency_Store::complete( 'cred:1', 'key-1', $fp, false, 200, array( 'status' => 'success', 'page_id' => 1 ) );
$replay = Design_Core_Elementor_Idempotency_Store::begin( 'cred:1', 'key-1', $fp );
dc_assert( ! empty( $replay['replay'] ) && array( 'status' => 'success', 'page_id' => 1 ) === $replay['response']['body'], 'the exact same key+payload replays the stored result' );
$other_fp = Design_Core_Elementor_Idempotency_Store::fingerprint( 'POST', '/pages/1/update', array( 'confirm' => true, 'extra' => 'different' ) );
$mismatch = Design_Core_Elementor_Idempotency_Store::begin( 'cred:1', 'key-1', $other_fp );
dc_assert( is_wp_error( $mismatch ) && 'design_core_idempotency_conflict' === $mismatch->get_error_code(), 'the same key with a different payload is refused as a conflict' );

// -- Remote settings / write guard -------------------------------------------------
dc_assert( true === Design_Core_Elementor_Remote_Settings::writes_enabled(), 'writes are enabled by default' );
dc_assert( 'local' === Design_Core_Elementor_Remote_Settings::environment(), 'environment defaults to local' );
dc_assert( true === Design_Core_Elementor_Remote_Write_Guard::ensure_writes_enabled(), 'ensure_writes_enabled() passes when writes are enabled' );
Design_Core_Elementor_Remote_Settings::save( array( 'write_enabled' => false, 'environment' => 'production' ) );
$guard = Design_Core_Elementor_Remote_Write_Guard::ensure_writes_enabled();
dc_assert( is_wp_error( $guard ) && 'design_core_remote_writes_disabled' === $guard->get_error_code(), 'writes are refused once disabled' );
dc_assert( true === Design_Core_Elementor_Remote_Settings::is_production(), 'environment is tracked and reported correctly' );
Design_Core_Elementor_Remote_Settings::save( array( 'write_enabled' => true, 'environment' => 'local' ) );

// -- Change Ledger rollback_available_for -----------------------------------------
$rollbackable = array( 'action' => 'elementor-save', 'rolled_back_at' => '', 'before' => array( 'storage' => 'inline' ) );
dc_assert( true === Design_Core_Elementor_Change_Ledger::rollback_available_for( $rollbackable ), 'an inline-stored elementor-save entry is rollback-available' );
$hash_only = array( 'action' => 'elementor-save', 'rolled_back_at' => '', 'before' => array( 'storage' => 'hash-only' ) );
dc_assert( false === Design_Core_Elementor_Change_Ledger::rollback_available_for( $hash_only ), 'a hash-only (unrecoverable) snapshot is never reported as rollback-available' );
$already_rolled_back = array( 'action' => 'elementor-save', 'rolled_back_at' => '2026-01-01T00:00:00Z', 'before' => array( 'storage' => 'inline' ) );
dc_assert( false === Design_Core_Elementor_Change_Ledger::rollback_available_for( $already_rolled_back ), 'an already-rolled-back entry is not rollback-available again' );
$wrong_action = array( 'action' => 'page-publish', 'rolled_back_at' => '', 'before' => array( 'storage' => 'inline' ) );
dc_assert( false === Design_Core_Elementor_Change_Ledger::rollback_available_for( $wrong_action ), 'only elementor-save actions are ever reported as rollback-available' );

dc_finish( 'Remote API v2' );

<?php
/** Run with: wp --user=<existing-owner> eval-file tools/check-agent-runtime.php [page-id]
 * Read-only runtime smoke test. This is NOT a test of the ChatGPT OAuth transport.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'wp_get_ability' ) || ! class_exists( 'Design_Core_Agent_Protocol' ) ) {
    fwrite( STDERR, "Run this inside WordPress with the upgraded plugin and Abilities API loaded.\n" );
    exit( 2 );
}
$checks = array();
$call = static function ( $slug, $input = array() ) use ( &$checks ) {
    $ability = wp_get_ability( 'design-core/agent-' . $slug );
    if ( ! $ability ) { $checks[] = array( 'operation' => $slug, 'status' => 'fail', 'code' => 'not_registered' ); return null; }
    $result = $ability->execute( $input );
    if ( is_wp_error( $result ) ) { $checks[] = array( 'operation' => $slug, 'status' => 'fail', 'code' => $result->get_error_code() ); return null; }
    if ( ! is_array( $result ) || ! isset( $result['status'] ) ) { $checks[] = array( 'operation' => $slug, 'status' => 'fail', 'code' => 'invalid_result' ); return null; }
    $checks[] = array( 'operation' => $slug, 'status' => 'pass' );
    return $result;
};
$walk = static function ( $slug, $input ) use ( $call, &$checks ) {
    $count = 0; $cursor = null; $snapshot = null;
    for ( $page = 0; $page < 100; $page++ ) {
        $request = $input + array( 'limit' => 100 );
        if ( $cursor ) { $request['cursor'] = $cursor; }
        $result = $call( $slug, $request );
        if ( ! $result ) { return; }
        if ( $snapshot && $snapshot !== $result['snapshot_id'] ) { $checks[] = array( 'operation' => $slug, 'status' => 'fail', 'code' => 'mixed_revisions' ); return; }
        $snapshot = $result['snapshot_id']; $count += $result['returned']; $cursor = $result['next_cursor'];
        if ( ! $cursor ) { $checks[] = array( 'operation' => $slug . ':complete', 'status' => $count === $result['total'] ? 'pass' : 'fail', 'count' => $count, 'expected' => $result['total'] ); return; }
    }
    $checks[] = array( 'operation' => $slug, 'status' => 'fail', 'code' => 'page_budget' );
};
foreach ( Design_Core_Agent_Protocol::catalog() as $operation ) {
    $checks[] = array( 'operation' => $operation['ability'] . ':registration', 'status' => wp_get_ability( $operation['ability'] ) ? 'pass' : 'fail' );
}
$call( 'context' );
$walk( 'catalog', array() );
$walk( 'element-schema', array( 'element_type' => 'container' ) );
$call( 'control-detail', array( 'element_type' => 'container', 'pointer' => '/controls' ) );
$call( 'docs' );
$call( 'library', array( 'kind' => 'design-system' ) );
$page_id = (int) ( $args[0] ?? 0 );
if ( $page_id > 0 ) {
    $walk( 'page-tree', array( 'page_id' => $page_id ) );
    $call( 'page-element', array( 'page_id' => $page_id, 'element_id' => '@document' ) );
}
$failed = array_filter( $checks, static fn( $check ) => 'fail' === $check['status'] );
echo wp_json_encode( array( 'status' => $failed ? 'fail' : 'pass', 'checks' => $checks,
    'wordpress_user_id' => get_current_user_id(), 'page_mutations' => 0,
    'oauth_transport' => 'not_tested', 'browser_qa' => 'not_tested', 'draft_write_e2e' => 'not_tested' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( $failed ) { exit( 1 ); }

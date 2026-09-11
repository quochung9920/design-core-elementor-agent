<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array(
    'core/design-core-capabilities.php',
    'core/api-access-settings.php',
    'core/gpt-actions-api.php',
    'core/permissions.php',
    'core/mcp-ability-bridge.php',
) );

// Minimal WP-user stubs: the standalone bootstrap has no user layer.
$GLOBALS['dc_test_uid'] = 0;
$GLOBALS['dc_test_caps'] = array();
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() { return (int) $GLOBALS['dc_test_uid']; }
}
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $cap ) { return in_array( (string) $cap, (array) $GLOBALS['dc_test_caps'], true ); }
}
function dc_perm_become( $uid, array $caps, $enabled ) {
    $GLOBALS['dc_test_uid'] = $uid;
    $GLOBALS['dc_test_caps'] = $caps;
    update_option( 'design_core_elementor_owner_api_settings', array( 'enabled' => $enabled, 'owner_user_id' => 0 ) );
}

// Anonymous is always denied, even with the API enabled.
dc_perm_become( 0, Design_Core_Elementor_Capabilities::all(), true );
$r = Design_Core_Elementor_Permissions::check_ability_capability( 'design_core_read' );
dc_assert( is_wp_error( $r ) && 'design_core_ability_auth_required' === $r->get_error_code(), 'anonymous principal gets 401' );

// Disabled API denies even a fully capable admin (explicit operator switch stays).
dc_perm_become( 7, array_merge( Design_Core_Elementor_Capabilities::all(), array( 'manage_options' ) ), false );
$r = Design_Core_Elementor_Permissions::check_ability_capability( 'design_core_read' );
dc_assert( is_wp_error( $r ) && 'design_core_api_disabled' === $r->get_error_code(), 'disabled API denies with 423' );

// Authenticated user without the capability is denied.
dc_perm_become( 7, array( 'read' ), true );
$r = Design_Core_Elementor_Permissions::check_ability_capability( 'design_core_read' );
dc_assert( is_wp_error( $r ) && 'design_core_ability_scope_forbidden' === $r->get_error_code(), 'capability-less user gets 403' );

// Least privilege: modify does not imply publish.
dc_perm_become( 7, array( 'design_core_modify' ), true );
dc_assert( true === Design_Core_Elementor_Permissions::check_ability_capability( 'design_core_modify' ), 'modify holder passes modify' );
$r = Design_Core_Elementor_Permissions::check_ability_capability( 'design_core_publish' );
dc_assert( is_wp_error( $r ) && 'design_core_ability_scope_forbidden' === $r->get_error_code(), 'modify holder is denied publish' );

// THE regression: a capable user WITHOUT manage_options and WITHOUT any
// claimed owner must pass -- capability is sufficient, owner lock is gone.
dc_perm_become( 7, array( 'design_core_read', 'design_core_preview', 'design_core_build' ), true );
foreach ( array( 'design_core_read', 'design_core_preview', 'design_core_build' ) as $cap ) {
    dc_assert( true === Design_Core_Elementor_Permissions::check_ability_capability( $cap ), "non-admin capability holder passes $cap" );
    dc_assert( true === Design_Core_Elementor_MCP_Ability_Bridge::permission( $cap ), "bridge delegates $cap to the same verdict" );
}

// Unknown capability strings fail closed.
$r = Design_Core_Elementor_Permissions::check_ability_capability( 'design_core_hack' );
dc_assert( is_wp_error( $r ) && 'design_core_ability_scope_forbidden' === $r->get_error_code(), 'unknown capability fails closed with 403' );

// Unknown operations fail closed.
$r = Design_Core_Elementor_Permissions::check_operation( 'noSuchOperation' );
dc_assert( is_wp_error( $r ) && 'design_core_ability_unknown' === $r->get_error_code(), 'unknown operation fails closed with 404' );

// Catalog parity: every bridged operation resolves to a real catalog
// capability, and the runtime verdict matches the catalog requirement.
$parity_failures = 0;
foreach ( Design_Core_Elementor_MCP_Ability_Bridge::OPERATION_METHODS as $operation_id => $method ) {
    $cap = Design_Core_Elementor_Permissions::capability_for_operation( $operation_id );
    if ( null === $cap || ! in_array( $cap, Design_Core_Elementor_Capabilities::all(), true ) ) { $parity_failures++; continue; }
    dc_perm_become( 7, array( $cap ), true );
    if ( true !== Design_Core_Elementor_Permissions::check_operation( $operation_id ) ) { $parity_failures++; }
    dc_perm_become( 7, array(), true );
    $denied = Design_Core_Elementor_Permissions::check_operation( $operation_id );
    if ( ! is_wp_error( $denied ) ) { $parity_failures++; }
}
dc_assert( 0 === $parity_failures, 'catalog capability and runtime verdict agree for every bridged operation' );

// Spot-check the exact abilities from the bug report.
foreach ( array( 'getSiteStatus' => 'design_core_read', 'understandSite' => 'design_core_read', 'previewBuild' => 'design_core_preview', 'createDraftPage' => 'design_core_build' ) as $op => $cap ) {
    dc_assert( $cap === Design_Core_Elementor_Permissions::capability_for_operation( $op ), "catalog maps $op to $cap" );
}

dc_finish( 'Ability Permissions' );

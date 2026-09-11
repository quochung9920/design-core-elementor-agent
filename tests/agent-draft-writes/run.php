<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array(
    'core/agent-draft-writes.php',
) );

// Tests 1-3: auto-enable on explicitly non-production environments.
dc_assert( true === Design_Core_Elementor_Agent_Draft_Writes::resolve( false, null, 'local' ), 'undefined constant + local resolves true' );
dc_assert( true === Design_Core_Elementor_Agent_Draft_Writes::resolve( false, null, 'development' ), 'undefined constant + development resolves true' );
dc_assert( true === Design_Core_Elementor_Agent_Draft_Writes::resolve( false, null, 'staging' ), 'undefined constant + staging resolves true' );

// Test 4: production fails closed without an explicit operator value.
dc_assert( false === Design_Core_Elementor_Agent_Draft_Writes::resolve( false, null, 'production' ), 'undefined constant + production resolves false' );

// Test 5: unknown and empty environments fail closed.
dc_assert( false === Design_Core_Elementor_Agent_Draft_Writes::resolve( false, null, 'weird-cloud-9' ), 'unknown environment resolves false' );
dc_assert( false === Design_Core_Elementor_Agent_Draft_Writes::resolve( false, null, '' ), 'empty environment resolves false' );

// Test 6: explicit external false is absolute on every environment.
foreach ( array( 'local', 'development', 'staging', 'production', 'unknown' ) as $env ) {
    dc_assert( false === Design_Core_Elementor_Agent_Draft_Writes::resolve( true, false, $env ), "explicit false stays false on $env" );
}

// Explicit external true is respected absolutely (execution keeps its own
// environment allowlist on top, so production writes stay blocked there).
dc_assert( true === Design_Core_Elementor_Agent_Draft_Writes::resolve( true, true, 'local' ), 'explicit true stays true on local' );
dc_assert( true === Design_Core_Elementor_Agent_Draft_Writes::resolve( true, true, 'production' ), 'explicit true is respected absolutely even on production' );

// Fail closed when the constant was never resolved (class loaded without bootstrap).
dc_assert( false === Design_Core_Elementor_Agent_Draft_Writes::enabled(), 'enabled() is false when the constant is undefined' );

// Structural: the plugin bootstrap defines the default exactly once, only
// when the operator did not, from the plugin environment source of truth.
$bootstrap = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'design-core-elementor.php' );
dc_assert( false !== strpos( $bootstrap, "if ( ! defined( 'DESIGN_CORE_AGENT_ENABLE_DRAFT_WRITES' )" ), 'bootstrap defines the default only when undefined' );
dc_assert( false !== strpos( $bootstrap, 'Agent_Draft_Writes::default_enabled' ), 'bootstrap resolves the default through the policy service' );
$resolver = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'core/agent-draft-writes.php' );
dc_assert( false !== strpos( $resolver, 'Remote_Settings::environment' ), 'policy reads the plugin environment source of truth' );
dc_assert( false !== strpos( $resolver, "'local', 'development', 'staging'" ), 'allowlist holds exactly local/development/staging' );

dc_finish( 'Agent Draft Writes' );

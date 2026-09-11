<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/design-run-trace.php';
require_once dirname( __DIR__ ) . '/includes/class-design-runs-admin.php';

foreach ( array( 'semantic-tokenizer', 'component-ontology', 'widget-fit-engine', 'semantic-widget-intelligence-v2', 'component-planner' ) as $module ) {
    require_once __DIR__ . '/' . $module . '.php';
}
foreach ( array( 'contract', 'knowledge', 'validator', 'plans', 'semantic', 'semantic-verifier-v2', 'protocol', 'semantic-protocol', 'run-protocol' ) as $module ) {
    require_once __DIR__ . '/agent/' . $module . '.php';
}

Design_Core_Elementor_Design_Run_Trace::boot();
Design_Core_Elementor_Design_Runs_Admin::boot();

add_action( 'wp_abilities_api_init', static function () { ( new Design_Core_Agent_Protocol() )->register_abilities(); }, 30 );
add_action( 'wp_abilities_api_init', static function () { ( new Design_Core_Agent_Semantic_Protocol() )->register_abilities(); }, 35 );
add_action( 'wp_abilities_api_init', static function () { ( new Design_Core_Agent_Run_Protocol() )->register_abilities(); }, 40 );
add_action( 'rest_api_init', static function () { ( new Design_Core_Agent_Protocol() )->register_routes(); }, 35 );

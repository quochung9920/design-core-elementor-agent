<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/design-run-trace.php';

foreach ( array( 'semantic-tokenizer', 'component-ontology', 'widget-fit-engine', 'semantic-widget-intelligence-v2', 'component-planner' ) as $module ) {
    require_once __DIR__ . '/' . $module . '.php';
}
foreach ( array( 'contract', 'knowledge', 'validator', 'plans', 'semantic', 'semantic-verifier-v2', 'protocol', 'semantic-protocol', 'run-protocol' ) as $module ) {
    require_once __DIR__ . '/agent/' . $module . '.php';
}

Design_Core_Elementor_Design_Run_Trace::boot();

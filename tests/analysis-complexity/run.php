<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
require_once DESIGN_CORE_ELEMENTOR_PATH . 'core/security-policy.php';
require_once DESIGN_CORE_ELEMENTOR_PATH . 'core/analysis-engine.php';
require_once DESIGN_CORE_ELEMENTOR_PATH . 'core/browser-analysis-service.php';

$analysis_reflection = new ReflectionClass( 'Design_Core_Elementor_Analysis_Engine' );
if ( ! $analysis_reflection->hasMethod( 'validate_html_complexity' ) ) {
    dc_assert( false, 'Analysis engine exposes a bounded pre-DOM HTML complexity gate' );
    dc_finish( 'Analysis complexity bounds' );
}
$analysis = $analysis_reflection->newInstanceWithoutConstructor();
$complexity = $analysis_reflection->getMethod( 'validate_html_complexity' );
$complexity->setAccessible( true );

$too_many = '<main>' . str_repeat( '<i></i>', 2501 ) . '</main>';
$too_many_result = $complexity->invoke( $analysis, $too_many );
dc_assert( is_wp_error( $too_many_result ) && 'design_core_html_element_limit' === $too_many_result->get_error_code(), 'High-node-count HTML is rejected before DOMDocument traversal' );

$too_deep = str_repeat( '<div>', 129 ) . 'x' . str_repeat( '</div>', 129 );
$too_deep_result = $complexity->invoke( $analysis, $too_deep );
dc_assert( is_wp_error( $too_deep_result ) && 'design_core_html_depth_limit' === $too_deep_result->get_error_code(), 'Deeply nested HTML is rejected before recursive IR extraction' );
$mismatched_depth = str_repeat( '<div></x>', 129 );
$mismatched_result = $complexity->invoke( $analysis, $mismatched_depth );
dc_assert( is_wp_error( $mismatched_result ) && 'design_core_html_depth_limit' === $mismatched_result->get_error_code(), 'Mismatched closing tags cannot decrement governed nesting depth' );
dc_assert( true === $complexity->invoke( $analysis, '<main><section><p>Safe</p></section></main>' ), 'Ordinary HTML passes the pre-DOM complexity gate' );
$live_rejection = ( new Design_Core_Elementor_Analysis_Engine() )->analyze_html( $too_many );
dc_assert( 'design_core_html_element_limit' === ( $live_rejection['error'] ?? '' ) && empty( $live_rejection['design_ir'] ), 'Public analysis path rejects excessive HTML before constructing DOMDocument' );

$browser_reflection = new ReflectionClass( 'Design_Core_Elementor_Browser_Analysis_Service' );
dc_assert( $browser_reflection->hasMethod( 'bounded_viewports' ), 'Browser service exposes deterministic bounded viewport normalization' );
if ( $browser_reflection->hasMethod( 'bounded_viewports' ) ) {
    $browser = $browser_reflection->newInstanceWithoutConstructor();
    $bounded = $browser_reflection->getMethod( 'bounded_viewports' ); $bounded->setAccessible( true );
    $viewports = $bounded->invoke( $browser, array( 2400, 2200, 2000, 1800, 1600, 1440, 1366, 1024, 767, 390 ) );
    foreach ( array( 1440, 1366, 1024, 767, 390 ) as $governed ) { dc_assert( in_array( $governed, $viewports, true ), 'Viewport cap preserves governed evidence width ' . $governed ); }
    dc_assert( 8 === count( $viewports ), 'Viewport normalization remains bounded to eight samples' );
}

$browser_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'scripts/browser-analyze.mjs' );
$node_list_position = strpos( $browser_source, "document.querySelectorAll('body *')" );
$length_position = strpos( $browser_source, 'elements.length', $node_list_position );
$spread_position = strpos( $browser_source, '[...elements]', $node_list_position );
dc_assert( false !== $node_list_position && false !== $length_position && false !== $spread_position && $length_position < $spread_position, 'Browser analyzer checks NodeList length before array materialization' );
$preflight_position = strpos( $browser_source, 'preflightHtmlComplexity' );
$launch_position = strpos( $browser_source, 'chromium.launch' );
dc_assert( false !== $preflight_position && false !== $launch_position && $preflight_position < $launch_position, 'Browser analyzer bounds source bytes, element count, and nesting before Chromium parses the document' );
dc_assert( false !== strpos( $browser_source, 'openTags' ) && false !== strpos( $browser_source, 'openTags[openTags.length - 1] === tag' ), 'Browser depth preflight only pops a matching open tag' );

dc_finish( 'Analysis complexity bounds' );

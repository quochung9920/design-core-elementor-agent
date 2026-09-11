<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array(
    'core/native-fidelity-report.php',
    'includes/class-settings.php',
) );

$all_native = array( 'status' => 'success', 'diagnostics' => array( 'item_results' => array(
    array( 'node_id' => 'a', 'strategy' => 'native-widget', 'diagnostics' => array( 'requested_strategy' => 'native-widget', 'actual_strategy' => 'native-widget' ) ),
    array( 'node_id' => 'b', 'strategy' => 'native-compose', 'diagnostics' => array( 'requested_strategy' => 'native-compose', 'actual_strategy' => 'native-compose' ) ),
    array( 'node_id' => 'c', 'strategy' => 'native-widget', 'diagnostics' => array( 'requested_strategy' => 'native-widget', 'actual_strategy' => 'native-widget' ) ),
) ) );
$report = Design_Core_Elementor_Native_Fidelity_Report::from_execution( $all_native );
dc_assert( 3 === $report['total_items'], 'all-native execution counts 3 items' );
dc_assert( 3 === $report['native_items'] && 0 === $report['fallback_items'], 'all-native execution has zero fallbacks' );
dc_assert( 100.0 === $report['native_rate'], 'all-native execution rates 100' );
dc_assert( true === Design_Core_Elementor_Native_Fidelity_Report::meets_threshold( $report, 90.0 ), 'all-native execution meets the default 90 gate' );

$mixed = array( 'status' => 'success', 'diagnostics' => array( 'item_results' => array(
    array( 'node_id' => 'a', 'strategy' => 'native-widget', 'diagnostics' => array( 'requested_strategy' => 'native-widget', 'actual_strategy' => 'native-widget' ) ),
    array( 'node_id' => 'b', 'strategy' => 'native-widget', 'fallback_used' => true, 'diagnostics' => array( 'requested_strategy' => 'native-widget', 'actual_strategy' => 'custom-html', 'reason' => 'missing-controls', 'error_code' => 'native-widget-unavailable' ) ),
    array( 'node_id' => 'c', 'strategy' => 'native-compose', 'diagnostics' => array( 'requested_strategy' => 'native-compose', 'actual_strategy' => 'native-compose' ) ),
    array( 'node_id' => 'd', 'strategy' => 'native-widget', 'diagnostics' => array( 'requested_strategy' => 'native-widget', 'actual_strategy' => 'native-widget' ) ),
) ) );
$mixed_report = Design_Core_Elementor_Native_Fidelity_Report::from_execution( $mixed );
dc_assert( 4 === $mixed_report['total_items'], 'mixed execution counts 4 items' );
dc_assert( 3 === $mixed_report['native_items'] && 1 === $mixed_report['fallback_items'], 'mixed execution isolates the single fallback' );
dc_assert( 75.0 === $mixed_report['native_rate'], 'mixed execution rates 75' );
dc_assert( 1 === count( $mixed_report['fallbacks'] ), 'mixed execution lists one fallback entry' );
dc_assert( 'b' === $mixed_report['fallbacks'][0]['node_id'], 'fallback entry keeps the node id' );
dc_assert( 'custom-html' === $mixed_report['fallbacks'][0]['actual_strategy'], 'fallback entry keeps the actual strategy' );
dc_assert( 'missing-controls' === $mixed_report['fallbacks'][0]['reason'], 'fallback entry keeps the reason' );
dc_assert( 2 === ( $mixed_report['by_actual_strategy']['native-widget'] ?? 0 ), 'strategy breakdown counts native-widget' );
dc_assert( 1 === ( $mixed_report['by_actual_strategy']['native-compose'] ?? 0 ), 'strategy breakdown counts native-compose' );
dc_assert( 1 === ( $mixed_report['by_actual_strategy']['custom-html'] ?? 0 ), 'strategy breakdown counts the fallback strategy' );
dc_assert( false === Design_Core_Elementor_Native_Fidelity_Report::meets_threshold( $mixed_report, 90.0 ), '75-rate execution fails the default 90 gate' );
dc_assert( true === Design_Core_Elementor_Native_Fidelity_Report::meets_threshold( $mixed_report, 75.0 ), '75-rate execution meets a 75 gate' );

$empty = Design_Core_Elementor_Native_Fidelity_Report::from_execution( array( 'status' => 'success' ) );
dc_assert( 0 === $empty['total_items'] && 100.0 === $empty['native_rate'], 'execution without items rates 100 instead of dividing by zero' );
dc_assert( true === Design_Core_Elementor_Native_Fidelity_Report::meets_threshold( $empty, 100.0 ), 'empty execution meets even a 100 gate' );

dc_assert( 90.0 === Design_Core_Elementor_Native_Fidelity_Report::min_rate_from_settings(), 'default minimum native rate is 90 without stored settings' );
update_option( 'design_core_conversion_rules', array( 'min_native_rate' => 80.0 ) );
dc_assert( 80.0 === Design_Core_Elementor_Native_Fidelity_Report::min_rate_from_settings(), 'stored min_native_rate setting is honoured' );
update_option( 'design_core_conversion_rules', array( 'min_native_rate' => 250 ) );
dc_assert( 100.0 === Design_Core_Elementor_Native_Fidelity_Report::min_rate_from_settings(), 'out-of-range min_native_rate is clamped to 100' );

// The shipped settings sanitizer must preserve the new rule alongside the old ones.
$sanitized = Design_Core_Elementor_Settings::sanitize_conversion_rules( array( 'min_native_rate' => '85.5' ) );
dc_assert( 85.5 === $sanitized['min_native_rate'], 'sanitizer keeps min_native_rate' );
dc_assert( false === $sanitized['create_as_draft'], 'sanitizer defaults unchecked boxes to false' );

dc_finish( 'Native Fidelity' );

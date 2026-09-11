<?php
/** Runtime-only Control Schema v2 parity check. Run with `wp eval-file`. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$plugin = \Elementor\Plugin::instance();
$registry = new Design_Core_Elementor_Control_Schema_Registry();
$checked = array();
foreach ( array( 'heading', 'text-editor', 'button', 'image' ) as $widget_type ) {
    $widget = $plugin->widgets_manager->get_widget_types( $widget_type );
    if ( ! $widget ) { throw new RuntimeException( $widget_type . ' widget is not registered.' ); }
    $own = $widget->get_stack( false );
    $method = new ReflectionMethod( $widget, 'get_common_widget_name' );
    $method->setAccessible( true );
    $common_name = $method->invoke( $widget );
    $common = $common_name ? $plugin->widgets_manager->get_widget_types( $common_name ) : null;
    $common_stack = $common ? $common->get_stack( false ) : array();
    $expected = array_merge(
        is_array( $own['controls'] ?? null ) ? $own['controls'] : array(),
        is_array( $own['style_controls'] ?? null ) ? $own['style_controls'] : array(),
        is_array( $common_stack['controls'] ?? null ) ? $common_stack['controls'] : array(),
        is_array( $common_stack['style_controls'] ?? null ) ? $common_stack['style_controls'] : array()
    );

    $schema = $registry->schema( 'widget', $widget_type, true );
    $actual = (array) ( $schema['controls'] ?? array() );
    $missing = array_diff_key( $expected, $actual );
    if ( $missing ) {
        throw new RuntimeException( $widget_type . ' registry is missing ' . count( $missing ) . ' controls from the explicit Elementor stacks.' );
    }
    if ( 2 !== (int) ( $schema['schema_version'] ?? 0 ) ) {
        throw new RuntimeException( $widget_type . ' did not resolve Control Schema v2.' );
    }
    if ( empty( $schema['fingerprint'] ) || empty( $schema['json_schema']['properties'] ) ) {
        throw new RuntimeException( $widget_type . ' did not produce a fingerprint and machine schema.' );
    }
    $checked[ $widget_type ] = array(
        'explicit_controls' => count( $expected ),
        'resolved_controls' => count( $actual ),
        'fingerprint' => $schema['fingerprint'],
        'capabilities' => $schema['capabilities']['supports'] ?? array(),
    );
}
WP_CLI::log( wp_json_encode( array( 'status' => 'pass', 'widgets' => $checked ) ) );

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
wp_set_current_user( 1 );
function dc_gds_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function dc_gds_find_widget( $elements, $widget_type ) {
    foreach ( $elements as $element ) {
        if ( 'widget' === ( $element['elType'] ?? '' ) && $widget_type === ( $element['widgetType'] ?? '' ) ) { return $element; }
        if ( ! empty( $element['elements'] ) ) { $found = dc_gds_find_widget( $element['elements'], $widget_type ); if ( $found ) { return $found; } }
    }
    return null;
}

$kit = \Elementor\Plugin::instance()->kits_manager->get_active_kit();
dc_gds_assert( $kit && method_exists( $kit, 'get_settings' ), 'Active Elementor Kit is unavailable.' );

$tokens = new Design_Core_Elementor_Design_Token_Service();
$tokens->save( array( 'colors' => array( 'dcgds-brand' => '#1f6feb' ) ) );
$bridge = new Design_Core_Elementor_Global_Style_Bridge();
$sync1 = $bridge->sync( 'v3' );
dc_gds_assert( 'synced' === ( $sync1['status'] ?? '' ), 'V3 global sync failed: ' . wp_json_encode( $sync1 ) );

$html = '<section><h3 class="dc-gds-heading">Global Heading</h3></section>';
$css = '.dc-gds-heading{color:#1f6feb;}';
$result = ( new Design_Core_Elementor_HTML_Converter() )->convert_to_elementor( $html, $css, 'Global Design System Evidence' );
dc_gds_assert( 'success' === ( $result['status'] ?? '' ), 'Global design system conversion failed: ' . ( $result['error'] ?? 'unknown' ) );

$heading = dc_gds_find_widget( $result['execution']['elements'] ?? array(), 'heading' );
dc_gds_assert( is_array( $heading ) && ! empty( $heading['settings']['__globals__']['title_color'] ), 'Generated Heading did not reference a global color through its runtime-native title_color control.' );
$ref1 = $heading['settings']['__globals__']['title_color'];
dc_gds_assert( false !== strpos( $ref1, 'globals/colors?id=' ), 'Global color reference has an unexpected shape: ' . $ref1 );

$tokens->save( array( 'colors' => array( 'dcgds-brand' => '#0b3d91' ) ) );
$sync2 = $bridge->sync( 'v3' );
dc_gds_assert( 'synced' === ( $sync2['status'] ?? '' ), 'Second V3 global sync failed: ' . wp_json_encode( $sync2 ) );
dc_gds_assert( ( $sync1['colors']['dcgds-brand'] ?? 'a' ) === ( $sync2['colors']['dcgds-brand'] ?? 'b' ), 'Re-sync produced a different global reference instead of reusing the same one.' );

$expected_id = substr( md5( sanitize_key( 'dc-color-dcgds-brand' ) ), 0, 7 );
// Read the persisted post meta directly rather than $kit->get_settings() -- Elementor's Controls_Stack
// caches parsed settings on the (still same, in-process) kit object, so a second get_settings() call
// in the same request can return the pre-update value even though update_settings() already persisted.
$kit_id = \Elementor\Plugin::instance()->kits_manager->get_active_id();
$persisted_settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
$custom_colors = (array) ( is_array( $persisted_settings ) ? ( $persisted_settings['custom_colors'] ?? array() ) : array() );
$matching = array_values( array_filter( $custom_colors, static function ( $c ) use ( $expected_id ) { return ( $c['_id'] ?? '' ) === $expected_id; } ) );
dc_gds_assert( 1 === count( $matching ), 'Expected exactly one global color entry for the token (no duplicate); found ' . count( $matching ) . '.' );
dc_gds_assert( '#0b3d91' === strtolower( (string) ( $matching[0]['color'] ?? '' ) ), 'Global color value was not updated in place on the same entry.' );

( new Design_Core_Elementor_Runtime_Evidence() )->record( 'global-design-system', 'pass', array(
    'mode' => 'v3',
    'global_id' => $expected_id,
    'page_id' => (int) $result['page_id'],
), 'global-design-system-smoke' );
echo "global-design-system=pass\n";

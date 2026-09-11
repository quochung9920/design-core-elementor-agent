<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
wp_set_current_user( 1 );
function dc_cwmi_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$html = '<section class="pricing-calculator"><h3>Calculator A</h3><p>Compare monthly hosting costs</p><a href="#buy-a">Buy A</a></section>'
    . '<section class="pricing-calculator"><h3>Calculator B</h3><p>Estimate yearly hosting costs</p><a href="#buy-b">Buy B</a></section>';
$converter = new Design_Core_Elementor_HTML_Converter();
$result = $converter->convert_to_elementor( $html, '', 'Custom Widget Multi Instance' );
dc_cwmi_assert( 'success' === ( $result['status'] ?? '' ), 'Custom widget multi-instance conversion failed: ' . ( $result['error'] ?? 'unknown' ) );
$page_id = (int) $result['page_id'];

$strategies = array_column( $result['build_plan']['items'] ?? array(), 'strategy' );
dc_cwmi_assert( 2 === count( array_filter( $strategies, static function ( $s ) { return 'custom-widget' === $s; } ) ), 'Both calculator instances did not select the custom-widget strategy: ' . implode( ',', $strategies ) );

$widgets = array_values( array_filter( $result['execution']['elements'] ?? array(), static function ( $el ) { return 'widget' === ( $el['elType'] ?? '' ); } ) );
dc_cwmi_assert( 2 === count( $widgets ), 'Expected exactly 2 custom widget elements, found ' . count( $widgets ) );
dc_cwmi_assert( ( $widgets[0]['widgetType'] ?? '' ) === ( $widgets[1]['widgetType'] ?? '' ) && '' !== ( $widgets[0]['widgetType'] ?? '' ), 'Both instances should reuse one registered widget type instead of generating a duplicate.' );
dc_cwmi_assert( $widgets[0]['settings'] !== $widgets[1]['settings'], 'Widget instance settings were not stored independently (shared instance state).' );
dc_cwmi_assert( 'Calculator A' === ( $widgets[0]['settings']['title'] ?? '' ) && 'Calculator B' === ( $widgets[1]['settings']['title'] ?? '' ), 'Instance content bindings did not preserve each calculator\'s own title.' );

$rendered = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $page_id, true );
dc_cwmi_assert( '' !== trim( (string) $rendered ), 'Multi-instance page did not render.' );
$pos_a = strpos( $rendered, 'Calculator A' ); $pos_b = strpos( $rendered, 'Calculator B' );
dc_cwmi_assert( false !== $pos_a && false !== $pos_b && $pos_a < $pos_b, 'Both widget instances must render, in source order, with independent content.' );

$registry_item = ( new Design_Core_Elementor_Widget_Registry() )->get( $widgets[0]['widgetType'] );
dc_cwmi_assert( is_array( $registry_item ) && ! empty( $registry_item['slug'] ), 'Custom widget was not persisted to the Widget Registry.' );

( new Design_Core_Elementor_Runtime_Evidence() )->record( 'custom-widget-multi-instance', 'pass', array(
    'page_id' => $page_id,
    'widget_type' => $widgets[0]['widgetType'],
    'instances' => 2,
), 'custom-widget-multi-instance-smoke' );
echo "custom-widget-multi-instance=pass\n";

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
wp_set_current_user( 1 );
function dc_nw_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function dc_nw_find_widget( $elements, $widget_types ) {
    foreach ( $elements as $element ) {
        if ( 'widget' === ( $element['elType'] ?? '' ) && in_array( $element['widgetType'] ?? '', $widget_types, true ) ) { return $element; }
        if ( ! empty( $element['elements'] ) ) { $found = dc_nw_find_widget( $element['elements'], $widget_types ); if ( $found ) { return $found; } }
    }
    return null;
}

$html = '<section class="faq"><div class="faq-item"><h3>Question 1</h3><p>Answer 1</p></div><div class="faq-item"><h3>Question 2</h3><p>Answer 2</p></div></section>';
$result = ( new Design_Core_Elementor_HTML_Converter() )->convert_to_elementor( $html, '', 'Native Widget FAQ' );
dc_nw_assert( 'success' === ( $result['status'] ?? '' ), 'FAQ conversion failed: ' . ( $result['error'] ?? 'unknown' ) );

$strategy = $result['build_plan']['items'][0]['strategy'] ?? '';
$capabilities = ( new Design_Core_Elementor_Capability_Scanner() )->scan();
$resolution = ( new Design_Core_Elementor_Native_Widget_Resolver() )->resolve( 'faq', $capabilities );

if ( 'supported' !== ( $resolution['status'] ?? '' ) ) {
    // No real FAQ-capable native widget registered in this runtime (e.g. accordion/toggle missing) --
    // the spec requires an explicit, diagnosed fallback here, never a claim of native-widget execution.
    dc_nw_assert( 'native-compose' === $strategy, 'No native FAQ widget is available but strategy was not the diagnosed native-compose fallback: ' . $strategy );
    dc_nw_assert(
        'native-widget-unavailable' === ( $result['build_plan']['items'][0]['fallback']['reason'] ?? '' )
        || false !== strpos( (string) ( $result['execution']['diagnostics']['item_results'][0]['diagnostics']['reason'] ?? '' ), 'faq-widget-unavailable' ),
        'Native-widget fallback is missing its diagnostics.'
    );
    ( new Design_Core_Elementor_Runtime_Evidence() )->record( 'native-widget-semantic-execution', 'unavailable', array( 'reason' => $resolution['reason'] ?? 'faq-widget-unavailable' ), 'native-widget-smoke' );
    echo "native-widget-semantic-execution=unavailable:" . ( $resolution['reason'] ?? '' ) . "\n";
    return;
}

dc_nw_assert( 'native-widget' === $strategy, 'FAQ did not select native-widget even though a native FAQ widget is available: ' . $strategy );
$widget = dc_nw_find_widget( $result['execution']['elements'] ?? array(), array( 'accordion', 'toggle', 'nested-accordion' ) );
dc_nw_assert( is_array( $widget ), 'Execution did not produce a real native FAQ-capable widget element.' );
dc_nw_assert( $widget['widgetType'] === $resolution['widget_type'], 'Bound widgetType does not match the resolver decision.' );

$tabs = $widget['settings']['tabs'] ?? array();
dc_nw_assert( 2 === count( $tabs ), 'Expected exactly 2 FAQ items bound into the native widget, got ' . count( $tabs ) );
dc_nw_assert( 'Question 1' === ( $tabs[0]['tab_title'] ?? '' ) && false !== strpos( (string) ( $tabs[0]['tab_content'] ?? '' ), 'Answer 1' ), 'First FAQ item title/content not preserved.' );
dc_nw_assert( 'Question 2' === ( $tabs[1]['tab_title'] ?? '' ) && false !== strpos( (string) ( $tabs[1]['tab_content'] ?? '' ), 'Answer 2' ), 'Second FAQ item title/content not preserved.' );

$post_id = (int) $result['page_id'];
$reloaded = ( new Design_Core_Elementor_V3_Adapter() )->reload( $post_id );
dc_nw_assert( ! empty( $reloaded ), 'Native FAQ widget page failed to reload.' );
$rendered = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $post_id, true );
dc_nw_assert( '' !== trim( (string) $rendered ), 'Native FAQ widget page failed to render.' );
dc_nw_assert( false !== strpos( $rendered, 'Question 1' ) && false !== strpos( $rendered, 'Question 2' ), 'Rendered output is missing the FAQ questions.' );

( new Design_Core_Elementor_Runtime_Evidence() )->record( 'native-widget-semantic-execution', 'pass', array(
    'page_id' => $post_id,
    'widget_type' => $widget['widgetType'],
    'items' => count( $tabs ),
), 'native-widget-smoke' );
echo "native-widget-semantic-execution=pass\n";

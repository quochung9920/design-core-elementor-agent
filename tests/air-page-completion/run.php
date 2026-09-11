<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

$script = DESIGN_CORE_ELEMENTOR_PATH . 'scripts/provision-air-page-completion.php';
if ( ! file_exists( $script ) ) {
    dc_assert( false, 'Air page completion blueprint exists' );
    dc_finish( 'Air page completion' );
}
define( 'DESIGN_CORE_AIR_PAGE_COMPLETION_BLUEPRINT_ONLY', true );
require $script;

$elements = design_core_air_page_completion_elements( array(
    'carton_top' => array( 'id' => 334, 'url' => 'https://example.test/carton-top.webp' ),
    'carton_side' => array( 'id' => 335, 'url' => 'https://example.test/carton-side.webp' ),
    'carton_front' => array( 'id' => 336, 'url' => 'https://example.test/carton-front.webp' ),
    'process' => array( 'id' => 337, 'url' => 'https://example.test/process.webp' ),
) );
$classes = array(); $widgets = array(); $html_stylesheets = 0; $nonzero_margins = 0;
$walk = static function ( $nodes ) use ( &$walk, &$classes, &$widgets, &$html_stylesheets, &$nonzero_margins ) {
    foreach ( (array) $nodes as $node ) {
        $settings = (array) ( $node['settings'] ?? array() );
        $class = (string) ( $settings['css_classes'] ?? $settings['_css_classes'] ?? '' );
        foreach ( preg_split( '/\s+/', trim( $class ) ) as $item ) { if ( '' !== $item ) { $classes[] = $item; } }
        if ( 'widget' === ( $node['elType'] ?? '' ) ) { $widgets[] = $node['widgetType'] ?? ''; }
        if ( 'html' === ( $node['widgetType'] ?? '' ) && false !== stripos( (string) ( $settings['html'] ?? '' ), '<style' ) ) { $html_stylesheets++; }
        foreach ( $settings as $key => $value ) {
            if ( 0 === strpos( $key, 'margin' ) && is_array( $value ) && array_filter( $value, static fn( $v, $k ) => 'unit' !== $k && 'isLinked' !== $k && 0 !== (float) $v, ARRAY_FILTER_USE_BOTH ) ) { $nonzero_margins++; }
        }
        $walk( $node['elements'] ?? array() );
    }
};
$walk( $elements );

dc_assert( 6 === count( $elements ), 'Completion blueprint owns exactly six missing roots' );
foreach ( array( 'dc-air-jumpbar', 'dc-costs-section', 'dc-process-section', 'dc-transit-section', 'dc-faq-section', 'dc-enquiry-section' ) as $class ) {
    dc_assert( in_array( $class, $classes, true ), "Completion includes {$class}" );
}
dc_assert( 'full' === ( $elements[0]['settings']['content_width'] ?? '' ) && 'top' === ( $elements[0]['settings']['sticky'] ?? '' ), 'Jumpbar is native Full Width and sticky' );
foreach ( array_slice( $elements, 1 ) as $root ) {
    dc_assert( 'full' === ( $root['settings']['content_width'] ?? '' ), 'Every missing section outer surface is native Full Width' );
    dc_assert( 'boxed' === ( $root['elements'][0]['settings']['content_width'] ?? '' ), 'Every missing section inner content owner is native Boxed' );
    dc_assert( ! isset( $root['elements'][0]['settings']['boxed_width'], $root['elements'][0]['settings']['max_width'] ), 'Boxed owner inherits Global Content Width without local width simulation' );
}
dc_assert( in_array( 'accordion', $widgets, true ), 'FAQ uses native Elementor Accordion' );
dc_assert( in_array( 'form', $widgets, true ), 'Enquiry uses native Elementor Pro Form' );
dc_assert( in_array( 'image', $widgets, true ), 'Cost and process assets use native Image widgets' );
dc_assert( 0 === $html_stylesheets, 'No HTML widget contains a stylesheet' );
dc_assert( 0 === $nonzero_margins, 'Sibling rhythm uses Container Gap instead of widget margins' );

dc_finish( 'Air page completion' );

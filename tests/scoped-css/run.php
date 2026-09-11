<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
dc_require( array( 'core/control-schema-registry.php' ) );

$fallback_file = DESIGN_CORE_ELEMENTOR_PATH . 'core/scoped-css-fallback.php';
$audit_file = DESIGN_CORE_ELEMENTOR_PATH . 'core/elementor-architecture-auditor.php';
if ( ! file_exists( $fallback_file ) || ! file_exists( $audit_file ) ) {
    dc_assert( false, 'Scoped CSS fallback and architecture auditor exist' );
    dc_finish( 'Scoped CSS ownership' );
}
require_once $fallback_file;
require_once $audit_file;

$provider = static function ( $element_type, $widget_type ) {
    if ( 'widget' === $element_type && 'heading' === $widget_type ) {
        return array( 'controls' => array( 'custom_css' => array( 'type' => 'code' ) ), 'style_controls' => array() );
    }
    return array();
};
$registry = new Design_Core_Elementor_Control_Schema_Registry( $provider );
$fallback = new Design_Core_Elementor_Scoped_CSS_Fallback( $registry );
$result = $fallback->apply( 'widget', 'heading', array( 'title' => 'Native title' ), array(
    'text-wrap' => 'balance',
    'width' => 'min(100%, 70rem)',
) );
dc_assert( false !== strpos( $result['settings']['custom_css'] ?? '', 'selector {' ), 'Fallback CSS is owned by the target Elementor element' );
dc_assert( false !== strpos( $result['settings']['custom_css'] ?? '', 'text-wrap: balance;' ), 'Safe unsupported declaration is preserved' );
dc_assert( false === strpos( $result['settings']['custom_css'] ?? '', '<style' ), 'Fallback never emits a style tag' );
dc_assert( empty( $result['unsupported'] ), 'Supported scoped fallback reports no unsupported values' );

$unsafe = $fallback->apply( 'widget', 'heading', array(), array( '@import' => 'url(https://bad.example/x.css)' ) );
dc_assert( ! empty( $unsafe['unsupported'] ), 'Unsafe CSS injection is rejected' );
dc_assert( empty( $unsafe['settings']['custom_css'] ), 'Unsafe CSS is never persisted' );
$external_url = $fallback->apply( 'widget', 'heading', array(), array( 'background-image' => 'url(https://tracking.example/pixel)' ) );
dc_assert( ! empty( $external_url['unsupported'] ) && empty( $external_url['settings']['custom_css'] ), 'Scoped fallback cannot bypass governed asset import with external CSS URLs' );
$escaped_url = $fallback->apply( 'widget', 'heading', array(), array( 'background-image' => 'u\\72l(https://tracking.example/pixel)' ) );
dc_assert( ! empty( $escaped_url['unsupported'] ) && empty( $escaped_url['settings']['custom_css'] ), 'CSS escapes cannot bypass URL rejection' );
$data_uri = $fallback->apply( 'widget', 'heading', array(), array( 'background' => 'data:text/css,body{}' ) );
dc_assert( ! empty( $data_uri['unsupported'] ) && empty( $data_uri['settings']['custom_css'] ), 'Data URIs are rejected by scoped fallback' );
$expression = $fallback->apply( 'widget', 'heading', array(), array( 'width' => 'expression(alert(1))' ) );
dc_assert( ! empty( $expression['unsupported'] ) && empty( $expression['settings']['custom_css'] ), 'Legacy CSS expressions are rejected' );
$image_set = $fallback->apply( 'widget', 'heading', array(), array( 'background-image' => 'image-set("https://tracking.example/pixel" 1x)' ) );
dc_assert( ! empty( $image_set['unsupported'] ) && empty( $image_set['settings']['custom_css'] ), 'CSS image-set cannot load external resources' );
$webkit_image_set = $fallback->apply( 'widget', 'heading', array(), array( 'background-image' => '-webkit-image-set("https://tracking.example/pixel" 1x)' ) );
dc_assert( ! empty( $webkit_image_set['unsupported'] ) && empty( $webkit_image_set['settings']['custom_css'] ), 'Vendor-prefixed image-set cannot load external resources' );

$missing_control = $fallback->apply( 'container', '', array(), array( 'text-wrap' => 'balance' ) );
dc_assert( ! empty( $missing_control['unsupported'] ), 'Missing Custom CSS capability fails closed' );

$elements = array(
    array(
        'id' => 'root', 'elType' => 'container',
        'settings' => array( '_css_classes' => 'dc-root', 'css_classes' => 'dc-root', 'content_width' => 'full' ),
        'elements' => array(
            array( 'id' => 'bad-html', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => '<style>.dc-root{padding:40px!important}</style><input>' ), 'elements' => array() ),
        ),
    ),
);
$audit = ( new Design_Core_Elementor_Architecture_Auditor() )->audit( $elements );
dc_assert( 1 === $audit['stylesheet_html_widget_count'], 'Stylesheets hidden in HTML widgets are detected' );
dc_assert( 1 === $audit['important_declaration_count'], '!important dependency is counted' );
dc_assert( 1 === $audit['class_only_container_count'], 'Class-only containers are detected as low native-control coverage' );
dc_assert( 'fail' === $audit['status'], 'Cross-element stylesheet dependency fails architecture QA' );

dc_finish( 'Scoped CSS ownership' );

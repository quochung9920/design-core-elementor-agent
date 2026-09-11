<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
dc_require( array(
    'core/design-ir.php', 'core/design-ir-validator.php', 'core/breakpoint-registry.php', 'core/responsive-style-parser.php', 'core/control-schema-registry.php',
    'core/widget-control-adapters.php', 'core/scoped-css-fallback.php', 'core/elementor-mapping-engine.php',
) );

$reflection = new ReflectionClass( 'Design_Core_Elementor_Mapping_Engine' );
$constructor = $reflection->getConstructor();
if ( ! $constructor || $constructor->getNumberOfParameters() < 1 ) {
    dc_assert( false, 'Elementor Mapping Engine accepts the runtime control mapper' );
    dc_finish( 'Native mapper integration' );
}

$schemas = array(
    'container:' => array(
        '_element_id' => array( 'type' => 'text' ),
        'css_classes' => array( 'type' => 'text' ),
        'html_tag' => array( 'type' => 'select' ),
        'content_width' => array( 'type' => 'select' ),
        'flex_direction' => array( 'type' => 'choose', 'is_responsive' => true ),
        'padding' => array( 'type' => 'dimensions', 'is_responsive' => true ),
        'background_background' => array( 'type' => 'choose' ),
        'background_color' => array( 'type' => 'color' ),
    ),
    'widget:heading' => array(
        '_css_classes' => array( 'type' => 'text' ),
        'title_color' => array( 'type' => 'color' ),
        'typography_typography' => array( 'type' => 'popover_toggle' ),
        'typography_font_size' => array( 'type' => 'slider', 'is_responsive' => true ),
        'typography_font_size_tablet' => array( 'type' => 'slider', 'is_responsive' => true ),
        'custom_css' => array( 'type' => 'code' ),
    ),
    'widget:image' => array(
        '_css_classes' => array( 'type' => 'text' ),
        'width' => array( 'type' => 'slider', 'is_responsive' => true ),
    ),
    'widget:form' => array(
        '_css_classes' => array( 'type' => 'text' ),
        'form_fields' => array( 'type' => 'form-fields-repeater' ),
        'form_name' => array( 'type' => 'text' ),
        'button_text' => array( 'type' => 'text' ),
        'show_labels' => array( 'type' => 'switcher' ),
        'mark_required' => array( 'type' => 'switcher' ),
        'submit_actions' => array( 'type' => 'select2' ),
        'input_size' => array( 'type' => 'select' ),
        'button_width' => array( 'type' => 'select', 'is_responsive' => true ),
        'column_gap' => array( 'type' => 'slider' ),
        'row_gap' => array( 'type' => 'slider' ),
        'field_text_color' => array( 'type' => 'color' ),
        'button_background_color' => array( 'type' => 'color' ),
    ),
);
$provider = static function ( $type, $widget ) use ( $schemas ) {
    return array( 'controls' => array(), 'style_controls' => $schemas[ $type . ':' . $widget ] ?? array() );
};
$mapper = new Design_Core_Elementor_Widget_Control_Mapper( new Design_Core_Elementor_Control_Schema_Registry( $provider ) );
$engine = new Design_Core_Elementor_Mapping_Engine( $mapper );

$base = array(
    'source' => array( 'tag' => 'div', 'classes' => array(), 'attributes' => array(), 'dom_path' => '' ),
    'semantic' => array( 'role' => '', 'component_type' => '', 'confidence' => 1.0 ),
    'content' => array( 'text' => '', 'rich_text' => '', 'link' => array(), 'image' => array(), 'list' => array(), 'fields' => array() ),
    'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'assets' => array(), 'interaction' => array(),
    'component' => array( 'fingerprint' => array( 'version' => 2, 'semantic' => 'element', 'structure' => 'element', 'content_schema' => array(), 'layout' => '', 'interaction' => '' ), 'repeated' => false, 'reusable' => false, 'dynamic' => false, 'content_schema' => array() ),
    'children' => array(),
);
$root = $base;
$root['id'] = 'root'; $root['source']['tag'] = 'section'; $root['source']['dom_path'] = '/section[1]'; $root['source']['classes'] = array( 'why-section', 'canvas' ); $root['source']['attributes']['id'] = 'why';
$root['semantic']['layout_mode'] = 'fullwidth'; $root['semantic']['container_role'] = 'surface';
$root['media'] = array( 'kind' => 'none', 'height_policy' => 'content-driven', 'warnings' => array() );
$root['layout'] = array( 'direction' => 'column' );
$root['style'] = array( 'background' => '#ffffff' );
$root['spacing'] = array( 'padding' => array( 'value' => 40, 'unit' => 'px' ) );
$root['children'] = array( 'heading', 'image', 'form' );
$heading = $base;
$heading['id'] = 'heading'; $heading['source']['tag'] = 'h2'; $heading['source']['dom_path'] = '/section[1]/h2[1]';
$heading['source']['classes'] = array( 'why-heading' );
$heading['content']['text'] = 'Native controls';
$heading['style'] = array( 'color' => '#112233', 'font_size' => array( 'value' => 48, 'unit' => 'px' ) );
$heading['responsive'] = array( 'tablet' => array( 'layout' => array(), 'style' => array( 'font_size' => array( 'value' => 36, 'unit' => 'px' ) ), 'spacing' => array() ) );
$image = $base;
$image['id'] = 'image'; $image['source']['tag'] = 'img'; $image['source']['dom_path'] = '/section[1]/img[1]'; $image['source']['classes'] = array( 'why-icon' );
$image['content']['image'] = array( 'url' => 'https://example.test/uploads/why-icon.webp', 'alt' => '' );
$image['layout']['width'] = array( 'value' => 26, 'unit' => 'px' );
$form = $base;
$form['id'] = 'form'; $form['source']['tag'] = 'form'; $form['source']['dom_path'] = '/section[1]/form[1]'; $form['source']['classes'] = array( 'lead-form' );
$form['content']['fields'] = array( array( 'id' => 'shipment', 'type' => 'text', 'label' => 'Shipment', 'placeholder' => '480kg from Ningbo', 'required' => false, 'width' => '75', 'width_mobile' => '100' ) );
$form['content']['form_name'] = 'Quick enquiry'; $form['content']['button_text'] = 'Send it'; $form['content']['show_labels'] = false; $form['content']['mark_required'] = false; $form['content']['submit_actions'] = array(); $form['content']['input_size'] = 'md';
$form['layout'] = array( 'button_width' => '25', 'column_gap' => array( 'value' => 10, 'unit' => 'px' ), 'row_gap' => array( 'value' => 10, 'unit' => 'px' ) );
$form['style'] = array( 'field_color' => '#ffffff', 'button_background' => '#fbc925' );
$form['responsive'] = array( 'mobile' => array( 'layout' => array( 'button_width' => '100' ), 'style' => array(), 'spacing' => array() ) );
$ir = array( 'schema_version' => 4, 'type' => 'design-ir', 'nodes' => array( $root, $heading, $image, $form ), 'root_ids' => array( 'root' ), 'analysis_quality' => array(), 'tokens' => array(), 'diagnostics' => array() );

$elements = $engine->map_ir( $ir );
dc_assert( 'column' === ( $elements[0]['settings']['flex_direction'] ?? '' ), 'Container settings come from runtime-validated native controls' );
dc_assert( 'full' === ( $elements[0]['settings']['content_width'] ?? '' ), 'Fullwidth surface persists native Elementor Full Width content semantics' );
dc_assert( 'why' === ( $elements[0]['settings']['_element_id'] ?? '' ), 'Container source anchor maps through runtime-validated identity control' );
dc_assert( 'section' === ( $elements[0]['settings']['html_tag'] ?? '' ), 'Container source semantic tag maps through runtime-validated HTML tag control' );
dc_assert( 'why-section canvas' === ( $elements[0]['settings']['css_classes'] ?? '' ), 'Source classes map to the container runtime class control' );
dc_assert( '40' === ( $elements[0]['settings']['padding']['top'] ?? null ), 'Container dimensions persist as native controls' );
dc_assert( '#112233' === ( $elements[0]['elements'][0]['settings']['title_color'] ?? '' ), 'Heading color uses heading-specific control' );
dc_assert( 36 === ( $elements[0]['elements'][0]['settings']['typography_font_size_tablet']['size'] ?? null ), 'Heading responsive typography persists natively' );
dc_assert( 'why-heading' === ( $elements[0]['elements'][0]['settings']['_css_classes'] ?? '' ), 'Widget source classes map through the runtime common control' );
dc_assert( 321 === ( $elements[0]['elements'][1]['settings']['image']['id'] ?? 0 ), 'Local Media Library URL resolves to its attachment ID' );
dc_assert( 'form' === ( $elements[0]['elements'][2]['widgetType'] ?? '' ), 'Semantic form maps to the registered Elementor Pro Form widget' );
dc_assert( 'shipment' === ( $elements[0]['elements'][2]['settings']['form_fields'][0]['custom_id'] ?? '' ) && 'Send it' === ( $elements[0]['elements'][2]['settings']['button_text'] ?? '' ), 'Form content binds to native repeater and submit controls' );
dc_assert( empty( $elements[0]['elements'][0]['settings']['custom_css'] ), 'Native-mappable styles do not create Custom CSS' );
$ir_with_fallback = $ir;
$ir_with_fallback['nodes'][1]['style']['css_fallback'] = array( 'text-wrap' => 'balance' );
$fallback_elements = $engine->map_ir( $ir_with_fallback );
dc_assert( false !== strpos( $fallback_elements[0]['elements'][0]['settings']['custom_css'] ?? '', 'selector {' ), 'Unmappable declarations fall back to Custom CSS on their owning element' );
$report = $engine->mapping_report();
dc_assert( in_array( 'title_color', $report['native_controls'], true ), 'Mapping report records native coverage' );
dc_assert( in_array( 'text-wrap', $report['custom_css_properties'] ?? array(), true ), 'Mapping report distinguishes scoped CSS fallback from native controls' );
dc_assert( in_array( 'fullwidth', $report['layout_modes'] ?? array(), true ), 'Mapping report exposes analyzed section layout modes' );
dc_assert( in_array( 'content-driven', $report['media_height_policies'] ?? array(), true ), 'Mapping report exposes governed media height policies' );

$empty_registry = new Design_Core_Elementor_Control_Schema_Registry( static function () { return array(); } );
$closed_engine = new Design_Core_Elementor_Mapping_Engine( new Design_Core_Elementor_Widget_Control_Mapper( $empty_registry ) );
$closed_failed = false;
try { $closed_engine->map_ir( $ir ); } catch ( UnexpectedValueException $exception ) { $closed_failed = true; }
dc_assert( $closed_failed, 'Missing runtime schema fails closed instead of emitting guessed legacy controls' );
$content_only_ir = $ir;
foreach ( $content_only_ir['nodes'] as &$content_only_node ) {
    $content_only_node['layout'] = array(); $content_only_node['style'] = array(); $content_only_node['spacing'] = array(); $content_only_node['responsive'] = array();
}
unset( $content_only_node );
$content_only_failed = false;
try { $closed_engine->map_ir( $content_only_ir ); } catch ( UnexpectedValueException $exception ) { $content_only_failed = true; }
dc_assert( $content_only_failed, 'Content-only elements also fail closed when their runtime control schemas are unavailable' );

dc_finish( 'Native mapper integration' );

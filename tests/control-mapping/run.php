<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

$mapper_file = DESIGN_CORE_ELEMENTOR_PATH . 'core/control-schema-mapper.php';
$registry_file = DESIGN_CORE_ELEMENTOR_PATH . 'core/control-schema-registry.php';
if ( ! file_exists( $mapper_file ) || ! file_exists( $registry_file ) ) {
    dc_assert( false, 'Control Schema v2 files exist' );
    dc_finish( 'Control mapping' );
}
require_once $mapper_file;
require_once $registry_file;

$provider_calls = 0;
$provider = static function ( $element_type, $widget_type ) use ( &$provider_calls ) {
    $provider_calls++;
    if ( 'widget' !== $element_type || 'heading' !== $widget_type ) { return array(); }
    return array(
        'controls' => array(
            'title' => array( 'type' => 'text', 'default' => 'Heading' ),
            'title_color' => array( 'type' => 'color', 'selectors' => array( '{{WRAPPER}} .title' => 'color: {{VALUE}};' ) ),
            'link' => array( 'type' => 'url' ),
            'items' => array(
                'type' => 'repeater',
                'fields' => array(
                    array( 'name' => 'item_title', 'type' => 'text' ),
                    array( 'name' => 'item_image', 'type' => 'media' ),
                ),
            ),
            '_element_width' => array( 'type' => 'select', 'responsive' => true ),
            'runtime_responsive' => array( 'type' => 'slider', 'responsive' => array(), 'is_responsive' => true ),
        ),
        'style_controls' => array(
            'typography_typography' => array( 'type' => 'popover_toggle' ),
            'typography_font_size' => array( 'type' => 'slider', 'responsive' => true ),
            'typography_font_size_tablet' => array( 'type' => 'slider', 'responsive' => true ),
            'typography_font_size_mobile' => array( 'type' => 'slider', 'responsive' => true ),
        ),
        'common_style_controls' => array(
            'custom_css' => array( 'type' => 'code' ),
        ),
    );
};

$registry = new Design_Core_Elementor_Control_Schema_Registry( $provider );
$schema = $registry->schema( 'widget', 'heading', true );
dc_assert( 2 === $schema['schema_version'], 'Control schema v2 is active' );
dc_assert( isset( $schema['controls']['title_color'] ), 'regular controls are discovered' );
dc_assert( isset( $schema['controls']['typography_font_size'] ), 'optimized style_controls are merged' );
dc_assert( isset( $schema['controls']['custom_css'] ), 'common widget style controls are merged into every widget capability schema' );
dc_assert( true === $registry->is_responsive( 'widget', 'heading', 'typography_font_size' ), 'responsive capability is retained' );
dc_assert( true === $registry->is_responsive( 'widget', 'heading', 'runtime_responsive' ), 'Elementor is_responsive metadata is recognized even when responsive is an empty device array' );
dc_assert( 'title_color' === $registry->first_supported( 'widget', 'heading', array( 'text_color', 'title_color' ), 'color' ), 'semantic alias resolves only to a supported control of expected type' );
dc_assert( '' === $registry->first_supported( 'widget', 'heading', array( 'title_color' ), 'slider' ), 'wrong control type fails closed' );
$registry->schema( 'widget', 'heading' );
dc_assert( 1 === $provider_calls, 'schema is cached per Elementor runtime target' );
dc_assert( '' !== $schema['fingerprint'], 'schema has a deterministic fingerprint' );
dc_assert( in_array( 'typography', $schema['capabilities']['supports'], true ), 'typography capability is inferred from live controls' );
dc_assert( in_array( 'media', $schema['capabilities']['supports'], true ), 'nested repeater media contributes to capability intelligence' );
dc_assert( in_array( 'link', $schema['capabilities']['supports'], true ), 'URL controls contribute to link capability intelligence' );
dc_assert( isset( $schema['json_schema']['properties']['title'] ), 'machine JSON Schema includes content controls' );
dc_assert( 'string' === $schema['json_schema']['properties']['title']['type'], 'text control maps to a string schema' );
dc_assert( 'array' === $schema['json_schema']['properties']['items']['type'], 'repeater maps to an array schema' );
dc_assert( isset( $schema['json_schema']['properties']['items']['items']['properties']['item_image'] ), 'repeater field schema is recursive' );

dc_finish( 'Control mapping' );

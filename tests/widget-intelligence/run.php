<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
dc_require( array( 'core/control-schema-mapper.php', 'core/control-schema-registry.php', 'core/widget-intelligence.php' ) );

class Design_Core_Elementor_Test_CTA_Widget {
    public function get_name() { return 'test-cta'; }
    public function get_title() { return 'Primary CTA Button'; }
    public function get_icon() { return 'eicon-button'; }
    public function get_categories() { return array( 'basic' ); }
    public function get_keywords() { return array( 'button', 'cta', 'action' ); }
    public function get_style_depends() { return array( 'test-cta' ); }
    public function get_script_depends() { return array(); }
    public function get_help_url() { return 'https://example.test/help'; }
}

$schema_provider = static function ( $element_type, $widget_type ) {
    if ( 'widget' !== $element_type || 'test-cta' !== $widget_type ) { return array(); }
    return array(
        'controls' => array(
            'text' => array( 'type' => 'text', 'label' => 'Text', 'default' => 'Click here' ),
            'link' => array( 'type' => 'url', 'label' => 'Link' ),
            'align' => array( 'type' => 'choose', 'responsive' => true, 'options' => array( 'left' => 'Left', 'center' => 'Center', 'right' => 'Right' ) ),
        ),
        'style_controls' => array(
            'background_color' => array( 'type' => 'color', 'label' => 'Background' ),
            'hover_color' => array( 'type' => 'color', 'label' => 'Hover color' ),
            'typography_font_size' => array( 'type' => 'slider', 'responsive' => true ),
        ),
    );
};
$widget = new Design_Core_Elementor_Test_CTA_Widget();
$widget_provider = static function () use ( $widget ) { return array( 'test-cta' => $widget ); };
$registry = new Design_Core_Elementor_Control_Schema_Registry( $schema_provider );
$service = new Design_Core_Elementor_Widget_Intelligence( $registry, $widget_provider );

$catalog = $service->catalog();
dc_assert( 1 === $catalog['counts']['all'], 'runtime widget catalog counts registered widgets' );
dc_assert( 1 === $catalog['counts']['design_core'], 'runtime class source is classified deterministically' );
dc_assert( isset( $catalog['widgets']['test-cta'] ), 'catalog is keyed by the live widget name' );
$summary = $catalog['widgets']['test-cta'];
dc_assert( 'action' === $summary['semantic']['primary_intent'], 'semantic intent is derived from runtime widget evidence' );
dc_assert( in_array( 'link', $summary['capabilities']['supports'], true ), 'live URL control yields link capability' );
dc_assert( in_array( 'color', $summary['capabilities']['supports'], true ), 'live color controls yield color capability' );
dc_assert( in_array( 'responsive', $summary['capabilities']['supports'], true ), 'responsive controls yield responsive capability' );
dc_assert( '' !== $summary['schema_fingerprint'], 'widget summary carries schema fingerprint' );

$detail = $service->inspect( 'test-cta' );
dc_assert( ! is_wp_error( $detail ), 'registered widget detail resolves' );
dc_assert( isset( $detail['json_schema']['properties']['text'] ), 'detail exposes machine-readable JSON Schema' );
dc_assert( isset( $detail['json_schema']['properties']['link'] ), 'machine schema exposes link contract' );
dc_assert( count( $detail['controls'] ) >= 6, 'detail exposes unified control rows' );

$candidates = $service->find_candidates(
    array(
        'intent' => 'action',
        'capabilities' => array( 'link', 'color' ),
        'controls' => array( 'text' ),
        'keywords' => array( 'cta' ),
    ),
    5
);
dc_assert( 1 === count( $candidates ), 'candidate finder returns compatible runtime widget' );
dc_assert( 'test-cta' === $candidates[0]['name'], 'candidate finder ranks the verified widget first' );
dc_assert( 1.0 === (float) $candidates[0]['score'], 'fully matched requirements receive a deterministic score of 1' );

dc_finish( 'Widget intelligence' );

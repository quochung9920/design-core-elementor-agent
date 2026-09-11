<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Build canonical IR for the post-comparison conversion CTA. */
function design_core_air_conversion_ir() {
    $base = static function ( $id, $tag = 'div', $classes = array(), $attributes = array() ) {
        return array(
            'id' => $id,
            'source' => array( 'tag' => $tag, 'classes' => array_values( $classes ), 'attributes' => $attributes, 'dom_path' => '/section[@class="conversion"]/' . $id ),
            'semantic' => array( 'role' => '', 'component_type' => '', 'confidence' => 1.0, 'layout_mode' => 'unknown', 'container_role' => '', 'width_policy' => 'fluid', 'fixed_width_violation' => false ),
            'content' => array( 'text' => '', 'rich_text' => '', 'link' => array(), 'image' => array(), 'list' => array(), 'fields' => array() ),
            'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'assets' => array(), 'interaction' => array(),
            'component' => array(
                'fingerprint' => array( 'version' => 2, 'semantic' => 'air-consolidation-conversion', 'structure' => $tag, 'content_schema' => array(), 'layout' => '', 'interaction' => '' ),
                'repeated' => false, 'reusable' => false, 'dynamic' => false, 'content_schema' => array(),
            ),
            'children' => array(),
        );
    };
    $dimension = static function ( $value, $unit = 'px' ) { return array( 'value' => $value, 'unit' => $unit ); };
    $dimensions = static function ( $top, $right, $bottom, $left, $unit = 'px' ) { return array( 'top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left, 'unit' => $unit ); };
    $state = static function ( $layout = array(), $style = array(), $spacing = array() ) { return array( 'layout' => $layout, 'style' => $style, 'spacing' => $spacing ); };
    $nodes = array();

    $root = $base( 'conversion-root', 'section', array( 'dc-conversion-section' ), array( 'id' => 'conversion-band' ) );
    $root['semantic']['role'] = 'conversion-cta';
    $root['semantic']['layout_mode'] = 'fullwidth';
    $root['semantic']['container_role'] = 'surface';
    $root['layout'] = array( 'content_width' => 'full', 'direction' => 'column', 'align' => 'center', 'width' => $dimension( 100, '%' ) );
    $root['style'] = array( 'background' => '#131417' );
    $root['spacing'] = array( 'padding' => $dimensions( 96, 0, 96, 0 ) );
    $root['responsive'] = array(
        'tablet' => $state( array(), array(), array( 'padding' => $dimensions( 76, 0, 76, 0 ) ) ),
        'mobile' => $state( array(), array(), array( 'padding' => $dimensions( 64, 0, 64, 0 ) ) ),
    );
    $root['media'] = array( 'kind' => 'none', 'height_policy' => 'content-driven', 'warnings' => array() );
    $root['children'] = array( 'conversion-container' );
    $nodes[] = $root;

    $container = $base( 'conversion-container', 'div', array( 'dc-conversion-container' ) );
    $container['semantic']['layout_mode'] = 'boxed';
    $container['semantic']['container_role'] = 'content';
    $container['layout'] = array( 'content_width' => 'boxed', 'direction' => 'row', 'justify' => 'space-between', 'align' => 'center', 'gap' => $dimension( 48 ) );
    $container['responsive'] = array(
        'tablet' => $state( array( 'direction' => 'column', 'align' => 'stretch', 'gap' => $dimension( 42 ) ) ),
        'mobile' => $state( array( 'direction' => 'column', 'align' => 'stretch', 'gap' => $dimension( 14 ) ) ),
    );
    $container['children'] = array( 'conversion-copy', 'conversion-form-wrap' );
    $nodes[] = $container;

    $copy = $base( 'conversion-copy', 'div', array( 'dc-conversion-copy' ) );
    $copy['layout'] = array( 'content_width' => 'full', 'direction' => 'column', 'width' => $dimension( 54, '%' ), 'gap' => $dimension( 10 ) );
    $copy['responsive'] = array(
        'tablet' => $state( array( 'width' => $dimension( 100, '%' ) ) ),
        'mobile' => $state( array( 'width' => $dimension( 100, '%' ) ) ),
    );
    $copy['spacing'] = array( 'padding' => $dimensions( 0, 0, 0, 0 ) );
    $copy['children'] = array( 'conversion-title', 'conversion-lead' );
    $nodes[] = $copy;

    $title = $base( 'conversion-title', 'h2', array( 'dc-conversion-title' ) );
    $title['content']['text'] = 'Not sure this is the right fit?';
    $title['style'] = array(
        'color' => '#ffffff', 'font_family' => 'Geist', 'font_weight' => '600',
        'font_size' => $dimension( 40 ), 'line_height' => $dimension( 1.06, 'em' ), 'letter_spacing' => $dimension( -2.6 ),
    );
    $title['responsive'] = array( 'mobile' => $state( array(), array( 'font_size' => $dimension( 22 ), 'letter_spacing' => $dimension( -0.396 ) ) ) );
    $nodes[] = $title;

    $lead_copy = 'Send us the weight, the origin and the deadline. If a direct uplift is better for you, we will say so.';
    $lead = $base( 'conversion-lead', 'p', array( 'dc-conversion-lead' ) );
    $lead['content']['text'] = $lead_copy;
    $lead['content']['rich_text'] = esc_html( $lead_copy );
    $lead['style'] = array( 'color' => '#b9bcc5', 'font_family' => 'Poppins', 'font_weight' => '400', 'font_size' => $dimension( 15.5 ), 'line_height' => $dimension( 1 / 23, 'em' ) );
    $lead['style']['line_height'] = $dimension( 1.68, 'em' );
    $lead['responsive'] = array( 'mobile' => $state( array(), array( 'font_size' => $dimension( 13.5 ) ) ) );
    $nodes[] = $lead;

    $form_wrap = $base( 'conversion-form-wrap', 'div', array( 'dc-conversion-form-wrap' ) );
    $form_wrap['layout'] = array( 'content_width' => 'full', 'direction' => 'column', 'width' => $dimension( 40, '%' ) );
    $form_wrap['responsive'] = array(
        'tablet' => $state( array( 'width' => $dimension( 100, '%' ) ) ),
        'mobile' => $state( array( 'width' => $dimension( 100, '%' ) ) ),
    );
    $form_wrap['spacing'] = array( 'padding' => $dimensions( 0, 0, 0, 0 ) );
    $form_wrap['children'] = array( 'conversion-form' );
    $nodes[] = $form_wrap;

    $form = $base( 'conversion-form', 'form', array( 'dc-conversion-form' ) );
    $form['semantic']['role'] = 'lead-capture';
    $form['content']['fields'] = array(
        array(
            'id' => 'shipment', 'type' => 'text', 'label' => 'Shipment details',
            'placeholder' => '480kg from Ningbo, needed Friday', 'required' => false,
            'width' => '75', 'width_tablet' => '75', 'width_mobile' => '100',
        ),
    );
    $form['content']['button_text'] = 'Send it';
    $form['content']['form_name'] = 'Air consolidation quick enquiry';
    $form['content']['show_labels'] = false;
    $form['content']['mark_required'] = false;
    $form['content']['submit_actions'] = array();
    $form['content']['input_size'] = 'md';
    $form['layout'] = array( 'column_gap' => $dimension( 10 ), 'row_gap' => $dimension( 10 ), 'button_width' => '25' );
    $form['responsive'] = array(
        'tablet' => $state( array( 'button_width' => '25' ) ),
        'mobile' => $state( array( 'button_width' => '100' ) ),
    );
    $form['style'] = array(
        'field_color' => '#ffffff', 'field_background' => 'rgba(255,255,255,0.12)', 'field_border_color' => '#ffffff',
        'field_border_width' => $dimensions( 1, 1, 1, 1 ), 'field_radius' => $dimensions( 6, 6, 6, 6 ),
        'field_font_family' => 'Poppins', 'field_font_size' => $dimension( 13.5 ), 'field_font_weight' => '400',
        'button_background' => '#fbc925', 'button_color' => '#131417', 'button_radius' => $dimensions( 6, 6, 6, 6 ),
        'button_padding' => $dimensions( 14, 26, 14, 26 ), 'button_font_family' => 'Poppins',
        'button_font_size' => $dimension( 13.5 ), 'button_font_weight' => '600',
    );
    $nodes[] = $form;

    return array(
        'schema_version' => Design_Core_Elementor_Design_IR::SCHEMA_VERSION,
        'type' => 'design-ir', 'nodes' => $nodes, 'root_ids' => array( 'conversion-root' ),
        'analysis_quality' => array( 'source' => 'high-fidelity-static-authority', 'section' => 'conversion' ),
        'tokens' => array(), 'diagnostics' => array(),
    );
}

if ( defined( 'WP_CLI' ) && WP_CLI && ! defined( 'DESIGN_CORE_AIR_CONVERSION_BLUEPRINT_ONLY' ) ) {
    $post_id = 2;
    $ir = design_core_air_conversion_ir();
    ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
    $engine = new Design_Core_Elementor_Mapping_Engine();
    $mapped = $engine->map_ir( $ir );
    if ( 1 !== count( $mapped ) ) { throw new RuntimeException( 'The conversion mapper did not produce exactly one root.' ); }

    $stored = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
    if ( ! is_array( $stored ) || count( $stored ) < 4 ) { throw new RuntimeException( 'The existing Elementor document is unavailable or incomplete.' ); }
    $next = array(); $inserted = false; $untouched_hashes = array();
    foreach ( $stored as $element ) {
        $settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
        $classes = ' ' . (string) ( $settings['css_classes'] ?? $settings['_css_classes'] ?? '' ) . ' ';
        $is_conversion = 'conversion-band' === ( $settings['_element_id'] ?? '' ) || false !== strpos( $classes, ' dc-conversion-section ' );
        if ( $is_conversion ) {
            if ( ! $inserted ) { $next[] = $mapped[0]; $inserted = true; }
            continue;
        }
        $next[] = $element;
        $untouched_hashes[] = hash( 'sha256', wp_json_encode( $element ) );
        if ( ! $inserted && ( 'compare' === ( $settings['_element_id'] ?? '' ) || false !== strpos( $classes, ' dc-compare-section ' ) ) ) {
            $next[] = $mapped[0]; $inserted = true;
        }
    }
    if ( ! $inserted ) { $next[] = $mapped[0]; }

    $adapter = new Design_Core_Elementor_V3_Adapter();
    $saved = $adapter->save_page( $post_id, $next, array() );
    if ( is_wp_error( $saved ) ) { throw new RuntimeException( $saved->get_error_message() ); }
    if ( class_exists( '\Elementor\Plugin' ) ) { \Elementor\Plugin::$instance->files_manager->clear_cache(); }

    $reloaded = $adapter->reload( $post_id );
    $conversion = null; $reloaded_untouched = array();
    foreach ( $reloaded as $element ) {
        $settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
        $classes = ' ' . (string) ( $settings['css_classes'] ?? $settings['_css_classes'] ?? '' ) . ' ';
        if ( 'conversion-band' === ( $settings['_element_id'] ?? '' ) || false !== strpos( $classes, ' dc-conversion-section ' ) ) { $conversion = $element; continue; }
        $reloaded_untouched[] = hash( 'sha256', wp_json_encode( $element ) );
    }
    if ( $untouched_hashes !== $reloaded_untouched ) { throw new RuntimeException( 'Provisioning conversion changed an out-of-scope root.' ); }
    if ( ! is_array( $conversion ) ) { throw new RuntimeException( 'The persisted conversion CTA is missing.' ); }

    $form_count = 0; $walk = static function ( $element ) use ( &$walk, &$form_count ) {
        if ( 'widget' === ( $element['elType'] ?? '' ) && 'form' === ( $element['widgetType'] ?? '' ) ) { $form_count++; }
        foreach ( $element['elements'] ?? array() as $child ) { $walk( $child ); }
    };
    $walk( $conversion );
    if ( 1 !== $form_count ) { throw new RuntimeException( 'The conversion CTA did not persist exactly one native Form widget.' ); }
    $rendered = $adapter->render( $post_id );
    if ( false === strpos( $rendered, 'Not sure this is the right fit?' ) || false === strpos( $rendered, '480kg from Ningbo, needed Friday' ) ) {
        throw new RuntimeException( 'The conversion CTA did not render its persisted content.' );
    }

    WP_CLI::print_value( array(
        'status' => 'pass', 'post_id' => $post_id, 'root_count' => count( $reloaded ),
        'untouched_root_hashes_verified' => count( $untouched_hashes ), 'native_form_count' => $form_count,
        'mapping_report' => $engine->mapping_report(), 'rendered_bytes' => strlen( $rendered ),
    ) );
}

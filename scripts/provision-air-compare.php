<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function design_core_air_compare_tokens() {
    return array(
        'colors' => array(
            'ink' => '#131417', 'copy' => '#5b5955', 'canvas' => '#f4f3f0',
            'line' => '#e2e0db', 'paper' => '#ffffff', 'muted' => '#85827c',
        ),
        'typography' => array(
            'section-heading' => array( 'font_family' => 'Geist', 'font_weight' => '600', 'font_size' => '34', 'line_height' => '1.1', 'letter_spacing' => '-2', 'font_size_tablet' => '26', 'letter_spacing_tablet' => '-1.6', 'font_size_mobile' => '26', 'line_height_mobile' => '1.12', 'letter_spacing_mobile' => '-1.6' ),
            'section-lead' => array( 'font_family' => 'Poppins', 'font_weight' => '400', 'font_size' => '16', 'line_height' => '1.72', 'font_size_tablet' => '14.5', 'font_size_mobile' => '14.5' ),
            'compare-cell' => array( 'font_family' => 'Poppins', 'font_weight' => '400', 'font_size' => '14.5', 'line_height' => '1.45' ),
            'compare-card-title' => array( 'font_family' => 'Geist', 'font_weight' => '600', 'font_size' => '17', 'line_height' => '1.2' ),
            'compare-card-value' => array( 'font_family' => 'Poppins', 'font_weight' => '400', 'font_size' => '13', 'line_height' => '1.2' ),
        ),
    );
}

function design_core_air_compare_ir() {
    $base = static function ( $id, $tag = 'div', $classes = array(), $attributes = array() ) {
        return array(
            'id' => $id,
            'source' => array( 'tag' => $tag, 'classes' => array_values( $classes ), 'attributes' => $attributes, 'dom_path' => '/section[@id="compare"]/' . $id ),
            'semantic' => array( 'role' => '', 'component_type' => '', 'confidence' => 1.0, 'layout_mode' => 'unknown', 'container_role' => '', 'width_policy' => 'fluid', 'fixed_width_violation' => false ),
            'content' => array( 'text' => '', 'rich_text' => '', 'link' => array(), 'image' => array(), 'list' => array(), 'fields' => array() ),
            'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'assets' => array(), 'interaction' => array(),
            'component' => array(
                'fingerprint' => array( 'version' => 2, 'semantic' => 'air-consolidation-compare', 'structure' => $tag, 'content_schema' => array(), 'layout' => '', 'interaction' => '' ),
                'repeated' => false, 'reusable' => false, 'dynamic' => false, 'content_schema' => array(),
            ),
            'children' => array(),
        );
    };
    $dimensions = static function ( $top, $right, $bottom, $left, $unit = 'px' ) { return array( 'top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left, 'unit' => $unit ); };
    $dimension = static function ( $value, $unit = 'px' ) { return array( 'value' => $value, 'unit' => $unit ); };
    $state = static function ( $layout = array(), $style = array(), $spacing = array() ) { return array( 'layout' => $layout, 'style' => $style, 'spacing' => $spacing ); };
    $nodes = array();
    $add = static function ( $node ) use ( &$nodes ) { $nodes[] = $node; };
    $text_node = static function ( $id, $tag, $text, $classes = array() ) use ( $base ) {
        $node = $base( $id, $tag, $classes );
        $node['content']['text'] = $text;
        $node['content']['rich_text'] = esc_html( $text );
        return $node;
    };

    $root = $base( 'compare-root', 'section', array( 'dc-compare-section' ), array( 'id' => 'compare' ) );
    $root['semantic']['role'] = 'comparison-section';
    $root['semantic']['layout_mode'] = 'fullwidth';
    $root['semantic']['container_role'] = 'surface';
    $root['layout'] = array( 'content_width' => 'full', 'direction' => 'column', 'align' => 'center', 'width' => $dimension( 100, '%' ) );
    $root['style'] = array( 'background' => '#ffffff' );
    $root['spacing'] = array( 'padding' => $dimensions( 110, 0, 110, 0 ) );
    $root['responsive'] = array(
        'tablet' => $state( array(), array(), array( 'padding' => $dimensions( 76, 0, 76, 0 ) ) ),
        'mobile' => $state( array(), array(), array( 'padding' => $dimensions( 64, 0, 64, 0 ) ) ),
    );
    $root['media'] = array( 'kind' => 'none', 'height_policy' => 'content-driven', 'warnings' => array() );
    $root['children'] = array( 'compare-container' );
    $add( $root );

    $container = $base( 'compare-container', 'div', array( 'dc-compare-container' ) );
    $container['semantic']['layout_mode'] = 'boxed';
    $container['semantic']['container_role'] = 'content';
    $container['layout'] = array( 'content_width' => 'boxed', 'direction' => 'column', 'gap' => $dimension( 44 ) );
    $container['style'] = array( 'css_fallback' => array( 'padding-block' => '0px' ) );
    $container['responsive'] = array( 'mobile' => $state( array( 'gap' => $dimension( 32 ) ) ) );
    $container['children'] = array( 'compare-intro', 'compare-body' );
    $add( $container );

    $intro = $base( 'compare-intro', 'div', array( 'dc-compare-intro' ) );
    $intro['layout'] = array( 'content_width' => 'full', 'direction' => 'column', 'width' => $dimension( 100, '%' ), 'gap' => $dimension( 16 ) );
    $intro['spacing'] = array( 'padding' => $dimensions( 0, 0, 0, 0 ) );
    $intro['responsive'] = array( 'mobile' => $state( array( 'gap' => $dimension( 14 ) ) ) );
    $intro['children'] = array( 'compare-title', 'compare-lead' );
    $add( $intro );

    $title = $text_node( 'compare-title', 'h2', 'How it compares.', array( 'dc-compare-title' ) );
    $title['style'] = array( 'color' => '#131417', 'font_family' => 'Geist', 'font_weight' => '600', 'font_size' => $dimension( 34 ), 'line_height' => $dimension( 1.1, 'em' ), 'letter_spacing' => $dimension( -2 ) );
    $title['responsive'] = array(
        'tablet' => $state( array(), array( 'font_size' => $dimension( 26 ), 'letter_spacing' => $dimension( -1.6 ) ) ),
        'mobile' => $state( array(), array( 'font_size' => $dimension( 26 ), 'line_height' => $dimension( 1.12, 'em' ), 'letter_spacing' => $dimension( -1.6 ) ) ),
    );
    $add( $title );

    $lead_copy = 'Four ways to bring freight into Australia. The right one depends on how much you are moving and how soon you need it.';
    $lead = $text_node( 'compare-lead', 'p', $lead_copy, array( 'dc-compare-lead' ) );
    $lead['layout'] = array( 'max_width' => $dimension( 760 ) );
    $lead['style'] = array( 'color' => '#5b5955', 'font_family' => 'Poppins', 'font_weight' => '400', 'font_size' => $dimension( 16 ), 'line_height' => $dimension( 1.72, 'em' ) );
    $lead['responsive'] = array(
        'tablet' => $state( array(), array( 'font_size' => $dimension( 14.5 ) ) ),
        'mobile' => $state( array(), array( 'font_size' => $dimension( 14.5 ) ) ),
    );
    $add( $lead );

    $body = $base( 'compare-body', 'div', array( 'dc-compare-body' ) );
    $body['layout'] = array( 'content_width' => 'full', 'direction' => 'column', 'width' => $dimension( 100, '%' ), 'gap' => $dimension( 26 ) );
    $body['spacing'] = array( 'padding' => $dimensions( 0, 0, 0, 0 ) );
    $body['responsive'] = array( 'mobile' => $state( array( 'gap' => $dimension( 24 ) ) ) );
    $body['children'] = array( 'compare-table', 'compare-cards', 'compare-note' );
    $add( $body );

    $table_data = array(
        array( 'Express courier', 'Highest per kg', 'Fastest', 'Cartons and samples, under a pallet' ),
        array( 'Air consolidation', 'Middle', '3 to 8 days airport to airport', 'Pallet-sized freight that cannot wait for sea' ),
        array( 'Direct air uplift', 'High', 'Fastest available air', 'Large or deadline-critical shipments' ),
        array( 'Sea freight', 'Lowest per kg', 'Weeks, not days', 'Volume you can plan ahead' ),
    );
    $column_widths = array( 20.0, 16.5, 26.0, 37.5 );

    $table = $base( 'compare-table', 'div', array( 'dc-compare-table' ) );
    $table['semantic']['role'] = 'comparison-table';
    $table['semantic']['hidden_on'] = array( 'mobile' );
    $table['layout'] = array( 'content_width' => 'full', 'direction' => 'column', 'width' => $dimension( 100, '%' ), 'gap' => $dimension( 0 ) );
    $table['spacing'] = array( 'padding' => $dimensions( 0, 0, 0, 0 ) );
    $table['children'] = array( 'compare-header-row', 'compare-row-1', 'compare-row-2', 'compare-row-3', 'compare-row-4' );
    $add( $table );

    $header = $base( 'compare-header-row', 'div', array( 'dc-compare-row', 'dc-compare-header-row' ) );
    $header['layout'] = array( 'content_width' => 'full', 'direction' => 'row', 'align' => 'end', 'width' => $dimension( 100, '%' ), 'gap' => $dimension( 0 ) );
    $header['style'] = array( 'border_style' => 'solid', 'border_color' => '#131417', 'border_width' => $dimensions( 0, 0, 1, 0 ) );
    $header['spacing'] = array( 'padding' => $dimensions( 0, 0, 0, 0 ) );
    $header['children'] = array();
    foreach ( array( '', 'RELATIVE COST', 'SPEED', 'BEST FOR' ) as $index => $label ) {
        $cell_id = 'compare-header-cell-' . ( $index + 1 );
        $cell = $text_node( $cell_id, 'p', $label, array( 'dc-compare-header-cell' ) );
        $cell['layout'] = array( 'max_width' => $dimension( $column_widths[ $index ], '%' ) );
        $cell['style'] = array( 'color' => '#85827c', 'font_family' => 'Poppins', 'font_weight' => '600', 'font_size' => $dimension( 10 ), 'line_height' => $dimension( 1.2, 'em' ), 'letter_spacing' => $dimension( 1.4 ) );
        $cell['spacing'] = array( 'padding' => $dimensions( 0, 20, 14, 20 ) );
        $header['children'][] = $cell_id;
        $add( $cell );
    }
    $add( $header );

    foreach ( $table_data as $row_index => $values ) {
        $number = $row_index + 1;
        $row_id = 'compare-row-' . $number;
        $row = $base( $row_id, 'div', array( 'dc-compare-row', 'dc-compare-row-' . $number ) );
        $row['layout'] = array( 'content_width' => 'full', 'direction' => 'row', 'align' => 'center', 'width' => $dimension( 100, '%' ), 'gap' => $dimension( 0 ) );
        $row['style'] = array( 'border_style' => 'solid', 'border_color' => '#e2e0db', 'border_width' => $dimensions( 0, 0, 1, 0 ) );
        $row['spacing'] = array( 'padding' => $dimensions( 0, 0, 0, 0 ) );
        if ( 2 === $number ) { $row['style']['background'] = '#f4f3f0'; }
        $row['children'] = array();
        foreach ( $values as $cell_index => $value ) {
            $cell_id = $row_id . '-cell-' . ( $cell_index + 1 );
            $cell = $text_node( $cell_id, 'p', $value, array( 'dc-compare-cell' ) );
            $cell['layout'] = array( 'max_width' => $dimension( $column_widths[ $cell_index ], '%' ) );
            $cell['style'] = array( 'color' => 0 === $cell_index ? '#131417' : '#5b5955', 'font_family' => 'Poppins', 'font_weight' => 0 === $cell_index ? '600' : '400', 'font_size' => $dimension( 0 === $cell_index ? 15.5 : 14.5 ), 'line_height' => $dimension( 1.45, 'em' ) );
            $cell['spacing'] = array( 'padding' => $dimensions( 22, 20, 22, 20 ) );
            $row['children'][] = $cell_id;
            $add( $cell );
        }
        $add( $row );
    }

    $cards = $base( 'compare-cards', 'div', array( 'dc-compare-cards' ) );
    $cards['semantic']['role'] = 'comparison-list';
    $cards['semantic']['hidden_on'] = array( 'desktop', 'laptop', 'tablet' );
    $cards['layout'] = array( 'content_width' => 'full', 'direction' => 'column', 'width' => $dimension( 100, '%' ), 'gap' => $dimension( 0 ) );
    $cards['spacing'] = array( 'padding' => $dimensions( 0, 0, 0, 0 ) );
    $cards['children'] = array( 'compare-card-1', 'compare-card-2', 'compare-card-3', 'compare-card-4' );
    $add( $cards );

    foreach ( $table_data as $index => $values ) {
        $number = $index + 1;
        $card_id = 'compare-card-' . $number;
        $card = $base( $card_id, 'article', array( 'dc-compare-card', 'dc-compare-card-' . $number ) );
        $card['semantic']['role'] = 'comparison-card';
        $card['semantic']['component_type'] = 'comparison-card';
        $card['component']['repeated'] = true;
        $card['layout'] = array( 'content_width' => 'full', 'direction' => 'column', 'width' => $dimension( 100, '%' ), 'gap' => $dimension( 10 ) );
        $card['style'] = array( 'border_style' => 'solid', 'border_color' => '#e2e0db', 'border_width' => $dimensions( 1, 0, 0, 0 ) );
        if ( 2 === $number ) { $card['style']['background'] = '#f4f3f0'; }
        $card['spacing'] = array( 'padding' => $dimensions( 18, 16, 18, 16 ) );
        $card['children'] = array( $card_id . '-title', $card_id . '-details' );
        $add( $card );

        $card_title = $text_node( $card_id . '-title', 'h3', $values[0], array( 'dc-compare-card-title' ) );
        $card_title['style'] = array( 'color' => '#131417', 'font_family' => 'Geist', 'font_weight' => '600', 'font_size' => $dimension( 17 ), 'line_height' => $dimension( 22 / 17, 'em' ) );
        $add( $card_title );

        $details = $base( $card_id . '-details', 'div', array( 'dc-compare-card-details' ) );
        $details['layout'] = array( 'content_width' => 'full', 'direction' => 'column', 'width' => $dimension( 100, '%' ), 'gap' => $dimension( 10 ) );
        $details['spacing'] = array( 'padding' => $dimensions( 0, 0, 0, 0 ) );
        $details['children'] = array( $card_id . '-meta', $card_id . '-best' );
        $add( $details );

        $meta = $base( $card_id . '-meta', 'div', array( 'dc-compare-meta' ) );
        $meta['layout'] = array( 'content_width' => 'full', 'direction' => 'row', 'width' => $dimension( 100, '%' ), 'gap' => $dimension( 20 ) );
        $meta['spacing'] = array( 'padding' => $dimensions( 0, 0, 0, 0 ) );
        $meta['children'] = array( $card_id . '-cost', $card_id . '-speed' );
        $add( $meta );

        foreach ( array( 'cost' => array( 'RELATIVE COST', $values[1] ), 'speed' => array( 'SPEED', $values[2] ) ) as $key => $pair ) {
            $group_id = $card_id . '-' . $key;
            $group = $base( $group_id, 'div', array( 'dc-compare-meta-group' ) );
            $group['layout'] = array( 'content_width' => 'full', 'direction' => 'column', 'width' => $dimension( 46.85, '%' ), 'gap' => $dimension( 3 ) );
            $group['responsive'] = array( 'mobile' => $state( array( 'width' => $dimension( 46.85, '%' ) ) ) );
            $group['spacing'] = array( 'padding' => $dimensions( 0, 0, 0, 0 ) );
            $group['children'] = array( $group_id . '-label', $group_id . '-value' );
            $add( $group );

            $label = $text_node( $group_id . '-label', 'p', $pair[0], array( 'dc-compare-meta-label' ) );
            $label['style'] = array( 'color' => '#85827c', 'font_family' => 'Poppins', 'font_weight' => '600', 'font_size' => $dimension( 9 ), 'line_height' => $dimension( 13 / 9, 'em' ), 'letter_spacing' => $dimension( 1.2 ) );
            $add( $label );

            $value = $text_node( $group_id . '-value', 'p', $pair[1], array( 'dc-compare-meta-value' ) );
            $value['style'] = array( 'color' => '#5b5955', 'font_family' => 'Poppins', 'font_weight' => '400', 'font_size' => $dimension( 13 ), 'line_height' => $dimension( 20 / 13, 'em' ) );
            $add( $value );
        }

        $best_id = $card_id . '-best';
        $best = $base( $best_id, 'div', array( 'dc-compare-best' ) );
        $best['layout'] = array( 'content_width' => 'full', 'direction' => 'column', 'width' => $dimension( 100, '%' ), 'gap' => $dimension( 3 ) );
        $best['spacing'] = array( 'padding' => $dimensions( 0, 0, 0, 0 ) );
        $best['children'] = array( $best_id . '-label', $best_id . '-value' );
        $add( $best );

        $best_label = $text_node( $best_id . '-label', 'p', 'BEST FOR', array( 'dc-compare-meta-label' ) );
        $best_label['style'] = array( 'color' => '#85827c', 'font_family' => 'Poppins', 'font_weight' => '600', 'font_size' => $dimension( 9 ), 'line_height' => $dimension( 13 / 9, 'em' ), 'letter_spacing' => $dimension( 1.2 ) );
        $add( $best_label );

        $best_value = $text_node( $best_id . '-value', 'p', $values[3], array( 'dc-compare-meta-value' ) );
        $best_value['style'] = array( 'color' => '#5b5955', 'font_family' => 'Poppins', 'font_weight' => '400', 'font_size' => $dimension( 13 ), 'line_height' => $dimension( 20 / 13, 'em' ) );
        $add( $best_value );
    }

    $note_copy = 'Close to the line? Send us the weight and the deadline. We price consolidation and a direct uplift, and recommend the cheaper one.';
    $note = $text_node( 'compare-note', 'p', $note_copy, array( 'dc-compare-note' ) );
    $note['layout'] = array( 'max_width' => $dimension( 700 ) );
    $note['style'] = array( 'color' => '#5b5955', 'font_family' => 'Poppins', 'font_weight' => '400', 'font_size' => $dimension( 14.5 ), 'line_height' => $dimension( 1.68, 'em' ) );
    $note['responsive'] = array( 'mobile' => $state( array(), array( 'font_size' => $dimension( 13.5 ) ) ) );
    $add( $note );

    return array(
        'schema_version' => Design_Core_Elementor_Design_IR::SCHEMA_VERSION,
        'type' => 'design-ir', 'nodes' => $nodes, 'root_ids' => array( 'compare-root' ),
        'analysis_quality' => array( 'source' => 'high-fidelity-static-authority', 'section' => 'compare', 'viewports' => array( 1440, 1366, 1024, 767, 390 ) ),
        'tokens' => array(), 'diagnostics' => array(),
    );
}

if ( defined( 'WP_CLI' ) && WP_CLI && ! defined( 'DESIGN_CORE_AIR_COMPARE_BLUEPRINT_ONLY' ) ) {
    $post_id = 2;
    $token_service = new Design_Core_Elementor_Design_Token_Service();
    $token_service->save( array_replace_recursive( $token_service->all(), design_core_air_compare_tokens() ) );
    $global_bridge = new Design_Core_Elementor_Global_Style_Bridge( $token_service );
    $global_sync = $global_bridge->sync_v3_kit();
    if ( is_wp_error( $global_sync ) || 'synced' !== ( $global_sync['status'] ?? '' ) ) { throw new RuntimeException( is_wp_error( $global_sync ) ? $global_sync->get_error_message() : 'Elementor global sync failed.' ); }

    $ir = design_core_air_compare_ir();
    ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
    $engine = new Design_Core_Elementor_Mapping_Engine();
    $mapped = $engine->map_ir( $ir );
    $report = $engine->mapping_report();
    if ( ! empty( $report['unsupported'] ) || 1 !== count( $mapped ) ) { throw new RuntimeException( 'The compare section did not map completely to one Elementor root.' ); }
    $mapped = $global_bridge->apply_v3_references( $mapped, $global_sync );

    $stored = json_decode( get_post_meta( $post_id, '_elementor_data', true ), true );
    if ( ! is_array( $stored ) || count( $stored ) < 3 ) { throw new RuntimeException( 'The existing Elementor document is unavailable or incomplete.' ); }
    $untouched = array();
    foreach ( $stored as $element ) {
        $settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
        $classes = (string) ( $settings['css_classes'] ?? $settings['_css_classes'] ?? '' );
        if ( 'compare' === ( $settings['_element_id'] ?? '' ) || false !== strpos( ' ' . $classes . ' ', ' dc-compare-section ' ) ) { continue; }
        $untouched[] = $element;
    }
    $untouched_hashes = array_map( static function ( $element ) { return hash( 'sha256', wp_json_encode( $element ) ); }, $untouched );
    $next = array_merge( $untouched, array( $mapped[0] ) );

    $adapter = new Design_Core_Elementor_V3_Adapter();
    $saved = $adapter->save_page( $post_id, $next, array() );
    if ( is_wp_error( $saved ) ) { throw new RuntimeException( $saved->get_error_message() ); }
    if ( class_exists( '\Elementor\Plugin' ) ) { \Elementor\Plugin::$instance->files_manager->clear_cache(); }

    $reloaded = $adapter->reload( $post_id );
    if ( ! is_array( $reloaded ) || count( $untouched ) + 1 !== count( $reloaded ) ) { throw new RuntimeException( 'The Elementor document did not persist the expected compare root count.' ); }
    $reloaded_untouched_hashes = array_map( static function ( $element ) { return hash( 'sha256', wp_json_encode( $element ) ); }, array_slice( $reloaded, 0, count( $untouched ) ) );
    if ( $untouched_hashes !== $reloaded_untouched_hashes ) { throw new RuntimeException( 'An out-of-scope Elementor root changed while provisioning compare.' ); }

    $compare = null;
    foreach ( $reloaded as $element ) { if ( 'compare' === ( $element['settings']['_element_id'] ?? '' ) ) { $compare = $element; break; } }
    if ( ! is_array( $compare ) ) { throw new RuntimeException( 'The persisted compare section anchor is missing.' ); }

    $settings_by_class = array(); $margin_controls = array(); $html_widgets = 0;
    $walk = static function ( $element ) use ( &$walk, &$settings_by_class, &$margin_controls, &$html_widgets ) {
        $settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
        $classes = trim( (string) ( $settings['css_classes'] ?? $settings['_css_classes'] ?? '' ) );
        foreach ( preg_split( '/\s+/', $classes ) as $class ) { if ( $class ) { $settings_by_class[ $class ][] = $settings; } }
        foreach ( $settings as $key => $value ) { if ( false !== strpos( (string) $key, 'margin' ) ) { $margin_controls[] = $key; } }
        if ( 'widget' === ( $element['elType'] ?? '' ) && 'html' === ( $element['widgetType'] ?? '' ) ) { $html_widgets++; }
        foreach ( $element['elements'] ?? array() as $child ) { $walk( $child ); }
    };
    $walk( $compare );
    if ( $margin_controls || $html_widgets ) { throw new RuntimeException( 'Compare persistence contains forbidden margin or HTML widget architecture.' ); }
    if ( 'hidden-mobile' !== ( $settings_by_class['dc-compare-table'][0]['hide_mobile'] ?? '' ) ) { throw new RuntimeException( 'Compare table did not retain native mobile visibility.' ); }
    $cards_settings = $settings_by_class['dc-compare-cards'][0] ?? array();
    if ( 'hidden-desktop' !== ( $cards_settings['hide_desktop'] ?? '' ) || 'hidden-laptop' !== ( $cards_settings['hide_laptop'] ?? '' ) || 'hidden-tablet' !== ( $cards_settings['hide_tablet'] ?? '' ) ) { throw new RuntimeException( 'Compare cards did not retain native desktop/laptop/tablet visibility.' ); }
    if ( 'section' !== ( $settings_by_class['dc-compare-section'][0]['html_tag'] ?? '' ) || 'article' !== ( $settings_by_class['dc-compare-card'][0]['html_tag'] ?? '' ) ) { throw new RuntimeException( 'Compare semantic HTML tags did not persist.' ); }

    $rendered = $adapter->render( $post_id );
    if ( ! is_string( $rendered ) || false === strpos( $rendered, 'How it compares.' ) || false === strpos( $rendered, 'Send us the weight and the deadline' ) ) { throw new RuntimeException( 'The persisted compare section did not render through Elementor.' ); }

    $css_content = '';
    if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
        $kit_css = new \Elementor\Core\Files\CSS\Post( \Elementor\Plugin::instance()->kits_manager->get_active_id() ); $kit_css->update(); $css_content .= (string) $kit_css->get_content();
        $page_css = new \Elementor\Core\Files\CSS\Post( $post_id ); $page_css->update(); $css_content .= "\n" . (string) $page_css->get_content();
    }
    if ( false === strpos( $css_content, 'hidden-mobile' ) && false === strpos( $rendered, 'elementor-hidden-mobile' ) ) { throw new RuntimeException( 'Generated output lacks responsive visibility evidence.' ); }

    $architecture = ( new Design_Core_Elementor_Architecture_Auditor() )->audit( array( $compare ) );
    if ( 'pass' !== ( $architecture['status'] ?? '' ) ) { throw new RuntimeException( 'The compare section failed the architecture ownership gate.' ); }

    WP_CLI::print_value( array(
        'status' => 'pass', 'post_id' => $post_id, 'root_count' => count( $reloaded ),
        'untouched_root_hashes_verified' => count( $untouched_hashes ),
        'mapping_report' => $report, 'rendered_bytes' => strlen( $rendered ), 'generated_css_bytes' => strlen( $css_content ),
        'architecture' => $architecture,
    ) );
}

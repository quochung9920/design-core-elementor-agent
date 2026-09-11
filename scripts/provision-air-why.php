<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Publish the reusable tokens whose complete desktop values are safe to bind as Elementor globals. */
function design_core_air_why_tokens() {
    return array(
        'colors' => array(
            'ink' => '#131417', 'copy' => '#5b5955', 'canvas' => '#f4f3f0',
            'line' => '#e2e0db', 'paper' => '#ffffff', 'card-hover' => '#fffaf0',
        ),
        'typography' => array(
            'section-heading' => array( 'font_family' => 'Geist', 'font_weight' => '600', 'font_size' => '34', 'line_height' => '1.1', 'letter_spacing' => '-2', 'font_size_tablet' => '26', 'letter_spacing_tablet' => '-1.6', 'font_size_mobile' => '26', 'line_height_mobile' => '1.12', 'letter_spacing_mobile' => '-1.6' ),
            'section-lead' => array( 'font_family' => 'Poppins', 'font_weight' => '400', 'font_size' => '16', 'line_height' => '1.72', 'font_size_tablet' => '14.5', 'font_size_mobile' => '14.5' ),
            'card-heading' => array( 'font_family' => 'Geist', 'font_weight' => '600', 'font_size' => '22', 'line_height' => '1.2', 'letter_spacing' => '-1.2', 'font_size_mobile' => '19' ),
            'card-body' => array( 'font_family' => 'Poppins', 'font_weight' => '400', 'font_size' => '14', 'line_height' => '1.62', 'font_size_mobile' => '13.5' ),
        ),
        'spacing' => array( 'section' => 110, 'card' => 40 ),
        'radius' => array( 'card-grid' => 12 ),
    );
}

/** Build the canonical, platform-neutral IR for the Air Consolidation benefit section. */
function design_core_air_why_ir( array $assets ) {
    if ( 4 !== count( $assets ) ) {
        throw new InvalidArgumentException( 'The why section requires exactly four governed icon assets.' );
    }

    $base = static function ( $id, $tag, $classes = array(), $attributes = array() ) {
        return array(
            'id' => $id,
            'source' => array(
                'tag' => $tag,
                'classes' => array_values( $classes ),
                'attributes' => $attributes,
                'dom_path' => '/section[@id="why"]/' . $id,
            ),
            'semantic' => array( 'role' => '', 'component_type' => '', 'confidence' => 1.0 ),
            'content' => array( 'text' => '', 'rich_text' => '', 'link' => array(), 'image' => array(), 'list' => array(), 'fields' => array() ),
            'layout' => array(),
            'style' => array(),
            'spacing' => array(),
            'responsive' => array(),
            'assets' => array(),
            'interaction' => array(),
            'component' => array(
                'fingerprint' => array(
                    'version' => 2,
                    'semantic' => 'air-consolidation-benefit',
                    'structure' => $tag,
                    'content_schema' => array(),
                    'layout' => '',
                    'interaction' => '',
                ),
                'repeated' => false,
                'reusable' => false,
                'dynamic' => false,
                'content_schema' => array(),
            ),
            'children' => array(),
        );
    };
    $dimensions = static function ( $top, $right, $bottom, $left, $unit = 'px' ) {
        return array( 'top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left, 'unit' => $unit );
    };
    $responsive = static function ( $layout = array(), $style = array(), $spacing = array() ) {
        return array( 'layout' => $layout, 'style' => $style, 'spacing' => $spacing );
    };

    $nodes = array();

    $root = $base( 'why-root', 'section', array( 'dc-why-section' ), array( 'id' => 'why' ) );
    $root['semantic']['role'] = 'benefits-section';
    $root['semantic']['layout_mode'] = 'fullwidth';
    $root['semantic']['container_role'] = 'surface';
    $root['layout'] = array( 'content_width' => 'full', 'direction' => 'column', 'align' => 'center', 'width' => array( 'value' => 100, 'unit' => '%' ) );
    $root['style'] = array( 'background' => '#f4f3f0' );
    $root['spacing'] = array( 'padding' => $dimensions( 110, 0, 110, 0 ) );
    $root['responsive'] = array(
        'tablet' => $responsive( array(), array(), array( 'padding' => $dimensions( 76, 0, 76, 0 ) ) ),
        'mobile' => $responsive( array(), array(), array( 'padding' => $dimensions( 64, 0, 64, 0 ) ) ),
    );
    $root['children'] = array( 'why-container' );
    $nodes[] = $root;

    $container = $base( 'why-container', 'div', array( 'dc-why-container' ) );
    $container['semantic']['layout_mode'] = 'boxed';
    $container['semantic']['container_role'] = 'content';
    $container['layout'] = array(
        'content_width' => 'boxed',
        'direction' => 'column',
        'gap' => array( 'value' => 44, 'unit' => 'px' ),
    );
    $container['responsive'] = array(
        'mobile' => $responsive( array( 'gap' => array( 'value' => 32, 'unit' => 'px' ) ) ),
    );
    $container['children'] = array( 'why-intro', 'why-grid' );
    $nodes[] = $container;

    $intro = $base( 'why-intro', 'div', array( 'dc-why-intro' ) );
    $intro['layout'] = array(
        'content_width' => 'full',
        'direction' => 'column',
        'width' => array( 'value' => 100, 'unit' => '%' ),
        'gap' => array( 'value' => 16, 'unit' => 'px' ),
    );
    $intro['responsive'] = array(
        'mobile' => $responsive( array( 'gap' => array( 'value' => 14, 'unit' => 'px' ) ) ),
    );
    $intro['spacing'] = array( 'padding' => $dimensions( 0, 0, 0, 0 ) );
    $intro['children'] = array( 'why-title', 'why-lead' );
    $nodes[] = $intro;

    $title = $base( 'why-title', 'h2', array( 'dc-why-title' ) );
    $title['content']['text'] = 'Why importers choose it.';
    $title['style'] = array(
        'color' => '#131417',
        'font_family' => 'Geist',
        'font_weight' => '600',
        'font_size' => array( 'value' => 34, 'unit' => 'px' ),
        'line_height' => array( 'value' => 1.1, 'unit' => 'em' ),
        'letter_spacing' => array( 'value' => -2, 'unit' => 'px' ),
    );
    $title['responsive'] = array(
        'tablet' => $responsive( array(), array( 'font_size' => array( 'value' => 26, 'unit' => 'px' ), 'letter_spacing' => array( 'value' => -1.6, 'unit' => 'px' ) ) ),
        'mobile' => $responsive( array(), array( 'font_size' => array( 'value' => 26, 'unit' => 'px' ), 'line_height' => array( 'value' => 1.12, 'unit' => 'em' ), 'letter_spacing' => array( 'value' => -1.6, 'unit' => 'px' ) ) ),
    );
    $nodes[] = $title;

    $lead_text = 'It exists to fill the gap between what a courier charges for a pallet and what a direct uplift costs to book.';
    $lead = $base( 'why-lead', 'p', array( 'dc-why-lead' ) );
    $lead['content']['text'] = $lead_text;
    $lead['content']['rich_text'] = esc_html( $lead_text );
    $lead['layout'] = array( 'max_width' => array( 'value' => 760, 'unit' => 'px' ) );
    $lead['style'] = array(
        'color' => '#5b5955',
        'font_family' => 'Poppins',
        'font_weight' => '400',
        'font_size' => array( 'value' => 16, 'unit' => 'px' ),
        'line_height' => array( 'value' => 1.72, 'unit' => 'em' ),
    );
    $lead['responsive'] = array(
        'tablet' => $responsive( array(), array( 'font_size' => array( 'value' => 14.5, 'unit' => 'px' ) ) ),
        'mobile' => $responsive( array(), array( 'font_size' => array( 'value' => 14.5, 'unit' => 'px' ) ) ),
    );
    $nodes[] = $lead;

    $grid = $base( 'why-grid', 'div', array( 'dc-why-grid' ) );
    $grid['layout'] = array(
        'content_width' => 'full',
        'direction' => 'row',
        'wrap' => 'wrap',
        'width' => array( 'value' => 100, 'unit' => '%' ),
        'gap' => array( 'value' => 1, 'unit' => 'px' ),
    );
    $grid['style'] = array(
        'background' => '#e2e0db',
        'radius' => array( 'value' => 12, 'unit' => 'px' ),
        'css_fallback' => array( 'border' => '1px solid #e2e0db', 'overflow' => 'hidden' ),
    );
    $grid['spacing'] = array( 'padding' => $dimensions( 0, 0, 0, 0 ) );
    $grid['children'] = array( 'why-card-1', 'why-card-2', 'why-card-3', 'why-card-4' );
    $nodes[] = $grid;

    $cards = array(
        array( 'Cheaper than a courier', 'Express couriers price small parcels well and larger consignments badly. Once your shipment is a pallet rather than a carton, consolidation is usually the cheaper way to fly it.' ),
        array( 'Weeks faster than sea', 'Sea freight from Asia runs in weeks. Consolidation runs in days. When the stock is already late, that gap is the entire decision.' ),
        array( 'You pay for what you use', 'No charter, no minimum uplift, no buying space you will not fill. The unit is shared, so the cost of flying it is shared.' ),
        array( 'One invoice, one team', 'Pickup, uplift, clearance, terminal charges and delivery on a single written price. We clear it and we cart it, so nothing is handed to a company you have never met.' ),
    );

    foreach ( $cards as $index => $copy ) {
        $number = $index + 1;
        $card_id = 'why-card-' . $number;
        $card = $base( $card_id, 'article', array( 'dc-why-card', 'dc-why-card-' . $number ) );
        $card['semantic']['role'] = 'feature-card';
        $card['semantic']['component_type'] = 'feature-card';
        $card['component']['repeated'] = true;
        $card['layout'] = array(
            'content_width' => 'full',
            'direction' => 'column',
            'width' => array( 'value' => 49.92, 'unit' => '%' ),
            'min_height' => array( 'value' => 250, 'unit' => 'px' ),
            'gap' => array( 'value' => 24, 'unit' => 'px' ),
        );
        $card['style'] = array( 'background' => '#ffffff' );
        $card['spacing'] = array( 'padding' => $dimensions( 40, 40, 40, 40 ) );
        $card['responsive'] = array(
            'tablet' => $responsive( array(), array(), array( 'padding' => $dimensions( 30, 30, 30, 30 ) ) ),
            'mobile' => $responsive(
                array( 'width' => array( 'value' => 100, 'unit' => '%' ), 'min_height' => array( 'value' => 0, 'unit' => 'px' ), 'gap' => array( 'value' => 16, 'unit' => 'px' ) ),
                array(),
                array( 'padding' => $dimensions( 26, 22, 26, 22 ) )
            ),
        );
        $card['interaction'] = array( 'hover' => array( 'background' => '#fffaf0', 'duration' => array( 'value' => 0.2, 'unit' => '' ) ) );
        $card['children'] = array( $card_id . '-icon', $card_id . '-body' );
        $nodes[] = $card;

        $icon = $base( $card_id . '-icon', 'img', array( 'dc-why-icon' ) );
        $icon['content']['image'] = array( 'url' => esc_url_raw( $assets[ $index ]['url'] ?? '' ), 'alt' => sanitize_text_field( $assets[ $index ]['alt'] ?? '' ) );
        $icon['layout'] = array( 'align' => 'left', 'width' => array( 'value' => 26, 'unit' => 'px' ), 'height' => array( 'value' => 26, 'unit' => 'px' ) );
        $icon['responsive'] = array( 'mobile' => $responsive( array( 'width' => array( 'value' => 24, 'unit' => 'px' ), 'height' => array( 'value' => 24, 'unit' => 'px' ) ) ) );
        $icon['assets'] = array( 'kind' => 'image', 'binding' => 'benefit-icon-' . $number );
        $nodes[] = $icon;

        $body = $base( $card_id . '-body', 'div', array( 'dc-why-card-body' ) );
        $body['layout'] = array(
            'content_width' => 'full',
            'direction' => 'column',
            'width' => array( 'value' => 100, 'unit' => '%' ),
            'gap' => array( 'value' => 10, 'unit' => 'px' ),
        );
        $body['responsive'] = array(
            'mobile' => $responsive( array( 'gap' => array( 'value' => 8, 'unit' => 'px' ) ) ),
        );
        $body['spacing'] = array( 'padding' => $dimensions( 0, 0, 0, 0 ) );
        $body['children'] = array( $card_id . '-heading', $card_id . '-text' );
        $nodes[] = $body;

        $heading = $base( $card_id . '-heading', 'h3', array( 'dc-why-card-heading' ) );
        $heading['content']['text'] = $copy[0];
        $heading['style'] = array(
            'color' => '#131417',
            'font_family' => 'Geist',
            'font_weight' => '600',
            'font_size' => array( 'value' => 22, 'unit' => 'px' ),
            'line_height' => array( 'value' => 1.2, 'unit' => 'em' ),
            'letter_spacing' => array( 'value' => -1.2, 'unit' => 'px' ),
        );
        $heading['responsive'] = array(
            'mobile' => $responsive(
                array(),
                array( 'font_size' => array( 'value' => 19, 'unit' => 'px' ) )
            ),
        );
        $nodes[] = $heading;

        $text = $base( $card_id . '-text', 'p', array( 'dc-why-card-text' ) );
        $text['content']['text'] = $copy[1];
        $text['content']['rich_text'] = esc_html( $copy[1] );
        $text['style'] = array(
            'color' => '#5b5955',
            'font_family' => 'Poppins',
            'font_weight' => '400',
            'font_size' => array( 'value' => 14, 'unit' => 'px' ),
            'line_height' => array( 'value' => 1.62, 'unit' => 'em' ),
        );
        $text['responsive'] = array( 'mobile' => $responsive( array(), array( 'font_size' => array( 'value' => 13.5, 'unit' => 'px' ) ) ) );
        $nodes[] = $text;
    }

    return array(
        'schema_version' => Design_Core_Elementor_Design_IR::SCHEMA_VERSION,
        'type' => 'design-ir',
        'nodes' => $nodes,
        'root_ids' => array( 'why-root' ),
        'analysis_quality' => array( 'source' => 'high-fidelity-static-authority', 'section' => 'why' ),
        'tokens' => array(),
        'diagnostics' => array(),
    );
}

if ( defined( 'WP_CLI' ) && WP_CLI && ! defined( 'DESIGN_CORE_AIR_WHY_BLUEPRINT_ONLY' ) ) {
    $post_id = 2;
    $asset_ids = array( 165, 166, 167, 168 );
    $assets = array();
    foreach ( $asset_ids as $attachment_id ) {
        $url = wp_get_attachment_url( $attachment_id );
        if ( ! $url || 'image/webp' !== get_post_mime_type( $attachment_id ) ) {
            throw new RuntimeException( 'A governed why-section WebP asset is unavailable.' );
        }
        $assets[] = array( 'url' => $url, 'alt' => '' );
    }

    $token_service = new Design_Core_Elementor_Design_Token_Service();
    $token_service->save( array_replace_recursive( $token_service->all(), design_core_air_why_tokens() ) );
    $global_bridge = new Design_Core_Elementor_Global_Style_Bridge( $token_service );
    $global_sync = $global_bridge->sync_v3_kit();
    if ( is_wp_error( $global_sync ) || 'synced' !== ( $global_sync['status'] ?? '' ) ) {
        throw new RuntimeException( is_wp_error( $global_sync ) ? $global_sync->get_error_message() : 'Elementor global token sync failed.' );
    }

    $ir = design_core_air_why_ir( $assets );
    ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
    $engine = new Design_Core_Elementor_Mapping_Engine();
    $mapped = $engine->map_ir( $ir );
    $mapped = $global_bridge->apply_v3_references( $mapped, $global_sync );
    if ( 1 !== count( $mapped ) ) {
        throw new RuntimeException( 'The why-section mapper did not produce exactly one root.' );
    }

    $stored = json_decode( get_post_meta( $post_id, '_elementor_data', true ), true );
    if ( ! is_array( $stored ) || count( $stored ) < 2 ) {
        throw new RuntimeException( 'The existing Elementor document is unavailable or incomplete.' );
    }
    $next = array(); $replaced_existing_root = false; $untouched_hashes = array();
    foreach ( $stored as $element ) {
        $settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
        $classes = (string) ( $settings['css_classes'] ?? $settings['_css_classes'] ?? '' );
        $is_why = 'why' === ( $settings['_element_id'] ?? '' ) || false !== strpos( ' ' . $classes . ' ', ' dc-why-section ' );
        if ( $is_why ) {
            if ( ! $replaced_existing_root ) { $next[] = $mapped[0]; $replaced_existing_root = true; }
            continue;
        }
        $next[] = $element;
        $untouched_hashes[] = hash( 'sha256', wp_json_encode( $element ) );
    }
    if ( ! $replaced_existing_root ) { $next[] = $mapped[0]; }
    $expected_root_count = count( $stored ) + ( $replaced_existing_root ? 0 : 1 );

    $adapter = new Design_Core_Elementor_V3_Adapter();
    $saved = $adapter->save_page( $post_id, $next, array() );
    if ( is_wp_error( $saved ) ) {
        throw new RuntimeException( $saved->get_error_message() );
    }
    if ( class_exists( '\Elementor\Plugin' ) ) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }

    $reloaded = $adapter->reload( $post_id );
    if ( ! is_array( $reloaded ) || $expected_root_count !== count( $reloaded ) ) {
        throw new RuntimeException( 'The Elementor document did not persist the expected root count.' );
    }
    $reloaded_untouched_hashes = array();
    foreach ( $reloaded as $element ) {
        $settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
        $classes = (string) ( $settings['css_classes'] ?? $settings['_css_classes'] ?? '' );
        if ( 'why' === ( $settings['_element_id'] ?? '' ) || false !== strpos( ' ' . $classes . ' ', ' dc-why-section ' ) ) { continue; }
        $reloaded_untouched_hashes[] = hash( 'sha256', wp_json_encode( $element ) );
    }
    if ( $untouched_hashes !== $reloaded_untouched_hashes ) { throw new RuntimeException( 'The why-section save changed an out-of-scope root.' ); }
    $why = null;
    foreach ( $reloaded as $element ) {
        if ( 'why' === ( $element['settings']['_element_id'] ?? '' ) ) { $why = $element; break; }
    }
    if ( ! is_array( $why ) ) {
        throw new RuntimeException( 'The persisted why-section anchor is missing.' );
    }

    $global_references = array();
    $collect_globals = static function ( $element ) use ( &$collect_globals, &$global_references ) {
        foreach ( (array) ( $element['settings']['__globals__'] ?? array() ) as $reference ) {
            if ( is_string( $reference ) && false !== strpos( $reference, 'globals/' ) ) { $global_references[] = $reference; }
        }
        foreach ( $element['elements'] ?? array() as $child ) { $collect_globals( $child ); }
    };
    $collect_globals( $why );
    if ( count( $global_references ) < 20 ) {
        throw new RuntimeException( 'The persisted why section did not retain sufficient Elementor global bindings.' );
    }

    $kit_settings = get_post_meta( \Elementor\Plugin::instance()->kits_manager->get_active_id(), '_elementor_page_settings', true );
    $kit_ids = array_merge( array_column( (array) ( $kit_settings['custom_colors'] ?? array() ), '_id' ), array_column( (array) ( $kit_settings['custom_typography'] ?? array() ), '_id' ) );
    foreach ( $global_references as $reference ) {
        $query = array(); parse_str( (string) parse_url( $reference, PHP_URL_QUERY ), $query );
        if ( empty( $query['id'] ) || ! in_array( $query['id'], $kit_ids, true ) ) { throw new RuntimeException( 'The why section contains a dangling Elementor global reference.' ); }
    }

    $rendered = $adapter->render( $post_id );
    if ( ! is_string( $rendered ) || false === strpos( $rendered, 'Why importers choose it.' ) ) {
        throw new RuntimeException( 'The persisted why section did not render through Elementor.' );
    }

    $css_content = '';
    if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
        $kit_css_file = new \Elementor\Core\Files\CSS\Post( \Elementor\Plugin::instance()->kits_manager->get_active_id() );
        $kit_css_file->update();
        $css_content .= (string) $kit_css_file->get_content();
        $css_file = new \Elementor\Core\Files\CSS\Post( $post_id );
        $css_file->update();
        $css_content .= "\n" . (string) $css_file->get_content();
    }
    $mobile_typography_css = (bool) preg_match( '/font-size:\s*19(?:\.0)?px/', $css_content );
    if ( ! $mobile_typography_css ) { throw new RuntimeException( 'The generated Elementor global CSS is missing the mobile card-heading typography rule.' ); }
    $architecture = ( new Design_Core_Elementor_Architecture_Auditor() )->audit( array( $why ) );
    if ( 'pass' !== ( $architecture['status'] ?? '' ) ) {
        throw new RuntimeException( 'The why section failed the architecture ownership gate.' );
    }

    WP_CLI::print_value( array(
        'status' => 'pass',
        'post_id' => $post_id,
        'root_count' => count( $reloaded ),
        'asset_ids' => $asset_ids,
        'global_reference_count' => count( $global_references ),
        'global_sync' => array( 'colors' => count( $global_sync['colors'] ?? array() ), 'typography' => count( $global_sync['typography'] ?? array() ) ),
        'mapping_report' => $engine->mapping_report(),
        'rendered_bytes' => strlen( $rendered ),
        'generated_css_bytes' => strlen( $css_content ),
        'mobile_typography_css' => $mobile_typography_css,
        'architecture' => $architecture,
    ) );
}

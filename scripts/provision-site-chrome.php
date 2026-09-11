<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function design_core_site_chrome_id( $name ) { return substr( md5( 'design-core-site-chrome|' . $name ), 0, 7 ); }
function design_core_site_chrome_dimensions( $top, $right, $bottom, $left, $unit = 'px' ) { return array( 'top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left, 'unit' => $unit, 'isLinked' => false ); }
function design_core_site_chrome_size( $size, $unit = 'px' ) { return array( 'size' => $size, 'unit' => $unit, 'sizes' => array() ); }
function design_core_site_chrome_container( $name, array $settings, array $children = array() ) {
    return array( 'id' => design_core_site_chrome_id( $name ), 'elType' => 'container', 'isInner' => false, 'settings' => $settings, 'elements' => $children );
}
function design_core_site_chrome_widget( $name, $type, array $settings ) {
    return array( 'id' => design_core_site_chrome_id( $name ), 'elType' => 'widget', 'widgetType' => $type, 'isInner' => false, 'settings' => $settings, 'elements' => array() );
}
function design_core_site_chrome_attachment( $slug ) {
    $posts = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'name' => sanitize_title( $slug ), 'numberposts' => 2 ) );
    if ( 1 !== count( $posts ) ) { throw new RuntimeException( 'Expected exactly one Media Library asset: ' . $slug ); }
    return array( 'id' => (int) $posts[0]->ID, 'url' => wp_get_attachment_url( $posts[0]->ID ) );
}
function design_core_site_chrome_menu( $name, $slug, array $items ) {
    $menu = wp_get_nav_menu_object( $slug );
    if ( ! $menu ) { $menu = wp_get_nav_menu_object( $name ); }
    if ( ! $menu ) {
        $menu_id = wp_create_nav_menu( $name );
        if ( is_wp_error( $menu_id ) ) { throw new RuntimeException( $menu_id->get_error_message() ); }
        $menu = wp_get_nav_menu_object( $menu_id );
    }
    foreach ( wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) ) ?: array() as $old ) { wp_delete_post( $old->ID, true ); }
    foreach ( $items as $item ) {
        $result = wp_update_nav_menu_item( $menu->term_id, 0, array(
            'menu-item-title' => $item[0], 'menu-item-url' => $item[1], 'menu-item-status' => 'publish', 'menu-item-type' => 'custom',
        ) );
        if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
    }
    return $menu->slug;
}
function design_core_site_chrome_require_controls( $element_type, $widget_type, array $controls ) {
    $registry = new Design_Core_Elementor_Control_Schema_Registry();
    foreach ( $controls as $control ) {
        if ( ! $registry->has( $element_type, $widget_type, $control ) ) { throw new RuntimeException( "Missing runtime control {$element_type}:{$widget_type}.{$control}" ); }
    }
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    if ( ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'An administrator context is required.' ); }
    Design_Core_Elementor_Plugin::require_widget_base_classes();

    $home = home_url( '/' );
    $air = add_query_arg( 'page_id', 2, $home );
    $primary_menu = design_core_site_chrome_menu( 'Design Core Primary Navigation', 'dc-primary-navigation', array(
        array( 'Air Freight', $air . '#top' ), array( 'Customs Clearance', '#' ), array( 'Land Transport', '#' ),
        array( 'Sea Freight', '#' ), array( 'Locations', '#' ), array( 'About', '#' ),
    ) );
    $services_menu = design_core_site_chrome_menu( 'Design Core Footer Services', 'dc-footer-services', array(
        array( 'Air Freight', $air . '#top' ), array( 'Customs Clearance', '#' ), array( 'Land Transport', '#' ), array( 'Sea Freight', '#' ),
    ) );
    $locations_menu = design_core_site_chrome_menu( 'Design Core Footer Locations', 'dc-footer-locations', array(
        array( 'Adelaide', '#' ), array( 'Brisbane', '#' ), array( 'Melbourne', '#' ), array( 'Perth', '#' ), array( 'Sydney', '#' ),
    ) );
    $company_menu = design_core_site_chrome_menu( 'Design Core Footer Company', 'dc-footer-company', array(
        array( 'About', '#' ), array( 'Import Guide', '#' ), array( 'FAQ', $air . '#questions' ), array( 'Contact', $air . '#conversion-band' ),
    ) );

    design_core_site_chrome_require_controls( 'container', '', array( 'content_width', 'flex_direction', 'flex_justify_content', 'flex_align_items', 'flex_gap', 'padding', 'background_color' ) );
    design_core_site_chrome_require_controls( 'widget', 'nav-menu', array( 'menu', 'layout', 'dropdown', 'pointer', 'menu_typography_font_family', 'color_menu_item' ) );
    design_core_site_chrome_require_controls( 'widget', 'dc-global-time-bar', array( 'title', 'locations', 'title_color', 'location_color', 'time_color' ) );

    $header_logo = design_core_site_chrome_attachment( 'intercargo-header-logo' );
    $footer_logo = design_core_site_chrome_attachment( 'intercargo-footer-logo' );
    $footer_dots = design_core_site_chrome_attachment( 'intercargo-footer-dots' );
    $gap = static function ( $size ) { return array( 'column' => (string) $size, 'row' => (string) $size, 'isLinked' => true, 'unit' => 'px', 'size' => $size ); };

    $time = design_core_site_chrome_widget( 'header-time-bar', 'dc-global-time-bar', array(
        'title' => 'Current time in:',
        'locations' => array(
            array( '_id' => 'australia', 'label' => 'Australia', 'timezone' => 'Australia/Sydney' ),
            array( '_id' => 'china', 'label' => 'China', 'timezone' => 'Asia/Shanghai' ),
            array( '_id' => 'us', 'label' => 'US', 'timezone' => 'America/Los_Angeles' ),
            array( '_id' => 'singapore', 'label' => 'Singapore', 'timezone' => 'Asia/Singapore' ),
            array( '_id' => 'japan', 'label' => 'Japan', 'timezone' => 'Asia/Tokyo' ),
            array( '_id' => 'south-korea', 'label' => 'South Korea', 'timezone' => 'Asia/Seoul' ),
        ),
        'title_color' => '#fbc925', 'location_color' => '#ffffff', 'time_color' => '#b9bcc5',
        'typography_typography' => 'custom', 'typography_font_family' => 'Poppins', 'typography_font_size' => design_core_site_chrome_size( 12 ), 'typography_font_weight' => '400',
        'gap' => design_core_site_chrome_size( 24 ), 'gap_mobile' => design_core_site_chrome_size( 16 ), '_css_classes' => 'dc-site-time-bar',
    ) );
    $announcement_inner = design_core_site_chrome_container( 'header-announcement-inner', array(
        'content_width' => 'boxed', 'flex_direction' => 'column', 'flex_justify_content' => 'center', 'flex_align_items' => 'stretch', 'flex_gap' => $gap( 0 ),
        'min_height' => design_core_site_chrome_size( 39 ), 'padding' => design_core_site_chrome_dimensions( 8, 0, 8, 0 ),
        'padding_mobile' => design_core_site_chrome_dimensions( 8, 0, 8, 0 ), 'css_classes' => 'dc-site-announcement-inner dc-global-container',
    ), array( $time ) );
    $announcement = design_core_site_chrome_container( 'header-announcement', array(
        'content_width' => 'full', 'flex_direction' => 'column', 'background_background' => 'classic', 'background_color' => '#131417',
        'padding' => design_core_site_chrome_dimensions( 0, 0, 0, 0 ), 'flex_gap' => $gap( 0 ), 'css_classes' => 'dc-site-announcement',
    ), array( $announcement_inner ) );

    $logo = design_core_site_chrome_widget( 'header-logo', 'image', array(
        'image' => array( 'id' => $header_logo['id'], 'url' => $header_logo['url'], 'size' => 'full' ), 'image_size' => 'full',
        'link_to' => 'custom', 'link' => array( 'url' => $home ), 'width' => design_core_site_chrome_size( 150 ), 'width_mobile' => design_core_site_chrome_size( 116 ), 'align' => 'left', '_css_classes' => 'dc-site-header-logo',
    ) );
    $nav = design_core_site_chrome_widget( 'header-nav-menu', 'nav-menu', array(
        'menu_name' => 'Primary', 'menu' => $primary_menu, 'layout' => 'horizontal', 'align_items' => 'end', 'pointer' => 'underline', 'animation_line' => 'slide',
        'dropdown' => 'tablet', 'full_width' => 'stretch', 'text_align' => 'aside', 'toggle' => 'burger',
        'menu_typography_typography' => 'custom', 'menu_typography_font_family' => 'Poppins', 'menu_typography_font_size' => design_core_site_chrome_size( 13 ), 'menu_typography_font_weight' => '500',
        'color_menu_item' => '#5a5d63', 'color_menu_item_hover' => '#131417', 'pointer_color_menu_item_hover' => '#fbc925', 'color_menu_item_active' => '#131417', 'pointer_color_menu_item_active' => '#fbc925',
        'menu_space_between' => design_core_site_chrome_size( 26 ), 'padding_horizontal_menu_item' => design_core_site_chrome_size( 0 ), 'padding_vertical_menu_item' => design_core_site_chrome_size( 12 ),
        'toggle_color' => '#131417', 'toggle_background_color' => '#f4f3ef', 'toggle_size' => design_core_site_chrome_size( 24 ), 'toggle_border_radius' => design_core_site_chrome_size( 6 ), '_css_classes' => 'dc-site-primary-menu',
    ) );
    $talk = design_core_site_chrome_widget( 'header-talk-button', 'button', array(
        'text' => 'Talk to our team', 'link' => array( 'url' => $air . '#conversion-band' ), 'align' => 'right',
        'typography_typography' => 'custom', 'typography_font_family' => 'Poppins', 'typography_font_size' => design_core_site_chrome_size( 13.5 ), 'typography_font_weight' => '600',
        'button_text_color' => '#131417', 'background_background' => 'classic', 'background_color' => '#fbc925', 'button_background_hover_color' => '#f2bd06', 'hover_color' => '#131417', 'hover_animation' => 'float',
        'border_radius' => design_core_site_chrome_dimensions( 6, 6, 6, 6 ), 'text_padding' => design_core_site_chrome_dimensions( 14, 26, 14, 26 ),
        'hide_tablet' => 'hidden-tablet', 'hide_mobile' => 'hidden-mobile', '_css_classes' => 'dc-site-header-cta',
    ) );
    $nav_actions = design_core_site_chrome_container( 'header-nav-actions', array(
        'content_width' => 'full', 'flex_direction' => 'row', 'flex_justify_content' => 'flex-end', 'flex_align_items' => 'center', 'flex_gap' => $gap( 26 ),
        'width' => design_core_site_chrome_size( 78, '%' ), 'width_tablet' => design_core_site_chrome_size( 50, '%' ), 'width_mobile' => design_core_site_chrome_size( 50, '%' ),
        'padding' => design_core_site_chrome_dimensions( 0, 0, 0, 0 ), 'css_classes' => 'dc-site-nav-actions',
    ), array( $nav, $talk ) );
    $nav_inner = design_core_site_chrome_container( 'header-nav-inner', array(
        'content_width' => 'boxed', 'flex_direction' => 'row', 'flex_justify_content' => 'space-between', 'flex_align_items' => 'center', 'flex_gap' => $gap( 32 ),
        'min_height' => design_core_site_chrome_size( 88 ), 'min_height_tablet' => design_core_site_chrome_size( 70 ), 'min_height_mobile' => design_core_site_chrome_size( 57 ),
        'padding' => design_core_site_chrome_dimensions( 0, 0, 0, 0 ), 'css_classes' => 'dc-site-nav-inner dc-global-container',
    ), array( $logo, $nav_actions ) );
    $nav_surface = design_core_site_chrome_container( 'header-nav-surface', array(
        'content_width' => 'full', 'flex_direction' => 'column', 'background_background' => 'classic', 'background_color' => '#ffffff',
        'border_border' => 'solid', 'border_width' => design_core_site_chrome_dimensions( 0, 0, 1, 0 ), 'border_color' => '#e7e5df',
        'padding' => design_core_site_chrome_dimensions( 0, 0, 0, 0 ), 'flex_gap' => $gap( 0 ), 'css_classes' => 'dc-site-nav-surface',
    ), array( $nav_inner ) );
    $header_root = design_core_site_chrome_container( 'site-header-root', array(
        'content_width' => 'full', 'flex_direction' => 'column', 'flex_gap' => $gap( 0 ), 'padding' => design_core_site_chrome_dimensions( 0, 0, 0, 0 ),
        'html_tag' => 'header', '_element_id' => 'site-header', 'css_classes' => 'dc-site-header', 'custom_css' => "@media(hover:hover) and (pointer:fine){selector .dc-site-header-cta .elementor-button:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(19,20,23,.12);background:#f2bd06}}@media(prefers-reduced-motion:reduce){selector *,selector *::before,selector *::after{transition-duration:.001ms;animation-duration:.001ms}}",
    ), array( $announcement, $nav_surface ) );

    $footer_menu_widget = static function ( $name, $menu, $label ) {
        return design_core_site_chrome_widget( 'footer-menu-' . $name, 'nav-menu', array(
            'menu_name' => $label, 'menu' => $menu, 'layout' => 'vertical', 'align_items' => 'start', 'pointer' => 'none', 'dropdown' => 'none', 'toggle' => 'none',
            'menu_typography_typography' => 'custom', 'menu_typography_font_family' => 'Poppins', 'menu_typography_font_size' => design_core_site_chrome_size( 12.5 ), 'menu_typography_font_size_mobile' => design_core_site_chrome_size( 10.5 ), 'menu_typography_font_weight' => '400',
            'color_menu_item' => '#c7c7c4', 'color_menu_item_hover' => '#ffffff', 'padding_horizontal_menu_item' => design_core_site_chrome_size( 0 ), 'padding_vertical_menu_item' => design_core_site_chrome_size( 5 ),
            '_css_classes' => 'dc-footer-menu dc-footer-menu-' . $name,
        ) );
    };
    $footer_column = static function ( $name, $title, $menu_widget ) use ( $gap ) {
        $heading = design_core_site_chrome_widget( 'footer-heading-' . $name, 'heading', array(
            'title' => $title, 'header_size' => 'h4', 'title_color' => '#fbc925', 'typography_typography' => 'custom',
            'typography_font_family' => 'Poppins', 'typography_font_size' => design_core_site_chrome_size( 11 ), 'typography_font_size_mobile' => design_core_site_chrome_size( 9.5 ), 'typography_font_weight' => '600', 'typography_letter_spacing' => design_core_site_chrome_size( .44 ),
            '_css_classes' => 'dc-footer-heading',
        ) );
        return design_core_site_chrome_container( 'footer-column-' . $name, array(
            'content_width' => 'full', 'flex_direction' => 'column', 'flex_gap' => $gap( 10 ), 'width' => design_core_site_chrome_size( 18, '%' ),
            'width_tablet' => design_core_site_chrome_size( 18, '%' ), 'width_mobile' => design_core_site_chrome_size( 28, '%' ), '_flex_grow' => 0, 'padding' => design_core_site_chrome_dimensions( 0, 0, 0, 0 ),
        ), array( $heading, $menu_widget ) );
    };
    $footer_brand_logo = design_core_site_chrome_widget( 'footer-logo', 'image', array(
        'image' => array( 'id' => $footer_logo['id'], 'url' => $footer_logo['url'], 'size' => 'full' ), 'image_size' => 'full', 'width' => design_core_site_chrome_size( 150 ), 'align' => 'left', '_css_classes' => 'dc-site-footer-logo',
    ) );
    $footer_brand_copy = design_core_site_chrome_widget( 'footer-brand-copy', 'text-editor', array(
        'editor' => 'Freight forwarding, customs and 3PL warehousing for businesses importing into Australia.', 'text_color' => '#c7c7c4',
        'typography_typography' => 'custom', 'typography_font_family' => 'Poppins', 'typography_font_size' => design_core_site_chrome_size( 12.5 ), 'typography_font_size_mobile' => design_core_site_chrome_size( 11.5 ), 'typography_font_weight' => '400', 'typography_line_height' => design_core_site_chrome_size( 1.68, 'em' ), '_css_classes' => 'dc-footer-brand-copy',
    ) );
    $brand = design_core_site_chrome_container( 'footer-brand', array(
        'content_width' => 'full', 'flex_direction' => 'column', 'flex_gap' => $gap( 16 ), 'width' => design_core_site_chrome_size( 32, '%' ),
        'width_tablet' => design_core_site_chrome_size( 32, '%' ), 'width_mobile' => design_core_site_chrome_size( 100, '%' ), 'padding' => design_core_site_chrome_dimensions( 0, 0, 0, 0 ),
    ), array( $footer_brand_logo, $footer_brand_copy ) );
    $services = $footer_column( 'services', 'Services', $footer_menu_widget( 'services', $services_menu, 'Services' ) );
    $locations = $footer_column( 'locations', 'Locations', $footer_menu_widget( 'locations', $locations_menu, 'Locations' ) );
    $company = $footer_column( 'company', 'Company', $footer_menu_widget( 'company', $company_menu, 'Company' ) );
    $footer_top = design_core_site_chrome_container( 'footer-top', array(
        'content_width' => 'full', 'flex_direction' => 'row', 'flex_wrap' => 'wrap', 'flex_justify_content' => 'space-between', 'flex_align_items' => 'flex-start', 'flex_gap' => $gap( 48 ),
        'flex_gap_tablet' => $gap( 28 ), 'flex_gap_mobile' => $gap( 22 ), 'padding' => design_core_site_chrome_dimensions( 0, 0, 0, 0 ), 'css_classes' => 'dc-footer-top',
    ), array( $brand, $services, $locations, $company ) );
    $legal_copy = design_core_site_chrome_widget( 'footer-legal-copy', 'text-editor', array(
        'editor' => '© Intercargo Connect. ABN and licence details publish at launch.', 'text_color' => '#c7c7c4', 'typography_typography' => 'custom',
        'typography_font_family' => 'Poppins', 'typography_font_size' => design_core_site_chrome_size( 11.5 ), 'typography_font_size_mobile' => design_core_site_chrome_size( 10 ), '_css_classes' => 'dc-footer-legal-copy',
    ) );
    $privacy = design_core_site_chrome_widget( 'footer-privacy', 'text-editor', array(
        'editor' => '<a href="' . esc_url( home_url( '/privacy-policy/' ) ) . '">Privacy</a>', 'text_color' => '#c7c7c4', 'link_color' => '#c7c7c4', 'link_hover_color' => '#ffffff',
        'typography_typography' => 'custom', 'typography_font_family' => 'Poppins', 'typography_font_size' => design_core_site_chrome_size( 11.5 ), 'typography_font_size_mobile' => design_core_site_chrome_size( 10 ), 'align' => 'right', 'align_mobile' => 'left', '_css_classes' => 'dc-footer-privacy',
    ) );
    $legal = design_core_site_chrome_container( 'footer-legal', array(
        'content_width' => 'full', 'flex_direction' => 'row', 'flex_direction_mobile' => 'column', 'flex_justify_content' => 'space-between', 'flex_align_items' => 'center', 'flex_align_items_mobile' => 'flex-start', 'flex_gap' => $gap( 20 ),
        'border_border' => 'solid', 'border_width' => design_core_site_chrome_dimensions( 1, 0, 0, 0 ), 'border_color' => '#4a4d4f',
        'padding' => design_core_site_chrome_dimensions( 20, 0, 0, 0 ), 'padding_mobile' => design_core_site_chrome_dimensions( 14, 0, 0, 0 ), 'css_classes' => 'dc-footer-legal',
    ), array( $legal_copy, $privacy ) );
    $footer_inner = design_core_site_chrome_container( 'footer-inner', array(
        'content_width' => 'boxed', 'flex_direction' => 'column', 'flex_gap' => $gap( 34 ), 'flex_gap_mobile' => $gap( 20 ), 'padding' => design_core_site_chrome_dimensions( 0, 0, 0, 0 ), 'css_classes' => 'dc-footer-inner dc-global-container',
    ), array( $footer_top, $legal ) );
    $footer_root = design_core_site_chrome_container( 'site-footer-root', array(
        'content_width' => 'full', 'flex_direction' => 'column', 'flex_gap' => $gap( 0 ), 'padding' => design_core_site_chrome_dimensions( 64, 0, 36, 0 ),
        'padding_mobile' => design_core_site_chrome_dimensions( 34, 0, 24, 0 ), 'background_background' => 'classic', 'background_color' => '#131417',
        'background_overlay_background' => 'classic', 'background_overlay_image' => array( 'id' => $footer_dots['id'], 'url' => $footer_dots['url'] ), 'background_overlay_position' => 'center right', 'background_overlay_repeat' => 'no-repeat', 'background_overlay_size' => 'contain', 'background_overlay_opacity' => array( 'size' => .08, 'unit' => 'px', 'sizes' => array() ),
        'html_tag' => 'footer', '_element_id' => 'site-footer', 'css_classes' => 'dc-site-footer', 'custom_css' => "@media(max-width:767px){selector .dc-footer-legal{flex-direction:row;gap:8px}selector .dc-footer-legal>.elementor-element{width:auto;flex:0 1 auto}}",
    ), array( $footer_inner ) );

    $provisioner = new Design_Core_Elementor_Theme_Part_Provisioner();
    $header = $provisioner->upsert( 'header', 'dc-site-header', 'Design Core — Site Header', array( $header_root ) );
    if ( is_wp_error( $header ) ) { throw new RuntimeException( $header->get_error_message() ); }
    $footer = $provisioner->upsert( 'footer', 'dc-site-footer', 'Design Core — Site Footer', array( $footer_root ) );
    if ( is_wp_error( $footer ) ) { throw new RuntimeException( $footer->get_error_message() ); }

    $page_id = 2;
    $page_document = \Elementor\Plugin::$instance->documents->get( $page_id );
    if ( ! $page_document ) { throw new RuntimeException( 'Page ID 2 is unavailable.' ); }
    $page_elements = json_decode( (string) get_post_meta( $page_id, '_elementor_data', true ), true );
    if ( ! is_array( $page_elements ) ) { throw new RuntimeException( 'Page ID 2 has invalid Elementor data.' ); }
    $page_elements_hash = hash( 'sha256', wp_json_encode( $page_elements ) );
    if ( 'elementor_header_footer' !== get_post_meta( $page_id, '_wp_page_template', true ) ) {
        $page_settings = $page_document->get_settings();
        $page_settings['template'] = 'elementor_header_footer';
        $page_settings['hide_title'] = 'yes';
        $page_saved = $page_document->save( array( 'elements' => $page_elements, 'settings' => $page_settings ) );
        if ( is_wp_error( $page_saved ) || false === $page_saved || null === $page_saved ) { throw new RuntimeException( 'Elementor rejected the Page ID 2 template migration.' ); }
        $reloaded_page_elements = json_decode( (string) get_post_meta( $page_id, '_elementor_data', true ), true );
        if ( $page_elements_hash !== hash( 'sha256', wp_json_encode( $reloaded_page_elements ) ) ) { throw new RuntimeException( 'Page ID 2 content changed during template migration.' ); }
    }

    if ( class_exists( '\Elementor\Plugin' ) ) { \Elementor\Plugin::$instance->files_manager->clear_cache(); }
    WP_CLI::print_value( array(
        'status' => 'pass', 'header_id' => $header['id'], 'header_created' => $header['created'],
        'footer_id' => $footer['id'], 'footer_created' => $footer['created'], 'page_posts_created' => 0,
        'page_id' => $page_id, 'page_template' => get_post_meta( $page_id, '_wp_page_template', true ),
        'menus' => array( $primary_menu, $services_menu, $locations_menu, $company_menu ),
        'assets' => array( $header_logo['id'], $footer_logo['id'], $footer_dots['id'] ),
    ) );
}

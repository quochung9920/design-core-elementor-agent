<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Scoped hover/focus rules that supplement native Elementor hover controls. */
function design_core_air_hover_contract() {
    $motion = '@media(prefers-reduced-motion:reduce){selector *,selector *::before,selector *::after{transition-duration:.001ms;animation-duration:.001ms;scroll-behavior:auto}}';
    return array(
        'dc-air-hero' => '@media(hover:hover) and (pointer:fine){selector .elementor-button:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(19,20,23,.12);background:#f2bd06}selector:hover .dc-hero-image img{transform:scale(1.015)}}selector .dc-hero-image img{transition:transform .2s ease}selector input{transition:border-color .18s,box-shadow .18s,background-color .18s}selector input:hover{border-color:#c9c6bf}selector input:focus{border-color:#fbc925;box-shadow:0 0 0 3px rgba(251,201,37,.18);outline:0}' . $motion,
        'dc-what-section' => '@media(hover:hover) and (pointer:fine){selector .dc-diagram-item{transition:transform .2s ease}selector .dc-diagram-item:hover{transform:translateY(-2px)}selector .dc-diagram-item img,selector .dc-diagram-art{transition:transform .2s ease}selector .dc-diagram-item:hover img,selector .dc-diagram-item:hover .dc-diagram-art{transform:translateY(-1px) scale(1.015)}}' . $motion,
        'dc-why-section' => '@media(hover:hover) and (pointer:fine){selector .dc-why-card{transition:transform .2s ease,background-color .2s ease,box-shadow .2s ease}selector .dc-why-card:hover{background:#faf9f6;transform:translateY(-2px);box-shadow:0 10px 28px rgba(19,20,23,.06)}}' . $motion,
        'dc-compare-section' => '@media(hover:hover) and (pointer:fine){selector .dc-compare-row:not(.dc-compare-header-row){transition:background-color .2s ease}selector .dc-compare-row:not(.dc-compare-header-row):hover{background:#faf9f6}selector .dc-compare-row-2:hover{background:#f4f3f0}}' . $motion,
        'dc-conversion-section' => '@media(hover:hover) and (pointer:fine){selector .elementor-button:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(19,20,23,.12);background:#f2bd06}}selector input{transition:border-color .18s,box-shadow .18s,background-color .18s}selector input:hover{border-color:#c9c6bf}selector input:focus{border-color:#fbc925;box-shadow:0 0 0 3px rgba(251,201,37,.18);background:rgba(255,255,255,.16);outline:0}' . $motion,
        'dc-faq-section' => '@media(hover:hover) and (pointer:fine){selector .elementor-accordion-item{transition:color .18s ease,background-color .18s ease}selector .elementor-accordion-item:hover{color:#000;background:rgba(19,20,23,.025)}selector .elementor-accordion-icon{transition:color .18s ease,transform .18s ease}selector .elementor-accordion-item:hover .elementor-accordion-icon{color:#131417;transform:rotate(90deg)}}' . $motion,
        'dc-enquiry-section' => '@media(hover:hover) and (pointer:fine){selector .elementor-button:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(19,20,23,.12);background:#f2bd06}}selector input,selector textarea{transition:border-color .18s,box-shadow .18s,background-color .18s}selector input:hover,selector textarea:hover{border-color:#c9c6bf}selector input:focus,selector textarea:focus{border-color:#fbc925;box-shadow:0 0 0 3px rgba(251,201,37,.18);background:#fff;outline:0}' . $motion,
    );
}

if ( defined( 'WP_CLI' ) && WP_CLI && ! defined( 'DESIGN_CORE_AIR_HOVER_BLUEPRINT_ONLY' ) ) {
    if ( ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Administrator capability is required.' ); }
    $post_id = 2;
    $elements = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
    if ( ! is_array( $elements ) ) { throw new RuntimeException( 'Page ID 2 Elementor data is invalid.' ); }
    $contract = design_core_air_hover_contract();
    $found = array();
    foreach ( $elements as &$element ) {
        $classes = ' ' . (string) ( $element['settings']['css_classes'] ?? '' ) . ' ';
        foreach ( $contract as $owner => $css ) {
            if ( false === strpos( $classes, ' ' . $owner . ' ' ) ) { continue; }
            $existing = (string) ( $element['settings']['custom_css'] ?? '' );
            $existing = preg_replace( '/\s*\/\* DC_HOVER_BEGIN \*\/.*?\/\* DC_HOVER_END \*\/\s*/s', "\n", $existing );
            $element['settings']['custom_css'] = trim( $existing ) . "\n/* DC_HOVER_BEGIN */\n" . $css . "\n/* DC_HOVER_END */";
            $found[] = $owner;
        }
    }
    unset( $element );
    $missing = array_values( array_diff( array_keys( $contract ), $found ) );
    if ( $missing ) { throw new RuntimeException( 'Hover owners missing: ' . implode( ', ', $missing ) ); }
    $adapter = new Design_Core_Elementor_V3_Adapter();
    $saved = $adapter->save_page( $post_id, $elements, array( 'template' => 'elementor_header_footer', 'hide_title' => 'yes' ) );
    if ( is_wp_error( $saved ) ) { throw new RuntimeException( $saved->get_error_message() ); }
    $reloaded = $adapter->reload( $post_id );
    foreach ( $contract as $owner => $css ) {
        $persisted = false;
        foreach ( $reloaded as $element ) { $classes = ' ' . (string) ( $element['settings']['css_classes'] ?? '' ) . ' '; if ( false !== strpos( $classes, ' ' . $owner . ' ' ) && false !== strpos( (string) ( $element['settings']['custom_css'] ?? '' ), 'DC_HOVER_BEGIN' ) ) { $persisted = true; break; } }
        if ( ! $persisted ) { throw new RuntimeException( 'Hover contract did not persist for ' . $owner ); }
    }
    if ( class_exists( '\Elementor\Plugin' ) ) { \Elementor\Plugin::$instance->files_manager->clear_cache(); }
    WP_CLI::print_value( array( 'status' => 'pass', 'post_id' => $post_id, 'owners' => count( $contract ), 'page_posts_created' => 0 ) );
}

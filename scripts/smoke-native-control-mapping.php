<?php
/** Runtime save/reload/render smoke for native control mapping. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

add_filter( 'design_core_elementor_run_browser_analysis', '__return_false', 100 );
$html = '<section class="dc-native-smoke"><h2>Native control smoke</h2><p class="quoted">Unicode ✓ C:\\cargo</p><a href="https://example.com">Continue</a></section>';
$css = '.dc-native-smoke{display:flex;flex-direction:column;padding:40px;background-color:#ffffff}.dc-native-smoke h2{color:#112233;font-family:Poppins;font-weight:600;font-size:48px;line-height:1.1;text-wrap:balance}.dc-native-smoke p{color:#445566;font-size:16px}.dc-native-smoke a{color:#ffffff;background-color:#173f35;padding:12px;border-radius:8px}@media(max-width:767px){.dc-native-smoke h2{font-size:24px}}';
$analysis = ( new Design_Core_Elementor_Analysis_Engine() )->analyze_html( $html, $css, 'native-control-smoke' );
$engine = new Design_Core_Elementor_Mapping_Engine();
$elements = $engine->map_ir( $analysis['design_ir'] );
$heading = $elements[0]['elements'][0]['settings'] ?? array();
if ( '#112233' !== ( $heading['title_color'] ?? '' ) || 'Poppins' !== ( $heading['typography_font_family'] ?? '' ) || 48.0 !== (float) ( $heading['typography_font_size']['size'] ?? 0 ) || 24.0 !== (float) ( $heading['typography_font_size_mobile']['size'] ?? 0 ) ) {
    throw new RuntimeException( 'Native heading controls were not mapped.' );
}
if ( false === strpos( (string) ( $heading['custom_css'] ?? '' ), 'selector {' ) ) {
    throw new RuntimeException( 'Scoped CSS fallback was not owned by the heading widget.' );
}
$post_id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Design Core native control smoke', 'post_status' => 'draft', 'post_content' => '' ) );
if ( is_wp_error( $post_id ) || ! $post_id ) { throw new RuntimeException( 'Smoke page creation failed.' ); }
try {
    $adapter = new Design_Core_Elementor_V3_Adapter();
    $saved = $adapter->save_page( $post_id, $elements, array() );
    if ( is_wp_error( $saved ) ) { throw new RuntimeException( $saved->get_error_message() ); }
    $saved_again = $adapter->save_page( $post_id, $elements, array() );
    if ( is_wp_error( $saved_again ) ) { throw new RuntimeException( 'Idempotent save failed: ' . $saved_again->get_error_message() ); }
    $reloaded = $adapter->reload( $post_id );
    $rendered = $adapter->render( $post_id );
    $generated_css = ( new \Elementor\Core\Files\CSS\Post( $post_id ) )->get_content();
    $architecture = ( new Design_Core_Elementor_Architecture_Auditor() )->audit( $reloaded );
    $reloaded_heading = $reloaded[0]['elements'][0]['settings'] ?? array();
    $reloaded_text = $reloaded[0]['elements'][1]['settings']['editor'] ?? '';
    $checks = array(
        'heading_color' => '#112233' === ( $reloaded_heading['title_color'] ?? '' ),
        'mobile_setting' => 24.0 === (float) ( $reloaded_heading['typography_font_size_mobile']['size'] ?? 0 ),
        'quoted_html' => false !== strpos( $reloaded_text, 'class="quoted"' ),
        'unicode_backslash' => false !== strpos( $reloaded_text, 'Unicode ✓ C:\\cargo' ),
        'custom_css_rendered' => false !== strpos( $generated_css, 'text-wrap: balance' ),
        'mobile_css_rendered' => 1 === preg_match( '/font-size:\s*24(?:\.0)?px/', $generated_css ),
        'frontend_rendered' => '' !== trim( $rendered ),
        'architecture' => 'pass' === $architecture['status'],
    );
    if ( in_array( false, $checks, true ) ) {
        throw new RuntimeException( 'Smoke checks failed: ' . wp_json_encode( array_keys( array_filter( $checks, static function ( $passed ) { return ! $passed; } ) ) ) );
    }
    $result = array(
        'status' => 'pass',
        'post_id' => (int) $post_id,
        'roots' => count( $reloaded ),
        'rendered_bytes' => strlen( $rendered ),
        'mapping_report' => $engine->mapping_report(),
        'architecture' => $architecture,
    );
    WP_CLI::log( wp_json_encode( $result ) );
} finally {
    wp_delete_post( $post_id, true );
}

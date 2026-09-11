<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$component_snapshot = get_option( Design_Core_Elementor_Component_Registry::OPTION_KEY, null );
$evidence_snapshot = get_option( Design_Core_Elementor_Runtime_Evidence::OPTION_KEY, null );
$kit = \Elementor\Plugin::instance()->kits_manager->get_active_kit();
$kit_snapshot = $kit && method_exists( $kit, 'get_settings' ) ? $kit->get_settings() : null;
$created = array();
try {
    wp_set_current_user( 1 );
    // Isolate the reuse scenario from component masters created by earlier runtime suites.
    // The exact pre-test registry is restored in finally.
    update_option( Design_Core_Elementor_Component_Registry::OPTION_KEY, array(), false );
    $converter = new Design_Core_Elementor_HTML_Converter();
    $a = $converter->convert_to_elementor( '<section><article class="feature-card"><h3>A</h3><p>One</p></article></section>', '.feature-card{padding:24px;border-radius:16px}', 'DC Integration Reuse A' );
    if ( 'success' !== ( $a['status'] ?? '' ) ) { throw new RuntimeException( 'Page A failed: ' . ( $a['error'] ?? 'unknown' ) ); }
    $created[] = (int) $a['page_id'];
    $b = $converter->convert_to_elementor( '<section><article class="feature-card"><h3>B</h3><p>Two</p></article></section>', '.feature-card{padding:99px;border-radius:2px}', 'DC Integration Reuse B' );
    if ( 'success' !== ( $b['status'] ?? '' ) ) { throw new RuntimeException( 'Page B failed: ' . ( $b['error'] ?? 'unknown' ) ); }
    $created[] = (int) $b['page_id'];
    if ( 'native-compose' !== ( $a['build_plan']['items'][0]['strategy'] ?? '' ) || 'reuse-component' !== ( $b['build_plan']['items'][0]['strategy'] ?? '' ) ) { throw new RuntimeException( 'Page B was not executed through the reusable component strategy.' ); }
    $reused_nodes = array_values( array_filter( $b['design_ir']['nodes'], static function ( $node ) { return ! empty( $node['component']['master_id'] ) && 'feature-card' === ( $node['semantic']['component_type'] ?? '' ); } ) );
    $source_nodes = array_values( array_filter( $b['analysis']['design_ir']['nodes'], static function ( $node ) { return 'feature-card' === ( $node['semantic']['component_type'] ?? '' ); } ) );
    if ( empty( $reused_nodes ) || 99 !== (int) ( $source_nodes[0]['spacing']['padding']['value'] ?? 0 ) || 24 !== (int) ( $reused_nodes[0]['spacing']['padding']['value'] ?? 0 ) ) { throw new RuntimeException( 'Page B did not instantiate shared structure/spacing from the component master.' ); }
    $master_ids = array_column( array_merge( $a['reuse'] ?? array(), $b['reuse'] ?? array() ), 'master_id' );
    if ( 1 !== count( array_unique( $master_ids ) ) ) { throw new RuntimeException( 'Pages did not share one master.' ); }
    $master = ( new Design_Core_Elementor_Component_Registry() )->get( $master_ids[0] );
    if ( 2 !== ( $master['usage']['count'] ?? 0 ) || 2 !== count( $master['instances'] ?? array() ) ) { throw new RuntimeException( 'Usage or instance binding count is invalid.' ); }
    $instances = array_values( $master['instances'] );
    if ( $instances[0]['content_bindings'] === $instances[1]['content_bindings'] ) { throw new RuntimeException( 'Instance content was not preserved independently.' ); }
    echo wp_json_encode( array( 'status' => 'pass', 'schema' => $a['design_ir']['schema_version'], 'plan' => $a['build_plan']['build_plan_schema_version'], 'page_a_strategy' => $a['build_plan']['items'][0]['strategy'], 'page_b_strategy' => $b['build_plan']['items'][0]['strategy'], 'master_id' => $master_ids[0], 'usage' => $master['usage']['count'], 'instances' => count( $instances ) ) ) . PHP_EOL;
} finally {
    foreach ( $created as $post_id ) { wp_delete_post( $post_id, true ); }
    if ( null === $component_snapshot ) { delete_option( Design_Core_Elementor_Component_Registry::OPTION_KEY ); } else { update_option( Design_Core_Elementor_Component_Registry::OPTION_KEY, $component_snapshot, false ); }
    if ( null === $evidence_snapshot ) { delete_option( Design_Core_Elementor_Runtime_Evidence::OPTION_KEY ); } else { update_option( Design_Core_Elementor_Runtime_Evidence::OPTION_KEY, $evidence_snapshot, false ); }
    if ( is_array( $kit_snapshot ) && $kit && method_exists( $kit, 'update_settings' ) ) { $kit->update_settings( $kit_snapshot ); }
}

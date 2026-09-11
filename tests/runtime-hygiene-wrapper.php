<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$suite = isset( $args[0] ) ? (string) $args[0] : '';
$tests_root = realpath( DESIGN_CORE_ELEMENTOR_PATH . 'tests' );
$suite_path = $suite ? realpath( $tests_root . '/' . ltrim( $suite, '/' ) ) : false;
if ( ! $suite_path || 0 !== strpos( $suite_path, $tests_root . DIRECTORY_SEPARATOR ) || 'php' !== strtolower( pathinfo( $suite_path, PATHINFO_EXTENSION ) ) ) {
    throw new RuntimeException( 'A valid runtime suite path under tests/ is required.' );
}

$run_id = wp_generate_uuid4();
$created = array();
$registry_classes = array( 'Design_Core_Elementor_Component_Registry', 'Design_Core_Elementor_Widget_Registry', 'Design_Core_Elementor_Token_Registry', 'Design_Core_Elementor_Runtime_Evidence' );
$option_keys = array();
foreach ( $registry_classes as $registry_class ) {
    $constant = $registry_class . '::OPTION_KEY';
    if ( class_exists( $registry_class ) && defined( $constant ) ) { $option_keys[] = constant( $constant ); }
}
$option_snapshots = array();
foreach ( $option_keys as $key ) { $option_snapshots[ $key ] = get_option( $key, null ); }
$kit = class_exists( '\Elementor\Plugin' ) && ! empty( \Elementor\Plugin::$instance->kits_manager ) ? \Elementor\Plugin::$instance->kits_manager->get_active_kit() : null;
$kit_snapshot = $kit && method_exists( $kit, 'get_settings' ) ? $kit->get_settings() : null;
if ( class_exists( 'Design_Core_Elementor_Component_Registry' ) ) { delete_option( Design_Core_Elementor_Component_Registry::OPTION_KEY ); }
$track = static function ( $post_id, $post, $update ) use ( &$created, $run_id ) {
    if ( $update || ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'page', 'elementor_library' ), true ) ) { return; }
    $created[] = (int) $post_id;
    update_post_meta( $post_id, '_design_core_test_artifact', $run_id );
};
add_action( 'wp_after_insert_post', $track, 1, 3 );

$cleanup = static function () use ( &$created, $run_id, $option_snapshots, $kit, $kit_snapshot ) {
    foreach ( array_unique( array_map( 'intval', $created ) ) as $post_id ) {
        if ( $run_id === get_post_meta( $post_id, '_design_core_test_artifact', true ) ) { wp_delete_post( $post_id, true ); }
    }
    foreach ( $option_snapshots as $key => $snapshot ) {
        if ( null === $snapshot ) { delete_option( $key ); } else { update_option( $key, $snapshot, false ); }
    }
    if ( is_array( $kit_snapshot ) && $kit && method_exists( $kit, 'update_settings' ) ) { $kit->update_settings( $kit_snapshot ); }
};
register_shutdown_function( $cleanup );

try {
    require $suite_path;
} finally {
    remove_action( 'wp_after_insert_post', $track, 1 );
    $cleanup();
}

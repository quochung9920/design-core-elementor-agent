<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
$GLOBALS['dc_meta'] = array();
function update_post_meta( $post_id, $key, $value ) { $GLOBALS['dc_meta'][ $post_id ][ $key ] = $value; return true; }
function get_post_meta( $post_id, $key, $single = false ) { return $GLOBALS['dc_meta'][ $post_id ][ $key ] ?? ''; }
function metadata_exists( $meta_type, $post_id, $meta_key ) { return array_key_exists( $meta_key, $GLOBALS['dc_meta'][ (int) $post_id ] ?? array() ); }
function get_post_field( $field, $post_id, $context = 'display' ) { return ''; }
function get_post( $post_id ) { return null; }
dc_require( array( 'core/change-ledger.php', 'core/elementor-persistence-service.php', 'core/elementor-adapter-interface.php', 'core/elementor-v3-adapter.php' ) );

$adapter = new Design_Core_Elementor_V3_Adapter();
$valid = array(
    array(
        'id' => 'root1234', 'elType' => 'container', 'settings' => array(),
        'elements' => array(
            array( 'id' => 'text1234', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => '<p class="quoted">Unicode ✓ \\ slash</p>' ), 'elements' => array() ),
        ),
    ),
);
$result = $adapter->save_page( 99, $valid );
dc_assert( is_wp_error( $result ) && 'design_core_elementor_runtime_unavailable' === $result->get_error_code(), 'V3 adapter fails closed when Elementor runtime is unavailable' );
dc_assert( empty( $GLOBALS['dc_meta'][99]['_elementor_data'] ), 'Fail-closed path never writes raw Elementor JSON directly' );

$invalid_nested = $valid;
$invalid_nested[0]['elements'][0]['settings'] = 'not-an-array';
dc_assert( false === $adapter->validate( $invalid_nested ), 'Nested Elementor payload shape is validated recursively' );

dc_finish( 'Elementor V3 adapter safety' );

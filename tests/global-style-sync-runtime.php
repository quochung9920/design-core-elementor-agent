<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$kit = \Elementor\Plugin::instance()->kits_manager->get_active_kit();
if ( ! $kit || ! method_exists( $kit, 'update_settings' ) ) { throw new RuntimeException( 'Active Elementor Kit is unavailable.' ); }
$kit_id = \Elementor\Plugin::instance()->kits_manager->get_active_id();
$token_key = Design_Core_Elementor_Design_Token_Service::OPTION_KEY;
$token_exists = false !== get_option( $token_key, false );
$token_snapshot = get_option( $token_key, null );
$ownership_key = Design_Core_Elementor_Global_Style_Bridge::V3_OWNERSHIP_OPTION;
$ownership_exists = false !== get_option( $ownership_key, false );
$ownership_snapshot = get_option( $ownership_key, null );
$kit_snapshot = get_post_meta( $kit_id, '_elementor_page_settings', true );
$kit_snapshot = is_array( $kit_snapshot ) ? $kit_snapshot : array();
$custom_colors_before = array_values( (array) ( $kit_snapshot['custom_colors'] ?? array() ) );
$custom_typography_before = array_values( (array) ( $kit_snapshot['custom_typography'] ?? array() ) );
$failure = null;

$assert = static function ( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$find = static function ( array $items, $id ) {
    foreach ( $items as $item ) { if ( $id === ( $item['_id'] ?? '' ) ) { return $item; } }
    return null;
};

try {
    $collision_id = substr( md5( sanitize_key( 'dc-color-runtime-brand' ) ), 0, 7 );
    $collision_item = array( '_id' => $collision_id, 'title' => 'User Collision', 'color' => '#abcdef' );
    $collision_settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
    $collision_settings = is_array( $collision_settings ) ? $collision_settings : array();
    $collision_settings['custom_colors'] = array_merge( $custom_colors_before, array( $collision_item ) );
    $kit->update_settings( array( 'custom_colors' => $collision_settings['custom_colors'] ) );

    $tokens = new Design_Core_Elementor_Design_Token_Service();
    $tokens->save( array(
        'colors' => array( 'runtime-brand' => '#123456' ),
        'typography' => array(
            'runtime-heading' => array( 'family' => 'Geist', 'weight' => '600', 'size' => '34', 'line_height' => '1.1', 'letter_spacing' => '-2', 'font_size_mobile' => '26' ),
        ),
    ) );
    $bridge = new Design_Core_Elementor_Global_Style_Bridge( $tokens );
    $first = $bridge->sync_v3_kit();
    $assert( 'synced' === ( $first['status'] ?? '' ), 'Initial V3 global sync failed.' );

    $persisted = get_post_meta( $kit_id, '_elementor_page_settings', true );
    $colors = array_values( (array) ( $persisted['custom_colors'] ?? array() ) );
    $types = array_values( (array) ( $persisted['custom_typography'] ?? array() ) );
    $color_ref_query = array(); parse_str( (string) parse_url( $first['colors']['runtime-brand'] ?? '', PHP_URL_QUERY ), $color_ref_query );
    $color_id = (string) ( $color_ref_query['id'] ?? '' );
    $type_id = substr( md5( sanitize_key( 'dc-type-runtime-heading' ) ), 0, 7 );
    $color = $find( $colors, $color_id );
    $type = $find( $types, $type_id );

    $assert( 1440.0 === (float) ( $persisted['container_width']['size'] ?? -1 ) && 'px' === ( $persisted['container_width']['unit'] ?? '' ), 'Global container width was not synchronized through the native Elementor Kit control.' );
    $assert( false !== strpos( (string) ( $persisted['custom_css'] ?? '' ), Design_Core_Elementor_Global_Layout_Standard::CSS_START ), 'Managed global layout CSS was not synchronized into the Elementor Pro Kit.' );
    $assert( false !== strpos( (string) ( $persisted['custom_css'] ?? '' ), 'clamp(16px, 4vw, 24px)' ), 'Fluid mobile container padding is missing from the managed Kit CSS.' );
    $assert( 'dc-global-container' === ( $first['layout']['class'] ?? '' ), 'V3 sync does not expose the governed global container class.' );

    $assert( $color_id && $collision_id !== $color_id, 'A user-owned stable-ID collision was overwritten instead of receiving an alternate Design Core ID.' );
    $assert( $collision_item === $find( $colors, $collision_id ), 'The colliding user-owned global was not preserved byte-for-byte.' );
    $assert( is_array( $color ) && '#123456' === strtolower( (string) ( $color['color'] ?? '' ) ), 'Global color was not persisted with its collision-safe stable ID.' );
    $assert( is_array( $type ) && 'Geist' === ( $type['typography_font_family'] ?? '' ), 'Typography family alias did not persist canonically.' );
    $assert( '600' === (string) ( $type['typography_font_weight'] ?? '' ), 'Typography weight alias did not persist canonically.' );
    $assert( 34.0 === (float) ( $type['typography_font_size']['size'] ?? -1 ), 'Typography size did not persist as an Elementor slider value.' );
    $assert( 'px' === ( $type['typography_font_size']['unit'] ?? '' ), 'Typography size unit is not Elementor-compatible.' );
    $assert( 1.1 === (float) ( $type['typography_line_height']['size'] ?? -1 ) && 'em' === ( $type['typography_line_height']['unit'] ?? '' ), 'Typography line-height did not persist canonically.' );
    $assert( 26.0 === (float) ( $type['typography_font_size_mobile']['size'] ?? -1 ), 'Responsive typography did not persist in the global preset.' );

    foreach ( $custom_colors_before as $before ) {
        $after = $find( $colors, $before['_id'] ?? '' );
        $assert( is_array( $after ) && $after === $before, 'Sync overwrote an unrelated user-owned global color.' );
    }
    foreach ( $custom_typography_before as $before ) {
        $after = $find( $types, $before['_id'] ?? '' );
        $assert( is_array( $after ) && $after === $before, 'Sync overwrote an unrelated user-owned global typography preset.' );
    }

    $tokens->save( array(
        'colors' => array( 'runtime-brand' => '#654321' ),
        'typography' => array( 'runtime-heading' => array( 'family' => 'Geist', 'weight' => '600', 'size' => '34', 'line_height' => '1.1', 'letter_spacing' => '-2', 'font_size_mobile' => '26' ) ),
    ) );
    $second = $bridge->sync_v3_kit();
    $assert( ( $first['colors']['runtime-brand'] ?? '' ) === ( $second['colors']['runtime-brand'] ?? 'changed' ), 'Re-sync changed the stable global reference.' );
    $persisted = get_post_meta( $kit_id, '_elementor_page_settings', true );
    $colors = array_values( (array) ( $persisted['custom_colors'] ?? array() ) );
    $matching = array_values( array_filter( $colors, static function ( $item ) use ( $color_id ) { return $color_id === ( $item['_id'] ?? '' ); } ) );
    $assert( 1 === count( $matching ) && '#654321' === strtolower( (string) ( $matching[0]['color'] ?? '' ) ), 'Re-sync did not update the stable color in place.' );
} catch ( Throwable $exception ) {
    $failure = $exception;
} finally {
    update_post_meta( $kit_id, '_elementor_page_settings', $kit_snapshot );
    if ( $token_exists ) { update_option( $token_key, $token_snapshot, false ); }
    else { delete_option( $token_key ); }
    if ( $ownership_exists ) { update_option( $ownership_key, $ownership_snapshot, false ); }
    else { delete_option( $ownership_key ); }
}

$restored_kit = get_post_meta( $kit_id, '_elementor_page_settings', true );
if ( array_values( (array) ( $restored_kit['custom_colors'] ?? array() ) ) !== $custom_colors_before || array_values( (array) ( $restored_kit['custom_typography'] ?? array() ) ) !== $custom_typography_before ) {
    throw new RuntimeException( 'Runtime global sync test failed to restore the active Elementor Kit.' );
}
if ( get_option( $token_key, null ) !== $token_snapshot ) { throw new RuntimeException( 'Runtime global sync test failed to restore Design Core tokens.' ); }
if ( get_option( $ownership_key, null ) !== $ownership_snapshot ) { throw new RuntimeException( 'Runtime global sync test failed to restore global ownership metadata.' ); }
if ( $failure ) { throw $failure; }

echo "global-style-sync-runtime=pass\n";

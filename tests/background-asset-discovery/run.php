<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
require_once DESIGN_CORE_ELEMENTOR_PATH . 'core/conversion-service.php';

$reflection = new ReflectionClass( 'Design_Core_Elementor_Conversion_Service' );
if ( ! $reflection->hasMethod( 'remote_asset_slots' ) ) {
    dc_assert( false, 'Conversion service exposes governed media asset discovery' );
    dc_finish( 'Background asset discovery' );
}
$service = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod( 'remote_asset_slots' );
$method->setAccessible( true );
$slots = $method->invoke( $service, array(
    'content' => array( 'image' => array( 'url' => 'https://example.test/content.webp' ) ),
    'style' => array( 'background_image' => array( 'url' => 'https://example.test/background.webp', 'id' => 0 ) ),
) );

dc_assert( 'https://example.test/content.webp' === ( $slots['content_image'] ?? '' ), 'Content image is discovered for governed Media Library import' );
dc_assert( 'https://example.test/background.webp' === ( $slots['background_image'] ?? '' ), 'Canonical background image is discovered for governed Media Library import' );
dc_assert( 2 === count( $slots ), 'Only supported remote media slots are returned' );

dc_finish( 'Background asset discovery' );

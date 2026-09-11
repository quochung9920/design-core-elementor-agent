<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array( 'core/figma-raster-asset-resolver.php' ) );
$resolver = new Design_Core_Elementor_Figma_Raster_Asset_Resolver();

function dc_image_node( $id, $scale = 'FILL', $transform = null, array $filters = array() ) {
    $fill = array( 'type' => 'IMAGE', 'visible' => true, 'opacity' => 1, 'imageRef' => 'img-' . $id, 'scaleMode' => $scale );
    if ( null !== $transform ) { $fill['imageTransform'] = $transform; }
    if ( $filters ) { $fill['filters'] = $filters; }
    return array(
        'id' => $id, 'type' => 'RECTANGLE', 'visible' => true,
        'absoluteBoundingBox' => array( 'x' => 0, 'y' => 0, 'width' => 640, 'height' => 420 ),
        'fills' => array( $fill ), 'strokes' => array(), 'effects' => array(), 'children' => array(),
    );
}

$identity = array( array( 1, 0, 0 ), array( 0, 1, 0 ) );
$crop = array( array( 1.35, 0, -0.18 ), array( 0, 1.35, -0.10 ) );
$stretch = dc_image_node( '20:1', 'STRETCH', $crop );
$plain = dc_image_node( '20:2', 'FILL', null );
$identity_stretch = dc_image_node( '20:3', 'STRETCH', $identity );
$filtered = dc_image_node( '20:4', 'FILL', null, array( 'contrast' => 0.2 ) );

$with_child = $stretch;
$with_child['id'] = '20:5';
$with_child['children'] = array( array( 'id' => '20:5:text', 'type' => 'TEXT', 'visible' => true, 'characters' => 'Caption' ) );

$multi_fill = $stretch;
$multi_fill['id'] = '20:6';
$multi_fill['fills'][] = array( 'type' => 'SOLID', 'visible' => true, 'opacity' => 0.25, 'color' => array( 'r' => 0, 'g' => 0, 'b' => 0 ) );

$with_effect = $stretch;
$with_effect['id'] = '20:7';
$with_effect['effects'] = array( array( 'type' => 'DROP_SHADOW', 'visible' => true ) );

dc_assert( $resolver->requires_exact_render( $stretch ), 'raster-assets: STRETCH/imageTransform image atom requires authoritative Figma render' );
dc_assert( ! $resolver->requires_exact_render( $plain ), 'raster-assets: normal FILL image remains editable source media' );
dc_assert( $resolver->requires_exact_render( $identity_stretch ), 'raster-assets: STRETCH scale mode is treated as transform-sensitive even with identity matrix' );
dc_assert( $resolver->requires_exact_render( $filtered ), 'raster-assets: image filters require authoritative rendered atom' );
dc_assert( ! $resolver->requires_exact_render( $with_child ), 'raster-assets: child-bearing layout is not flattened into raster media' );
dc_assert( ! $resolver->requires_exact_render( $multi_fill ), 'raster-assets: multi-paint composition is not double-rendered as one image fill' );
dc_assert( ! $resolver->requires_exact_render( $with_effect ), 'raster-assets: effected structural node remains structural' );

$root = array( 'id' => 'root', 'type' => 'FRAME', 'visible' => true, 'absoluteBoundingBox' => array( 'x' => 0, 'y' => 0, 'width' => 1200, 'height' => 900 ), 'children' => array( $stretch, $plain, $filtered ) );
$ids = $resolver->collect( $root ); sort( $ids );
dc_assert( array( '20:1', '20:4' ) === $ids, 'raster-assets: collection returns only transform-sensitive image atoms' );
dc_assert( $resolver->synthetic_ref( '20:1' ) === $resolver->synthetic_ref( '20:1' ), 'raster-assets: synthetic image refs are stable' );
dc_assert( $resolver->synthetic_ref( '20:1' ) !== $resolver->synthetic_ref( '20:2' ), 'raster-assets: synthetic image refs are node-specific' );

dc_finish( 'Figma transformed raster fidelity' );

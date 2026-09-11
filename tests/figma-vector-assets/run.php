<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array( 'core/figma-vector-asset-resolver.php' ) );
$resolver = new Design_Core_Elementor_Figma_Vector_Asset_Resolver();

function dc_icon_composite( $id, $type, $size ) {
    return array(
        'id' => $id,
        'type' => $type,
        'visible' => true,
        'absoluteBoundingBox' => array( 'x' => 10, 'y' => 10, 'width' => $size, 'height' => $size ),
        'children' => array(
            array(
                'id' => $id . ':glyph',
                'type' => 'VECTOR',
                'visible' => true,
                'absoluteBoundingBox' => array( 'x' => 10, 'y' => 10, 'width' => $size, 'height' => $size ),
            ),
        ),
    );
}

$arrow = dc_icon_composite( '10:1', 'INSTANCE', 17 );
$check = dc_icon_composite( '10:2', 'COMPONENT', 15 );
$clock = dc_icon_composite( '10:3', 'FRAME', 20 );
$dot = array(
    'id' => '10:4', 'type' => 'ELLIPSE', 'visible' => true,
    'absoluteBoundingBox' => array( 'x' => 0, 'y' => 0, 'width' => 9, 'height' => 9 ),
    'fills' => array( array( 'type' => 'SOLID', 'visible' => true ) ),
);
$media = array(
    'id' => '10:5', 'type' => 'FRAME', 'visible' => true,
    'absoluteBoundingBox' => array( 'x' => 0, 'y' => 0, 'width' => 536, 'height' => 470 ),
    'children' => array( array( 'id' => '10:5:glyph', 'type' => 'VECTOR', 'visible' => true ) ),
);
$text_badge = array(
    'id' => '10:6', 'type' => 'FRAME', 'visible' => true,
    'absoluteBoundingBox' => array( 'x' => 0, 'y' => 0, 'width' => 80, 'height' => 24 ),
    'children' => array( array( 'id' => '10:6:text', 'type' => 'TEXT', 'visible' => true, 'characters' => 'NEW' ) ),
);
$image_badge = array(
    'id' => '10:7', 'type' => 'FRAME', 'visible' => true,
    'absoluteBoundingBox' => array( 'x' => 0, 'y' => 0, 'width' => 64, 'height' => 64 ),
    'children' => array( array( 'id' => '10:7:image', 'type' => 'RECTANGLE', 'visible' => true, 'fills' => array( array( 'type' => 'IMAGE', 'visible' => true, 'imageRef' => 'img1' ) ) ) ),
);

dc_assert( $resolver->is_export_candidate( $arrow ), 'vector-assets: 17px INSTANCE icon is exported as an exact SVG composite' );
dc_assert( $resolver->is_export_candidate( $check ), 'vector-assets: 15px COMPONENT icon is exported as an exact SVG composite' );
dc_assert( $resolver->is_export_candidate( $clock ), 'vector-assets: 20px FRAME icon is exported as an exact SVG composite' );
dc_assert( $resolver->is_export_candidate( $dot ), 'vector-assets: 9px ELLIPSE primitive is exported rather than stretched as a container' );
dc_assert( ! $resolver->is_export_candidate( $media ), 'vector-assets: large layout/media frames are never collapsed into icons' );
dc_assert( ! $resolver->is_export_candidate( $text_badge ), 'vector-assets: small composites containing text stay editable structure' );
dc_assert( ! $resolver->is_export_candidate( $image_badge ), 'vector-assets: raster-image composites are not mislabeled as vectors' );

$root = array(
    'id' => 'root', 'type' => 'FRAME', 'visible' => true,
    'absoluteBoundingBox' => array( 'x' => 0, 'y' => 0, 'width' => 1200, 'height' => 920 ),
    'children' => array( $arrow, $check, $clock, $dot, $media ),
);
$ids = $resolver->collect( $root, 48 );
sort( $ids );
dc_assert( array( '10:1', '10:2', '10:3', '10:4', '10:5:glyph' ) === $ids, 'vector-assets: composite icons suppress their child export while unrelated vector leaves remain discoverable' );

dc_finish( 'Figma vector assets' );

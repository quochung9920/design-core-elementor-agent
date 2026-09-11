<?php
/** Exact Figma-node geometry verifier contract. */
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
dc_require( array( 'core/figma-geometry-verifier.php' ) );

$ir = array(
    'root_ids' => array( 'root-ir' ),
    'nodes' => array(
        array( 'id' => 'root-ir', 'source' => array( 'tag' => 'section' ), 'style' => array(), 'figma' => array( 'id' => '4:1049', 'type' => 'SECTION', 'geometry' => array( 'x' => 300, 'y' => 100, 'width' => 1200, 'height' => 920 ) ) ),
        array( 'id' => 'dot-ir', 'source' => array( 'tag' => 'div' ), 'style' => array(), 'figma' => array( 'id' => '4:1087', 'type' => 'ELLIPSE', 'geometry' => array( 'x' => 314, 'y' => 645, 'width' => 9, 'height' => 9 ) ) ),
    ),
);

$analysis = array( 'schema_version' => 2, 'viewports' => array( '1920' => array(
    array( 'tag' => 'section', 'classes' => array( 'elementor-element', 'dc-figma-node-4-1049' ), 'figmaClass' => 'dc-figma-node-4-1049', 'rect' => array( 'x' => 100, 'y' => 20, 'width' => 1200, 'height' => 920 ), 'styles' => array(), 'elementorId' => 'root123', 'elementorType' => 'container', 'widgetType' => '', 'domPath' => '/section[1]' ),
    array( 'tag' => 'div', 'classes' => array( 'elementor-element', 'dc-figma-node-4-1087' ), 'figmaClass' => 'dc-figma-node-4-1087', 'rect' => array( 'x' => 114, 'y' => 565, 'width' => 9, 'height' => 9 ), 'styles' => array(), 'elementorId' => 'dot1234', 'elementorType' => 'container', 'widgetType' => '', 'domPath' => '/section[1]/div[1]' ),
) ) );

$verifier = new Design_Core_Elementor_Figma_Geometry_Verifier();
$report = $verifier->report( $ir, $analysis, 1920 );
dc_assert( 'pass' === ( $report['status'] ?? '' ), 'figma geometry: exact node classes produce a verified report' );
dc_assert( 2 === (int) ( $report['expected'] ?? 0 ) && 2 === (int) ( $report['matched'] ?? 0 ), 'figma geometry: every source node is matched to a rendered owner' );
dc_assert( 1.0 === (float) ( $report['match_ratio'] ?? 0 ), 'figma geometry: match ratio is explicit' );
dc_assert( empty( $report['differences'] ), 'figma geometry: canvas offsets are normalized relative to the root' );

$stretched = $analysis;
$stretched['viewports']['1920'][1]['rect']['width'] = 149;
$bad = $verifier->report( $ir, $stretched, 1920 );
$diff = $bad['differences'][1920]['/figma-node/4-1087']['rect']['width'] ?? array();
dc_assert( 9.0 === (float) ( $diff['reference'] ?? 0 ) && 149.0 === (float) ( $diff['candidate'] ?? 0 ), 'figma geometry: stretched primitive is measured against its exact source node' );
dc_assert( 'dot1234' === ( $bad['differences'][1920]['/figma-node/4-1087']['candidate_meta']['elementor_id'] ?? '' ), 'figma geometry: correction evidence keeps exact Elementor owner' );

$missing = $analysis;
array_pop( $missing['viewports']['1920'] );
$missing_report = $verifier->report( $ir, $missing, 1920 );
dc_assert( 'fail' === ( $missing_report['status'] ?? '' ) && in_array( '4:1087', (array) ( $missing_report['missing'] ?? array() ), true ), 'figma geometry: a missing compiled node fails closed instead of being ignored' );

dc_finish( 'Figma geometry verifier' );

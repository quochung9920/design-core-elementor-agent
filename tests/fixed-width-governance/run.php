<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array(
    'core/layout-media-analyzer.php',
) );

function dc_fw_node( $id, $width ) {
    return array(
        'id' => $id,
        'source' => array( 'tag' => 'div', 'dom_path' => '/body/div[' . $id . ']', 'classes' => array() ),
        'children' => array(),
        'layout' => array( 'width' => $width ),
        'style' => array(),
        'spacing' => array(),
        'responsive' => array(),
        'assets' => array(),
        'interaction' => array(),
        'component' => array(),
        'semantic' => array(),
        'content' => array(),
    );
}

$analyzer = new Design_Core_Elementor_Layout_Media_Analyzer();
$ir = array(
    'nodes' => array(
        dc_fw_node( 'bounded', array( 'value' => 530, 'unit' => 'px' ) ),
        dc_fw_node( 'page-root', array( 'value' => 530, 'unit' => 'px' ) ),
        dc_fw_node( 'too-wide', array( 'value' => 1300, 'unit' => 'px' ) ),
        dc_fw_node( 'fluid', array( 'value' => 100, 'unit' => '%' ) ),
    ),
    'root_ids' => array( 'page-root' ),
);
$enriched = $analyzer->enrich( $ir, array() );
$by_id = array();
foreach ( $enriched['nodes'] as $node ) { $by_id[ $node['id'] ] = $node; }

dc_assert( ! empty( $by_id['bounded']['semantic']['fixed_width_violation'] ), 'bounded box is still flagged as a fixed width' );
$gov = $by_id['bounded']['layout_governance'] ?? array();
dc_assert( ! empty( $gov['fixed_width_exception'] ), 'bounded non-root box gets a governed exception' );
dc_assert( '' !== trim( (string) ( $gov['reason'] ?? '' ) ), 'governed exception carries a non-empty reason' );

dc_assert( ! empty( $by_id['page-root']['semantic']['fixed_width_violation'] ), 'page root is still flagged' );
dc_assert( empty( $by_id['page-root']['layout_governance']['fixed_width_exception'] ), 'page root never gets an exception' );

dc_assert( ! empty( $by_id['too-wide']['semantic']['fixed_width_violation'] ), '1300px box is still flagged' );
dc_assert( empty( $by_id['too-wide']['layout_governance']['fixed_width_exception'] ), 'wider-than-1200px box never gets an exception' );

dc_assert( empty( $by_id['fluid']['semantic']['fixed_width_violation'] ), 'fluid box has no violation' );
dc_assert( empty( $by_id['fluid']['layout_governance']['fixed_width_exception'] ), 'fluid box needs no exception' );

// The governed nodes must survive the real validator instead of fataling.
// (page-root and too-wide are intentionally excluded: they stay fatal.)
dc_require( array( 'core/design-ir-validator.php', 'core/design-ir.php' ) );
$valid_ir = array(
    'schema_version' => 4,
    'type' => 'design-ir',
    'nodes' => array(),
    'root_ids' => array( 'bounded', 'fluid' ),
);
foreach ( $enriched['nodes'] as $node ) {
    if ( ! in_array( $node['id'], array( 'bounded', 'fluid' ), true ) ) { continue; }
    $node['semantic'] = array();
    $node['content'] = array();
    $node['component'] = array( 'fingerprint' => array( 'version' => 2, 'semantic' => 's', 'structure' => 't', 'layout' => 'l', 'interaction' => 'i', 'content_schema' => array() ) );
    $valid_ir['nodes'][] = $node;
}
try {
    ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $valid_ir );
    dc_assert( true, 'governed IR passes the real validator' );
} catch ( Throwable $e ) {
    dc_assert( false, 'governed IR passes the real validator (got: ' . $e->getMessage() . ')' );
}

$report = $analyzer->report( $enriched );
dc_assert( 3 === count( $report['fixed_width_violations'] ), 'report still lists all violations transparently' );
dc_assert( 1 === count( $report['fixed_width_exceptions'] ), 'report lists the single governed exception' );

// Bounded fixed-pixel heights are governed; viewport-unit risks never are.
function dc_fw_media_node( $id, $height, $responsive_height = null ) {
    $node = dc_fw_node( $id, array( 'value' => 100, 'unit' => '%' ) );
    $node['layout']['height'] = $height;
    if ( null !== $responsive_height ) { $node['responsive'] = array( 'mobile' => array( 'layout' => array( 'height' => $responsive_height ), 'style' => array() ) ); }
    return $node;
}

$height_ir = array(
    'nodes' => array(
        dc_fw_media_node( 'hero', array( 'value' => 460, 'unit' => 'px' ) ),
        dc_fw_media_node( 'tower', array( 'value' => 1200, 'unit' => 'px' ) ),
        dc_fw_media_node( 'clippy', array( 'value' => 460, 'unit' => 'px' ), '100vh' ),
    ),
    'root_ids' => array( 'hero', 'tower', 'clippy' ),
);
$height_enriched = $analyzer->enrich( $height_ir, array() );
$h_by_id = array();
foreach ( $height_enriched['nodes'] as $node ) { $h_by_id[ $node['id'] ] = $node; }
dc_assert( ! empty( $h_by_id['hero']['media_governance']['height_exception'] ), 'bounded 460px height gets a governed exception' );
dc_assert( '' !== trim( (string) ( $h_by_id['hero']['media_governance']['reason'] ?? '' ) ), 'height exception carries a reason' );
dc_assert( empty( $h_by_id['tower']['media_governance']['height_exception'] ), '1200px height never gets an exception' );
dc_assert( empty( $h_by_id['clippy']['media_governance']['height_exception'] ), 'viewport-unit risk alongside keeps the node fatal' );

dc_finish( 'Fixed Width Governance' );

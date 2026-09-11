<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array(
    'core/figma-structure-verifier.php',
    'core/figma-font-verifier.php',
    'core/figma-responsive-verifier.php',
) );

$ir = array(
    'root_ids' => array( 'root-ir' ),
    'nodes' => array(
        array(
            'id' => 'root-ir', 'source' => array( 'tag' => 'section' ), 'style' => array(), 'children' => array( 'heading-ir', 'overlay-ir' ),
            'figma' => array( 'id' => '4:1049', 'type' => 'FRAME', 'geometry' => array( 'x' => 0, 'y' => 0, 'width' => 1200, 'height' => 720 ) ),
        ),
        array(
            'id' => 'heading-ir', 'source' => array( 'tag' => 'h1' ), 'children' => array(),
            'style' => array( 'font_family' => 'Manrope' ),
            'figma' => array(
                'id' => '4:1050', 'type' => 'TEXT', 'geometry' => array( 'x' => 0, 'y' => 20, 'width' => 520, 'height' => 200 ),
                'text_composition' => array( 'runs' => array( array( 'text' => 'calm', 'style' => array( 'font_family' => 'Newsreader' ) ) ) ),
            ),
        ),
        array(
            'id' => 'overlay-ir', 'source' => array( 'tag' => 'div' ), 'style' => array(), 'children' => array(),
            'figma' => array( 'id' => '4:1127', 'type' => 'FRAME', 'geometry' => array( 'x' => 650, 'y' => 600, 'width' => 280, 'height' => 74 ) ),
        ),
    ),
);

$analysis = array( 'schema_version' => 6, 'viewports' => array( '1440' => array(
    array(
        'tag' => 'section', 'classes' => array( 'elementor-element', 'dc-figma-node-4-1049' ), 'figmaClass' => 'dc-figma-node-4-1049', 'figmaParentClass' => '',
        'rect' => array( 'x' => 100, 'y' => 40, 'width' => 1200, 'height' => 720 ), 'styles' => array(), 'elementorId' => 'root1234', 'parentElementorId' => '',
    ),
    array(
        'tag' => 'h1', 'classes' => array( 'elementor-heading-title', 'dc-figma-node-4-1050' ), 'figmaClass' => 'dc-figma-node-4-1050', 'figmaParentClass' => 'dc-figma-node-4-1049',
        'rect' => array( 'x' => 100, 'y' => 60, 'width' => 520, 'height' => 200 ), 'styles' => array( 'fontFamily' => 'Manrope, sans-serif' ),
        'fontFamilyPrimary' => 'Manrope', 'fontLoaded' => true, 'elementorId' => 'head1234', 'parentElementorId' => 'root1234',
    ),
    array(
        'tag' => 'span', 'classes' => array( 'dc-figma-node-4-1050' ), 'figmaClass' => 'dc-figma-node-4-1050', 'figmaParentClass' => 'dc-figma-node-4-1049',
        'rect' => array( 'x' => 240, 'y' => 150, 'width' => 90, 'height' => 62 ), 'styles' => array( 'fontFamily' => 'Newsreader, serif' ),
        'fontFamilyPrimary' => 'Newsreader', 'fontLoaded' => true, 'elementorId' => 'head1234', 'parentElementorId' => 'root1234',
    ),
    array(
        'tag' => 'div', 'classes' => array( 'elementor-element', 'dc-figma-node-4-1127' ), 'figmaClass' => 'dc-figma-node-4-1127', 'figmaParentClass' => 'dc-figma-node-4-1049',
        'rect' => array( 'x' => 750, 'y' => 640, 'width' => 280, 'height' => 74 ), 'styles' => array(), 'elementorId' => 'card1234', 'parentElementorId' => 'root1234',
    ),
) ) );

$structure = new Design_Core_Elementor_Figma_Structure_Verifier();
$structure_pass = $structure->report( $ir, $analysis, 1440 );
dc_assert( 'pass' === ( $structure_pass['status'] ?? '' ), 'strict-verifiers: exact Figma parent ownership passes' );
dc_assert( 1.0 === (float) ( $structure_pass['parent_match_ratio'] ?? 0 ), 'strict-verifiers: parent match ratio is explicit' );

$wrong_parent = $analysis;
$wrong_parent['viewports']['1440'][3]['figmaParentClass'] = 'dc-figma-node-9-9999';
$structure_fail = $structure->report( $ir, $wrong_parent, 1440 );
dc_assert( 'fail' === ( $structure_fail['status'] ?? '' ) && ! empty( $structure_fail['issues'] ), 'strict-verifiers: wrong composition parent fails closed' );
dc_assert( 'card1234' === ( $structure_fail['issues'][0]['elementor_id'] ?? '' ), 'strict-verifiers: structure failure keeps exact Elementor owner' );

$font = new Design_Core_Elementor_Figma_Font_Verifier();
$font_pass = $font->report( $ir, $analysis, 1440 );
dc_assert( 'pass' === ( $font_pass['status'] ?? '' ), 'strict-verifiers: authored base and mixed-run fonts are browser-proven' );
dc_assert( 2 === (int) ( $font_pass['expected'] ?? 0 ) && 2 === (int) ( $font_pass['verified'] ?? 0 ), 'strict-verifiers: both Manrope and Newsreader are verified' );

$font_bad = $analysis;
$font_bad['viewports']['1440'][2]['fontFamilyPrimary'] = 'Georgia';
$font_bad['viewports']['1440'][2]['styles']['fontFamily'] = 'Georgia, serif';
$font_fail = $font->report( $ir, $font_bad, 1440 );
dc_assert( 'fail' === ( $font_fail['status'] ?? '' ) && 1 === (int) ( $font_fail['issue_count'] ?? 0 ), 'strict-verifiers: fallback font cannot masquerade as source typography' );
dc_assert( 'Newsreader' === ( $font_fail['issues'][0]['expected_family'] ?? '' ), 'strict-verifiers: font issue identifies the missing authored family' );

$responsive_analysis = $analysis;
foreach ( array( 1024, 768, 390 ) as $viewport ) {
    $responsive_analysis['viewports'][ (string) $viewport ] = array(
        array(
            'tag' => 'section', 'classes' => array( 'elementor-element', 'dc-figma-node-4-1049' ), 'figmaClass' => 'dc-figma-node-4-1049', 'figmaParentClass' => '',
            'rect' => array( 'x' => 0, 'y' => 0, 'width' => $viewport, 'height' => 900 ), 'styles' => array(), 'elementorId' => 'root1234', 'parentElementorId' => '',
        ),
    );
}
$responsive = new Design_Core_Elementor_Figma_Responsive_Verifier();
$responsive_pass = $responsive->report( $ir, $responsive_analysis, array( 1024, 768, 390 ) );
dc_assert( 'pass' === ( $responsive_pass['status'] ?? '' ) && 'inferred-runtime' === ( $responsive_pass['mode'] ?? '' ), 'strict-verifiers: responsive runtime safety is explicitly verified as inferred, not mobile pixel fidelity' );

$responsive_bad = $responsive_analysis;
$responsive_bad['viewports']['390'][0]['rect']['width'] = 1200;
$responsive_fail = $responsive->report( $ir, $responsive_bad, array( 390 ) );
dc_assert( 'fail' === ( $responsive_fail['status'] ?? '' ) && ! empty( $responsive_fail['issues'] ), 'strict-verifiers: fixed desktop-width root fails mobile runtime verification' );

dc_finish( 'Figma strict rendered verifiers' );

<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array(
    'core/breakpoint-registry.php',
    'core/responsive-compiler.php',
) );

function dc_rc_node( $id, $classes = array(), $layout = array() ) {
    return array(
        'id' => $id,
        'source' => array( 'tag' => 'div', 'dom_path' => '/body/div[' . $id . ']', 'classes' => $classes ),
        'children' => array(),
        'layout' => $layout,
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

function dc_rc_compile( $nodes, $css ) {
    $compiler = new Design_Core_Elementor_Responsive_Compiler();
    list( $ir ) = $compiler->compile( array( 'nodes' => $nodes, 'root_ids' => array(), 'diagnostics' => array() ), $css );
    $by_id = array();
    foreach ( $ir['nodes'] as $node ) { $by_id[ $node['id'] ] = $node; }
    return array( $by_id, $ir['diagnostics']['responsive_mapping'] ?? array() );
}

// Exact breakpoint match.
list( $nodes ) = dc_rc_compile(
    array( dc_rc_node( 'a', array( 'box' ), array( 'display' => 'flex' ) ) ),
    '@media(max-width:767px){.box{flex-direction:column}}'
);
dc_assert( 'column' === ( $nodes['a']['responsive']['mobile']['layout']['direction'] ?? null ), 'exact 767px query maps to mobile' );

// Close queries merge onto the nearest active device (no silent downgrade).
list( $nodes, $mapping ) = dc_rc_compile(
    array( dc_rc_node( 'b', array( 'grid' ), array( 'display' => 'grid' ) ) ),
    '@media(max-width:1100px){.grid{gap:32px}}@media(max-width:900px){.grid{gap:24px}}@media(max-width:600px){.grid{gap:16px}}'
);
dc_assert( array( 'value' => 24.0, 'unit' => 'px' ) == ( $nodes['b']['responsive']['tablet']['layout']['gap'] ?? null ), '1100px and 900px merge onto tablet, later order wins' );
dc_assert( array( 'value' => 16.0, 'unit' => 'px' ) == ( $nodes['b']['responsive']['mobile']['layout']['gap'] ?? null ), '600px lands on mobile' );

// Ranges classify by their max bound; non-width media stays unmapped.
list( $nodes, $mapping ) = dc_rc_compile(
    array( dc_rc_node( 'c', array( 'row' ) ) ),
    '@media (max-width: 720px) and (min-width: 601px){.row{padding:8px}}@media (hover:hover) and (pointer:fine){.row{padding:4px}}'
);
dc_assert( isset( $nodes['c']['responsive']['mobile']['spacing']['padding'] ), '720px range classifies to mobile' );
dc_assert( 1 === count( array_filter( $mapping['rules_unmapped'], static function ( $u ) { return 'no-active-device-match' === ( $u['reason'] ?? '' ); } ) ), 'hover media is recorded unmapped, not guessed' );

// Values identical to desktop are redundant, not overrides.
list( $nodes ) = dc_rc_compile(
    array( dc_rc_node( 'd', array( 'same' ), array( 'display' => 'flex' ) ) ),
    '@media(max-width:767px){.same{display:flex}}'
);
dc_assert( empty( $nodes['d']['responsive'] ), 'desktop-identical values are skipped' );

// Single-side longhands complete from the desktop box instead of zeroing siblings.
$with_pad = dc_rc_node( 'e', array( 'pad' ) );
$with_pad['spacing'] = array( 'padding' => array( 'top' => 32, 'right' => 32, 'bottom' => 32, 'left' => 32, 'unit' => 'px' ) );
list( $nodes ) = dc_rc_compile(
    array( $with_pad ),
    '@media(max-width:767px){.pad{padding-top:12px}}'
);
$box = $nodes['e']['responsive']['mobile']['spacing']['padding'] ?? array();
dc_assert( 12.0 == ( $box['top'] ?? null ) && 32.0 == ( $box['right'] ?? null ) && 'px' === ( $box['unit'] ?? '' ), 'longhand merges with desktop siblings' );

// Grid columns become a declared column count.
list( $nodes ) = dc_rc_compile(
    array( dc_rc_node( 'f', array( 'cols' ) ) ),
    '@media(max-width:767px){.cols{grid-template-columns:1fr}}@media(max-width:1024px){.cols{grid-template-columns:repeat(2,1fr)}}'
);
dc_assert( 1 === ( $nodes['f']['responsive']['mobile']['layout']['columns'] ?? 0 ), 'single track maps to one column on mobile' );

// Full breakpoint coverage reports devices and approximate matches separately.
list( $nodes, $mapping ) = dc_rc_compile(
    array( dc_rc_node( 'g', array( 'hero', 'inner' ) ) ),
    '@media(max-width:1100px){.hero .inner{padding:20px}}'
);
dc_assert( 1 === (int) ( $mapping['rules_approximate'] ?? 0 ), 'descendant matches are counted approximate, not lost' );

// Air Consolidation regression fixture: the real source must yield a
// non-zero responsive override count (previously exactly zero).
$fixture = dirname( __DIR__ ) . '/fixtures/air-consolidation.html';
if ( is_readable( $fixture ) ) {
    $loader_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'design-core-elementor.php' );
    preg_match_all( "/'(includes\\/[^']+\\.php|core\\/[^']+\\.php)'/", $loader_source, $loader_matches );
    foreach ( array_unique( $loader_matches[1] ) as $loader_file ) {
        $loader_path = DESIGN_CORE_ELEMENTOR_PATH . $loader_file;
        if ( is_readable( $loader_path ) ) { require_once $loader_path; }
    }
    $source = file_get_contents( $fixture );
    $fixture_css = '';
    $fixture_body = $source;
    if ( preg_match( '/<style[^>]*>(.*?)<\/style>/is', $source, $m ) ) { $fixture_css = $m[1]; }
    if ( preg_match( '/<body[^>]*>(.*?)<\/body>/is', $source, $b ) ) { $fixture_body = $b[1]; }
    $analysis = ( new Design_Core_Elementor_Analysis_Engine() )->analyze_html( $fixture_body, $fixture_css, 'air-fixture' );
    $normalized = ( new Design_Core_Elementor_Normalization_Pipeline() )->normalize( $analysis['design_ir'], $fixture_css );
    $override_nodes = 0;
    foreach ( $normalized['nodes'] as $node ) { if ( ! empty( $node['responsive'] ) ) { $override_nodes++; } }
    dc_assert( 0 < $override_nodes, "air fixture yields responsive overrides ($override_nodes nodes)" );
} else {
    dc_assert( true, 'air fixture missing, skipped' );
}

// The compiler governs what it introduces: bounded heights it writes must
// not trip downstream validation, while viewport-unit risks stay fatal.
list( $nodes ) = dc_rc_compile(
    array( dc_rc_node( 'h', array( 'tall' ), array( 'height' => array( 'value' => 460, 'unit' => 'px' ) ) ) ),
    '@media(max-width:767px){.tall{height:331px}}'
);
dc_assert( ! empty( $nodes['h']['media_governance']['height_exception'] ), 'compiler-introduced bounded height is governed' );

dc_finish( 'Responsive Compiler' );

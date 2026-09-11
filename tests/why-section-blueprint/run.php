<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
dc_require( array( 'core/design-ir.php', 'core/design-ir-validator.php' ) );

$blueprint_file = DESIGN_CORE_ELEMENTOR_PATH . 'scripts/provision-air-why.php';
if ( ! file_exists( $blueprint_file ) ) {
    dc_assert( false, 'Air consolidation why-section blueprint exists' );
    dc_finish( 'Why section blueprint' );
}
require_once $blueprint_file;

if ( ! function_exists( 'design_core_air_why_ir' ) || ! function_exists( 'design_core_air_why_tokens' ) ) {
    dc_assert( false, 'Air consolidation why-section blueprint exposes a canonical IR builder' );
    dc_finish( 'Why section blueprint' );
}

$assets = array(
    array( 'url' => 'https://example.test/uploads/why-1.webp', 'alt' => '' ),
    array( 'url' => 'https://example.test/uploads/why-2.webp', 'alt' => '' ),
    array( 'url' => 'https://example.test/uploads/why-3.webp', 'alt' => '' ),
    array( 'url' => 'https://example.test/uploads/why-4.webp', 'alt' => '' ),
);
$ir = design_core_air_why_ir( $assets );
$why_tokens = design_core_air_why_tokens();
$valid = false;
try {
    $valid = ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
} catch ( Throwable $exception ) {
    $valid = false;
}
dc_assert( true === $valid, 'Why section blueprint is valid canonical Design IR' );
dc_assert( 26 === count( $ir['nodes'] ?? array() ), 'Why section uses an editable native node tree with semantic Gap-owning wrappers' );

$nodes = array();
foreach ( $ir['nodes'] as $node ) { $nodes[ $node['id'] ] = $node; }
$root = $nodes[ $ir['root_ids'][0] ] ?? array();
dc_assert( 'why' === ( $root['source']['attributes']['id'] ?? '' ), 'Why root preserves its jump-link anchor' );
dc_assert( in_array( 'dc-why-section', $root['source']['classes'] ?? array(), true ), 'Why root has a stable editor-facing class' );
dc_assert( 64 === ( $root['responsive']['mobile']['spacing']['padding']['top'] ?? null ), 'Mobile section padding is stored in canonical responsive state' );
dc_assert( ! isset( $nodes['why-container']['layout']['width'], $nodes['why-container']['responsive']['tablet']['layout']['width'] ), 'Global content owner does not carry a conflicting local desktop or tablet width' );
dc_assert( 'center' === ( $root['layout']['align'] ?? '' ), 'Section centers its constrained child through native flex alignment' );
dc_assert( ! isset( $nodes['why-container']['spacing']['padding'] ) && 0 === ( $nodes['why-grid']['spacing']['padding']['top'] ?? null ), 'Global owner leaves clamp gutters untouched while local structural grid clears default padding' );
dc_assert( 'em' === ( $nodes['why-lead']['style']['line_height']['unit'] ?? '' ), 'Unitless source line-height is encoded with a CSS-producing Elementor unit' );
dc_assert( false === stripos( (string) ( $nodes['why-lead']['content']['rich_text'] ?? '' ), '<p' ), 'Text Editor content avoids theme paragraph margins while remaining editable' );
dc_assert( 100 === ( $nodes['why-card-1']['responsive']['mobile']['layout']['width']['value'] ?? null ), 'Benefit cards become full-width through responsive controls' );
dc_assert( 19 === ( $nodes['why-card-1-heading']['responsive']['mobile']['style']['font_size']['value'] ?? null ), 'Card heading mobile typography is explicit' );
dc_assert( 'left' === ( $nodes['why-card-1-icon']['layout']['align'] ?? '' ), 'Benefit icons use native left alignment' );
dc_assert( '#fffaf0' === ( $nodes['why-card-1']['interaction']['hover']['background'] ?? '' ), 'Benefit hover state is represented in canonical interaction data' );
$image_urls = array();
foreach ( $nodes as $node ) {
    if ( 'img' === ( $node['source']['tag'] ?? '' ) ) { $image_urls[] = $node['content']['image']['url'] ?? ''; }
}
dc_assert( array_column( $assets, 'url' ) === $image_urls, 'All benefit icons come from governed local asset bindings' );

$raw_html = false;
foreach ( $nodes as $node ) {
    if ( 'html' === ( $node['source']['tag'] ?? '' ) || false !== stripos( (string) ( $node['content']['rich_text'] ?? '' ), '<style' ) ) { $raw_html = true; }
}
dc_assert( ! $raw_html, 'Why section contains no raw HTML widget or stylesheet' );
dc_assert( 34 === (int) ( $why_tokens['typography']['section-heading']['font_size'] ?? 0 ), 'Why section publishes an exact global section-heading typography token' );
dc_assert( 26 === (int) ( $why_tokens['typography']['section-heading']['font_size_mobile'] ?? 0 ), 'Global section-heading token includes its responsive mobile value' );
dc_assert( 22 === (int) ( $why_tokens['typography']['card-heading']['font_size'] ?? 0 ), 'Why section publishes an exact global card-heading typography token' );
$provision_source = file_get_contents( $blueprint_file );
dc_assert( false !== strpos( $provision_source, '$expected_root_count' ) && false !== strpos( $provision_source, '$untouched_hashes' ) && false === strpos( $provision_source, '3 !== count( $reloaded )' ), 'Why provisioning preserves dynamic document roots and verifies untouched root hashes' );

dc_finish( 'Why section blueprint' );

<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
dc_require( array( 'core/design-ir.php', 'core/design-ir-validator.php' ) );

$script = DESIGN_CORE_ELEMENTOR_PATH . 'scripts/provision-air-compare.php';
if ( ! file_exists( $script ) ) {
    dc_assert( false, 'Air compare blueprint script exists' );
    dc_finish( 'Air compare section' );
}
define( 'DESIGN_CORE_AIR_COMPARE_BLUEPRINT_ONLY', true );
require $script;

$ir = design_core_air_compare_ir();
$nodes = array();
foreach ( $ir['nodes'] as $node ) { $nodes[ $node['id'] ] = $node; }

$gap = static function ( $id, $device = 'desktop' ) use ( $nodes ) {
    if ( 'desktop' === $device ) { return $nodes[ $id ]['layout']['gap']['value'] ?? null; }
    return $nodes[ $id ]['responsive'][ $device ]['layout']['gap']['value'] ?? null;
};
$has_nonzero_margin = static function ( array $node ) {
    $states = array( $node['spacing'] ?? array() );
    foreach ( $node['responsive'] ?? array() as $responsive ) { $states[] = $responsive['spacing'] ?? array(); }
    foreach ( $states as $spacing ) {
        foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
            if ( isset( $spacing['margin'][ $side ] ) && 0.0 !== (float) $spacing['margin'][ $side ] ) { return true; }
        }
    }
    return false;
};

dc_assert( 'fullwidth' === ( $nodes['compare-root']['semantic']['layout_mode'] ?? '' ) && 'surface' === ( $nodes['compare-root']['semantic']['container_role'] ?? '' ), 'Compare section owns a fullwidth white visual surface' );
dc_assert( 'content-driven' === ( $nodes['compare-root']['media']['height_policy'] ?? '' ) && ! isset( $nodes['compare-root']['layout']['min_height'], $nodes['compare-root']['layout']['height'] ), 'Ordinary compare section height is content-driven without fixed or viewport height' );
dc_assert( 100 === ( $nodes['compare-root']['layout']['width']['value'] ?? null ) && '%' === ( $nodes['compare-root']['layout']['width']['unit'] ?? '' ), 'Fullwidth surface remains fluid' );
dc_assert( 'content' === ( $nodes['compare-container']['semantic']['container_role'] ?? '' ), 'Inner Container owns constrained content' );
dc_assert( 'boxed' === ( $nodes['compare-container']['semantic']['layout_mode'] ?? '' ) && 'boxed' === ( $nodes['compare-container']['layout']['content_width'] ?? '' ), 'Constrained content owner uses native Elementor Boxed semantics' );
dc_assert( ! isset( $nodes['compare-container']['layout']['width'], $nodes['compare-container']['layout']['max_width'], $nodes['compare-container']['spacing']['padding'] ), 'Global content owner has no conflicting local width or gutter controls' );
dc_assert( '0px' === ( $nodes['compare-container']['style']['css_fallback']['padding-block'] ?? '' ), 'Global content owner explicitly removes Elementor block padding without overriding clamp gutters' );
dc_assert( array( 'compare-intro', 'compare-body' ) === ( $nodes['compare-container']['children'] ?? array() ), 'Section rhythm is grouped into intro and comparison body' );
dc_assert( 44 === $gap( 'compare-container' ) && 32 === $gap( 'compare-container', 'mobile' ), 'Container Gap owns intro-to-comparison spacing responsively' );
dc_assert( 16 === $gap( 'compare-intro' ) && 14 === $gap( 'compare-intro', 'mobile' ), 'Intro Gap owns title-to-lead spacing' );
dc_assert( 26 === $gap( 'compare-body' ) && 24 === $gap( 'compare-body', 'mobile' ), 'Comparison body Gap owns content-to-note spacing' );

dc_assert( array( 'mobile' ) === ( $nodes['compare-table']['semantic']['hidden_on'] ?? array() ), 'Desktop/tablet comparison table uses native mobile visibility control' );
dc_assert( array( 'desktop', 'laptop', 'tablet' ) === ( $nodes['compare-cards']['semantic']['hidden_on'] ?? array() ), 'Mobile comparison cards use native desktop/laptop/tablet visibility controls' );
dc_assert( 5 === count( $nodes['compare-table']['children'] ?? array() ), 'Native table composition contains one header and four data rows' );
dc_assert( 4 === count( $nodes['compare-cards']['children'] ?? array() ), 'Mobile composition contains four semantic cards' );

$zero_padding_ids = array( 'compare-intro', 'compare-body', 'compare-table', 'compare-header-row', 'compare-row-1', 'compare-row-2', 'compare-row-3', 'compare-row-4', 'compare-cards' );
foreach ( range( 1, 4 ) as $number ) {
    $zero_padding_ids = array_merge( $zero_padding_ids, array( 'compare-card-' . $number . '-details', 'compare-card-' . $number . '-meta', 'compare-card-' . $number . '-cost', 'compare-card-' . $number . '-speed', 'compare-card-' . $number . '-best' ) );
}
foreach ( $zero_padding_ids as $id ) {
    $padding = $nodes[ $id ]['spacing']['padding'] ?? array();
    dc_assert( 0 === ( $padding['top'] ?? null ) && 0 === ( $padding['right'] ?? null ) && 0 === ( $padding['bottom'] ?? null ) && 0 === ( $padding['left'] ?? null ), 'Structural wrapper explicitly neutralizes Elementor default padding: ' . $id );
}

$expected_widths = array( 20.0, 16.5, 26.0, 37.5 );
foreach ( $nodes['compare-table']['children'] as $row_id ) {
    $row = $nodes[ $row_id ];
    dc_assert( 4 === count( $row['children'] ?? array() ), 'Every comparison row has exactly four cells' );
    dc_assert( 'solid' === ( $row['style']['border_style'] ?? '' ) && 1 === ( $row['style']['border_width']['bottom'] ?? null ), 'Every comparison row owns a native 1px bottom divider' );
    $widths = array();
    foreach ( $row['children'] as $cell_id ) { $widths[] = (float) ( $nodes[ $cell_id ]['layout']['max_width']['value'] ?? -1 ); }
    dc_assert( $expected_widths === $widths, 'Row cells preserve source column proportions without fixed pixel Containers' );
}

foreach ( range( 1, 4 ) as $number ) {
    $card = $nodes[ 'compare-card-' . $number ];
    dc_assert( 'article' === ( $card['source']['tag'] ?? '' ) && ! empty( $card['component']['repeated'] ), 'Mobile comparison item is a repeated semantic article' );
    dc_assert( array( 'compare-card-' . $number . '-title', 'compare-card-' . $number . '-details' ) === ( $card['children'] ?? array() ), 'Card groups title and details so Gap owns vertical rhythm' );
    foreach ( array( 'cost', 'speed' ) as $meta_key ) {
        $group = $nodes[ 'compare-card-' . $number . '-' . $meta_key ];
        dc_assert( 46.85 === (float) ( $group['responsive']['mobile']['layout']['width']['value'] ?? -1 ), 'Mobile meta group explicitly retains two-column width against Elementor mobile defaults' );
    }
}
dc_assert( '#f4f3f0' === ( $nodes['compare-card-2']['style']['background'] ?? '' ), 'Air consolidation card owns the source highlight surface' );
dc_assert( ! isset( $nodes['compare-card-1']['style']['background'] ), 'Non-highlight cards do not persist redundant background settings' );
dc_assert( 0.001 > abs( (float) ( $nodes['compare-card-1-title']['style']['line_height']['value'] ?? 0 ) - 22 / 17 ), 'Mobile card title line-height matches the source normal 22px text box' );
dc_assert( 0.001 > abs( (float) ( $nodes['compare-card-1-cost-label']['style']['line_height']['value'] ?? 0 ) - 13 / 9 ), 'Mobile metadata label line-height matches the source normal 13px text box' );
dc_assert( 0.001 > abs( (float) ( $nodes['compare-card-1-cost-value']['style']['line_height']['value'] ?? 0 ) - 20 / 13 ), 'Mobile metadata value line-height matches the source normal 20px text box' );
dc_assert( 0.001 > abs( (float) ( $nodes['compare-card-1-best-label']['style']['line_height']['value'] ?? 0 ) - 13 / 9 ) && 0.001 > abs( (float) ( $nodes['compare-card-1-best-value']['style']['line_height']['value'] ?? 0 ) - 20 / 13 ), 'Mobile best-for label and value retain the same source normal line boxes' );

dc_assert( 'How it compares.' === ( $nodes['compare-title']['content']['text'] ?? '' ), 'Section title remains editable native content' );
dc_assert( false !== strpos( $nodes['compare-note']['content']['text'] ?? '', 'Send us the weight and the deadline' ), 'Decision note remains editable native content' );

$margin_nodes = array();
foreach ( $nodes as $id => $node ) { if ( $has_nonzero_margin( $node ) ) { $margin_nodes[] = $id; } }
dc_assert( array() === $margin_nodes, 'Compare section uses parent Gap and padding rather than child margins' );

$valid = true;
try { ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir ); } catch ( Throwable $exception ) { $valid = false; }
dc_assert( $valid, 'Compare canonical IR passes fail-closed Design Core validation' );

dc_finish( 'Air compare section' );

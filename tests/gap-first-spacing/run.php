<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
dc_require( array( 'core/design-ir.php', 'core/design-ir-validator.php' ) );
define( 'DESIGN_CORE_AIR_WHY_BLUEPRINT_ONLY', true );
require dirname( dirname( __DIR__ ) ) . '/scripts/provision-air-why.php';

$assets = array_fill( 0, 4, array( 'url' => 'https://example.test/icon.webp', 'alt' => '' ) );
$ir = design_core_air_why_ir( $assets );
$nodes = array();
foreach ( $ir['nodes'] as $node ) { $nodes[ $node['id'] ] = $node; }

$gap_value = static function ( $node, $device = 'desktop' ) {
    if ( 'desktop' === $device ) { return $node['layout']['gap']['value'] ?? null; }
    return $node['responsive'][ $device ]['layout']['gap']['value'] ?? null;
};

$has_nonzero_margin = static function ( $node ) {
    $states = array( $node['spacing'] ?? array() );
    foreach ( $node['responsive'] ?? array() as $responsive ) { $states[] = $responsive['spacing'] ?? array(); }
    foreach ( $states as $spacing ) {
        if ( empty( $spacing['margin'] ) ) { continue; }
        foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
            if ( 0.0 !== (float) ( $spacing['margin'][ $side ] ?? 0 ) ) { return true; }
        }
    }
    return false;
};

dc_assert( array( 'why-intro', 'why-grid' ) === ( $nodes['why-container']['children'] ?? array() ), 'Section groups intro content so parent Gap owns section rhythm' );
dc_assert( ! in_array( 'dc-global-container', $nodes['why-container']['source']['classes'] ?? array(), true ), 'Reserved global class is adapter-owned rather than copied into source classes' );
dc_assert( 'fullwidth' === ( $nodes['why-root']['semantic']['layout_mode'] ?? '' ) && 'surface' === ( $nodes['why-root']['semantic']['container_role'] ?? '' ) && 'content' === ( $nodes['why-container']['semantic']['container_role'] ?? '' ), 'Section declares compatible fullwidth surface and constrained content ownership' );
dc_assert( 'boxed' === ( $nodes['why-container']['semantic']['layout_mode'] ?? '' ) && 'boxed' === ( $nodes['why-container']['layout']['content_width'] ?? '' ), 'Constrained content owner uses native Elementor Boxed semantics' );
dc_assert( ! isset( $nodes['why-container']['layout']['width'], $nodes['why-container']['layout']['max_width'] ), 'Global container does not carry conflicting local width controls' );
dc_assert( ! isset( $nodes['why-container']['spacing']['padding'] ), 'Global container does not carry local padding that overrides clamp gutters' );
dc_assert( 44 === $gap_value( $nodes['why-container'] ), 'Section container uses native desktop Gap between intro and grid' );
dc_assert( 32 === $gap_value( $nodes['why-container'], 'mobile' ), 'Section container uses native mobile Gap between intro and grid' );
dc_assert( array( 'why-title', 'why-lead' ) === ( $nodes['why-intro']['children'] ?? array() ), 'Intro wrapper owns title-to-lead spacing' );
dc_assert( 16 === $gap_value( $nodes['why-intro'] ), 'Intro wrapper uses native desktop Gap' );
dc_assert( 14 === $gap_value( $nodes['why-intro'], 'mobile' ), 'Intro wrapper uses native mobile Gap' );

foreach ( range( 1, 4 ) as $number ) {
    $card_id = 'why-card-' . $number;
    dc_assert( array( $card_id . '-icon', $card_id . '-body' ) === ( $nodes[ $card_id ]['children'] ?? array() ), 'Card groups text body so native Gap can express unequal vertical rhythm' );
    dc_assert( 24 === $gap_value( $nodes[ $card_id ] ), 'Card uses native desktop Gap between icon and body' );
    dc_assert( 16 === $gap_value( $nodes[ $card_id ], 'mobile' ), 'Card uses native mobile Gap between icon and body' );
    dc_assert( array( $card_id . '-heading', $card_id . '-text' ) === ( $nodes[ $card_id . '-body']['children'] ?? array() ), 'Card body owns heading-to-copy spacing' );
    dc_assert( 10 === $gap_value( $nodes[ $card_id . '-body'] ), 'Card body uses native desktop Gap' );
    dc_assert( 8 === $gap_value( $nodes[ $card_id . '-body'], 'mobile' ), 'Card body uses native mobile Gap' );
}

$margin_nodes = array();
foreach ( $nodes as $id => $node ) { if ( $has_nonzero_margin( $node ) ) { $margin_nodes[] = $id; } }
dc_assert( array() === $margin_nodes, 'Sibling spacing avoids non-zero margin when container Gap can own it' );

$validator = new Design_Core_Elementor_Design_IR_Validator();
$invalid_ir = $ir;
foreach ( $invalid_ir['nodes'] as &$invalid_node ) {
    if ( 'why-lead' === $invalid_node['id'] ) { $invalid_node['spacing']['margin'] = array( 'top' => 16, 'right' => 0, 'bottom' => 0, 'left' => 0, 'unit' => 'px' ); }
}
unset( $invalid_node );
$rejected = false;
try { $validator->validate( $invalid_ir ); } catch ( InvalidArgumentException $exception ) { $rejected = false !== strpos( $exception->getMessage(), 'Gap-first' ); }
dc_assert( $rejected, 'Core validator rejects unexplained child margin when parent Container Gap can own spacing' );

$exception_ir = $invalid_ir;
foreach ( $exception_ir['nodes'] as &$exception_node ) {
    if ( 'why-lead' === $exception_node['id'] ) {
        $exception_node['spacing_ownership'] = array(
            'margin_exception' => true,
            'reason' => 'Independent optical offset cannot be represented by parent Gap.',
        );
    }
}
unset( $exception_node );
$exception_valid = true;
try { $validator->validate( $exception_ir ); } catch ( InvalidArgumentException $exception ) { $exception_valid = false; }
dc_assert( $exception_valid, 'Core validator permits a rare margin only with an explicit non-empty justification' );

dc_finish( 'Gap-first spacing ownership' );

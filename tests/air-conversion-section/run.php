<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
dc_require( array( 'core/design-ir.php', 'core/design-ir-validator.php' ) );

$script = DESIGN_CORE_ELEMENTOR_PATH . 'scripts/provision-air-conversion.php';
if ( ! file_exists( $script ) ) {
    dc_assert( false, 'Air conversion CTA blueprint exists' );
    dc_finish( 'Air conversion section' );
}
define( 'DESIGN_CORE_AIR_CONVERSION_BLUEPRINT_ONLY', true );
require $script;

$ir = design_core_air_conversion_ir();
$nodes = array();
foreach ( $ir['nodes'] ?? array() as $node ) { $nodes[ $node['id'] ] = $node; }

$valid = false;
try { $valid = ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir ); } catch ( Throwable $exception ) { $valid = false; }
dc_assert( true === $valid, 'Conversion CTA canonical IR passes fail-closed validation' );
dc_assert( 7 === count( $nodes ), 'Conversion CTA uses a concise editable native node tree' );
dc_assert( 'fullwidth' === ( $nodes['conversion-root']['semantic']['layout_mode'] ?? '' ) && 'surface' === ( $nodes['conversion-root']['semantic']['container_role'] ?? '' ), 'Conversion background owner is semantic Full Width' );
dc_assert( 'full' === ( $nodes['conversion-root']['layout']['content_width'] ?? '' ) && ! isset( $nodes['conversion-root']['layout']['max_width'] ), 'Full Width conversion root has no local max-width' );
dc_assert( 'boxed' === ( $nodes['conversion-container']['semantic']['layout_mode'] ?? '' ) && 'content' === ( $nodes['conversion-container']['semantic']['container_role'] ?? '' ), 'Conversion content owner is semantic Boxed' );
dc_assert( 'boxed' === ( $nodes['conversion-container']['layout']['content_width'] ?? '' ) && ! isset( $nodes['conversion-container']['layout']['width'], $nodes['conversion-container']['layout']['max_width'] ), 'Boxed conversion owner inherits Elementor Global Content Width' );
dc_assert( array( 'conversion-copy', 'conversion-form-wrap' ) === ( $nodes['conversion-container']['children'] ?? array() ), 'Conversion content separates copy and lead-capture form' );
dc_assert( 'column' === ( $nodes['conversion-container']['responsive']['tablet']['layout']['direction'] ?? '' ) && 14 === ( $nodes['conversion-container']['responsive']['mobile']['layout']['gap']['value'] ?? null ), 'Conversion layout stacks responsively through native Container controls' );
dc_assert( 'Not sure this is the right fit?' === ( $nodes['conversion-title']['content']['text'] ?? '' ), 'Conversion heading matches the static design authority' );
dc_assert( false !== strpos( $nodes['conversion-lead']['content']['text'] ?? '', 'direct uplift' ), 'Conversion supporting copy remains editable' );
$form = $nodes['conversion-form'];
dc_assert( 'form' === ( $form['source']['tag'] ?? '' ) && 1 === count( $form['content']['fields'] ?? array() ), 'Conversion uses one semantic lead-capture form field' );
dc_assert( '480kg from Ningbo, needed Friday' === ( $form['content']['fields'][0]['placeholder'] ?? '' ) && 'Send it' === ( $form['content']['button_text'] ?? '' ), 'Form field and submit copy match the source' );
dc_assert( '75' === ( $form['content']['fields'][0]['width'] ?? '' ) && '100' === ( $form['content']['fields'][0]['width_mobile'] ?? '' ), 'Field width changes from inline desktop to stacked mobile' );
dc_assert( '25' === ( $form['layout']['button_width'] ?? '' ) && '100' === ( $form['responsive']['mobile']['layout']['button_width'] ?? '' ), 'Submit width changes from inline desktop to stacked mobile' );
$has_margin = false;
foreach ( $nodes as $node ) {
    foreach ( array_merge( array( $node['spacing'] ?? array() ), array_values( $node['responsive'] ?? array() ) ) as $state ) {
        if ( ! empty( $state['margin'] ) || ! empty( $state['spacing']['margin'] ) ) { $has_margin = true; }
    }
}
dc_assert( ! $has_margin, 'Conversion CTA uses parent Gap and padding instead of child margins' );

dc_finish( 'Air conversion section' );

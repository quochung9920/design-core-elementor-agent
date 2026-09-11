<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
$script = DESIGN_CORE_ELEMENTOR_PATH . 'scripts/provision-air-hover-contract.php';
if ( ! file_exists( $script ) ) { dc_assert( false, 'Air hover contract exists' ); dc_finish( 'Air hover contract' ); }
define( 'DESIGN_CORE_AIR_HOVER_BLUEPRINT_ONLY', true );
require $script;
$rules = design_core_air_hover_contract();
foreach ( array( 'dc-air-hero', 'dc-what-section', 'dc-why-section', 'dc-compare-section', 'dc-conversion-section', 'dc-faq-section', 'dc-enquiry-section' ) as $owner ) { dc_assert( ! empty( $rules[ $owner ] ), "Hover contract owns {$owner}" ); }
dc_assert( false !== strpos( $rules['dc-why-section'], 'translateY(-2px)' ) && false !== strpos( $rules['dc-why-section'], 'box-shadow' ), 'Why card hover includes lift and shadow' );
dc_assert( false !== strpos( $rules['dc-compare-section'], 'dc-compare-row' ) && false !== strpos( $rules['dc-compare-section'], ':hover' ), 'Compare rows have explicit hover feedback' );
dc_assert( false !== strpos( $rules['dc-faq-section'], 'elementor-accordion-item:hover' ) && false !== strpos( $rules['dc-faq-section'], 'rotate(90deg)' ), 'FAQ hover targets actual accordion item and icon' );
dc_assert( false !== strpos( implode( "\n", $rules ), 'prefers-reduced-motion:reduce' ), 'Hover contract respects reduced-motion preference' );
dc_finish( 'Air hover contract' );

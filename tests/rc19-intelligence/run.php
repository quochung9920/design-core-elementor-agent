<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array(
    'core/design-ir.php','core/design-ir-validator.php','core/change-ledger.php','core/responsive-normalizer.php','core/layout-intelligence.php','core/build-plan-preview.php','core/visual-feedback-engine.php','core/section-recipe-library.php','core/page-shell.php','core/figma-design-ir-adapter.php','core/page-snapshot.php','core/section-explainability.php','core/agent-gateway.php','core/elementor-persistence-service.php',
) );

$normalized = ( new Design_Core_Elementor_Responsive_Normalizer() )->normalize( array( 'nodes' => array( array( 'layout' => array( 'direction' => 'row', 'gap' => array( 'value' => 30, 'unit' => 'px' ) ), 'style' => array(), 'spacing' => array(), 'responsive' => array( 'mobile' => array( 'layout' => array( 'direction' => 'column', 'gap' => array( 'value' => 18, 'unit' => 'px' ) ) ) ) ) ) ) );
dc_assert( 'column' === ( $normalized['nodes'][0]['responsive']['mobile']['direction'] ?? '' ), 'partial grouped responsive layout is flattened' );
dc_assert( 18 === (int) ( $normalized['nodes'][0]['responsive']['mobile']['gap']['value'] ?? 0 ), 'grouped responsive gap survives normalization' );

$layout = new Design_Core_Elementor_Layout_Intelligence();
$nodes = array(
    'root' => array( 'id' => 'root', 'children' => array( 'left', 'right' ), 'layout' => array( 'display' => 'flex', 'direction' => 'row' ), 'style' => array(), 'semantic' => array(), 'component' => array() ),
    'left' => array( 'id' => 'left', 'children' => array(), 'layout' => array( 'width' => array( 'value' => 40, 'unit' => '%' ) ), 'style' => array(), 'semantic' => array(), 'component' => array() ),
    'right' => array( 'id' => 'right', 'children' => array(), 'layout' => array( 'width' => array( 'value' => 60, 'unit' => '%' ) ), 'style' => array(), 'semantic' => array(), 'component' => array() ),
);
$split = $layout->detect_node( $nodes['root'], $nodes );
dc_assert( 'split' === ( $split['pattern'] ?? '' ), 'Layout Intelligence detects split layouts' );
dc_assert( abs( 0.4 - (float) ( $split['ratios'][0] ?? 0 ) ) < 0.001 && abs( 0.6 - (float) ( $split['ratios'][1] ?? 0 ) ) < 0.001, 'Layout Intelligence preserves 40/60 ratio' );

$feedback = ( new Design_Core_Elementor_Visual_Feedback_Engine() )->evaluate( array( 1440 => array( 'status' => 'success', 'similarity' => 0.90, 'difference_ratio' => 0.10 ) ), array( 1440 => array( '/hero' => array( 'rect' => array( 'width' => array( 'reference' => 1200, 'candidate' => 1140, 'delta' => -60 ) ), 'styles' => array( 'fontSize' => array( 'reference' => '56px', 'candidate' => '48px' ) ) ) ) ), 0.95 );
dc_assert( 'needs-correction' === ( $feedback['status'] ?? '' ), 'Visual Feedback v2 identifies a failing comparison' );
dc_assert( ! empty( $feedback['correction_plan'] ), 'Visual Feedback v2 produces correction directives' );
$categories = array_values( array_unique( array_column( $feedback['issues'], 'category' ) ) );
dc_assert( in_array( 'geometry', $categories, true ) && in_array( 'typography', $categories, true ), 'Visual Feedback classifies geometry and typography issues' );

$figma = array( 'id' => '1:1', 'type' => 'FRAME', 'name' => 'Hero', 'layoutMode' => 'HORIZONTAL', 'itemSpacing' => 24, 'absoluteBoundingBox' => array( 'width' => 1200, 'height' => 600 ), 'children' => array(
    array( 'id' => '1:2', 'type' => 'TEXT', 'name' => 'Heading', 'characters' => 'International engineering', 'style' => array( 'fontSize' => 48, 'fontWeight' => 700 ), 'absoluteBoundingBox' => array( 'width' => 500, 'height' => 70 ) ),
    array( 'id' => '1:3', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'Platform neutral body copy.', 'style' => array( 'fontSize' => 18 ), 'absoluteBoundingBox' => array( 'width' => 500, 'height' => 50 ) ),
    array( 'id' => '1:4', 'type' => 'RECTANGLE', 'name' => 'Hero Image', 'fills' => array( array( 'type' => 'IMAGE', 'imageRef' => 'abc', 'scaleMode' => 'FILL' ) ), 'absoluteBoundingBox' => array( 'width' => 600, 'height' => 500 ) ),
) );
$figma_ir = ( new Design_Core_Elementor_Figma_Design_IR_Adapter() )->convert( $figma );
dc_assert( ! is_wp_error( $figma_ir ) && 4 === count( $figma_ir['nodes'] ?? array() ), 'Figma adapter produces validated Design IR' );
if ( ! is_wp_error( $figma_ir ) ) {
    $schemas = array(); foreach ( $figma_ir['nodes'] as $node ) { $schemas[ $node['semantic']['role'] ?? '' ] = (array) ( $node['component']['content_schema'] ?? array() ); }
    dc_assert( in_array( 'heading', $schemas['heading'] ?? array(), true ), 'Figma heading maps to heading content schema' );
    dc_assert( in_array( 'rich_text', $schemas['text'] ?? array(), true ), 'Figma body text maps to rich_text content schema' );
}

$recipes = Design_Core_Elementor_Section_Recipe_Library::definitions();
dc_assert( isset( $recipes['hero-split'], $recipes['benefits-grid'], $recipes['comparison'], $recipes['calculator'], $recipes['process-timeline'], $recipes['enquiry-cta'] ), 'common Section Recipe library covers primary reusable families' );
$shells = Design_Core_Elementor_Page_Shell::definitions();
dc_assert( isset( $shells['service-landing'], $shells['location-landing'], $shells['resource-article'], $shells['import-guide'], $shells['contact-about'] ), 'Page Shell library covers the canonical page families' );
dc_assert( 3 === Design_Core_Elementor_Build_Plan_Preview::estimate_elements_from_ir( array( 'nodes' => array( array(), array(), array() ) ) ), 'BuildPlan Preview has deterministic element estimation' );
dc_assert( Design_Core_Elementor_Change_Ledger::hash_value( array( 'a' => 1 ) ) === Design_Core_Elementor_Change_Ledger::hash_value( array( 'a' => 1 ) ), 'Change Ledger hashing is deterministic' );
dc_assert( class_exists( 'Design_Core_Elementor_Persistence_Service' ), 'governed Elementor persistence boundary exists' );
$tools = Design_Core_Elementor_Agent_Gateway::definitions();
dc_assert( isset( $tools['build-preview'], $tools['page-snapshot'], $tools['figma-to-ir'], $tools['visual-feedback'], $tools['convert-html'], $tools['history-rollback'] ), 'compact Agent Gateway exposes the rc19 capabilities' );
dc_assert( ! empty( $tools['history-rollback']['destructive'] ) && ! empty( $tools['convert-html']['destructive'] ), 'mutating Agent Gateway tools are explicitly annotated destructive' );

dc_finish( 'RC19 intelligence' );

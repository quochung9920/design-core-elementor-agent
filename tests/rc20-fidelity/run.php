<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array(
    'core/change-ledger.php',
    'core/design-ir.php','core/design-ir-validator.php',
    'core/control-schema-mapper.php','core/control-schema-registry.php',
    'core/layout-intelligence.php','core/build-plan-simulator.php',
    'core/visual-feedback-engine.php','core/visual-correction-applier.php',
    'core/figma-transport.php','core/figma-normalization-service.php','core/figma-design-ir-adapter.php',
    'core/design-benchmark-corpus.php','core/agent-gateway.php',
) );

// Responsive Layout Intelligence v3: the same node is a split on desktop and a stack on mobile.
$layout = new Design_Core_Elementor_Layout_Intelligence();
$ir = array(
    'nodes' => array(
        array( 'id'=>'root','source'=>array('dom_path'=>'/body/section[1]'),'children'=>array('left','right'),'layout'=>array('display'=>'flex','direction'=>'row'),'style'=>array(),'semantic'=>array(),'component'=>array(),'responsive'=>array('mobile'=>array('direction'=>'column')) ),
        array( 'id'=>'left','source'=>array('dom_path'=>'/body/section[1]/div[1]'),'children'=>array(),'layout'=>array('width'=>array('value'=>40,'unit'=>'%')),'style'=>array(),'semantic'=>array(),'component'=>array(),'responsive'=>array('mobile'=>array('width'=>array('value'=>100,'unit'=>'%'))) ),
        array( 'id'=>'right','source'=>array('dom_path'=>'/body/section[1]/div[2]'),'children'=>array(),'layout'=>array('width'=>array('value'=>60,'unit'=>'%')),'style'=>array(),'semantic'=>array(),'component'=>array(),'responsive'=>array('mobile'=>array('width'=>array('value'=>100,'unit'=>'%'))) ),
    ),
);
$enriched = $layout->enrich( $ir );
$root = null; foreach ( $enriched['nodes'] as $node ) { if ( 'root' === $node['id'] ) { $root = $node; break; } }
dc_assert( 3 === Design_Core_Elementor_Layout_Intelligence::VERSION, 'Layout Intelligence is v3' );
dc_assert( 'split' === ( $root['layout_intelligence']['states']['desktop']['pattern'] ?? '' ), 'desktop state detects split' );
dc_assert( 'stack' === ( $root['layout_intelligence']['states']['mobile']['pattern'] ?? '' ), 'mobile state detects stack' );
dc_assert( ! empty( $root['layout_intelligence']['responsive_pattern_change'] ), 'responsive pattern transition is recorded' );

// Visual Feedback v3 aligns structurally different DOM by content/tag and keeps Elementor ownership.
$reference_analysis = array( 'schema_version'=>4, 'viewports'=>array( 1440=>array(
    array( 'index'=>5,'domPath'=>'/body/main[1]/h1[1]','tag'=>'h1','ownText'=>'International engineering','rect'=>array('x'=>40,'y'=>50,'width'=>700,'height'=>70),'styles'=>array('fontSize'=>'56px','lineHeight'=>'58px','fontWeight'=>'700','textAlign'=>'left') ),
) ) );
$candidate_analysis = array( 'schema_version'=>4, 'viewports'=>array( 1440=>array(
    array( 'index'=>12,'domPath'=>'/body/div[1]/main[1]/div[2]/h1[1]','tag'=>'h1','ownText'=>'International engineering','elementorId'=>'abc12345','elementorType'=>'widget','widgetType'=>'heading','rect'=>array('x'=>40,'y'=>50,'width'=>700,'height'=>64),'styles'=>array('fontSize'=>'48px','lineHeight'=>'52px','fontWeight'=>'700','textAlign'=>'left') ),
) ) );
$vf = new Design_Core_Elementor_Visual_Feedback_Engine();
$diffs = $vf->compare_analysis( $reference_analysis, $candidate_analysis );
dc_assert( 'text-tag' === ( $diffs[1440]['/body/main[1]/h1[1]']['match_method'] ?? '' ), 'Visual Feedback matches changed DOM by text and tag' );
dc_assert( 'abc12345' === ( $diffs[1440]['/body/main[1]/h1[1]']['candidate_meta']['elementor_id'] ?? '' ), 'Visual Feedback preserves owning Elementor ID' );
$feedback = $vf->evaluate( array( 1440=>array('status'=>'success','similarity'=>0.91,'difference_ratio'=>0.09) ), $diffs, 0.95 );
$auto = array_values( array_filter( (array) $feedback['correction_plan'], static function( $d ){ return ! empty( $d['auto_applicable'] ); } ) );
dc_assert( ! empty( $auto ), 'Visual Feedback v3 emits an auto-applicable directive' );
dc_assert( 'abc12345' === ( $auto[0]['elementor_id'] ?? '' ), 'auto directive addresses a concrete Elementor element' );
$properties = array(); foreach ( (array) ( $auto[0]['changes'] ?? array() ) as $change ) { $properties[] = $change['property'] ?? ''; }
dc_assert( in_array( 'font_size', $properties, true ) || in_array( 'line_height', $properties, true ), 'auto directive carries exact typography target values' );

// Correction values are translated to Elementor control shapes without any runtime guessing.
$applier = new Design_Core_Elementor_Visual_Correction_Applier( new Design_Core_Elementor_Control_Schema_Registry( static function(){ return array(); } ) );
$slider = $applier->value_for_control( array('type'=>'slider'), 'font_size', '56px' );
dc_assert( 56.0 === (float) ( $slider['size'] ?? 0 ) && 'px' === ( $slider['unit'] ?? '' ), 'slider CSS target maps to Elementor dimension shape' );
$gaps = $applier->value_for_control( array('type'=>'gaps'), 'gap', '20px 30px' );
dc_assert( '20' === (string) ( $gaps['row'] ?? '' ) && '30' === (string) ( $gaps['column'] ?? '' ), 'two-axis gap maps to Elementor gaps shape' );

// Figma transport URL parsing stays separate from network/auth and normalizes Figma node IDs.
$transport = new Design_Core_Elementor_Figma_Transport();
$parsed = $transport->parse_url( 'https://www.figma.com/design/1nleuMMcXfzSgHGwle3v78/Dimension-Website?node-id=73224-44&t=test' );
dc_assert( ! is_wp_error( $parsed ) && '1nleuMMcXfzSgHGwle3v78' === ( $parsed['file_key'] ?? '' ), 'Figma transport parses file key' );
dc_assert( '73224:44' === ( $parsed['node_id'] ?? '' ), 'Figma transport normalizes node-id' );

// Figma Adapter v4 consumes transport wrapper, runs pre-IR normalization, resolves imageRef and preserves sizing/component evidence.
$figma_wrapper = array(
    'source' => array('node_id'=>'1:1'),
    'image_fills' => array('img-ref'=>'https://cdn.example.test/figma-image.png'),
    'figma' => array('document'=>array(
        'id'=>'1:1','type'=>'FRAME','name'=>'Hero','layoutMode'=>'HORIZONTAL','layoutSizingHorizontal'=>'FIXED','absoluteBoundingBox'=>array('width'=>1200,'height'=>600),
        'children'=>array(
            array('id'=>'1:2','type'=>'INSTANCE','name'=>'Hero card','componentId'=>'component:1','componentProperties'=>array('Variant'=>array('type'=>'VARIANT','value'=>'Large')),'layoutSizingHorizontal'=>'FIXED','absoluteBoundingBox'=>array('width'=>500,'height'=>300),'fills'=>array(array('type'=>'IMAGE','imageRef'=>'img-ref','scaleMode'=>'FILL'))),
        ),
    )),
);
$figma_ir = ( new Design_Core_Elementor_Figma_Design_IR_Adapter() )->convert( $figma_wrapper );
dc_assert( ! is_wp_error( $figma_ir ) && 4 === Design_Core_Elementor_Figma_Design_IR_Adapter::VERSION, 'Figma Adapter v4 produces Design IR' );
$image_node = null; $root_node = null;
if ( ! is_wp_error( $figma_ir ) ) {
    foreach ( $figma_ir['nodes'] as $node ) {
        if ( ! empty( $node['assets']['images'] ) ) { $image_node = $node; }
        if ( $node['id'] === ( $figma_ir['root_ids'][0] ?? '' ) ) { $root_node = $node; }
    }
    dc_assert( 4 === (int) ( $figma_ir['diagnostics']['figma_adapter_version'] ?? 0 ), 'Figma v4 IR diagnostics report the adapter version' );
    dc_assert( isset( $figma_ir['diagnostics']['normalization'] ) && 2 === (int) ( $figma_ir['diagnostics']['normalization']['nodes'] ?? 0 ), 'Figma v4 normalization stage walks the source tree and reports evidence' );
}
dc_assert( true === ( $image_node['layout_governance']['fixed_width_exception'] ?? false ), 'Figma authored FIXED width governance is preserved through v4 normalization' );
dc_assert( 'https://cdn.example.test/figma-image.png' === ( $image_node['assets']['images'][0]['resolved_url'] ?? '' ), 'Figma imageRef resolves through transport evidence' );
dc_assert( 'component:1' === ( $image_node['figma']['component_id'] ?? '' ), 'Figma component instance identity is preserved' );

// Strategy-aware simulator does not equate a whole IR subtree with Elementor element count.
$sim_ir = array( 'nodes'=>array(
    array('id'=>'root','source'=>array('tag'=>'section'),'children'=>array('a','b')),
    array('id'=>'a','source'=>array('tag'=>'h2'),'children'=>array()),
    array('id'=>'b','source'=>array('tag'=>'p'),'children'=>array()),
) );
$sim_plan = array( 'items'=>array( array('node_id'=>'root','strategy'=>'custom-widget') ) );
$sim = ( new Design_Core_Elementor_Build_Plan_Simulator() )->simulate( $sim_ir, $sim_plan );
dc_assert( 1 === (int) ( $sim['estimated_element_count'] ?? 0 ), 'custom-widget strategy collapses a three-node IR subtree to one predicted Elementor element' );

// The quality corpus and agent surface make fidelity measurable/operable.
$defs = Design_Core_Elementor_Design_Benchmark_Corpus::definitions();
dc_assert( isset( $defs['hero-split'], $defs['card-grid'], $defs['overlap-media'] ) && count( $defs ) >= 5, 'rc20 benchmark corpus contains core fidelity patterns' );
$tools = Design_Core_Elementor_Agent_Gateway::definitions();
dc_assert( isset( $tools['visual-correct'], $tools['quality-benchmarks'], $tools['figma-to-ir'] ), 'agent gateway exposes rc20 fidelity tools' );
dc_assert( ! empty( $tools['visual-correct']['destructive'] ), 'automatic visual correction is explicitly destructive' );
dc_assert( 2 === Design_Core_Elementor_Agent_Gateway::VERSION, 'Agent Gateway is v2' );

dc_finish( 'RC20 fidelity contracts' );

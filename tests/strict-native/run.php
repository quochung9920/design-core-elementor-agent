<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array(
    'core/control-schema-registry.php',
    'core/breakpoint-registry.php',
    'core/responsive-style-parser.php',
    'core/widget-control-adapters.php',
    'core/elementor-mapping-engine.php',
    'core/binding-governor.php',
    'core/strict-structure-gate.php',
    'core/agent/validator.php',
    'core/runtime-control-binder.php',
    'core/native-widget-binder.php',
    'core/semantic-native-mapping-v2.php',
) );

$gov = 'Design_Core_Elementor_Binding_Governor';

// A. Data-contract rejection: generic binding must refuse data widgets.
dc_assert( '' !== $gov::generic_binding_rejection( 'mega-menu' ), 'mega-menu is rejected for generic binding' );
dc_assert( '' !== $gov::generic_binding_rejection( 'form' ), 'form is rejected for generic binding' );
dc_assert( '' !== $gov::generic_binding_rejection( 'tabs' ), 'tabs are rejected for generic binding' );
dc_assert( '' === $gov::generic_binding_rejection( 'heading' ), 'heading stays generically bindable' );
dc_assert( '' === $gov::generic_binding_rejection( 'accordion' ), 'accordion keeps its dedicated binder path' );

class DC_Test_Stub_Registry {
    public function available( $type, $name = '' ) { return true; }
    public function schema( $type, $name = '' ) { return array( 'controls' => array() ); }
    public function has( $type, $name, $control ) { return false; }
    public function is_responsive( $type, $name, $control ) { return false; }
    public function first_supported( $type, $name, array $candidates, $expected = '' ) { return ''; }
}
$binder = new Design_Core_Elementor_Native_Widget_Binder( new DC_Test_Stub_Registry() );
$ir = array( 'nodes' => array( array( 'id' => 'n1', 'source' => array( 'tag' => 'nav' ), 'children' => array(), 'content' => array(), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array(), 'component' => array() ) ), 'root_ids' => array( 'n1' ) );
dc_assert( null === $binder->bind( $ir, 'mega-menu' ), 'generic binder refuses mega-menu without data' );
dc_assert( null === $binder->bind( $ir, 'form' ), 'generic binder refuses form without data' );

// D. Condition evaluation mirrors the strict validator.
$effective = array( 'content_width' => 'boxed', 'container_type' => 'flex' );
dc_assert( false === $gov::control_active( array( 'condition' => array( 'content_width' => 'full' ) ), $effective ), 'width without full content-width is inactive' );
dc_assert( true === $gov::control_active( array( 'condition' => array( 'content_width' => 'boxed' ) ), $effective ), 'matching condition is active' );
dc_assert( false === $gov::control_active( array( 'condition' => array( 'container_type!' => 'flex' ) ), $effective ), 'negated mismatch is inactive' );
dc_assert( true === $gov::control_active( array( 'conditions' => array( 'relation' => 'and', 'terms' => array( array( 'name' => 'container_type', 'operator' => '==', 'value' => 'flex' ) ) ) ), $effective ), 'terms groups evaluate' );
dc_assert( null === $gov::control_active( array( 'conditions' => array( 'relation' => 'maybe' ) ), $effective ), 'unevaluable conditions fail closed to null' );

// D. Unit validation.
dc_assert( true === $gov::unit_allowed( array( 'size' => 1, 'unit' => 'px' ), array( 'type' => 'slider', 'size_units' => array( 'px', 'em' ) ) ), 'listed unit passes' );
dc_assert( false === $gov::unit_allowed( array( 'size' => 1, 'unit' => 'vw' ), array( 'type' => 'slider', 'size_units' => array( 'px', 'em' ) ) ), 'unlisted unit fails' );
dc_assert( true === $gov::unit_allowed( 'plain', array( 'type' => 'slider' ) ), 'non-dimension values skip unit checks' );

// Rich text keeps text, drops layout markup.
list( $kept, $was ) = $gov::sanitize_richtext( '<p>Hello <strong>world</strong></p>' );
dc_assert( false === $was && false !== strpos( $kept, '<strong>' ), 'clean rich text is untouched' );
list( $kept, $was ) = $gov::sanitize_richtext( '<div class="x"><p>Hello</p></div>' );
dc_assert( true === $was && 'Hello' === $kept, 'layout markup is stripped to text' );

// Trivial CSS never reaches output.
dc_assert( true === $gov::is_trivial_css( 'box-sizing' ), 'box-sizing is trivial' );
dc_assert( true === $gov::is_trivial_css( 'content' ), 'content is trivial' );
dc_assert( false === $gov::is_trivial_css( 'padding' ), 'padding is not trivial' );

// Governed settings: unknown/inactive/bad-unit settings are dropped, never persisted.
$stub = new DC_Test_Stub_Registry();
$fake_controls = new class() {
    public function schema( $t, $w = '' ) {
        return array( 'controls' => array(
            'title' => array( 'name' => 'title', 'type' => 'text' ),
            'width' => array( 'name' => 'width', 'type' => 'slider', 'size_units' => array( 'px' ), 'condition' => array( 'content_width' => 'full' ) ),
            'line_height' => array( 'name' => 'line_height', 'type' => 'slider', 'size_units' => array( 'px', 'em' ) ),
            'typography_font_size' => array( 'name' => 'typography_font_size', 'type' => 'slider', 'size_units' => array( 'px', 'em' ) ),
            'typography_line_height' => array( 'name' => 'typography_line_height', 'type' => 'slider', 'size_units' => array( 'px', 'em' ) ),
        ) );
    }
};
$notes = array();
$kept = $gov::govern_settings( 'widget', 'heading', array( 'title' => 'Hi', 'nope' => 1, 'width' => array( 'size' => 5, 'unit' => 'px' ), 'line_height' => array( 'size' => 1.5, 'unit' => 'rem' ) ), $fake_controls, $notes );
dc_assert( 'Hi' === ( $kept['title'] ?? null ), 'valid setting survives governance' );
dc_assert( ! isset( $kept['nope'] ), 'unknown control is dropped' );
dc_assert( ! isset( $kept['width'] ), 'inactive conditional control is dropped' );
dc_assert( ! isset( $kept['line_height'] ), 'disallowed unit is dropped' );
dc_assert( in_array( 'width:condition-inactive', array_map( static function ( $n ) { return explode( ':', $n )[0] . ':condition-check'; }, array() ), true ) || in_array( 'width', array_map( static function ( $n ) { return explode( ':', $n )[0]; }, $notes['skipped_conditional'] ), true ), 'dropped settings are reported' );

// Strict gate without a runtime reports unavailable, never a fake verdict.
$gate = Design_Core_Elementor_Strict_Structure_Gate::audit_elements( array() );
dc_assert( 'unavailable' === ( $gate['status'] ?? '' ), 'gate is unavailable without Elementor runtime' );
dc_assert( in_array( 'restricted', Design_Core_Elementor_Strict_Structure_Gate::MATERIAL_CODES, true ), 'restricted is material' );
dc_assert( in_array( 'layout_in_richtext', Design_Core_Elementor_Strict_Structure_Gate::MATERIAL_CODES, true ), 'layout_in_richtext is material' );
$material = Design_Core_Elementor_Strict_Structure_Gate::material( array( array( 'code' => 'enum' ), array( 'code' => 'unknown_control' ) ) );
dc_assert( 1 === count( $material ) && 'enum' === $material[0]['code'], 'material filter keeps only blocking codes' );

// Tree metrics drive the acceptance counters.
$metrics = Design_Core_Elementor_Strict_Structure_Gate::tree_metrics( array(
    array( 'id' => 'a', 'elType' => 'container', 'settings' => array( 'padding_tablet' => array( 'unit' => 'px', 'top' => 1, 'right' => 1, 'bottom' => 1, 'left' => 1 ) ), 'elements' => array(
        array( 'id' => 'b', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'x', 'custom_css' => 'selector{a:b;}' ), 'elements' => array() ),
    ) ),
) );
dc_assert( 2 === ( $metrics['elements'] ?? 0 ), 'metrics count nested elements' );
dc_assert( 1 === ( $metrics['custom_css_elements'] ?? 0 ), 'metrics count css elements' );
dc_assert( 1 === ( $metrics['responsive_override_count'] ?? 0 ), 'metrics count responsive overrides' );
dc_assert( 1 === ( $metrics['widgets']['heading'] ?? 0 ), 'metrics count widgets' );

// B/F/G pattern helpers via reflection (pure node readers, no Elementor needed).
$mapper = new Design_Core_Elementor_Semantic_Native_Mapping_V2();
$ref = new ReflectionClass( $mapper );
$nodes_prop = $ref->getProperty( 'nodes' );
$nodes_prop->setAccessible( true );
$call = static function ( $method, $node, $nodes ) use ( $mapper, $ref, $nodes_prop ) {
    $nodes_prop->setValue( $mapper, $nodes );
    $m = $ref->getMethod( $method );
    $m->setAccessible( true );
    return $m->invoke( $mapper, $node );
};
$link = static function ( $id, $url, $text ) {
    return array( 'id' => $id, 'source' => array( 'tag' => 'a' ), 'children' => array(), 'content' => array( 'text' => $text, 'link' => array( 'url' => $url ) ), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array(), 'component' => array() );
};
$jump = array( 'id' => 'nav', 'source' => array( 'tag' => 'nav' ), 'children' => array( 'l1', 'l2' ), 'content' => array(), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array( 'role' => 'navigation' ), 'component' => array() );
$all = array( 'nav' => $jump, 'l1' => $link( 'l1', '#what', 'What' ), 'l2' => $link( 'l2', '#why', 'Why' ) );
dc_assert( true === $call( 'is_jump_nav', $jump, $all ), 'anchor-majority nav detects as jump nav' );
$site = array( 'id' => 'nav', 'source' => array( 'tag' => 'nav' ), 'children' => array( 'l1', 'l2' ), 'content' => array(), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array( 'role' => 'navigation' ), 'component' => array() );
$all2 = array( 'nav' => $site, 'l1' => $link( 'l1', '/about', 'About' ), 'l2' => $link( 'l2', '/contact', 'Contact' ) );
dc_assert( false === $call( 'is_jump_nav', $site, $all2 ), 'site links are not jump nav' );
$table = array( 'id' => 't', 'source' => array( 'tag' => 'table' ), 'children' => array(), 'content' => array(), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array(), 'component' => array() );
dc_assert( true === $call( 'is_data_table', $table, array( 't' => $table ) ), 'table tag detects as data table' );
$details = array(
    'id' => 'd', 'source' => array( 'tag' => 'details' ), 'children' => array( 's', 'p' ),
    'content' => array(), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array(), 'component' => array(),
);
$sum = array( 'id' => 's', 'source' => array( 'tag' => 'summary' ), 'children' => array(), 'content' => array( 'text' => 'How fast?' ), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array(), 'component' => array() );
$par = array( 'id' => 'p', 'source' => array( 'tag' => 'p' ), 'children' => array(), 'content' => array( 'text' => 'Very fast.' ), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array(), 'component' => array() );
$pair = $call( 'qa', $details, array( 'd' => $details, 's' => $sum, 'p' => $par ) );
dc_assert( 'How fast?' === ( $pair['title'] ?? null ) && false !== strpos( ( $pair['content'] ?? '' ), 'Very fast' ), 'details/summary extracts accordion pairs' );

// A group of disclosures is an accordion candidate even without faq role.
$group = array( 'id' => 'g', 'source' => array( 'tag' => 'div' ), 'children' => array( 'd1', 'd2' ), 'content' => array(), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array(), 'component' => array() );
$d1 = array( 'id' => 'd1', 'source' => array( 'tag' => 'details' ), 'children' => array(), 'content' => array(), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array(), 'component' => array() );
$d2 = $d1; $d2['id'] = 'd2';
dc_assert( true === ( static function () use ( $mapper, $ref, $group, $d1, $d2 ) {
    $nodes_prop = $ref->getProperty( 'nodes' );
    $nodes_prop->setAccessible( true );
    $nodes_prop->setValue( $mapper, array( 'g' => $group, 'd1' => $d1, 'd2' => $d2 ) );
    $m = $ref->getMethod( 'has_disclosures' );
    $m->setAccessible( true );
    return $m->invoke( $mapper, $group, 2 );
} )(), 'disclosure groups are detected without role hints' );

// Bare "#" placeholders are not section anchors.
$bare = array( 'id' => 'n', 'source' => array( 'tag' => 'nav' ), 'children' => array( 'a1', 'a2' ), 'content' => array(), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array(), 'component' => array() );
$mklink = static function ( $id, $url, $text ) {
    return array( 'id' => $id, 'source' => array( 'tag' => 'a' ), 'children' => array(), 'content' => array( 'text' => $text, 'link' => array( 'url' => $url ) ), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array(), 'component' => array() );
};
dc_assert( false === $call( 'anchor_majority', $bare, array( 'n' => $bare, 'a1' => $mklink( 'a1', '#', 'One' ), 'a2' => $mklink( 'a2', '#', 'Two' ) ) ), 'bare placeholders do not count as anchors' );
dc_assert( true === $call( 'anchor_majority', $bare, array( 'n' => $bare, 'a1' => $mklink( 'a1', '#sec1', 'One' ), 'a2' => $mklink( 'a2', '#sec2', 'Two' ) ) ), 'real section anchors count' );

// Repeated numbered items form a steps candidate.
$step = static function ( $id, $num ) {
    return array( 'id' => $id, 'source' => array( 'tag' => 'div' ), 'children' => array( $id . 'n', $id . 'h', $id . 'p' ), 'content' => array(), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array(), 'component' => array() );
};
$step_nodes = array( 'steps' => array( 'id' => 'steps', 'source' => array( 'tag' => 'div' ), 'children' => array( 's1', 's2', 's3' ), 'content' => array(), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array(), 'component' => array() ) );
foreach ( array( 's1', 's2', 's3' ) as $i => $sid ) {
    $n = $i + 1;
    $step_nodes[ $sid ] = $step( $sid, $n );
    $step_nodes[ $sid . 'n' ] = array( 'id' => $sid . 'n', 'source' => array( 'tag' => 'span' ), 'children' => array(), 'content' => array( 'text' => "0$n" ), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array(), 'component' => array() );
    $step_nodes[ $sid . 'h' ] = array( 'id' => $sid . 'h', 'source' => array( 'tag' => 'h3' ), 'children' => array(), 'content' => array( 'text' => "Step $n" ), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array(), 'component' => array() );
    $step_nodes[ $sid . 'p' ] = array( 'id' => $sid . 'p', 'source' => array( 'tag' => 'p' ), 'children' => array(), 'content' => array( 'text' => 'Do it' ), 'layout' => array(), 'style' => array(), 'spacing' => array(), 'responsive' => array(), 'semantic' => array(), 'component' => array() );
}
dc_assert( true === $call( 'is_process_steps', $step_nodes['steps'], $step_nodes ), 'repeated numbered items detect as steps' );

// Unitless line-height resolves against the element's own px font size.
$notes = array();
$kept = $gov::govern_settings( 'widget', 'heading', array( 'typography_font_size' => array( 'size' => 56, 'unit' => 'px' ), 'typography_line_height' => '1.5' ), $fake_controls, $notes );
dc_assert( array( 'size' => 84.0, 'unit' => 'px' ) == ( $kept['typography_line_height'] ?? null ), 'unitless line-height normalizes via font size' );

// Validator documented domains accept known values, reject garbage.
$validator_ref = new ReflectionClass( 'Design_Core_Agent_Validator' );
$domain_method = null;
if ( $validator_ref->hasMethod( 'documented_options_domain' ) ) {
    $domain_method = $validator_ref->getMethod( 'documented_options_domain' );
    $domain_method->setAccessible( true );
}
if ( $domain_method ) {
    dc_assert( in_array( 'absolute', $domain_method->invoke( null, 'position' ), true ), 'position domain is documented' );
    dc_assert( null === $domain_method->invoke( null, 'no_such_control_xyz' ), 'unknown controls have no domain' );
}

// Centered fixed-width wrappers become boxed content, not custom CSS.
$boxed_node = array(
    'layout' => array( 'max_width' => array( 'value' => 1184, 'unit' => 'px' ) ),
    'style' => array( 'css_fallback' => array( 'margin-inline' => 'auto', 'box-sizing' => 'border-box' ) ),
    'responsive' => array(),
    'semantic' => array(),
);
$boxed_mapper = new Design_Core_Elementor_Widget_Control_Mapper();
$boxed_ref = new ReflectionClass( $boxed_mapper );
$boxed_method = $boxed_ref->getMethod( 'normalize_centered_boxed_container' );
$boxed_method->setAccessible( true );
$boxed_out = $boxed_method->invoke( $boxed_mapper, $boxed_node );
dc_assert( 'boxed' === ( $boxed_out['layout']['content_width'] ?? null ), 'centered max-width maps to boxed content' );
dc_assert( ! isset( $boxed_out['layout']['max_width'] ), 'consumed max-width leaves no residue' );

// Unrepresentable side properties never reach output.
dc_assert( true === $gov::is_unrepresentable_css( 'border-top' ), 'side borders are unrepresentable' );
dc_assert( true === $gov::is_unrepresentable_css( 'table-layout' ), 'table internals are unrepresentable' );
dc_assert( false === $gov::is_unrepresentable_css( 'padding' ), 'padding stays representable' );

// Gate material classification.
$material = Design_Core_Elementor_Strict_Structure_Gate::material( array(
    array( 'code' => 'condition' ),
    array( 'code' => 'unknown_control' ),
) );
dc_assert( 1 === count( $material ) && 'condition' === $material[0]['code'], 'only blocking codes are material' );

dc_finish( 'Strict Native' );

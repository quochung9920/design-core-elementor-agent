<?php
require_once dirname( __DIR__ ) . '/bootstrap-standalone.php';

dc_require( array(
    'core/change-ledger.php',
    'core/design-ir.php',
    'core/design-ir-validator.php',
    'core/design-token-pipeline.php',
    'core/design-token-service.php',
    'core/css-ast-service.php',
    'core/control-schema-registry.php',
    'core/widget-control-adapters.php',
    'core/elementor-setting-governor.php',
    'core/figma-normalization-service.php',
    'core/figma-design-ir-adapter.php',
    'core/wordpress-mcp-compatibility.php',
    'core/agent-gateway.php',
) );

// -----------------------------------------------------------------------------
// Style Dictionary-inspired token contracts: hierarchy, aliases, semantic paths.
// -----------------------------------------------------------------------------
$dictionary = array(
    'color' => array(
        '$type' => 'color',
        'brand' => array( '$value' => '#2563EB' ),
        'action' => array( '$value' => '{color.brand}' ),
    ),
    'spacing' => array(
        '$type' => 'dimension',
        'md' => array( '$value' => array( 'value'=>24, 'unit'=>'px' ) ),
    ),
    'radius' => array(
        '$type' => 'dimension',
        'lg' => array( '$value' => '16px' ),
    ),
    'typography' => array(
        'heading' => array(
            '$type' => 'typography',
            '$value' => array( 'font_family'=>'Inter', 'font_weight'=>700 ),
        ),
    ),
);
$pipeline = new Design_Core_Elementor_Design_Token_Pipeline();
$compiled = $pipeline->compile( $dictionary );
dc_assert( '#2563EB' === ( $compiled['dictionary']['color.action']['value'] ?? '' ), 'Token aliases resolve deterministically.' );
dc_assert( '#2563EB' === ( $compiled['tokens']['colors']['action'] ?? '' ), 'Resolved aliases map to semantic color tokens.' );
dc_assert( 24.0 === ( $compiled['tokens']['spacing']['md'] ?? null ), 'Dimension spacing maps to numeric Design Core spacing.' );
dc_assert( 16.0 === ( $compiled['tokens']['radius']['lg'] ?? null ), 'Radius namespace wins over generic dimension type.' );
dc_assert( 'Inter' === ( $compiled['tokens']['typography']['heading']['font_family'] ?? '' ), 'Typography composite remains in typography.' );
$css_vars = $pipeline->to_css_variables( $compiled, 'dc' );
dc_assert( false !== strpos( $css_vars, '--dc-color-brand: #2563EB;' ), 'Compiled dictionary exports stable CSS custom properties.' );

$cycle_failed = false;
try {
    $pipeline->compile( array( 'color'=>array( '$type'=>'color', 'a'=>array('$value'=>'{color.b}'), 'b'=>array('$value'=>'{color.a}') ) ) );
} catch ( RuntimeException $exception ) {
    $cycle_failed = false !== strpos( strtolower( $exception->getMessage() ), 'circular' );
}
dc_assert( $cycle_failed, 'Circular token references fail closed.' );

// -----------------------------------------------------------------------------
// PHP-CSS-Parser-inspired normalized rule model, with bounded source fallback.
// -----------------------------------------------------------------------------
$css = '.card { color: #111; background-image: linear-gradient(90deg, #000 0%, #fff 100%); }'
    . '@media (max-width: 767px) { .card { width: calc(100% - 20px); } }';
$ast = ( new Design_Core_Elementor_CSS_AST_Service() )->parse( $css );
dc_assert( ! is_wp_error( $ast ), 'CSS AST service parses common CSS without error.' );
dc_assert( 2 === (int) ( $ast['rule_count'] ?? 0 ), 'CSS AST preserves top-level and media rules.' );
$media_rule = $ast['rules'][1] ?? array();
dc_assert( false !== strpos( (string) ( $media_rule['media'] ?? '' ), '767px' ), 'Media condition is preserved structurally.' );
dc_assert( 'calc(100% - 20px)' === ( $media_rule['declarations']['width']['value'] ?? '' ), 'Function-valued declarations are not split by naive delimiters.' );

// -----------------------------------------------------------------------------
// Elementor MCP-derived runtime setting lessons, but live schema stays authority.
// -----------------------------------------------------------------------------
$provider = static function ( $element_type, $widget_type ) {
    if ( 'container' !== $element_type ) { return array( 'controls'=>array() ); }
    return array( 'controls'=>array(
        'container_type'=>array( 'type'=>'select' ),
        'grid_columns_grid'=>array( 'type'=>'slider' ),
        'grid_rows_grid'=>array( 'type'=>'slider' ),
        'grid_gaps'=>array( 'type'=>'gaps' ),
    ) );
};
$registry = new Design_Core_Elementor_Control_Schema_Registry( $provider );
$governor = new Design_Core_Elementor_Elementor_Setting_Governor( $registry );
$governed = $governor->govern( 'container', '', array(), array( 'layout'=>array( 'display'=>'grid', 'columns'=>3, 'gap'=>array('value'=>24,'unit'=>'px') ) ), true );
dc_assert( 'grid' === ( $governed['settings']['container_type'] ?? '' ), 'Grid mode is emitted only through a runtime-advertised control.' );
dc_assert( 3 === (int) ( $governed['settings']['grid_columns_grid']['size'] ?? 0 ), 'Grid column count is explicitly mapped.' );
dc_assert( 1 === (int) ( $governed['settings']['grid_rows_grid']['size'] ?? 0 ), 'New authored grids receive the explicit one-row seed when runtime supports it.' );
dc_assert( 24 === (int) ( $governed['settings']['grid_gaps']['column'] ?? 0 ), 'Grid gap uses the runtime control encoder.' );

$no_grid_registry = new Design_Core_Elementor_Control_Schema_Registry( static function () { return array( 'controls'=>array() ); } );
$no_grid = ( new Design_Core_Elementor_Elementor_Setting_Governor( $no_grid_registry ) )->govern( 'container', '', array(), array( 'layout'=>array('display'=>'grid','columns'=>3) ), true );
dc_assert( ! isset( $no_grid['settings']['container_type'] ), 'Private grid settings are never guessed when live controls are unavailable.' );

// -----------------------------------------------------------------------------
// FigmaToCode-inspired compiler normalization: normalize before generating IR.
// -----------------------------------------------------------------------------
$figma = array(
    'id'=>'root', 'type'=>'FRAME', 'name'=>'Hero', 'layoutMode'=>'HORIZONTAL',
    'absoluteBoundingBox'=>array('x'=>100,'y'=>200,'width'=>1200,'height'=>600),
    'itemReverseZIndex'=>true,
    'children'=>array(
        array( 'id'=>'hidden', 'type'=>'RECTANGLE', 'name'=>'Hidden', 'visible'=>false ),
        array(
            'id'=>'badge', 'type'=>'RECTANGLE', 'name'=>'Badge', 'layoutPositioning'=>'ABSOLUTE',
            'absoluteBoundingBox'=>array('x'=>140,'y'=>230,'width'=>120,'height'=>40),
            'fills'=>array( array(
                'type'=>'GRADIENT_ANGULAR',
                'gradientStops'=>array( array( 'position'=>0, 'boundVariables'=>array( 'color'=>array('id'=>'VariableID:1','name'=>'Accent') ) ) ),
            ) ),
        ),
    ),
);
$normalized = ( new Design_Core_Elementor_Figma_Normalization_Service() )->normalize( $figma );
$root = $normalized['node'];
dc_assert( 1 === count( $root['children'] ?? array() ), 'Invisible Figma children are filtered before IR conversion.' );
$badge = $root['children'][0] ?? array();
dc_assert( 40.0 === (float) ( $badge['_design_core_layout']['left']['value'] ?? -1 ), 'Absolute Figma X is normalized relative to its parent.' );
dc_assert( 30.0 === (float) ( $badge['_design_core_layout']['top']['value'] ?? -1 ), 'Absolute Figma Y is normalized relative to its parent.' );
dc_assert( 'VariableID:1' === ( $badge['_design_core_variable_refs'][0]['id'] ?? '' ), 'Nested Figma variable references, including gradient stops, are preserved.' );
dc_assert( ! empty( $badge['_design_core_warnings'] ), 'Target-dependent Figma fidelity emits warnings instead of silent approximation.' );

$ir = ( new Design_Core_Elementor_Figma_Design_IR_Adapter() )->convert( array( 'document'=>$figma ) );
dc_assert( ! is_wp_error( $ir ), 'Normalized synthetic Figma selection validates as Design IR v4.' );
dc_assert( 4 === (int) ( $ir['diagnostics']['figma_adapter_version'] ?? 0 ), 'Figma adapter v4 is reported in IR diagnostics.' );
dc_assert( 1 <= (int) ( $ir['diagnostics']['normalization']['warning_count'] ?? 0 ), 'Figma normalization diagnostics retain target-fidelity warnings.' );
$ir_badge = null;
foreach ( (array) ( $ir['nodes'] ?? array() ) as $ir_node ) { if ( 'badge' === strtolower( (string) ( $ir_node['figma']['name'] ?? '' ) ) ) { $ir_badge = $ir_node; break; } }
dc_assert( is_array( $ir_badge ) && 'VariableID:1' === ( $ir_badge['figma']['variable_refs'][0]['id'] ?? '' ), 'Figma variable references survive normalization into canonical IR evidence.' );

// -----------------------------------------------------------------------------
// Official WordPress MCP Adapter compatibility: public surface stays read/preview.
// -----------------------------------------------------------------------------
$compat = new Design_Core_Elementor_WordPress_MCP_Compatibility();
$pre_status = $compat->status();
dc_assert( false === (bool) ( $pre_status['abilities_api'] ?? true ), 'Standalone WordPress 6.8 stub correctly reports no Abilities API.' );
dc_assert( false === (bool) ( $pre_status['reviewed_adapter_runtime_eligible'] ?? true ), 'Reviewed official MCP Adapter compatibility reports its WordPress 6.9+ runtime requirement without changing Design Core 6.5 baseline.' );

$public = Design_Core_Elementor_WordPress_MCP_Compatibility::public_ability_names();
dc_assert( 5 === count( $public ), 'Official MCP Adapter compatibility exposes the intended compact public ability set.' );
$joined = implode( ' ', $public );
dc_assert( false === strpos( $joined, 'update' ) && false === strpos( $joined, 'rollback' ) && false === strpos( $joined, 'publish' ), 'No write/destructive Design Core ability is made public through generic WordPress MCP exposure.' );
dc_assert( 'private-explicit-rest-mcp-only' === ( $pre_status['write_policy'] ?? '' ), 'Write policy remains the explicit rc21 REST/MCP approval path.' );

$GLOBALS['dc_registered_abilities'] = array();
if ( ! function_exists( 'wp_register_ability' ) ) {
    function wp_register_ability( $name, $definition ) {
        $GLOBALS['dc_registered_abilities'][ $name ] = $definition;
        return true;
    }
}
( new Design_Core_Elementor_Agent_Gateway() )->register_abilities();
$compat->register_abilities();

dc_assert( false === (bool) ( $GLOBALS['dc_registered_abilities']['design-core/call-tool']['meta']['mcp']['public'] ?? true ), 'Legacy generic call-tool ability explicitly opts out of official MCP public exposure.' );
$site_ability = $GLOBALS['dc_registered_abilities']['design-core/site-status'] ?? array();
dc_assert( true === (bool) ( $site_ability['meta']['mcp']['public'] ?? false ), 'Safe explicit site-status ability opts into official MCP exposure.' );
dc_assert( true === (bool) ( $site_ability['meta']['annotations']['readOnlyHint'] ?? false ), 'Official MCP ability uses standard readOnlyHint annotation.' );
dc_assert( false === (bool) ( $site_ability['meta']['annotations']['destructiveHint'] ?? true ), 'Official MCP read ability is explicitly non-destructive.' );

dc_finish( 'Reference integration contracts' );

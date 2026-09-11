<?php
require dirname( __DIR__ ) . '/bootstrap-standalone.php';
dc_require( array(
    'core/design-ir.php', 'core/design-ir-validator.php', 'core/build-plan.php',
    'core/build-plan-validator.php', 'core/executor-result.php',
    'core/strategy-executor-interface.php', 'core/elementor-adapter-interface.php',
    'core/elementor-mapping-engine.php', 'core/strategy-executors.php',
    'core/build-plan-executor.php', 'core/conversion-service.php', 'core/html-converter.php',
    'core/runtime-evidence.php',
    'core/responsive-normalizer.php',
    'core/design-token-service.php', 'core/conversion-transaction.php',
    'core/native-widget-resolver.php',
) );

$node = array(
    'id' => 'node-1',
    'source' => array( 'tag' => 'section', 'classes' => array( 'hero' ), 'attributes' => array(), 'dom_path' => '/section[1]' ),
    'semantic' => array( 'role' => 'section', 'component_type' => '', 'confidence' => 1.0 ),
    'content' => array( 'text' => '', 'rich_text' => '', 'link' => array(), 'image' => array(), 'list' => array(), 'fields' => array() ),
    'layout' => array( 'display' => 'flex', 'gap' => array( 'value' => 24, 'unit' => 'px' ) ),
    'style' => array(), 'spacing' => array(), 'responsive' => array(), 'assets' => array(), 'interaction' => array(),
    'component' => array( 'fingerprint' => array( 'version' => 2, 'semantic' => 'section', 'structure' => 'section', 'content_schema' => array(), 'layout' => 'flex', 'interaction' => '' ), 'repeated' => false, 'reusable' => false, 'dynamic' => false, 'content_schema' => array() ),
    'children' => array(),
);
$ir = array( 'schema_version' => 4, 'type' => 'design-ir', 'nodes' => array( $node ), 'root_ids' => array( 'node-1' ), 'analysis_quality' => array(), 'tokens' => array(), 'diagnostics' => array() );
$validator = new Design_Core_Elementor_Design_IR_Validator();
dc_assert( true === $validator->validate( $ir ), 'valid Design IR v4 accepted' );
$bad = $ir; $bad['nodes'][] = $node;
try { $validator->validate( $bad ); dc_assert( false, 'duplicate IDs rejected' ); } catch ( InvalidArgumentException $e ) { dc_assert( true, 'duplicate IDs rejected' ); }
$bad = $ir; $bad['nodes'][0]['children'] = array( 'node-1' );
try { $validator->validate( $bad ); dc_assert( false, 'circular hierarchy rejected' ); } catch ( InvalidArgumentException $e ) { dc_assert( true, 'circular hierarchy rejected' ); }
$bad = $ir; $bad['nodes'][0]['layout']['gap']['unit'] = 'javascript';
try { $validator->validate( $bad ); dc_assert( false, 'invalid unit rejected' ); } catch ( InvalidArgumentException $e ) { dc_assert( true, 'invalid unit rejected' ); }
$bad = $ir; $bad['nodes'][0]['content']['fields']['settings'] = array();
try { $validator->validate( $bad ); dc_assert( false, 'nested Elementor platform leakage rejected' ); } catch ( InvalidArgumentException $e ) { dc_assert( true, 'nested Elementor platform leakage rejected' ); }
$bad = $ir; $bad['nodes'][0]['component']['fingerprint']['semantic'] = array( 'bad' );
try { $validator->validate( $bad ); dc_assert( false, 'malformed fingerprint field rejected' ); } catch ( InvalidArgumentException $e ) { dc_assert( true, 'malformed fingerprint field rejected' ); }
$bad = $ir; $bad['root_ids'][] = 'node-1';
try { $validator->validate( $bad ); dc_assert( false, 'duplicate root rejected' ); } catch ( InvalidArgumentException $e ) { dc_assert( true, 'duplicate root rejected' ); }
$bad = $ir; $orphan = $node; $orphan['id'] = 'node-orphan'; $bad['nodes'][] = $orphan;
try { $validator->validate( $bad ); dc_assert( false, 'orphan node rejected' ); } catch ( InvalidArgumentException $e ) { dc_assert( true, 'orphan node rejected' ); }

$plan = Design_Core_Elementor_Build_Plan::create( array( Design_Core_Elementor_Build_Plan_Item::create( 'node-1', 'native-compose', 'elementor-v3' ) ) );
dc_assert( 1 === $plan['build_plan_schema_version'], 'BuildPlan v1 created' );
dc_assert( true === ( new Design_Core_Elementor_Build_Plan_Validator() )->validate( $plan ), 'BuildPlan validates' );
$unknown = $plan; $unknown['items'][0]['strategy'] = 'magic';
try { ( new Design_Core_Elementor_Build_Plan_Validator() )->validate( $unknown ); dc_assert( false, 'unknown strategy rejected' ); } catch ( InvalidArgumentException $e ) { dc_assert( true, 'unknown strategy rejected' ); }
$duplicate_plan = Design_Core_Elementor_Build_Plan::create( array( $plan['items'][0], $plan['items'][0] ) );
try { ( new Design_Core_Elementor_Build_Plan_Validator() )->validate( $duplicate_plan, $ir ); dc_assert( false, 'duplicate BuildPlan node rejected' ); } catch ( InvalidArgumentException $e ) { dc_assert( true, 'duplicate BuildPlan node rejected' ); }

class DC_Test_Adapter implements Design_Core_Elementor_Adapter_Interface {
    public function target() { return 'elementor-v3'; }
    public function supports( $capability ) { return true; }
    public function normalize( $ir ) { return array( array( 'id' => 'el-1', 'elType' => 'container', 'settings' => array(), 'elements' => array() ) ); }
    public function validate( $elements ) { return ! empty( $elements ); }
    public function save( $document_id, $elements, $context = array() ) { return true; }
    public function reload( $document_id ) { return array(); }
    public function render( $document_id ) { return ''; }
}
$executors = Design_Core_Elementor_Strategy_Executors::defaults();
$execution = ( new Design_Core_Elementor_Build_Plan_Executor( $executors ) )->execute( $plan, array( 'ir' => $ir, 'adapter' => new DC_Test_Adapter() ) );
dc_assert( 'success' === $execution['status'], 'executor resolves native strategy' );
dc_assert( 1 === count( $execution['elements'] ), 'executor produces platform elements' );
dc_assert( ! empty( $execution['created_artifacts'] ), 'executor reports artifacts' );
$v4_plan = Design_Core_Elementor_Build_Plan::create( array( Design_Core_Elementor_Build_Plan_Item::create( 'node-1', 'native-compose', 'elementor-v4' ) ) );
$mismatch = ( new Design_Core_Elementor_Build_Plan_Executor( $executors ) )->execute( $v4_plan, array( 'ir' => $ir, 'adapter' => new DC_Test_Adapter() ) );
dc_assert( 'unsupported' === $mismatch['status'] && 'adapter-target-mismatch' === $mismatch['diagnostics']['reason'], 'adapter target mismatch fails closed' );
foreach ( $executors as $executor ) { dc_assert( $executor instanceof Design_Core_Elementor_Strategy_Executor_Interface, 'executor implements common interface' ); }
$result = Design_Core_Elementor_Executor_Result::create( 'unsupported', 'loop', array(), array(), array(), false, array( 'requested_strategy' => 'loop', 'actual_strategy' => '', 'reason' => 'unavailable', 'error_code' => 'loop-unavailable' ) );
dc_assert( 'unsupported' === $result['status'], 'common result status enforced' );
$malformed_result = array( 'status' => 'invented', 'strategy' => 'loop', 'elements' => 'bad', 'created_artifacts' => array(), 'warnings' => array(), 'fallback_used' => false, 'diagnostics' => array() );
dc_assert( false === Design_Core_Elementor_Executor_Result::validate( $malformed_result ), 'malformed executor result is rejected' );
try { Design_Core_Elementor_Executor_Result::create( 'unsupported', 'loop' ); dc_assert( false, 'fallback diagnostics mandatory' ); } catch ( InvalidArgumentException $e ) { dc_assert( true, 'fallback diagnostics mandatory' ); }

$executor_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'core/build-plan-executor.php' );
dc_assert( false === strpos( $executor_source, 'map_html(' ), 'executor never calls map_html' );
dc_assert( false === strpos( $executor_source, 'source_html' ), 'executor never consumes source_html' );
$v4_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'core/elementor-v4-adapter.php' );
dc_assert( false === strpos( $v4_source, 'to_atomic_composition' ), 'V4 adapter never invents Atomic storage/composition' );
dc_assert( false !== strpos( $v4_source, 'design_core_elementor_v4_composition_transform' ), 'V4 requires an explicit governed public transformer' );
dc_assert( false !== strpos( $v4_source, 'design_core_elementor_v4_composition_reload' ) && false !== strpos( $v4_source, 'design_core_elementor_v4_composition_render' ), 'V4 reload/render require governed public integration' );
$converter_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'core/html-converter.php' );
dc_assert( false !== strpos( $converter_source, 'Design_Core_Elementor_Conversion_Service' ), 'HTML converter delegates to canonical service' );
dc_assert( interface_exists( 'Design_Core_Elementor_Global_Style_Adapter_Interface' ) || file_exists( DESIGN_CORE_ELEMENTOR_PATH . 'core/global-style-adapters.php' ), 'global style adapter boundary exists' );
dc_assert( file_exists( DESIGN_CORE_ELEMENTOR_PATH . 'core/responsive-normalizer.php' ), 'responsive normalization boundary exists' );
$responsive_ir = $ir; $responsive_ir['nodes'][0]['responsive'] = array( 'mobile' => array( 'gap' => array( 'value' => 12, 'unit' => 'px' ) ) );
$responsive_ir = ( new Design_Core_Elementor_Responsive_Normalizer() )->normalize( $responsive_ir );
dc_assert( 12 === (int) $responsive_ir['nodes'][0]['responsive']['mobile']['gap']['value'], 'changed nested responsive dimension is preserved' );
$evidence = ( new Design_Core_Elementor_Runtime_Evidence() )->record( 'architecture-contract', 'partial', array(), 'tests' );
dc_assert( 1 === $evidence['schema_version'], 'RuntimeEvidence v1 created' );
dc_assert( isset( $evidence['environment'], $evidence['timestamp'], $evidence['ttl'] ), 'RuntimeEvidence includes environment and TTL' );
$changed_environment = $evidence; $changed_environment['php_version'] = '0.0.0';
dc_assert( false === ( new Design_Core_Elementor_Runtime_Evidence() )->is_fresh( $changed_environment ), 'runtime evidence expires when environment changes' );
$service_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'core/conversion-service.php' );
dc_assert( false !== strpos( $service_source, 'Design_Core_Elementor_Asset_Importer' ) && false !== strpos( $service_source, 'import_assets' ), 'canonical conversion path wires governed asset import' );
$GLOBALS['dc_test_options']['design_core_elementor_components'] = array( 'before' => true );
$transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();
$GLOBALS['dc_test_options']['design_core_elementor_components'] = array( 'after' => true );
$rollback = $transaction->rollback();
dc_assert( is_array( $rollback ) && 'rolled-back' === $rollback['status'], 'transaction returns explicit rollback result' );
dc_assert( array( 'after' => true ) === get_option( 'design_core_elementor_components' ), 'conversion rollback never rewinds shared registry state' );
$GLOBALS['dc_test_options']['design_core_elementor_components'] = array( 'before-unrelated' => true );
$transaction = ( new Design_Core_Elementor_Conversion_Transaction() )->begin();
$GLOBALS['dc_test_options']['design_core_elementor_components'] = array( 'concurrent-write' => true );
$transaction->rollback();
dc_assert( array( 'concurrent-write' => true ) === get_option( 'design_core_elementor_components' ), 'rollback preserves unowned concurrent registry write' );
dc_assert( false === strpos( $service_source, 'track_registry_mutation' ), 'conversion service uses atomic registry mutation instead of snapshot rollback' );
dc_assert( false !== strpos( $service_source, '$runtime_adapter = $atomic_fallback_reason ? new Design_Core_Elementor_V3_Adapter() : $adapter' ), 'V4 fallback reloads and renders through V3 adapter contract' );

$planner_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'core/build-planner.php' );
dc_assert( false !== strpos( $planner_source, 'Design_Core_Elementor_Decision_Engine' ), 'canonical Build Planner calls the Decision Engine' );
dc_assert( false !== strpos( $planner_source, 'Design_Core_Elementor_Reuse_Engine' ) && false !== strpos( $planner_source, 'Design_Core_Elementor_Variant_Engine' ), 'canonical Build Planner consults Reuse/Variant Engine, not a bare registry ternary' );
dc_assert( false !== strpos( $planner_source, "throw new RuntimeException" ), 'unrecognized Decision Engine strategy fails closed' );

$decision_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'core/decision-engine.php' );
dc_assert( false !== strpos( $decision_source, 'function decide_node' ), 'Decision Engine exposes a canonical IR-shaped decision method' );
$decide_node_start = strpos( $decision_source, 'function decide_node' );
$decide_component_start = strpos( $decision_source, 'function decide_component' );
$decide_node_body = ( false !== $decide_node_start && false !== $decide_component_start && $decide_component_start > $decide_node_start ) ? substr( $decision_source, $decide_node_start, $decide_component_start - $decide_node_start ) : '';
dc_assert( '' !== $decide_node_body && 0 === preg_match( '/\$\w+\[\s*[\'"]html[\'"]\s*\]/', $decide_node_body ) && false === strpos( $decide_node_body, 'preg_match' ), 'decide_node() never parses HTML' );

$executor_body_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'core/strategy-executors.php' );
dc_assert( false !== strpos( $executor_body_source, 'Design_Core_Elementor_Custom_Widget_Executor' ), 'custom-widget strategy executor delegates to Custom_Widget_Executor' );
dc_assert( false !== strpos( $executor_body_source, 'Design_Core_Elementor_Loop_Executor' ), 'loop strategy executor delegates to Loop_Executor' );
$executors = Design_Core_Elementor_Strategy_Executors::defaults();
foreach ( $executors as $strategy_executor ) {
    $reflection = new ReflectionProperty( $strategy_executor, 'implemented' );
    $reflection->setAccessible( true );
    dc_assert( true === $reflection->getValue( $strategy_executor ), get_class( $strategy_executor ) . ' is marked implemented' );
}

dc_assert( file_exists( DESIGN_CORE_ELEMENTOR_PATH . 'core/control-schema-registry.php' ), 'Runtime Control Schema Registry exists' );
dc_assert( file_exists( DESIGN_CORE_ELEMENTOR_PATH . 'core/widget-control-adapters.php' ), 'Widget-specific control adapters exist' );
dc_assert( file_exists( DESIGN_CORE_ELEMENTOR_PATH . 'core/scoped-css-fallback.php' ), 'Scoped per-element CSS fallback exists' );
dc_assert( file_exists( DESIGN_CORE_ELEMENTOR_PATH . 'core/elementor-architecture-auditor.php' ), 'Elementor architecture auditor exists' );
$plugin_loader_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'design-core-elementor.php' );
dc_assert( false !== strpos( $plugin_loader_source, 'core/control-schema-registry.php' ) && false !== strpos( $plugin_loader_source, 'core/widget-control-adapters.php' ), 'Plugin runtime loads control discovery before the mapping engine' );
$visual_qa_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'core/visual-qa.php' );
dc_assert( false !== strpos( $visual_qa_source, 'Design_Core_Elementor_Architecture_Auditor' ), 'Visual QA enforces Elementor-native architecture coverage' );

dc_assert( file_exists( DESIGN_CORE_ELEMENTOR_PATH . 'core/native-widget-resolver.php' ), 'Native Widget Resolver exists' );
dc_assert( file_exists( DESIGN_CORE_ELEMENTOR_PATH . 'core/native-widget-binder.php' ), 'Native Widget Binder exists' );
$native_widget_class_start = strpos( $executor_body_source, 'class Design_Core_Elementor_Native_Widget_Executor' );
$native_compose_class_start = strpos( $executor_body_source, 'class Design_Core_Elementor_Native_Compose_Executor' );
$native_widget_class_body = ( false !== $native_widget_class_start && false !== $native_compose_class_start ) ? substr( $executor_body_source, $native_widget_class_start, $native_compose_class_start - $native_widget_class_start ) : '';
dc_assert( false !== strpos( $native_widget_class_body, 'Design_Core_Elementor_Native_Widget_Resolver' ), 'native-widget strategy executor resolves an actual native widget instead of falling through to generic composition' );
dc_assert( false !== strpos( $native_widget_class_body, 'Design_Core_Elementor_Native_Widget_Binder' ), 'native-widget strategy executor binds real content through Native_Widget_Binder' );
$resolver_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'core/native-widget-resolver.php' );
dc_assert( false !== strpos( $resolver_source, 'has_required_controls' ), 'Native Widget Resolver verifies the resolved widget actually has the controls it needs (rejects promotional/stub widgets), not just that a widgetType name is registered' );
dc_assert( false !== strpos( $planner_source, "'native-widget-unavailable'" ), 'native-widget BuildPlan items carry an explicit fallback diagnostic when no real native widget is available' );
// Structural invariant: the resolver must never advertise a widget_type as "supported" that
// Native_Widget_Binder can't actually bind -- that would just move the fake-widget problem one
// step later instead of eliminating it. Checked directly against both classes' real state, not
// by string-matching the resolver's own source.
$binder_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'core/native-widget-binder.php' );
foreach ( Design_Core_Elementor_Native_Widget_Resolver::ROLE_CANDIDATES as $role => $candidates ) {
    foreach ( $candidates as $candidate ) {
        $wanted = $candidate['widget_type'];
        // A candidate is bindable when the binder has a dedicated case for it
        // OR routes it explicitly (e.g. accordion/toggle share accordion()).
        $has_case = false !== strpos( $binder_source, "case '" . $wanted . "':" );
        $has_dispatch = 1 === preg_match( '/in_array\s*\(\s*\$widget\s*,\s*array\s*\([^)]*\'' . preg_quote( $wanted, '/' ) . "'/", $binder_source );
        dc_assert( $has_case || $has_dispatch, 'Native_Widget_Binder has a real case for resolver candidate "' . $wanted . '" (role: ' . $role . ')' );
    }
}

dc_assert( file_exists( DESIGN_CORE_ELEMENTOR_PATH . 'core/pro-loop-adapter.php' ), 'Elementor Pro Loop adapter exists as a dedicated class' );
$pro_loop_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'core/pro-loop-adapter.php' );
foreach ( array( 'supports', 'preflight', 'create_loop_template', 'create_query_binding', 'create_loop_grid', 'validate', 'rollback' ) as $method ) {
    dc_assert( false !== strpos( $pro_loop_source, 'function ' . $method ), 'Pro_Loop_Adapter implements ' . $method . '()' );
}
dc_assert( false === strpos( $pro_loop_source, 'ElementorPro\\' ) || false !== strpos( $pro_loop_source, 'class_exists' ), 'Pro Loop adapter only probes for Elementor Pro classes, never assumes their private internals' );
$loop_executor_source = file_get_contents( DESIGN_CORE_ELEMENTOR_PATH . 'core/loop-executor.php' );
dc_assert( false !== strpos( $loop_executor_source, 'Design_Core_Elementor_Pro_Loop_Adapter' ), 'Loop_Executor delegates to the formal Pro_Loop_Adapter contract rather than embedding Pro-specific logic itself' );

// A fallback recovering ONE item in a multi-root plan must not discard the other items' elements
// or skip executing them -- Build_Plan_Executor previously `return`ed from inside the items loop
// the moment any single item needed its fallback, silently dropping every other section on the
// page. Every existing test used single-section fixtures, so this never surfaced until an
// independent review traced it directly against the executor's own source.
$multi_node_a = $node; $multi_node_a['id'] = 'node-a';
$multi_node_b = $node; $multi_node_b['id'] = 'node-b';
$multi_ir = array( 'schema_version' => 4, 'type' => 'design-ir', 'nodes' => array( $multi_node_a, $multi_node_b ), 'root_ids' => array( 'node-a', 'node-b' ), 'analysis_quality' => array(), 'tokens' => array(), 'diagnostics' => array() );
$multi_plan = Design_Core_Elementor_Build_Plan::create( array(
    // reuse-widget with no registry_id set safely returns 'unsupported' (registered-widget-not-found)
    // without needing Widget_Registry/Elementor to be loaded, so this exercises a real fallback recovery
    // in the standalone (no-WordPress) test environment.
    Design_Core_Elementor_Build_Plan_Item::create( 'node-a', 'reuse-widget', 'elementor-v3', array( 'fallback' => array( 'allowed' => true, 'strategy' => 'native-compose', 'reason' => 'reuse-widget-unavailable' ) ) ),
    Design_Core_Elementor_Build_Plan_Item::create( 'node-b', 'native-compose', 'elementor-v3' ),
) );
$multi_execution = ( new Design_Core_Elementor_Build_Plan_Executor( $executors ) )->execute( $multi_plan, array( 'ir' => $multi_ir, 'adapter' => new DC_Test_Adapter() ) );
dc_assert( 'success' === ( $multi_execution['status'] ?? '' ), 'a plan with one fallback-recovered item still succeeds overall' );
dc_assert( 2 === count( $multi_execution['elements'] ?? array() ), 'both the fallback-recovered item AND the item after it contribute elements to the final page (found ' . count( $multi_execution['elements'] ?? array() ) . ')' );
dc_assert( 2 === count( $multi_execution['diagnostics']['item_results'] ?? array() ), 'item_results carries an entry for every item, including the one that used its fallback' );
dc_assert( true === ( $multi_execution['diagnostics']['item_results'][0]['fallback_used'] ?? false ), 'the first item result is marked fallback_used' );

dc_finish( 'Architecture' );

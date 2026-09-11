<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class Design_Core_Elementor_Abstract_Strategy_Executor implements Design_Core_Elementor_Strategy_Executor_Interface {
    protected $strategy = '';
    protected $implemented = false;
    public function supports( array $plan_item, array $context ) { return $this->strategy === ( $plan_item['strategy'] ?? '' ); }
    public function preflight( array $plan_item, array $context ) {
        if ( ! $this->implemented ) { return Design_Core_Elementor_Executor_Result::create( 'unsupported', $this->strategy, array(), array(), array(), false, $this->diagnostics( $this->strategy, '', 'strategy-implementation-unavailable', 'strategy-unavailable' ) ); }
        if ( empty( $context['ir'] ) || empty( $context['adapter'] ) || ! $context['adapter'] instanceof Design_Core_Elementor_Adapter_Interface ) { return Design_Core_Elementor_Executor_Result::create( 'failed', $this->strategy, array(), array(), array(), false, $this->diagnostics( $this->strategy, '', 'execution-context-invalid', 'invalid-context' ) ); }
        return true;
    }
    public function execute( array $plan_item, array $context ) {
        $preflight = $this->preflight( $plan_item, $context );
        if ( true !== $preflight ) { return $preflight; }
        $sub_ir = $this->sub_ir( $context['ir'], $plan_item['node_id'] );
        $elements = $context['adapter']->normalize( $sub_ir );
        if ( ! $context['adapter']->validate( $elements ) ) { return Design_Core_Elementor_Executor_Result::create( 'failed', $this->strategy, array(), array(), array(), false, $this->diagnostics( $this->strategy, '', 'adapter-output-invalid', 'invalid-output' ) ); }
        $artifacts = array_map( static function ( $element ) { return array( 'type' => 'elementor-element', 'id' => $element['id'] ?? '' ); }, $elements );
        return Design_Core_Elementor_Executor_Result::create( 'success', $this->strategy, $elements, $artifacts );
    }
    public function validate( array $result, array $context ) { return Design_Core_Elementor_Executor_Result::validate( $result ); }
    public function rollback( array $result, array $context ) { return true; }
    protected function diagnostics( $requested, $actual, $reason, $code ) { return array( 'requested_strategy' => $requested, 'actual_strategy' => $actual, 'reason' => $reason, 'error_code' => $code ); }
    protected function sub_ir( $ir, $root_id ) {
        $by_id = array(); foreach ( $ir['nodes'] as $node ) { $by_id[ $node['id'] ] = $node; }
        if ( ! isset( $by_id[ $root_id ] ) ) { throw new InvalidArgumentException( 'BuildPlan node does not exist in DesignIR.' ); }
        $ids = array(); $walk = function ( $id ) use ( &$walk, &$ids, $by_id ) { if ( isset( $ids[ $id ] ) ) { return; } $ids[ $id ] = true; foreach ( $by_id[ $id ]['children'] ?? array() as $child ) { $walk( $child ); } }; $walk( $root_id );
        $sub = $ir; $sub['root_ids'] = array( $root_id ); $sub['nodes'] = array_values( array_filter( $ir['nodes'], static function ( $node ) use ( $ids ) { return isset( $ids[ $node['id'] ] ); } ) ); return $sub;
    }
    /** Returns the single root node object of an already-scoped sub-IR (as produced by sub_ir()). */
    protected function root_node( $sub_ir ) {
        $root_id = $sub_ir['root_ids'][0] ?? '';
        foreach ( $sub_ir['nodes'] as $node ) { if ( $node['id'] === $root_id ) { return $node; } }
        return array();
    }

    /**
     * Elementor registers widgets once, early, via the elementor/widgets/register hook. A
     * generic-runtime widget the current request just created in the Widget Registry would
     * otherwise be silently dropped by document->save() until a later request re-bootstraps
     * Elementor, so register it with the live widgets_manager immediately if it isn't already.
     */
    protected function ensure_runtime_widget_registered( $widget_type ) {
        if ( ! class_exists( '\Elementor\Plugin' ) ) { return; }
        if ( class_exists( 'Design_Core_Elementor_Plugin' ) ) { Design_Core_Elementor_Plugin::require_widget_base_classes(); }
        if ( ! class_exists( 'Design_Core_Elementor_Runtime_Widget' ) ) { return; }
        $widgets_manager = \Elementor\Plugin::instance()->widgets_manager;
        if ( ! $widgets_manager || $widgets_manager->get_widget_types( $widget_type ) ) { return; }
        $definition = ( new Design_Core_Elementor_Widget_Registry() )->get( $widget_type );
        if ( ! $definition ) { return; }
        try { $widgets_manager->register( new Design_Core_Elementor_Runtime_Widget( array(), array( 'widgetType' => $definition['slug'] ?? $widget_type ) ) ); } catch ( Throwable $exception ) { /* leave unregistered; downstream validate()/reload will fail closed */ }
    }
}

/** Resolves the semantic role to an actually-registered, actually-functional native Elementor widget (Native_Widget_Resolver) and binds real content into it (Native_Widget_Binder) -- never falls through to generic container composition for a role Decision Engine specifically chose native-widget for. */
class Design_Core_Elementor_Native_Widget_Executor extends Design_Core_Elementor_Abstract_Strategy_Executor {
    protected $strategy = 'native-widget';
    protected $implemented = true;
    public function execute( array $plan_item, array $context ) {
        $preflight = $this->preflight( $plan_item, $context );
        if ( true !== $preflight ) { return $preflight; }
        $sub_ir = $this->sub_ir( $context['ir'], $plan_item['node_id'] );
        $root = $this->root_node( $sub_ir );
        $role = sanitize_key( $root['semantic']['role'] ?? '' );
        $capabilities = ( new Design_Core_Elementor_Capability_Scanner() )->scan();
        $resolution = ( new Design_Core_Elementor_Native_Widget_Resolver() )->resolve( $role, $capabilities );
        if ( 'supported' !== ( $resolution['status'] ?? '' ) ) {
            return Design_Core_Elementor_Executor_Result::create( 'unsupported', $this->strategy, array(), array(), array(), false, $this->diagnostics( $this->strategy, '', $resolution['reason'] ?? 'native-widget-unavailable', 'native-widget-capability-missing' ) );
        }
        $element = ( new Design_Core_Elementor_Native_Widget_Binder() )->bind( $sub_ir, $resolution['widget_type'] );
        if ( ! $element || ! $context['adapter']->validate( array( $element ) ) ) {
            return Design_Core_Elementor_Executor_Result::create( 'unsupported', $this->strategy, array(), array(), array(), false, $this->diagnostics( $this->strategy, '', 'native-widget-content-binding-failed', 'native-widget-binding-unavailable' ) );
        }
        return Design_Core_Elementor_Executor_Result::create( 'success', $this->strategy, array( $element ), array( array( 'type' => 'elementor-element', 'id' => $element['id'] ) ) );
    }
}
class Design_Core_Elementor_Native_Compose_Executor extends Design_Core_Elementor_Abstract_Strategy_Executor { protected $strategy = 'native-compose'; protected $implemented = true; }
class Design_Core_Elementor_Reuse_Component_Executor extends Design_Core_Elementor_Abstract_Strategy_Executor { protected $strategy = 'reuse-component'; protected $implemented = true; }

/** Registry already contains a matching widget definition: bind IR content and emit the widget element. No PHP regeneration. */
class Design_Core_Elementor_Reuse_Widget_Executor extends Design_Core_Elementor_Abstract_Strategy_Executor {
    protected $strategy = 'reuse-widget';
    protected $implemented = true;
    public function execute( array $plan_item, array $context ) {
        $preflight = $this->preflight( $plan_item, $context );
        if ( true !== $preflight ) { return $preflight; }
        $registry_id = $plan_item['reuse']['registry_id'] ?? '';
        $widget = $registry_id ? ( new Design_Core_Elementor_Widget_Registry() )->get( $registry_id ) : null;
        if ( ! $widget || empty( $widget['slug'] ) ) {
            return Design_Core_Elementor_Executor_Result::create( 'unsupported', $this->strategy, array(), array(), array(), false, $this->diagnostics( $this->strategy, '', 'registered-widget-not-found', 'widget-not-found' ) );
        }
        $this->ensure_runtime_widget_registered( $widget['slug'] );
        $sub_ir = $this->sub_ir( $context['ir'], $plan_item['node_id'] );
        if ( ! method_exists( $context['adapter'], 'normalize_as_widget' ) ) {
            return Design_Core_Elementor_Executor_Result::create( 'unsupported', $this->strategy, array(), array(), array(), false, $this->diagnostics( $this->strategy, '', 'adapter-widget-binding-unavailable', 'adapter-unsupported' ) );
        }
        $element = $context['adapter']->normalize_as_widget( $sub_ir, $widget['slug'] );
        if ( ! $context['adapter']->validate( array( $element ) ) ) { return Design_Core_Elementor_Executor_Result::create( 'failed', $this->strategy, array(), array(), array(), false, $this->diagnostics( $this->strategy, '', 'adapter-output-invalid', 'invalid-output' ) ); }
        return Design_Core_Elementor_Executor_Result::create( 'success', $this->strategy, array( $element ), array( array( 'type' => 'elementor-element', 'id' => $element['id'] ?? '' ) ) );
    }
}

/** Registry write bookkeeping (master creation, blueprint capture, instance/variant binding) happens in Conversion_Service::synchronize_reuse() against the atomic, locked registry. Element output is the same governed IR->Elementor mapping every strategy uses. */
class Design_Core_Elementor_Component_Executor extends Design_Core_Elementor_Abstract_Strategy_Executor { protected $strategy = 'component'; protected $implemented = true; }
class Design_Core_Elementor_Variant_Executor extends Design_Core_Elementor_Abstract_Strategy_Executor { protected $strategy = 'variant'; protected $implemented = true; }

/** Bridges to Loop_Executor. A capability-gated or runtime-integration-unavailable outcome returns 'unsupported' so the BuildPlan fallback (set by Build_Planner) can take over instead of silently degrading. */
class Design_Core_Elementor_Loop_Strategy_Executor extends Design_Core_Elementor_Abstract_Strategy_Executor {
    protected $strategy = 'loop';
    protected $implemented = true;
    public function execute( array $plan_item, array $context ) {
        $preflight = $this->preflight( $plan_item, $context );
        if ( true !== $preflight ) { return $preflight; }
        $sub_ir = $this->sub_ir( $context['ir'], $plan_item['node_id'] );
        $root = $this->root_node( $sub_ir );
        $legacy_component = array( 'type' => $root['semantic']['role'] ?? '', 'content_schema' => $root['component']['content_schema'] ?? array() );
        $capabilities = ( new Design_Core_Elementor_Capability_Scanner() )->scan();
        $result = ( new Design_Core_Elementor_Loop_Executor() )->execute( $legacy_component, $capabilities );
        if ( 'success' !== ( $result['status'] ?? '' ) || empty( $result['elements'] ) ) {
            return Design_Core_Elementor_Executor_Result::create( 'unsupported', $this->strategy, array(), array(), array(), false, $this->diagnostics( $this->strategy, '', $result['reason'] ?? 'loop-runtime-unavailable', 'loop-unavailable' ) );
        }
        if ( ! $context['adapter']->validate( $result['elements'] ) ) { return Design_Core_Elementor_Executor_Result::create( 'failed', $this->strategy, array(), array(), array(), false, $this->diagnostics( $this->strategy, '', 'adapter-output-invalid', 'invalid-output' ) ); }
        $artifacts = array_map( static function ( $element ) { return array( 'type' => 'elementor-element', 'id' => $element['id'] ?? '' ); }, $result['elements'] );
        return Design_Core_Elementor_Executor_Result::create( 'success', $this->strategy, $result['elements'], $artifacts );
    }
}

/** Bridges to Custom_Widget_Executor for find-or-create registration, then binds IR content into a real Elementor widget element through the governed mapper (Mapping_Engine::map_ir_as_widget). */
class Design_Core_Elementor_Custom_Widget_Strategy_Executor extends Design_Core_Elementor_Abstract_Strategy_Executor {
    protected $strategy = 'custom-widget';
    protected $implemented = true;
    public function execute( array $plan_item, array $context ) {
        $preflight = $this->preflight( $plan_item, $context );
        if ( true !== $preflight ) { return $preflight; }
        $sub_ir = $this->sub_ir( $context['ir'], $plan_item['node_id'] );
        $root = $this->root_node( $sub_ir );
        $legacy_component = array( 'type' => $root['semantic']['role'] ?? 'custom-component', 'content_schema' => $root['component']['content_schema'] ?? array() );
        $result = ( new Design_Core_Elementor_Custom_Widget_Executor() )->execute( $legacy_component );
        if ( 'success' !== ( $result['status'] ?? '' ) || empty( $result['widget_type'] ) ) {
            return Design_Core_Elementor_Executor_Result::create( 'unsupported', $this->strategy, array(), array(), array(), false, $this->diagnostics( $this->strategy, '', $result['reason'] ?? 'custom-widget-unavailable', 'custom-widget-unavailable' ) );
        }
        $this->ensure_runtime_widget_registered( $result['widget_type'] );
        if ( ! method_exists( $context['adapter'], 'normalize_as_widget' ) ) {
            return Design_Core_Elementor_Executor_Result::create( 'unsupported', $this->strategy, array(), array(), array(), false, $this->diagnostics( $this->strategy, '', 'adapter-widget-binding-unavailable', 'adapter-unsupported' ) );
        }
        $element = $context['adapter']->normalize_as_widget( $sub_ir, $result['widget_type'] );
        if ( ! $context['adapter']->validate( array( $element ) ) ) { return Design_Core_Elementor_Executor_Result::create( 'failed', $this->strategy, array(), array(), array(), false, $this->diagnostics( $this->strategy, '', 'adapter-output-invalid', 'invalid-output' ) ); }
        $artifacts = array( array( 'type' => 'elementor-element', 'id' => $element['id'] ?? '' ) );
        if ( ! empty( $result['created'] ) ) { $artifacts[] = array( 'type' => 'widget-registration', 'id' => $result['widget_type'] ); }
        return Design_Core_Elementor_Executor_Result::create( 'success', $this->strategy, array( $element ), $artifacts );
    }
}

/** Last-resort structural fallback: native containers/widgets plus explicit per-property diagnostics. Build_Planner never routes here for a property natively supported by Elementor controls (padding/margin/font-size/color/background/border/radius/gap). */
class Design_Core_Elementor_CSS_Fallback_Executor extends Design_Core_Elementor_Abstract_Strategy_Executor {
    protected $strategy = 'css-fallback';
    protected $implemented = true;
    const DISALLOWED_PROPERTIES = array( 'padding', 'margin', 'font-size', 'color', 'background', 'border', 'radius', 'gap' );
    public function execute( array $plan_item, array $context ) {
        $preflight = $this->preflight( $plan_item, $context );
        if ( true !== $preflight ) { return $preflight; }
        $sub_ir = $this->sub_ir( $context['ir'], $plan_item['node_id'] );
        $elements = $context['adapter']->normalize( $sub_ir );
        if ( ! $context['adapter']->validate( $elements ) ) { return Design_Core_Elementor_Executor_Result::create( 'failed', $this->strategy, array(), array(), array(), false, $this->diagnostics( $this->strategy, '', 'adapter-output-invalid', 'invalid-output' ) ); }
        $properties = array_values( array_diff( array_map( 'sanitize_key', (array) ( $plan_item['diagnostics']['css_fallback_properties'] ?? array() ) ), self::DISALLOWED_PROPERTIES ) );
        $artifacts = array_map( static function ( $element ) { return array( 'type' => 'elementor-element', 'id' => $element['id'] ?? '' ); }, $elements );
        $diagnostics = array(
            'node_id' => $plan_item['node_id'],
            'properties' => $properties,
            'reason' => $plan_item['fallback']['reason'] ?? 'no-native-control-for-property',
            'native_capabilities_checked' => true,
            'scope' => $plan_item['node_id'],
        );
        return Design_Core_Elementor_Executor_Result::create( 'success', $this->strategy, $elements, $artifacts, array(), false, $diagnostics );
    }
}

/** Absolute last resort. Only ever reached as another item's fallback.strategy, once Build_Planner has already recorded that native/component/widget/css alternatives were checked (item.diagnostics.fallback_trail). */
class Design_Core_Elementor_Raw_HTML_Fallback_Executor extends Design_Core_Elementor_Abstract_Strategy_Executor {
    protected $strategy = 'raw-html-fallback';
    protected $implemented = true;
    public function execute( array $plan_item, array $context ) {
        $preflight = $this->preflight( $plan_item, $context );
        if ( true !== $preflight ) { return $preflight; }
        $sub_ir = $this->sub_ir( $context['ir'], $plan_item['node_id'] );
        $elements = $context['adapter']->normalize( $sub_ir );
        if ( ! $context['adapter']->validate( $elements ) ) { return Design_Core_Elementor_Executor_Result::create( 'failed', $this->strategy, array(), array(), array(), false, $this->diagnostics( $this->strategy, '', 'adapter-output-invalid', 'invalid-output' ) ); }
        $artifacts = array_map( static function ( $element ) { return array( 'type' => 'elementor-element', 'id' => $element['id'] ?? '' ); }, $elements );
        $diagnostics = array(
            'node_id' => $plan_item['node_id'],
            'checked' => $plan_item['diagnostics']['fallback_trail'] ?? array( 'native', 'component', 'widget', 'css' ),
            'reason' => $plan_item['fallback']['reason'] ?? 'no-governed-alternative-available',
        );
        return Design_Core_Elementor_Executor_Result::create( 'success', $this->strategy, $elements, $artifacts, array(), false, $diagnostics );
    }
}

class Design_Core_Elementor_Strategy_Executors {
    public static function defaults() { return array( new Design_Core_Elementor_Native_Widget_Executor(), new Design_Core_Elementor_Native_Compose_Executor(), new Design_Core_Elementor_Reuse_Component_Executor(), new Design_Core_Elementor_Reuse_Widget_Executor(), new Design_Core_Elementor_Component_Executor(), new Design_Core_Elementor_Variant_Executor(), new Design_Core_Elementor_Loop_Strategy_Executor(), new Design_Core_Elementor_Custom_Widget_Strategy_Executor(), new Design_Core_Elementor_CSS_Fallback_Executor(), new Design_Core_Elementor_Raw_HTML_Fallback_Executor() ); }
}

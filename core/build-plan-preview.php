<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only BuildPlan preflight. Never executes strategies or mutates registries/posts. */
class Design_Core_Elementor_Build_Plan_Preview {
    const SCHEMA_VERSION = 2;

    private $planner;
    private $normalizer;

    public function __construct( $planner = null, $normalizer = null ) {
        $this->planner = $planner ?: new Design_Core_Elementor_Build_Planner();
        $this->normalizer = $normalizer ?: new Design_Core_Elementor_Normalization_Pipeline();
    }

    public function preview_source( $html, $css = '', $source_name = 'preview', $adapter_target = 'auto' ) {
        $analysis = ( new Design_Core_Elementor_Conversion_Service() )->analyze( $html, $css, $source_name );
        if ( ! empty( $analysis['error'] ) ) { return new WP_Error( 'design_core_preview_analysis_failed', $analysis['message'] ?? $analysis['error'] ); }
        $preview = $this->preview_ir( (array) ( $analysis['design_ir'] ?? array() ), $adapter_target, (string) $css );
        if ( ! is_wp_error( $preview ) ) { $preview['analysis_summary'] = $analysis['summary'] ?? array(); }
        return $preview;
    }

    public function preview_ir( array $ir, $adapter_target = 'auto', $css = '' ) {
        try {
            ( new Design_Core_Elementor_Design_IR_Validator() )->validate( $ir );
            $ir = $this->normalizer->normalize( $ir, (string) $css );
            $target = $this->resolve_adapter_target( $adapter_target );
            $plan = $this->planner->plan( $ir, $target );
        } catch ( Throwable $exception ) {
            return new WP_Error( 'design_core_preview_contract_failed', $exception->getMessage() );
        }

        $nodes = array(); foreach ( $ir['nodes'] as $node ) { $nodes[ $node['id'] ] = $node; }
        $caps = class_exists( 'Design_Core_Elementor_Capability_Scanner' ) ? ( new Design_Core_Elementor_Capability_Scanner() )->scan() : array();
        $items = array(); $warnings = array(); $requirements = array(); $strategy_counts = array();
        $widget_intelligence = class_exists( 'Design_Core_Elementor_Widget_Intelligence' ) ? new Design_Core_Elementor_Widget_Intelligence() : null;

        foreach ( (array) ( $plan['items'] ?? array() ) as $item ) {
            $node = $nodes[ $item['node_id'] ] ?? array();
            $strategy = sanitize_key( $item['strategy'] ?? '' );
            $strategy_counts[ $strategy ] = ( $strategy_counts[ $strategy ] ?? 0 ) + 1;
            $semantic = sanitize_key( $node['semantic']['role'] ?? '' );
            $content_schema = array_values( array_filter( array_map( 'sanitize_key', (array) ( $node['component']['content_schema'] ?? array() ) ) ) );
            $candidates = array();
            $requested_capabilities = $this->capabilities_from_schema( $content_schema );
            if ( $widget_intelligence && in_array( $strategy, array( 'native-widget', 'native-compose', 'custom-widget' ), true ) ) {
                $candidates = $widget_intelligence->find_candidates( array(
                    'intent' => $this->intent_from_role( $semantic ),
                    'keywords' => array( $semantic ),
                    'capabilities' => $requested_capabilities,
                ), 5 );
                foreach ( $candidates as $candidate_index => $candidate ) {
                    if ( $candidate_index >= 3 ) { break; }
                    $candidates[ $candidate_index ]['runtime_control_evidence'] = $this->runtime_control_evidence( $widget_intelligence, $candidate['name'] ?? '', $requested_capabilities );
                }
            }

            $item_warnings = array();
            if ( 'loop' === $strategy && empty( $caps['elementor']['loop_available'] ) ) { $item_warnings[] = 'Elementor Loop runtime is unavailable; fallback is expected.'; }
            if ( 'elementor-v4' === ( $item['adapter_target'] ?? '' ) && empty( $caps['capabilities']['atomic_build_composition'] ) ) { $item_warnings[] = 'Atomic build-composition capability is unavailable.'; }
            if ( ! empty( $item['fallback']['allowed'] ) ) { $item_warnings[] = 'Plan contains a governed fallback: ' . sanitize_text_field( $item['fallback']['reason'] ?? '' ); }
            $warnings = array_merge( $warnings, $item_warnings );

            if ( 'elementor-v4' === ( $item['adapter_target'] ?? '' ) ) { $requirements[] = 'elementor-atomic-build-composition'; }
            if ( 'loop' === $strategy ) { $requirements[] = 'elementor-pro-loop'; }
            if ( $candidates && 'pro' === ( $candidates[0]['source'] ?? '' ) ) { $requirements[] = 'elementor-pro'; }

            $items[] = array(
                'node_id' => (string) ( $item['node_id'] ?? '' ),
                'semantic_role' => $semantic,
                'section_action' => sanitize_key( $node['section']['reuse']['action'] ?? ( $item['variant']['action'] ?? 'new' ) ),
                'strategy' => $strategy,
                'adapter_target' => (string) ( $item['adapter_target'] ?? '' ),
                'registry' => (array) ( $item['reuse'] ?? array() ),
                'fallback' => (array) ( $item['fallback'] ?? array() ),
                'decision' => (array) ( $item['diagnostics'] ?? array() ),
                'widget_candidates' => $candidates,
                'warnings' => $item_warnings,
            );
        }

        $simulation = class_exists( 'Design_Core_Elementor_Build_Plan_Simulator' )
            ? ( new Design_Core_Elementor_Build_Plan_Simulator() )->simulate( $ir, $plan, $items )
            : array( 'version' => 0, 'mutation_performed' => false, 'estimated_element_count' => self::estimate_elements_from_ir( $ir ), 'summary' => array(), 'items' => array() );
        $estimated = (int) ( $simulation['estimated_element_count'] ?? self::estimate_elements_from_ir( $ir ) );

        $unsafe = array_filter( $items, static function ( $item ) {
            if ( '' === ( $item['strategy'] ?? '' ) ) { return true; }
            if ( 'loop' === ( $item['strategy'] ?? '' ) && empty( $item['fallback']['allowed'] ) ) { return true; }
            return false;
        } );
        $unsafe_reasons = array();
        if ( $unsafe ) { $unsafe_reasons[] = 'One or more BuildPlan items have no safe executable strategy/fallback.'; }
        if ( 'elementor-v4' === $target && empty( $caps['capabilities']['atomic_build_composition'] ) ) { $unsafe_reasons[] = 'Elementor V4 was requested but the public Atomic build-composition capability is unavailable.'; }
        $readiness = $this->binding_readiness( $plan, $ir );
        foreach ( (array) ( $readiness['blocked'] ?? array() ) as $blocked ) { $unsafe_reasons[] = $blocked; }
        $layout_report = class_exists( 'Design_Core_Elementor_Layout_Intelligence' ) ? ( new Design_Core_Elementor_Layout_Intelligence() )->report( $ir ) : array();

        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'dry_run' => true,
            'mutation_performed' => false,
            // Exposed so callers that gate mutation behind an approved preview (rc21 remote
            // API preview tickets) can persist the exact IR that was planned against, instead
            // of re-normalizing input themselves and risking drift from what was previewed.
            'normalized_ir' => $ir,
            'can_execute_safely' => empty( $unsafe_reasons ),
            'unsafe_reasons' => $unsafe_reasons,
            'adapter_target' => $target,
            'estimated_element_count' => $estimated,
            'root_count' => count( (array) ( $ir['root_ids'] ?? array() ) ),
            'strategy_counts' => $strategy_counts,
            'requirements' => array_values( array_unique( $requirements ) ),
            'warnings' => array_values( array_unique( array_filter( $warnings ) ) ),
            'layout_intelligence' => $layout_report,
            'execution_simulation' => $simulation,
            'binding_readiness' => $readiness,
            'responsive_mapping' => $ir['diagnostics']['responsive_mapping'] ?? array(),
            'css_policy' => self::css_policy_summary( $ir ),
            'items' => $items,
            'build_plan' => $plan,
        );
    }

    /**
     * Pre-execution CSS posture: how many IR nodes already carry fallback CSS
     * declarations (the pool custom CSS would be drawn from), so reviewers can
     * see styling risk before anything is built. Post-execution truth lives in
     * the executor gate metrics; this estimate never blocks by itself.
     */
    private static function css_policy_summary( array $ir ) {
        $nodes_with_fallback = 0;
        $fallback_declarations = 0;
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $fallback = (array) ( $node['style']['css_fallback'] ?? array() );
            if ( $fallback ) {
                $nodes_with_fallback++;
                $fallback_declarations += count( $fallback );
            }
        }
        return array(
            'ir_nodes_with_css_fallback' => $nodes_with_fallback,
            'ir_css_fallback_declarations' => $fallback_declarations,
        );
    }

    /**
     * Static pre-execution honesty check. For native-widget items whose role
     * the resolver actually covers, an unsupported verdict here becomes an
     * unsafe reason so preview can never report a plan as safely writable
     * that the executor would refuse. Roles outside resolver coverage are
     * reported as verified-at-apply (enforced by the shared executor gate),
     * never guessed.
     */
    private function binding_readiness( $plan, $ir ) {
        $checked = 0;
        $blocked = array();
        $deferred = 0;
        if ( ! class_exists( 'Design_Core_Elementor_Native_Widget_Resolver' ) ) {
            return array( 'checked' => 0, 'blocked' => array(), 'deferred' => 0, 'note' => 'resolver-unavailable' );
        }
        $nodes = array();
        foreach ( (array) ( $ir['nodes'] ?? array() ) as $node ) {
            if ( is_array( $node ) && ! empty( $node['id'] ) ) { $nodes[ $node['id'] ] = $node; }
        }
        $candidates = array();
        if ( method_exists( 'Design_Core_Elementor_Native_Widget_Resolver', 'role_candidates' ) ) {
            $candidates = (array) Design_Core_Elementor_Native_Widget_Resolver::role_candidates();
        } else {
            $reflection = new ReflectionClass( 'Design_Core_Elementor_Native_Widget_Resolver' );
            if ( $reflection->hasConstant( 'ROLE_CANDIDATES' ) ) {
                $candidates = (array) $reflection->getConstant( 'ROLE_CANDIDATES' );
            }
        }
        foreach ( (array) ( $plan['items'] ?? array() ) as $item ) {
            if ( 'native-widget' !== ( $item['strategy'] ?? '' ) ) { continue; }
            $node = $nodes[ $item['node_id'] ?? '' ] ?? array();
            $role = sanitize_key( (string) ( $node['semantic']['role'] ?? '' ) );
            if ( '' === $role || ! array_key_exists( $role, $candidates ) ) { $deferred++; continue; }
            $checked++;
            try {
                $resolution = ( new Design_Core_Elementor_Native_Widget_Resolver() )->resolve( $role, array() );
            } catch ( Throwable $exception ) {
                $blocked[] = 'Item ' . (string) ( $item['node_id'] ?? '' ) . ' (role ' . $role . '): resolver error, verified at apply time.';
                continue;
            }
            if ( 'supported' !== ( $resolution['status'] ?? '' ) ) {
                $blocked[] = 'Item ' . (string) ( $item['node_id'] ?? '' ) . ' (role ' . $role . '): ' . (string) ( $resolution['reason'] ?? 'native-widget-unavailable' ) . '. Execution will fall back; verify the fallback output before approving.';
            }
        }
        return array( 'checked' => $checked, 'blocked' => array_values( $blocked ), 'deferred' => $deferred );
    }

    /** Raw fallback estimator retained for compatibility; rc20 uses the strategy-aware simulator above. */
    public static function estimate_elements_from_ir( array $ir ) { return count( (array) ( $ir['nodes'] ?? array() ) ); }

    private function runtime_control_evidence( $service, $widget_name, array $capabilities ) {
        if ( ! $widget_name || ! is_object( $service ) || ! method_exists( $service, 'inspect' ) || ! $capabilities ) { return array(); }
        $detail = $service->inspect( $widget_name );
        if ( is_wp_error( $detail ) || ! is_array( $detail ) ) { return array(); }
        $evidence = array_fill_keys( $capabilities, array() );
        foreach ( (array) ( $detail['controls'] ?? array() ) as $row ) {
            $path = strtolower( (string) ( $row['path'] ?? '' ) );
            $definition = (array) ( $row['definition'] ?? array() );
            $type = sanitize_key( (string) ( $definition['type'] ?? '' ) );
            foreach ( $capabilities as $capability ) {
                if ( count( $evidence[ $capability ] ) >= 8 || ! $this->control_supports_capability( $path, $type, $definition, $capability ) ) { continue; }
                $evidence[ $capability ][] = (string) ( $row['path'] ?? '' );
            }
        }
        foreach ( $evidence as $capability => $paths ) { $evidence[ $capability ] = array_values( array_unique( array_filter( $paths ) ) ); }
        return $evidence;
    }

    private function control_supports_capability( $path, $type, array $definition, $capability ) {
        switch ( sanitize_key( $capability ) ) {
            case 'media': return in_array( $type, array( 'media', 'gallery' ), true ) || (bool) preg_match( '/(?:image|video|media|gallery)/', $path );
            case 'link': return 'url' === $type || (bool) preg_match( '/(?:^|\\.)(?:link|url)(?:_|\\.|$)/', $path );
            case 'dynamic': return ! empty( $definition['dynamic'] ) || false !== strpos( $path, 'dynamic' );
            case 'responsive': return ! empty( $definition['responsive'] ) || ! empty( $definition['is_responsive'] );
            case 'icon': return in_array( $type, array( 'icon', 'icons' ), true ) || false !== strpos( $path, 'icon' );
            case 'typography': return 'font' === $type || false !== strpos( $path, 'typography' ) || false !== strpos( $path, 'font_' );
            case 'hover': return false !== strpos( $path, 'hover' );
            case 'spacing': return in_array( $type, array( 'dimensions', 'gaps' ), true ) || (bool) preg_match( '/(?:margin|padding|gap|spacing)/', $path );
        }
        return false;
    }

    private function resolve_adapter_target( $requested ) {
        $requested = sanitize_key( (string) $requested );
        if ( in_array( $requested, array( 'elementor-v3', 'elementor-v4' ), true ) ) { return $requested; }
        $caps = class_exists( 'Design_Core_Elementor_Capability_Scanner' ) ? ( new Design_Core_Elementor_Capability_Scanner() )->scan() : array();
        if ( 'v4' === ( $caps['elementor']['editor_mode'] ?? 'v3' ) && ! empty( $caps['capabilities']['atomic_build_composition'] ) ) { return 'elementor-v4'; }
        return 'elementor-v3';
    }

    private function intent_from_role( $role ) {
        if ( in_array( $role, array( 'cta', 'button', 'action' ), true ) ) { return 'action'; }
        if ( in_array( $role, array( 'faq', 'form' ), true ) ) { return 'form'; }
        if ( in_array( $role, array( 'navigation', 'menu' ), true ) ) { return 'navigation'; }
        if ( in_array( $role, array( 'hero', 'heading', 'text', 'content' ), true ) ) { return 'content'; }
        if ( false !== strpos( $role, 'product' ) || false !== strpos( $role, 'pricing' ) ) { return 'commerce'; }
        return $role;
    }

    private function capabilities_from_schema( array $schema ) {
        $map = array(
            'media' => array( 'media', 'responsive' ), 'image' => array( 'media', 'responsive' ),
            'link' => array( 'link', 'hover', 'responsive' ), 'heading' => array( 'typography', 'responsive' ),
            'rich_text' => array( 'typography', 'responsive' ), 'interactive' => array( 'dynamic', 'responsive' ),
        );
        $caps = array();
        foreach ( $schema as $slot ) { if ( isset( $map[ $slot ] ) ) { $caps = array_merge( $caps, $map[ $slot ] ); } }
        return array_values( array_unique( $caps ) );
    }
}

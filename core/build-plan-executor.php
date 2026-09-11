<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Strategy-agnostic BuildPlan orchestrator. */
class Design_Core_Elementor_Build_Plan_Executor {
    private $executors;
    public function __construct( $executors = null ) { $this->executors = is_array( $executors ) ? array_values( $executors ) : Design_Core_Elementor_Strategy_Executors::defaults(); }
    public function execute( $plan, $context = array() ) {
        ( new Design_Core_Elementor_Build_Plan_Validator() )->validate( $plan );
        $elements = array(); $artifacts = array(); $warnings = array(); $results = array();
        foreach ( $plan['items'] as $item ) {
            if ( empty( $context['adapter'] ) || ! $context['adapter'] instanceof Design_Core_Elementor_Adapter_Interface || $item['adapter_target'] !== $context['adapter']->target() ) { return Design_Core_Elementor_Executor_Result::create( 'unsupported', $item['strategy'], array(), array(), array(), false, $this->diagnostics( $item['strategy'], '', 'adapter-target-mismatch', 'adapter-mismatch' ) ); }
            $executor = $this->resolve( $item, $context );
            if ( ! $executor ) { return Design_Core_Elementor_Executor_Result::create( 'unsupported', $item['strategy'], array(), array(), array(), false, $this->diagnostics( $item['strategy'], '', 'executor-unavailable', 'executor-not-found' ) ); }
            $result = $executor->execute( $item, $context );
            if ( ! $executor->validate( $result, $context ) ) { return Design_Core_Elementor_Executor_Result::create( 'failed', $item['strategy'], array(), array(), array(), false, $this->diagnostics( $item['strategy'], '', 'executor-result-invalid', 'invalid-result' ) ); }
            if ( 'success' !== $result['status'] ) {
                if ( empty( $item['fallback']['allowed'] ) || empty( $item['fallback']['strategy'] ) ) {
                    // No recovery path for this item: fail the whole conversion rather than silently
                    // publish a page missing this section's content.
                    return $result;
                }
                $executor->rollback( $result, $context );
                $result = $this->execute_fallback( $item, $context, $result );
                if ( 'success' !== $result['status'] ) { return $result; }
                // Fallback recovered this ONE item -- continue with the rest of the plan instead of
                // discarding every other section on the page (every item is one top-level DOM root;
                // one section needing a fallback must never take the whole page down with it).
            }
            $results[] = $result;
            $elements = array_merge( $elements, $result['elements'] ); $artifacts = array_merge( $artifacts, $result['created_artifacts'] ); $warnings = array_merge( $warnings, $result['warnings'] );
        }
        // Shared strict gate: a tree that would fail structural validation must
        // never leave the executor as a writable success, no matter which
        // strategy path or caller produced it.
        $gate = array( 'status' => 'unavailable', 'reason' => 'gate-unavailable' );
        if ( class_exists( 'Design_Core_Elementor_Strict_Structure_Gate' ) ) {
            $gate = Design_Core_Elementor_Strict_Structure_Gate::audit_elements( $elements );
        }
        if ( 'fail' === ( $gate['status'] ?? '' ) ) {
            $gate_strategy = $plan['items'][0]['strategy'] ?? 'native-compose';
            return Design_Core_Elementor_Executor_Result::create( 'failed', $gate_strategy, array(), array(), $warnings, false, array( 'requested_strategy' => $gate_strategy, 'actual_strategy' => '', 'reason' => 'strict-structural-gate', 'error_code' => 'strict-gate-failed', 'strict_gate' => $gate ) );
        }
        $result = Design_Core_Elementor_Executor_Result::create( 'success', $plan['items'][0]['strategy'] ?? 'native-compose', $elements, $artifacts, $warnings, false, array( 'item_results' => $results, 'strict_gate' => $gate ) );
        return $result;
    }
    private function resolve( $item, $context ) { foreach ( $this->executors as $executor ) { if ( $executor instanceof Design_Core_Elementor_Strategy_Executor_Interface && $executor->supports( $item, $context ) ) { return $executor; } } return null; }
    private function execute_fallback( $item, $context, $failed ) {
        $fallback = $item; $fallback['strategy'] = $item['fallback']['strategy']; $fallback['fallback']['allowed'] = false;
        $executor = $this->resolve( $fallback, $context );
        if ( ! $executor ) { return $failed; }
        $result = $executor->execute( $fallback, $context );
        if ( ! $executor->validate( $result, $context ) ) { return Design_Core_Elementor_Executor_Result::create( 'failed', $item['strategy'], array(), array(), array(), true, $this->diagnostics( $item['strategy'], $fallback['strategy'], 'fallback-result-invalid', 'invalid-fallback-result' ) ); }
        $result['fallback_used'] = true;
        // Prefer the failing executor's own specific runtime reason (e.g. "faq-widget-unavailable")
        // over the static, planning-time reason (e.g. "native-widget-unavailable") when it has one.
        $reason = $failed['diagnostics']['reason'] ?? $item['fallback']['reason'];
        $result['diagnostics'] = $this->diagnostics( $item['strategy'], $fallback['strategy'], $reason, $failed['diagnostics']['error_code'] ?? 'strategy-failed' );
        return $result;
    }
    private function diagnostics( $requested, $actual, $reason, $code ) { return array( 'requested_strategy' => $requested, 'actual_strategy' => $actual, 'reason' => $reason, 'error_code' => $code ); }
}

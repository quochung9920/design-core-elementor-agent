<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Native fidelity report: how much of a conversion used real native
 * Elementor controls/strategies versus fallback strategies.
 *
 * Source of truth is the executor's per-item diagnostics
 * ($execution['diagnostics']['item_results']), where each entry may carry
 * 'fallback_used' plus diagnostics.requested_strategy / actual_strategy /
 * reason / error_code. Reporting only -- it never changes what gets built.
 */
class Design_Core_Elementor_Native_Fidelity_Report {
    const DEFAULT_MIN_RATE = 90.0;

    public static function from_execution( $execution ) {
        $items = array();
        if ( is_array( $execution ) && isset( $execution['diagnostics']['item_results'] ) && is_array( $execution['diagnostics']['item_results'] ) ) {
            $items = array_values( $execution['diagnostics']['item_results'] );
        }
        $total = count( $items );
        $fallbacks = array();
        $by_actual_strategy = array();
        foreach ( $items as $index => $item ) {
            $item = is_array( $item ) ? $item : array();
            $diagnostics = isset( $item['diagnostics'] ) && is_array( $item['diagnostics'] ) ? $item['diagnostics'] : array();
            $actual = (string) ( $diagnostics['actual_strategy'] ?? $item['strategy'] ?? 'unknown' );
            if ( '' === $actual ) { $actual = 'unknown'; }
            $by_actual_strategy[ $actual ] = ( $by_actual_strategy[ $actual ] ?? 0 ) + 1;
            if ( empty( $item['fallback_used'] ) ) { continue; }
            $fallbacks[] = array(
                'node_id' => (string) ( $item['node_id'] ?? $item['node'] ?? 'item-' . $index ),
                'requested_strategy' => (string) ( $diagnostics['requested_strategy'] ?? '' ),
                'actual_strategy' => $actual,
                'reason' => (string) ( $diagnostics['reason'] ?? '' ),
                'error_code' => (string) ( $diagnostics['error_code'] ?? '' ),
            );
        }
        $fallback_count = count( $fallbacks );
        $native_count = max( 0, $total - $fallback_count );
        ksort( $by_actual_strategy );
        return array(
            'total_items' => $total,
            'native_items' => $native_count,
            'fallback_items' => $fallback_count,
            'native_rate' => $total > 0 ? round( 100.0 * $native_count / $total, 1 ) : 100.0,
            'by_actual_strategy' => $by_actual_strategy,
            'fallbacks' => $fallbacks,
        );
    }

    public static function meets_threshold( $report, $min_rate ) {
        $report = is_array( $report ) ? $report : array();
        $rate = isset( $report['native_rate'] ) ? (float) $report['native_rate'] : 0.0;
        return $rate >= (float) $min_rate;
    }

    public static function min_rate_from_settings() {
        $rules = class_exists( 'Design_Core_Elementor_Settings' ) ? Design_Core_Elementor_Settings::get_conversion_rules() : array();
        $min = isset( $rules['min_native_rate'] ) ? (float) $rules['min_native_rate'] : self::DEFAULT_MIN_RATE;
        if ( $min < 0 ) { $min = 0; }
        if ( $min > 100 ) { $min = 100; }
        return (float) $min;
    }
}

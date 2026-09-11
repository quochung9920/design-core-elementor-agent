<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Executor_Result {
    const STATUSES = array( 'success', 'deferred', 'unsupported', 'failed' );
    public static function create( $status, $strategy, $elements = array(), $created_artifacts = array(), $warnings = array(), $fallback_used = false, $diagnostics = array() ) {
        if ( ! in_array( $status, self::STATUSES, true ) ) { throw new InvalidArgumentException( 'ExecutorResult status is invalid.' ); }
        if ( ! in_array( $strategy, Design_Core_Elementor_Build_Plan_Validator::STRATEGIES, true ) ) { throw new InvalidArgumentException( 'ExecutorResult strategy is invalid.' ); }
        if ( 'success' !== $status ) {
            foreach ( array( 'requested_strategy', 'actual_strategy', 'reason', 'error_code' ) as $field ) { if ( ! array_key_exists( $field, $diagnostics ) ) { throw new InvalidArgumentException( 'ExecutorResult fallback diagnostics are incomplete.' ); } }
        }
        $result = array( 'status' => $status, 'strategy' => $strategy, 'elements' => array_values( $elements ), 'created_artifacts' => array_values( $created_artifacts ), 'warnings' => array_values( $warnings ), 'fallback_used' => (bool) $fallback_used, 'diagnostics' => $diagnostics );
        if ( ! self::validate( $result ) ) { throw new InvalidArgumentException( 'ExecutorResult contract is invalid.' ); }
        return $result;
    }
    public static function validate( $result ) {
        if ( ! is_array( $result ) || ! in_array( $result['status'] ?? null, self::STATUSES, true ) || ! in_array( $result['strategy'] ?? null, Design_Core_Elementor_Build_Plan_Validator::STRATEGIES, true ) ) { return false; }
        foreach ( array( 'elements', 'created_artifacts', 'warnings', 'diagnostics' ) as $field ) { if ( ! isset( $result[ $field ] ) || ! is_array( $result[ $field ] ) ) { return false; } }
        if ( ! isset( $result['fallback_used'] ) || ! is_bool( $result['fallback_used'] ) ) { return false; }
        if ( 'success' === $result['status'] && ( empty( $result['elements'] ) || empty( $result['created_artifacts'] ) ) ) { return false; }
        if ( 'success' !== $result['status'] || $result['fallback_used'] ) { foreach ( array( 'requested_strategy', 'actual_strategy', 'reason', 'error_code' ) as $field ) { if ( ! array_key_exists( $field, $result['diagnostics'] ) || ! is_string( $result['diagnostics'][ $field ] ) ) { return false; } } }
        return true;
    }
}

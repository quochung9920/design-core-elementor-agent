<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared strict structural gate: the exact agent structural validator,
 * applied to built Elementor trees BEFORE persistence (executor), reported
 * at preview time (dry run), and re-run AFTER persistence (verification).
 *
 * One gate, three call sites -- a plan that would fail strict validation can
 * never be reported as safely writable by one path and rejected by another.
 *
 * Material findings (block writes): restricted, enum, unit, condition,
 * layout_in_richtext, inactive_breakpoint, not_responsive_control,
 * unbound-required-data, flattened-critical. Everything else is reported
 * but non-blocking. Statuses: pass | fail | unavailable (no Elementor
 * runtime in this process -- callers record, never fake, this state).
 */
class Design_Core_Elementor_Strict_Structure_Gate {
    const MATERIAL_CODES = array(
        'restricted', 'enum', 'unit', 'condition', 'layout_in_richtext',
        'inactive_breakpoint', 'not_responsive_control',
        'unbound-required-data', 'flattened-critical',
    );

    public static function audit_elements( $elements, $knowledge = null ) {
        if ( ! class_exists( 'Design_Core_Agent_Validator' ) ) {
            return array( 'status' => 'unavailable', 'reason' => 'validator-unavailable', 'issues' => array(), 'material' => array() );
        }
        if ( null === $knowledge ) {
            if ( ! class_exists( 'Design_Core_Agent_Knowledge' ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
                return array( 'status' => 'unavailable', 'reason' => 'runtime-unavailable', 'issues' => array(), 'material' => array() );
            }
            $knowledge = new Design_Core_Agent_Knowledge();
        }
        $validator = new Design_Core_Agent_Validator( $knowledge );
        $issues = array();
        $by_id = array();
        foreach ( self::flatten( $elements ) as $row ) { $by_id[ $row['element_id'] ] = $row; }
        foreach ( self::flatten( $elements ) as $row ) {
            $result = $validator->validate( array(
                'element_type' => $row['element_type'],
                'widget' => $row['widget'],
                'settings' => $row['settings'],
            ) );
            if ( is_wp_error( $result ) ) {
                $issues[] = array( 'element_id' => $row['element_id'], 'widget' => $row['widget'] ?: $row['element_type'], 'code' => 'schema-' . $result->get_error_code(), 'message' => 'Runtime schema unavailable for this element.' );
                continue;
            }
            foreach ( (array) ( $result['issues'] ?? array() ) as $issue ) {
                $issue['element_id'] = $row['element_id'];
                $issue['widget'] = $row['widget'] ?: $row['element_type'];
                if ( 'restricted' === ( $issue['code'] ?? '' ) && '/custom_css' === substr( (string) ( $issue['path'] ?? '' ), -11 ) ) {
                    $issue['css_sample'] = substr( (string) ( $row['settings']['custom_css'] ?? '' ), 0, 160 );
                }
                if ( in_array( ( $issue['code'] ?? '' ), array( 'unit', 'condition', 'enum' ), true ) ) {
                    $leaf = preg_replace( '#^.*/([^/]+)$#', '$1', (string) ( $issue['path'] ?? '' ) );
                    $leaf = preg_replace( '/_(widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/', '', $leaf );
                    if ( array_key_exists( $leaf, $row['settings'] ) ) {
                        $issue['value_sample'] = substr( is_string( $row['settings'][ $leaf ] ) ? $row['settings'][ $leaf ] : wp_json_encode( $row['settings'][ $leaf ] ), 0, 120 );
                    }
                }
                $issues[] = $issue;
            }
        }
        $material = self::material( $issues );
        $metrics = self::tree_metrics( $elements );
        $max_ratio = self::max_custom_css_ratio();
        if ( $max_ratio < $metrics['custom_css_ratio'] ) {
            $material[] = array( 'element_id' => '', 'code' => 'custom-css-ratio', 'message' => 'Custom CSS element ratio ' . $metrics['custom_css_ratio'] . ' exceeds the ' . $max_ratio . ' threshold.' );
            $material = array_values( $material );
        }
        return array(
            'status' => $material ? 'fail' : 'pass',
            'issues' => $issues,
            'material' => array_values( $material ),
            'metrics' => $metrics,
            'max_custom_css_ratio' => $max_ratio,
            'counts' => array(
                'elements' => count( self::flatten( $elements ) ),
                'issues' => count( $issues ),
                'material' => count( $material ),
            ),
        );
    }

    public static function material( array $issues ) {
        return array_values( array_filter( $issues, static function ( $issue ) {
            return in_array( (string) ( $issue['code'] ?? '' ), self::MATERIAL_CODES, true );
        } ) );
    }

    public static function max_custom_css_ratio() {
        if ( class_exists( 'Design_Core_Elementor_Settings' ) && method_exists( 'Design_Core_Elementor_Settings', 'get_qa_thresholds' ) ) {
            $thresholds = Design_Core_Elementor_Settings::get_qa_thresholds();
            if ( isset( $thresholds['max_custom_css_ratio'] ) ) {
                $ratio = (float) $thresholds['max_custom_css_ratio'];
                if ( $ratio < 0 ) { $ratio = 0; }
                if ( $ratio > 1 ) { $ratio = 1; }
                return $ratio;
            }
        }
        return 0.0;
    }

    public static function tree_metrics( $elements ) {
        $flat = self::flatten( $elements );
        $css_elements = 0;
        $css_declarations = 0;
        $responsive_overrides = 0;
        $widgets = array();
        $max_depth = 0;
        $walk = static function ( $list, $depth ) use ( &$walk, &$css_elements, &$css_declarations, &$responsive_overrides, &$widgets, &$max_depth ) {
            foreach ( (array) $list as $element ) {
                if ( ! is_array( $element ) ) { continue; }
                if ( $depth > $max_depth ) { $max_depth = $depth; }
                $settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
                if ( isset( $settings['custom_css'] ) && '' !== trim( (string) $settings['custom_css'] ) ) {
                    $css_elements++;
                    $css_declarations += substr_count( (string) $settings['custom_css'], ';' );
                }
                foreach ( array_keys( $settings ) as $key ) {
                    if ( preg_match( '/_(widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/', (string) $key ) ) { $responsive_overrides++; }
                }
                if ( 'widget' === ( $element['elType'] ?? '' ) ) {
                    $w = (string) ( $element['widgetType'] ?? 'unknown' );
                    $widgets[ $w ] = ( $widgets[ $w ] ?? 0 ) + 1;
                }
                if ( ! empty( $element['elements'] ) ) { $walk( $element['elements'], $depth + 1 ); }
            }
        };
        $walk( $elements, 0 );
        $total = count( $flat );
        return array(
            'elements' => $total,
            'max_depth' => $max_depth,
            'widgets' => $widgets,
            'custom_css_elements' => $css_elements,
            'custom_css_declarations' => $css_declarations,
            'custom_css_ratio' => $total > 0 ? round( $css_elements / $total, 4 ) : 0,
            'responsive_override_count' => $responsive_overrides,
        );
    }

    private static function flatten( $elements ) {
        $flat = array();
        $walk = static function ( $list ) use ( &$walk, &$flat ) {
            foreach ( (array) $list as $element ) {
                if ( ! is_array( $element ) ) { continue; }
                $el_type = strtolower( (string) ( $element['elType'] ?? '' ) );
                $flat[] = array(
                    'element_id' => (string) ( $element['id'] ?? '' ),
                    'element_type' => in_array( $el_type, array( 'widget', 'container', 'section', 'column' ), true ) ? $el_type : 'container',
                    'widget' => 'widget' === $el_type ? (string) ( $element['widgetType'] ?? '' ) : '',
                    'settings' => is_array( $element['settings'] ?? null ) ? $element['settings'] : array(),
                );
                if ( ! empty( $element['elements'] ) ) { $walk( $element['elements'] ); }
            }
        };
        $walk( $elements );
        return $flat;
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Centralized Elementor control-binding governance.
 *
 * Every setting the engine persists must survive the same rules the strict
 * structural validator enforces: the control must exist, must be active for
 * the effective settings (conditions), must accept the value shape and unit,
 * and rich-text settings must not smuggle layout markup. Anything that fails
 * here is reported as unsupported/skipped -- never persisted, never silently
 * converted into custom CSS.
 *
 * Widgets whose content contract requires populated data controls
 * (menu_items, form_fields, tabs) can never be bound generically; only their
 * dedicated semantic binders may emit them, and only with real data.
 */
class Design_Core_Elementor_Binding_Governor {
    /** Widget => required data control that generic binding cannot populate. */
    const DATA_CONTROLS = array(
        'mega-menu' => 'menu_items',
        'form' => 'form_fields',
        'tabs' => 'tabs',
        'nested-tabs' => 'tabs',
        'nested-accordion' => 'tabs',
        'price-table' => 'price_table',
    );

    /** Declarations with zero visual effect inside Elementor; never emit. */
    const TRIVIAL_CSS_PROPERTIES = array( 'box-sizing', 'content' );

    /**
     * Declarations with no valid Elementor control on the mapping path.
     * Emitting them as custom CSS would fail strict validation, while mapping
     * them onto all-side controls would misrepresent the design -- so the
     * mapper drops them and reports the loss instead of guessing.
     */
    const UNREPRESENTABLE_CSS_PROPERTIES = array(
        'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-block', 'border-block-start', 'border-block-end',
        'border-inline', 'border-inline-start', 'border-inline-end',
        'border-collapse', 'table-layout',
        'top', 'right', 'bottom', 'left',
        'outline', 'outline-offset', 'outline-width', 'outline-style', 'outline-color',
        'white-space', 'font',
    );

    /**
     * @return string Empty when generic binding may proceed, otherwise the
     *   rejection reason naming the unbindable data control.
     */
    public static function generic_binding_rejection( $widget_type ) {
        $widget_type = sanitize_key( (string) $widget_type );
        if ( isset( self::DATA_CONTROLS[ $widget_type ] ) ) {
            return 'generic-binding-cannot-populate:' . self::DATA_CONTROLS[ $widget_type ];
        }
        return '';
    }

    /**
     * Mirror of the strict validator's activity check: a control with an
     * unmet condition/conditions must not be persisted, even when the value
     * itself looks valid. Returns true (active), false (inactive) or null
     * (cannot be evaluated safely -- caller must treat as inactive).
     */
    public static function control_active( array $definition, array $effective ) {
        foreach ( (array) ( $definition['condition'] ?? array() ) as $key => $expected ) {
            $negate = is_string( $key ) && str_ends_with( $key, '!' );
            $key = $negate && is_string( $key ) ? substr( $key, 0, -1 ) : $key;
            $actual = $effective[ $key ] ?? null;
            if ( is_string( $key ) && preg_match( '/^([^\[]+)\[([^\]]+)\]$/', $key, $match ) ) {
                $actual = isset( $effective[ $match[1] ][ $match[2] ] ) ? $effective[ $match[1] ][ $match[2] ] : null;
            }
            $matched = is_array( $expected ) ? in_array( $actual, $expected, true ) : $actual === $expected;
            if ( $negate ? $matched : ! $matched ) { return false; }
        }
        if ( ! empty( $definition['conditions'] ) ) { return self::terms( (array) $definition['conditions'], $effective ); }
        return true;
    }

    private static function terms( array $group, array $effective, $depth = 0 ) {
        if ( $depth > 12 || ! isset( $group['terms'] ) || ! is_array( $group['terms'] ) ) { return null; }
        $relation = strtolower( (string) ( $group['relation'] ?? 'and' ) );
        if ( ! in_array( $relation, array( 'and', 'or' ), true ) ) { return null; }
        $results = array();
        foreach ( $group['terms'] as $term ) {
            if ( ! is_array( $term ) ) { return null; }
            if ( isset( $term['terms'] ) ) { $result = self::terms( $term, $effective, $depth + 1 ); }
            else {
                if ( ! isset( $term['name'], $term['operator'] ) || ! array_key_exists( 'value', $term ) ) { return null; }
                $a = $effective[ $term['name'] ] ?? null;
                $b = $term['value'];
                switch ( $term['operator'] ) {
                    case '==': case '===': $result = $a === $b; break;
                    case '!=': case '!==': $result = $a !== $b; break;
                    case 'in': $result = is_array( $b ) && in_array( $a, $b, true ); break;
                    case '!in': $result = is_array( $b ) && ! in_array( $a, $b, true ); break;
                    default: return null;
                }
            }
            if ( null === $result ) { return null; }
            $results[] = $result;
        }
        return 'or' === $relation ? in_array( true, $results, true ) : ! in_array( false, $results, true );
    }

    /**
     * @return array Allowed size units for slider/dimensions/gaps controls.
     * Mirrors the strict validator: explicit size_units win, otherwise the
     * control's declared default unit, otherwise px.
     */
    public static function allowed_units( array $definition ) {
        $units = array_map( 'strval', (array) ( $definition['size_units'] ?? array() ) );
        if ( ! $units && isset( $definition['default']['unit'] ) ) {
            $units = array( (string) $definition['default']['unit'] );
        }
        if ( ! $units ) { $units = array( 'px' ); }
        return array_values( array_unique( $units ) );
    }

    public static function is_trivial_css( $property ) {
        return in_array( strtolower( trim( (string) $property ) ), self::TRIVIAL_CSS_PROPERTIES, true );
    }

    /**
     * Final pass over about-to-persist settings. Drops anything the live
     * runtime schema would reject (unknown/ui-only/inactive-condition/bad
     * unit) and sanitizes rich-text layout markup. Returns the kept settings;
     * every decision lands in $diagnostics (unsupported/skipped/sanitized).
     * Mirrors the strict structural validator so planning-time output and
     * audit-time verdicts cannot disagree.
     */
    public static function govern_settings( $element_type, $widget_type, array $settings, $registry, array &$diagnostics = array() ) {
        $diagnostics = array( 'unsupported' => array(), 'skipped_conditional' => array(), 'sanitized_richtext' => array() );
        if ( ! is_object( $registry ) || ! method_exists( $registry, 'schema' ) ) { return $settings; }
        $element_type = sanitize_key( (string) $element_type );
        $widget_type = sanitize_key( (string) $widget_type );
        $schema = $registry->schema( $element_type, $widget_type );
        $controls = is_array( $schema['controls'] ?? null ) ? $schema['controls'] : array();
        if ( ! $controls ) { return $settings; }
        $definitions = array();
        foreach ( $controls as $key => $definition ) {
            if ( is_array( $definition ) ) { $definitions[ (string) ( $definition['name'] ?? $key ) ] = $definition; }
        }
        $effective = array();
        foreach ( $definitions as $name => $definition ) {
            if ( array_key_exists( 'default', $definition ) ) { $effective[ $name ] = $definition['default']; }
        }
        $effective = array_replace( $effective, $settings );
        $kept = array();
        foreach ( $settings as $name => $value ) {
            $lookup = (string) $name;
            $base_name = $lookup;
            if ( preg_match( '/^(.+)_(widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/', $lookup, $device ) ) {
                $base_name = $device[1];
                $base = $definitions[ $base_name ] ?? null;
                if ( ! is_array( $base ) || ( empty( $base['responsive'] ) && empty( $base['is_responsive'] ) ) ) {
                    $diagnostics['unsupported'][] = $lookup . ':not-responsive';
                    continue;
                }
            }
            $definition = $definitions[ $lookup ] ?? ( $definitions[ $base_name ] ?? null );
            if ( ! is_array( $definition ) ) { $diagnostics['unsupported'][] = $lookup . ':unknown-control'; continue; }
            if ( self::ui_only( (string) ( $definition['type'] ?? '' ) ) ) { $diagnostics['unsupported'][] = $lookup . ':ui-control'; continue; }
            if ( true !== self::control_active( $definition, $effective ) ) { $diagnostics['skipped_conditional'][] = $lookup; continue; }
            $type = (string) ( $definition['type'] ?? '' );
            if ( in_array( $type, array( 'text', 'textarea', 'wysiwyg' ), true ) && is_string( $value ) ) {
                list( $clean, $was ) = self::sanitize_richtext( $value );
                if ( $was ) { $diagnostics['sanitized_richtext'][] = $lookup; $value = $clean; }
            }
            if ( in_array( $type, array( 'slider', 'dimensions', 'gaps' ), true ) && ! self::unit_allowed( $value, $definition ) ) {
                $diagnostics['unsupported'][] = $lookup . ':bad-unit';
                continue;
            }
            $kept[ $name ] = $value;
        }
        $diagnostics['unsupported'] = array_values( array_unique( $diagnostics['unsupported'] ) );
        $diagnostics['skipped_conditional'] = array_values( array_unique( $diagnostics['skipped_conditional'] ) );
        $diagnostics['sanitized_richtext'] = array_values( array_unique( $diagnostics['sanitized_richtext'] ) );
        $normalized = self::normalize_unitless_line_height( $kept, $definitions );
        if ( $normalized ) { $diagnostics['normalized_line_height'] = true; }
        return $kept;
    }

    /**
     * Unitless line-height is not a guess when the element's own font size is
     * known: CSS defines it as ratio times font size. Convert to px so the
     * value survives strict unit validation instead of being dropped. Handles
     * base and responsive-suffixed keys, scalar ratios and ratio markers.
     */
    /**
     * Line-height values arrive unitless (ratios), unit-tagged-empty, or in px
     * while the runtime control may expose only em. Resolve every form to an
     * allowed unit using the element's own px font size -- ratio and px/em
     * conversions there are exact CSS semantics, never guesses. Values that
     * cannot be resolved are left for the unit check to drop with diagnostics.
     */
    private static function normalize_unitless_line_height( array &$settings, array $definitions ) {
        $done = false;
        $allowed = self::allowed_units( is_array( $definitions['typography_line_height'] ?? null ) ? $definitions['typography_line_height'] : array() );
        foreach ( $settings as $key => $value ) {
            if ( ! preg_match( '/^typography_line_height(_(widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile))?$/', (string) $key, $m ) ) { continue; }
            $unit = is_array( $value ) ? strtolower( (string) ( $value['unit'] ?? '' ) ) : '';
            if ( '' !== $unit && in_array( $unit, $allowed, true ) ) { continue; }
            $suffix = $m[1] ?? '';
            $ratio = null;
            $px = null;
            if ( is_scalar( $value ) && is_numeric( $value ) ) { $ratio = (float) $value; }
            elseif ( is_array( $value ) && array_key_exists( 'ratio', $value ) && is_numeric( $value['ratio'] ) ) { $ratio = (float) $value['ratio']; }
            elseif ( is_array( $value ) && array_key_exists( 'value', $value ) && '' === (string) ( $value['unit'] ?? 'px' ) && is_numeric( $value['value'] ) ) {
                $ratio = (float) $value['value'];
            } elseif ( is_array( $value ) && isset( $value['size'] ) && is_numeric( $value['size'] ) && 'px' === strtolower( (string) ( $value['unit'] ?? '' ) ) ) {
                $px = (float) $value['size'];
            }
            if ( ( null === $ratio || $ratio <= 0 || 10 < $ratio ) && null === $px ) { continue; }
            $font_key = 'typography_font_size' . $suffix;
            $font_size = $settings[ $font_key ] ?? ( '' === $suffix ? null : ( $settings['typography_font_size'] ?? null ) );
            if ( ! is_array( $font_size ) || ! isset( $font_size['size'] ) || ! is_numeric( $font_size['size'] ) ) { continue; }
            if ( 'px' !== strtolower( (string) ( $font_size['unit'] ?? '' ) ) ) { continue; }
            $fs = (float) $font_size['size'];
            if ( $fs <= 0 ) { continue; }
            if ( in_array( 'px', $allowed, true ) ) {
                $settings[ $key ] = array( 'size' => round( ( null === $ratio ? $px : $ratio * $fs ), 2 ), 'unit' => 'px' );
            } elseif ( in_array( 'em', $allowed, true ) ) {
                $em = null === $ratio ? $px / $fs : $ratio;
                if ( $em <= 0 || 10 < $em ) { continue; }
                $settings[ $key ] = array( 'size' => round( $em, 3 ), 'unit' => 'em' );
            } else { continue; }
            $done = true;
        }
        return $done;
    }
    private static function ui_only( $type ) {
        // Mirror the strict validator exactly: repeater is DATA (rows are
        // validated individually), never editor UI. Diverging here would drop
        // menu items, tabs, form fields and table rows that validation keeps.
        if ( class_exists( 'Design_Core_Agent_Knowledge' ) && method_exists( 'Design_Core_Agent_Knowledge', 'ui_only' ) ) {
            return Design_Core_Agent_Knowledge::ui_only( $type );
        }
        return in_array( sanitize_key( (string) $type ), array( 'section', 'tab', 'tabs', 'divider', 'heading', 'raw_html', 'notice', 'deprecated_notice', 'alert', 'button' ), true );
    }

    public static function unit_allowed( $value, array $definition ) {
        if ( ! is_array( $value ) || ! isset( $value['unit'] ) ) { return true; }
        return in_array( (string) $value['unit'], self::allowed_units( $definition ), true );
    }

    public static function is_unrepresentable_css( $property ) {
        return in_array( strtolower( trim( (string) $property ) ), self::UNREPRESENTABLE_CSS_PROPERTIES, true );
    }

    /**
     * Strip structural layout markup from rich-text bindings. Text survives;
     * layout ownership stays with native elements. Returns [text, sanitized].
     */
    public static function sanitize_richtext( $html ) {
        $html = (string) $html;
        if ( ! preg_match( '/<(?:div|section|article|nav|table|header|footer|main)\b|\b(?:style|class)\s*=/i', $html ) ) {
            return array( $html, false );
        }
        $text = trim( wp_strip_all_tags( $html ) );
        return array( $text, true );
    }
}

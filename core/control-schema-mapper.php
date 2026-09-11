<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Converts the live Elementor control contract into a compact machine-readable
 * JSON Schema. Runtime controls remain authoritative; this mapper only describes
 * values that an agent/compiler may safely reason about.
 */
class Design_Core_Elementor_Control_Schema_Mapper {
    const VERSION = 1;

    private static $structural_types = array(
        'section', 'tab', 'tabs', 'divider', 'heading', 'raw_html', 'notice',
        'deprecated_notice', 'alert', 'button',
    );

    public static function map_controls( array $controls ) {
        $properties = array();
        foreach ( $controls as $control_id => $control ) {
            if ( ! is_string( $control_id ) || ! is_array( $control ) ) { continue; }
            $mapped = self::map_control( $control );
            if ( empty( $mapped ) ) { continue; }
            $properties[ $control_id ] = $mapped;
        }

        return array(
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            // Elementor/add-ons can add controls dynamically. The generated schema is
            // discovery guidance, not a replacement for the live runtime authority.
            'additionalProperties' => true,
            'properties' => $properties,
            'x-design-core' => array(
                'mapper_version' => self::VERSION,
                'source' => 'elementor-runtime',
            ),
        );
    }

    public static function map_control( array $control ) {
        $type = self::key( $control['type'] ?? '' );
        if ( in_array( $type, self::$structural_types, true ) ) { return array(); }

        switch ( $type ) {
            case 'text':
            case 'textarea':
            case 'wysiwyg':
            case 'code':
            case 'font':
            case 'animation':
            case 'hover_animation':
            case 'exit_animation':
            case 'hidden':
            case 'date_time':
                $schema = array( 'type' => 'string' );
                break;

            case 'number':
                $schema = array( 'type' => 'number' );
                break;

            case 'select':
            case 'select2':
                $schema = self::map_select( $control );
                break;

            case 'choose':
            case 'visual_choice':
                $schema = self::map_choice( $control );
                break;

            case 'switcher':
            case 'popover_toggle':
                $schema = array( 'type' => 'string', 'enum' => array( 'yes', '' ) );
                break;

            case 'color':
                $schema = array( 'type' => 'string', 'description' => 'CSS color value such as hex, rgb(a), hsl(a), or a supported Elementor token.' );
                break;

            case 'slider':
                $schema = array(
                    'type' => 'object',
                    'properties' => array(
                        'size' => array( 'type' => array( 'number', 'string' ) ),
                        'unit' => array( 'type' => 'string' ),
                    ),
                );
                break;

            case 'dimensions':
                $schema = array(
                    'type' => 'object',
                    'properties' => array(
                        'top' => array( 'type' => array( 'string', 'number' ) ),
                        'right' => array( 'type' => array( 'string', 'number' ) ),
                        'bottom' => array( 'type' => array( 'string', 'number' ) ),
                        'left' => array( 'type' => array( 'string', 'number' ) ),
                        'unit' => array( 'type' => 'string' ),
                        'isLinked' => array( 'type' => 'boolean' ),
                    ),
                );
                break;

            case 'gaps':
                $schema = array(
                    'type' => 'object',
                    'properties' => array(
                        'column' => array( 'type' => array( 'string', 'number' ) ),
                        'row' => array( 'type' => array( 'string', 'number' ) ),
                        'unit' => array( 'type' => 'string' ),
                        'isLinked' => array( 'type' => 'boolean' ),
                    ),
                );
                break;

            case 'url':
                $schema = array(
                    'type' => 'object',
                    'properties' => array(
                        'url' => array( 'type' => 'string' ),
                        'is_external' => array( 'type' => 'boolean' ),
                        'nofollow' => array( 'type' => 'boolean' ),
                        'custom_attributes' => array( 'type' => 'string' ),
                    ),
                );
                break;

            case 'media':
                $schema = array(
                    'type' => 'object',
                    'properties' => array(
                        'id' => array( 'type' => 'integer' ),
                        'url' => array( 'type' => 'string' ),
                    ),
                );
                break;

            case 'gallery':
                $schema = array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'id' => array( 'type' => 'integer' ),
                            'url' => array( 'type' => 'string' ),
                        ),
                    ),
                );
                break;

            case 'icons':
            case 'icon':
                $schema = array(
                    'type' => 'object',
                    'properties' => array(
                        'value' => array(),
                        'library' => array( 'type' => 'string' ),
                    ),
                );
                break;

            case 'image_dimensions':
                $schema = array(
                    'type' => 'object',
                    'properties' => array(
                        'width' => array( 'type' => 'integer' ),
                        'height' => array( 'type' => 'integer' ),
                    ),
                );
                break;

            case 'box_shadow':
                $schema = array(
                    'type' => 'object',
                    'properties' => array(
                        'horizontal' => array( 'type' => array( 'integer', 'number' ) ),
                        'vertical' => array( 'type' => array( 'integer', 'number' ) ),
                        'blur' => array( 'type' => array( 'integer', 'number' ) ),
                        'spread' => array( 'type' => array( 'integer', 'number' ) ),
                        'color' => array( 'type' => 'string' ),
                    ),
                );
                break;

            case 'text_shadow':
                $schema = array(
                    'type' => 'object',
                    'properties' => array(
                        'horizontal' => array( 'type' => array( 'integer', 'number' ) ),
                        'vertical' => array( 'type' => array( 'integer', 'number' ) ),
                        'blur' => array( 'type' => array( 'integer', 'number' ) ),
                        'color' => array( 'type' => 'string' ),
                    ),
                );
                break;

            case 'repeater':
                $schema = self::map_repeater( $control );
                break;

            default:
                // Third-party Elementor add-ons frequently introduce custom control
                // types. Preserve them as permissive values instead of pretending a
                // private value format is known.
                $schema = array( 'type' => array( 'string', 'number', 'integer', 'boolean', 'object', 'array', 'null' ) );
                break;
        }

        $label = isset( $control['label'] ) && is_scalar( $control['label'] ) ? trim( (string) $control['label'] ) : '';
        if ( '' !== $label ) { $schema['title'] = $label; }

        if ( array_key_exists( 'default', $control ) ) {
            $default = self::normalize_value( $control['default'] );
            if ( null !== $default || null === $control['default'] ) { $schema['default'] = $default; }
        }

        $schema['x-elementor'] = array_filter(
            array(
                'control_type' => $type,
                'responsive' => self::is_responsive( $control ),
                'tab' => isset( $control['tab'] ) && is_scalar( $control['tab'] ) ? (string) $control['tab'] : '',
                'section' => isset( $control['section'] ) && is_scalar( $control['section'] ) ? (string) $control['section'] : '',
            ),
            static function ( $value ) { return false !== $value && '' !== $value && null !== $value; }
        );

        return $schema;
    }

    private static function map_select( array $control ) {
        $enum = self::option_keys( $control );
        $multiple = ! empty( $control['multiple'] );
        if ( $multiple ) {
            $items = array( 'type' => 'string' );
            if ( $enum ) { $items['enum'] = $enum; }
            return array( 'type' => 'array', 'items' => $items );
        }
        $schema = array( 'type' => 'string' );
        if ( $enum ) { $schema['enum'] = $enum; }
        return $schema;
    }

    private static function map_choice( array $control ) {
        $schema = array( 'type' => 'string' );
        $enum = self::option_keys( $control );
        if ( $enum ) { $schema['enum'] = $enum; }
        return $schema;
    }

    private static function map_repeater( array $control ) {
        $properties = array();
        foreach ( (array) ( $control['fields'] ?? array() ) as $field_key => $field ) {
            if ( ! is_array( $field ) ) { continue; }
            $name = isset( $field['name'] ) && is_scalar( $field['name'] ) ? (string) $field['name'] : ( is_string( $field_key ) ? $field_key : '' );
            if ( '' === $name ) { continue; }
            $mapped = self::map_control( $field );
            if ( $mapped ) { $properties[ $name ] = $mapped; }
        }
        return array(
            'type' => 'array',
            'items' => array(
                'type' => 'object',
                'additionalProperties' => true,
                'properties' => $properties,
            ),
        );
    }

    private static function option_keys( array $control ) {
        if ( empty( $control['options'] ) || ! is_array( $control['options'] ) ) { return array(); }
        $keys = array();
        foreach ( array_keys( $control['options'] ) as $key ) {
            if ( is_scalar( $key ) && '' !== (string) $key ) { $keys[] = (string) $key; }
        }
        return array_values( array_unique( $keys ) );
    }

    private static function is_responsive( array $control ) {
        return ! empty( $control['responsive'] ) || ! empty( $control['is_responsive'] );
    }

    private static function normalize_value( $value, $depth = 0 ) {
        if ( $depth > 5 ) { return '[max-depth]'; }
        if ( null === $value || is_scalar( $value ) ) { return $value; }
        if ( is_object( $value ) || is_resource( $value ) ) { return null; }
        if ( ! is_array( $value ) ) { return null; }
        $normalized = array();
        foreach ( $value as $key => $item ) {
            $normalized[ $key ] = self::normalize_value( $item, $depth + 1 );
        }
        return $normalized;
    }

    private static function key( $value ) {
        return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
    }
}

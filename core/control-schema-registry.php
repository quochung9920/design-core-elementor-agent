<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Discovers the real control schema exposed by the active Elementor runtime.
 *
 * Schema v2 is the single runtime authority for Inspector, control mapping,
 * native-widget capability checks and agent-facing machine schemas. It merges
 * Elementor's normal/style/common stacks, supplements them with the public
 * resolved controls when available, and never invents private control names.
 */
class Design_Core_Elementor_Control_Schema_Registry {
    const SCHEMA_VERSION = 2;

    private $provider;
    private $cache = array();
    private $machine_cache = array();

    /**
     * Process-wide snapshot. Elementor mutates live widget control
     * definitions during document save (observed: typography slider defaults
     * flipping px/em), so two reads of the same schema in one request can
     * disagree -- and bind-time verdicts would then contradict verify-time
     * verdicts on identical values. First complete read wins for the whole
     * process: every consumer in a request (binder, executor gate, post-write
     * verification) judges against the same contract.
     */
    private static $process_cache = array();

    public function __construct( $provider = null ) {
        $this->provider = is_callable( $provider ) ? $provider : array( $this, 'runtime_controls' );
    }

    private function is_default_provider() {
        return is_array( $this->provider ) && isset( $this->provider[0], $this->provider[1] ) && $this->provider[0] === $this && 'runtime_controls' === $this->provider[1];
    }

    public function schema( $element_type, $widget_type = '', $include_machine_schema = false ) {
        $element_type = sanitize_key( (string) $element_type );
        $widget_type = sanitize_key( (string) $widget_type );
        $key = $element_type . ':' . $widget_type;
        $use_process_cache = $this->is_default_provider();

        if ( $use_process_cache && isset( self::$process_cache[ $key ] ) ) {
            $this->cache[ $key ] = self::$process_cache[ $key ];
        } elseif ( ! $use_process_cache && isset( $this->cache[ $key ] ) ) {
            // Custom provider (tests, bounded stubs): instance cache only.
        } else {
            $stacks = call_user_func( $this->provider, $element_type, $widget_type );
            if ( is_wp_error( $stacks ) || ! is_array( $stacks ) ) { $stacks = array(); }
            $controls = array_merge(
                is_array( $stacks['controls'] ?? null ) ? $stacks['controls'] : array(),
                is_array( $stacks['style_controls'] ?? null ) ? $stacks['style_controls'] : array(),
                is_array( $stacks['common_controls'] ?? null ) ? $stacks['common_controls'] : array(),
                is_array( $stacks['common_style_controls'] ?? null ) ? $stacks['common_style_controls'] : array(),
                is_array( $stacks['resolved_controls'] ?? null ) ? $stacks['resolved_controls'] : array()
            );

            $normalized = array();
            foreach ( $controls as $id => $control ) {
                if ( ! is_string( $id ) || ! is_array( $control ) ) { continue; }
                $normalized[ $id ] = $control;
                $normalized[ $id ]['type'] = sanitize_key( (string) ( $control['type'] ?? '' ) );
                $normalized[ $id ]['responsive'] = $this->control_is_responsive( $id, $control, $controls );
            }

            $statistics = $this->statistics( $normalized );
            $capabilities = $this->capabilities( $normalized );
            $fingerprint_input = array(
                'schema_version' => self::SCHEMA_VERSION,
                'controls' => $this->fingerprint_contract( $normalized ),
            );

            $this->cache[ $key ] = array(
                'schema_version' => self::SCHEMA_VERSION,
                'element_type' => $element_type,
                'widget_type' => $widget_type,
                'controls' => $normalized,
                'statistics' => $statistics,
                'capabilities' => $capabilities,
                'fingerprint' => hash( 'sha256', (string) wp_json_encode( $fingerprint_input ) ),
                'runtime' => array(
                    'elementor' => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '',
                    'elementor_pro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ELEMENTOR_PRO_VERSION : '',
                ),
            );
            if ( $use_process_cache ) { self::$process_cache[ $key ] = $this->cache[ $key ]; }
        }

        $schema = $this->cache[ $key ];
        if ( $include_machine_schema ) {
            $schema['json_schema'] = $this->machine_schema( $element_type, $widget_type );
        }
        return $schema;
    }

    public function machine_schema( $element_type, $widget_type = '' ) {
        $element_type = sanitize_key( (string) $element_type );
        $widget_type = sanitize_key( (string) $widget_type );
        $key = $element_type . ':' . $widget_type;
        if ( isset( $this->machine_cache[ $key ] ) ) { return $this->machine_cache[ $key ]; }
        $schema = $this->schema( $element_type, $widget_type, false );
        if ( ! class_exists( 'Design_Core_Elementor_Control_Schema_Mapper' ) ) {
            return $this->machine_cache[ $key ] = array();
        }
        return $this->machine_cache[ $key ] = Design_Core_Elementor_Control_Schema_Mapper::map_controls( (array) ( $schema['controls'] ?? array() ) );
    }

    public function control( $element_type, $widget_type, $control_id ) {
        $schema = $this->schema( $element_type, $widget_type );
        return $schema['controls'][ $control_id ] ?? null;
    }

    public function has( $element_type, $widget_type, $control_id ) {
        return is_array( $this->control( $element_type, $widget_type, $control_id ) );
    }

    public function is_responsive( $element_type, $widget_type, $control_id ) {
        $control = $this->control( $element_type, $widget_type, $control_id );
        return is_array( $control ) && ! empty( $control['responsive'] );
    }

    public function first_supported( $element_type, $widget_type, array $candidates, $expected_type = '' ) {
        foreach ( $candidates as $candidate ) {
            $control = $this->control( $element_type, $widget_type, $candidate );
            if ( ! is_array( $control ) ) { continue; }
            if ( $expected_type && $expected_type !== ( $control['type'] ?? '' ) ) { continue; }
            return $candidate;
        }
        return '';
    }

    public function available( $element_type, $widget_type = '' ) {
        return ! empty( $this->schema( $element_type, $widget_type )['controls'] );
    }

    /**
     * Runtime provider. get_stack(false) gives the explicit own stack; the common
     * widget stack is merged separately. get_controls() is then read with
     * Elementor's supported style-control toggle when possible so optimized
     * control loading cannot hide a control from the final contract.
     */
    public function runtime_controls( $element_type, $widget_type ) {
        if ( ! class_exists( '\\Elementor\\Plugin' ) ) { return array(); }
        $plugin = \Elementor\Plugin::instance();
        $element = null;
        if ( 'container' === $element_type || 'section' === $element_type || 'column' === $element_type ) {
            $element = ! empty( $plugin->elements_manager ) ? $plugin->elements_manager->get_element_types( $element_type ) : null;
        } elseif ( 'widget' === $element_type && $widget_type ) {
            $element = ! empty( $plugin->widgets_manager ) ? $plugin->widgets_manager->get_widget_types( $widget_type ) : null;
        }
        if ( ! $element || ! method_exists( $element, 'get_stack' ) ) { return array(); }

        try {
            $stack = $this->read_stack( $element );
            $common_controls = array();
            $common_style_controls = array();
            if ( 'widget' === $element_type && ! empty( $plugin->widgets_manager ) ) {
                $common_name = $this->common_widget_name( $element );
                if ( $common_name ) {
                    $common = $plugin->widgets_manager->get_widget_types( $common_name );
                    if ( $common && method_exists( $common, 'get_stack' ) ) {
                        $common_stack = $this->read_stack( $common );
                        $common_controls = is_array( $common_stack['controls'] ?? null ) ? $common_stack['controls'] : array();
                        $common_style_controls = is_array( $common_stack['style_controls'] ?? null ) ? $common_stack['style_controls'] : array();
                    }
                }
            }

            return array(
                'controls' => is_array( $stack['controls'] ?? null ) ? $stack['controls'] : array(),
                'style_controls' => is_array( $stack['style_controls'] ?? null ) ? $stack['style_controls'] : array(),
                'common_controls' => $common_controls,
                'common_style_controls' => $common_style_controls,
                'resolved_controls' => $this->read_public_controls( $element ),
            );
        } catch ( Throwable $exception ) {
            return array();
        }
    }

    private function read_stack( $element ) {
        $method = new ReflectionMethod( $element, 'get_stack' );
        if ( ! $method->isPublic() ) { $method->setAccessible( true ); }
        $stack = $method->getNumberOfParameters() > 0 ? $method->invoke( $element, false ) : $method->invoke( $element );
        return is_array( $stack ) ? $stack : array();
    }

    private function common_widget_name( $element ) {
        if ( ! method_exists( $element, 'get_common_widget_name' ) ) { return ''; }
        try {
            $method = new ReflectionMethod( $element, 'get_common_widget_name' );
            $method->setAccessible( true );
            return sanitize_key( (string) $method->invoke( $element ) );
        } catch ( Throwable $exception ) {
            return '';
        }
    }

    private function read_public_controls( $element ) {
        if ( ! method_exists( $element, 'get_controls' ) ) { return array(); }
        try {
            $method = new ReflectionMethod( $element, 'get_controls' );
            if ( ! $method->isPublic() || $method->getNumberOfRequiredParameters() > 0 ) { return array(); }

            $performance = '\\Elementor\\Core\\Frontend\\Performance';
            $can_toggle = class_exists( $performance )
                && method_exists( $performance, 'set_use_style_controls' )
                && method_exists( $performance, 'is_use_style_controls' );
            $previous = null;
            if ( $can_toggle ) {
                $previous = (bool) $performance::is_use_style_controls();
                $performance::set_use_style_controls( true );
            }
            try {
                $controls = $element->get_controls();
            } finally {
                if ( $can_toggle ) { $performance::set_use_style_controls( $previous ); }
            }
            return is_array( $controls ) ? $controls : array();
        } catch ( Throwable $exception ) {
            return array();
        }
    }

    private function control_is_responsive( $id, array $control, array $controls ) {
        if ( ! empty( $control['responsive'] ) || ! empty( $control['is_responsive'] ) ) { return true; }
        foreach ( array( '_tablet', '_mobile', '_widescreen', '_laptop', '_tablet_extra', '_mobile_extra' ) as $suffix ) {
            if ( isset( $controls[ $id . $suffix ] ) ) { return true; }
        }
        return false;
    }

    private function statistics( array $controls ) {
        $flat = $this->flatten_for_analysis( $controls );
        $type_counts = array();
        $responsive = 0;
        foreach ( $flat as $row ) {
            $control = (array) ( $row['definition'] ?? array() );
            $type = sanitize_key( (string) ( $control['type'] ?? '' ) );
            if ( $type ) { $type_counts[ $type ] = ( $type_counts[ $type ] ?? 0 ) + 1; }
            if ( ! empty( $control['responsive'] ) || ! empty( $control['is_responsive'] ) ) { $responsive++; }
        }
        ksort( $type_counts );
        return array(
            'top_level' => count( $controls ),
            'flattened' => count( $flat ),
            'responsive' => $responsive,
            'type_counts' => $type_counts,
        );
    }

    private function capabilities( array $controls ) {
        $counts = array(
            'responsive' => 0, 'repeater' => 0, 'media' => 0, 'link' => 0, 'icon' => 0,
            'typography' => 0, 'color' => 0, 'background' => 0, 'border' => 0,
            'spacing' => 0, 'hover' => 0, 'animation' => 0, 'dynamic' => 0,
        );
        foreach ( $this->flatten_for_analysis( $controls ) as $row ) {
            $id = strtolower( (string) ( $row['path'] ?? '' ) );
            $control = (array) ( $row['definition'] ?? array() );
            $type = sanitize_key( (string) ( $control['type'] ?? '' ) );
            if ( ! empty( $control['responsive'] ) || ! empty( $control['is_responsive'] ) ) { $counts['responsive']++; }
            if ( 'repeater' === $type ) { $counts['repeater']++; }
            if ( in_array( $type, array( 'media', 'gallery' ), true ) || preg_match( '/(?:image|video|media|gallery)/', $id ) ) { $counts['media']++; }
            if ( 'url' === $type || preg_match( '/(?:^|\.)(?:link|url)(?:_|\.|$)/', $id ) ) { $counts['link']++; }
            if ( in_array( $type, array( 'icon', 'icons' ), true ) || false !== strpos( $id, 'icon' ) ) { $counts['icon']++; }
            if ( 'font' === $type || false !== strpos( $id, 'typography' ) || false !== strpos( $id, 'font_' ) ) { $counts['typography']++; }
            if ( 'color' === $type || false !== strpos( $id, 'color' ) ) { $counts['color']++; }
            if ( false !== strpos( $id, 'background' ) ) { $counts['background']++; }
            if ( false !== strpos( $id, 'border' ) || in_array( $type, array( 'box_shadow', 'text_shadow' ), true ) ) { $counts['border']++; }
            if ( in_array( $type, array( 'dimensions', 'gaps' ), true ) || preg_match( '/(?:margin|padding|gap|spacing)/', $id ) ) { $counts['spacing']++; }
            if ( false !== strpos( $id, 'hover' ) ) { $counts['hover']++; }
            if ( false !== strpos( $type, 'animation' ) || false !== strpos( $id, 'animation' ) || false !== strpos( $id, 'motion_fx' ) ) { $counts['animation']++; }
            if ( ! empty( $control['dynamic'] ) || false !== strpos( $id, 'dynamic' ) ) { $counts['dynamic']++; }
        }
        $supports = array();
        foreach ( $counts as $capability => $count ) { if ( $count > 0 ) { $supports[] = $capability; } }
        return array( 'supports' => $supports, 'counts' => $counts );
    }

    private function flatten_for_analysis( array $controls, $parent = '', $depth = 0 ) {
        $rows = array();
        foreach ( $controls as $id => $control ) {
            if ( ! is_array( $control ) ) { continue; }
            $name = (string) ( $control['name'] ?? $id );
            $path = '' === $parent ? $name : $parent . '.' . $name;
            $rows[] = array( 'path' => $path, 'definition' => $control );
            if ( $depth < 8 && ! empty( $control['fields'] ) && is_array( $control['fields'] ) ) {
                $rows = array_merge( $rows, $this->flatten_for_analysis( $control['fields'], $path, $depth + 1 ) );
            }
        }
        return $rows;
    }

    private function fingerprint_contract( array $controls ) {
        $contract = array();
        foreach ( $controls as $id => $control ) {
            if ( ! is_array( $control ) ) { continue; }
            $entry = array(
                'type' => sanitize_key( (string) ( $control['type'] ?? '' ) ),
                'responsive' => ! empty( $control['responsive'] ) || ! empty( $control['is_responsive'] ),
            );
            if ( array_key_exists( 'default', $control ) ) { $entry['default'] = $this->fingerprint_value( $control['default'] ); }
            if ( ! empty( $control['options'] ) && is_array( $control['options'] ) ) { $entry['options'] = array_map( 'strval', array_keys( $control['options'] ) ); }
            if ( ! empty( $control['fields'] ) && is_array( $control['fields'] ) ) { $entry['fields'] = $this->fingerprint_contract( $control['fields'] ); }
            $contract[ $id ] = $entry;
        }
        ksort( $contract );
        return $contract;
    }

    private function fingerprint_value( $value, $depth = 0 ) {
        if ( $depth > 4 ) { return '[max-depth]'; }
        if ( null === $value || is_scalar( $value ) ) { return $value; }
        if ( is_object( $value ) ) { return '[object:' . get_class( $value ) . ']'; }
        if ( is_resource( $value ) ) { return '[resource]'; }
        if ( ! is_array( $value ) ) { return '[' . gettype( $value ) . ']'; }
        $out = array();
        foreach ( $value as $key => $item ) { $out[ $key ] = $this->fingerprint_value( $item, $depth + 1 ); }
        return $out;
    }
}

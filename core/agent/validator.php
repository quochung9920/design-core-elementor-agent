<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Strict native settings validator. Unknown semantics are rejected, never guessed. */
final class Design_Core_Agent_Validator {
    private $knowledge;
    public function __construct( $knowledge ) { $this->knowledge = $knowledge; }
    public function validate( array $input ) {
        $schema = $this->knowledge->schema( $input ); if ( is_wp_error( $schema ) ) { return $schema; }
        if ( ! empty( $input['schema_fingerprint'] ) && ! hash_equals( $schema['fingerprint'], $input['schema_fingerprint'] ) ) {
            return Design_Core_Agent_Contract::error( 'schema_stale', 'Runtime control schema changed.', 409 );
        }
        $settings = (array) ( $input['settings'] ?? array() ); $issues = array();
        $this->settings( $settings, $schema['controls'], '', $issues );
        return array( 'status' => $issues ? 'invalid' : 'valid', 'valid' => ! $issues,
            'schema_fingerprint' => $schema['fingerprint'], 'issues' => $issues,
            'settings_hash' => Design_Core_Agent_Contract::hash( $settings ),
            'render_verified' => false, 'interaction_verified' => false );
    }
    private function issue( &$issues, $path, $code, $message ) {
        $issues[] = array( 'path' => $path, 'code' => $code, 'message' => $message );
    }

    /**
     * Closed, documented value domains for stable controls whose option lists
     * are exposed dynamically (callbacks) and therefore never reach the
     * schema snapshot. Elementor control domains and CSS-spec values -- not
     * guesses. Returns null when no documented domain exists, in which case
     * the caller keeps reporting dynamic_options.
     */
    private static function documented_options_domain( $control_name ) {
        static $domains = null;
        if ( null === $domains ) {
            $weights = array( '', 'normal', 'bold', 'bolder', 'lighter', '100', '200', '300', '400', '500', '600', '700', '800', '900' );
            $domains = array(
                'background_background' => array( '', 'classic', 'gradient', 'video', 'slideshow' ),
                'html_tag' => array( '', 'article', 'aside', 'div', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'main', 'nav', 'section', 'span', 'p', 'ul', 'ol', 'li', 'a', 'button' ),
                'position' => array( '', 'default', 'static', 'relative', 'absolute', 'fixed', 'sticky', 'custom' ),
                'content_width' => array( '', 'boxed', 'full' ),
                'container_type' => array( '', 'flex', 'grid' ),
                'layout' => array( '', 'vertical', 'grid' ),
                'typography_font_weight' => $weights,
                'header_size' => array( '', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ),
                '_element_width' => array( '', 'default', 'initial', 'custom' ),
                'flex_justify_content' => array( '', 'flex-start', 'flex-end', 'center', 'space-between', 'space-around', 'space-evenly', 'stretch' ),
                'flex_align_items' => array( '', 'stretch', 'flex-start', 'flex-end', 'center', 'baseline' ),
                'field_type' => array( '', 'text', 'email', 'textarea', 'url', 'tel', 'number', 'date', 'time' ),
                'align' => array( '', 'left', 'center', 'right', 'justify', 'stretch' ),
                'typography_text_decoration' => array( '', 'none', 'underline', 'overline', 'line-through' ),
                'column_count' => array( '', '2', '3', '4', '5', '6' ),
                'width' => array( '', '20', '25', '30', '33', '40', '50', '60', '66', '70', '75', '80', '100' ),
                'input_size' => array( '', 'xs', 'sm', 'md', 'lg', 'xl' ),
            );
        }
        return $domains[ (string) $control_name ] ?? null;
    }
    private function settings( array $values, array $controls, $parent, array &$issues, $depth = 0 ) {
        if ( $depth > 24 ) { $this->issue( $issues, $parent, 'depth', 'Settings exceed validation depth.' ); return; }
        // Repeater definitions may use integer keys; normalize by runtime name.
        $definitions = array();
        foreach ( $controls as $key => $definition ) { if ( is_array( $definition ) ) { $definitions[ $definition['name'] ?? $key ] = $definition; } }
        $effective = array();
        foreach ( $definitions as $name => $definition ) { if ( array_key_exists( 'default', $definition ) ) { $effective[ $name ] = $definition['default']; } }
        $effective = array_replace( $effective, $values );
        foreach ( $values as $name => $value ) {
            $path = $parent . '/' . Design_Core_Agent_Contract::escape( (string) $name );
            // Runtime controls are authoritative: custom_css is a real persisted
            // Elementor control when the live schema offers it (e.g. Pro is
            // active). Only reject it when the runtime has no such control.
            if ( 'custom_css' === $name && ! isset( $definitions['custom_css'] ) ) {
                $this->issue( $issues, $path, 'restricted', 'The live runtime schema has no custom_css control; strict native plans do not accept this field.' ); continue;
            }
            if ( in_array( $name, array( '__globals__', '__dynamic__', 'custom_attributes' ), true ) ) {
                $this->issue( $issues, $path, 'restricted', 'Requires a dedicated verified binding adapter; strict native plans do not accept this field.' ); continue;
            }
            if ( Design_Core_Agent_Contract::sensitive( (string) $name ) ) { $this->issue( $issues, $path, 'restricted', 'Credential-like fields cannot be authored through a design plan.' ); continue; }
            // Any name matching a device suffix is breakpoint-gated first, whether or not it
            // also happens to be a literally registered control -- an inactive device is
            // meaningless in this runtime regardless of how the control got into the schema.
            // A device-suffixed name (e.g. padding_mobile) is normally never registered as its
            // own control in the live Elementor schema -- responsive values are stored as
            // sibling settings keys next to the base control (padding), which alone carries
            // responsive/is_responsive. So when the exact name is not itself a real control,
            // fall back to its base: only when the base control genuinely exists and is
            // responsive, never blindly by suffix alone.
            $lookup_name = $name; $has_device_suffix = (bool) preg_match( '/^(.+)_(widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/', $name, $device );
            if ( $has_device_suffix ) {
                $breakpoints = $this->knowledge->breakpoints();
                if ( ! array_key_exists( $device[2], $breakpoints ) ) { $this->issue( $issues, $path, 'inactive_breakpoint', 'Responsive device is not active in this runtime.' ); continue; }
            }
            if ( ! isset( $definitions[ $name ] ) ) {
                if ( $has_device_suffix && isset( $definitions[ $device[1] ] ) ) {
                    $base_definition = $definitions[ $device[1] ];
                    if ( empty( $base_definition['responsive'] ) && empty( $base_definition['is_responsive'] ) ) { $this->issue( $issues, $path, 'not_responsive_control', 'Base control does not expose per-device values in this runtime.' ); continue; }
                    $lookup_name = $device[1];
                } else {
                    $this->issue( $issues, $path, 'unknown_control', 'Control is absent from the live runtime schema.' ); continue;
                }
            }
            $definition = $definitions[ $lookup_name ]; $type = (string) ( $definition['type'] ?? '' );
            if ( Design_Core_Agent_Knowledge::ui_only( $type ) ) { $this->issue( $issues, $path, 'ui_control', 'This is editor UI, not a writable setting.' ); continue; }
            $condition = $this->active( $definition, $effective );
            if ( true !== $condition ) { $this->issue( $issues, $path, 'condition', false === $condition ? 'Control is inactive for the supplied/default settings.' : 'Condition could not be evaluated safely.' ); }
            if ( is_array( $value ) && array_key_exists( 'unavailable', $value ) ) { $this->issue( $issues, $path, 'unavailable_value', 'A redacted/unavailable value cannot be written.' ); continue; }
            if ( isset( $definition['fields'] ) && is_array( $definition['fields'] ) ) {
                if ( ! is_array( $value ) || ! array_is_list( $value ) ) { $this->issue( $issues, $path, 'repeater_type', 'Expected an array of repeater rows.' ); continue; }
                $ids = array();
                foreach ( $value as $index => $row ) {
                    if ( ! is_array( $row ) ) { $this->issue( $issues, $path . '/' . $index, 'row_type', 'Repeater row must be an object.' ); continue; }
                    if ( isset( $row['_id'] ) ) {
                        if ( ! is_string( $row['_id'] ) || in_array( $row['_id'], $ids, true ) ) { $this->issue( $issues, $path . '/' . $index, 'row_id', 'Repeater IDs must be unique strings.' ); }
                        $ids[] = $row['_id'];
                    }
                    $this->settings( $row, (array) $definition['fields'], $path . '/' . $index, $issues, $depth + 1 );
                }
                continue;
            }
            $this->value( $value, $definition, $path, $issues );
        }
    }
    private function active( array $definition, array $effective ) {
        foreach ( (array) ( $definition['condition'] ?? array() ) as $key => $expected ) {
            $negate = str_ends_with( (string) $key, '!' ); $key = $negate ? substr( $key, 0, -1 ) : $key;
            $actual = $effective[ $key ] ?? null;
            if ( preg_match( '/^([^\[]+)\[([^\]]+)\]$/', $key, $match ) ) { $actual = $effective[ $match[1] ][ $match[2] ] ?? null; }
            $matched = is_array( $expected ) ? in_array( $actual, $expected, true ) : $actual === $expected;
            if ( $negate ? $matched : ! $matched ) { return false; }
        }
        if ( ! empty( $definition['conditions'] ) ) { return $this->terms( (array) $definition['conditions'], $effective ); }
        return true;
    }
    private function terms( array $group, array $effective, $depth = 0 ) {
        if ( $depth > 12 || ! isset( $group['terms'] ) || ! is_array( $group['terms'] ) ) { return null; }
        $relation = strtolower( (string) ( $group['relation'] ?? 'and' ) );
        if ( ! in_array( $relation, array( 'and', 'or' ), true ) ) { return null; }
        $results = array();
        foreach ( $group['terms'] as $term ) {
            if ( ! is_array( $term ) ) { return null; }
            if ( isset( $term['terms'] ) ) { $result = $this->terms( $term, $effective, $depth + 1 ); }
            else {
                if ( ! isset( $term['name'], $term['operator'] ) || ! array_key_exists( 'value', $term ) ) { return null; }
                $a = $effective[ $term['name'] ] ?? null; $b = $term['value'];
                switch ( $term['operator'] ) {
                    case '==': case '===': $result = $a === $b; break;
                    case '!=': case '!==': $result = $a !== $b; break;
                    case 'in': $result = is_array( $b ) && in_array( $a, $b, true ); break;
                    case '!in': $result = is_array( $b ) && ! in_array( $a, $b, true ); break;
                    case 'contains': $result = is_array( $a ) ? in_array( $b, $a, true ) : ( is_string( $a ) && is_string( $b ) && str_contains( $a, $b ) ); break;
                    default: return null;
                }
            }
            if ( null === $result ) { return null; } $results[] = $result;
        }
        return 'or' === $relation ? in_array( true, $results, true ) : ! in_array( false, $results, true );
    }
    private function value( $value, array $definition, $path, array &$issues ) {
        $type = (string) ( $definition['type'] ?? '' );
        if ( 'switcher' === $type || 'popover_toggle' === $type ) {
            $allowed = array( '', (string) ( $definition['return_value'] ?? 'yes' ) );
            if ( ! in_array( $value, $allowed, true ) ) { $this->issue( $issues, $path, 'enum', 'Use the runtime return_value or the empty value.' ); }
            return;
        }
        if ( in_array( $type, array( 'select', 'select2', 'choose', 'visual_choice' ), true ) ) {
            // Elementor's choose control (e.g. flex_direction) can carry its real value
            // domain in selectors_dictionary instead of options -- confirmed against the live
            // Container schema, not assumed. select/select2/visual_choice do not use this
            // mechanism in the current runtime and still require options.
            $option_source = ( is_array( $definition['options'] ?? null ) && $definition['options'] )
                ? $definition['options']
                : ( 'choose' === $type && is_array( $definition['selectors_dictionary'] ?? null ) && $definition['selectors_dictionary'] ? $definition['selectors_dictionary'] : null );
            if ( null === $option_source ) {
                // An explicitly empty multi-selection chooses nothing and
                // needs no option list to verify against.
                if ( 'select2' === $type && ! empty( $definition['multiple'] ) && array() === $value ) { return; }
                // Some stable controls expose their options dynamically (callbacks) so
                // no static list ever reaches the schema. A closed, documented value
                // domain is still verifiable -- anything outside it keeps failing.
                $leaf = preg_replace( '#^.*/([^/]+)$#', '$1', (string) $path );
                $domain = self::documented_options_domain( $leaf );
                if ( null !== $domain ) {
                    $multiple = 'select2' === $type && ! empty( $definition['multiple'] );
                    if ( $multiple && ( ! is_array( $value ) || ! array_is_list( $value ) ) ) { $this->issue( $issues, $path, 'type', 'Expected an array of selected values.' ); return; }
                    foreach ( $multiple ? $value : array( $value ) as $choice ) {
                        if ( ! is_scalar( $choice ) || ! in_array( (string) $choice, $domain, true ) ) { $this->issue( $issues, $path, 'enum', 'Value is outside the documented option domain.' ); }
                    }
                    return;
                }
                $this->issue( $issues, $path, 'dynamic_options', 'Options are unavailable; a runtime options adapter is required.' ); return;
            }
            $multiple = 'select2' === $type && ! empty( $definition['multiple'] );
            if ( $multiple && ( ! is_array( $value ) || ! array_is_list( $value ) ) ) { $this->issue( $issues, $path, 'type', 'Expected an array of selected values.' ); return; }
            $options = array_map( 'strval', array_keys( $option_source ) );
            // A runtime dictionary can be a partial logical mapping (e.g. only
            // start/end entries) while the control still interpolates {{VALUE}}
            // directly. A closed, documented domain remains verifiable --
            // values inside it pass, anything outside it keeps failing.
            $leaf = preg_replace( '#^.*/([^/]+)$#', '$1', (string) $path );
            $domain = self::documented_options_domain( $leaf );
            foreach ( $multiple ? $value : array( $value ) as $choice ) {
                if ( ! is_scalar( $choice ) || ! in_array( (string) $choice, $options, true ) ) {
                    if ( null !== $domain && is_scalar( $choice ) && in_array( (string) $choice, $domain, true ) ) { continue; }
                    $this->issue( $issues, $path, 'enum', 'Value is not a runtime option.' );
                }
            }
            return;
        }
        if ( 'number' === $type ) {
            if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( $value ) ) ) { $this->issue( $issues, $path, 'type', 'Expected a number.' ); return; }
            $numeric = $value + 0;
            foreach ( array( 'min', 'max' ) as $bound ) { if ( isset( $definition[ $bound ] ) && ( 'min' === $bound ? $numeric < $definition[ $bound ] : $numeric > $definition[ $bound ] ) ) { $this->issue( $issues, $path, 'range', 'Value is outside the runtime range.' ); } }
            return;
        }
        if ( in_array( $type, array( 'slider', 'dimensions', 'gaps' ), true ) ) {
            $keys = 'slider' === $type ? array( 'size' ) : ( 'gaps' === $type ? array( 'column', 'row' ) : array( 'top', 'right', 'bottom', 'left' ) );
            if ( ! is_array( $value ) || ! isset( $value['unit'] ) ) { $this->issue( $issues, $path, 'type', 'Expected a dimension object with unit.' ); return; }
            $allowed_keys = array_merge( $keys, array( 'unit', 'isLinked', 'sizes' ) );
            if ( array_diff( array_keys( $value ), $allowed_keys ) ) { $this->issue( $issues, $path, 'unknown_field', 'Unknown dimension property.' ); }
            $units = (array) ( $definition['size_units'] ?? array() );
            if ( ! $units && isset( $definition['default']['unit'] ) ) {
                // Controls without an explicit size_units list still declare
                // their native unit through the default value (e.g. fr-based
                // grid sliders). Honor the runtime declaration, default px.
                $units = array( (string) $definition['default']['unit'] );
            }
            if ( ! $units ) { $units = array( 'px' ); }
            if ( ! in_array( $value['unit'], $units, true ) ) { $this->issue( $issues, $path, 'unit', 'Unit is not exposed by this runtime control.' ); }
            foreach ( $keys as $key ) {
                if ( ! array_key_exists( $key, $value ) || ( '' !== $value[ $key ] && ! is_numeric( $value[ $key ] ) && ! ( 'dimensions' === $type && 'auto' === $value[ $key ] ) ) ) { $this->issue( $issues, $path . '/' . $key, 'dimension', 'Supply an explicit numeric value, empty value, or a supported auto dimension.' ); }
                $range = $definition['range'][ $value['unit'] ] ?? array();
                if ( isset( $value[ $key ] ) && is_numeric( $value[ $key ] ) ) {
                    if ( ( isset( $range['min'] ) && $value[ $key ] < $range['min'] ) || ( isset( $range['max'] ) && $value[ $key ] > $range['max'] ) ) { $this->issue( $issues, $path . '/' . $key, 'range', 'Dimension is outside the runtime range.' ); }
                }
            }
            return;
        }
        if ( 'gallery' === $type ) {
            if ( ! is_array( $value ) || ! array_is_list( $value ) ) { $this->issue( $issues, $path, 'type', 'Expected a gallery array.' ); return; }
            foreach ( $value as $index => $media ) { $this->value( $media, array( 'type' => 'media' ), $path . '/' . $index, $issues ); }
            return;
        }
        if ( 'image_dimensions' === $type ) {
            if ( ! is_array( $value ) || array_diff( array_keys( $value ), array( 'width', 'height' ) ) ) { $this->issue( $issues, $path, 'type', 'Expected width/height image dimensions.' ); return; }
            foreach ( $value as $key => $size ) { if ( '' !== $size && ( ! is_numeric( $size ) || $size < 0 ) ) { $this->issue( $issues, $path . '/' . $key, 'dimension', 'Expected a nonnegative size.' ); } }
            return;
        }
        if ( in_array( $type, array( 'box_shadow', 'text_shadow' ), true ) ) {
            if ( ! is_array( $value ) || array_diff( array_keys( $value ), array( 'horizontal', 'vertical', 'blur', 'spread', 'color', 'position' ) ) ) { $this->issue( $issues, $path, 'type', 'Invalid shadow object.' ); return; }
            foreach ( $value as $key => $part ) {
                if ( 'color' === $key ) { $this->value( $part, array( 'type' => 'color' ), $path . '/color', $issues ); }
                elseif ( 'position' === $key ) { if ( ! in_array( $part, array( '', 'inset', 'outline' ), true ) ) { $this->issue( $issues, $path, 'shadow_position', 'Invalid shadow position.' ); } }
                elseif ( ! is_numeric( $part ) ) { $this->issue( $issues, $path . '/' . $key, 'number', 'Expected a numeric shadow offset.' ); }
            }
            return;
        }
        if ( 'icon' === $type ) {
            if ( ! is_string( $value ) || ( '' !== $value && ! isset( $definition['options'][ $value ] ) ) ) { $this->issue( $issues, $path, 'icon', 'Legacy icon must be present in runtime options.' ); }
            return;
        }
        if ( 'icons' === $type ) {
            if ( ! is_array( $value ) || array_diff( array_keys( $value ), array( 'value', 'library' ) ) || ! isset( $value['value'], $value['library'] ) ) { $this->issue( $issues, $path, 'type', 'Expected an icons value/library object.' ); return; }
            if ( '' === $value['value'] && '' === $value['library'] ) { return; }
            if ( isset( $definition['default'] ) && $value === $definition['default'] ) { return; }
            $manager = '\\Elementor\\Icons_Manager';
            $tabs = is_callable( array( $manager, 'get_icon_manager_tabs' ) ) ? $manager::get_icon_manager_tabs() : array();
            if ( ! is_string( $value['library'] ) || ! isset( $tabs[ $value['library'] ] ) || ! is_string( $value['value'] ) || ! preg_match( '/^[a-zA-Z0-9_-]+(?: [a-zA-Z0-9_-]+)*$/', $value['value'] ) ) {
                $this->issue( $issues, $path, 'icon_library', 'Icon requires a registered font library or a separately verified SVG media adapter.' );
            }
            return;
        }
        if ( in_array( $type, array( 'url', 'media' ), true ) ) {
            if ( ! is_array( $value ) ) { $this->issue( $issues, $path, 'type', 'Expected a URL/media object.' ); return; }
            $allowed = 'media' === $type ? array( 'id', 'url', 'alt', 'size', 'source' ) : array( 'url', 'is_external', 'nofollow' );
            if ( array_diff( array_keys( $value ), $allowed ) ) { $this->issue( $issues, $path, 'unknown_field', 'Unrecognized media/URL property.' ); }
            if ( isset( $value['url'] ) && ( ! is_string( $value['url'] ) || preg_match( '/^(?:javascript|data|file|vbscript):/i', trim( $value['url'] ) ) ) ) { $this->issue( $issues, $path, 'unsafe_url', 'Unsafe URL scheme.' ); }
            foreach ( array( 'is_external', 'nofollow' ) as $flag ) { if ( isset( $value[ $flag ] ) && ! is_bool( $value[ $flag ] ) ) { $this->issue( $issues, $path . '/' . $flag, 'type', 'Expected a boolean.' ); } }
            foreach ( array( 'alt', 'size', 'source' ) as $field ) { if ( isset( $value[ $field ] ) && ! is_string( $value[ $field ] ) ) { $this->issue( $issues, $path . '/' . $field, 'type', 'Expected a string.' ); } }
            if ( isset( $value['id'] ) && ( ! is_int( $value['id'] ) || $value['id'] < 0 ) ) { $this->issue( $issues, $path, 'media_id', 'Attachment ID must be a nonnegative integer.' ); }
            return;
        }
        if ( in_array( $type, array( 'text', 'textarea', 'wysiwyg', 'font', 'color', 'animation', 'hover_animation', 'exit_animation', 'hidden', 'date_time' ), true ) ) {
            if ( ! is_string( $value ) ) { $this->issue( $issues, $path, 'type', 'Expected a string.' ); return; }
            if ( function_exists( 'wp_kses_post' ) && wp_kses_post( $value ) !== $value ) { $this->issue( $issues, $path, 'unsafe_markup', 'Content would be changed by WordPress HTML sanitization; correct it before writing.' ); }
            if ( preg_match( '/<(?:script|style|iframe|object|embed|form|input)\b|\bon[a-z]+\s*=|javascript\s*:/i', $value ) ) { $this->issue( $issues, $path, 'executable_content', 'Executable/interactive markup is not permitted in content settings.' ); }
            if ( preg_match( '/<(?:div|section|article|nav|table|header|footer|main)\b|\b(?:style|class)\s*=/i', $value ) ) { $this->issue( $issues, $path, 'layout_in_richtext', 'Use native elements or a reviewed component instead of layout markup inside a text setting.' ); }
            if ( 'color' === $type && '' !== $value && ! preg_match( '/^(?:#[a-f0-9]{3,8}|(?:rgb|hsl)a?\([0-9.%\s,\/+\-]+\)|transparent|currentColor)$/i', $value ) ) { $this->issue( $issues, $path, 'color', 'Color requires a recognized literal or a verified global binding.' ); }
            return;
        }
        $this->issue( $issues, $path, 'unsupported_control_type', 'No verified value adapter for runtime control type: ' . $type . '. Nothing was silently dropped.' );
    }
}

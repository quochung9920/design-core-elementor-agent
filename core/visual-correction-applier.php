<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Conservative Visual Feedback correction applier for verified Elementor V3 data.
 *
 * It only changes controls that are present in the live Control Schema Registry.
 * Unknown/private controls, unaddressable DOM issues and unsupported responsive
 * mappings are skipped rather than guessed.
 */
class Design_Core_Elementor_Visual_Correction_Applier {
    const VERSION = 1;
    const MAX_DIRECTIVES = 120;

    private $registry;

    public function __construct( Design_Core_Elementor_Control_Schema_Registry $registry = null ) {
        $this->registry = $registry ?: new Design_Core_Elementor_Control_Schema_Registry();
    }

    public function apply( $post_id, array $directives, array $context = array() ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) { return new WP_Error( 'design_core_visual_correction_post_invalid', 'A valid Elementor page ID is required for automatic correction.' ); }
        $stored = (string) get_post_meta( $post_id, '_elementor_data', true );
        $elements = json_decode( $stored, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $elements ) || empty( $elements ) ) {
            return new WP_Error( 'design_core_visual_correction_data_invalid', 'Automatic correction requires valid Elementor V3 document data.' );
        }
        $adapter = new Design_Core_Elementor_V3_Adapter();
        if ( ! $adapter->validate( $elements ) ) { return new WP_Error( 'design_core_visual_correction_not_v3', 'The current Elementor payload is not a verified V3 element tree; correction failed closed.' ); }

        $applied = array(); $skipped = array(); $seen = 0;
        foreach ( array_slice( $directives, 0, self::MAX_DIRECTIVES ) as $directive ) {
            if ( ! is_array( $directive ) || empty( $directive['auto_applicable'] ) || empty( $directive['elementor_id'] ) ) { continue; }
            $seen++;
            $element_id = sanitize_key( (string) $directive['elementor_id'] );
            $index_path = $this->find_element_path( $elements, $element_id );
            if ( null === $index_path ) { $skipped[] = array( 'elementor_id' => $element_id, 'reason' => 'element-not-found' ); continue; }
            $element =& $this->element_by_path( $elements, $index_path );
            if ( ! is_array( $element ) ) { $skipped[] = array( 'elementor_id' => $element_id, 'reason' => 'element-path-invalid' ); unset( $element ); continue; }
            $result = $this->apply_directive_to_element( $element, $directive );
            unset( $element );
            if ( empty( $result['changes'] ) ) { $skipped[] = array( 'elementor_id' => $element_id, 'reason' => $result['reason'] ?? 'no-safe-control', 'details' => $result['skipped'] ?? array() ); continue; }
            $applied[] = array( 'elementor_id' => $element_id, 'device' => $directive['device'] ?? 'desktop', 'category' => $directive['category'] ?? '', 'changes' => $result['changes'], 'skipped' => $result['skipped'] ?? array() );
        }

        if ( ! $applied ) {
            return array( 'version' => self::VERSION, 'status' => 'no-safe-changes', 'post_id' => $post_id, 'directives_seen' => $seen, 'applied' => array(), 'skipped' => $skipped );
        }

        $settings = $this->document_settings( $post_id );
        $service = new Design_Core_Elementor_Persistence_Service();
        $evidence = $service->save_and_verify(
            $post_id,
            $elements,
            $settings,
            array( $adapter, 'raw_save_page' ),
            array( $adapter, 'reload' ),
            array( $adapter, 'render' ),
            array( 'adapter' => 'elementor-v3', 'source' => 'visual-correction', 'iteration' => (int) ( $context['iteration'] ?? 0 ), 'directives' => count( $applied ) )
        );
        if ( is_wp_error( $evidence ) ) { return $evidence; }
        return array( 'version' => self::VERSION, 'status' => 'applied', 'post_id' => $post_id, 'directives_seen' => $seen, 'applied' => $applied, 'skipped' => $skipped, 'persistence' => $evidence );
    }

    /** Pure seam used by standalone tests. */
    public function value_for_control( array $definition, $property, $reference, $current = null ) {
        $type = sanitize_key( (string) ( $definition['type'] ?? '' ) );
        $property = sanitize_key( (string) $property );
        if ( 'dimensions' === $type ) { return $this->dimensions_value( $reference, $current ); }
        if ( 'gaps' === $type ) { return $this->gaps_value( $reference ); }
        if ( 'slider' === $type ) { return $this->dimension_value( $reference ); }
        if ( 'number' === $type ) { return (float) preg_replace( '/[^0-9.\-]/', '', (string) $reference ); }
        if ( in_array( $property, array( 'font_weight' ), true ) && is_numeric( $reference ) ) { return (string) (int) $reference; }
        return trim( (string) $reference );
    }

    private function apply_directive_to_element( array &$element, array $directive ) {
        $device = sanitize_key( (string) ( $directive['device'] ?? 'desktop' ) );
        if ( ! in_array( $device, array( 'desktop', 'widescreen', 'laptop', 'tablet_extra', 'tablet', 'mobile_extra', 'mobile' ), true ) ) { $device = 'desktop'; }
        $changes = (array) ( $directive['changes'] ?? array() );
        if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) { $element['settings'] = array(); }
        $applied = array(); $skipped = array();

        $dimension_groups = array( 'padding' => array(), 'margin' => array() );
        foreach ( $changes as $change ) {
            if ( ! is_array( $change ) ) { continue; }
            $property = sanitize_key( (string) ( $change['property'] ?? '' ) );
            if ( preg_match( '/^(padding|margin)_(top|right|bottom|left)$/', $property, $match ) ) {
                $dimension_groups[ $match[1] ][ $match[2] ] = (string) ( $change['reference'] ?? '' );
            }
        }
        foreach ( $dimension_groups as $base => $sides ) {
            if ( ! $sides ) { continue; }
            $resolved = $this->resolve_control( $element, $base, $device );
            if ( ! $resolved ) { $skipped[] = $base . ':runtime-control-unavailable'; continue; }
            $setting_key = $this->setting_key( $resolved['id'], $device );
            $current = $element['settings'][ $setting_key ] ?? array();
            $next = $this->merge_dimension_sides( $current, $sides );
            if ( $next !== $current ) { $element['settings'][ $setting_key ] = $next; $applied[] = array( 'property' => $base, 'control' => $resolved['id'], 'setting' => $setting_key, 'value' => $next ); }
        }

        foreach ( $changes as $change ) {
            if ( ! is_array( $change ) ) { continue; }
            $property = sanitize_key( (string) ( $change['property'] ?? '' ) );
            if ( preg_match( '/^(padding|margin)_(top|right|bottom|left)$/', $property ) ) { continue; }
            $reference = (string) ( $change['reference'] ?? '' );
            $resolved = $this->resolve_control( $element, $property, $device );
            if ( ! $resolved ) { $skipped[] = $property . ':runtime-control-unavailable'; continue; }
            $setting_key = $this->setting_key( $resolved['id'], $device );
            $current = $element['settings'][ $setting_key ] ?? null;
            $next = $this->value_for_control( $resolved['definition'], $property, $reference, $current );
            if ( $next === $current ) { continue; }
            $element['settings'][ $setting_key ] = $next;
            $applied[] = array( 'property' => $property, 'control' => $resolved['id'], 'setting' => $setting_key, 'value' => $next );
        }

        return array( 'changes' => $applied, 'skipped' => $skipped, 'reason' => $applied ? '' : 'no-runtime-verified-control' );
    }

    private function resolve_control( array $element, $property, $device ) {
        $element_type = 'widget' === ( $element['elType'] ?? '' ) ? 'widget' : 'container';
        $widget_type = 'widget' === $element_type ? sanitize_key( (string) ( $element['widgetType'] ?? '' ) ) : '';
        $map = array(
            'width' => array( 'width', '_element_width', 'content_width' ),
            'max_width' => array( 'max_width', 'content_width', 'width' ),
            'height' => array( 'height', 'min_height' ), 'min_height' => array( 'min_height', 'height' ),
            'gap' => array( 'gap', 'flex_gap', 'row_gap', 'column_gap' ), 'row_gap' => array( 'row_gap', 'gap', 'flex_gap' ), 'column_gap' => array( 'column_gap', 'gap', 'flex_gap' ),
            'padding' => array( 'padding' ), 'margin' => array( 'margin' ),
            'justify_content' => array( 'justify_content' ), 'align_items' => array( 'align_items' ),
            'font_size' => array( 'typography_font_size', 'title_typography_font_size', 'text_typography_font_size' ),
            'line_height' => array( 'typography_line_height', 'title_typography_line_height', 'text_typography_line_height' ),
            'letter_spacing' => array( 'typography_letter_spacing', 'title_typography_letter_spacing', 'text_typography_letter_spacing' ),
            'font_weight' => array( 'typography_font_weight', 'title_typography_font_weight', 'text_typography_font_weight' ),
            'align' => array( 'align', 'text_align', 'alignment' ),
            'object_fit' => array( 'object-fit', 'object_fit', 'image_fit' ), 'object_position' => array( 'object-position', 'object_position', 'image_position' ),
            'background_size' => array( 'background_size' ), 'background_position' => array( 'background_position' ),
            'border_radius' => array( 'border_radius', 'image_border_radius' ),
        );
        $candidates = $map[ $property ] ?? array( $property );
        foreach ( $candidates as $candidate ) {
            $definition = $this->registry->control( $element_type, $widget_type, $candidate );
            if ( ! is_array( $definition ) ) { continue; }
            if ( 'desktop' !== $device && empty( $definition['responsive'] ) && empty( $definition['is_responsive'] ) ) { continue; }
            return array( 'id' => $candidate, 'definition' => $definition );
        }

        // Conservative semantic fallback: require exactly one live control whose ID
        // contains the normalized property token and whose type is compatible.
        $schema = $this->registry->schema( $element_type, $widget_type ); $matches = array();
        $token = str_replace( array( 'font_', 'object_' ), '', $property );
        foreach ( (array) ( $schema['controls'] ?? array() ) as $id => $definition ) {
            if ( ! is_array( $definition ) || false === strpos( str_replace( '-', '_', strtolower( (string) $id ) ), str_replace( '-', '_', $token ) ) ) { continue; }
            if ( 'desktop' !== $device && empty( $definition['responsive'] ) && empty( $definition['is_responsive'] ) ) { continue; }
            $matches[] = array( 'id' => (string) $id, 'definition' => $definition );
            if ( count( $matches ) > 1 ) { break; }
        }
        return 1 === count( $matches ) ? $matches[0] : null;
    }

    private function setting_key( $control_id, $device ) { return 'desktop' === $device ? (string) $control_id : (string) $control_id . '_' . $device; }

    private function dimension_value( $value ) {
        if ( preg_match( '/(-?[0-9.]+)\s*(px|%|em|rem|vh|vw|vmin|vmax)?/i', trim( (string) $value ), $match ) ) {
            return array( 'size' => (float) $match[1], 'unit' => strtolower( $match[2] ?: 'px' ) );
        }
        return array( 'size' => 0.0, 'unit' => 'px' );
    }

    private function dimensions_value( $value, $current = null ) {
        $parts = array_values( array_filter( preg_split( '/\s+/', trim( (string) $value ) ) ) );
        if ( ! $parts ) { return is_array( $current ) ? $current : array( 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'unit' => 'px', 'isLinked' => true ); }
        if ( 1 === count( $parts ) ) { $parts = array( $parts[0], $parts[0], $parts[0], $parts[0] ); }
        elseif ( 2 === count( $parts ) ) { $parts = array( $parts[0], $parts[1], $parts[0], $parts[1] ); }
        elseif ( 3 === count( $parts ) ) { $parts = array( $parts[0], $parts[1], $parts[2], $parts[1] ); }
        $dims = array_map( array( $this, 'dimension_value' ), array_slice( $parts, 0, 4 ) ); $unit = $dims[0]['unit'];
        return array( 'top' => (string) $dims[0]['size'], 'right' => (string) $dims[1]['size'], 'bottom' => (string) $dims[2]['size'], 'left' => (string) $dims[3]['size'], 'unit' => $unit, 'isLinked' => count( array_unique( array_map( static function ( $d ) { return $d['size'] . $d['unit']; }, $dims ) ) ) === 1 );
    }

    private function merge_dimension_sides( $current, array $sides ) {
        $next = is_array( $current ) ? $current : array();
        foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) { if ( ! array_key_exists( $side, $next ) ) { $next[ $side ] = '0'; } }
        $unit = (string) ( $next['unit'] ?? 'px' );
        foreach ( $sides as $side => $value ) { $dimension = $this->dimension_value( $value ); $next[ $side ] = (string) $dimension['size']; $unit = $dimension['unit']; }
        $next['unit'] = $unit;
        $next['isLinked'] = count( array_unique( array( $next['top'], $next['right'], $next['bottom'], $next['left'] ) ) ) === 1;
        return $next;
    }

    private function gaps_value( $value ) {
        $parts = array_values( array_filter( preg_split( '/\s+/', trim( (string) $value ) ) ) );
        $row = $this->dimension_value( $parts[0] ?? '0px' ); $column = $this->dimension_value( $parts[1] ?? ( $parts[0] ?? '0px' ) );
        return array( 'row' => (string) $row['size'], 'column' => (string) $column['size'], 'unit' => $row['unit'], 'isLinked' => $row['size'] === $column['size'] && $row['unit'] === $column['unit'] );
    }

    private function find_element_path( array $elements, $id, array $prefix = array() ) {
        foreach ( $elements as $index => $element ) {
            if ( ! is_array( $element ) ) { continue; }
            $path = array_merge( $prefix, array( $index ) );
            if ( (string) ( $element['id'] ?? '' ) === (string) $id ) { return $path; }
            $child = $this->find_element_path( (array) ( $element['elements'] ?? array() ), $id, array_merge( $path, array( 'elements' ) ) );
            if ( null !== $child ) { return $child; }
        }
        return null;
    }

    private function &element_by_path( array &$elements, array $path ) {
        $ref =& $elements;
        foreach ( $path as $segment ) { $ref =& $ref[ $segment ]; }
        return $ref;
    }

    private function document_settings( $post_id ) {
        if ( ! class_exists( '\\Elementor\\Plugin' ) ) { return array(); }
        try {
            $plugin = \Elementor\Plugin::instance();
            if ( empty( $plugin->documents ) ) { return array(); }
            $document = $plugin->documents->get( (int) $post_id );
            if ( $document && method_exists( $document, 'get_settings' ) ) { $settings = $document->get_settings(); return is_array( $settings ) ? $settings : array(); }
        } catch ( Throwable $exception ) { return array(); }
        return array();
    }
}

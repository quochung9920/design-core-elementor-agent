<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Design_Core_Elementor_Global_Style_Bridge {
    const V4_SYNC_OPTION = 'design_core_elementor_v4_class_sync';
    const V3_OWNERSHIP_OPTION = 'design_core_elementor_v3_global_ownership_v1';
    private $tokens;

    public function __construct( $tokens = null ) {
        $this->tokens = $tokens instanceof Design_Core_Elementor_Design_Token_Service ? $tokens : new Design_Core_Elementor_Design_Token_Service();
    }

    public function sync( $mode = 'v3' ) {
        $mode = sanitize_key( $mode );
        return in_array( $mode, array( 'v4', 'mixed' ), true ) ? $this->sync_v4_classes() : $this->sync_v3_kit();
    }

    public function sync_v3_kit() {
        if ( ! class_exists( '\Elementor\Plugin' ) ) { return new WP_Error( 'design_core_elementor_missing', 'Elementor is not active.' ); }
        $plugin = \Elementor\Plugin::instance();
        if ( empty( $plugin->kits_manager ) || ! method_exists( $plugin->kits_manager, 'get_active_kit' ) ) { return new WP_Error( 'design_core_kit_unavailable', 'Elementor active Kit is unavailable.' ); }
        $kit = $plugin->kits_manager->get_active_kit();
        if ( ! $kit || ! method_exists( $kit, 'update_settings' ) ) { return new WP_Error( 'design_core_kit_not_editable', 'Elementor Kit settings cannot be updated.' ); }

        $tokens = $this->tokens->all();
        $kit_id = method_exists( $plugin->kits_manager, 'get_active_id' ) ? (int) $plugin->kits_manager->get_active_id() : 0;
        $persisted = $kit_id ? get_post_meta( $kit_id, '_elementor_page_settings', true ) : array();
        $custom_colors = is_array( $persisted ) && isset( $persisted['custom_colors'] ) ? (array) $persisted['custom_colors'] : (array) $kit->get_settings( 'custom_colors' );
        $custom_typography = is_array( $persisted ) && isset( $persisted['custom_typography'] ) ? (array) $persisted['custom_typography'] : (array) $kit->get_settings( 'custom_typography' );
        $ownership = get_option( self::V3_OWNERSHIP_OPTION, array() );
        $ownership = is_array( $ownership ) ? $ownership : array();
        $ownership['colors'] = is_array( $ownership['colors'] ?? null ) ? $ownership['colors'] : array();
        $ownership['typography'] = is_array( $ownership['typography'] ?? null ) ? $ownership['typography'] : array();
        $ownership['layout'] = is_array( $ownership['layout'] ?? null ) ? $ownership['layout'] : array();
        $color_refs = array(); $typography_refs = array();
        foreach ( $tokens['colors'] ?? array() as $name => $value ) {
            $id = $this->owned_stable_id( 'dc-color-' . $name, $custom_colors, $ownership['colors'][ $name ] ?? '' );
            $ownership['colors'][ $name ] = $id;
            $custom_colors = $this->upsert_repeater( $custom_colors, array( '_id' => $id, 'title' => $this->title( $name ), 'color' => $value ) );
            $color_refs[ $name ] = 'globals/colors?id=' . $id;
        }
        foreach ( $tokens['typography'] ?? array() as $name => $value ) {
            $id = $this->owned_stable_id( 'dc-type-' . $name, $custom_typography, $ownership['typography'][ $name ] ?? '' );
            $ownership['typography'][ $name ] = $id;
            $item = array_merge( array( '_id' => $id, 'title' => $this->title( $name ), 'typography_typography' => 'custom' ), $this->typography_kit_settings( $this->typography_definition( $value ) ) );
            $custom_typography = $this->upsert_repeater( $custom_typography, $item );
            $typography_refs[ $name ] = 'globals/typography?id=' . $id;
        }

        $updates = array( 'custom_colors' => array_values( $custom_colors ), 'custom_typography' => array_values( $custom_typography ) );
        $layout_result = array( 'status' => 'unavailable', 'class' => '' );
        if ( class_exists( 'Design_Core_Elementor_Global_Layout_Standard' ) ) {
            $standard = new Design_Core_Elementor_Global_Layout_Standard();
            $desired_layout = $standard->kit_settings();
            $desired_width = $desired_layout['container_width'];
            $current_width = is_array( $persisted ) && isset( $persisted['container_width'] ) ? $persisted['container_width'] : null;
            $previous_width = $ownership['layout']['container_width'] ?? null;
            $width_owned = null === $current_width || ( null !== $previous_width && $current_width === $previous_width );
            if ( $width_owned ) {
                $updates['container_width'] = $desired_width;
                $ownership['layout']['container_width'] = $desired_width;
            }
            $current_css = is_array( $persisted ) ? (string) ( $persisted['custom_css'] ?? '' ) : '';
            $updates['custom_css'] = $standard->merge_managed_css( $current_css );
            $ownership['layout']['managed_css_hash'] = hash( 'sha256', $standard->managed_css() );
            $layout_result = array(
                'status' => $width_owned ? 'synced' : 'preserved-user-width',
                'class' => $standard->class_name(),
                'devices' => $standard->devices(),
            );
        }

        try { $kit->update_settings( $updates ); }
        catch ( Throwable $e ) { return new WP_Error( 'design_core_global_sync_failed', $e->getMessage() ); }
        update_option( self::V3_OWNERSHIP_OPTION, $ownership, false );
        if ( get_option( self::V3_OWNERSHIP_OPTION, null ) !== $ownership ) {
            return new WP_Error( 'design_core_global_ownership_failed', 'Elementor global ownership metadata could not be persisted.' );
        }
        return array( 'mode' => 'v3', 'status' => 'synced', 'colors' => $color_refs, 'typography' => $typography_refs, 'layout' => $layout_result );
    }

    public function sync_v4_classes() {
        $ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'elementor/manage-classes' ) : null;
        if ( ! $ability || ! method_exists( $ability, 'execute' ) ) { return array( 'mode' => 'v4', 'status' => 'capability-unavailable', 'classes' => array() ); }

        $tokens = $this->tokens->all();
        $hash = hash( 'sha256', wp_json_encode( $tokens ) );
        $cached = get_option( self::V4_SYNC_OPTION, array() );
        if ( ( $cached['hash'] ?? '' ) === $hash && ! empty( $cached['classes'] ) ) { return array( 'mode' => 'v4', 'status' => 'cached', 'classes' => $cached['classes'], 'ids' => $cached['ids'] ?? array() ); }

        $classes = array(); $desired = array();
        foreach ( $tokens['colors'] ?? array() as $name => $value ) {
            $text = 'dc-text-' . sanitize_key( $name ); $bg = 'dc-bg-' . sanitize_key( $name );
            $desired[ $text ] = array( 'color' => $value ); $desired[ $bg ] = array( 'background-color' => $value );
            $classes['text'][ $name ] = $text; $classes['background'][ $name ] = $bg;
        }
        foreach ( $tokens['typography'] ?? array() as $name => $value ) {
            if ( ! is_array( $value ) ) { continue; }
            $label = 'dc-type-' . sanitize_key( $name ); $css = array();
            foreach ( $value as $key => $setting ) {
                $prop = str_replace( '_', '-', preg_replace( '/^typography_/', '', sanitize_key( (string) $key ) ) );
                if ( in_array( $prop, array( 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-transform' ), true ) ) { $css[ $prop ] = (string) $setting; }
            }
            if ( $css ) { $desired[ $label ] = $css; $classes['typography'][ $name ] = $label; }
        }
        foreach ( $tokens['radius'] ?? array() as $name => $value ) {
            $label = 'dc-radius-' . sanitize_key( $name ); $desired[ $label ] = array( 'border-radius' => (float) $value . 'px' ); $classes['radius'][ $name ] = $label;
        }
        foreach ( $tokens['spacing'] ?? array() as $name => $value ) {
            $label = 'dc-gap-' . sanitize_key( $name ); $desired[ $label ] = array( 'gap' => (float) $value . 'px' ); $classes['spacing'][ $name ] = $label;
        }
        if ( empty( $desired ) ) { return array( 'mode' => 'v4', 'status' => 'no-tokens', 'classes' => $classes ); }

        $known_ids = is_array( $cached['ids'] ?? null ) ? $cached['ids'] : array();
        $operations = array(); $labels_by_index = array();
        foreach ( $desired as $label => $css ) {
            $operation = array( 'action' => empty( $known_ids[ $label ] ) ? 'create' : 'update', 'label' => $label, 'css' => $css );
            if ( ! empty( $known_ids[ $label ] ) ) { $operation['id'] = sanitize_text_field( (string) $known_ids[ $label ] ); }
            $labels_by_index[] = $label; $operations[] = $operation;
        }

        $results = array(); $offset = 0;
        foreach ( array_chunk( $operations, 50 ) as $batch ) {
            try { $response = $ability->execute( array( 'operations' => $batch ) ); }
            catch ( Throwable $e ) { return new WP_Error( 'design_core_v4_global_sync_failed', $e->getMessage() ); }
            if ( is_wp_error( $response ) ) { return $response; }
            foreach ( (array) ( $response['results'] ?? array() ) as $result ) {
                $global_index = $offset + (int) ( $result['index'] ?? 0 );
                $requested_label = $labels_by_index[ $global_index ] ?? '';
                if ( $requested_label && 'ok' === ( $result['status'] ?? '' ) && ! empty( $result['id'] ) ) { $known_ids[ $requested_label ] = sanitize_text_field( (string) $result['id'] ); }
                $result['requested_label'] = $requested_label; $results[] = $result;
            }
            $offset += count( $batch );
        }

        update_option( self::V4_SYNC_OPTION, array( 'hash' => $hash, 'classes' => $classes, 'ids' => $known_ids, 'synced_at' => gmdate( 'c' ) ), false );
        return array( 'mode' => 'v4', 'status' => 'synced', 'classes' => $classes, 'ids' => $known_ids, 'results' => $results );
    }

    public function apply_v3_references( $elements, $sync_result ) {
        if ( ! is_array( $sync_result ) || 'v3' !== ( $sync_result['mode'] ?? '' ) ) { return $elements; }
        $tokens = $this->tokens->all();
        foreach ( $elements as $index => $element ) {
            $settings = $element['settings'] ?? array(); $globals = is_array( $settings['__globals__'] ?? null ) ? $settings['__globals__'] : array();
            foreach ( $sync_result['colors'] ?? array() as $name => $ref ) {
                $token = $tokens['colors'][ $name ] ?? null;
                foreach ( array( 'title_color', 'text_color', 'background_color', 'button_text_color' ) as $key ) {
                    if ( $token && isset( $settings[ $key ] ) && strtolower( (string) $settings[ $key ] ) === strtolower( (string) $token ) && ( empty( $globals[ $key ] ) || $globals[ $key ] === $ref ) ) { $globals[ $key ] = $ref; }
                }
            }
            foreach ( $sync_result['typography'] ?? array() as $name => $ref ) {
                $definition = $this->typography_definition( $tokens['typography'][ $name ] ?? array() );
                if ( $definition && $this->typography_matches( $settings, $definition ) && ( empty( $globals['typography_typography'] ) || $globals['typography_typography'] === $ref ) ) { $globals['typography_typography'] = $ref; }
            }
            if ( $globals ) { $elements[ $index ]['settings']['__globals__'] = $globals; }
            if ( ! empty( $element['elements'] ) ) { $elements[ $index ]['elements'] = $this->apply_v3_references( $element['elements'], $sync_result ); }
        }
        return $elements;
    }

    private function typography_definition( $value ) {
        if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) { return array( 'typography_font_family' => sanitize_text_field( (string) $value ) ); }
        if ( ! is_array( $value ) ) { return array(); }
        $aliases = array(
            'family' => 'typography_font_family', 'font_family' => 'typography_font_family', 'typography_font_family' => 'typography_font_family',
            'weight' => 'typography_font_weight', 'font_weight' => 'typography_font_weight', 'typography_font_weight' => 'typography_font_weight',
            'size' => 'typography_font_size', 'font_size' => 'typography_font_size', 'typography_font_size' => 'typography_font_size',
            'line_height' => 'typography_line_height', 'typography_line_height' => 'typography_line_height',
            'letter_spacing' => 'typography_letter_spacing', 'typography_letter_spacing' => 'typography_letter_spacing',
            'text_transform' => 'typography_text_transform', 'typography_text_transform' => 'typography_text_transform',
            'font_style' => 'typography_font_style', 'typography_font_style' => 'typography_font_style',
            'text_decoration' => 'typography_text_decoration', 'typography_text_decoration' => 'typography_text_decoration',
        );
        $definition = array();
        foreach ( $value as $key => $setting ) {
            if ( ! is_scalar( $setting ) && null !== $setting ) { continue; }
            $key = str_replace( '-', '_', sanitize_key( (string) $key ) );
            if ( ! isset( $aliases[ $key ] ) && preg_match( '/^(font_size|line_height|letter_spacing)_(widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/', $key, $responsive ) ) {
                $aliases[ $key ] = 'typography_' . $responsive[1] . '_' . $responsive[2];
            }
            if ( isset( $aliases[ $key ] ) && '' !== trim( (string) $setting ) ) { $definition[ $aliases[ $key ] ] = sanitize_text_field( (string) $setting ); }
        }
        return $definition;
    }

    private function typography_kit_settings( array $definition ) {
        $settings = array();
        $dimension_units = array( 'typography_font_size' => 'px', 'typography_line_height' => 'em', 'typography_letter_spacing' => 'px' );
        foreach ( $definition as $key => $value ) {
            $base_key = preg_replace( '/_(?:widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/', '', $key );
            if ( isset( $dimension_units[ $base_key ] ) && is_numeric( $value ) ) { $settings[ $key ] = array( 'size' => (float) $value, 'unit' => $dimension_units[ $base_key ] ); }
            else { $settings[ $key ] = $value; }
        }
        return $settings;
    }

    private function typography_matches( array $settings, array $definition ) {
        $dimension_units = array( 'typography_font_size' => 'px', 'typography_line_height' => 'em', 'typography_letter_spacing' => 'px' );
        foreach ( $definition as $key => $expected ) {
            if ( ! array_key_exists( $key, $settings ) ) { return false; }
            $actual = $settings[ $key ];
            $base_key = preg_replace( '/_(?:widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/', '', $key );
            if ( isset( $dimension_units[ $base_key ] ) ) {
                if ( ! is_array( $actual ) || ! array_key_exists( 'size', $actual ) || (float) $actual['size'] !== (float) $expected ) { return false; }
                $unit = (string) ( $actual['unit'] ?? $dimension_units[ $base_key ] );
                if ( '' !== $unit && $dimension_units[ $base_key ] !== $unit ) { return false; }
                continue;
            }
            if ( strtolower( trim( (string) $actual ) ) !== strtolower( trim( (string) $expected ) ) ) { return false; }
        }
        foreach ( array_keys( $settings ) as $key ) {
            if ( preg_match( '/^typography_(?:font_size|line_height|letter_spacing)_(?:widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/', $key ) && ! array_key_exists( $key, $definition ) ) { return false; }
        }
        return true;
    }

    private function owned_stable_id( $seed, array $items, $owned_id = '' ) {
        $owned_id = sanitize_text_field( (string) $owned_id );
        if ( $owned_id ) { return $owned_id; }
        for ( $attempt = 0; $attempt < 100; $attempt++ ) {
            $id = $this->stable_id( $seed . ( $attempt ? '-collision-' . $attempt : '' ) );
            $occupied = false;
            foreach ( $items as $item ) { if ( $id === ( $item['_id'] ?? '' ) ) { $occupied = true; break; } }
            if ( ! $occupied ) { return $id; }
        }
        throw new RuntimeException( 'Unable to allocate a collision-safe Elementor global ID.' );
    }

    private function upsert_repeater( $items, $new_item ) { foreach ( $items as $index => $item ) { if ( ( $item['_id'] ?? '' ) === $new_item['_id'] ) { $items[ $index ] = array_merge( $item, $new_item ); return $items; } } $items[] = $new_item; return $items; }
    private function stable_id( $value ) { return substr( md5( sanitize_key( $value ) ), 0, 7 ); }
    private function title( $name ) { return ucwords( str_replace( array( '-', '_' ), ' ', sanitize_text_field( (string) $name ) ) ); }
}

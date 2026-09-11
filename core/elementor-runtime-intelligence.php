<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Runtime-first Elementor/Elementor Pro knowledge composed from Widget Intelligence v2. */
class Design_Core_Elementor_Runtime_Intelligence {
    const SCHEMA_VERSION = 1;
    const MAX_SITE_ITEMS = 200;
    private $widgets;

    public function __construct( $widgets = null ) {
        $this->widgets = $widgets instanceof Design_Core_Elementor_Widget_Intelligence
            ? $widgets
            : new Design_Core_Elementor_Widget_Intelligence();
    }

    public function elementor_catalog( $source = 'all', $query = '', $limit = 100 ) {
        $limit = max( 1, min( self::MAX_SITE_ITEMS, (int) $limit ) );
        $catalog = $this->widgets->catalog( $source, $query );
        $rows = array();
        foreach ( array_slice( array_values( (array) ( $catalog['widgets'] ?? array() ) ), 0, $limit ) as $widget ) {
            if ( ! is_array( $widget ) ) { continue; }
            $rows[] = array(
                'name' => (string) ( $widget['name'] ?? '' ),
                'title' => (string) ( $widget['title'] ?? '' ),
                'source' => (string) ( $widget['source'] ?? '' ),
                'source_label' => (string) ( $widget['source_label'] ?? '' ),
                'categories' => array_values( (array) ( $widget['categories'] ?? array() ) ),
                'keywords' => array_values( (array) ( $widget['keywords'] ?? array() ) ),
                'control_count' => (int) ( $widget['control_count'] ?? 0 ),
                'responsive_control_count' => (int) ( $widget['responsive_control_count'] ?? 0 ),
                'semantic' => (array) ( $widget['semantic'] ?? array() ),
                'capabilities' => (array) ( $widget['capabilities'] ?? array() ),
                'schema_fingerprint' => (string) ( $widget['schema_fingerprint'] ?? '' ),
            );
        }
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'widget_profile_version' => (int) ( $catalog['profile_version'] ?? 0 ),
            'control_schema_version' => (int) ( $catalog['schema_version'] ?? 0 ),
            'versions' => (array) ( $catalog['versions'] ?? array() ),
            'counts' => (array) ( $catalog['counts'] ?? array() ),
            'control_counts' => (array) ( $catalog['control_counts'] ?? array() ),
            'filtered_count' => isset( $catalog['filtered_count'] ) ? (int) $catalog['filtered_count'] : count( (array) ( $catalog['widgets'] ?? array() ) ),
            'returned' => count( $rows ),
            'truncated' => count( (array) ( $catalog['widgets'] ?? array() ) ) > count( $rows ),
            'widgets' => $rows,
            'error' => (string) ( $catalog['error'] ?? '' ),
        );
    }

    public function widget_search( array $requirements, $limit = 10 ) {
        $limit = max( 1, min( 50, (int) $limit ) );
        $query = trim( sanitize_text_field( (string) ( $requirements['query'] ?? '' ) ) );
        $intent = sanitize_key( (string) ( $requirements['intent'] ?? '' ) );
        $keywords = $this->clean_string_list( (array) ( $requirements['keywords'] ?? array() ), 30 );
        if ( $query ) { $keywords = array_values( array_unique( array_merge( $keywords, $this->keywords_from_text( $query, 12 ) ) ) ); }
        $normalized = array(
            'intent' => $intent,
            'capabilities' => $this->clean_key_list( (array) ( $requirements['capabilities'] ?? array() ), 20 ),
            'controls' => $this->clean_key_list( (array) ( $requirements['controls'] ?? array() ), 30 ),
            'keywords' => $keywords,
        );
        if ( ! $normalized['intent'] && ! $normalized['capabilities'] && ! $normalized['controls'] && ! $normalized['keywords'] ) {
            return new WP_Error( 'design_core_widget_search_requirements_required', 'Provide query, intent, capabilities, controls or keywords.', array( 'status' => 400 ) );
        }
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'requirements' => $normalized,
            'candidates' => $this->widgets->find_candidates( $normalized, $limit ),
        );
    }

    public function widget_schema( $widget_name, $detail = false ) {
        $detail_row = $this->widgets->inspect( $widget_name );
        if ( is_wp_error( $detail_row ) ) { return $detail_row; }
        if ( ! is_array( $detail_row ) ) { return new WP_Error( 'design_core_widget_schema_invalid', 'Widget intelligence returned an invalid schema.', array( 'status' => 500 ) ); }
        if ( $detail ) { return Design_Core_Elementor_Widget_Intelligence::transport_safe( $detail_row ); }

        $controls = array();
        foreach ( array_slice( (array) ( $detail_row['controls'] ?? array() ), 0, 30 ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $definition = (array) ( $row['definition'] ?? array() );
            $controls[] = array(
                'path' => (string) ( $row['path'] ?? '' ),
                'name' => (string) ( $row['name'] ?? '' ),
                'label' => sanitize_text_field( (string) ( $definition['label'] ?? '' ) ),
                'type' => sanitize_key( (string) ( $definition['type'] ?? '' ) ),
                'responsive' => ! empty( $definition['responsive'] ) || ! empty( $definition['is_responsive'] ),
                'condition' => Design_Core_Elementor_Widget_Intelligence::transport_safe( $definition['condition'] ?? array() ),
            );
        }
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'name' => (string) ( $detail_row['name'] ?? '' ),
            'title' => (string) ( $detail_row['title'] ?? '' ),
            'source' => (string) ( $detail_row['source'] ?? '' ),
            'class' => (string) ( $detail_row['class'] ?? '' ),
            'categories' => (array) ( $detail_row['categories'] ?? array() ),
            'semantic' => (array) ( $detail_row['semantic'] ?? array() ),
            'capabilities' => (array) ( $detail_row['capabilities'] ?? array() ),
            'control_count' => (int) ( $detail_row['control_count'] ?? 0 ),
            'responsive_control_count' => (int) ( $detail_row['responsive_control_count'] ?? 0 ),
            'control_types' => (array) ( $detail_row['control_types'] ?? array() ),
            'schema_fingerprint' => (string) ( $detail_row['schema_fingerprint'] ?? '' ),
            'controls_sample' => $controls,
            'controls_sample_truncated' => (int) ( $detail_row['control_count'] ?? 0 ) > count( $controls ),
            'note' => 'Pass detail=true for the full flattened control contract and machine-readable JSON Schema.',
        );
    }

    public function elementor_capabilities() {
        $catalog = $this->widgets->catalog();
        $intent_counts = array();
        $capability_counts = array();
        $pro_widgets = array();
        foreach ( (array) ( $catalog['widgets'] ?? array() ) as $widget ) {
            if ( ! is_array( $widget ) ) { continue; }
            foreach ( (array) ( $widget['semantic']['intents'] ?? array() ) as $intent ) {
                $intent = sanitize_key( (string) $intent );
                if ( $intent ) { $intent_counts[ $intent ] = ( $intent_counts[ $intent ] ?? 0 ) + 1; }
            }
            foreach ( (array) ( $widget['capabilities']['supports'] ?? array() ) as $capability ) {
                $capability = sanitize_key( (string) $capability );
                if ( $capability ) { $capability_counts[ $capability ] = ( $capability_counts[ $capability ] ?? 0 ) + 1; }
            }
            if ( 'pro' === ( $widget['source'] ?? '' ) && count( $pro_widgets ) < 150 ) {
                $pro_widgets[] = (string) ( $widget['name'] ?? '' );
            }
        }
        ksort( $intent_counts );
        ksort( $capability_counts );
        $scanner = class_exists( 'Design_Core_Elementor_Capability_Scanner' ) ? ( new Design_Core_Elementor_Capability_Scanner() )->scan() : array();
        $templates = $this->template_catalog( 100 );
        $schema = class_exists( 'Design_Core_Elementor_Control_Schema_Registry' ) ? new Design_Core_Elementor_Control_Schema_Registry() : null;

        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'runtime' => array(
                'elementor' => (string) ( $catalog['versions']['elementor'] ?? '' ),
                'elementor_pro' => (string) ( $catalog['versions']['elementor_pro'] ?? '' ),
                'elementor_pro_active' => ! empty( $catalog['versions']['elementor_pro'] ),
                'editor_mode' => (string) ( $scanner['elementor']['editor_mode'] ?? 'v3' ),
            ),
            'widget_counts' => (array) ( $catalog['counts'] ?? array() ),
            'control_counts' => (array) ( $catalog['control_counts'] ?? array() ),
            'semantic_intent_counts' => $intent_counts,
            'capability_counts' => $capability_counts,
            'pro_widget_names' => $pro_widgets,
            'element_schemas' => array(
                'container' => $schema ? $schema->available( 'container' ) : false,
                'section' => $schema ? $schema->available( 'section' ) : false,
                'column' => $schema ? $schema->available( 'column' ) : false,
            ),
            'template_types' => (array) ( $templates['counts_by_type'] ?? array() ),
            'policy' => array(
                'runtime_is_source_of_truth' => true,
                'prefer_native_widgets' => true,
                'never_guess_unknown_controls' => true,
            ),
        );
    }

    private function template_catalog( $limit = 100 ) {
        $limit = max( 1, min( 200, (int) $limit ) );
        if ( ! post_type_exists( 'elementor_library' ) ) { return array( 'available'=>false, 'counts_by_type'=>array() ); }
        $posts = get_posts( array( 'post_type'=>'elementor_library', 'post_status'=>'any', 'numberposts'=>$limit, 'orderby'=>'modified', 'order'=>'DESC', 'suppress_filters'=>false ) );
        $counts = array();
        foreach ( (array) $posts as $post ) {
            if ( ! is_object( $post ) ) { continue; }
            $type = sanitize_key( (string) get_post_meta( $post->ID, '_elementor_template_type', true ) );
            $type = $type ?: 'unknown';
            $counts[ $type ] = ( $counts[ $type ] ?? 0 ) + 1;
        }
        ksort( $counts );
        return array( 'available'=>true, 'counts_by_type'=>$counts );
    }

    private function keywords_from_text( $text, $limit ) {
        $text = $this->lower( wp_strip_all_tags( (string) $text ) );
        $parts = preg_split( '/[^a-z0-9_-]+/i', $text );
        $stop = array_flip( array( 'the','and','for','with','from','that','this','into','page','site','website','create','build','make','want','need','please','using','elementor','wordpress' ) );
        $out = array();
        foreach ( is_array( $parts ) ? $parts : array() as $part ) {
            $part = trim( (string) $part );
            if ( strlen( $part ) < 3 || isset( $stop[ $part ] ) ) { continue; }
            $out[] = sanitize_text_field( $part );
            if ( count( array_unique( $out ) ) >= $limit ) { break; }
        }
        return array_values( array_unique( $out ) );
    }

    private function clean_key_list( array $items, $limit ) {
        $out = array();
        foreach ( $items as $item ) {
            $key = sanitize_key( (string) $item );
            if ( $key ) { $out[] = $key; }
            if ( count( $out ) >= $limit ) { break; }
        }
        return array_values( array_unique( $out ) );
    }

    private function clean_string_list( array $items, $limit ) {
        $out = array();
        foreach ( $items as $item ) {
            if ( ! is_scalar( $item ) ) { continue; }
            $value = trim( sanitize_text_field( (string) $item ) );
            if ( $value ) { $out[] = $value; }
            if ( count( $out ) >= $limit ) { break; }
        }
        return array_values( array_unique( $out ) );
    }

    private function lower( $value ) {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value ) : strtolower( (string) $value );
    }
}

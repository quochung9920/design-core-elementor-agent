<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Builds a bounded, mutation-free website context for AI design decisions.
 *
 * The context deliberately separates source/reference intent from local site conventions.
 * It mines the active Elementor Kit, live runtime capabilities, reusable registries and
 * existing Elementor page patterns so a design agent can reuse the site's language before
 * inventing local styles or custom widgets.
 */
final class Design_Core_Elementor_Site_Context_Engine {
    const VERSION = 1;
    const MAX_PATTERN_PAGES = 24;
    const MAX_SECTION_PATTERNS = 24;
    const MAX_WIDGET_USAGE = 60;
    const MAX_MEDIA_ITEMS = 20;

    public function prepare( $brief = '', $page_id = 0, $site_map_limit = 100 ) {
        $brief = trim( sanitize_textarea_field( (string) $brief ) );
        $page_id = max( 0, (int) $page_id );
        $site_map_limit = max( 1, min( 200, (int) $site_map_limit ) );

        $site = new Design_Core_Elementor_Site_Intelligence();
        $runtime = new Design_Core_Elementor_Runtime_Intelligence();

        $site_map = $site->site_map( $site_map_limit );
        if ( is_wp_error( $site_map ) ) { return $site_map; }
        $design_system = $site->site_design_system();
        if ( is_wp_error( $design_system ) ) { return $design_system; }
        $capabilities = $runtime->elementor_capabilities();
        if ( is_wp_error( $capabilities ) ) { return $capabilities; }

        $patterns = $this->pattern_inventory();
        $target = $this->target_context( $page_id );
        $media = $site->media_library( '', 'image', self::MAX_MEDIA_ITEMS );
        if ( is_wp_error( $media ) ) { $media = array(); }

        $context = array(
            'context_version' => self::VERSION,
            'generated_at' => gmdate( 'c' ),
            'brief' => $brief,
            'target_page_id' => $page_id,
            'site' => array(
                'identity' => (array) ( $site_map['site'] ?? array() ),
                'theme' => (array) ( $site_map['theme'] ?? array() ),
                'post_types' => (array) ( $site_map['post_types'] ?? array() ),
                'menus' => (array) ( $site_map['menus'] ?? array() ),
                'templates' => (array) ( $site_map['elementor_templates'] ?? array() ),
            ),
            'design_system' => $design_system,
            'elementor' => array(
                'runtime' => (array) ( $capabilities['runtime'] ?? array() ),
                'widget_counts' => (array) ( $capabilities['widget_counts'] ?? array() ),
                'control_counts' => (array) ( $capabilities['control_counts'] ?? array() ),
                'capability_counts' => (array) ( $capabilities['capability_counts'] ?? array() ),
                'policy' => (array) ( $capabilities['policy'] ?? array() ),
                'global_settings' => $this->active_kit_settings(),
            ),
            'reusable' => array(
                'design_core_registry' => (array) ( $site_map['design_core_registry'] ?? array() ),
                'template_types' => (array) ( $site_map['elementor_templates']['counts_by_type'] ?? array() ),
            ),
            'existing_site_patterns' => $patterns,
            'target_page' => $target,
            'assets' => array(
                'recent_images' => array_values( array_slice( (array) ( $media['items'] ?? array() ), 0, self::MAX_MEDIA_ITEMS ) ),
                'returned' => (int) ( $media['returned'] ?? 0 ),
                'found' => (int) ( $media['found'] ?? 0 ),
            ),
            'decision_policy' => $this->decision_policy(),
            'context_priority' => array(
                'user_requirement',
                'reference_source',
                'website_design_system',
                'existing_reusable_components',
                'existing_page_patterns',
                'live_elementor_runtime',
            ),
            'implementation_priority' => array(
                'reuse_existing_component',
                'exact_native_widget',
                'closest_compatible_widget_native_controls',
                'closest_compatible_widget_scoped_css',
                'native_widget_composition',
                'new_custom_widget',
            ),
        );

        $hash_payload = $context;
        unset( $hash_payload['generated_at'] );
        $context['context_hash'] = hash( 'sha256', (string) wp_json_encode( $hash_payload ) );
        $context['completeness'] = $this->completeness( $context );
        return $context;
    }

    private function active_kit_settings() {
        if ( ! class_exists( '\\Elementor\\Plugin' ) ) { return array( 'available'=>false, 'settings'=>array() ); }
        try {
            $plugin = \Elementor\Plugin::instance();
            if ( empty( $plugin->kits_manager ) || ! method_exists( $plugin->kits_manager, 'get_active_kit' ) ) { return array( 'available'=>false, 'settings'=>array() ); }
            $kit = $plugin->kits_manager->get_active_kit();
            if ( ! $kit || ! method_exists( $kit, 'get_settings' ) ) { return array( 'available'=>false, 'settings'=>array() ); }
            $method = new ReflectionMethod( $kit, 'get_settings' );
            if ( $method->getNumberOfRequiredParameters() > 0 ) { return array( 'available'=>false, 'settings'=>array(), 'reason'=>'bulk-settings-unavailable' ); }
            $settings = $kit->get_settings();
            if ( ! is_array( $settings ) ) { return array( 'available'=>false, 'settings'=>array() ); }
            $safe = class_exists( 'Design_Core_Elementor_Widget_Intelligence' ) ? Design_Core_Elementor_Widget_Intelligence::transport_safe( $settings ) : $settings;
            return array(
                'available' => true,
                'active_kit_id' => method_exists( $plugin->kits_manager, 'get_active_id' ) ? (int) $plugin->kits_manager->get_active_id() : 0,
                'setting_keys' => array_values( array_map( 'strval', array_keys( $settings ) ) ),
                'settings' => $safe,
            );
        } catch ( Throwable $exception ) {
            return array( 'available'=>false, 'settings'=>array(), 'error'=>sanitize_text_field( $exception->getMessage() ) );
        }
    }

    private function target_context( $page_id ) {
        if ( $page_id <= 0 || ! class_exists( 'Design_Core_Elementor_Page_Snapshot' ) ) {
            return array( 'available' => false, 'page_id' => $page_id );
        }
        $snapshot = ( new Design_Core_Elementor_Page_Snapshot() )->snapshot( $page_id );
        if ( is_wp_error( $snapshot ) ) {
            return array( 'available' => false, 'page_id' => $page_id, 'error' => $snapshot->get_error_message() );
        }
        return array(
            'available' => true,
            'page_id' => $page_id,
            'page' => (array) ( $snapshot['page'] ?? array() ),
            'elementor' => array(
                'editor_mode' => (string) ( $snapshot['elementor']['editor_mode'] ?? '' ),
                'total_elements' => (int) ( $snapshot['elementor']['total_elements'] ?? 0 ),
                'widget_types' => (array) ( $snapshot['elementor']['widget_types'] ?? array() ),
            ),
            'design_core' => (array) ( $snapshot['design_core'] ?? array() ),
            'warnings' => (array) ( $snapshot['warnings'] ?? array() ),
        );
    }

    private function pattern_inventory() {
        $posts = get_posts( array(
            'post_type' => 'page',
            'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ),
            'numberposts' => self::MAX_PATTERN_PAGES,
            'orderby' => 'modified',
            'order' => 'DESC',
            'meta_key' => '_elementor_edit_mode',
            'meta_value' => 'builder',
            'suppress_filters' => false,
        ) );

        $widgets = array();
        $sections = array();
        $global_refs = 0;
        $pages = array();
        foreach ( is_array( $posts ) ? $posts : array() as $post ) {
            if ( ! is_object( $post ) ) { continue; }
            $tree = $this->elementor_tree( (int) $post->ID );
            if ( ! $tree ) { continue; }
            $page_widgets = array();
            foreach ( $tree as $index => $root ) {
                if ( ! is_array( $root ) ) { continue; }
                $signature_widgets = array();
                $this->walk_element( $root, $widgets, $page_widgets, $signature_widgets, $global_refs );
                $signature_widgets = array_slice( array_values( array_filter( $signature_widgets ) ), 0, 12 );
                if ( $signature_widgets ) {
                    $signature = implode( '>', $signature_widgets );
                    if ( ! isset( $sections[ $signature ] ) ) {
                        $sections[ $signature ] = array( 'count' => 0, 'widgets' => $signature_widgets, 'examples' => array() );
                    }
                    $sections[ $signature ]['count']++;
                    if ( count( $sections[ $signature ]['examples'] ) < 3 ) {
                        $sections[ $signature ]['examples'][] = array(
                            'page_id' => (int) $post->ID,
                            'title' => sanitize_text_field( (string) $post->post_title ),
                            'root_index' => (int) $index,
                        );
                    }
                }
            }
            arsort( $page_widgets );
            $pages[] = array(
                'page_id' => (int) $post->ID,
                'title' => sanitize_text_field( (string) $post->post_title ),
                'status' => sanitize_key( (string) $post->post_status ),
                'modified_gmt' => (string) $post->post_modified_gmt,
                'top_widgets' => array_slice( $page_widgets, 0, 12, true ),
            );
        }

        arsort( $widgets );
        uasort( $sections, static function ( $a, $b ) { return (int) $b['count'] <=> (int) $a['count']; } );
        return array(
            'sampled_pages' => count( $pages ),
            'global_reference_count' => $global_refs,
            'widget_usage' => array_slice( $widgets, 0, self::MAX_WIDGET_USAGE, true ),
            'section_patterns' => array_values( array_slice( $sections, 0, self::MAX_SECTION_PATTERNS, true ) ),
            'pages' => $pages,
            'reuse_signal' => array(
                'prefer_frequent_site_widgets' => true,
                'prefer_existing_section_signatures' => true,
                'prefer_global_references' => true,
            ),
        );
    }

    private function elementor_tree( $page_id ) {
        $raw = get_post_meta( $page_id, '_elementor_data', true );
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            return is_array( $decoded ) ? $decoded : array();
        }
        return is_array( $raw ) ? $raw : array();
    }

    private function walk_element( array $element, array &$widgets, array &$page_widgets, array &$signature_widgets, &$global_refs ) {
        if ( 'widget' === ( $element['elType'] ?? '' ) ) {
            $type = sanitize_key( (string) ( $element['widgetType'] ?? '' ) );
            if ( $type ) {
                $widgets[ $type ] = ( $widgets[ $type ] ?? 0 ) + 1;
                $page_widgets[ $type ] = ( $page_widgets[ $type ] ?? 0 ) + 1;
                $signature_widgets[] = $type;
            }
        }
        $settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
        $global_refs += $this->count_global_references( $settings );
        foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
            if ( is_array( $child ) ) { $this->walk_element( $child, $widgets, $page_widgets, $signature_widgets, $global_refs ); }
        }
    }

    private function count_global_references( array $settings ) {
        $count = 0;
        foreach ( $settings as $key => $value ) {
            if ( '__globals__' === $key && is_array( $value ) ) { $count += count( array_filter( $value ) ); continue; }
            if ( is_array( $value ) ) { $count += $this->count_global_references( $value ); }
        }
        return $count;
    }

    private function decision_policy() {
        return array(
            'analyze_before_build' => true,
            'runtime_schema_is_authority' => true,
            'global_design_before_local_values' => true,
            'existing_site_pattern_before_invention' => true,
            'functional_correctness_before_visual_similarity' => true,
            'native_controls_before_css' => true,
            'scoped_css_only_for_presentation_gap' => true,
            'native_composition_before_custom_widget' => true,
            'custom_widget_only_for_new_behavior_or_data_model' => true,
            'never_guess_unknown_controls' => true,
        );
    }

    private function completeness( array $context ) {
        $checks = array(
            'site' => ! empty( $context['site']['identity'] ),
            'design_system' => ! empty( $context['design_system'] ),
            'elementor_runtime' => ! empty( $context['elementor']['runtime'] ),
            'widget_catalog' => (int) ( $context['elementor']['widget_counts']['all'] ?? 0 ) > 0,
            'site_patterns' => (int) ( $context['existing_site_patterns']['sampled_pages'] ?? 0 ) > 0,
            'target_page' => empty( $context['target_page_id'] ) || ! empty( $context['target_page']['available'] ),
        );
        $passed = count( array_filter( $checks ) );
        return array(
            'score' => round( $passed / max( 1, count( $checks ) ), 4 ),
            'checks' => $checks,
            'status' => $passed === count( $checks ) ? 'complete' : 'partial',
        );
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded read model for the current WordPress site and its Design Core context. */
class Design_Core_Elementor_Site_Intelligence {
    const SCHEMA_VERSION = 1;
    const MAX_SITE_ITEMS = 200;
    const MAX_SEARCH_RESULTS = 50;

    public function site_map( $limit = 100 ) {
        $limit = max( 1, min( self::MAX_SITE_ITEMS, (int) $limit ) );
        $pages = get_posts( array(
            'post_type' => 'page',
            'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ),
            'numberposts' => $limit,
            'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
            'order' => 'ASC',
            'suppress_filters' => false,
        ) );

        $page_rows = array();
        foreach ( is_array( $pages ) ? $pages : array() as $page ) {
            if ( ! is_object( $page ) ) { continue; }
            $manifest = get_post_meta( (int) $page->ID, '_design_core_page_manifest', true );
            $page_rows[] = array(
                'id' => (int) $page->ID,
                'title' => sanitize_text_field( (string) $page->post_title ),
                'slug' => sanitize_title( (string) $page->post_name ),
                'status' => sanitize_key( (string) $page->post_status ),
                'parent_id' => (int) $page->post_parent,
                'menu_order' => (int) $page->menu_order,
                'modified_gmt' => (string) $page->post_modified_gmt,
                'url' => 'publish' === $page->post_status ? (string) get_permalink( $page->ID ) : '',
                'elementor' => 'builder' === (string) get_post_meta( $page->ID, '_elementor_edit_mode', true ),
                'manifest_section_count' => is_array( $manifest ) ? count( (array) ( $manifest['sections'] ?? array() ) ) : 0,
            );
        }

        $post_types = array();
        $objects = get_post_types( array( 'public' => true ), 'objects' );
        foreach ( is_array( $objects ) ? $objects : array() as $name => $object ) {
            if ( ! is_object( $object ) || 'attachment' === $name ) { continue; }
            $counts = wp_count_posts( $name );
            $post_types[] = array(
                'name' => sanitize_key( (string) $name ),
                'label' => sanitize_text_field( (string) ( $object->label ?? $name ) ),
                'hierarchical' => ! empty( $object->hierarchical ),
                'rest_base' => sanitize_key( (string) ( $object->rest_base ?? $name ) ),
                'counts' => array(
                    'publish' => (int) ( $counts->publish ?? 0 ),
                    'draft' => (int) ( $counts->draft ?? 0 ),
                    'private' => (int) ( $counts->private ?? 0 ),
                    'pending' => (int) ( $counts->pending ?? 0 ),
                    'future' => (int) ( $counts->future ?? 0 ),
                ),
            );
        }

        $menus = array();
        if ( function_exists( 'wp_get_nav_menus' ) ) {
            foreach ( (array) wp_get_nav_menus() as $menu ) {
                if ( ! is_object( $menu ) ) { continue; }
                $menus[] = array(
                    'id' => (int) ( $menu->term_id ?? 0 ),
                    'name' => sanitize_text_field( (string) ( $menu->name ?? '' ) ),
                    'slug' => sanitize_title( (string) ( $menu->slug ?? '' ) ),
                    'items' => (int) ( $menu->count ?? 0 ),
                );
            }
        }

        $theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
        $templates = $this->template_catalog( '', min( 100, $limit ) );

        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate( 'c' ),
            'site' => array(
                'name' => sanitize_text_field( (string) get_bloginfo( 'name' ) ),
                'home_url' => (string) home_url( '/' ),
                'site_url' => (string) site_url( '/' ),
                'locale' => (string) get_locale(),
                'show_on_front' => sanitize_key( (string) get_option( 'show_on_front', 'posts' ) ),
                'page_on_front' => (int) get_option( 'page_on_front', 0 ),
                'page_for_posts' => (int) get_option( 'page_for_posts', 0 ),
            ),
            'theme' => array(
                'stylesheet' => (string) get_stylesheet(),
                'template' => (string) get_template(),
                'name' => $theme && method_exists( $theme, 'get' ) ? sanitize_text_field( (string) $theme->get( 'Name' ) ) : '',
                'version' => $theme && method_exists( $theme, 'get' ) ? sanitize_text_field( (string) $theme->get( 'Version' ) ) : '',
            ),
            'post_types' => $post_types,
            'pages' => $page_rows,
            'pages_truncated' => count( $page_rows ) >= $limit,
            'menus' => $menus,
            'elementor_templates' => $templates,
            'design_core_registry' => array(
                'components' => class_exists( 'Design_Core_Elementor_Component_Registry' ) ? count( (array) ( new Design_Core_Elementor_Component_Registry() )->all() ) : 0,
                'sections' => class_exists( 'Design_Core_Elementor_Section_Registry' ) ? count( (array) ( new Design_Core_Elementor_Section_Registry() )->all() ) : 0,
                'widgets' => class_exists( 'Design_Core_Elementor_Widget_Registry' ) ? count( (array) ( new Design_Core_Elementor_Widget_Registry() )->all() ) : 0,
            ),
        );
    }

    public function site_design_system() {
        $tokens = class_exists( 'Design_Core_Elementor_Design_Token_Service' )
            ? ( new Design_Core_Elementor_Design_Token_Service() )->all()
            : array();
        $breakpoints = class_exists( 'Design_Core_Elementor_Breakpoint_Registry' )
            ? ( new Design_Core_Elementor_Breakpoint_Registry() )->all()
            : array();
        $layout = class_exists( 'Design_Core_Elementor_Global_Layout_Standard' )
            ? ( new Design_Core_Elementor_Global_Layout_Standard() )->devices()
            : array();

        $kit_id = 0;
        $kit_settings = array();
        if ( class_exists( '\\Elementor\\Plugin' ) ) {
            try {
                $plugin = \Elementor\Plugin::instance();
                if ( ! empty( $plugin->kits_manager ) && method_exists( $plugin->kits_manager, 'get_active_id' ) && method_exists( $plugin->kits_manager, 'get_active_kit' ) ) {
                    $kit_id = (int) $plugin->kits_manager->get_active_id();
                    $kit = $plugin->kits_manager->get_active_kit();
                    if ( $kit && method_exists( $kit, 'get_settings' ) ) {
                        foreach ( array(
                            'system_colors', 'custom_colors', 'system_typography', 'custom_typography',
                            'container_width', 'space_between_widgets', 'viewport_md', 'viewport_lg', 'active_breakpoints',
                        ) as $key ) {
                            $value = $kit->get_settings( $key );
                            if ( null !== $value && '' !== $value ) {
                                $kit_settings[ $key ] = Design_Core_Elementor_Widget_Intelligence::transport_safe( $value );
                            }
                        }
                    }
                }
            } catch ( Throwable $exception ) {
                $kit_settings = array();
            }
        }

        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate( 'c' ),
            'design_core_tokens' => Design_Core_Elementor_Widget_Intelligence::transport_safe( $tokens ),
            'runtime_breakpoints' => $breakpoints,
            'managed_layout_standard' => $layout,
            'elementor_kit' => array(
                'active_kit_id' => $kit_id,
                'settings' => $kit_settings,
            ),
            'source_of_truth' => array(
                'widget_controls' => 'live-elementor-runtime',
                'design_tokens' => 'design-core-token-service',
                'global_styles' => 'active-elementor-kit',
            ),
        );
    }

    public function search_content( $query, array $types = array(), $limit = 20 ) {
        $query = trim( sanitize_text_field( (string) $query ) );
        if ( '' === $query ) { return new WP_Error( 'design_core_search_query_required', 'A non-empty search query is required.', array( 'status' => 400 ) ); }
        $limit = max( 1, min( self::MAX_SEARCH_RESULTS, (int) $limit ) );

        $public = array_values( array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) ) );
        $requested = array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );
        $post_types = $requested ? array_values( array_intersect( $requested, $public ) ) : $public;
        if ( ! $post_types ) { return new WP_Error( 'design_core_search_post_types_invalid', 'No requested public post types are available.', array( 'status' => 400 ) ); }

        $q = new WP_Query( array(
            's' => $query,
            'post_type' => $post_types,
            'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ),
            'posts_per_page' => $limit,
            'orderby' => 'relevance',
            'order' => 'DESC',
            'no_found_rows' => false,
            'ignore_sticky_posts' => true,
        ) );

        $results = array();
        foreach ( (array) $q->posts as $post ) {
            if ( ! is_object( $post ) ) { continue; }
            $excerpt_source = '' !== trim( (string) $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
            $excerpt = wp_trim_words( wp_strip_all_tags( (string) $excerpt_source ), 32, '…' );
            $results[] = array(
                'id' => (int) $post->ID,
                'type' => sanitize_key( (string) $post->post_type ),
                'status' => sanitize_key( (string) $post->post_status ),
                'title' => sanitize_text_field( (string) $post->post_title ),
                'slug' => sanitize_title( (string) $post->post_name ),
                'modified_gmt' => (string) $post->post_modified_gmt,
                'url' => 'publish' === $post->post_status ? (string) get_permalink( $post->ID ) : '',
                'excerpt' => $excerpt,
                'elementor' => 'builder' === (string) get_post_meta( $post->ID, '_elementor_edit_mode', true ),
                'has_design_core_manifest' => is_array( get_post_meta( $post->ID, '_design_core_page_manifest', true ) ),
            );
        }

        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'query' => $query,
            'post_types' => $post_types,
            'found' => (int) $q->found_posts,
            'returned' => count( $results ),
            'results' => $results,
            'registry_matches' => $this->registry_matches( $query, 20 ),
        );
    }

    public function media_library( $query = '', $mime = '', $limit = 30 ) {
        $limit = max( 1, min( self::MAX_SEARCH_RESULTS, (int) $limit ) );
        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => false,
        );
        $query = trim( sanitize_text_field( (string) $query ) );
        $mime = trim( sanitize_text_field( (string) $mime ) );
        if ( $query ) { $args['s'] = $query; }
        if ( $mime ) { $args['post_mime_type'] = $mime; }
        $q = new WP_Query( $args );
        $items = array();
        foreach ( (array) $q->posts as $attachment ) {
            if ( ! is_object( $attachment ) ) { continue; }
            $meta = wp_get_attachment_metadata( $attachment->ID );
            $items[] = array(
                'id' => (int) $attachment->ID,
                'title' => sanitize_text_field( (string) $attachment->post_title ),
                'mime_type' => sanitize_text_field( (string) $attachment->post_mime_type ),
                'url' => (string) wp_get_attachment_url( $attachment->ID ),
                'alt' => sanitize_text_field( (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) ),
                'caption' => sanitize_text_field( (string) $attachment->post_excerpt ),
                'width' => is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0,
                'height' => is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0,
                'modified_gmt' => (string) $attachment->post_modified_gmt,
            );
        }
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'query' => $query,
            'mime' => $mime,
            'found' => (int) $q->found_posts,
            'returned' => count( $items ),
            'items' => $items,
        );
    }

    private function template_catalog( $type = '', $limit = 100 ) {
        $limit = max( 1, min( self::MAX_SITE_ITEMS, (int) $limit ) );
        if ( ! post_type_exists( 'elementor_library' ) ) {
            return array( 'available' => false, 'counts_by_type' => array(), 'templates' => array() );
        }
        $type = sanitize_key( (string) $type );
        $posts = get_posts( array(
            'post_type' => 'elementor_library',
            'post_status' => 'any',
            'numberposts' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
            'suppress_filters' => false,
        ) );
        $counts = array();
        $rows = array();
        foreach ( is_array( $posts ) ? $posts : array() as $post ) {
            if ( ! is_object( $post ) ) { continue; }
            $template_type = sanitize_key( (string) get_post_meta( $post->ID, '_elementor_template_type', true ) );
            if ( '' === $template_type ) { $template_type = 'unknown'; }
            $counts[ $template_type ] = ( $counts[ $template_type ] ?? 0 ) + 1;
            if ( $type && $type !== $template_type ) { continue; }
            $rows[] = array(
                'id' => (int) $post->ID,
                'title' => sanitize_text_field( (string) $post->post_title ),
                'status' => sanitize_key( (string) $post->post_status ),
                'template_type' => $template_type,
                'modified_gmt' => (string) $post->post_modified_gmt,
            );
        }
        ksort( $counts );
        return array(
            'available' => true,
            'elementor_pro_active' => defined( 'ELEMENTOR_PRO_VERSION' ),
            'counts_by_type' => $counts,
            'returned' => count( $rows ),
            'templates' => $rows,
        );
    }

    private function registry_matches( $query, $limit ) {
        $needle = $this->lower( $query );
        $matches = array();
        $registries = array(
            'section' => class_exists( 'Design_Core_Elementor_Section_Registry' ) ? ( new Design_Core_Elementor_Section_Registry() )->all() : array(),
            'component' => class_exists( 'Design_Core_Elementor_Component_Registry' ) ? ( new Design_Core_Elementor_Component_Registry() )->all() : array(),
        );
        foreach ( $registries as $kind => $items ) {
            foreach ( (array) $items as $item ) {
                if ( ! is_array( $item ) ) { continue; }
                $semantic = (string) ( $item['fingerprint']['semantic'] ?? '' );
                $id = (string) ( $item['id'] ?? '' );
                $haystack = $this->lower( $id . ' ' . $semantic . ' ' . wp_json_encode( array_keys( (array) ( $item['variants'] ?? array() ) ) ) );
                if ( false === strpos( $haystack, $needle ) ) { continue; }
                $matches[] = array(
                    'kind' => $kind,
                    'id' => sanitize_text_field( $id ),
                    'semantic' => sanitize_text_field( $semantic ),
                    'variant_count' => count( (array) ( $item['variants'] ?? array() ) ),
                    'usage_count' => (int) ( $item['usage']['count'] ?? 0 ),
                );
                if ( count( $matches ) >= $limit ) { return $matches; }
            }
        }
        return $matches;
    }

    private function lower( $value ) {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value ) : strtolower( (string) $value );
    }
}

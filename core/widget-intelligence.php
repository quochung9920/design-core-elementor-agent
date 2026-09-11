<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Runtime-first widget knowledge service.
 *
 * Elementor itself is the source of truth. Semantic metadata is derived from the
 * live widget registration plus its discovered control contract; it never
 * replaces or hard-codes the runtime schema.
 */
class Design_Core_Elementor_Widget_Intelligence {
    const PROFILE_VERSION = 1;

    private $registry;
    private $provider;
    private $catalog_cache = null;

    public function __construct( Design_Core_Elementor_Control_Schema_Registry $registry = null, $provider = null ) {
        $this->registry = $registry ?: new Design_Core_Elementor_Control_Schema_Registry();
        $this->provider = is_callable( $provider ) ? $provider : array( $this, 'runtime_widgets' );
    }

    public function catalog( $source = 'all', $query = '' ) {
        if ( null === $this->catalog_cache ) { $this->catalog_cache = $this->build_catalog(); }

        $source = sanitize_key( (string) $source );
        $valid_sources = array( 'all', 'core', 'pro', 'design_core', 'third_party' );
        if ( ! in_array( $source, $valid_sources, true ) ) { $source = 'all'; }
        $query = trim( (string) $query );
        if ( 'all' === $source && '' === $query ) { return $this->catalog_cache; }

        $query_cmp = self::lower( $query );
        $filtered = array_filter( $this->catalog_cache['widgets'], static function ( $widget ) use ( $source, $query_cmp ) {
            if ( 'all' !== $source && ( $widget['source'] ?? '' ) !== $source ) { return false; }
            if ( '' === $query_cmp ) { return true; }
            $semantic = is_array( $widget['semantic'] ?? null ) ? $widget['semantic'] : array();
            $haystack = implode( ' ', array(
                (string) ( $widget['name'] ?? '' ),
                (string) ( $widget['title'] ?? '' ),
                (string) ( $widget['class'] ?? '' ),
                implode( ' ', (array) ( $widget['categories'] ?? array() ) ),
                implode( ' ', (array) ( $widget['keywords'] ?? array() ) ),
                implode( ' ', (array) ( $semantic['intents'] ?? array() ) ),
                implode( ' ', (array) ( $semantic['search_terms'] ?? array() ) ),
                implode( ' ', (array) ( $widget['capabilities']['supports'] ?? array() ) ),
            ) );
            return false !== strpos( self::lower( $haystack ), $query_cmp );
        } );

        $catalog = $this->catalog_cache;
        $catalog['widgets'] = $filtered;
        $catalog['filtered_count'] = count( $filtered );
        return $catalog;
    }

    public function inspect( $widget_type ) {
        $widget_type = sanitize_key( (string) $widget_type );
        if ( '' === $widget_type ) { return new WP_Error( 'design_core_widget_missing', 'Widget name is required.' ); }
        $widgets = call_user_func( $this->provider );
        if ( is_wp_error( $widgets ) ) { return $widgets; }
        if ( ! is_array( $widgets ) || ! isset( $widgets[ $widget_type ] ) || ! is_object( $widgets[ $widget_type ] ) ) {
            return new WP_Error( 'design_core_widget_not_found', 'The requested Elementor widget is not registered.' );
        }
        return $this->summarize_widget( $widget_type, $widgets[ $widget_type ], true );
    }

    /**
     * Rank live widgets against explicit requirements. This is deterministic
     * candidate discovery; final use still requires the runtime schema/binder.
     * Requirements: intent, controls[], capabilities[], keywords[].
     */
    public function find_candidates( array $requirements, $limit = 10 ) {
        $limit = max( 1, min( 100, (int) $limit ) );
        $catalog = $this->catalog();
        if ( ! empty( $catalog['error'] ) ) { return array(); }
        $ranked = array();
        foreach ( $catalog['widgets'] as $widget ) {
            $scored = $this->score_widget( $widget, $requirements );
            if ( $scored['score'] <= 0 ) { continue; }
            $ranked[] = array(
                'name' => $widget['name'],
                'title' => $widget['title'],
                'source' => $widget['source'],
                'score' => $scored['score'],
                'reasons' => $scored['reasons'],
                'intents' => $widget['semantic']['intents'] ?? array(),
                'capabilities' => $widget['capabilities']['supports'] ?? array(),
                'schema_fingerprint' => $widget['schema_fingerprint'] ?? '',
            );
        }
        usort( $ranked, static function ( $a, $b ) {
            if ( (float) $a['score'] === (float) $b['score'] ) { return strcasecmp( (string) $a['title'], (string) $b['title'] ); }
            return ( (float) $a['score'] > (float) $b['score'] ) ? -1 : 1;
        } );
        return array_slice( $ranked, 0, $limit );
    }

    /** Recursively make runtime definitions JSON/REST-safe without losing shape. */
    public static function transport_safe( $value, $depth = 0 ) {
        if ( $depth > 8 ) { return '[max-depth]'; }
        if ( null === $value || is_scalar( $value ) ) { return $value; }
        if ( is_resource( $value ) ) { return '[resource]'; }
        if ( $value instanceof Closure ) { return '[closure]'; }
        if ( is_object( $value ) ) { return '[object ' . get_class( $value ) . ']'; }
        if ( ! is_array( $value ) ) { return '[' . gettype( $value ) . ']'; }
        $out = array();
        $count = 0;
        foreach ( $value as $key => $item ) {
            if ( $count++ >= 500 ) { $out['__truncated__'] = true; break; }
            $out[ $key ] = self::transport_safe( $item, $depth + 1 );
        }
        return $out;
    }

    public function runtime_widgets() {
        if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
            return new WP_Error( 'design_core_elementor_runtime_missing', 'Elementor runtime is not available.' );
        }
        try {
            $plugin = \Elementor\Plugin::instance();
            if ( ! isset( $plugin->widgets_manager ) || ! is_object( $plugin->widgets_manager ) ) {
                return new WP_Error( 'design_core_widgets_manager_missing', 'Elementor widgets manager is not available.' );
            }
            $widgets = $plugin->widgets_manager->get_widget_types();
            return is_array( $widgets ) ? $widgets : new WP_Error( 'design_core_widgets_invalid', 'Elementor returned an invalid widget registry.' );
        } catch ( Throwable $exception ) {
            return new WP_Error( 'design_core_widgets_runtime_error', $exception->getMessage() );
        }
    }

    public static function classify_widget_class( $class_name ) {
        $class_name = ltrim( (string) $class_name, '\\' );
        if ( 0 === strpos( $class_name, 'ElementorPro\\' ) ) { return 'pro'; }
        if ( 0 === strpos( $class_name, 'Design_Core_Elementor_' ) ) { return 'design_core'; }
        if ( 0 === strpos( $class_name, 'Elementor\\' ) ) { return 'core'; }
        return 'third_party';
    }

    private function build_catalog() {
        $catalog = array(
            'profile_version' => self::PROFILE_VERSION,
            'schema_version' => Design_Core_Elementor_Control_Schema_Registry::SCHEMA_VERSION,
            'versions' => array(
                'elementor' => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '',
                'elementor_pro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ELEMENTOR_PRO_VERSION : '',
            ),
            'counts' => array( 'all' => 0, 'core' => 0, 'pro' => 0, 'design_core' => 0, 'third_party' => 0 ),
            'control_counts' => array( 'all' => 0, 'core' => 0, 'pro' => 0, 'design_core' => 0, 'third_party' => 0 ),
            'widgets' => array(),
            'error' => '',
        );

        $widgets = call_user_func( $this->provider );
        if ( is_wp_error( $widgets ) ) {
            $catalog['error'] = $widgets->get_error_message();
            return $catalog;
        }
        if ( ! is_array( $widgets ) ) {
            $catalog['error'] = 'Widget provider returned an invalid registry.';
            return $catalog;
        }

        foreach ( $widgets as $registered_name => $widget ) {
            if ( ! is_object( $widget ) ) { continue; }
            $summary = $this->summarize_widget( (string) $registered_name, $widget, false );
            $name = $summary['name'];
            $catalog['widgets'][ $name ] = $summary;
            $source = $summary['source'];
            $catalog['counts']['all']++;
            $catalog['control_counts']['all'] += (int) $summary['control_count'];
            if ( isset( $catalog['counts'][ $source ] ) ) {
                $catalog['counts'][ $source ]++;
                $catalog['control_counts'][ $source ] += (int) $summary['control_count'];
            }
        }

        uasort( $catalog['widgets'], static function ( $a, $b ) {
            return strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
        } );
        return $catalog;
    }

    private function summarize_widget( $registered_name, $widget, $include_detail ) {
        $name = (string) $this->safe_widget_call( $widget, 'get_name', $registered_name );
        $name = sanitize_key( $name ?: (string) $registered_name );
        $class = get_class( $widget );
        $source = self::classify_widget_class( $class );
        $schema = $this->registry->schema( 'widget', $name, (bool) $include_detail );
        $controls = is_array( $schema['controls'] ?? null ) ? $schema['controls'] : array();
        $flat = self::flatten_controls( $controls );

        $section_count = 0;
        $nested_count = 0;
        $responsive_count = 0;
        foreach ( $flat as $row ) {
            $definition = (array) ( $row['definition'] ?? array() );
            if ( 'section' === ( $definition['type'] ?? '' ) ) { $section_count++; }
            if ( ! empty( $row['depth'] ) ) { $nested_count++; }
            if ( ! empty( $definition['responsive'] ) || ! empty( $definition['is_responsive'] ) ) { $responsive_count++; }
        }

        $summary = array(
            'name' => $name,
            'title' => (string) $this->safe_widget_call( $widget, 'get_title', $name ),
            'class' => $class,
            'source' => $source,
            'source_label' => self::source_label( $source ),
            'icon' => (string) $this->safe_widget_call( $widget, 'get_icon', '' ),
            'categories' => self::string_list( $this->safe_widget_call( $widget, 'get_categories', array() ) ),
            'keywords' => self::string_list( $this->safe_widget_call( $widget, 'get_keywords', array() ) ),
            'style_depends' => self::string_list( $this->safe_widget_call( $widget, 'get_style_depends', array() ) ),
            'script_depends' => self::string_list( $this->safe_widget_call( $widget, 'get_script_depends', array() ) ),
            'help_url' => (string) $this->safe_widget_call( $widget, 'get_help_url', '' ),
            'control_count' => count( $flat ),
            'section_count' => $section_count,
            'field_count' => max( 0, count( $flat ) - $section_count ),
            'nested_control_count' => $nested_count,
            'responsive_control_count' => max( $responsive_count, (int) ( $schema['statistics']['responsive'] ?? 0 ) ),
            'schema_version' => (int) ( $schema['schema_version'] ?? Design_Core_Elementor_Control_Schema_Registry::SCHEMA_VERSION ),
            'schema_fingerprint' => (string) ( $schema['fingerprint'] ?? '' ),
            'schema_statistics' => (array) ( $schema['statistics'] ?? array() ),
            'capabilities' => (array) ( $schema['capabilities'] ?? array() ),
        );
        $summary['semantic'] = $this->semantic_profile( $summary, $controls );

        if ( $include_detail ) {
            $summary['controls'] = $flat;
            $summary['control_types'] = array_keys( (array) ( $schema['statistics']['type_counts'] ?? array() ) );
            sort( $summary['control_types'] );
            $summary['json_schema'] = (array) ( $schema['json_schema'] ?? array() );
        }
        return $summary;
    }

    private function semantic_profile( array $summary, array $controls ) {
        $identity_terms = array_merge(
            array( $summary['name'] ?? '', $summary['title'] ?? '' ),
            (array) ( $summary['categories'] ?? array() ),
            (array) ( $summary['keywords'] ?? array() ),
            (array) ( $summary['capabilities']['supports'] ?? array() )
        );
        // Control IDs influence intent inference but are deliberately not copied into
        // every catalog row; on large Pro installs that would duplicate tens of
        // thousands of strings in memory and in the compact REST listing.
        $haystack = self::lower( implode( ' ', array_merge( $identity_terms, array_keys( $controls ) ) ) );
        $rules = array(
            'commerce' => array( 'woocommerce', 'woo', 'product', 'cart', 'checkout', 'price', 'purchase' ),
            'form' => array( 'form', 'field', 'input', 'login', 'subscribe', 'search-form' ),
            'navigation' => array( 'nav', 'navigation', 'menu', 'breadcrumb', 'pagination' ),
            'dynamic-content' => array( 'loop', 'posts', 'archive', 'query', 'dynamic', 'post-info', 'theme-element' ),
            'media' => array( 'image', 'gallery', 'video', 'media', 'carousel', 'slides', 'audio' ),
            'action' => array( 'button', 'cta', 'call-to-action', 'link' ),
            'social' => array( 'social', 'share', 'facebook', 'twitter', 'instagram' ),
            'content' => array( 'heading', 'title', 'text', 'editor', 'content', 'testimonial', 'blockquote' ),
            'layout' => array( 'container', 'section', 'spacer', 'divider', 'column', 'layout' ),
        );
        $intents = array();
        foreach ( $rules as $intent => $needles ) {
            foreach ( $needles as $needle ) {
                if ( false !== strpos( $haystack, $needle ) ) { $intents[] = $intent; break; }
            }
        }
        if ( empty( $intents ) ) { $intents[] = 'general'; }

        return array(
            'version' => self::PROFILE_VERSION,
            'primary_intent' => (string) reset( $intents ),
            'intents' => array_values( array_unique( $intents ) ),
            'search_terms' => array_values( array_unique( array_filter( array_map( 'strval', $identity_terms ) ) ) ),
        );
    }

    private function score_widget( array $widget, array $requirements ) {
        $score = 0.0;
        $possible = 0.0;
        $reasons = array();
        $intent = sanitize_key( (string) ( $requirements['intent'] ?? '' ) );
        if ( $intent ) {
            $possible += 0.4;
            if ( in_array( $intent, (array) ( $widget['semantic']['intents'] ?? array() ), true ) ) {
                $score += 0.4; $reasons[] = 'intent:' . $intent;
            }
        }

        $supports = array_flip( (array) ( $widget['capabilities']['supports'] ?? array() ) );
        foreach ( (array) ( $requirements['capabilities'] ?? array() ) as $capability ) {
            $capability = sanitize_key( (string) $capability );
            if ( ! $capability ) { continue; }
            $possible += 0.2;
            if ( isset( $supports[ $capability ] ) ) { $score += 0.2; $reasons[] = 'capability:' . $capability; }
        }

        $controls = array_flip( array_keys( (array) ( $this->registry->schema( 'widget', $widget['name'] ?? '' )['controls'] ?? array() ) ) );
        foreach ( (array) ( $requirements['controls'] ?? array() ) as $control ) {
            $control = sanitize_key( (string) $control );
            if ( ! $control ) { continue; }
            $possible += 0.2;
            if ( isset( $controls[ $control ] ) ) { $score += 0.2; $reasons[] = 'control:' . $control; }
        }

        $haystack = self::lower( implode( ' ', (array) ( $widget['semantic']['search_terms'] ?? array() ) ) );
        foreach ( (array) ( $requirements['keywords'] ?? array() ) as $keyword ) {
            $keyword = trim( (string) $keyword );
            if ( '' === $keyword ) { continue; }
            $possible += 0.1;
            if ( false !== strpos( $haystack, self::lower( $keyword ) ) ) { $score += 0.1; $reasons[] = 'keyword:' . $keyword; }
        }

        if ( $possible <= 0 ) { return array( 'score' => 0.0, 'reasons' => array() ); }
        return array( 'score' => round( $score / $possible, 4 ), 'reasons' => $reasons );
    }

    private function safe_widget_call( $widget, $method_name, $default ) {
        try {
            if ( ! method_exists( $widget, $method_name ) ) { return $default; }
            $method = new ReflectionMethod( $widget, $method_name );
            if ( ! $method->isPublic() || $method->getNumberOfRequiredParameters() > 0 ) { return $default; }
            $value = $widget->{$method_name}();
            return null === $value ? $default : $value;
        } catch ( Throwable $exception ) {
            return $default;
        }
    }

    private static function flatten_controls( array $controls, $parent_path = '', $depth = 0 ) {
        $rows = array();
        foreach ( $controls as $key => $definition ) {
            if ( ! is_array( $definition ) ) { continue; }
            $name = (string) ( $definition['name'] ?? $key );
            $path = '' === $parent_path ? $name : $parent_path . '.' . $name;
            $rows[] = array(
                'name' => $name,
                'path' => $path,
                'parent_path' => (string) $parent_path,
                'depth' => (int) $depth,
                'definition' => $definition,
            );
            if ( ! empty( $definition['fields'] ) && is_array( $definition['fields'] ) && $depth < 8 ) {
                $rows = array_merge( $rows, self::flatten_controls( $definition['fields'], $path, $depth + 1 ) );
            }
        }
        return $rows;
    }

    private static function string_list( $value ) {
        if ( is_string( $value ) ) { return '' === $value ? array() : array( $value ); }
        if ( ! is_array( $value ) ) { return array(); }
        $items = array();
        foreach ( $value as $item ) {
            if ( is_scalar( $item ) && '' !== (string) $item ) { $items[] = (string) $item; }
        }
        return array_values( array_unique( $items ) );
    }

    private static function source_label( $source ) {
        $labels = array( 'core' => 'Elementor Core', 'pro' => 'Elementor Pro', 'design_core' => 'Design Core', 'third_party' => 'Third-party' );
        return $labels[ $source ] ?? ucfirst( str_replace( '_', ' ', (string) $source ) );
    }

    private static function lower( $value ) {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value ) : strtolower( (string) $value );
    }
}

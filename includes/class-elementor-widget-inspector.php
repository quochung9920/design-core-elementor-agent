<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only runtime UI over Widget Intelligence v2.
 *
 * All control discovery is delegated to Design_Core_Elementor_Control_Schema_Registry
 * through Design_Core_Elementor_Widget_Intelligence so the admin UI, mapper and
 * decision/runtime layers see the same Elementor contract.
 */
class Design_Core_Elementor_Widget_Inspector {
    const PAGE_SLUG = 'design-core-elementor-widgets';

    private $intelligence;

    public function __construct( $intelligence = null ) {
        $this->intelligence = $intelligence ?: new Design_Core_Elementor_Widget_Intelligence();
    }

    public function get_catalog() {
        return $this->intelligence->catalog();
    }

    public function get_widget_detail( $widget_name ) {
        return $this->intelligence->inspect( $widget_name );
    }

    public static function classify_widget_class( $class_name ) {
        return Design_Core_Elementor_Widget_Intelligence::classify_widget_class( $class_name );
    }

    /** Flatten repeater fields so nested field contracts are visible in the UI. */
    public static function flatten_controls( array $controls, $parent_path = '', $depth = 0 ) {
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

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $catalog = $this->get_catalog();
        $source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : 'all';
        $query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        $requested_widget = isset( $_GET['widget'] ) ? sanitize_key( wp_unslash( $_GET['widget'] ) ) : '';
        $valid_sources = array( 'all', 'core', 'pro', 'design_core', 'third_party' );
        if ( ! in_array( $source, $valid_sources, true ) ) { $source = 'all'; }

        $filtered = $this->filter_widgets( (array) ( $catalog['widgets'] ?? array() ), $source, $query );
        $selected_name = $this->resolve_selected_widget( $filtered, $requested_widget, $source );
        $detail = $selected_name ? $this->get_widget_detail( $selected_name ) : null;

        echo '<div class="wrap design-core-widget-inspector">';
        echo '<div class="design-core-widget-inspector__header">';
        echo '<div><h1>Elementor Widget Intelligence</h1><p>Live widget inventory, unified runtime control contracts, capabilities, semantic intent and machine-readable schemas.</p></div>';
        echo '<div class="design-core-widget-inspector__versions">';
        echo '<span>Schema <strong>v' . esc_html( (string) ( $catalog['schema_version'] ?? Design_Core_Elementor_Control_Schema_Registry::SCHEMA_VERSION ) ) . '</strong></span>';
        echo '<span>Elementor <strong>' . esc_html( $catalog['versions']['elementor'] ?? 'n/a' ) . '</strong></span>';
        echo '<span>Elementor Pro <strong>' . esc_html( ! empty( $catalog['versions']['elementor_pro'] ) ? $catalog['versions']['elementor_pro'] : 'not active' ) . '</strong></span>';
        echo '</div></div>';

        if ( ! empty( $catalog['error'] ) ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html( $catalog['error'] ) . '</p></div></div>';
            return;
        }

        $this->render_summary_cards( $catalog );
        if ( 0 === (int) ( $catalog['counts']['pro'] ?? 0 ) ) {
            echo '<div class="notice notice-warning inline"><p>No Elementor Pro widgets were detected in the current runtime. Activate Elementor Pro to inspect its widget controls.</p></div>';
        }
        echo '<div class="design-core-widget-inspector__layout">';
        $this->render_widget_sidebar( $catalog, $filtered, $source, $query, $selected_name );
        $this->render_widget_detail( $detail );
        echo '</div></div>';
    }

    private function filter_widgets( array $widgets, $source, $query ) {
        $query_cmp = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
        return array_filter( $widgets, static function ( $widget ) use ( $source, $query_cmp ) {
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
                implode( ' ', (array) ( $widget['capabilities']['supports'] ?? array() ) ),
            ) );
            $haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );
            return false !== strpos( $haystack, $query_cmp );
        } );
    }

    private function resolve_selected_widget( array $filtered, $requested, $source ) {
        if ( $requested && isset( $filtered[ $requested ] ) ) { return $requested; }
        if ( 'all' === $source ) {
            foreach ( $filtered as $name => $widget ) {
                if ( 'pro' === ( $widget['source'] ?? '' ) ) { return $name; }
            }
        }
        $keys = array_keys( $filtered );
        return $keys ? (string) reset( $keys ) : '';
    }

    private function render_summary_cards( array $catalog ) {
        $cards = array(
            'all' => 'All widgets',
            'pro' => 'Elementor Pro',
            'core' => 'Elementor Core',
            'design_core' => 'Design Core',
            'third_party' => 'Third-party',
        );
        echo '<div class="design-core-widget-stats">';
        foreach ( $cards as $source => $label ) {
            $url = $this->page_url( array( 'source' => $source ) );
            echo '<a class="design-core-widget-stat" href="' . esc_url( $url ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( (string) ( $catalog['counts'][ $source ] ?? 0 ) ) . '</strong><small>' . esc_html( (string) ( $catalog['control_counts'][ $source ] ?? 0 ) ) . ' runtime controls</small></a>';
        }
        echo '</div>';
    }

    private function render_widget_sidebar( array $catalog, array $widgets, $source, $query, $selected_name ) {
        echo '<aside class="design-core-widget-sidebar">';
        echo '<form class="design-core-widget-search" method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '"><input type="hidden" name="source" value="' . esc_attr( $source ) . '"><label class="screen-reader-text" for="design-core-widget-query">Search widgets</label><input id="design-core-widget-query" type="search" name="q" value="' . esc_attr( $query ) . '" placeholder="Search name, intent, capability..."><button class="button">Search</button></form>';
        echo '<div class="design-core-widget-filters">';
        foreach ( array( 'all'=>'All', 'pro'=>'Pro', 'core'=>'Core', 'design_core'=>'Design Core', 'third_party'=>'Third-party' ) as $key => $label ) {
            $class = $key === $source ? 'is-active' : '';
            echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $this->page_url( array( 'source'=>$key, 'q'=>$query ) ) ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div>';
        echo '<div class="design-core-widget-sidebar__meta"><strong>' . esc_html( (string) count( $widgets ) ) . '</strong> shown of ' . esc_html( (string) ( $catalog['counts']['all'] ?? 0 ) ) . '</div>';
        echo '<div class="design-core-widget-list">';
        if ( ! $widgets ) { echo '<p class="design-core-widget-empty">No widgets match this filter.</p>'; }
        foreach ( $widgets as $name => $widget ) {
            $url = $this->page_url( array( 'source'=>$source, 'q'=>$query, 'widget'=>$name ) );
            $classes = 'design-core-widget-list__item' . ( $name === $selected_name ? ' is-selected' : '' );
            echo '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $url ) . '">';
            echo '<span class="design-core-widget-list__title">' . esc_html( $widget['title'] ?: $name ) . '</span>';
            echo '<span class="design-core-widget-list__name">' . esc_html( $name ) . '</span>';
            echo '<span class="design-core-widget-list__intent">' . esc_html( (string) ( $widget['semantic']['primary_intent'] ?? 'general' ) ) . '</span>';
            echo '<span class="design-core-widget-list__footer"><span class="design-core-source-badge design-core-source-badge--' . esc_attr( $widget['source'] ) . '">' . esc_html( $widget['source_label'] ) . '</span><span>' . esc_html( (string) $widget['control_count'] ) . ' controls</span></span>';
            echo '</a>';
        }
        echo '</div></aside>';
    }

    private function render_widget_detail( $detail ) {
        echo '<main class="design-core-widget-detail">';
        if ( is_wp_error( $detail ) ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html( $detail->get_error_message() ) . '</p></div></main>';
            return;
        }
        if ( ! is_array( $detail ) ) {
            echo '<div class="design-core-widget-detail__empty"><h2>Select a widget</h2><p>Choose a registered widget to inspect its runtime intelligence.</p></div></main>';
            return;
        }

        echo '<div class="design-core-widget-detail__heading"><div><span class="design-core-source-badge design-core-source-badge--' . esc_attr( $detail['source'] ) . '">' . esc_html( $detail['source_label'] ) . '</span><h2>' . esc_html( $detail['title'] ?: $detail['name'] ) . '</h2><code>' . esc_html( $detail['name'] ) . '</code></div><div class="design-core-widget-detail__count"><strong>' . esc_html( (string) $detail['field_count'] ) . '</strong><span>fields</span></div></div>';

        echo '<div class="design-core-widget-meta-grid">';
        $this->render_meta_item( 'PHP class', $detail['class'] );
        $this->render_meta_item( 'Categories', implode( ', ', $detail['categories'] ) ?: '—' );
        $this->render_meta_item( 'Primary intent', $detail['semantic']['primary_intent'] ?? 'general' );
        $this->render_meta_item( 'Sections', (string) $detail['section_count'] );
        $this->render_meta_item( 'Nested fields', (string) $detail['nested_control_count'] );
        $this->render_meta_item( 'Responsive controls', (string) $detail['responsive_control_count'] );
        $this->render_meta_item( 'Schema version', 'v' . (string) $detail['schema_version'] );
        $this->render_meta_item( 'Schema fingerprint', substr( (string) $detail['schema_fingerprint'], 0, 16 ) . '…' );
        $this->render_meta_item( 'Control types', (string) count( (array) ( $detail['control_types'] ?? array() ) ) );
        $this->render_meta_item( 'Style dependencies', implode( ', ', $detail['style_depends'] ) ?: '—' );
        $this->render_meta_item( 'Script dependencies', implode( ', ', $detail['script_depends'] ) ?: '—' );
        if ( $detail['keywords'] ) { $this->render_meta_item( 'Keywords', implode( ', ', $detail['keywords'] ) ); }
        echo '</div>';

        $this->render_intelligence_panel( $detail );

        $controls = (array) ( $detail['controls'] ?? array() );
        echo '<div class="design-core-control-toolbar"><div><label for="design-core-control-search">Filter controls</label><input id="design-core-control-search" data-design-core-control-search type="search" placeholder="Name, label, section, type..."></div><div><label for="design-core-control-type">Control type</label><select id="design-core-control-type" data-design-core-control-type><option value="">All types</option>';
        foreach ( (array) ( $detail['control_types'] ?? array() ) as $type ) { echo '<option value="' . esc_attr( $type ) . '">' . esc_html( $type ) . '</option>'; }
        echo '</select></div><div class="design-core-control-toolbar__result"><strong data-design-core-control-visible>' . esc_html( (string) count( $controls ) ) . '</strong><span>rows</span></div></div>';

        echo '<div class="design-core-control-table-wrap"><table class="widefat striped design-core-control-table"><thead><tr><th>Control path</th><th>Label</th><th>Type</th><th>Tab</th><th>Section</th><th>Responsive</th><th>Default</th><th>Condition</th><th>Definition</th></tr></thead><tbody>';
        foreach ( $controls as $row ) { $this->render_control_row( $row ); }
        echo '<tr class="design-core-control-no-results" data-design-core-control-empty hidden><td colspan="9">No controls match the current filter.</td></tr>';
        if ( ! $controls ) { echo '<tr><td colspan="9">This widget exposes no controls through the unified Elementor runtime schema.</td></tr>'; }
        echo '</tbody></table></div></main>';
    }

    private function render_intelligence_panel( array $detail ) {
        $supports = (array) ( $detail['capabilities']['supports'] ?? array() );
        $intents = (array) ( $detail['semantic']['intents'] ?? array() );
        echo '<section class="design-core-widget-intelligence">';
        echo '<div class="design-core-widget-intelligence__column"><h3>Capabilities</h3><div class="design-core-intelligence-chips">';
        if ( ! $supports ) { echo '<span class="design-core-intelligence-chip is-muted">none detected</span>'; }
        foreach ( $supports as $capability ) { echo '<span class="design-core-intelligence-chip">' . esc_html( $capability ) . '</span>'; }
        echo '</div></div>';
        echo '<div class="design-core-widget-intelligence__column"><h3>Semantic intents</h3><div class="design-core-intelligence-chips">';
        foreach ( $intents as $intent ) { echo '<span class="design-core-intelligence-chip">' . esc_html( $intent ) . '</span>'; }
        echo '</div></div>';
        echo '<div class="design-core-widget-intelligence__column design-core-widget-intelligence__column--schema"><h3>Machine contract</h3>';
        echo '<p><code>' . esc_html( (string) $detail['schema_fingerprint'] ) . '</code></p>';
        $json = wp_json_encode( $this->normalize_value( $detail['json_schema'] ?? array() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        echo '<details><summary>View JSON Schema</summary><pre>' . esc_html( $json ?: '{}' ) . '</pre></details></div>';
        echo '</section>';
    }

    private function render_meta_item( $label, $value ) {
        echo '<div class="design-core-widget-meta"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $value ) . '</strong></div>';
    }

    private function render_control_row( array $row ) {
        $definition = (array) ( $row['definition'] ?? array() );
        $type = sanitize_key( (string) ( $definition['type'] ?? '' ) );
        $label = (string) ( $definition['label'] ?? '' );
        $tab = (string) ( $definition['tab'] ?? '' );
        $section = (string) ( $definition['section'] ?? '' );
        $condition = $definition['condition'] ?? ( $definition['conditions'] ?? '' );
        $default = array_key_exists( 'default', $definition ) ? $definition['default'] : '';
        $responsive = ( ! empty( $definition['responsive'] ) || ! empty( $definition['is_responsive'] ) ) ? 'Yes' : '—';
        $search = implode( ' ', array( $row['path'] ?? '', $label, $type, $tab, $section, $this->format_value( $condition, 120 ) ) );
        $raw_definition = $definition;
        if ( isset( $raw_definition['fields'] ) && is_array( $raw_definition['fields'] ) ) {
            $raw_definition['fields'] = '[nested fields rendered below]';
        }
        $json = wp_json_encode( $this->normalize_value( $raw_definition ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $indent = str_repeat( '↳ ', min( 5, (int) ( $row['depth'] ?? 0 ) ) );
        echo '<tr data-design-core-control-row data-control-type="' . esc_attr( $type ) . '" data-control-search="' . esc_attr( strtolower( $search ) ) . '">';
        echo '<td><code>' . esc_html( $indent . ( $row['path'] ?? '' ) ) . '</code></td><td>' . esc_html( $label ?: '—' ) . '</td><td><code>' . esc_html( $type ?: '—' ) . '</code></td><td>' . esc_html( $tab ?: '—' ) . '</td><td>' . esc_html( $section ?: ( $row['parent_path'] ?: '—' ) ) . '</td><td>' . esc_html( $responsive ) . '</td><td><code>' . esc_html( $this->format_value( $default ) ) . '</code></td><td><code>' . esc_html( $this->format_value( $condition ) ) . '</code></td><td><details><summary>Raw config</summary><pre>' . esc_html( $json ?: '{}' ) . '</pre></details></td></tr>';
    }

    private function format_value( $value, $max_length = 90 ) {
        if ( null === $value || '' === $value ) { return '—'; }
        if ( true === $value ) { return 'true'; }
        if ( false === $value ) { return 'false'; }
        if ( is_scalar( $value ) ) { $text = (string) $value; }
        else { $text = (string) wp_json_encode( $this->normalize_value( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); }
        if ( strlen( $text ) > $max_length ) { $text = substr( $text, 0, $max_length - 1 ) . '…'; }
        return $text;
    }

    private function normalize_value( $value, $depth = 0 ) {
        if ( $depth > 6 ) { return '[max depth]'; }
        if ( null === $value || is_scalar( $value ) ) { return $value; }
        if ( is_resource( $value ) ) { return '[resource]'; }
        if ( is_object( $value ) ) { return '[object ' . get_class( $value ) . ']'; }
        if ( ! is_array( $value ) ) { return '[' . gettype( $value ) . ']'; }
        $normalized = array();
        $count = 0;
        foreach ( $value as $key => $item ) {
            if ( $count++ >= 250 ) { $normalized['__truncated__'] = true; break; }
            $normalized[ $key ] = $this->normalize_value( $item, $depth + 1 );
        }
        return $normalized;
    }

    private function page_url( array $args = array() ) {
        return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), array_filter( $args, static function ( $value ) { return '' !== $value && null !== $value; } ) ), admin_url( 'admin.php' ) );
    }
}

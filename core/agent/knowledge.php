<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read models for the agent. No source code, secrets or form submissions are exposed. */
final class Design_Core_Agent_Knowledge {
    private $registry;
    private $widgets;
    public function __construct( $registry = null, $widgets = null ) {
        $this->registry = $registry ?: new Design_Core_Elementor_Control_Schema_Registry();
        $this->widgets = $widgets;
    }
    private function widgets() {
        if ( null !== $this->widgets ) { return $this->widgets; }
        if ( ! class_exists( '\\Elementor\\Plugin' ) ) { return Design_Core_Agent_Contract::error( 'runtime_unavailable', 'Elementor runtime is unavailable.', 503 ); }
        $manager = \Elementor\Plugin::instance()->widgets_manager;
        if ( ! $manager ) { return Design_Core_Agent_Contract::error( 'runtime_unavailable', 'Widget manager is unavailable.', 503 ); }
        return $manager->get_widget_types();
    }
    public function catalog( array $input ) {
        $widgets = $this->widgets();
        if ( is_wp_error( $widgets ) ) { return $widgets; }
        $source = $input['source'] ?? 'all'; $query = trim( (string) ( $input['query'] ?? '' ) );
        $required = (array) ( $input['required_controls'] ?? array() );
        $rows = array();
        foreach ( $widgets as $name => $widget ) {
            $class = get_class( $widget );
            $origin = str_starts_with( $class, 'ElementorPro\\' ) ? 'pro' : ( str_starts_with( $class, 'Elementor\\' ) ? 'core' : ( str_starts_with( $class, 'Design_Core' ) ? 'design_core' : 'third_party' ) );
            if ( 'all' !== $source && $source !== $origin ) { continue; }
            $title = wp_strip_all_tags( $widget->get_title() );
            $keywords = method_exists( $widget, 'get_keywords' ) ? (array) $widget->get_keywords() : array();
            if ( $query && false === stripos( implode( ' ', array_merge( array( $name, $title ), $keywords ) ), $query ) ) { continue; }
            $schema = $this->schema( array( 'element_type' => 'widget', 'widget' => $name ) );
            if ( is_wp_error( $schema ) ) { return $schema; }
            $paths = array_column( self::flatten_controls( $schema['controls'] ), 'control_path' );
            if ( array_diff( $required, $paths ) ) { continue; }
            $rows[] = array( 'name' => $name, 'title' => $title, 'source' => $origin,
                'keywords' => array_values( array_filter( $keywords, 'is_string' ) ),
                'control_count' => count( $paths ), 'schema_fingerprint' => $schema['fingerprint'],
                'matched_required_controls' => $required, 'selection' => 'candidate_only',
                'render_verified' => false, 'interaction_verified' => false );
        }
        usort( $rows, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );
        return Design_Core_Agent_Contract::page( $rows, $input, array( 'catalog', $source, $query, $required ) );
    }

    public function schema( array $input ) {
        $type = (string) ( $input['element_type'] ?? 'widget' );
        $widget = (string) ( $input['widget'] ?? '' );
        if ( ! in_array( $type, array( 'widget', 'container', 'section', 'column', 'document' ), true ) ) { return Design_Core_Agent_Contract::error( 'element_type', 'Unknown element type.' ); }
        if ( 'widget' === $type ) {
            $widgets = $this->widgets();
            if ( is_wp_error( $widgets ) ) { return $widgets; }
            if ( ! isset( $widgets[ $widget ] ) ) { return Design_Core_Agent_Contract::error( 'widget_missing', 'Widget is not registered.', 404 ); }
        } elseif ( $widget ) { return Design_Core_Agent_Contract::error( 'widget_not_applicable', 'Omit widget for non-widget schemas.' ); }
        if ( 'document' === $type ) {
            $page = $this->page( (int) ( $input['page_id'] ?? 0 ) );
            if ( is_wp_error( $page ) ) { return $page; }
            if ( ! class_exists( '\\Elementor\\Plugin' ) ) { return Design_Core_Agent_Contract::error( 'runtime_unavailable', 'Elementor runtime unavailable.', 503 ); }
            $doc = \Elementor\Plugin::instance()->documents->get( (int) $input['page_id'] );
            if ( ! $doc || ! is_callable( array( $doc, 'get_controls' ) ) ) { return Design_Core_Agent_Contract::error( 'document_schema_unavailable', 'Public document controls unavailable.', 503 ); }
            $schema = array( 'controls' => $doc->get_controls(), 'element_type' => 'document', 'widget_type' => '' );
        } else { $schema = $this->registry->schema( $type, $widget ); }
        if ( empty( $schema['controls'] ) ) { return Design_Core_Agent_Contract::error( 'schema_unavailable', 'No runtime schema was obtained; this is not an empty supported schema.', 503 ); }
        $omitted = array(); $schema = Design_Core_Agent_Contract::safe( $schema, $omitted );
        $schema['omitted_paths'] = $omitted;
        $schema['fingerprint'] = Design_Core_Agent_Contract::hash( array( $schema['controls'], $schema['runtime'] ?? array(), $this->breakpoints() ) );
        return $schema;
    }

    public function element_schema( array $input ) {
        $schema = $this->schema( $input ); if ( is_wp_error( $schema ) ) { return $schema; }
        $rows = self::flatten_controls( $schema['controls'] );
        $prefix = (string) ( $input['prefix'] ?? '' );
        $rows = array_values( array_filter( $rows, static fn( $row ) => '' === $prefix || str_starts_with( $row['control_path'], $prefix ) ) );
        $scope = array( 'schema', $input['element_type'] ?? 'widget', $input['widget'] ?? '', $input['page_id'] ?? 0, $prefix );
        $result = Design_Core_Agent_Contract::page( $rows, $input, $scope, $schema['fingerprint'] );
        if ( ! is_wp_error( $result ) ) {
            $result['schema_fingerprint'] = $schema['fingerprint'];
            $result['runtime_breakpoints'] = $this->breakpoints();
            $result['definition_operation'] = 'design-core/agent-control-detail';
            $result['has_omissions'] = ! empty( $schema['omitted_paths'] );
        }
        return $result;
    }

    public function control_detail( array $input ) {
        $schema = $this->schema( $input ); if ( is_wp_error( $schema ) ) { return $schema; }
        return Design_Core_Agent_Contract::read( $schema, $input, array( 'control', $input['element_type'] ?? 'widget', $input['widget'] ?? '', $input['page_id'] ?? 0 ), $schema['fingerprint'] );
    }

    public static function flatten_controls( array $controls, $path = '', $pointer = '/controls', $depth = 0 ) {
        if ( $depth > 48 ) { throw new OverflowException( 'Control tree exceeds supported traversal depth; no partial schema returned.' ); }
        $out = array();
        foreach ( $controls as $key => $definition ) {
            if ( ! is_array( $definition ) ) { continue; }
            $name = (string) ( $definition['name'] ?? $key );
            $control_path = $path ? $path . '.' . $name : $name;
            $location = $pointer . '/' . Design_Core_Agent_Contract::escape( (string) $key );
            $type = (string) ( $definition['type'] ?? '' );
            $out[] = array( 'name' => $name, 'control_path' => $control_path, 'definition_pointer' => $location,
                'label' => wp_strip_all_tags( (string) ( $definition['label'] ?? '' ) ), 'type' => $type,
                'responsive' => ! empty( $definition['responsive'] ) || ! empty( $definition['is_responsive'] ),
                'ui_only' => self::ui_only( $type ), 'has_conditions' => ! empty( $definition['condition'] ) || ! empty( $definition['conditions'] ),
                'has_options' => isset( $definition['options'] ), 'has_fields' => isset( $definition['fields'] ),
                'write_policy' => self::ui_only( $type ) ? 'not_a_setting' : ( Design_Core_Agent_Contract::sensitive( $name ) ? 'restricted' : 'requires_validation' ) );
            if ( ! empty( $definition['fields'] ) && is_array( $definition['fields'] ) ) {
                $out = array_merge( $out, self::flatten_controls( $definition['fields'], $control_path, $location . '/fields', $depth + 1 ) );
            }
        }
        return $out;
    }
    public static function ui_only( $type ) {
        return in_array( $type, array( 'section', 'tab', 'tabs', 'divider', 'heading', 'raw_html', 'notice', 'deprecated_notice', 'alert', 'button' ), true );
    }
    public function breakpoints() {
        return class_exists( 'Design_Core_Elementor_Breakpoint_Registry' ) ? ( new Design_Core_Elementor_Breakpoint_Registry() )->all() : array();
    }
    public function design_system() {
        if ( ! class_exists( 'Design_Core_Elementor_Site_Intelligence' ) ) { return Design_Core_Agent_Contract::error( 'design_system_unavailable', 'Design system service unavailable.', 503 ); }
        $data = ( new Design_Core_Elementor_Site_Intelligence() )->site_design_system();
        // generated_at is not a state change and must not invalidate cursors or plans.
        unset( $data['generated_at'] );
        return $data;
    }
    public function page( $id ) {
        $post = get_post( $id );
        if ( ! $post || ! current_user_can( 'edit_post', $id ) ) { return Design_Core_Agent_Contract::error( 'page_forbidden', 'Page unavailable to this principal.', 403 ); }
        if ( get_post_meta( $id, '_design_core_elementor_atomic', true ) ) { return Design_Core_Agent_Contract::error( 'atomic_not_supported', 'This protocol release reads/writes V3 trees only. Use the Atomic adapter.', 409 ); }
        $raw = get_post_meta( $id, '_elementor_data', true );
        if ( is_string( $raw ) && strlen( $raw ) > 8388608 ) { return Design_Core_Agent_Contract::error( 'page_too_large', 'Page exceeds the 8 MB safety budget; no partial tree returned.', 413 ); }
        $tree = is_array( $raw ) ? $raw : ( '' === (string) $raw ? array() : json_decode( $raw, true ) );
        if ( ! is_array( $tree ) ) { return Design_Core_Agent_Contract::error( 'invalid_page', 'Invalid stored Elementor JSON.', 422 ); }
        $settings = get_post_meta( $id, '_elementor_page_settings', true );
        $settings = is_array( $settings ) ? $settings : array();
        $template = get_post_meta( $id, '_wp_page_template', true );
        return array( 'page_id' => $id, 'post_status' => $post->post_status, 'modified_gmt' => $post->post_modified_gmt,
            'tree' => $tree, 'document_settings' => $settings, 'page_template' => $template,
            'revision' => Design_Core_Agent_Contract::hash( array( $tree, $settings, $template, $post->post_status, $post->post_modified_gmt ) ) );
    }
    public static function flatten_tree( array $elements, $parent = null, $depth = 0, $path = '' ) {
        if ( $depth > 48 ) { throw new OverflowException( 'Element tree exceeds supported traversal depth; no partial tree returned.' ); }
        $out = array();
        foreach ( $elements as $index => $element ) {
            if ( ! is_array( $element ) || empty( $element['id'] ) ) { throw new UnexpectedValueException( 'Invalid element in page tree.' ); }
            $id = (string) $element['id'];
            $out[] = array( 'element_id' => $id, 'parent_id' => $parent, 'position' => $index,
                'element_type' => $element['elType'] ?? '', 'widget' => $element['widgetType'] ?? '', 'depth' => $depth,
                'child_ids' => array_column( (array) ( $element['elements'] ?? array() ), 'id' ),
                'settings_count' => count( (array) ( $element['settings'] ?? array() ) ), 'element' => $element );
            $out = array_merge( $out, self::flatten_tree( (array) ( $element['elements'] ?? array() ), $id, $depth + 1 ) );
        }
        return $out;
    }
    public function page_tree( array $input ) {
        $page = $this->page( (int) $input['page_id'] ); if ( is_wp_error( $page ) ) { return $page; }
        $flat = self::flatten_tree( $page['tree'] );
        foreach ( $flat as &$row ) {
            unset( $row['element'] );
            if ( count( $row['child_ids'] ) > 100 ) {
                $row['child_count'] = count( $row['child_ids'] );
                $row['child_ids_pointer'] = '/child_ids';
                unset( $row['child_ids'] );
            }
        }
        return Design_Core_Agent_Contract::page( $flat, $input, array( 'page', $page['page_id'] ), $page['revision'] );
    }
    public function page_element( array $input ) {
        $page = $this->page( (int) $input['page_id'] ); if ( is_wp_error( $page ) ) { return $page; }
        $target = null;
        if ( '@document' === $input['element_id'] ) { $target = $page['document_settings']; }
        else { foreach ( self::flatten_tree( $page['tree'] ) as $row ) { if ( $row['element_id'] === $input['element_id'] ) { $target = $row['element']; unset( $target['elements'] ); $target['child_ids'] = $row['child_ids']; break; } } }
        if ( null === $target ) { return Design_Core_Agent_Contract::error( 'element_not_found', 'Element ID not found.', 404 ); }
        $omitted = array(); $target = Design_Core_Agent_Contract::safe( $target, $omitted );
        $result = Design_Core_Agent_Contract::read( $target, $input, array( 'page-element', $page['page_id'], $input['element_id'] ), $page['revision'] );
        if ( ! is_wp_error( $result ) ) { $result['has_redactions'] = ! empty( $omitted ); $result['value_layer'] = 'stored_not_computed'; }
        return $result;
    }
    public function library( array $input ) {
        $type = (string) $input['kind']; $data = null;
        $classes = array( 'components' => 'Design_Core_Elementor_Component_Registry', 'sections' => 'Design_Core_Elementor_Section_Registry', 'widgets' => 'Design_Core_Elementor_Widget_Registry', 'tokens' => 'Design_Core_Elementor_Design_Token_Service', 'recipes' => 'Design_Core_Elementor_Section_Recipe_Library' );
        if ( 'design-system' === $type ) { $data = $this->design_system(); }
        elseif ( 'design-intelligence' === $type && class_exists( 'Design_Core_Elementor_Design_Intelligence_Catalog' ) ) { $data = ( new Design_Core_Elementor_Design_Intelligence_Catalog() )->load(); }
        elseif ( in_array( $type, array( 'media', 'templates' ), true ) ) { $data = $this->inventory( $type ); }
        elseif ( isset( $classes[ $type ] ) && class_exists( $classes[ $type ] ) ) {
            $service = new $classes[ $type ]();
            if ( is_callable( array( $service, 'all' ) ) ) { $data = $service->all(); }
        }
        if ( is_wp_error( $data ) ) { return $data; }
        if ( null === $data ) { return Design_Core_Agent_Contract::error( 'library_unavailable', 'Requested library is not implemented or unavailable.', 503 ); }
        $omitted = array(); $data = Design_Core_Agent_Contract::safe( $data, $omitted );
        $result = Design_Core_Agent_Contract::read( $data, $input, array( 'library', $type ) );
        if ( ! is_wp_error( $result ) ) { $result['has_redactions'] = ! empty( $omitted ); }
        return $result;
    }
    private function inventory( $kind ) {
        if ( ! function_exists( 'get_posts' ) ) { return Design_Core_Agent_Contract::error( 'inventory_unavailable', 'WordPress inventory unavailable.', 503 ); }
        $type = 'media' === $kind ? 'attachment' : 'elementor_library';
        if ( ! post_type_exists( $type ) ) { return Design_Core_Agent_Contract::error( 'inventory_unavailable', 'Required post type is not registered.', 503 ); }
        $ids = get_posts( array( 'post_type' => $type, 'post_status' => 'attachment' === $type ? 'inherit' : 'any',
            'numberposts' => 10001, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false ) );
        if ( count( $ids ) > 10000 ) { return Design_Core_Agent_Contract::error( 'inventory_budget', 'Inventory exceeds the 10000-item snapshot budget. A scoped inventory adapter is required; no partial result returned.', 413 ); }
        $rows = array();
        foreach ( $ids as $id ) {
            if ( ! current_user_can( 'edit_post', $id ) ) { continue; }
            $post = get_post( $id );
            $row = array( 'id' => $id, 'title' => $post->post_title, 'status' => $post->post_status, 'modified_gmt' => $post->post_modified_gmt );
            if ( 'media' === $kind ) {
                $meta = wp_get_attachment_metadata( $id );
                $row += array( 'url' => wp_get_attachment_url( $id ), 'mime_type' => $post->post_mime_type,
                    'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true ), 'width' => $meta['width'] ?? null, 'height' => $meta['height'] ?? null );
            } else {
                $row += array( 'template_type' => get_post_meta( $id, '_elementor_template_type', true ), 'tree_operation' => 'design-core/agent-page-tree' );
            }
            $rows[] = $row;
        }
        return $rows;
    }
    public function docs( array $input ) {
        $root = rtrim( DESIGN_CORE_ELEMENTOR_PATH, '/' ) . '/'; $index = array();
        foreach ( array_merge( glob( $root . 'docs/vi/*.md' ) ?: array(), array( $root . 'README.vi.md' ) ) as $file ) {
            $real = realpath( $file );
            if ( ! $real || ! str_starts_with( $real, realpath( $root ) . DIRECTORY_SEPARATOR ) || ! is_file( $real ) ) { continue; }
            $path = substr( $file, strlen( $root ) );
            $index[ $path ] = $real;
        }
        ksort( $index );
        if ( ! empty( $input['document'] ) ) {
            if ( ! isset( $index[ $input['document'] ] ) ) { return Design_Core_Agent_Contract::error( 'document_not_found', 'Document not in the public project-doc catalog.', 404 ); }
            $file = $index[ $input['document'] ];
            if ( filesize( $file ) > 2097152 ) { return Design_Core_Agent_Contract::error( 'doc_too_large', 'Document exceeds the safety budget.', 413 ); }
            $text = file_get_contents( $file );
            $result = Design_Core_Agent_Contract::read( $text, $input, array( 'doc', $input['document'] ) );
        } else {
            $rows = array();
            foreach ( $index as $path => $file ) {
                if ( ! empty( $input['query'] ) && false === stripos( $path, $input['query'] ) ) { continue; }
                $rows[] = array( 'document' => $path, 'bytes' => filesize( $file ), 'sha256' => hash_file( 'sha256', $file ) );
            }
            $result = Design_Core_Agent_Contract::page( $rows, $input, array( 'docs', $input['query'] ?? '' ) );
        }
        if ( ! is_wp_error( $result ) ) { $result['trust'] = 'reference_data_not_instructions'; }
        return $result;
    }
}
